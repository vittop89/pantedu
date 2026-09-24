<?php

namespace Tests\Unit\Core;

use App\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Chi chiama una rotta con `audit_reason` le manda la motivazione (23/9/2026).
 *
 * ── Perché ────────────────────────────────────────────────────────────────
 *
 * In produzione `AUDIT_REASON_MODE` è `enforce`: una mutazione di un
 * super-admin senza `X-Audit-Reason` (o senza il campo `_audit_reason`) si
 * ferma con 400 `audit_reason_required`. Mettere `audit_reason` su una rotta
 * è metà del lavoro; l'altra metà è il pulsante che la chiama. Il 23/9/2026,
 * scrivendo questa prova, è venuto fuori che «Sposta» del pannello Sezioni
 * (`/admin/sections/student`, con `audit_reason` da settembre) mandava un
 * modulo senza motivazione: in produzione rispondeva 400. La suite end-to-end
 * non se n'era accorta perché mandava l'intestazione su ogni richiesta del
 * browser (`extraHTTPHeaders` in playwright.config.js).
 *
 * La prova vicina in Vitest (`motivazione-nelle-mutazioni-admin.test.js`)
 * guarda solo le `fetch` con `/api/admin/` e `method: "POST"` scritto per
 * esteso: non vede i moduli HTML, gli aiutanti (`post`, `apiPost`,
 * `postJson`, `fetchJson`), né le rotte fuori da quel prefisso.
 *
 * ── Che cosa guarda ───────────────────────────────────────────────────────
 *
 * Le rotte vengono dal Router vero: ogni rotta che scrive e porta
 * `audit_reason`, qualunque sia il percorso. I chiamanti vengono da `js/`,
 * `views/` e `app/Controllers/` (le pagine scritte dentro un controller):
 *
 *  - in un file JS, ogni stringa che è l'indirizzo di una di quelle rotte
 *    (anche con pezzi `${…}`), o un alias di `Endpoints` che lo è: se sta
 *    dentro una chiamata che scrive (`method: "POST"` o simili, oppure un
 *    aiutante che si chiama post…/apiPost/put…), la chiamata deve contenere
 *    `X-Audit-Reason`, `auditReason(`, `auditHeaders(` o `_audit_reason`.
 *    Commenti e letture (una `fetchJson` senza metodo) non contano;
 *  - in una vista o in un controller, ogni `<form method="post">` il cui
 *    `action` è una di quelle rotte deve avere un campo `_audit_reason`:
 *    nascosto con almeno dieci caratteri, o visibile e `required`; e ogni
 *    `fetch(` verso una di quelle rotte, come sopra.
 *
 * E in più: ogni rotta con `audit_reason` deve avere almeno un chiamante
 * riconosciuto, o stare in SENZA_CHIAMANTE con il perché. Così un chiamante
 * scritto in una forma che la prova non sa leggere (un indirizzo composto
 * con `+`, un modulo con l'azione calcolata) non passa in silenzio: la rotta
 * risulta senza chiamanti e la prova lo dice.
 *
 * ── Che cosa non guarda ───────────────────────────────────────────────────
 *
 * Che l'aiutante inoltri davvero il motivo che riceve: si guarda la chiamata,
 * non il corpo di `post()`. E un indirizzo messo in una variabile lontano
 * dalla chiamata (`url = "/x"; … fetch(url, …)`) non è riconoscibile: se la
 * rotta ha altri chiamanti riconosciuti, quel chiamante sfugge. Per questo le
 * chiamate aggiornate il 23/9/2026 scrivono l'indirizzo dentro la chiamata.
 */
final class ChiamantiMandanoLaMotivazioneTest extends TestCase
{
    /** Dove stanno i chiamanti, relativo alla radice del repository. */
    private const CARTELLE = ['js', 'views', 'app/Controllers'];

    /** Il file che dà un nome agli indirizzi (`Endpoints.files.deleteFile`). */
    private const ENDPOINTS = 'js/modules/core/endpoints.js';

    /** Che una chiamata porta la motivazione. */
    private const SEGNI = ['X-Audit-Reason', 'auditReason(', 'auditHeaders(', '_audit_reason'];

    /** I metodi che cambiano stato sul server. */
    private const SCRIVONO = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Parole che precedono una parentesi senza essere una chiamata. */
    private const NON_CHIAMATE = ['if', 'for', 'while', 'switch', 'catch', 'return', 'function', 'typeof', 'await', 'new'];

    /** Segnaposto per un pezzo calcolato di un indirizzo (`${…}`, `<?= … ?>`). */
    private const PEZZO = "\x01";

    /**
     * Rotte con `audit_reason` senza un chiamante nell'interfaccia, e perché.
     * Può solo diminuire: una rotta che trova un chiamante esce da qui.
     *
     * @var array<string, string>
     */
    private const SENZA_CHIAMANTE = [
        'POST /api/admin/adozioni' =>
            'API del catalogo delle adozioni a mano: la usa la suite end-to-end '
            . '(tests/e2e/support/api/adozioni.api.ts); il pannello passa dal dataset MIUR.',
        'POST /api/admin/adozioni/{id}/delete' =>
            'Come sopra: la pulizia dei libri messi dalla suite.',
        'POST /api/institutes' =>
            'API JSON senza chiamanti: gli istituti si creano dal modulo /admin/institutes/new.',
        'POST /admin/sidebar-config/reorder' =>
            'Nessun pulsante la chiama: l’ordine delle sezioni si salva riga per riga '
            . 'da /admin/sidebar-config/save.',
        'POST /files/delete-folder' =>
            '`Endpoints.files.deleteFolder` esiste, ma nessuno lo chiama.',
        'POST /files/save-pdf' =>
            '`Endpoints.files.savePdf` esiste, ma nessuno lo chiama: i PDF si salvano '
            . 'dalla compilazione di /api/verifica/*.',
        'POST /api/admin/verifica/preamble' =>
            'Nessun pulsante la chiama oggi: il preambolo si modifica come file dal '
            . 'pannello dei modelli (/api/admin/verifica/files/write).',
        'POST /api/admin/verifica/preamble/reset' =>
            'Come sopra.',
        'PUT /admin/waf/api/rules/{id}' =>
            'Nessun pulsante modifica una regola WAF esistente: il pannello le crea, '
            . 'le accende o spegne e le elimina (js/entries/admin-waf-rules.js).',
    ];

    #[Test]
    public function i_chiamanti_delle_rotte_con_motivazione_la_mandano(): void
    {
        $esito = self::esamina(self::rotteConMotivazione(self::rotteDelSito()), self::sorgenti());

        // Una prova che non trova chiamanti dice sempre di sì: il 23/9/2026 le
        // chiamate e i moduli riconosciuti erano più di cento.
        self::assertGreaterThan(80, array_sum($esito['chiamate']), 'la prova deve trovare i chiamanti, non zero');
        self::assertSame([], $esito['senza'], sprintf(
            "%d chiamate a rotte con `audit_reason` non mandano la motivazione: in produzione "
            . "(AUDIT_REASON_MODE=enforce) rispondono 400 audit_reason_required.\n\n"
            . "Fetch: intestazione X-Audit-Reason con auditReason(contesto, dettaglio) di "
            . "js/modules/core/audit-reason.js. Modulo HTML: campo _audit_reason (nascosto con "
            . "un testo che dica che cosa si fa, o visibile e required).\n\n  %s",
            \count($esito['senza']),
            implode("\n  ", $esito['senza'])
        ));
    }

    #[Test]
    public function ogni_rotta_con_motivazione_ha_un_chiamante_riconosciuto_o_un_perche(): void
    {
        $rotte = self::rotteConMotivazione(self::rotteDelSito());
        $esito = self::esamina($rotte, self::sorgenti());

        $orfane = [];
        foreach (array_keys($rotte) as $chiave) {
            if (($esito['chiamate'][$chiave] ?? 0) === 0 && !isset(self::SENZA_CHIAMANTE[$chiave])) {
                $orfane[] = $chiave;
            }
        }
        $trovate = [];
        foreach (array_keys(self::SENZA_CHIAMANTE) as $chiave) {
            if (!isset($rotte[$chiave])) {
                $trovate[] = $chiave . ' (non è più una rotta con audit_reason)';
            } elseif (($esito['chiamate'][$chiave] ?? 0) > 0) {
                $trovate[] = $chiave . ' (ha un chiamante)';
            }
        }

        self::assertSame([], $orfane, "Rotte con audit_reason senza un chiamante riconosciuto. "
            . "Se il chiamante c'è, scrivi l'indirizzo dentro la chiamata (non composto con + "
            . "né in una variabile); se non c'è, mettila in SENZA_CHIAMANTE con il perché.\n  "
            . implode("\n  ", $orfane));
        self::assertSame([], $trovate, "SENZA_CHIAMANTE può solo diminuire: togli\n  "
            . implode("\n  ", $trovate));
    }

    /**
     * La controprova, su sorgenti finti: ogni forma di chiamata riconosciuta
     * passa con la motivazione ed è presa senza; commenti e letture non
     * contano; un indirizzo lontano dalla chiamata non è un chiamante.
     */
    #[Test]
    public function la_guardia_si_accorge_di_un_chiamante_senza_motivazione(): void
    {
        $rotte = [
            'POST /api/admin/cose/{id}' => '/api/admin/cose/{id}',
            'PUT /api/admin/preset/{name}' => '/api/admin/preset/{name}',
            'POST /admin/sezioni/sposta' => '/admin/sezioni/sposta',
            'POST /tikz/modello' => '/tikz/modello',
            'POST /admin/migra' => '/admin/migra',
        ];
        $js = <<<'JS'
            import { Endpoints } from "../core/endpoints.js";
            // fetch("/api/admin/cose/1", { method: "POST" }) — un commento non conta
            const a = await fetch(`/api/admin/cose/${id}`, {
                method: "POST", headers: { "X-Audit-Reason": auditReason("Cambio", x) }, body: fd });
            const b = await fetch(`/api/admin/cose/${encodeURIComponent(id)}`, {
                method: "POST", headers: { "Content-Type": "application/json" }, body: fd });
            const c = await post(`/api/admin/cose/${id}`, { x: 1 }, auditReason(`Cambio #${id}`));
            const d = await apiPost("/api/admin/cose/7", { x: (1 + 2) });
            const e = await fetchJson(`/api/admin/preset/${n}?scope=${s}`, { cache: "no-store" });
            const f = await fetchJson(`/api/admin/preset/${n}?scope=${s}`, { method: "PUT", headers: { a: "(" } });
            const g = _postJson(Endpoints.tikz.modello, { etichetta: ")" });
            const h = /["'(]/.test(x) ? 1 : 2;
            let url = "/tikz/modello";
            const i = await apiCall('POST', `/api/admin/cose/${id}`, null);
            JS;
        $endpoints = <<<'JS'
            export const Endpoints = {
                tikz: {
                    modello:        "/tikz/modello",
                },
            };
            JS;
        $vista = <<<'PHP'
            <form method="POST" action="/admin/sezioni/sposta" class="x">
                <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="_audit_reason" value="Studente #<?= (int)$s['id'] ?> spostato di sezione">
            </form>
            <form method="post" action="/admin/sezioni/sposta" data-nome="<?= $h($a['nome']) ?>">
                <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            </form>
            <form method="post" action="/admin/sezioni/sposta">
                <input type="hidden" name="_audit_reason" value="corta">
            </form>
            <form method="post" action="/admin/sezioni/sposta">
                <input type="text" name="_audit_reason" minlength="10">
            </form>
            <form method="GET" action="/admin/sezioni/sposta"></form>
            <a href="/admin/sezioni/sposta">non è un invio</a>
            PHP;
        $controller = <<<'PHP'
            <?php
            $html = <<<HTML
            <script>
            const r = await fetch('/admin/migra', { method: 'POST', headers: { 'X-CSRF-Token': '{$csrf}' } });
            </script>
            HTML;
            PHP;

        $esito = self::esamina($rotte, [
            'js/finto.js' => $js,
            self::ENDPOINTS => $endpoints,
            'views/finta.php' => $vista,
            'app/Controllers/Finto.php' => $controller,
        ]);

        self::assertSame([
            'app/Controllers/Finto.php:4 → fetch(…) verso POST /admin/migra',
            'js/finto.js:10 → fetchJson(…) verso PUT /api/admin/preset/{name}',
            'js/finto.js:11 → _postJson(…) verso POST /tikz/modello',
            'js/finto.js:14 → apiCall(…) verso POST /api/admin/cose/{id}',
            'js/finto.js:5 → fetch(…) verso POST /api/admin/cose/{id}',
            'js/finto.js:8 → apiPost(…) verso POST /api/admin/cose/{id}',
            'views/finta.php:11 → <form> verso POST /admin/sezioni/sposta (campo visibile non required)',
            'views/finta.php:5 → <form> verso POST /admin/sezioni/sposta',
            'views/finta.php:8 → <form> verso POST /admin/sezioni/sposta (motivazione nascosta più corta di 10 caratteri)',
        ], $esito['senza']);
        $chiamate = $esito['chiamate'];
        ksort($chiamate);
        self::assertSame([
            'POST /admin/migra' => 1,
            'POST /admin/sezioni/sposta' => 4,
            'POST /api/admin/cose/{id}' => 5,
            'POST /tikz/modello' => 1,
            'PUT /api/admin/preset/{name}' => 1,
        ], $chiamate, 'la lettura, il commento, il collegamento e la variabile non sono chiamanti');
    }

    /**
     * Nel motivo niente indirizzi IP né nomi utente (23/9/2026).
     *
     * La motivazione finisce in chiaro in `privileged_access_log.reason`, dove
     * l'IP di chi agisce sta solo come hash (RequestFingerprint, migrazione
     * 100). Tre motivi del pannello WAF ci mettevano l'indirizzo aggiunto o
     * bloccato. L'oggetto dell'azione lo tiene già la tabella che la riceve.
     */
    #[Test]
    public function nel_motivo_non_finiscono_indirizzi_ne_nomi_utente(): void
    {
        $trovati = [];
        foreach (self::sorgenti() as $file => $testo) {
            if (str_ends_with($file, '.js')) {
                foreach (self::identificativiNelMotivo($testo) as [$riga, $nome]) {
                    $trovati[] = "$file:$riga → $nome";
                }
            }
        }
        self::assertSame([], $trovati, "Un motivo che contiene un indirizzo o un nome utente:\n  "
            . implode("\n  ", $trovati));

        // La controprova.
        $finto = <<<'JS'
            headers: { "X-Audit-Reason": `Blocco di ${ip} per ${data.reason}` },
            const m = auditReason("Blocco di una credenziale", data.username);
            const n = auditReason(`Sblocco sulla sezione ${section}`, "indirizzo IP bloccato per errore");
            JS;
        self::assertSame([[1, 'ip'], [2, 'username']], self::identificativiNelMotivo($finto));
    }

    /**
     * Gli identificativi `ip`, `ip_or_cidr`, `CLIENT_IP`, `clientIp`,
     * `username` usati dentro un motivo: gli argomenti di `auditReason(…)` e
     * il valore di `X-Audit-Reason` sulla sua riga, tolte le stringhe
     * semplici (il testo) e tenuti i pezzi `${…}` dei modelli.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function identificativiNelMotivo(string $testo): array
    {
        $pezzi = [];
        foreach (self::chiamate($testo, 'auditReason') as [$dove, $corpo]) {
            $pezzi[] = [$dove, $corpo];
        }
        if (preg_match_all('/[\'"]X-Audit-Reason[\'"]\s*:[^\n]*/', $testo, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as [$corpo, $dove]) {
                $pezzi[] = [(int)$dove, $corpo];
            }
        }
        $fuori = [];
        foreach ($pezzi as [$dove, $corpo]) {
            $senzaTesto = (string)preg_replace(['/"(?:[^"\\\\]|\\\\.)*"/', "/'(?:[^'\\\\]|\\\\.)*'/"], '""', $corpo);
            if (preg_match('/(?<![\w$])(?:[\w$]+\.)*(ip|ip_or_cidr|CLIENT_IP|clientIp|username)\b/', $senzaTesto, $n) === 1) {
                $fuori[] = [self::riga($testo, $dove), $n[1]];
            }
        }
        usort($fuori, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return $fuori;
    }

    // ───────────────────────────── la guardia ─────────────────────────────

    private static function rotteDelSito(): Router
    {
        $router = new Router();
        require \dirname(__DIR__, 3) . '/routes/web.php';
        return $router;
    }

    /**
     * Le rotte che scrivono e chiedono la motivazione: chiave `METODO percorso`
     * per ogni verbo che scrive, valore il percorso.
     *
     * @return array<string, string>
     */
    private static function rotteConMotivazione(Router $router): array
    {
        $fuori = [];
        foreach ($router->routes() as $rotta) {
            if (!CoperturaMotivazioneTest::chiedeLaMotivazione($rotta->middleware)) {
                continue;
            }
            foreach (array_intersect($rotta->methods, self::SCRIVONO) as $metodo) {
                $fuori[$metodo . ' ' . $rotta->pattern] = $rotta->pattern;
            }
        }
        return $fuori;
    }

    /** @return array<string, string> percorso relativo → contenuto */
    private static function sorgenti(): array
    {
        $radice = \dirname(__DIR__, 3);
        $fuori = [];
        foreach (self::CARTELLE as $cartella) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($radice . '/' . $cartella, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if (!\in_array($file->getExtension(), ['js', 'php', 'html'], true)) {
                    continue;
                }
                $rel = substr($file->getPathname(), \strlen($radice) + 1);
                $fuori[$rel] = (string)file_get_contents($file->getPathname());
            }
        }
        ksort($fuori);
        return $fuori;
    }

    /**
     * @param array<string, string> $rotte   chiave → percorso
     * @param array<string, string> $sorgenti percorso del file → contenuto
     * @return array{senza: list<string>, chiamate: array<string, int>}
     */
    public static function esamina(array $rotte, array $sorgenti): array
    {
        $alias = isset($sorgenti[self::ENDPOINTS]) ? self::alias($sorgenti[self::ENDPOINTS]) : [];
        $senza = [];
        $chiamate = [];
        foreach ($sorgenti as $file => $testo) {
            $trovate = str_ends_with($file, '.js')
                ? ($file === self::ENDPOINTS ? [] : self::chiamateJs($testo, $alias))
                : self::chiamateInPagina($testo);
            foreach ($trovate as [$riga, $indirizzo, $come, $motivata, $nota, $verbo]) {
                foreach (self::rotteDi($indirizzo, $rotte) as $chiave) {
                    if (!str_starts_with($chiave, $verbo . ' ')) {
                        continue;
                    }
                    $chiamate[$chiave] = ($chiamate[$chiave] ?? 0) + 1;
                    if (!$motivata) {
                        $senza[] = "$file:$riga → $come verso $chiave" . ($nota !== '' ? " ($nota)" : '');
                    }
                }
            }
        }
        sort($senza);
        return ['senza' => $senza, 'chiamate' => $chiamate];
    }

    /**
     * Le rotte che un indirizzo scritto nel codice può raggiungere.
     *
     * Si confronta nei due versi: un pezzo calcolato dell'indirizzo può
     * valere qualunque segmento della rotta, e un segnaposto della rotta
     * qualunque segmento dell'indirizzo.
     *
     * @param array<string, string> $rotte
     * @return list<string>
     */
    private static function rotteDi(string $indirizzo, array $rotte): array
    {
        $indirizzo = (string)preg_replace('/[?#].*$/s', '', $indirizzo);
        if (!str_starts_with($indirizzo, '/')) {
            return [];
        }
        $comeRegex = '#^' . implode('[^/]+', array_map(
            static fn(string $p): string => preg_quote($p, '#'),
            explode(self::PEZZO, $indirizzo)
        )) . '$#';
        $concreto = str_replace(self::PEZZO, 'x1', $indirizzo);

        $fuori = [];
        foreach ($rotte as $chiave => $percorso) {
            $esempio = (string)preg_replace(['/\{\w+\*\}/', '/\{\w+\??\}/'], ['x1/x2', 'x1'], $percorso);
            $regexRotta = '#^' . preg_replace_callback(
                '/\{\w+(\*|\?)?\}|[^{]+/',
                static fn(array $m): string => $m[0][0] === '{'
                    ? (($m[1] ?? '') === '*' ? '.+' : '[^/]+')
                    : preg_quote($m[0], '#'),
                $percorso
            ) . '$#';
            if (preg_match($comeRegex, $esempio) === 1 || preg_match($regexRotta, $concreto) === 1) {
                $fuori[] = $chiave;
            }
        }
        return $fuori;
    }

    /**
     * I nomi di `Endpoints` che sono un indirizzo: `Endpoints.files.deleteFile`
     * → `/files/delete`.
     *
     * @return array<string, string>
     */
    private static function alias(string $testo): array
    {
        [, $letterali] = self::lessico($testo);
        $fuori = [];
        foreach ($letterali as [$inizio, , $valore]) {
            $prima = substr($testo, 0, $inizio);
            $riga = substr($prima, (int)strrpos("\n" . $prima, "\n"));
            if (preg_match('/^\s*(\w+)\s*:/', $riga, $chiave) !== 1) {
                continue;
            }
            if (preg_match_all('/^ {4}(\w+)\s*:\s*\{/m', $prima, $gruppi) < 1) {
                continue;
            }
            $gruppo = end($gruppi[1]);
            $fuori['Endpoints.' . $gruppo . '.' . $chiave[1]] = $valore;
        }
        return $fuori;
    }

    /**
     * Le chiamate che scrivono in un file JS, con l'indirizzo e se portano la
     * motivazione.
     *
     * @param array<string, string> $alias
     * @return list<array{0: int, 1: string, 2: string, 3: bool, 4: string, 5: string}>
     */
    private static function chiamateJs(string $testo, array $alias): array
    {
        [$scheletro, $letterali] = self::lessico($testo);
        $riferimenti = [];
        foreach ($letterali as [$inizio, , $valore]) {
            if (str_starts_with($valore, '/')) {
                $riferimenti[] = [$inizio, $valore];
            }
        }
        if (preg_match_all('/\bEndpoints\.(\w+)\.(\w+)\b/', $scheletro, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as [$nome, $dove]) {
                if (isset($alias[$nome])) {
                    $riferimenti[] = [(int)$dove, $alias[$nome]];
                }
            }
        }

        $fuori = [];
        foreach ($riferimenti as [$dove, $indirizzo]) {
            $chiamata = self::chiamataAttorno($scheletro, $dove);
            if ($chiamata === null) {
                continue;
            }
            [$inizio, $fine, $nome] = $chiamata;
            $corpo = substr($testo, $inizio, $fine - $inizio);
            $verbo = self::verbo($nome, $corpo);
            if ($verbo === null) {
                continue;
            }
            $fuori[] = [self::riga($testo, $dove), $indirizzo, $nome . '(…)', self::motivata($corpo), '', $verbo];
        }
        return $fuori;
    }

    /**
     * I moduli e le `fetch` di una vista o di un controller.
     *
     * @return list<array{0: int, 1: string, 2: string, 3: bool, 4: string, 5: string}>
     */
    private static function chiamateInPagina(string $testo): array
    {
        // I pezzi PHP diventano un segnaposto, a righe invariate: dentro un
        // attributo, la chiusura del pezzo PHP chiuderebbe il tag prima del
        // tempo. (Qui niente chiusura PHP scritta per esteso: in un commento
        // di riga termina il file PHP.)
        $piano = (string)preg_replace_callback(
            '/<\?(?:=|php\b)[\s\S]*?\?>/',
            static fn(array $m): string => self::PEZZO . str_repeat("\n", substr_count($m[0], "\n")),
            $testo
        );
        $fuori = [];

        preg_match_all('/<form\b([^>]*)>/i', $piano, $moduli, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($moduli as $modulo) {
            $attributi = $modulo[1][0];
            if (preg_match('/\bmethod\s*=\s*["\']?post\b/i', $attributi) !== 1
                || preg_match('/\baction\s*=\s*"([^"]*)"/i', $attributi, $azione) !== 1) {
                continue;
            }
            $dopo = $modulo[0][1] + \strlen($modulo[0][0]);
            $chiude = stripos($piano, '</form>', $dopo);
            $corpo = substr($piano, $dopo, ($chiude === false ? \strlen($piano) : $chiude) - $dopo);
            [$motivata, $nota] = self::campoMotivazione($corpo);
            $fuori[] = [self::riga($piano, $modulo[0][1]), $azione[1], '<form>', $motivata, $nota, 'POST'];
        }

        foreach (self::chiamate($piano, 'fetch') as [$dove, $corpo]) {
            if (preg_match('/^fetch\s*\(\s*([\'"`])(.*?)\1/s', $corpo, $primo) !== 1) {
                continue;
            }
            $indirizzo = (string)preg_replace('/\$\{[^}]*\}/', self::PEZZO, $primo[2]);
            $verbo = self::verbo('fetch', $corpo);
            if ($verbo === null) {
                continue;
            }
            $fuori[] = [self::riga($piano, $dove), $indirizzo, 'fetch(…)', self::motivata($corpo), '', $verbo];
        }
        return $fuori;
    }

    /**
     * Il campo della motivazione in un modulo: nascosto con un testo di almeno
     * dieci caratteri (un pezzo PHP conta uno), o visibile e obbligatorio.
     *
     * @return array{0: bool, 1: string}
     */
    private static function campoMotivazione(string $corpo): array
    {
        if (preg_match('/<input\b[^>]*\bname\s*=\s*["\']_audit_reason["\'][^>]*>/i', $corpo, $campo) !== 1) {
            return [false, ''];
        }
        if (preg_match('/\btype\s*=\s*["\']hidden["\']/i', $campo[0]) === 1) {
            $valore = preg_match('/\bvalue\s*=\s*"([^"]*)"/i', $campo[0], $v) === 1 ? $v[1] : '';
            return mb_strlen($valore) >= 10
                ? [true, '']
                : [false, 'motivazione nascosta più corta di 10 caratteri'];
        }
        return preg_match('/\brequired\b/i', $campo[0]) === 1
            ? [true, '']
            : [false, 'campo visibile non required'];
    }

    /**
     * Le chiamate a una funzione (`fetch(`, `auditReason(`), con le parentesi
     * bilanciate fuori dalle stringhe.
     *
     * @return list<array{0: int, 1: string}> posizione e testo della chiamata
     */
    private static function chiamate(string $testo, string $funzione): array
    {
        $fuori = [];
        preg_match_all('/\b' . preg_quote($funzione, '/') . '\s*\(/', $testo, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$apertura, $dove]) {
            $i = (int)$dove + \strlen($apertura);
            $livello = 1;
            $apice = null;
            $n = \strlen($testo);
            while ($i < $n && $livello > 0) {
                $c = $testo[$i];
                if ($apice !== null) {
                    if ($c === '\\') {
                        $i++;
                    } elseif ($c === $apice) {
                        $apice = null;
                    }
                } elseif ($c === '"' || $c === "'" || $c === '`') {
                    $apice = $c;
                } elseif ($c === '(') {
                    $livello++;
                } elseif ($c === ')') {
                    $livello--;
                }
                $i++;
            }
            $fuori[] = [(int)$dove, substr($testo, (int)$dove, $i - (int)$dove)];
        }
        return $fuori;
    }

    /**
     * La chiamata che contiene la posizione, cercata sullo scheletro (stringhe
     * e commenti spenti): inizio del nome, fine della parentesi, nome. Null se
     * fra la posizione e la parentesi aperta c'è un `;` o una graffa: allora
     * l'indirizzo sta in un'istruzione, non negli argomenti di una chiamata.
     *
     * @return array{0: int, 1: int, 2: string}|null
     */
    private static function chiamataAttorno(string $scheletro, int $dove): ?array
    {
        $livello = 0;
        $apre = null;
        for ($j = $dove - 1; $j >= 0; $j--) {
            $c = $scheletro[$j];
            if ($c === ')' || $c === ']') {
                $livello++;
            } elseif ($c === '(' || $c === '[') {
                if ($livello === 0) {
                    if ($c === '(') {
                        $apre = $j;
                    }
                    break;
                }
                $livello--;
            } elseif ($livello === 0 && ($c === ';' || $c === '{' || $c === '}')) {
                break;
            }
        }
        if ($apre === null) {
            return null;
        }
        $prima = rtrim(substr($scheletro, 0, $apre));
        if (preg_match('/([\w$.]+)$/', $prima, $nome) !== 1) {
            return null;
        }
        $ultimo = (string)substr((string)strrchr('.' . $nome[1], '.'), 1);
        if (\in_array($ultimo, self::NON_CHIAMATE, true)) {
            return null;
        }
        $livello = 0;
        $n = \strlen($scheletro);
        for ($k = $apre; $k < $n; $k++) {
            if ($scheletro[$k] === '(') {
                $livello++;
            } elseif ($scheletro[$k] === ')' && --$livello === 0) {
                return [\strlen($prima) - \strlen($nome[1]), $k + 1, $ultimo];
            }
        }
        return null;
    }

    /**
     * Il verbo con cui la chiamata scrive, o null se legge: un `method` che
     * scrive, un verbo passato come argomento (`apiCall('POST', url, …)`), o
     * un aiutante che si chiama post…, apiPost (POST) o put… (PUT).
     */
    private static function verbo(string $nome, string $corpo): ?string
    {
        if (preg_match('/\bmethod\s*:\s*["\'`](POST|PUT|PATCH|DELETE)["\'`]/i', $corpo, $m) === 1) {
            return strtoupper($m[1]);
        }
        if (preg_match('/[(,]\s*["\'`](POST|PUT|PATCH|DELETE)["\'`]\s*[,)]/', $corpo, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^_?(post\w*|apiPost)$/i', $nome) === 1) {
            return 'POST';
        }
        return preg_match('/^_?put\w*$/i', $nome) === 1 ? 'PUT' : null;
    }

    private static function motivata(string $corpo): bool
    {
        foreach (self::SEGNI as $segno) {
            if (str_contains($corpo, $segno)) {
                return true;
            }
        }
        return false;
    }

    private static function riga(string $testo, int $dove): int
    {
        return substr_count($testo, "\n", 0, $dove) + 1;
    }

    /**
     * Il lessico minimo di un sorgente JS: lo scheletro (commenti, stringhe,
     * modelli ed espressioni regolari spenti con spazi, a lunghezza e righe
     * invariate) e le stringhe, con i pezzi `${…}` dei modelli sostituiti dal
     * segnaposto. Basta per trovare gli indirizzi e le parentesi delle
     * chiamate senza farsi ingannare da una `)` dentro una stringa.
     *
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: string}>}
     */
    private static function lessico(string $s): array
    {
        $scheletro = $s;
        $letterali = [];
        self::codice($s, 0, false, $scheletro, $letterali);
        usort($letterali, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return [$scheletro, $letterali];
    }

    /**
     * Scorre codice JS da $i. Dentro un `${…}` si ferma alla graffa che lo
     * chiude e restituisce la posizione dopo di essa.
     *
     * @param list<array{0: int, 1: int, 2: string}> $letterali
     */
    private static function codice(string $s, int $i, bool $inModello, string &$scheletro, array &$letterali): int
    {
        $n = \strlen($s);
        $graffe = 0;
        $prec = '';
        while ($i < $n) {
            $c = $s[$i];
            $d = $s[$i + 1] ?? '';
            if ($c === '/' && $d === '/') {
                $fine = strpos($s, "\n", $i);
                $fine = $fine === false ? $n : $fine;
                self::spegni($scheletro, $i, $fine);
                $i = $fine;
                continue;
            }
            if ($c === '/' && $d === '*') {
                $fine = strpos($s, '*/', $i + 2);
                $fine = $fine === false ? $n : $fine + 2;
                self::spegni($scheletro, $i, $fine);
                $i = $fine;
                continue;
            }
            if ($c === '/' && ($prec === '' || str_contains('(,=:[!&|?{};+-*%<>~^', $prec) || preg_match('/\b(return|typeof|case|in|of)$/', rtrim(substr($s, max(0, $i - 12), 12))) === 1)) {
                $j = $i + 1;
                $classe = false;
                while ($j < $n && $s[$j] !== "\n") {
                    if ($s[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($s[$j] === '[') {
                        $classe = true;
                    } elseif ($s[$j] === ']') {
                        $classe = false;
                    } elseif ($s[$j] === '/' && !$classe) {
                        break;
                    }
                    $j++;
                }
                self::spegni($scheletro, $i, $j + 1);
                $i = $j + 1;
                $prec = '/';
                continue;
            }
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n && $s[$j] !== $c && $s[$j] !== "\n") {
                    $j += $s[$j] === '\\' ? 2 : 1;
                }
                $letterali[] = [$i, $j + 1, substr($s, $i + 1, $j - $i - 1)];
                self::spegni($scheletro, $i, $j + 1);
                $i = $j + 1;
                $prec = 'a';
                continue;
            }
            if ($c === '`') {
                $i = self::modello($s, $i, $scheletro, $letterali);
                $prec = 'a';
                continue;
            }
            if ($inModello) {
                if ($c === '{') {
                    $graffe++;
                } elseif ($c === '}') {
                    if ($graffe === 0) {
                        return $i + 1;
                    }
                    $graffe--;
                }
            }
            if (!ctype_space($c)) {
                $prec = ctype_alnum($c) || $c === '_' || $c === '$' || $c === ')' || $c === ']' ? 'a' : $c;
            }
            $i++;
        }
        return $n;
    }

    /**
     * Un modello `…`: la stringa con i segnaposto, e le stringhe annidate nei
     * suoi `${…}`. Restituisce la posizione dopo l'accento grave di chiusura.
     *
     * @param list<array{0: int, 1: int, 2: string}> $letterali
     */
    private static function modello(string $s, int $inizio, string &$scheletro, array &$letterali): int
    {
        $n = \strlen($s);
        $valore = '';
        $i = $inizio + 1;
        while ($i < $n && $s[$i] !== '`') {
            if ($s[$i] === '\\') {
                $valore .= substr($s, $i, 2);
                $i += 2;
                continue;
            }
            if ($s[$i] === '$' && ($s[$i + 1] ?? '') === '{') {
                $valore .= self::PEZZO;
                $i = self::codice($s, $i + 2, true, $scheletro, $letterali);
                continue;
            }
            $valore .= $s[$i];
            $i++;
        }
        $letterali[] = [$inizio, $i + 1, $valore];
        self::spegni($scheletro, $inizio, $i + 1);
        return $i + 1;
    }

    /** Spegne un tratto dello scheletro, lasciando gli a capo. */
    private static function spegni(string &$scheletro, int $da, int $a): void
    {
        $a = min($a, \strlen($scheletro));
        for ($k = $da; $k < $a; $k++) {
            if ($scheletro[$k] !== "\n") {
                $scheletro[$k] = ' ';
            }
        }
    }
}
