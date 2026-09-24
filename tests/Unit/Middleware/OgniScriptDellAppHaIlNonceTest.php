<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ogni `<script>` che l'applicazione scrive porta il nonce della CSP
 * (23/9/2026, revisione architetturale A-16, R-3 passo 4).
 *
 * Fino a oggi il nonce lo metteva SecurityHeadersMiddleware, con una regex, su
 * ogni `<script>` della risposta: anche su quelli arrivati dal contenuto. Adesso
 * il middleware non tocca il corpo, e il nonce lo scrive chi scrive lo script
 * (App\Support\Csp). Il rischio si sposta: uno script dell'applicazione scritto
 * senza nonce, con la CSP rigorosa che c'è in produzione, non parte — e in
 * sviluppo, con la CSP rilassata, nessuno se ne accorge.
 *
 * La guardia legge i sorgenti di views/ (.php e .html) e di app/ (.php). Per
 * ogni `<script` fuori dai commenti (PHP e HTML) vuole subito dopo una di
 * queste forme:
 *
 *   <script<?= \App\Support\Csp::attributo() ?> …          (vista)
 *   '<script' . \App\Support\Csp::attributo() . ' …'       (stringa PHP)
 *   '<script' . $csp . '…'  /  <script{$csp} …             ($csp = …Csp::attributo() nello stesso file)
 *
 * oppure un tipo che il browser non esegue — `application/json`,
 * `application/ld+json`, `text/tikz` —: le isole di dati, che il nonce non lo
 * vogliono. Un `<script` seguito da `\`, `[` o `(` è un'espressione regolare
 * (i sanificatori), non un tag.
 *
 * Le eccezioni stanno in eccezioni(), con il perché; un'eccezione che non serve
 * più fa fallire la guardia, come una voce morta della baseline di PHPStan.
 */
final class OgniScriptDellAppHaIlNonceTest extends TestCase
{
    /**
     * File => perché. Oggi nessuna: l'ultima era views/admin/delete_temp.html,
     * file morto e non instradato, tolto il 23/9/2026 (revisione
     * architetturale A-45). Il metodo resta per quelle che verranno, con la
     * prova che le tiene vive.
     *
     * @return array<string, string>
     */
    private static function eccezioni(): array
    {
        return [];
    }

    private const NONCE = '~^(?:'
        . '<\?=\s*\\\\?(?:App\\\\Support\\\\)?Csp::attributo\(\)\s*;?\s*\?>'
        . '|[\'"]\s*\.\s*\\\\?(?:App\\\\Support\\\\)?Csp::attributo\(\)'
        . ')~';

    /** Le forme con la variabile: valgono se il file la assegna da Csp::attributo(). */
    private const NONCE_IN_VARIABILE = '~^(?:\{\$csp\}|[\'"]\s*\.\s*\$csp\b)~';

    private const ASSEGNA_CSP = '~\$csp\s*=\s*\\\\?(?:App\\\\Support\\\\)?Csp::attributo\(\)~';

    private const ISOLA_DI_DATI = '~^\s+type=\\\\?["\']?(?:application/(?:ld\+)?json|text/tikz)\b~i';

    /** Dopo `<script`: un'espressione regolare, non un tag. */
    private const ESPRESSIONE_REGOLARE = '~^[\\\\\[(]~';

    private static function radice(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Il sorgente senza commenti: quelli di PHP (tokenizer) e, nel testo fuori
     * da PHP, quelli HTML. Al loro posto spazi e a capo, così le righe restano
     * quelle del file.
     */
    private static function senzaCommenti(string $sorgente, bool $php): string
    {
        $vuoto = static fn(string $s): string => (string)preg_replace('/[^\n]/', ' ', $s);
        $togliHtml = static fn(string $s): string => (string)preg_replace_callback(
            '/<!--.*?-->/s',
            static fn(array $m): string => $vuoto($m[0]),
            $s
        );
        if (!$php) {
            return $togliHtml($sorgente);
        }
        $fuori = '';
        foreach (token_get_all($sorgente) as $t) {
            if (!is_array($t)) {
                $fuori .= $t;
                continue;
            }
            [$tipo, $testo] = $t;
            $fuori .= match ($tipo) {
                T_COMMENT, T_DOC_COMMENT => $vuoto($testo),
                T_INLINE_HTML => $togliHtml($testo),
                default => $testo,
            };
        }
        return $fuori;
    }

    /**
     * Gli `<script` di un sorgente che non portano il nonce.
     *
     * @return list<string> «nome:riga  estratto»
     */
    public static function scriptSenzaNonce(string $sorgente, string $nome): array
    {
        $testo = self::senzaCommenti($sorgente, str_ends_with($nome, '.php'));
        $assegnaCsp = preg_match(self::ASSEGNA_CSP, $testo) === 1;
        $trovati = [];
        preg_match_all('~<script~i', $testo, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [, $pos]) {
            $dopo = substr($testo, $pos + 7, 200);
            if (
                preg_match(self::ESPRESSIONE_REGOLARE, $dopo) === 1
                || preg_match(self::NONCE, $dopo) === 1
                || ($assegnaCsp && preg_match(self::NONCE_IN_VARIABILE, $dopo) === 1)
                || preg_match(self::ISOLA_DI_DATI, $dopo) === 1
            ) {
                continue;
            }
            $riga = substr_count($testo, "\n", 0, $pos) + 1;
            $estratto = trim((string)preg_replace('/\s+/', ' ', substr($testo, $pos, 70)));
            $trovati[] = sprintf('%s:%d  %s', $nome, $riga, $estratto);
        }
        return $trovati;
    }

    /** @return array<string, string> percorso relativo => sorgente */
    private static function sorgenti(): array
    {
        $out = [];
        foreach (['views' => '/\.(php|html?)$/', 'app' => '/\.php$/'] as $cartella => $estensioni) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::radice() . '/' . $cartella));
            foreach ($it as $file) {
                if ($file->isFile() && preg_match($estensioni, $file->getFilename()) === 1) {
                    $rel = substr($file->getPathname(), strlen(self::radice()) + 1);
                    $out[$rel] = (string)file_get_contents($file->getPathname());
                }
            }
        }
        ksort($out);
        return $out;
    }

    #[Test]
    public function ogniScriptDiViewsEAppHaIlNonceOEUnIsolaDiDati(): void
    {
        $senza = [];
        $letti = 0;
        foreach (self::sorgenti() as $nome => $sorgente) {
            if (isset(self::eccezioni()[$nome])) {
                continue;
            }
            $letti += substr_count(strtolower($sorgente), '<script');
            array_push($senza, ...self::scriptSenzaNonce($sorgente, $nome));
        }
        // Che abbia guardato davvero: nei sorgenti ci sono decine di `<script`.
        self::assertGreaterThan(50, $letti, 'la guardia ha letto i sorgenti');
        self::assertSame([], $senza, "script dell'applicazione senza il nonce di App\\Support\\Csp:\n" . implode("\n", $senza));
    }

    #[Test]
    public function leEccezioniServonoAncora(): void
    {
        $sorgenti = self::sorgenti();
        // Anche senza eccezioni la prova guarda qualcosa: che ognuna nomini un
        // file che c'è (zero su zero, oggi).
        self::assertCount(
            \count(self::eccezioni()),
            array_intersect_key(self::eccezioni(), $sorgenti),
            "un'eccezione nomina un file che non c'è più"
        );
        foreach (array_keys(self::eccezioni()) as $nome) {
            self::assertArrayHasKey($nome, $sorgenti, "l'eccezione {$nome} nomina un file che non c'è più");
            self::assertNotSame(
                [],
                self::scriptSenzaNonce($sorgenti[$nome], $nome),
                "l'eccezione {$nome} non serve più: toglila"
            );
        }
    }

    /** L'altro verso: uno script senza nonce, in ognuna delle forme in cui si scrive, la fa scattare. */
    #[Test]
    public function unoScriptSenzaNonceInUnaVistaOInUnaClasseLaFaScattare(): void
    {
        $vista = "<div>\n<script src=\"/js/x.js\" defer></script>\n<script>\nvar a = 1;\n</script>\n</div>\n";
        $trovati = self::scriptSenzaNonce($vista, 'views/prova.php');
        self::assertCount(2, $trovati);
        self::assertStringStartsWith('views/prova.php:2  <script src="/js/x.js"', $trovati[0]);
        self::assertStringStartsWith('views/prova.php:3  <script> var a', $trovati[1]);

        $classe = <<<'PHP'
        <?php
        $a = '<script type="module" src="/x.js"></script>';
        $b = "<script type=\"module\" src=\"/y.js\"></script>";
        $c = <<<HTML
        <script>alert(1)</script>
        HTML;
        $d = '<script' . $altro . '>';
        PHP;
        self::assertCount(4, self::scriptSenzaNonce($classe, 'app/Prova.php'));
    }

    #[Test]
    public function leFormeConIlNonceNonLaFannoScattare(): void
    {
        $vista = '<script<?= \App\Support\Csp::attributo() ?> src="/x.js"></script>'
            . '<script<?= Csp::attributo(); ?>>var a;</script>';
        self::assertSame([], self::scriptSenzaNonce($vista, 'views/prova.php'));

        $classe = <<<'PHP'
        <?php
        $csp = \App\Support\Csp::attributo();
        $a = '<script' . \App\Support\Csp::attributo() . ' type="module"></script>';
        $b = "<script" . Csp::attributo() . ">";
        $c = '<script' . $csp . '>';
        $d = <<<HTML
        <script{$csp} src="/x.js"></script>
        HTML;
        PHP;
        self::assertSame([], self::scriptSenzaNonce($classe, 'app/Prova.php'));

        // La variabile vale solo se viene da Csp::attributo().
        $senzaAssegnazione = "<?php\n\$d = <<<HTML\n<script{\$csp}>x</script>\nHTML;\n";
        self::assertCount(1, self::scriptSenzaNonce($senzaAssegnazione, 'app/Prova.php'));
    }

    #[Test]
    public function isoleDiDatiCommentiEdEspressioniRegolariNonLaFannoScattare(): void
    {
        $vista = "<script type=\"application/json\" id=\"s\">{}</script>\n"
            . "<!-- il loader deve venire dopo lo <script async> della configurazione -->\n"
            . "<?php /* un tempo qui c'era uno <script> in linea */ ?>\n"
            . "<?php // come quando era uno <script> inline ?>\n";
        self::assertSame([], self::scriptSenzaNonce($vista, 'views/prova.php'));

        $classe = <<<'PHP'
        <?php
        /** Toglie `<script>` e gli on*=. */
        $a = '<script type="application/ld+json">' . $json . '</script>';
        $b = '<script type="text/tikz" data-show-console="true">';
        $c = "<script type=\"application/json\">";
        $d = preg_replace('#<script\b[^>]*>.*?</script\s*>#si', '', $s);
        $e = '/<script\s+type=["\']text\/tikz/';
        PHP;
        self::assertSame([], self::scriptSenzaNonce($classe, 'app/Prova.php'));

        // Un .html non passa dal tokenizer: i commenti HTML si tolgono lo stesso.
        self::assertSame([], self::scriptSenzaNonce('<!-- <script>x</script> -->', 'views/prova.html'));
        self::assertCount(1, self::scriptSenzaNonce('<script>x</script>', 'views/prova.html'));
    }
}
