<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Waf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Nessun esito del filtro di sicurezza può eccedere la colonna che lo ospita.
 *
 * Il 22/9/2026: il controllo anti-bot scriveva `outcome = 'fingerprint_collected'`,
 * ventuno caratteri, in una colonna `varchar(16)`. In modalità stretta l'INSERT
 * falliva, e `WafLogService::log()` inghiotte le eccezioni perché il filtro non
 * deve bloccare il sito quando il database non risponde. Risultato: la verifica
 * avveniva e nel registro **non compariva mai**. Misurato in produzione: zero
 * righe su ventimila, dal 10 agosto.
 *
 * È il guasto tipico di questo progetto — qualcosa che non funziona e il
 * fallimento assomiglia al normale — e non si vede guardando il sito, che
 * risponde benissimo. Si vede solo confrontando gli esiti che il codice scrive
 * con la larghezza della colonna, ed è quello che fa questa prova.
 *
 * Non serve il database: la larghezza è dichiarata nella migrazione, e gli
 * esiti sono letterali nel codice.
 */
final class EsitiDelWafStannoNellaColonnaTest extends TestCase
{
    /**
     * La larghezza NON si scrive qui: si legge dalle migrazioni.
     *
     * Una costante a mano diverge dallo schema senza che nessuno se ne
     * accorga, ed è proprio il genere di scarto che questa prova esiste per
     * impedire. Vince l'ultima migrazione che ridefinisce la colonna.
     */
    private static function larghezza(): int
    {
        $dir = dirname(__DIR__, 4) . '/database/migrations';
        $file = glob($dir . '/*.sql') ?: [];
        sort($file, SORT_NATURAL);

        $larghezza = null;
        foreach ($file as $f) {
            $sql = (string)file_get_contents($f);
            if (!str_contains($sql, 'waf_logs') && !str_contains(basename($f), 'waf')) {
                continue;
            }
            if (preg_match_all('/`?outcome`?\s+VARCHAR\((\d+)\)/i', $sql, $m)) {
                $larghezza = (int)end($m[1]);
            }
        }

        self::assertNotNull($larghezza,
            'nessuna migrazione definisce waf_logs.outcome: la prova non sa su cosa misurare');

        return $larghezza;
    }

    /** @return list<string> i file che scrivono nel registro del WAF */
    private static function sorgenti(): array
    {
        $radice = dirname(__DIR__, 4);
        return [
            $radice . '/app/Controllers/WafApiController.php',
            $radice . '/app/Middleware/WafMiddleware.php',
            $radice . '/app/Services/Waf/WafLogService.php',
        ];
    }

    #[Test]
    public function ogni_esito_scritto_nel_codice_entra_nella_colonna(): void
    {
        $troppoLunghi = [];
        $trovati = 0;

        foreach (self::sorgenti() as $file) {
            self::assertFileExists($file);
            $testo = (string)file_get_contents($file);
            // `'outcome' => 'qualcosa'` e `'outcome' => "qualcosa"`
            preg_match_all('/[\'"]outcome[\'"]\s*=>\s*[\'"]([a-z_0-9]+)[\'"]/i', $testo, $m);
            foreach ($m[1] as $esito) {
                $trovati++;
                if (strlen($esito) > self::larghezza()) {
                    $troppoLunghi[] = basename($file) . ": '$esito' (" . strlen($esito) . ' caratteri)';
                }
            }
        }

        self::assertGreaterThan(0, $trovati,
            'la prova non ha trovato nessun esito: se le scritture si sono spostate, va aggiornata');
        self::assertSame([], $troppoLunghi,
            "esiti che non entrano in varchar(" . self::larghezza() . "), e che quindi "
            . "sparirebbero dal registro senza dare errore:\n  " . implode("\n  ", $troppoLunghi));
    }

    #[Test]
    public function la_prova_riconosce_un_esito_troppo_lungo(): void
    {
        // Il verso che di solito non si prova: la guardia deve accorgersene.
        // Senza questo caso, un errore nell'espressione regolare renderebbe la
        // prova sopra sempre verde — e sarebbe di nuovo un controllo che non
        // misura niente.
        $troppoLungo = str_repeat('x', self::larghezza() + 1);
        $finto = "'outcome' => '$troppoLungo',";
        preg_match_all('/[\'"]outcome[\'"]\s*=>\s*[\'"]([a-z_0-9]+)[\'"]/i', $finto, $m);

        self::assertSame([$troppoLungo], $m[1], "l'espressione deve riconoscere la scrittura");
        self::assertGreaterThan(self::larghezza(), strlen($m[1][0]),
            'e deve vedere che eccede la colonna');

        // Il valore vero che il 22/9/2026 spariva in silenzio: oggi ci sta, ma
        // solo perché la migrazione 135 ha allargato la colonna a 32. Con i
        // sedici caratteri di prima non entrava, e l'INSERT falliva.
        self::assertGreaterThan(16, strlen('fingerprint_collected'),
            'con la larghezza precedente questo valore non entrava');
    }
}
