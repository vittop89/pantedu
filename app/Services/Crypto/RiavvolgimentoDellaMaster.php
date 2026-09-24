<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Cambia la chiave master (KMS_MASTER_KEY) senza perdere i dati.
 *
 * ── Perché esiste (23/9/2026, A-88) ──────────────────────────────────────
 *
 * La procedura di rotazione in docs/security/operations/kms-recovery.md
 * diceva «lo script di rewrap non esiste (TODO Phase D14)». Una chiave che
 * non si sa cambiare è una chiave che non si cambia nemmeno quando serve: il
 * 23/9 la revisione ha trovato in un annesso storico del pentest un valore
 * con la forma di una master. Decidere se e quando ruotare resta del
 * manutentore; questo è lo strumento per farlo senza buttare i dati.
 *
 * ── Che cosa dipende dalla master ─────────────────────────────────────────
 *
 * Due cose sole, e sono le sole che si ricifrano:
 *
 *   - teacher_keys.wrapped_kek — la KEK di ogni docente, per ogni versione,
 *     avvolta con TKEK = HKDF-SHA256(master, sale «prefisso|docente», info
 *     «versione»), come in TeacherCryptoService. Il prefisso di oggi è
 *     «pantedu-…»; righe rimaste indietro dal cambio di nome possono avere
 *     ancora «progetto-precedente-…» (tools/crypto/migrate_hkdf_prefix.php). Si
 *     aprono con tutti e due, e si riscrivono con quello di oggi, l'unico
 *     che l'applicazione legge.
 *   - teacher_recovery_keys.wrapped_recovery — la chiave di recupero del
 *     docente, cifrata con la master stessa (TeacherRecoveryService), anche
 *     quando è revocata: resta nel database e resta un dato della master.
 *
 * Tutto il resto — contenuti, compilazioni, mappe, verifiche, token di
 * Drive, file e chiavi dell'import da PDF — è cifrato con la KEK del
 * docente, che qui non cambia: si riavvolge l'involucro, non il contenuto.
 * Il censimento completo, con le firme degli export per l'autorità che
 * invece cambiano, sta nella guida.
 *
 * ── Come ──────────────────────────────────────────────────────────────────
 *
 *   prova()     conta e controlla che ogni elemento si apra con la vecchia;
 *               non scrive niente;
 *   applica()   ricifra con la nuova, IV nuovo, una transazione per docente:
 *               un'interruzione lascia ogni docente tutto vecchio o tutto
 *               nuovo, e un secondo giro finisce il lavoro. Un elemento che
 *               si apre già con la nuova si salta; uno che non si apre con
 *               nessuna delle due si elenca e non si tocca;
 *   verifica()  ogni elemento si apre con la nuova? Elenca per id quelli che
 *               si aprono solo con la vecchia.
 *
 * Non restituisce niente di segreto: conteggi e id («docente/vN» per le
 * chiavi, «docente» per il recupero). Il giro si può limitare a un elenco di
 * docenti (le prove non toccano altro); senza elenco lavora su tutti.
 *
 * ── Il giro ha guardato tutto? ────────────────────────────────────────────
 *
 * Ogni giro conta anche le righe con COUNT(*) di teacher_keys e
 * teacher_recovery_keys — tutte, revocate comprese, dei docenti dell'elenco
 * o di tutti — e le confronta con gli elementi esaminati. Se non coincidono
 * il giro non è riuscito. Senza questo confronto, un giro che per un difetto
 * non trovasse nessun docente risponderebbe «tutto a posto» con zero
 * elementi, e la procedura arriverebbe allo scambio delle chiavi con i dati
 * ancora avvolti con la vecchia (verificato il 23/9/2026: era così).
 *
 * La prova e la verifica leggono in una sola transazione di sola lettura,
 * quindi da una fotografia sola: a sito acceso, una chiave nata a metà giro
 * non fa risultare il conteggio diverso dagli elementi. L'applicazione
 * lavora a sito fermo, e conta alla fine.
 *
 * @phpstan-type Conti array{
 *     totale: int,
 *     nel_database: int,
 *     con_la_nuova: int,
 *     con_la_vecchia: int,
 *     prefisso_storico: int,
 *     ricifrati: int,
 *     solo_con_la_vecchia: list<string>,
 *     illeggibili: list<string>
 * }
 * @phpstan-type Esito array{modo: string, docenti: int, chiavi: Conti, recupero: Conti}
 */
