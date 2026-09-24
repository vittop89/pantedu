<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Services\Ops\SegnalazioniDiViolazione;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dalla segnalazione all'incidente, e il legame resta scritto.
 *
 * Le prove unitarie guardano la decisione (quali sono in ritardo). Queste
 * guardano il giro vero sul database: quali segnalazioni risultano aperte,
 * che l'apertura dell'incidente le tolga dal conto, e che l'età la calcoli il
 * database e non PHP — misurato il 22/9/2026, `created_at` è una TIMESTAMP
 * restituita in UTC e `strtotime()` la leggeva con il fuso di Roma: una
 * segnalazione di otto ore ne risultava dieci, con una soglia di sei.
 *
 * Tutto dentro una transazione annullata alla fine: l'incidente che si apre
 * qui **non si potrebbe cancellare**, perché è precisamente ciò che il
 * trigger della migrazione 136 impedisce.
 */
final class SegnalazioneApreLIncidenteTest extends TestCase
{
    private PDO $pdo;
    private string $marca = '';

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        if (!SegnalazioniDiViolazione::legameEsiste($this->pdo)) {
            $this->markTestSkipped('migrazione 137 non applicata su questo database');
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->beginTransaction();
        $this->marca = 'prova-' . bin2hex(random_bytes(6)) . '@example.local';
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function segnalazione(int $oreFa): int
    {
        $this->pdo->prepare(
            'INSERT INTO dpo_requests (name, email, subject, is_minor_related, message, status, created_at)
             VALUES ("Prova", ?, "breach_report", 0, ?, "open", DATE_SUB(NOW(), INTERVAL ? HOUR))'
        )->execute([$this->marca, 'Segnalazione di prova, dentro una transazione annullata.', $oreFa]);

        return (int)$this->pdo->lastInsertId();
    }

    /** @return array{fonte:string,id:int,ore:int}|null */
    private function trova(int $id): ?array
    {
        foreach (SegnalazioniDiViolazione::apertaSenzaIncidente($this->pdo) as $s) {
            if ($s['fonte'] === 'dpo' && $s['id'] === $id) {
                return $s;
            }
        }
        return null;
    }

    #[Test]
    public function l_eta_la_calcola_il_database(): void
    {
        $id = $this->segnalazione(8);
        $s  = $this->trova($id);

        self::assertNotNull($s, 'la segnalazione deve risultare aperta');
        self::assertSame(
            8,
            $s['ore'],
            'otto ore devono dire otto: confrontare una TIMESTAMP UTC con time() di PHP sbaglia di tutto il fuso'
        );
    }

    #[Test]
    public function una_segnalazione_recente_non_e_in_ritardo(): void
    {
        $id = $this->segnalazione(1);

        self::assertNotNull($this->trova($id), 'risulta aperta');
        self::assertSame(
            [],
            array_filter(
                SegnalazioniDiViolazione::inRitardo(SegnalazioniDiViolazione::apertaSenzaIncidente($this->pdo)),
                static fn(array $s): bool => $s['id'] === $id && $s['fonte'] === 'dpo',
            ),
            'un\'ora non è un ritardo'
        );
    }

    #[Test]
    public function aprire_l_incidente_la_toglie_dal_conto(): void
    {
        $id = $this->segnalazione(8);
        self::assertNotNull($this->trova($id), 'prima è aperta');

        $esito = SegnalazioniDiViolazione::apriIncidente($this->pdo, 'dpo', $id, 'nota della prova', 0);

        self::assertArrayHasKey('id', $esito, 'l\'incidente deve nascere: ' . ($esito['errore'] ?? ''));
        self::assertNull($this->trova($id), 'dopo non deve più risultare da valutare');
    }

    /**
     * Il registro non ammette cancellazioni: due incidenti per la stessa
     * segnalazione resterebbero lì per sempre. Chiamarla due volte deve
     * riportare allo stesso incidente, non aprirne un altro.
     */
    #[Test]
    public function non_apre_due_incidenti_per_la_stessa_segnalazione(): void
    {
        $id = $this->segnalazione(8);

        $primo   = SegnalazioniDiViolazione::apriIncidente($this->pdo, 'dpo', $id, 'prima nota', 0);
        $secondo = SegnalazioniDiViolazione::apriIncidente($this->pdo, 'dpo', $id, 'seconda nota', 0);

        self::assertArrayHasKey('id', $primo);
        self::assertSame($primo['id'], $secondo['id'] ?? null, 'stesso incidente, non un doppione');
    }

    /**
     * La data del rilevamento è **adesso**, non quella della segnalazione: le
     * settantadue ore decorrono da quando il titolare ne viene a conoscenza.
     * Metterci la data d'arrivo sarebbe una bugia in favore nostro, e per
     * giunta incorreggibile (il trigger della 136 congela quel campo).
     */
    #[Test]
    public function il_rilevamento_e_adesso_e_l_accaduto_e_la_segnalazione(): void
    {
        $id     = $this->segnalazione(8);
        $esito  = SegnalazioniDiViolazione::apriIncidente($this->pdo, 'dpo', $id, 'nota', 0);
        $inc    = (int)($esito['id'] ?? 0);
        self::assertGreaterThan(0, $inc);

        $st = $this->pdo->prepare(
            'SELECT TIMESTAMPDIFF(HOUR, detected_at, NOW()) AS rilevato,
                    TIMESTAMPDIFF(HOUR, occurred_at, NOW()) AS accaduto
               FROM data_breach_incidents WHERE id = ?'
        );
        $st->execute([$inc]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        self::assertSame(0, (int)$r['rilevato'], 'la conoscenza è di adesso');
        self::assertSame(8, (int)$r['accaduto'], 'il fatto è di quando è arrivata la segnalazione');
    }

    /**
     * Una segnalazione chiusa è una valutazione conclusa con «non era una
     * violazione»: legittima, e l'art. 33 §5 chiede di documentarla. Non deve
     * continuare a suonare.
     */
    #[Test]
    public function una_segnalazione_chiusa_non_suona_piu(): void
    {
        $id = $this->segnalazione(8);
        self::assertNotNull($this->trova($id));

        $this->pdo->prepare('UPDATE dpo_requests SET status = "closed" WHERE id = ?')->execute([$id]);

        self::assertNull($this->trova($id));
    }

    /** E la posta indesiderata non è un incidente da aprire. */
    #[Test]
    public function la_posta_indesiderata_non_suona(): void
    {
        $id = $this->segnalazione(8);
        $this->pdo->prepare('UPDATE dpo_requests SET status = "spam" WHERE id = ?')->execute([$id]);

        self::assertNull($this->trova($id));
    }
}
