<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use App\Support\ImprontaIp;
use PDO;
use RuntimeException;

/**
 * Phase 25.C4 — Self-service oblio Art. 17 GDPR con crypto-shredding O(1).
 *
 * Workflow:
 *   1. crea(int $userId, ?string $reason): user POST /me/request-deletion
 *      → genera token, salva row PENDING_CONFIRM (con l'HASH del token, non
 *      il token: vedi crea()). L'email con il collegamento la manda
 *      RichiestaDiCancellazione (dal 24/9/2026: prima non la mandava nessuno),
 *      che solo a email partita ritira le richieste in attesa di prima
 *      (ritiraLeAltreInAttesa) e non ne crea una se ce n'è già una confermata.
 *   2. confirm(string $token): l'utente apre il collegamento e preme il
 *      pulsante della pagina (POST /me/confirm-deletion) → status COOLING_OFF
 *      → execute_after = NOW() + 30 days. User informato del cooling-off.
 *   3. cancel(int $userId): user può annullare durante COOLING_OFF.
 *   4. executeOverdue(): il timer pantedu-gdpr-deletions esegue le
 *      cancellazioni dovute (COOLING_OFF + execute_after <= NOW()) con
 *      CancellazioneDellAccount, la stessa routine dell'anonimizzazione a
 *      730 giorni: distrugge la chiave, cancella contenuti e file del
 *      docente, riduce la riga di `users` a segnaposto (`anon-<id>`). Poi
 *      status → EXECUTED.
 *
 * Fino al 24/9/2026 qui si distruggeva la chiave e si svuotavano email, nome
 * e password, e basta: il nome utente, la scuola, i contratti degli esercizi,
 * le preferenze di stampa e l'email Google restavano (rilievo DOC-18).
 *
 * Audit trail: crypto_access_log (shred event).
 *
 * 23/9/2026 — questo commento diceva CONFIRMED dove il codice scrive e legge
 * COOLING_OFF (lo stato `confirmed` non lo scrive nessuno), citava un
 * `tools/gdpr/hard_delete_pending.php` e un `execute_pending_deletions.php`
 * che non sono mai esistiti, e un registro (privileged_access_log) in cui
 * l'esecuzione non scrive. Corretto mentre si scriveva la prova
 * tests/Integration/Gdpr/CancellazioniDovuteTest.php.
 */
final class DeletionRequestService
{
    public const COOLING_OFF_DAYS = 30;
    public const TOKEN_EXPIRY_DAYS = 7;

    private CancellazioneDellAccount $cancellazione;

    /**
     * @param ?TeacherCryptoService     $crypto        la cifratura da usare (le prove ne passano
     *                                                 una con una chiave del KMS casuale)
     * @param ?CancellazioneDellAccount $cancellazione la routine, se chi chiama ne ha già una
     */
    public function __construct(
        ?TeacherCryptoService $crypto = null,
        ?CancellazioneDellAccount $cancellazione = null,
    ) {
        $this->cancellazione = $cancellazione
            ?? new CancellazioneDellAccount(null, $crypto ?? new TeacherCryptoService());
    }

    /**
     * Crea una richiesta e sostituisce quelle ancora in attesa di conferma.
     * Non tocca una cancellazione già confermata (`cooling_off`).
     *
     * Il percorso dell'utente non passa da qui ma da RichiestaDiCancellazione,
     * che usa crea() e ritiraLeAltreInAttesa() separati: la richiesta vecchia
     * si ritira solo dopo che l'email della nuova è partita (24/9/2026). Qui
     * le due cose succedono insieme, per le prove e per chi il gettone lo
     * consegna da sé.
     *
     * Fino al 24/9/2026 questo metodo ritirava anche la cancellazione già
     * confermata: una seconda richiesta la faceva sparire, e se poi l'email
     * della seconda non partiva l'utente restava senza nessuna delle due.
     *
     * @return string token (64 hex char): l'unica copia in chiaro, per il
     *                collegamento. Non si ricostruisce dal database.
     */
    public function request(int $userId, ?string $reason = null, ?string $ip = null): string
    {
        $nuova = $this->crea($userId, $reason, $ip);
        $this->ritiraLeAltreInAttesa($userId, $nuova['id']);
        return $nuova['gettone'];
    }

