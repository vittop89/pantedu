<?php

declare(strict_types=1);

namespace Tests\Unit\Architettura;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * I tre elenchi di oggi non crescono (23/9/2026).
 *
 * ── Perché (revisione architetturale del 23/9/2026, A-10, A-34; ADR-049) ──
 *
 * `wiki/_llm-primer.md` dichiara da tempo la regola — il SQL sta nei
 * repository, `php://input` si legge con l'helper di ADR-034, l'ambiente si
 * legge solo in `app/Config` — ma nessun controllo la sorvegliava: un
 * controller nuovo con SQL, o una lettura diretta di `php://input`, passava
 * senza che niente lo dicesse.
 *
 * Questa prova non pretende di correggere i file di oggi (quel lavoro è
 * separato, R-8 passo 4): fissa i tre elenchi in
 * `tools/ci/confini-degli-strati.json` e li ricalcola a ogni corsa. Un file
 * NUOVO in un elenco fa fallire la prova, con il nome e la regola violata. Un
 * file dell'elenco che non viola più la regola fa fallire l'altro verso, per
 * ricordare di toglierlo dal JSON: altrimenti un file corretto lascerebbe
 * spazio a uno nuovo con lo stesso peso, e il cricchetto smetterebbe di
 * stringere (lo stesso principio di
 * `LaBaselineDiPhpstanPuoSoloScendereTest`).
 *
 * Misurato nei due versi (23/9/2026): un controller finto con
 * `Database::connection()->query(...)` fuori dall'elenco fa fallire
 * `controllerConSqlNonHaFileNuovi`; togliendo il SQL da un file VERO
 * dell'elenco (mutazione temporanea, poi ripristinato) fallisce
 * `controllerConSqlNonHaFileDaTogliere`, finché non lo si toglie dal JSON.
 */
