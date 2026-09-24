<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\Gdpr\TosAcceptanceService;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il preavviso di una versione nuova dei Termini arriva davvero all'utente.
 *
 * ── Il difetto, misurato il 22/9/2026 ─────────────────────────────────────
 *
 * I Termini §14 e l'AUP §11 promettono un avviso **dentro l'applicazione**
 * trenta giorni prima che una modifica sostanziale entri in vigore. È un
 * impegno contrattuale verso chi li ha accettati.
 *
 * Non si è mai visto. Il markup del banner nasce con `hidden`, e l'unico
 * `el.hidden = false` del progetto sta in `js/modules/core/legal-notice.js`,
 * che **nessuno importava** — misurato anche sul pacchetto costruito, dove
 * `fm-legal-notice` non compariva fra gli asset mentre `fm-bb-menu` sì.
 *
 * Tutto lasciava credere il contrario: il markup c'era, il servizio che lo
 * alimenta c'era, il commento lo dichiarava. È il verde che non misura, su un
 * documento che si firma.
 *
 * ── Che cosa prova questa classe, e che cosa no ───────────────────────────
 *
 * Prova la parte server: che con una versione in arrivo il servizio produca il
 * preavviso, e che senza non lo produca. La parte client — togliere `hidden` —
 * la tiene {@see \Tests\Unit\IlBannerLegaleSiCaricaTest}, che guarda
 * l'importazione e il pacchetto costruito: qui un browser non c'è.
 *
 * ── Perché la condizione va costruita a mano ──────────────────────────────
 *
 * Il preavviso esiste solo se c'è una versione con `effective_from` nel
 * futuro, e **ogni versione pubblicata finora è entrata in vigore lo stesso
 * giorno** (rinuncia al preavviso, motivata a registro). Una prova che si
 * limitasse a interrogare il servizio sui dati veri riporterebbe «nessun
 * preavviso» e passerebbe in tutti e due i versi senza aver guardato niente.
 */
final class AvvisoLegaleInPaginaTest extends TestCase
{
    private PDO $pdo;
    private int $docente = 0;

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($base . '/app/Config');

        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM legal_document_versions LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o tabella delle versioni non disponibili: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();

        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES (?, 'teacher', 'Zz', 'Avvisato', ?, 'x', 'approved', 1)"
        )->execute(['zz_avvlegale', 'zz_avvlegale@example.test']);
        $this->docente = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** Una versione sostanziale che entra in vigore fra N giorni. */
    private function versioneInArrivo(int $fraGiorni, string $tipo = 'tos'): string
    {
        $versione = '99.' . random_int(1000, 9999);
        $this->pdo->prepare(
            'INSERT INTO legal_document_versions
                 (doc_type, version, published_at, effective_from, is_substantial, summary)
             VALUES (?, ?, NOW(), NOW() + INTERVAL ? DAY, 1, ?)'
        )->execute([$tipo, $versione, $fraGiorni, 'Versione di prova']);

        return $versione;
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Il verso «non deve esserci». Va per primo: se il preavviso comparisse
     * sempre, la prova qui sotto non misurerebbe niente.
     */
    #[Test]
    public function senza_versioni_in_arrivo_non_c_e_nessun_preavviso(): void
    {
        // Le versioni vere sono tutte già in vigore; per sicurezza si toglie
        // dal futuro qualunque riga, dentro la transazione che poi si annulla.
        $this->pdo->exec('DELETE FROM legal_document_versions WHERE effective_from > NOW()');

        self::assertNull(
            (new TosAcceptanceService($this->pdo))->noticeFor($this->docente),
            'senza niente in arrivo il banner non deve comparire'
        );
    }

    /** Il verso che conta. */
    #[Test]
    public function con_una_versione_in_arrivo_il_preavviso_c_e_e_dice_quanti_giorni(): void
    {
        $versione = $this->versioneInArrivo(30);

        $avviso = (new TosAcceptanceService($this->pdo))->noticeFor($this->docente);

        self::assertIsArray($avviso, 'con una versione in arrivo il preavviso deve esserci');
        self::assertGreaterThanOrEqual(28, $avviso['days_remaining'], 'e deve dire quanti giorni mancano');
        self::assertLessThanOrEqual(30, $avviso['days_remaining']);

        $versioni = array_column($avviso['versions'], 'version');
        self::assertContains($versione, $versioni, 'e deve nominare la versione in arrivo');
    }

    /**
     * Chi ha già accettato in anticipo non deve vedere l'avviso: sarebbe un
     * banner che chiede una cosa già fatta.
     */
    #[Test]
    public function chi_ha_gia_accettato_in_anticipo_non_lo_vede(): void
    {
        $tos = $this->versioneInArrivo(30, 'tos');
        $aup = $this->versioneInArrivo(30, 'aup');

        $servizio = new TosAcceptanceService($this->pdo);
        self::assertIsArray($servizio->noticeFor($this->docente), 'controllo positivo: prima lo vede');

        $this->pdo->prepare(
            // `accepted_ip` non ha un valore predefinito: il verbale di
            // accettazione vuole sapere da dove è arrivata, sempre.
            'INSERT INTO user_tos_acceptance (user_id, tos_version, aup_version, accepted_ip, accepted_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$this->docente, $tos, $aup, '127.0.0.1']);

        // Il servizio tiene in cache le pendenti: ne serve uno nuovo.
        self::assertNull(
            (new TosAcceptanceService($this->pdo))->noticeFor($this->docente),
            'accettato in anticipo: il banner non ha più niente da dire'
        );
    }

    /**
     * Una modifica non sostanziale non fa comparire niente: il preavviso di
     * trenta giorni è promesso per le sostanziali.
     */
    #[Test]
    public function una_modifica_non_sostanziale_non_fa_comparire_il_banner(): void
    {
        $this->pdo->exec('DELETE FROM legal_document_versions WHERE effective_from > NOW()');
        $this->pdo->prepare(
            'INSERT INTO legal_document_versions
                 (doc_type, version, published_at, effective_from, is_substantial, summary)
             VALUES (?, ?, NOW(), NOW() + INTERVAL 30 DAY, 0, ?)'
        )->execute(['tos', '99.' . random_int(1000, 9999), 'Correzione di forma']);

        self::assertNull(
            (new TosAcceptanceService($this->pdo))->noticeFor($this->docente),
            'il preavviso dei trenta giorni riguarda le modifiche sostanziali'
        );
    }
}