final class RiavvolgimentoDellaMaster
{
    public const PROVA = 'prova';
    public const APPLICA = 'applica';
    public const VERIFICA = 'verifica';

    /**
     * Uguale a TeacherCryptoService::HKDF_INFO_PREFIX, che lì è privata. Se
     * divergessero, le prove d'integrazione se ne accorgono: dopo il giro
     * decifrano con TeacherCryptoService vero.
     */
    private const PREFISSO = 'pantedu-teacher-kek-v1';
    private const PREFISSO_STORICO = 'progetto-precedente-teacher-kek-v1';

    private const CIFRARIO = 'aes-256-gcm';
    private const IV = 12;
    private const TAG = 16;
    private const KEK = 32;

    private PDO $pdo;
    private string $vecchia;
    private string $nuova;
    /** @var list<int>|null */
    private ?array $soloDocenti;

    /**
     * @param list<int>|null $soloDocenti null = tutti i docenti che hanno una
     *                                    chiave o una chiave di recupero
     */
    public function __construct(PDO $pdo, string|ChiaveMadre $vecchia, string|ChiaveMadre $nuova, ?array $soloDocenti = null)
    {
        [$this->vecchia, $this->nuova] = self::controllaLeChiavi($vecchia, $nuova);
        if ($soloDocenti !== null) {
            $soloDocenti = array_values(array_unique(array_map('intval', $soloDocenti)));
            if ($soloDocenti === [] || min($soloDocenti) <= 0) {
                // Un elenco vuoto non vuol dire «tutti»: chi lo passa voleva
                // limitare il giro, e lavorare su tutto sarebbe l'opposto.
                throw new InvalidArgumentException('elenco di docenti vuoto o con id non validi');
            }
        }
        $this->pdo = $pdo;
        $this->soloDocenti = $soloDocenti;
    }

    /**
     * Le due chiavi in binario, o il rifiuto. La regola di validità è quella di
     * ChiaveMadre, l'unica per tutti i lettori della master (A-36): 64
     * caratteri esadecimali, da `\A` a `\z` (un a capo finale non passa). Le
     * chiavi arrivano come stringhe (le prove) o già lette da ChiaveMadre (lo
     * script). Il messaggio nomina la variabile, mai il valore.
     *
     * @return array{0: string, 1: string}
     */
    public static function controllaLeChiavi(string|ChiaveMadre $vecchia, string|ChiaveMadre $nuova): array
    {
        $vecchia = self::chiave($vecchia, 'KMS_MASTER_KEY');
        $nuova = self::chiave($nuova, 'KMS_MASTER_KEY_NEW');
        if (hash_equals($vecchia, $nuova)) {
            throw new InvalidArgumentException(
                'KMS_MASTER_KEY_NEW è uguale a KMS_MASTER_KEY: non c\'è niente da cambiare'
            );
        }
        return [$vecchia, $nuova];
    }

    private static function chiave(string|ChiaveMadre $valore, string $nome): string
    {
        $chiave = $valore instanceof ChiaveMadre ? $valore : ChiaveMadre::daValore($valore);
        if (!$chiave->presente()) {
            throw new InvalidArgumentException("$nome assente");
        }
        if (!$chiave->valida()) {
            throw new InvalidArgumentException(
                "$nome non è di 64 caratteri esadecimali (ne ha " . $chiave->lunghezza() . ')'
            );
        }
        return $chiave->byte();
    }

    /** @return Esito */
    public function prova(): array
    {
        return $this->giro(self::PROVA);
    }

    /** @return Esito */
    public function applica(): array
    {
        return $this->giro(self::APPLICA);
    }

    /** @return Esito */
    public function verifica(): array
    {
        return $this->giro(self::VERIFICA);
    }

    /**
     * Il giro è andato bene? Sempre: ha esaminato tutte le righe che il
     * database conta, e niente è illeggibile. Per la verifica, in più,
     * niente si apre ancora solo con la vecchia.
     *
     * @param Esito $esito
     */
    public static function riuscito(array $esito): bool
    {
        foreach (['chiavi', 'recupero'] as $tipo) {
            if ($esito[$tipo]['totale'] !== $esito[$tipo]['nel_database']) {
                return false;
            }
            if ($esito[$tipo]['illeggibili'] !== []) {
                return false;
            }
            if ($esito['modo'] === self::VERIFICA && $esito[$tipo]['con_la_vecchia'] > 0) {
                return false;
            }
        }
        return true;
    }

