<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Gli header che portano l'indirizzo del client li legge solo EdgeContext
 * (23/9/2026).
 *
 * `Client-IP`, `X-Forwarded-For`, `CF-Connecting-IP` e simili li sceglie chi
 * manda la richiesta, e nginx li lascia passare. Ci si può credere solo se la
 * connessione arriva da un proxy fidato, ed è esattamente quello che decide
 * `App\Services\Waf\EdgeContext`. Il 23/9/2026 cinque punti li leggevano da sé
 * — il login, i consensi e le cancellazioni, gli hash dei registri di audit,
 * il registro degli accessi, il pannello del WAF — e il blocco dell'IP per
 * sezione si aggirava con un `X-Forwarded-For` inventato (revisione
 * architetturale 2026-09, A-63).
 *
 * Il confine si guarda nel codice, perché una formula nuova copiata da una
 * vecchia non si nota in revisione. Si guardano le stringhe PHP (non i
 * commenti, non l'HTML dei template) di `app/`, `public/`, `routes/` e
 * `views/`, tranne EdgeContext, e se ne cercano due cose:
 *
 * - **letture**: una stringa che è per intero il nome di uno di quegli header
 *   in una delle forme con cui PHP e l'applicazione li espongono. Sono due: la
 *   chiave di `$_SERVER` e `Request::$server` (`HTTP_X_FORWARDED_FOR`) e la
 *   chiave di `Request::$headers` (`x-forwarded-for`), che col trattino è anche
 *   il nome che danno `getallheaders()` e `apache_request_headers()`. Senza
 *   distinzione di maiuscole. Non ce ne devono essere;
 * - **menzioni**: una stringa che contiene il nome di uno di quegli header
 *   senza esserlo per intero, per esempio una riga di header costruita per una
 *   richiesta in uscita. Non leggono niente del client, ma possono essere una
 *   lettura scritta in un'altra forma: ognuna va nominata in
 *   MENZIONI_CHE_NON_SONO_LETTURE col perché, e un'eccezione che il codice non
 *   contiene più fa fallire la prova.
 *
 * Che cosa non vede: un nome spezzato fra più stringhe (`'HTTP_' . $nome`) e le
 * letture fuori da quelle quattro cartelle (gli script di `tools/` non servono
 * richieste).
 *
 * Provato nei due versi: il cercatore trova le letture messe apposta nelle due
 * forme, e non si fa ingannare da un commento che ne parla, da un header di
 * un'altra natura (`X-Forwarded-Proto`) o da una chiave qualunque che si chiama
 * `client_ip`.
 */
final class SoloEdgeContextLeggeGliHeaderDeiProxyTest extends TestCase
{
    /**
     * Gli header che portano un indirizzo, col nome che hanno in HTTP.
     * `Forwarded` (RFC 7239) conta solo come lettura: come parte di un'altra
     * stringa sarebbe anche `X-Forwarded-Proto`.
     */
    private const HEADER_DI_INDIRIZZO = [
        'Client-IP',
        'X-Forwarded-For',
        'X-Real-IP',
        'CF-Connecting-IP',
        'True-Client-IP',
        'X-Client-IP',
        'Forwarded',
    ];

    private const UNICO_LETTORE = 'app/Services/Waf/EdgeContext.php';

    private const CARTELLE = ['app', 'public', 'routes', 'views'];

    /**
     * Stringhe che nominano uno di quegli header senza leggerlo, per file, col
     * perché.
     *
     * @var array<string, array<string, string>>
     */
    private const MENZIONI_CHE_NON_SONO_LETTURE = [
        'app/Services/TexCompile/ControlloTex.php' => [
            'X-Forwarded-For: 127.0.0.1' => 'è un header IN USCITA: la sonda di /health/tex '
                . 'lo manda al nginx dell\'host, come se la richiesta venisse dalla macchina stessa. '
                . 'Non legge niente della richiesta del client',
        ],
    ];

    #[Test]
    public function nessuno_fuori_da_edge_context_legge_l_indirizzo_dagli_header(): void
    {
        $radice = \dirname(__DIR__, 2);
        $trovati = [];
        $eccezioniViste = [];
        $letti = 0;
        foreach (self::CARTELLE as $cartella) {
            $file = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice . '/' . $cartella));
            foreach ($file as $f) {
                if (!$f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }
                $relativo = substr($f->getPathname(), \strlen($radice) + 1);
                if ($relativo === self::UNICO_LETTORE) {
                    continue;
                }
                $letti++;
                $ammesse = self::MENZIONI_CHE_NON_SONO_LETTURE[$relativo] ?? [];
                foreach (self::menzioni((string)file_get_contents($f->getPathname())) as [$testo, $riga, $lettura]) {
                    if ($lettura) {
                        $trovati[] = "{$relativo}:{$riga} legge {$testo}";
                    } elseif (isset($ammesse[$testo])) {
                        $eccezioniViste[$relativo][$testo] = true;
                    } else {
                        $trovati[] = "{$relativo}:{$riga} nomina un header di indirizzo in «{$testo}»: "
                            . 'se non è una lettura, va fra MENZIONI_CHE_NON_SONO_LETTURE col perché';
                    }
                }
            }
        }

        $eccezioniSparite = [];
        foreach (self::MENZIONI_CHE_NON_SONO_LETTURE as $relativo => $stringhe) {
            foreach (array_keys($stringhe) as $testo) {
                if (!isset($eccezioniViste[$relativo][$testo])) {
                    $eccezioniSparite[] = "{$relativo}: «{$testo}»";
                }
            }
        }

        self::assertGreaterThan(450, $letti, 'il cercatore deve aver guardato le cartelle davvero (561 file il 23/9/2026)');
        self::assertSame([], $trovati,
            "l'indirizzo del client si chiede a EdgeContext::clientIp(\$server), "
            . 'che crede a questi header solo da un proxy fidato');
        self::assertSame([], $eccezioniSparite,
            'un\'eccezione che il codice non contiene più si toglie: '
            . 'lasciata lì coprirebbe una stringa nuova con lo stesso testo');
    }

    #[Test]
    public function edge_context_c_e_e_li_legge(): void
    {
        // Se EdgeContext cambiasse posto, la prova sopra non escluderebbe più
        // niente e resterebbe verde anche con l'unico lettore legittimo spostato.
        $edge = \dirname(__DIR__, 2) . '/' . self::UNICO_LETTORE;
        self::assertFileExists($edge);
        $letture = array_filter(
            self::menzioni((string)file_get_contents($edge)),
            static fn (array $m): bool => $m[2],
        );
        self::assertNotSame([], $letture);
    }

    #[Test]
    public function il_cercatore_trova_le_letture_nelle_due_forme(): void
    {
        $sorgente = <<<'PHP'
            <?php
            $ip = $req->server['HTTP_X_FORWARDED_FOR'] ?? $_SERVER["HTTP_CLIENT_IP"] ?? '';
            $ip = $req->headers['x-forwarded-for'] ?? $req->headers["cf-connecting-ip"] ?? '';
            $ip = getallheaders()['X-Real-IP'] ?? $h['True-Client-Ip'] ?? $h['FORWARDED'] ?? '';
            PHP;

        self::assertSame(
            [
                ['HTTP_X_FORWARDED_FOR', 2, true],
                ['HTTP_CLIENT_IP', 2, true],
                ['x-forwarded-for', 3, true],
                ['cf-connecting-ip', 3, true],
                ['X-Real-IP', 4, true],
                ['True-Client-Ip', 4, true],
                ['FORWARDED', 4, true],
            ],
            self::menzioni($sorgente),
        );
    }

    #[Test]
    public function il_cercatore_ignora_commenti_altri_header_e_chiavi_qualunque(): void
    {
        $sorgente = <<<'PHP'
            <?php
            // Un commento che parla di HTTP_X_FORWARDED_FOR non è una lettura.
            /* Nemmeno questo: x-forwarded-for, Client-IP */
            $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $req->headers['x-forwarded-proto'] ?? '';
            $dati = ['client_ip' => $ip, 'forwarded_authority' => $a];
            PHP;

        self::assertSame([], self::menzioni($sorgente));
    }

    #[Test]
    public function il_cercatore_distingue_una_menzione_da_una_lettura(): void
    {
        // La riga di header in uscita di ControlloTex nomina l'header ma non è
        // il suo nome per intero: è una menzione, non una lettura. Un nome
        // dentro una stringa con interpolazione conta allo stesso modo.
        $sorgente = <<<'PHP'
            <?php
            $h = ['Host: ' . $host, 'X-Forwarded-For: 127.0.0.1'];
            $k = "{$prefisso}HTTP_X_FORWARDED_FOR";
            PHP;

        self::assertSame(
            [
                ['X-Forwarded-For: 127.0.0.1', 2, false],
                ['HTTP_X_FORWARDED_FOR', 3, false],
            ],
            self::menzioni($sorgente),
        );
    }

    /**
     * Le stringhe PHP che nominano un header di indirizzo, con la riga e se
     * sono una lettura (il nome per intero, in una delle due forme).
     *
     * @return list<array{0: string, 1: int, 2: bool}>
     */
    private static function menzioni(string $sorgente): array
    {
        $letture = [];
        $pezzi = [];
        foreach (self::HEADER_DI_INDIRIZZO as $nome) {
            $trattino = strtolower($nome);
            $chiaveServer = 'http_' . str_replace('-', '_', $trattino);
            $letture[] = $trattino;
            $letture[] = $chiaveServer;
            $pezzi[] = $chiaveServer;
            if ($trattino !== 'forwarded') {
                $pezzi[] = $trattino;
            }
        }

        $trovate = [];
        foreach (token_get_all($sorgente) as $t) {
            if (!\is_array($t)) {
                continue;
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $testo = substr($t[1], 1, -1);
            } elseif ($t[0] === T_ENCAPSED_AND_WHITESPACE) {
                $testo = $t[1];
            } else {
                continue;
            }
            $minuscolo = strtolower($testo);
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING && \in_array($minuscolo, $letture, true)) {
                $trovate[] = [$testo, $t[2], true];
                continue;
            }
            foreach ($pezzi as $pezzo) {
                if (str_contains($minuscolo, $pezzo)) {
                    $trovate[] = [$testo, $t[2], false];
                    break;
                }
            }
        }
        return $trovate;
    }
}
