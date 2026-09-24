<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La pulizia di `rate_limits` toglie le righe vecchie e lascia le recenti,
 * con lo script vero sul database vero (23/9/2026).
 *
 * È lo script che `pantedu-rate-limit-cleanup.timer` lancia ogni giorno, e che
 * fino a oggi non lanciava nessuno (A-84 e DOC-30 della revisione del 23/9).
 * Che il timer ci sia e che lo script esca con errore senza database lo prova
 * tests/Unit/PuliziaDelLimitatoreTest.php; qui si guarda il risultato.
 *
 * Il database è condiviso: la prova non deve togliere righe di altri. Le sue
 * hanno un secchio con un nome unico e date del 1970 (1 e 2 secondi dopo
 * l'epoca), e lo script si lancia con una soglia che toglie solo quello che è
 * più vecchio di mille secondi dopo l'epoca — cioè nient'altro che le righe
 * della prova. La riga «recente», a 5000 secondi, deve restare: è il verso in
 * cui la pulizia non deve scattare. Tutte le righe della prova si tolgono alla
 * fine, anche se fallisce.
 */
final class PuliziaDiRateLimitsTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $secchio = '';

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM rate_limits LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('DB o tabella rate_limits non disponibili: ' . $e->getMessage());
        }
        $this->secchio = 'prova-pulizia:' . date('YmdHis') . ':' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->pdo?->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([$this->secchio]);
    }

    /** @return list<int> le date delle righe della prova ancora nella tabella */
    private function rimaste(): array
    {
        $st = $this->pdo?->prepare('SELECT ts FROM rate_limits WHERE bucket = ? ORDER BY ts');
        $st?->execute([$this->secchio]);
        return array_map('intval', $st?->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    #[Test]
    public function toglie_le_righe_oltre_la_soglia_e_lascia_le_altre(): void
    {
        $ins = $this->pdo?->prepare('INSERT INTO rate_limits (bucket, ts, ip_address) VALUES (?, ?, ?)');
        foreach ([1, 2, 5000] as $ts) {
            $ins?->execute([$this->secchio, $ts, '192.0.2.1']);
        }
        self::assertSame([1, 2, 5000], $this->rimaste(), 'le righe della prova ci sono');

        // «Più vecchie di N secondi» con N = adesso − 1000: toglie solo ts < 1000.
        $soglia = time() - 1000;
        // L'ambiente del processo, ma senza DB_ENABLED: lo script deve leggere
        // la configurazione dai .env, come in produzione. Se un'altra prova ne
        // ha lasciato una copia nell'ambiente vero, Dotenv (immutabile) non la
        // riscriverebbe in $_ENV, e con variables_order=GPCS lo script
        // vedrebbe il database spento (23/9/2026, ordine casuale di phpunit.xml).
        $ambiente = getenv();
        unset($ambiente['DB_ENABLED']);
        $proc = proc_open(
            [PHP_BINARY, \dirname(__DIR__, 2) . '/tools/rate_limit_cleanup.php', "--older={$soglia}"],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubi,
            \dirname(__DIR__, 2),
            $ambiente,
        );
        self::assertIsResource($proc);
        $uscita = (string)stream_get_contents($tubi[1]);
        $errori = (string)stream_get_contents($tubi[2]);
        $esito = proc_close($proc);

        self::assertSame(0, $esito, $uscita . $errori);
        self::assertMatchesRegularExpression('/^Removed \d+ rate_limits rows older than \d+s\.$/m', $uscita);
        self::assertSame([5000], $this->rimaste(), 'via le due vecchie, resta la recente');
    }
}
