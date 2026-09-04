<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit;

use App\Core\Config;
use App\Core\Database;
use App\Services\Audit\ActivityLogger;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Il registro delle operazioni (migration 098).
 *
 * Due proprieta' contano piu' delle altre, ed entrambe nascono da un difetto
 * trovato in produzione il 2026-09-02.
 *
 * `una_richiesta_negata_finisce_a_registro`: il vecchio middleware loggava
 * solo le risposte sotto il 400, cioe' buttava via esattamente i tentativi
 * che un audit serve a ritrovare. Se qualcuno un giorno rimettesse un filtro
 * sullo status "per ridurre il rumore", questo test glielo dice.
 *
 * `l_indirizzo_non_si_conserva_in_chiaro`: da qui passano anche le operazioni
 * degli studenti, minorenni compresi. L'IP resta confrontabile con uno noto
 * ma non si legge.
 */
final class ActivityLoggerTest extends TestCase
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

        // Schema allineato a database/migrations/098.
        $this->pdo->exec(
            'CREATE TABLE audit_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                occurred_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actor_user_id INTEGER,
                actor_name TEXT NOT NULL DEFAULT "anonymous",
                actor_role TEXT NOT NULL DEFAULT "guest",
                action TEXT NOT NULL DEFAULT "http_request",
                method TEXT NOT NULL DEFAULT "-",
                path TEXT NOT NULL DEFAULT "",
                status INTEGER,
                outcome TEXT NOT NULL DEFAULT "ok",
                subject_type TEXT,
                subject_id TEXT,
                details_json TEXT,
                ip_hash BLOB,
                ua_hash BLOB,
                request_id TEXT
            )'
        );

        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->pdo);
        Config::set('database.enabled', true);

        $_SERVER['REMOTE_ADDR']     = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['REQUEST_METHOD']  = 'POST';
        $_SERVER['REQUEST_URI']     = '/test';
    }

    protected function tearDown(): void
    {
        Database::reset();
        $this->pdo = null;
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'],
              $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['X_REQUEST_ID']);
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        return $this->pdo->query('SELECT * FROM audit_activity_log ORDER BY id')->fetchAll();
    }

    #[Test]
    public function una_lettura_riuscita_non_sporca_il_registro(): void
    {
        // Le GET a buon fine restano fuori: per la navigazione c'e'
        // access_log.json, e registrare ogni pagina vista di ogni studente
        // sarebbe sproporzionato.
        self::assertFalse(ActivityLogger::shouldLogRequest('GET', 200));
        self::assertFalse(ActivityLogger::shouldLogRequest('HEAD', 304));
    }

    #[Test]
    public function ogni_scrittura_finisce_a_registro(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::assertTrue(
                ActivityLogger::shouldLogRequest($method, 200),
                "$method e' un'operazione e va registrata"
            );
        }
    }

    #[Test]
    public function una_richiesta_negata_finisce_a_registro(): void
    {
        // Anche se e' una GET, e anche da anonimo: il tentativo respinto su
        // una risorsa protetta e' il caso che un audit serve a trovare.
        foreach ([401, 403, 429] as $status) {
            self::assertTrue(
                ActivityLogger::shouldLogRequest('GET', $status, '/admin/logs', false),
                "un $status va registrato anche se chi lo riceve non e' autenticato"
            );
        }
    }

    #[Test]
    public function le_scansioni_anonime_non_riempiono_il_registro(): void
    {
        // Un anonimo che colleziona 404 e' uno scanner: ne produce migliaia e
        // ha gia' waf_logs. Lo stesso 404 preso da un utente autenticato e'
        // invece un fatto da poter ricostruire.
        self::assertFalse(ActivityLogger::shouldLogRequest('GET', 404, '/wp-admin.php', false));
        self::assertFalse(ActivityLogger::shouldLogRequest('GET', 500, '/qualcosa', false));
        self::assertTrue(ActivityLogger::shouldLogRequest('GET', 404, '/studio/mappa/x', true));

        // Una scrittura resta un'operazione anche da anonimo (login, iscrizione).
        self::assertTrue(ActivityLogger::shouldLogRequest('POST', 200, '/register', false));
    }

    #[Test]
    public function i_beacon_di_telemetria_restano_fuori(): void
    {
        // Il browser li manda da solo, molti al minuto, e non cambiano niente
        // di conservato: se entrassero coprirebbero le righe che contano.
        self::assertFalse(ActivityLogger::shouldLogRequest('POST', 204, '/analytics/nav'));
        self::assertFalse(ActivityLogger::shouldLogRequest('POST', 200, '/api/vitals'));
        self::assertFalse(ActivityLogger::shouldLogRequest('POST', 200, '/waf/fingerprint'));
        self::assertFalse(ActivityLogger::shouldLogRequest('POST', 200, '/tikz/render'));
        self::assertFalse(ActivityLogger::shouldLogRequest('POST', 429, '/api/vitals?v=1'));

        // Un percorso che comincia allo stesso modo ma è un'altra cosa resta dentro.
        self::assertTrue(ActivityLogger::shouldLogRequest('POST', 200, '/api/vitals-config'));
        self::assertTrue(ActivityLogger::shouldLogRequest('POST', 200, '/api/teacher/content/1/delete'));
    }

    #[Test]
    public function la_riga_conserva_metodo_percorso_e_stato(): void
    {
        ActivityLogger::request('DELETE', '/api/teacher/content/9/delete', 200);

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('DELETE', $rows[0]['method']);
        self::assertSame('/api/teacher/content/9/delete', $rows[0]['path']);
        self::assertSame(200, (int)$rows[0]['status']);
        self::assertSame('ok', $rows[0]['outcome']);
        self::assertSame('http_request', $rows[0]['action']);
    }

    #[Test]
    public function un_403_viene_classificato_come_negato(): void
    {
        ActivityLogger::request('GET', '/admin/logs', 403);
        ActivityLogger::request('POST', '/api/qualcosa', 500);

        $rows = $this->rows();
        self::assertSame('denied', $rows[0]['outcome']);
        self::assertSame('error', $rows[1]['outcome']);
    }

    #[Test]
    public function l_indirizzo_non_si_conserva_in_chiaro(): void
    {
        ActivityLogger::request('POST', '/register', 302);

        $stored = $this->rows()[0]['ip_hash'];
        self::assertNotSame('203.0.113.7', $stored);
        self::assertSame(hash('sha256', '203.0.113.7', true), $stored);
    }

    #[Test]
    public function un_evento_di_dominio_porta_soggetto_e_dettagli(): void
    {
        ActivityLogger::event(
            'parent_consent_granted',
            subjectType: 'user',
            subjectId:   '42',
            details:     ['consent_id' => 7],
        );

        $row = $this->rows()[0];
        self::assertSame('parent_consent_granted', $row['action']);
        self::assertSame('user', $row['subject_type']);
        self::assertSame('42', $row['subject_id']);
        self::assertSame(['consent_id' => 7], json_decode((string)$row['details_json'], true));
    }

    #[Test]
    public function un_attore_di_sistema_puo_essere_dichiarato(): void
    {
        // I job da cron non hanno sessione: senza questo, la riga risulterebbe
        // di un anonimo e non si capirebbe che l'ha scritta la manutenzione.
        ActivityLogger::event('parent_consent_cleanup', actorName: 'cron', actorRole: 'system');

        $row = $this->rows()[0];
        self::assertSame('cron', $row['actor_name']);
        self::assertSame('system', $row['actor_role']);
    }

    #[Test]
    public function senza_database_non_esplode(): void
    {
        Config::set('database.enabled', false);
        ActivityLogger::request('POST', '/qualsiasi', 200);
        self::assertSame([], $this->rows());
    }
}
