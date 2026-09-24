<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il dominio di produzione non sta nel codice (23/9/2026, rilievo A-13 della
 * revisione architetturale).
 *
 * Fino a quel giorno compariva in 43 righe di 26 file di `app/` e `views/`:
 * come ripiego di `app.url`, come collegamento fisso, come casella di posta
 * fissa. Il progetto si dichiara installabile da altri (`publiccode.yml`), e
 * un'altra istanza — o la CI con `app.url` vuota — mandava collegamenti,
 * gettoni di reset e le segnalazioni di terzi al titolare sbagliato. La radice
 * dei collegamenti è `app.url` (App\Support\IndirizzoPubblico), le caselle
 * vengono da app/Config/mail.php.
 *
 * Questa guardia scorre ogni file di `app/` e `views/` e fallisce se il dominio
 * ricompare fuori dall'elenco qui sotto. Ogni eccezione dice perché, vale per
 * un file e per una riga precisa, e deve servire ancora: un'eccezione che non
 * trova più la sua riga fa fallire la prova, così l'elenco può solo
 * accorciarsi di proposito. Il dominio può stare nei documenti, in
 * `publiccode.yml`, nel `.env` versionato e nei valori d'esempio di
 * `.env.example`: non sono codice.
 *
 * La controprova — che la guardia veda il dominio quando c'è, e non veda
 * quello che gli somiglia — è in questa stessa classe, su una cartella finta.
 */
final class DominioNonScrittoNelCodiceTest extends TestCase
{
    /** Il dominio, in qualunque combinazione di maiuscole. */
    private const DOMINIO = '/pantedu\.eu/i';

    private const CARTELLE = ['app', 'views'];

