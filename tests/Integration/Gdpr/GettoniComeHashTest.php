<?php

declare(strict_types=1);

namespace Tests\Integration\Gdpr;

use App\Controllers\ParentConsentController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Services\Gdpr\DeletionRequestService;
use App\Services\Gdpr\ParentConsentService;
use App\Services\RegistrationService;
use App\Support\ImprontaIp;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * I gettoni di conferma di cancellazione e di consenso del genitore si
 * conservano come hash (23/9/2026, A-67 della revisione architetturale).
 *
 * ── Il difetto ────────────────────────────────────────────────────────────
 *
 * `deletion_requests.confirm_token` e `parent_consents.confirm_token`
 * tenevano il gettone in chiaro. Chi leggeva il database — un dump, una copia
 * locale, un backup — poteva confermare una cancellazione pendente, che dopo
 * il ripensamento porta al crypto-shredding, o dare e rifiutare il consenso
 * dell'art. 8 al posto del genitore. In piu' `RegistrationService` scriveva
 * il gettone del genitore in error_log, insieme al suo indirizzo.
 *
 * ── Le due direzioni ──────────────────────────────────────────────────────
 *
 * Il gettone dell'email conferma, e il valore letto dal database, usato come
 * gettone, NO. La seconda e' la prova che fallisce sul codice di prima: li'
 * il valore del database ERA il gettone. La prima impedisce che la seconda
 * passi per il motivo banale di un servizio che non conferma piu' niente.
 *
 * Le cancellazioni e l'approvazione girano in una transazione annullata alla
 * fine. Il consenso confermato no: `confirm()` apre una transazione sua, e
 * PDO non le annida. Quelle righe si cancellano a mano in tearDown.
 */
