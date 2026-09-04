<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr;

use App\Services\Gdpr\TosAcceptanceService;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Copre il versioning ToS/AUP e il preavviso.
 *
 * Il caso che merita più attenzione è `aup_only_bump_can_be_accepted`: prima
 * della migration 094 la PK era (user_id, tos_version), l'INSERT IGNORE
 * collideva in silenzio e chi provava ad accettare un aggiornamento della sola
 * AUP restava in loop di redirect permanente.
 */
final class TosAcceptanceServiceTest extends TestCase
{
    private function pdo(): PDO
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE legal_document_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                doc_type TEXT NOT NULL,
                version TEXT NOT NULL,
                published_at TEXT NOT NULL,
                effective_from TEXT NOT NULL,
                is_substantial INTEGER NOT NULL DEFAULT 1,
                checksum TEXT,
                summary TEXT,
                UNIQUE (doc_type, version)
            )'
        );
        $pdo->exec(
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
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        return $pdo;
    }

    private function addVersion(
        PDO $pdo,
        string $type,
        string $version,
        string $published,
        string $effective,
        bool $substantial = true,
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO legal_document_versions
                (doc_type, version, published_at, effective_from, is_substantial, summary)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$type, $version, $published, $effective, $substantial ? 1 : 0, 'nota']);
    }

    private function daysFromNow(int $days): string
    {
        return (new \DateTimeImmutable("$days days"))->format('Y-m-d H:i:s');
    }

    // -----------------------------------------------------------------

    #[Test]
    public function effective_version_ignores_versions_not_yet_in_force(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        // Pubblicata oggi, vincolante fra 30 giorni.
        $this->addVersion($pdo, 'aup', '1.1', $this->daysFromNow(0), $this->daysFromNow(30));

        $svc = new TosAcceptanceService($pdo);

        $this->assertSame('1.0', $svc->getCurrentAupVersion(), 'la 1.1 non è ancora in vigore');
        $this->assertSame('1.0', $svc->getCurrentTosVersion());
    }

    #[Test]
    public function version_becomes_effective_once_the_date_passes(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.1', $this->daysFromNow(-40), $this->daysFromNow(-1));

        $svc = new TosAcceptanceService($pdo);

        $this->assertSame('1.1', $svc->getCurrentAupVersion());
        $this->assertSame([], $svc->pendingVersions(), 'nulla resta in preavviso');
    }

    #[Test]
    public function pending_version_does_not_block_anyone(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.1', $this->daysFromNow(0), $this->daysFromNow(30));

        $svc = new TosAcceptanceService($pdo);
        $svc->recordAcceptance(7, '10.0.0.1', 'UA');

        // Ha accettato quel che è in vigore: il gate lo lascia passare anche
        // se c'è una versione in arrivo che non ha ancora accettato.
        $this->assertTrue($svc->hasAccepted(7));
    }

    #[Test]
    public function notice_reports_days_remaining(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.1', $this->daysFromNow(-1), $this->daysFromNow(29));

        $notice = (new TosAcceptanceService($pdo))->noticeFor(7);

        $this->assertNotNull($notice);
        $this->assertSame(28, $notice['days_remaining'], 'diff arrotondato per difetto');
        $this->assertCount(1, $notice['versions']);
        $this->assertSame('1.1', $notice['versions'][0]['version']);
    }

    #[Test]
    public function non_substantial_change_raises_no_notice(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.1', $this->daysFromNow(0), $this->daysFromNow(2), false);

        $this->assertNull((new TosAcceptanceService($pdo))->noticeFor(7));
    }

    #[Test]
    public function early_acceptance_clears_the_notice(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.1', $this->daysFromNow(0), $this->daysFromNow(30));

        $svc = new TosAcceptanceService($pdo);
        $this->assertNotNull($svc->noticeFor(7));

        // targetVersions punta alla pendente: si accetta in anticipo.
        $this->assertSame(['tos' => '1.0', 'aup' => '1.1'], $svc->targetVersions());
        $this->assertTrue($svc->recordAcceptance(7, '10.0.0.1', 'UA'));

        $this->assertNull((new TosAcceptanceService($pdo))->noticeFor(7));
    }

    #[Test]
    public function aup_only_bump_can_be_accepted(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));

        // L'utente accetta la coppia 1.0/1.0.
        $svc = new TosAcceptanceService($pdo);
        $this->assertTrue($svc->recordAcceptance(7, '10.0.0.1', 'UA'));
        $this->assertTrue($svc->hasAccepted(7));

        // Entra in vigore la sola AUP 1.1: il ToS resta 1.0.
        $this->addVersion($pdo, 'aup', '1.1', $this->daysFromNow(-40), $this->daysFromNow(-1));

        $svc2 = new TosAcceptanceService($pdo);
        $this->assertSame('1.0', $svc2->getCurrentTosVersion());
        $this->assertSame('1.1', $svc2->getCurrentAupVersion());
        $this->assertFalse($svc2->hasAccepted(7), 'ora è fuori regola e va al gate');

        // Il punto: questa accettazione DEVE andare a buon fine. Prima della
        // 094 collideva sulla PK (7, '1.0') e veniva scartata in silenzio,
        // lasciando hasAccepted() false per sempre.
        $svc3 = new TosAcceptanceService($pdo);
        $this->assertTrue($svc3->recordAcceptance(7, '10.0.0.1', 'UA'));
        $this->assertTrue($svc3->hasAccepted(7));

        $rows = (int)$pdo->query('SELECT COUNT(*) FROM user_tos_acceptance')->fetchColumn();
        $this->assertSame(2, $rows, 'entrambe le accettazioni restano a registro');
    }

    #[Test]
    public function early_acceptance_still_satisfies_the_gate_once_in_force(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        // In preavviso: entra in vigore fra un giorno.
        $this->addVersion($pdo, 'aup', '1.1', $this->daysFromNow(-29), $this->daysFromNow(1));

        // Il docente accetta oggi, in anticipo.
        $this->assertTrue((new TosAcceptanceService($pdo))->recordAcceptance(7, '10.0.0.1', 'UA'));

        // Il giorno dopo la 1.1 è vincolante. Non deve essere richiesto nulla
        // di nuovo: aveva già accettato proprio quella versione.
        $pdo->exec(
            "UPDATE legal_document_versions SET effective_from = '"
            . $this->daysFromNow(-1) . "' WHERE doc_type = 'aup' AND version = '1.1'"
        );

        $svc = new TosAcceptanceService($pdo);
        $this->assertSame('1.1', $svc->getCurrentAupVersion());
        $this->assertTrue($svc->hasAccepted(7), 'accettare in anticipo deve valere anche dopo');
    }

    /**
     * I documenti promettono che solo le modifiche SOSTANZIALI richiedono
     * nuova accettazione. Una correzione non sostanziale alza il numero di
     * versione mostrato ma non deve rimurare fuori chi era già in regola —
     * altrimenti il percorso dichiarato innocuo sarebbe più brutale di quello
     * sostanziale, che almeno concede 30 giorni di preavviso.
     */
    #[Test]
    public function non_substantial_bump_does_not_invalidate_acceptance(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));

        $svc = new TosAcceptanceService($pdo);
        $this->assertTrue($svc->recordAcceptance(7, '10.0.0.1', 'UA'));
        $this->assertTrue($svc->hasAccepted(7));

        // Cambio di recapito email: già in vigore, ma non sostanziale.
        $this->addVersion($pdo, 'tos', '1.1', $this->daysFromNow(-1), $this->daysFromNow(-1), false);
        $this->addVersion($pdo, 'aup', '1.1', $this->daysFromNow(-1), $this->daysFromNow(-1), false);

        $svc2 = new TosAcceptanceService($pdo);
        $this->assertSame('1.1', $svc2->getCurrentTosVersion(), 'la versione mostrata sale');
        $this->assertTrue($svc2->hasAccepted(7), 'ma resta in regola: nessun nuovo consenso dovuto');
    }

    #[Test]
    public function substantial_bump_does_invalidate_acceptance(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));

        $svc = new TosAcceptanceService($pdo);
        $svc->recordAcceptance(7, '10.0.0.1', 'UA');
        $this->assertTrue($svc->hasAccepted(7));

        // Questa invece cambia gli obblighi: preavviso dato, ora è in vigore.
        $this->addVersion($pdo, 'aup', '2.0', $this->daysFromNow(-40), $this->daysFromNow(-1));

        $this->assertFalse(
            (new TosAcceptanceService($pdo))->hasAccepted(7),
            'una modifica sostanziale in vigore richiede nuova accettazione'
        );
    }

    #[Test]
    public function acceptance_of_a_non_substantial_version_still_counts(): void
    {
        // Chi accetta oggi registra la 1.1 (non sostanziale). Deve risultare
        // in regola rispetto alla soglia, che è la 1.0 sostanziale.
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'tos', '1.1', $this->daysFromNow(-1), $this->daysFromNow(-1), false);
        $this->addVersion($pdo, 'aup', '1.1', $this->daysFromNow(-1), $this->daysFromNow(-1), false);

        $svc = new TosAcceptanceService($pdo);
        $this->assertSame(['tos' => '1.1', 'aup' => '1.1'], $svc->targetVersions());
        $this->assertTrue($svc->recordAcceptance(8, '10.0.0.2', 'UA'));
        $this->assertTrue((new TosAcceptanceService($pdo))->hasAccepted(8));
    }

    #[Test]
    public function acceptance_is_idempotent(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));

        $svc = new TosAcceptanceService($pdo);
        $this->assertTrue($svc->recordAcceptance(7, '10.0.0.1', 'UA'));
        $this->assertFalse($svc->recordAcceptance(7, '10.0.0.1', 'UA'), 'secondo submit: no-op');

        $rows = (int)$pdo->query('SELECT COUNT(*) FROM user_tos_acceptance')->fetchColumn();
        $this->assertSame(1, $rows);
    }

    #[Test]
    public function acceptance_records_evidence_metadata(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-90), $this->daysFromNow(-90));

        (new TosAcceptanceService($pdo))->recordAcceptance(7, '203.0.113.9', 'Mozilla/5.0');

        $row = $pdo->query('SELECT * FROM user_tos_acceptance')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('203.0.113.9', $row['accepted_ip']);
        $this->assertSame('Mozilla/5.0', $row['user_agent']);
        $this->assertNotEmpty($row['accepted_at']);
    }

    #[Test]
    public function historic_acceptance_uses_the_versions_in_force_back_then(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-200), $this->daysFromNow(-200));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-200), $this->daysFromNow(-200));
        // AUP 1.1 in vigore da 10 giorni fa.
        $this->addVersion($pdo, 'aup', '1.1', $this->daysFromNow(-45), $this->daysFromNow(-10));

        // Il docente aveva spuntato la casella 60 giorni fa, quando la 1.1
        // non era ancora in vigore: la riga deve dire 1.0, non 1.1.
        $when = $this->daysFromNow(-60);
        $svc = new TosAcceptanceService($pdo);
        $this->assertTrue($svc->recordHistoricAcceptance(7, $when, '203.0.113.9', 'UA'));

        $row = $pdo->query('SELECT * FROM user_tos_acceptance')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('1.0', $row['aup_version'], 'non attribuirgli un testo mai visto');
        $this->assertSame($when, $row['accepted_at'], 'la data è quella della spunta');
        $this->assertSame('203.0.113.9', $row['accepted_ip']);

        // E resta comunque fuori regola rispetto alla 1.1 ora vigente.
        $this->assertFalse((new TosAcceptanceService($pdo))->hasAccepted(7));
    }

    #[Test]
    public function historic_acceptance_is_idempotent(): void
    {
        $pdo = $this->pdo();
        $this->addVersion($pdo, 'tos', '1.0', $this->daysFromNow(-200), $this->daysFromNow(-200));
        $this->addVersion($pdo, 'aup', '1.0', $this->daysFromNow(-200), $this->daysFromNow(-200));

        $when = $this->daysFromNow(-60);
        $svc = new TosAcceptanceService($pdo);

        $this->assertTrue($svc->recordHistoricAcceptance(7, $when, '10.0.0.1'));
        $this->assertFalse($svc->recordHistoricAcceptance(7, $when, '10.0.0.1'));
        $this->assertSame(
            1,
            (int)$pdo->query('SELECT COUNT(*) FROM user_tos_acceptance')->fetchColumn()
        );
    }

    #[Test]
    public function falls_back_to_constants_when_registry_is_empty(): void
    {
        // Installazione in cui la 094 non è ancora girata.
        $svc = new TosAcceptanceService($this->pdo());

        $this->assertSame(TosAcceptanceService::TOS_VERSION_CURRENT, $svc->getCurrentTosVersion());
        $this->assertSame(TosAcceptanceService::AUP_VERSION_CURRENT, $svc->getCurrentAupVersion());
    }

    #[Test]
    public function history_is_ordered_most_recent_first(): void
    {
        $pdo = $this->pdo();
        $pdo->exec(
            "INSERT INTO user_tos_acceptance
                (user_id, tos_version, aup_version, accepted_at, accepted_ip)
             VALUES (7, '1.0', '1.0', '2026-01-01 10:00:00', '10.0.0.1'),
                    (7, '1.0', '1.1', '2026-06-01 10:00:00', '10.0.0.2')"
        );

        $history = (new TosAcceptanceService($pdo))->listHistory(7);

        $this->assertCount(2, $history);
        $this->assertSame('1.1', $history[0]['aup_version']);
        $this->assertSame('1.0', $history[1]['aup_version']);
    }
}