    /** @return Esito */
    private function giro(string $modo): array
    {
        $esito = [
            'modo'     => $modo,
            'docenti'  => 0,
            'chiavi'   => self::vuoto(),
            'recupero' => self::vuoto(),
        ];
        $scrive = $modo === self::APPLICA;

        if (!$scrive) {
            // Una fotografia sola per elenco, righe e conteggi (vedi in
            // testa): in REPEATABLE READ la fissa la prima lettura. Vale per
            // la transazione che segue, e in sola lettura.
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->pdo->beginTransaction();
            try {
                foreach ($this->docenti() as $docente) {
                    $esito['docenti']++;
                    $this->chiaviDelDocente($docente, false, $esito['chiavi']);
                    $this->recuperoDelDocente($docente, false, $esito['recupero']);
                }
                $this->contaNelDatabase($esito);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
            return $esito;
        }

        foreach ($this->docenti() as $docente) {
            $esito['docenti']++;
            // Una transazione per docente: le sue versioni di chiave e la sua
            // chiave di recupero cambiano insieme o non cambiano. Le righe si
            // leggono con FOR UPDATE, così nessuno le riscrive a metà.
            $this->pdo->beginTransaction();
            try {
                $this->chiaviDelDocente($docente, true, $esito['chiavi']);
                $this->recuperoDelDocente($docente, true, $esito['recupero']);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new RuntimeException(
                    "riavvolgimento interrotto al docente $docente: le sue righe sono rimaste com'erano, "
                    . 'quelle dei docenti prima di lui sono già nuove. Si ripete il giro: '
                    . 'gli elementi già nuovi si saltano',
                    0,
                    $e
                );
            }
        }
        $this->contaNelDatabase($esito);
        return $esito;
    }

    /**
     * Le righe che il giro doveva esaminare, contate dal database senza
     * passare dall'elenco dei docenti: tutte, revocate comprese, dei docenti
     * dell'elenco o di tutti.
     *
     * @param Esito $esito
     */
    private function contaNelDatabase(array &$esito): void
    {
        $filtro = '';
        $parametri = [];
        if ($this->soloDocenti !== null) {
            $filtro = ' WHERE %s IN (' . implode(',', array_fill(0, count($this->soloDocenti), '?')) . ')';
            $parametri = $this->soloDocenti;
        }
        $tabelle = ['chiavi' => ['teacher_keys', 'teacher_id'], 'recupero' => ['teacher_recovery_keys', 'user_id']];
        foreach ($tabelle as $tipo => [$tabella, $colonna]) {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM $tabella" . sprintf($filtro, $colonna));
            $st->execute($parametri);
            $esito[$tipo]['nel_database'] = (int)$st->fetchColumn();
        }
    }

    /** @return list<int> in ordine crescente */
    private function docenti(): array
    {
        $ids = array_map(
            'intval',
            $this->pdo->query(
                'SELECT teacher_id FROM teacher_keys
                 UNION
                 SELECT user_id FROM teacher_recovery_keys
                 ORDER BY 1'
            )->fetchAll(PDO::FETCH_COLUMN)
        );
        if ($this->soloDocenti !== null) {
            $ids = array_intersect($ids, $this->soloDocenti);
        }
        return array_values($ids);
    }

    /** @param Conti $conti */
    private function chiaviDelDocente(int $docente, bool $scrive, array &$conti): void
    {
        $sql = 'SELECT key_version, wrapped_kek FROM teacher_keys WHERE teacher_id = ? ORDER BY key_version';
        $st = $this->pdo->prepare($sql . ($scrive ? ' FOR UPDATE' : ''));
        $st->execute([$docente]);

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $riga) {
            $versione = (int)$riga['key_version'];
            [$stato, $kek] = $this->apriChiave($docente, $versione, (string)$riga['wrapped_kek']);
            self::conta($conti, $stato, $docente . '/v' . $versione);
            if (!$scrive || $kek === null || $stato === 'nuova') {
                continue;
            }
            // Sempre col prefisso di oggi: una riga ancora col prefisso
            // storico l'applicazione non la leggeva, e da qui in poi sì.
            $this->pdo->prepare(
                'UPDATE teacher_keys SET wrapped_kek = ? WHERE teacher_id = ? AND key_version = ?'
            )->execute([
                $this->chiudi($kek, $this->tkek($this->nuova, self::PREFISSO, $docente, $versione)),
                $docente,
                $versione,
            ]);
            $conti['ricifrati']++;
        }
    }