final class GettoniComeHashTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $studenti = [];
    private string $registroPrima = '';
    private string $registro = '';
    private string $cartella = '';
    private mixed $mittentePrima = null;

    protected function setUp(): void
    {
        if (!Database::isAvailable()) {
            self::markTestSkipped('database non disponibile');
        }
        $this->pdo = Database::connection();
        $this->registroPrima = (string)ini_get('error_log');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        foreach ($this->studenti as $id) {
            $this->pdo->prepare('DELETE FROM parent_consents WHERE student_user_id = ?')->execute([$id]);
            // I registri tengono le righe di prova solo se l'utenza non puo'
            // cancellarle: la prova non deve fallire per questo.
            try {
                $this->pdo->prepare('DELETE FROM consent_audit WHERE user_id = ?')->execute([$id]);
                $this->pdo->prepare(
                    'DELETE FROM audit_activity_log WHERE subject_type = "user" AND subject_id = ?'
                )->execute([(string)$id]);
            } catch (\Throwable) {
            }
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        ini_set('error_log', $this->registroPrima);
        if ($this->cartella !== '') {
            foreach (glob($this->cartella . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->cartella);
        }
        if ($this->mittentePrima !== null) {
            Config::set('mail.from', $this->mittentePrima);
        }
    }

    // ── Cancellazione (art. 17) ─────────────────────────────────────────────

    #[Test]
    public function la_cancellazione_conserva_l_hash_e_non_il_gettone(): void
    {
        $this->pdo->beginTransaction();
        $gettone = (new DeletionRequestService())->request($this->docente(), 'prova gettoni', '203.0.113.9');

        $riga = $this->riga('deletion_requests', 'confirm_token = ?', hash('sha256', $gettone));
        $this->assertNotNull($riga, 'nella tabella c\'e\' l\'hash del gettone');
        $this->assertNessunaColonnaContiene($gettone, $riga);
        $this->assertSame(
            0,
            $this->quanteContengono('deletion_requests', $gettone),
            'e il gettone non c\'e\', neanche come pezzo'
        );
        // L'indirizzo della richiesta: impronta con chiave dal 24/9/2026.
        $this->assertNotNull(ImprontaIp::di('203.0.113.9'));
        $this->assertSame(ImprontaIp::di('203.0.113.9'), $riga['request_ip_hash']);
        $this->assertNotSame(hash('sha256', '203.0.113.9', true), $riga['request_ip_hash']);
    }

    #[Test]
    public function il_gettone_dell_email_conferma_la_cancellazione(): void
    {
        $this->pdo->beginTransaction();
        $svc = new DeletionRequestService();
        $gettone = $svc->request($this->docente());

        $this->assertTrue($svc->confirm($gettone), 'il collegamento dell\'email conferma');
    }

    #[Test]
    public function il_valore_del_database_non_conferma_la_cancellazione(): void
    {
        $this->pdo->beginTransaction();
        $svc = new DeletionRequestService();
        $utente = $this->docente();
        $svc->request($utente);

        $st = $this->pdo->prepare(
            'SELECT confirm_token FROM deletion_requests WHERE user_id = ? AND status = "pending_confirm"'
        );
        $st->execute([$utente]);
        $dalDatabase = (string)$st->fetchColumn();
        $this->assertNotSame('', $dalDatabase, 'la richiesta e\' stata scritta');

        $this->assertFalse($svc->confirm($dalDatabase), 'chi legge il database non conferma la cancellazione');
        $stato = $svc->activeRequest($utente)['status'] ?? null;
        $this->assertSame('pending_confirm', $stato, 'e la richiesta resta in attesa');
    }

    #[Test]
    public function la_richiesta_di_cancellazione_non_scrive_il_gettone_nel_registro(): void
    {
        $this->registroTemporaneo();
        $sentinella = 'sentinella-' . bin2hex(random_bytes(6));
        error_log($sentinella);

        $this->pdo->beginTransaction();
        $gettone = (new DeletionRequestService())->request($this->docente(), 'prova registro', '203.0.113.9');

        $scritto = (string)file_get_contents($this->registro);
        $this->assertStringContainsString($sentinella, $scritto, 'il registro catturato e\' quello giusto');
        $this->assertStringNotContainsString($gettone, $scritto, 'il gettone non va nel registro');
        $this->assertStringNotContainsString(hash('sha256', $gettone), $scritto, 'e nemmeno il suo hash');
    }

    // ── Consenso del genitore (art. 8) ──────────────────────────────────────

    #[Test]
    public function il_consenso_conserva_l_hash_e_non_il_gettone(): void
    {
        $studente = $this->minore();
        $gettone = (new ParentConsentService())->request($studente, 'genitore.gettoni@example.invalid', 'Genitore');

        $riga = $this->riga('parent_consents', 'confirm_token = ?', hash('sha256', $gettone));
        $this->assertNotNull($riga, 'nella tabella c\'e\' l\'hash del gettone');
        $this->assertNessunaColonnaContiene($gettone, $riga);
        $this->assertSame(
            0,
            $this->quanteContengono('parent_consents', $gettone),
            'e il gettone non c\'e\', neanche come pezzo'
        );
    }

    #[Test]
    public function il_gettone_dell_email_apre_la_pagina_e_conferma_il_consenso(): void
    {
        $studente = $this->minore();
        $svc = new ParentConsentService();
        $gettone = $svc->request($studente, 'genitore.gettoni@example.invalid');

        $pagina = $this->anteprima($gettone);
        $this->assertSame(200, $pagina->status, 'il collegamento dell\'email apre la pagina');
        $this->assertStringContainsString('Consenso parentale richiesto', $pagina->body);

        $esito = $svc->confirm($gettone, '203.0.113.9', 'Prova');
        $this->assertTrue($esito['ok'], 'e conferma il consenso');
        $st = $this->pdo->prepare('SELECT active FROM users WHERE id = ?');
        $st->execute([$studente]);
        $this->assertSame(1, (int)$st->fetchColumn(), 'l\'account del minore si attiva');
    }

    #[Test]
    public function il_valore_del_database_non_apre_non_conferma_e_non_rifiuta(): void
    {
        $studente = $this->minore();
        $svc = new ParentConsentService();
        $svc->request($studente, 'genitore.gettoni@example.invalid');

        $st = $this->pdo->prepare('SELECT confirm_token FROM parent_consents WHERE student_user_id = ?');
        $st->execute([$studente]);
        $dalDatabase = (string)$st->fetchColumn();
        $this->assertNotSame('', $dalDatabase, 'la richiesta e\' stata scritta');

        $this->assertSame(404, $this->anteprima($dalDatabase)->status, 'la pagina del genitore non si apre');
        $conferma = $svc->confirm($dalDatabase, '203.0.113.9', 'Prova');
        $this->assertFalse($conferma['ok'], 'il consenso non si da\' con il valore del database');
        $rifiuto = $svc->reject($dalDatabase, '203.0.113.9', 'Prova');
        $this->assertFalse($rifiuto['ok'], 'e non si rifiuta');
        $this->assertSame('pending', $svc->findByStudent($studente)['status'] ?? null, 'la richiesta resta in attesa');
    }

    /**
     * La ricerca della pagina del genitore è passata dal controller al
     * servizio (`anteprima`): deve trovare la richiesta in ogni stato, perché
     * la pagina dice «Già confermato» o «Consenso revocato», non 404.
     */
    #[Test]
    public function la_pagina_del_genitore_si_apre_anche_dopo_la_conferma_e_la_revoca(): void
    {
        $studente = $this->minore();
        $svc = new ParentConsentService();
        $gettone = $svc->request($studente, 'genitore.gettoni@example.invalid');
        $this->assertTrue($svc->confirm($gettone, '203.0.113.9', 'Prova')['ok'], 'precondizione: confermato');

        $pagina = $this->anteprima($gettone);
        $this->assertSame(200, $pagina->status, 'dopo la conferma la pagina si apre');
        $this->assertStringContainsString('Già confermato', $pagina->body);

        $this->pdo->prepare('UPDATE parent_consents SET status = "revoked" WHERE student_user_id = ?')
            ->execute([$studente]);
        $pagina = $this->anteprima($gettone);
        $this->assertSame(200, $pagina->status, 'dopo la revoca la pagina si apre');
        $this->assertStringContainsString('Consenso revocato', $pagina->body);
    }

    // ── Il registro degli errori ────────────────────────────────────────────

    #[Test]
    public function l_approvazione_di_un_minore_non_scrive_gettone_ne_indirizzo_nel_registro(): void
    {
        // Senza mittente il servizio non manda posta: la prova non deve
        // scrivere a nessuno, e il gettone resta solo nel valore restituito.
        $this->mittentePrima = Config::get('mail.from', '');
        Config::set('mail.from', '');

        $this->registroTemporaneo();
        // Il registro e' davvero questo file: senza la sentinella, «non
        // contiene il gettone» passerebbe anche se error_log scrivesse altrove.
        $sentinella = 'sentinella-' . bin2hex(random_bytes(6));
        error_log($sentinella);

        $this->pdo->beginTransaction();
        $nome = 'zzgettoni' . bin2hex(random_bytes(4));
        $genitore = $nome . '.genitore@example.invalid';
        file_put_contents($this->cartella . '/registrations.json', json_encode(['pending' => [[
            'id'            => 'reg_' . $nome,
            'username'      => $nome,
            'role'          => 'student',
            'first_name'    => 'Zz',
            'last_name'     => 'Gettoni',
            'email'         => $nome . '@example.invalid',
            'password_hash' => 'x',
            'status'        => 'pending',
            'created'       => date('Y-m-d H:i:s'),
            'birth_date'    => date('Y-m-d', strtotime('-12 years')),
            'is_minor'      => true,
            'parent_email'  => $genitore,
            'parent_name'   => 'Genitore',
        ]], 'history' => []]));
        $servizio = new RegistrationService($this->cartella . '/registrations.json');

        $servizio->approve('reg_' . $nome, 'prova');

        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM parent_consents pc JOIN users u ON u.id = pc.student_user_id WHERE u.username = ?'
        );
        $st->execute([$nome]);
        $this->assertSame(1, (int)$st->fetchColumn(), 'la richiesta al genitore e\' partita');

        $scritto = (string)file_get_contents($this->registro);
        $this->assertStringContainsString($sentinella, $scritto, 'il registro catturato e\' quello giusto');
        $this->assertStringNotContainsString($genitore, $scritto, 'l\'indirizzo del genitore non va nel registro');
        // Il gettone non si conosce: approve() non lo restituisce. Si prova
        // allora ogni stringa del registro che ne ha la forma: nessuna deve
        // aprire la pagina del genitore.
        preg_match_all('/[0-9a-f]{64}/', $scritto, $trovati);
        foreach (array_unique($trovati[0]) as $candidato) {
            $this->assertSame(404, $this->anteprima($candidato)->status, 'nel registro non c\'e\' un gettone valido');
        }
    }

    // ── Appoggi ─────────────────────────────────────────────────────────────

    /** error_log in un file di una cartella temporanea, cancellata in tearDown. */
    private function registroTemporaneo(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu_gettoni_' . bin2hex(random_bytes(4));
        mkdir($this->cartella, 0700, true);
        $this->registro = $this->cartella . '/errori.log';
        ini_set('error_log', $this->registro);
    }

    private function docente(): int
    {
        $nome = 'zzgettoni' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Gettoni", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Uno studente di dodici anni in attesa del consenso, fuori transazione. */
    private function minore(): int
    {
        $nome = 'zzgettoni' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, birth_date)
             VALUES (?, "student", "Zz", "Gettoni", ?, "x", "pending_parent_consent", 0, ?)'
        )->execute([$nome, "$nome@example.invalid", date('Y-m-d', strtotime('-12 years'))]);
        $id = (int)$this->pdo->lastInsertId();
        $this->studenti[] = $id;
        return $id;
    }

    private function anteprima(string $gettone): \App\Core\Response
    {
        return (new ParentConsentController())->preview(new Request(), ['token' => $gettone]);
    }

    /** @return array<string,mixed>|null */
    private function riga(string $tabella, string $dove, string $valore): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM $tabella WHERE $dove");
        $st->execute([$valore]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return \is_array($r) ? $r : null;
    }

    private function quanteContengono(string $tabella, string $gettone): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM $tabella WHERE confirm_token LIKE ?");
        $st->execute(['%' . $gettone . '%']);
        return (int)$st->fetchColumn();
    }

    /** @param array<string,mixed> $riga */
    private function assertNessunaColonnaContiene(string $gettone, array $riga): void
    {
        foreach ($riga as $colonna => $valore) {
            $this->assertStringNotContainsString(
                $gettone,
                (string)$valore,
                "la colonna $colonna non contiene il gettone"
            );
        }
    }
}
