<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr;

use App\Core\Config;
use App\Core\Database;
use App\Services\Gdpr\ParentConsentService;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Il consenso genitoriale lascia traccia in `consent_audit`.
 *
 * IL DIFETTO CHE QUESTI TEST FISSANO (2026-09-02)
 *
 * `reject()` scriveva la sua riga di audit; `confirm()` no. Cioe' il rifiuto
 * era documentato e il consenso *concesso* spariva — proprio l'evento che
 * costituisce la base giuridica per trattare i dati di un minore (art. 8
 * GDPR) e che attiva il suo account. Chi registrava uno studente minorenne e
 * poi andava a cercare la conferma del genitore nei registri non trovava
 * niente, e non aveva modo di capire se il problema fosse il registro o il
 * consenso.
 *
 * Da qui l'insistenza dei test sul percorso felice: e' quello che mancava.
 */
final class ParentConsentAuditTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Il servizio scrive i timestamp con NOW(), che SQLite non conosce.
        // Si aggiunge invece di riscrivere le query: cambiarle solo per far
        // girare un test significherebbe testare un codice diverso da quello
        // che va in produzione.
        $this->pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'), 0);

        // Schema allineato a database/migrations/015 + 098.
        $this->pdo->exec(
            'CREATE TABLE parent_consents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_user_id INTEGER NOT NULL,
                parent_email TEXT NOT NULL,
                parent_name TEXT,
                confirm_token TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                requested_at TEXT DEFAULT CURRENT_TIMESTAMP,
                confirmed_at TEXT, revoked_at TEXT, expires_at TEXT,
                confirm_ip_hash BLOB, confirm_ua_hash BLOB
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE consent_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                consent_id INTEGER,
                user_id INTEGER NOT NULL,
                consent_type TEXT NOT NULL,
                event TEXT NOT NULL,
                text_version TEXT,
                accessed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_hash BLOB
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT, email TEXT, first_name TEXT, last_name TEXT,
                password_hash TEXT DEFAULT "", active INTEGER DEFAULT 0,
                status TEXT, approved_at TEXT, deleted_at TEXT
            )'
        );
        $this->pdo->exec(
            'INSERT INTO users (id, username, email, active, status)
             VALUES (42, "studente.minore", "minore@example.invalid", 0, "pending_parent_consent")'
        );
        // Il registro delle operazioni: qui non e' l'oggetto del test, ma
        // ParentConsentService ci scrive e senza tabella l'insert fallirebbe
        // (in silenzio, ma sporcherebbe error_log).
        $this->pdo->exec(
            'CREATE TABLE audit_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                occurred_at TEXT DEFAULT CURRENT_TIMESTAMP,
                actor_user_id INTEGER, actor_name TEXT, actor_role TEXT,
                action TEXT, method TEXT, path TEXT, status INTEGER, outcome TEXT,
                subject_type TEXT, subject_id TEXT, details_json TEXT,
                ip_hash BLOB, ua_hash BLOB, request_id TEXT
            )'
        );

        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->pdo);
        Config::set('database.enabled', true);
    }

    protected function tearDown(): void
    {
        Database::reset();
        $this->pdo = null;
    }

    /** @return list<array<string,mixed>> */
    private function audit(): array
    {
        return $this->pdo->query('SELECT * FROM consent_audit ORDER BY id')->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    private function activity(): array
    {
        return $this->pdo->query('SELECT * FROM audit_activity_log ORDER BY id')->fetchAll();
    }

    #[Test]
    public function la_richiesta_al_genitore_e_gia_un_evento(): void
    {
        (new ParentConsentService())->request(42, 'genitore@example.invalid', 'Genitore');

        $rows = $this->audit();
        self::assertCount(1, $rows);
        self::assertSame('requested', $rows[0]['event']);
        self::assertSame('parent_consent', $rows[0]['consent_type']);
        self::assertSame(42, (int)$rows[0]['user_id']);
    }

    #[Test]
    public function il_consenso_concesso_finisce_a_registro(): void
    {
        $svc = new ParentConsentService();
        $token = $svc->request(42, 'genitore@example.invalid');

        $res = $svc->confirm($token, '198.51.100.4', 'Mozilla/5.0');
        self::assertTrue($res['ok'], 'la conferma deve riuscire');

        $eventi = array_column($this->audit(), 'event');
        self::assertSame(['requested', 'granted'], $eventi);
    }

    #[Test]
    public function il_consenso_concesso_attiva_l_account(): void
    {
        $svc = new ParentConsentService();
        $token = $svc->request(42, 'genitore@example.invalid');
        $svc->confirm($token, '198.51.100.4', 'Mozilla/5.0');

        $u = $this->pdo->query('SELECT active, approved_at FROM users WHERE id = 42')->fetch();
        self::assertSame(1, (int)$u['active'], 'il consenso attiva l\'account');
        self::assertNotNull($u['approved_at']);
        // La colonna `status` non si verifica qui: la UPDATE del servizio la
        // scrive come status="active", e in SQLite le virgolette doppie sono
        // un *identificatore*, non una stringa — legge quindi la colonna
        // `active`. In MySQL, dove il codice gira, e' una stringa e basta.
    }

    #[Test]
    public function l_indirizzo_del_genitore_si_conserva_come_hash(): void
    {
        $svc = new ParentConsentService();
        $token = $svc->request(42, 'genitore@example.invalid');
        $svc->confirm($token, '198.51.100.4', 'Mozilla/5.0');

        $granted = array_values(array_filter($this->audit(), fn($r) => $r['event'] === 'granted'))[0];
        self::assertSame(hash('sha256', '198.51.100.4', true), $granted['ip_hash']);
    }

    #[Test]
    public function il_consenso_concesso_compare_nel_registro_operazioni(): void
    {
        $svc = new ParentConsentService();
        $token = $svc->request(42, 'genitore@example.invalid');
        $svc->confirm($token, '198.51.100.4', 'Mozilla/5.0');

        $azioni = array_column($this->activity(), 'action');
        self::assertContains('parent_consent_requested', $azioni);
        self::assertContains('parent_consent_granted', $azioni);
    }

    #[Test]
    public function un_token_scaduto_lascia_detto_che_e_scaduto(): void
    {
        $svc = new ParentConsentService();
        $token = $svc->request(42, 'genitore@example.invalid');
        $this->pdo->exec('UPDATE parent_consents SET expires_at = "2000-01-01 00:00:00"');

        $res = $svc->confirm($token, '198.51.100.4', 'Mozilla/5.0');
        self::assertFalse($res['ok']);
        self::assertSame('token_expired', $res['error']);

        self::assertSame(['requested', 'expired'], array_column($this->audit(), 'event'));
    }

    #[Test]
    public function la_revoca_del_genitore_finisce_a_registro(): void
    {
        $svc = new ParentConsentService();
        $token = $svc->request(42, 'genitore@example.invalid');
        $svc->confirm($token, '198.51.100.4', 'Mozilla/5.0');

        self::assertTrue($svc->revoke(42, 'genitore@example.invalid'));
        self::assertSame(['requested', 'granted', 'revoked'], array_column($this->audit(), 'event'));
    }

    #[Test]
    public function il_rifiuto_resta_documentato_come_prima(): void
    {
        $svc = new ParentConsentService();
        $token = $svc->request(42, 'genitore@example.invalid');

        $res = $svc->reject($token, '198.51.100.4', 'Mozilla/5.0');
        self::assertTrue($res['ok']);
        self::assertSame(['requested', 'revoked'], array_column($this->audit(), 'event'));
    }
}
