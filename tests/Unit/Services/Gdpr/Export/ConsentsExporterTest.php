<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr\Export;

use App\Core\Database;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\Exporters\ConsentsExporter;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Regressione: l'exporter interrogava la tabella `tos_acceptances` con le
 * colonne `version` / `ip_address`. Nessuna delle tre esiste — la tabella è
 * `user_tos_acceptance` con `tos_version`, `aup_version`, `accepted_ip`.
 * L'errore veniva inghiottito da un catch vuoto, quindi ogni export Art. 15
 * dichiarava zero accettazioni anche quando il registro era pieno.
 */
final class ConsentsExporterTest extends TestCase
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

        // Schema allineato a database/migrations/056 + 094.
        $this->pdo->exec(
            'CREATE TABLE user_tos_acceptance (
                user_id INTEGER NOT NULL,
                tos_version TEXT NOT NULL,
                aup_version TEXT NOT NULL,
                accepted_at TEXT NOT NULL,
                accepted_ip TEXT NOT NULL,
                user_agent TEXT,
                PRIMARY KEY (user_id, tos_version, aup_version)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE consents (
                id INTEGER PRIMARY KEY, user_id INTEGER, consent_type TEXT,
                granted INTEGER, granted_at TEXT, revoked_at TEXT,
                text_version TEXT, ip_address TEXT, user_agent TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE parent_consents (
                id INTEGER PRIMARY KEY, student_user_id INTEGER, parent_email TEXT,
                status TEXT, confirmed_at TEXT, revoked_at TEXT, created_at TEXT
            )'
        );

        $this->primeSingleton($this->pdo);
    }

    protected function tearDown(): void
    {
        Database::reset();
        $this->pdo = null;
    }

    private function primeSingleton(?PDO $pdo): void
    {
        $prop = new ReflectionProperty(Database::class, 'pdo');
        $prop->setValue(null, $pdo);
    }

    /**
     * @return array{payload: array<string,mixed>, summary: array<string,mixed>}
     *         consents.json e il summary della sezione
     */
    private function runExport(): array
    {
        $section = (new ConsentsExporter())->export(new ExportContext(userId: 7));
        $this->assertCount(1, $section->files);
        return [
            'payload' => json_decode($section->files[0]->content, true),
            'summary' => $section->summary,
        ];
    }

    // -----------------------------------------------------------------

    #[Test]
    public function acceptances_reach_the_export(): void
    {
        $this->pdo->exec(
            "INSERT INTO user_tos_acceptance
                (user_id, tos_version, aup_version, accepted_at, accepted_ip, user_agent)
             VALUES (7, '1.0', '1.0', '2026-05-21 09:00:00', '203.0.113.9', 'Mozilla/5.0'),
                    (7, '1.0', '1.1', '2026-08-02 11:30:00', '203.0.113.9', 'Mozilla/5.0')"
        );

        ['payload' => $payload, 'summary' => $summary] = $this->runExport();

        $this->assertCount(2, $payload['tos_acceptances']);
        $this->assertSame(2, $summary['tos_acceptances_count'] ?? null);
        $this->assertArrayNotHasKey('tos_acceptances_unavailable', $payload);

        // I metadati probatori devono esserci: senza IP e User-Agent la riga
        // non dimostra granché.
        $first = $payload['tos_acceptances'][0];
        $this->assertArrayHasKey('accepted_ip', $first);
        $this->assertArrayHasKey('user_agent', $first);
        $this->assertArrayHasKey('tos_version', $first);
        $this->assertArrayHasKey('aup_version', $first);
    }

    #[Test]
    public function most_recent_acceptance_comes_first(): void
    {
        $this->pdo->exec(
            "INSERT INTO user_tos_acceptance
                (user_id, tos_version, aup_version, accepted_at, accepted_ip)
             VALUES (7, '1.0', '1.0', '2026-05-21 09:00:00', '10.0.0.1'),
                    (7, '1.0', '1.1', '2026-08-02 11:30:00', '10.0.0.1')"
        );

        ['payload' => $payload, 'summary' => $summary] = $this->runExport();

        $this->assertSame('1.1', $payload['tos_acceptances'][0]['aup_version']);
    }

    #[Test]
    public function other_users_acceptances_are_not_leaked(): void
    {
        $this->pdo->exec(
            "INSERT INTO user_tos_acceptance
                (user_id, tos_version, aup_version, accepted_at, accepted_ip)
             VALUES (7, '1.0', '1.0', '2026-05-21 09:00:00', '10.0.0.1'),
                    (99, '1.0', '1.0', '2026-05-21 09:00:00', '10.0.0.2')"
        );

        ['payload' => $payload, 'summary' => $summary] = $this->runExport();

        $this->assertCount(1, $payload['tos_acceptances']);
        $this->assertSame('10.0.0.1', $payload['tos_acceptances'][0]['accepted_ip']);
    }

    /**
     * Un export che non riesce a leggere il registro deve dirlo. Una lista
     * vuota senza spiegazione, in un documento Art. 15, si legge come
     * "non hai mai accettato nulla" — che è un'affermazione diversa.
     */
    #[Test]
    public function unreadable_registry_is_declared_not_silently_empty(): void
    {
        $this->pdo->exec('DROP TABLE user_tos_acceptance');

        ['payload' => $payload, 'summary' => $summary] = $this->runExport();

        $this->assertSame([], $payload['tos_acceptances']);
        $this->assertArrayHasKey('tos_acceptances_unavailable', $payload);
        $this->assertSame('read_failed', $payload['tos_acceptances_unavailable']['reason']);
        // Il conteggio è presente ma null: "non lo so" è diverso da "zero".
        $this->assertArrayHasKey('tos_acceptances_count', $summary);
        $this->assertNull($summary['tos_acceptances_count']);
    }
}
