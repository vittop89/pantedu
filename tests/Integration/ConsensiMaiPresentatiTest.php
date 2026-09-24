<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * I consensi mai presentati (migrazione 130, 15/9/2026).
 *
 * Il banner dei cookie registrava «analytics» e «marketing» per gli utenti
 * autenticati che sceglievano «Accetta tutti», senza mai mostrare quelle due
 * categorie. La migrazione li revoca, con l'evento in consent_audit.
 *
 * Nei due versi: i consensi attivi di quelle due categorie si revocano e
 * lasciano l'evento; un consenso di un'altra categoria e uno già revocato non
 * si toccano; rilanciata, la migrazione non scrive niente. Tutto in
 * transazione → rollback.
 */
final class ConsensiMaiPresentatiTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!Database::isAvailable()) {
            self::markTestSkipped('database non disponibile');
        }
        $this->pdo = Database::connection();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function utente(): int
    {
        $nome = 'zzcmp' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Consensi", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        return (int)$this->pdo->lastInsertId();
    }

    private function consenso(int $utente, string $tipo, ?string $revocatoIl = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO consents (user_id, consent_type, granted, granted_at, revoked_at, legal_basis, text_version)
             VALUES (?, ?, 1, "2026-05-19 14:23:46", ?, "art6_1_a_consent", "1.0")'
        )->execute([$utente, $tipo, $revocatoIl]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Le istruzioni della migrazione, senza commenti. */
    private function migrazione(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/130_consensi_mai_presentati.sql');
        $sql = (string)preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $istruzione) {
            $this->pdo->exec($istruzione);
        }
    }

    /** @return array{revoked_at: ?string, notes: ?string} */
    private function riga(int $id): array
    {
        $st = $this->pdo->prepare('SELECT revoked_at, notes FROM consents WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    private function eventi(int $id): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM consent_audit WHERE consent_id = ? AND event = "revoked"');
        $st->execute([$id]);
        return (int)$st->fetchColumn();
    }

    #[Test]
    public function i_consensi_mai_presentati_si_revocano_e_lasciano_l_evento(): void
    {
        $utente = $this->utente();
        $analytics = $this->consenso($utente, 'analytics');
        $marketing = $this->consenso($utente, 'marketing');

        $this->migrazione();

        foreach ([$analytics, $marketing] as $id) {
            $riga = $this->riga($id);
            $this->assertNotNull($riga['revoked_at'], "il consenso $id è revocato");
            $this->assertStringContainsString('migrazione 130', (string)$riga['notes'], 'con il motivo scritto');
            $this->assertSame(1, $this->eventi($id), "e con un evento in consent_audit");
        }
    }

    #[Test]
    public function gli_altri_consensi_e_quelli_gia_revocati_non_si_toccano(): void
    {
        $utente = $this->utente();
        $altro = $this->consenso($utente, 'institute_share');
        $giaRevocato = $this->consenso($utente, 'analytics', '2026-06-01 10:00:00');

        $this->migrazione();

        $this->assertNull($this->riga($altro)['revoked_at'], 'un consenso di un\'altra categoria resta attivo');
        $this->assertSame(0, $this->eventi($altro));
        $this->assertSame('2026-06-01 10:00:00', $this->riga($giaRevocato)['revoked_at'], 'uno già revocato tiene la sua data');
        $this->assertNull($this->riga($giaRevocato)['notes']);
        $this->assertSame(0, $this->eventi($giaRevocato));
    }

    #[Test]
    public function rilanciata_non_scrive_niente(): void
    {
        $utente = $this->utente();
        $analytics = $this->consenso($utente, 'analytics');

        $this->migrazione();
        $prima = $this->riga($analytics);
        $this->migrazione();

        $this->assertSame($prima, $this->riga($analytics));
        $this->assertSame(1, $this->eventi($analytics), 'un solo evento anche rilanciandola');
    }
}