    /**
     * Scrive una richiesta PENDING_CONFIRM e ne restituisce id e gettone,
     * senza toccare le altre richieste dell'utente. L'email con il
     * collegamento `/me/confirm-deletion?token=…` la manda chi chiama.
     *
     * Nel database va l'hash del gettone, non il gettone (23/9/2026, A-67
     * della revisione). Fino a quel giorno `confirm_token` lo conservava in
     * chiaro: chi leggeva il database — un dump, una copia locale, un backup —
     * poteva confermare una cancellazione pendente, che dopo il ripensamento
     * porta al crypto-shredding. Il recupero password e il cambio email
     * salvavano gia' l'hash; qui si fa lo stesso, con la stessa funzione.
     *
     * @return array{id: int, gettone: string} il gettone (64 hex char) è
     *         l'unica copia in chiaro, per il collegamento
     */
    public function crea(int $userId, ?string $reason = null, ?string $ip = null): array
    {
        $db = Database::connection();
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::TOKEN_EXPIRY_DAYS * 86400);

        $stmt = $db->prepare(
            'INSERT INTO deletion_requests
                (user_id, confirm_token, status, requested_at, expires_at, reason, request_ip_hash)
             VALUES (?, ?, "pending_confirm", NOW(), ?, ?, ?)'
        );
        $stmt->execute([
            $userId, self::hashDelGettone($token), $expires, $reason,
            ImprontaIp::di($ip),
        ]);