    /** @param Conti $conti */
    private function recuperoDelDocente(int $docente, bool $scrive, array &$conti): void
    {
        $sql = 'SELECT wrapped_recovery FROM teacher_recovery_keys WHERE user_id = ?';
        $st = $this->pdo->prepare($sql . ($scrive ? ' FOR UPDATE' : ''));
        $st->execute([$docente]);
        $avvolta = $st->fetchColumn();
        if ($avvolta === false || $avvolta === null) {
            return;
        }
        $avvolta = (string)$avvolta;

        $r = $this->apri($avvolta, $this->nuova);
        $stato = 'nuova';
        if ($r === null) {
            $r = $this->apri($avvolta, $this->vecchia);
            $stato = $r === null ? 'illeggibile' : 'vecchia';
        }
        self::conta($conti, $stato, (string)$docente);
        if (!$scrive || $r === null || $stato === 'nuova') {
            return;
        }
        $this->pdo->prepare('UPDATE teacher_recovery_keys SET wrapped_recovery = ? WHERE user_id = ?')
            ->execute([$this->chiudi($r, $this->nuova), $docente]);
        $conti['ricifrati']++;
    }

    /**
     * Con quale chiave si apre la KEK avvolta: prima la nuova (già fatta),
     * poi la vecchia col prefisso di oggi, poi la vecchia con lo storico.
     *
     * @return array{0: string, 1: ?string} [nuova|vecchia|storica|illeggibile, KEK in chiaro]
     */
    private function apriChiave(int $docente, int $versione, string $avvolta): array
    {
        if (strlen($avvolta) !== self::IV + self::KEK + self::TAG) {
            return ['illeggibile', null];
        }
        $tentativi = [
            'nuova'   => [$this->nuova, self::PREFISSO],
            'vecchia' => [$this->vecchia, self::PREFISSO],
            'storica' => [$this->vecchia, self::PREFISSO_STORICO],
        ];
        foreach ($tentativi as $stato => [$master, $prefisso]) {
            $kek = $this->apri($avvolta, $this->tkek($master, $prefisso, $docente, $versione));
            if ($kek !== null && strlen($kek) === self::KEK) {
                return [$stato, $kek];
            }
        }
        return ['illeggibile', null];
    }

    /** Come TeacherCryptoService::deriveTkek(), con la master e il prefisso scelti. */
    private function tkek(string $master, string $prefisso, int $docente, int $versione): string
    {
        return hash_hkdf('sha256', $master, 32, (string)$versione, $prefisso . '|' . $docente);
    }

    /** AES-256-GCM, forma iv(12) || ct || tag(16), come nei due servizi. */
    private function apri(string $blob, string $chiave): ?string
    {
        if (strlen($blob) <= self::IV + self::TAG) {
            return null;
        }
        $chiaro = openssl_decrypt(
            substr($blob, self::IV, -self::TAG),
            self::CIFRARIO,
            $chiave,
            OPENSSL_RAW_DATA,
            substr($blob, 0, self::IV),
            substr($blob, -self::TAG)
        );
        return $chiaro === false ? null : $chiaro;
    }

    private function chiudi(string $chiaro, string $chiave): string
    {
        $iv = random_bytes(self::IV);
        $tag = '';
        $ct = openssl_encrypt($chiaro, self::CIFRARIO, $chiave, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG);
        if ($ct === false) {
            throw new RuntimeException('cifratura fallita');
        }
        return $iv . $ct . $tag;
    }

    /** @param Conti $conti */
    private static function conta(array &$conti, string $stato, string $id): void
    {
        $conti['totale']++;
        if ($stato === 'nuova') {
            $conti['con_la_nuova']++;
        } elseif ($stato === 'vecchia' || $stato === 'storica') {
            $conti['con_la_vecchia']++;
            $conti['solo_con_la_vecchia'][] = $id;
            if ($stato === 'storica') {
                $conti['prefisso_storico']++;
            }
        } else {
            $conti['illeggibili'][] = $id;
        }
    }

    /** @return Conti */
    private static function vuoto(): array
    {
        return [
            'totale'              => 0,
            'nel_database'        => 0,
            'con_la_nuova'        => 0,
            'con_la_vecchia'      => 0,
            'prefisso_storico'    => 0,
            'ricifrati'           => 0,
            'solo_con_la_vecchia' => [],
            'illeggibili'         => [],
        ];
    }
}
