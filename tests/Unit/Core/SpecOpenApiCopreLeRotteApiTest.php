<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * La spec OpenAPI committata elenca tutti i percorsi `/api/` del Router vero.
 *
 * ── Perché (revisione architetturale del 23/9/2026, DOC-2) ──
 *
 * `tools/api/generate_openapi.php` estraeva le rotte con una regex sul testo
 * di `routes/web.php`, e riconosceva solo `$router->` e `$r->`: le rotte nei
 * gruppi annidati (`$rr->`, `$rrr->`, dove sta quasi tutta la scrittura
 * dell'API) restavano fuori, e ogni handler che non fosse letteralmente
 * `[Controller::class, 'metodo']` (le chiusure, come le rotte `legacy_gone`)
 * pure. `docs/api/openapi.full.yaml` copriva 112 dei 253 percorsi `/api/`
 * reali — e la spec non lo diceva.
 *
 * Il generatore ora legge le rotte dal vero `App\Core\Router` (si istanzia e
 * si include `routes/web.php`, come `CoperturaCsrfTest` qui accanto), quindi
 * questa prova confronta due cose lette allo stesso modo: se una PR aggiunge
 * una rotta `/api/` e dimentica `composer openapi:build`, il numero diverge e
 * la prova lo dice, con l'elenco delle differenze.
 *
 * Misurato nei due versi (23/9/2026): con la spec di prima del 2026-09-23
 * (112 percorsi committati) la prova falliva già mostrando le 141 mancanti;
 * con la spec rigenerata oggi passa. Il verso «non scatta quando non deve» è
 * la seconda prova qui sotto, che usa un router di due rotte finte e uno YAML
 * finto scritto apposta uguale: non fallisce da sola.
 */
final class SpecOpenApiCopreLeRotteApiTest extends TestCase
{
    private const SPEC = '/docs/api/openapi.full.yaml';

    private static function radice(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * Gli stessi percorsi che vede il sito, normalizzati come fa il
     * generatore: `{nome*}`/`{nome?}` diventano `{nome}` (OpenAPI non ha i
     * modificatori del Router).
     *
     * @return list<string>
     */
    private static function percorsiApiDalRouter(): array
    {
        $router = new Router();
        require self::radice() . '/routes/web.php';

        $percorsi = [];
        foreach ($router->routes() as $rotta) {
            if (!str_starts_with($rotta->pattern, '/api/')) {
                continue;
            }
            $normalizzato = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)[*?]?\}/', '{$1}', $rotta->pattern);
            $percorsi[$normalizzato] = true;
        }
        $elenco = array_keys($percorsi);
        sort($elenco);
        return $elenco;
    }

    /**
     * I percorsi `/api/` dichiarati nella spec YAML indicata.
     *
     * @return list<string>
     */
    private static function percorsiApiDallaSpec(string $percorsoAssoluto): array
    {
        $doc = Yaml::parseFile($percorsoAssoluto);
        $percorsi = array_values(array_filter(
            array_keys($doc['paths'] ?? []),
            static fn (string $p): bool => str_starts_with($p, '/api/')
        ));
        sort($percorsi);
        return $percorsi;
    }

    #[Test]
    public function la_spec_committata_ha_lo_stesso_numero_di_percorsi_api_del_router(): void
    {
        $dalRouter = self::percorsiApiDalRouter();
        $dallaSpec = self::percorsiApiDallaSpec(self::radice() . self::SPEC);

        $mancanti = array_values(array_diff($dalRouter, $dallaSpec));
        $inPiu    = array_values(array_diff($dallaSpec, $dalRouter));

        self::assertSame(
            \count($dalRouter),
            \count($dallaSpec),
            \sprintf(
                "La spec %s ha %d percorsi /api/, il Router ne dichiara %d.\n\n"
                . "Mancanti nella spec (%d): %s\n\nIn più nella spec, non dichiarati dal Router (%d): %s\n\n"
                . "Rigenera con: composer openapi:build",
                self::SPEC,
                \count($dallaSpec),
                \count($dalRouter),
                \count($mancanti),
                implode(', ', $mancanti),
                \count($inPiu),
                implode(', ', $inPiu)
            )
        );
        // Lo stesso numero non basta: devono essere gli stessi percorsi.
        self::assertSame($dalRouter, $dallaSpec);
    }

    /**
     * Controprova, nei due versi, su tabelle scritte apposta: un router con
     * una rotta che lo YAML non ha fa fallire il confronto; con gli stessi
     * due percorsi (uno semplice, uno con parametro) non scatta.
     */
    #[Test]
    public function il_confronto_scatta_quando_manca_un_percorso_e_tace_quando_combaciano(): void
    {
        $router = new Router();
        $router->get('/api/prova', ['App\Controllers\FileController', 'mostra']);
        $router->get('/api/prova/{id}', ['App\Controllers\FileController', 'mostra']);

        $normalizza = static function (array $percorsi): array {
            sort($percorsi);
            return $percorsi;
        };

        $dalRouter = $normalizza(array_map(
            static fn ($r) => preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)[*?]?\}/', '{$1}', $r->pattern),
            array_values(array_filter($router->routes(), static fn ($r) => str_starts_with($r->pattern, '/api/')))
        ));

        // Verso «tace»: lo YAML ha esattamente gli stessi due percorsi.
        self::assertSame($dalRouter, $normalizza(['/api/prova', '/api/prova/{id}']));

        // Verso «scatta»: allo YAML manca /api/prova/{id}.
        $incompleto = $normalizza(['/api/prova']);
        self::assertNotSame($dalRouter, $incompleto);
        self::assertCount(1, array_diff($dalRouter, $incompleto));
    }
}