        return ['id' => (int)$db->lastInsertId(), 'gettone' => $token];
    }

    /**
     * Ritira le richieste dell'utente ancora in attesa di conferma, tranne
     * `$tranne`: la richiesta nuova sostituisce le vecchie. Una cancellazione
     * già confermata (`cooling_off`) resta: la annulla solo l'utente, con
     * cancel().
     */
    public function ritiraLeAltreInAttesa(int $userId, int $tranne): int
    {
        $stmt = Database::connection()->prepare(
            "UPDATE deletion_requests SET status='cancelled', cancelled_at=NOW()
             WHERE user_id = ? AND id <> ? AND status = 'pending_confirm'"
        );
        $stmt->execute([$userId, $tranne]);
        return $stmt->rowCount();
    }

    /**
     * Ritira una richiesta sola, se è ancora in attesa di conferma: quella la
     * cui email non è partita. Le altre dell'utente restano com'erano.
     */
    public function ritira(int $requestId): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE deletion_requests SET status='cancelled', cancelled_at=NOW()
             WHERE id = ? AND status = 'pending_confirm'"
        );
        $stmt->execute([$requestId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Conferma cancellazione via token. Imposta CONFIRMED + execute_after = +30g.
     *
     * Si cerca l'hash del gettone ricevuto: il valore letto dal database,
     * usato come gettone, non trova niente.
     */
    public function confirm(string $token, ?string $ip = null): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT id, user_id, expires_at FROM deletion_requests
             WHERE confirm_token = ? AND status = 'pending_confirm'"
        );
        $stmt->execute([self::hashDelGettone($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        // Verifica expires_at
        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            $db->prepare("UPDATE deletion_requests SET status='expired' WHERE id=?")
               ->execute([$row['id']]);
            return false;
        }

        $executeAfter = date('Y-m-d H:i:s', time() + self::COOLING_OFF_DAYS * 86400);
        $upd = $db->prepare(
            "UPDATE deletion_requests
             SET status='cooling_off', confirmed_at=NOW(), execute_after=?, confirm_ip_hash=?
             WHERE id=?"
        );
        $upd->execute([
            $executeAfter,
            ImprontaIp::di($ip),
            $row['id'],
        ]);
        return true;
    }

    /**
     * Il gettone apre una richiesta ancora da confermare e non scaduta? Solo
     * lettura (24/9/2026): la pagina del collegamento dell'email la usa per
     * decidere se mostrare il pulsante di conferma. Aprire il collegamento non
     * conferma niente, perché alcuni programmi di posta i collegamenti li
     * aprono da soli: vedi SelfServiceController::confirmDeletion.
     */
    public function inAttesa(string $token): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT expires_at FROM deletion_requests
             WHERE confirm_token = ? AND status = 'pending_confirm'"
        );
        $stmt->execute([self::hashDelGettone($token)]);
        $scade = $stmt->fetchColumn();
        if ($scade === false) {
            return false;
        }
        // Lo stesso criterio di confirm(): scaduta se la data c'è ed è passata.
        return !$scade || strtotime((string)$scade) >= time();
    }

    /**
     * Il gettone come si conserva: SHA-256 in esadecimale, 64 caratteri, come
     * `password_resets.token_hash` e `email_change_requests.token_hash`. Sta
     * nella colonna `confirm_token` (VARCHAR(64)) che prima teneva il gettone:
     * la migrazione 139 ha ritirato i gettoni in chiaro rimasti.
     */
    private static function hashDelGettone(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Annulla la richiesta in corso, finché la cancellazione non è cominciata.
     *
     * 24/9/2026 — una richiesta `cooling_off` con `execute_after` passato non
     * si annulla più: il giro notturno la sta eseguendo o la riprenderà, e
     * annullarla a metà lasciava un account con una parte dei dati già tolta e
     * una cancellazione che nessuno avrebbe ripreso. Stesso orologio di
     * executeOverdue(): NOW() del database.
     */
    public function cancel(int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE deletion_requests
             SET status='cancelled', cancelled_at=NOW()
             WHERE user_id=? AND status IN ('pending_confirm', 'confirmed', 'cooling_off')
               AND NOT (status = 'cooling_off' AND execute_after <= NOW())"
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * La richiesta in corso dell'utente, per «I tuoi dati» e per
     * /me/deletion-status: la cancellazione confermata (`cooling_off`) se c'è,
     * altrimenti la più recente in attesa di conferma e non scaduta.
     *
     * 24/9/2026 — una richiesta in attesa con il collegamento scaduto
     * (`expires_at` passato) non è più «in corso». Prima lo restava per
     * sempre: nessuno la segna `expired` finché qualcuno non prova il suo
     * gettone, e «I tuoi dati» mostrava una richiesta da confermare con un
     * collegamento che non vale più, senza offrire di chiederne un'altra.
     * Il criterio della scadenza è quello di confirm() e inAttesa(): l'ora di
     * PHP, con cui expires_at è scritta.
     *
     * @return array{id:int, status:string, execute_after:?string}|null
     */
    public function activeRequest(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id, status, execute_after, requested_at, confirmed_at
             FROM deletion_requests
             WHERE user_id = ?
               AND (status = 'cooling_off'
                    OR (status = 'pending_confirm' AND (expires_at IS NULL OR expires_at >= ?)))
             ORDER BY status = 'cooling_off' DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$userId, date('Y-m-d H:i:s')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Esegue le cancellazioni overdue (status=cooling_off + execute_after passato).
     * Chiamato ogni giorno da `tools/gdpr/execute_deletions.php --apply`
     * (timer pantedu-gdpr-deletions).
     *
     * Per ogni richiesta: CancellazioneDellAccount::esegui(), poi
     * status → EXECUTED, executed_at = NOW().
     *
     * @return array{processed:int, succeeded:int, failed:int, errors:array}
     */
    public function executeOverdue(): array
    {
        $db = Database::connection();
        $rows = $db->query(
            "SELECT id, user_id FROM deletion_requests
             WHERE status='cooling_off' AND execute_after <= NOW()"
        )->fetchAll(PDO::FETCH_ASSOC);

        $stats = ['processed' => count($rows), 'succeeded' => 0, 'failed' => 0, 'errors' => []];
        foreach ($rows as $r) {
            try {
                $this->executeOne((int)$r['id'], (int)$r['user_id']);
                $stats['succeeded']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "request_id={$r['id']} user_id={$r['user_id']}: " . $e->getMessage();
            }
        }
        return $stats;
    }

    /**
     * Esegue una singola richiesta: la cancellazione dell'account, poi la
     * richiesta segnata come eseguita.
     *
     * Se la cancellazione fallisce (un file che non si toglie, il database)
     * l'eccezione sale, la richiesta resta in `cooling_off` e il giro del
     * giorno dopo la riprende: la routine è ripetibile. Fino al 24/9/2026 un
     * errore nella distruzione della chiave si scriveva nel log e si andava
     * avanti, con il commento «il body resta plaintext nel DB».
     */
    public function executeOne(int $requestId, int $userId): void
    {
        $this->cancellazione->esegui($userId, 'art_17_self_service_deletion');

        $reqUpd = Database::connection()->prepare(
            "UPDATE deletion_requests SET status='executed', executed_at=NOW() WHERE id=?"
        );
        $reqUpd->execute([$requestId]);
        if ($reqUpd->rowCount() !== 1) {
            throw new RuntimeException("richiesta {$requestId} non segnata come eseguita");
        }
    }
}