final class ConfiniDegliStratiTest extends TestCase
{
    private static function radice(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function censimento(): array
    {
        $json = file_get_contents(self::radice() . '/tools/ci/confini-degli-strati.json');
        $dati = json_decode((string)$json, true);
        self::assertIsArray($dati, 'tools/ci/confini-degli-strati.json si legge');
        return $dati;
    }

    /** Il codice del file, tolti commenti e docblock (token_get_all). */
    private static function senzaCommenti(string $codice): string
    {
        $out = '';
        foreach (token_get_all($codice) as $tok) {
            if (is_array($tok)) {
                if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
        }
        return $out;
    }

    /** @return iterable<string> percorsi assoluti dei .php sotto $cartella */
    private static function phpSotto(string $cartella): iterable
    {
        if (!is_dir($cartella)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cartella, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                yield $f->getPathname();
            }
        }
    }

    private static function relativo(string $assoluto): string
    {
        return str_replace(self::radice() . '/', '', $assoluto);
    }

    /** @return list<string> percorsi relativi, ordinati */
    private static function controllerConSqlOggi(): array
    {
        $pattern = '/->prepare\(|->query\(|->exec\(|Database::connection\(\)/';
        $trovati = [];
        foreach (self::phpSotto(self::radice() . '/app/Controllers') as $path) {
            if (preg_match($pattern, self::senzaCommenti((string)file_get_contents($path)))) {
                $trovati[] = self::relativo($path);
            }
        }
        sort($trovati);
        return $trovati;
    }

    /** @return list<string> */
    private static function phpInputFuoriHelperOggi(): array
    {
        $pattern = '/file_get_contents\(\s*[\'"]php:\/\/input[\'"]\s*\)/';
        $trovati = [];
        foreach (self::phpSotto(self::radice() . '/app/Controllers') as $path) {
            if (preg_match($pattern, self::senzaCommenti((string)file_get_contents($path)))) {
                $trovati[] = self::relativo($path);
            }
        }
        sort($trovati);
        return $trovati;
    }

    /**
     * L'eccezione di ADR-049: il caricatore della configurazione e le
     * credenziali di manutenzione/migratore, tenute fuori da Config::get.
     */
    private const AMBIENTE_ESCLUSI = ['app/Core/Config.php', 'app/Core/Database.php'];

    /** @return list<string> */
    private static function ambienteFuoriDaConfigOggi(): array
    {
        $pattern = '/\$_ENV\s*\[|\bgetenv\s*\(/';
        $trovati = [];
        foreach (['app', 'views'] as $sotto) {
            foreach (self::phpSotto(self::radice() . '/' . $sotto) as $path) {
                $rel = self::relativo($path);
                if (str_starts_with($rel, 'app/Config/') || in_array($rel, self::AMBIENTE_ESCLUSI, true)) {
                    continue;
                }
                if (preg_match($pattern, self::senzaCommenti((string)file_get_contents($path)))) {
                    $trovati[] = $rel;
                }
            }
        }
        sort($trovati);
        return $trovati;
    }

    /**
     * Confronta l'elenco calcolato oggi con quello del JSON e restituisce
     * [nuovi, daTogliere]. "Nuovi" sono file che violano e non sono elencati;
     * "daTogliere" sono file elencati che non violano più.
     */
    private static function confronta(array $oggi, array $censito): array
    {
        $nuovi = array_values(array_diff($oggi, $censito));
        $daTogliere = array_values(array_diff($censito, $oggi));
        return [$nuovi, $daTogliere];
    }

    #[Test]
    public function controllerConSqlNonHaFileNuovi(): void
    {
        [$nuovi, ] = self::confronta(self::controllerConSqlOggi(), self::censimento()['controller_con_sql']['elenco']);
        self::assertSame(
            [],
            $nuovi,
            'Un controller nuovo esegue SQL direttamente (->prepare/->query/->exec o Database::connection()),'
            . ' vietato fuori dai repository e dai servizi con PDO iniettato (ADR-049): '
            . implode(', ', $nuovi)
        );
    }

    #[Test]
    public function controllerConSqlNonHaFileDaTogliere(): void
    {
        $censiti = self::censimento()['controller_con_sql']['elenco'];
        [, $daTogliere] = self::confronta(self::controllerConSqlOggi(), $censiti);
        self::assertSame(
            [],
            $daTogliere,
            'Questi controller non eseguono più SQL direttamente: togliili da'
            . ' tools/ci/confini-degli-strati.json (controller_con_sql), altrimenti'
            . ' lasciano spazio a un controller nuovo con lo stesso difetto: '
            . implode(', ', $daTogliere)
        );
    }

    #[Test]
    public function phpInputFuoriHelperNonHaFileNuovi(): void
    {
        $censiti = self::censimento()['php_input_fuori_helper']['elenco'];
        [$nuovi, ] = self::confronta(self::phpInputFuoriHelperOggi(), $censiti);
        self::assertSame(
            [],
            $nuovi,
            "Un controller nuovo legge php://input direttamente invece di Request::rawBody()/json()/body()"
            . ' (ADR-034, ADR-049): ' . implode(', ', $nuovi)
        );
    }

    #[Test]
    public function phpInputFuoriHelperNonHaFileDaTogliere(): void
    {
        $censiti = self::censimento()['php_input_fuori_helper']['elenco'];
        [, $daTogliere] = self::confronta(self::phpInputFuoriHelperOggi(), $censiti);
        self::assertSame(
            [],
            $daTogliere,
            'Questi file non leggono più php://input direttamente: togliili da'
            . ' tools/ci/confini-degli-strati.json (php_input_fuori_helper): '
            . implode(', ', $daTogliere)
        );
    }

    #[Test]
    public function ambienteFuoriDaConfigNonHaFileNuovi(): void
    {
        $censiti = self::censimento()['ambiente_fuori_da_config']['elenco'];
        [$nuovi, ] = self::confronta(self::ambienteFuoriDaConfigOggi(), $censiti);
        self::assertSame(
            [],
            $nuovi,
            'Un file nuovo fuori da app/Config legge $_ENV o getenv() direttamente,'
            . ' vietato dalla regola sull\'ambiente centralizzato (ADR-049): '
            . implode(', ', $nuovi)
        );
    }

    #[Test]
    public function ambienteFuoriDaConfigNonHaFileDaTogliere(): void
    {
        $censiti = self::censimento()['ambiente_fuori_da_config']['elenco'];
        [, $daTogliere] = self::confronta(self::ambienteFuoriDaConfigOggi(), $censiti);
        self::assertSame(
            [],
            $daTogliere,
            'Questi file non leggono più l\'ambiente direttamente: togliili da'
            . ' tools/ci/confini-degli-strati.json (ambiente_fuori_da_config): '
            . implode(', ', $daTogliere)
        );
    }

    /**
     * Controprova del criterio SQL: un commento che nomina il pattern non
     * conta (misurato su app/Controllers/AdminMigrateController.php, che ha
     * "// `Database::connection()` ..." e nessuna chiamata vera), una
     * chiamata vera sì.
     */
    #[Test]
    public function ilCriterioSqlIgnoraICommentiERiconosceLeChiamateVere(): void
    {
        $soloCommento = "<?php\n// Prima usava Database::connection() per questo.\nclass X {}\n";
        self::assertDoesNotMatchRegularExpression(
            '/->prepare\(|->query\(|->exec\(|Database::connection\(\)/',
            self::senzaCommenti($soloCommento)
        );

        $chiamataVera = "<?php\nclass X { function f() { return Database::connection()->query('SELECT 1'); } }\n";
        self::assertMatchesRegularExpression(
            '/->prepare\(|->query\(|->exec\(|Database::connection\(\)/',
            self::senzaCommenti($chiamataVera)
        );

        $docblock = "<?php\n/**\n * Esempio: \$db->prepare('...')\n */\nclass X {}\n";
        self::assertDoesNotMatchRegularExpression(
            '/->prepare\(|->query\(|->exec\(|Database::connection\(\)/',
            self::senzaCommenti($docblock)
        );
    }

    /**
     * Controprova del criterio ambiente: $_SERVER da solo (dato della
     * richiesta, non variabile d'ambiente) non deve far scattare il pattern
     * usato dallo scanner — lo scanner cerca solo $_ENV[ e getenv(.
     */
    #[Test]
    public function ilCriterioAmbienteNonCercaServerDaSolo(): void
    {
        $pattern = '/\$_ENV\s*\[|\bgetenv\s*\(/';
        self::assertDoesNotMatchRegularExpression(
            $pattern,
            "<?php\nif (!empty(\$_SERVER['HTTPS'])) { return true; }\n"
        );
        self::assertMatchesRegularExpression(
            $pattern,
            "<?php\nreturn \$_ENV['APP_ENV'] ?? \$_SERVER['APP_ENV'] ?? 'production';\n"
        );
    }

    /** Il censimento e il codice sorgente sono coerenti sui totali dichiarati. */
    #[Test]
    public function iTotaliDichiaratiCorrispondonoAgliElenchi(): void
    {
        $dati = self::censimento();
        foreach (['controller_con_sql', 'php_input_fuori_helper', 'ambiente_fuori_da_config'] as $chiave) {
            self::assertSame(
                $dati[$chiave]['quanti'],
                count($dati[$chiave]['elenco']),
                "\"quanti\" di $chiave non corrisponde alla lunghezza di \"elenco\" nel JSON"
            );
        }
    }
}