    /**
     * [file, frammento della riga, perché]. Il frammento deve comparire nella
     * riga che contiene il dominio.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const ECCEZIONI = [
        [
            'views/partials/modals.php',
            'Contenuti originali di <a href="/"',
            'nota di copyright dei contenuti originali (CC BY-NC-SA): il nome dell\'opera, non un collegamento '
            . 'né una casella; il collegamento è relativo. Cambiarlo è una scelta di presentazione, e la '
            . 'barra in fondo a ogni pagina è nelle immagini attese della regressione visiva',
        ],
        [
            'views/partials/modals.php',
            'id="fm-license-section">© 2022 pantedu.eu<',
            'la stessa nota nella barra in fondo a ogni pagina, che la regressione visiva '
            . '(tests/e2e/qualita/regressione-visiva.spec.js) fotografa a tre larghezze',
        ],
    ];

    private static function radice(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Le righe con il dominio fuori dalle eccezioni, e le eccezioni che non
     * trovano più la loro riga.
     *
     * @param list<string>                                $cartelle
     * @param list<array{0: string, 1: string, 2: string}> $eccezioni
     * @return array{fuori: list<string>, morte: list<string>, file: int}
     */
    private static function scorri(string $radice, array $cartelle, array $eccezioni): array
    {
        $fuori = [];
        $usate = [];
        $file = 0;
        foreach ($cartelle as $cartella) {
            $dir = $radice . '/' . $cartella;
            self::assertDirectoryExists($dir, "$cartella manca: la guardia non guarderebbe niente");
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if (!$f->isFile()) {
                    continue;
                }
                $testo = (string) file_get_contents($f->getPathname());
                if (str_contains($testo, "\0")) {
                    continue; // binario
                }
                $file++;
                $relativo = ltrim(str_replace('\\', '/', substr($f->getPathname(), \strlen($radice))), '/');
                foreach (preg_split('/\R/', $testo) ?: [] as $n => $riga) {
                    if (preg_match(self::DOMINIO, $riga) !== 1) {
                        continue;
                    }
                    foreach ($eccezioni as $i => [$quale, $frammento]) {
                        if ($quale === $relativo && str_contains($riga, $frammento)) {
                            $usate[$i] = true;
                            continue 2;
                        }
                    }
                    $fuori[] = $relativo . ':' . ($n + 1) . '  ' . trim($riga);
                }
            }
        }
        $morte = [];
        foreach ($eccezioni as $i => [$quale, $frammento]) {
            if (!isset($usate[$i])) {
                $morte[] = "$quale — «{$frammento}»";
            }
        }
        return ['fuori' => $fuori, 'morte' => $morte, 'file' => $file];
    }

    #[Test]
    public function il_dominio_di_produzione_non_compare_nel_codice(): void
    {
        $esito = self::scorri(self::radice(), self::CARTELLE, self::ECCEZIONI);

        self::assertGreaterThan(500, $esito['file'], 'la guardia deve aver letto i file di app/ e views/');
        self::assertSame(
            [],
            $esito['fuori'],
            "Il dominio di produzione è tornato nel codice. La radice dei collegamenti è app.url "
            . "(App\\Support\\IndirizzoPubblico), le caselle vengono da app/Config/mail.php:\n"
            . implode("\n", $esito['fuori'])
        );
    }

    #[Test]
    public function ogni_eccezione_trova_ancora_la_sua_riga(): void
    {
        $esito = self::scorri(self::radice(), self::CARTELLE, self::ECCEZIONI);
        self::assertSame(
            [],
            $esito['morte'],
            "Eccezioni che non servono più: toglile dall'elenco.\n" . implode("\n", $esito['morte'])
        );
    }

    #[Test]
    public function la_guardia_vede_il_dominio_e_non_quello_che_gli_somiglia(): void
    {
        // Il dominio si compone qui, non si scrive: nella copia pubblica il
        // sanitizer sostituisce ogni indirizzo @pantedu.eu del sorgente, e la
        // prova non guarderebbe più quello che dice (23/9/2026).
        $dominio = 'pantedu' . '.eu';
        $finta = sys_get_temp_dir() . '/pantedu-guardia-dominio-' . bin2hex(random_bytes(6));
        mkdir($finta . '/app/Servizi', 0700, true);
        mkdir($finta . '/views', 0700, true);
        try {
            file_put_contents($finta . '/app/Servizi/Uno.php', implode("\n", [
                '<?php',
                "\$sito = Config::get('app.url') ?: 'https://pantedu.eu';", // 2: sì
                "\$a = 'ABUSE@" . strtoupper($dominio) . "';",                // 3: sì, maiuscole
                "\$b = 'https://pantedu.example';",                            // 4: no
                "\$c = 'pantedu-eu';",                                         // 5: no
                "\$d = 'pantedu_eu';",                                         // 6: no
            ]));
            file_put_contents($finta . '/views/pagina.html', "<p>scrivi a info@{$dominio}</p>\n<p>Pantedu</p>\n");
            // Il frammento di un'eccezione vale solo nel suo file: qui no.
            file_put_contents($finta . '/views/altra.php', "<a>© 2022 pantedu.eu</a>\n");
            file_put_contents($finta . '/views/modals.php', "<a>© 2022 pantedu.eu</a>\n");

            $eccezioni = [
                ['views/modals.php', '© 2022 pantedu.eu', 'prova'],
                ['views/sparito.php', 'non c\'è più', 'prova'],
            ];
            $esito = self::scorri($finta, ['app', 'views'], $eccezioni);

            $trovati = array_map(static fn(string $r): string => explode('  ', $r)[0], $esito['fuori']);
            sort($trovati);
            self::assertSame(
                ['app/Servizi/Uno.php:2', 'app/Servizi/Uno.php:3', 'views/altra.php:1', 'views/pagina.html:1'],
                $trovati,
            );
            self::assertSame(["views/sparito.php — «non c'è più»"], $esito['morte']);
        } finally {
            foreach (['app/Servizi/Uno.php', 'views/pagina.html', 'views/altra.php', 'views/modals.php'] as $f) {
                @unlink($finta . '/' . $f);
            }
            @rmdir($finta . '/app/Servizi');
            @rmdir($finta . '/app');
            @rmdir($finta . '/views');
            @rmdir($finta);
        }
    }
}
