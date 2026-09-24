<?php

namespace Tests\Unit\Core;

use App\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ogni mutazione amministrativa chiede la motivazione (ADR-008), oppure è
 * esentata qui per iscritto (23/9/2026).
 *
 * ── Il difetto, dalla revisione architetturale del 23/9/2026 (A-69) ───────
 *
 * ADR-008 vuole la motivazione su tutte le rotte amministrative che
 * modificano. Ricontate con il Router vero, 50 mutazioni su 81 sotto
 * `/admin` e `/api/admin` non la chiedevano: 33 avevano solo `sadmin_audit`,
 * che scrive nel registro soltanto le letture (GET e HEAD), e 17 niente —
 * fra cui il cambio di ruolo, la cancellazione di un utente,
 * `/api/admin/security/*` e `/admin/migrate/run`. Il «chi, cosa, quando»
 * restava in `audit_activity_log`; il «perché» no. E tre commenti dicevano il
 * contrario.
 *
 * ── Che cosa conta come mutazione amministrativa ──────────────────────────
 *
 * Una rotta che accetta POST, PUT, PATCH o DELETE e che:
 *  - sta sotto `/admin` o `/api/admin/`, oppure
 *  - porta `super_admin_required`, oppure
 *  - ha solo la zona `admin` nei suoi middleware di ruolo (config/roles.php):
 *    `/files/*`, `/tikz/*`, `/api/institutes` sono dell'amministratore anche
 *    se il percorso non lo dice.
 *
 * `RequiresAuditReasonMiddleware` agisce solo per il super-admin: per chiunque
 * altro lascia passare. La zona `istituto` (l'amministratore di istituto,
 * ADR-040) oggi non ha nessuna mutazione; il giorno che ne avrà una, portarle
 * la motivazione vuol dire estendere prima il middleware a lui, perché oggi
 * la salterebbe. Per questo c'è una prova a parte che fallisce alla prima
 * mutazione della zona: la scelta si fa lì, non per caso.
 *
 * ── Nei due versi ─────────────────────────────────────────────────────────
 *
 * Fallisce se una mutazione amministrativa non ha `audit_reason` e non sta
 * nelle esenzioni; e fallisce se un'esenzione non corrisponde più a una
 * mutazione amministrativa, o se la sua rotta nel frattempo ha acquisito
 * `audit_reason`: l'elenco può solo scendere. La controprova su un Router
 * finto dice che la regola si accorge di una rotta scoperta.
 *
 * Che i chiamanti la mandino davvero lo guarda ChiamantiMandanoLaMotivazioneTest.
 */
final class CoperturaMotivazioneTest extends TestCase
{
    /** I metodi che cambiano stato sul server. */
    private const SCRIVONO = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Mutazioni amministrative senza motivazione, e perché.
     *
     * Chiave: `METODO percorso` (per una rotta dichiarata con `any()`, il
     * primo verbo che scrive, come in CoperturaCsrfTest).
     *
     * @var array<string, string>
     */
    private const ESENTI = [
        // ── Non scrivono niente ──
        'POST /admin/generate-hash' =>
            '`AdminController::generateHash` calcola l’hash bcrypt di una password e '
            . 'lo restituisce: nessun dato cambia, e non c’è un perché da registrare.',
        'POST /check/password' =>
            'Dichiarata con `any()`. `CheckController::password` confronta una password '
            . 'con quella dell’amministratore e risponde «correct» o «incorrect»: non scrive.',
        'POST /drafts/{path*}' => self::MOTIVO_LEGACY,
        'POST /risdoc/{path*}' => self::MOTIVO_LEGACY,
        'POST /strcomp_bes_altro/{path*}' => self::MOTIVO_LEGACY,
        'POST /verifiche/{path*}' => self::MOTIVO_LEGACY,

        // ── Toccano solo i temporanei ──
        'POST /admin/print' =>
            '`AdminPrintController::generate` compila una verifica in un .tex che finisce '
            . 'in storage/temp e torna al chiamante: nessun registro né materiale cambia. '
            . 'La lettura del materiale altrui è una lettura, non una mutazione.',
        'POST /admin/print/batch' =>
            'Come /admin/print, per le tre varianti in un archivio .zip costruito in memoria.',
        'POST /files/save-latex' =>
            '`FileController::saveLatex` scrive un .tex di lavoro in verifiche/temp, la '
            . 'radice dei temporanei (lo carica poi il salvataggio verso Drive): non tocca '
            . 'materiali né dati di persone.',
        'POST /files/clear-temp' =>
            '`FileController::clearTemp` svuota le due radici dei temporanei (temp e '
            . 'verifiche/temp): manutenzione, nessun dato di persone.',
        'POST /delete_temp.php' =>
            'Dichiarata con `any()`: è la stessa pulizia dei temporanei, per i cron. '
            . '`CronController::deleteTemp` risponde solo da CLI o da localhost, quindi '
            . 'non la chiama una persona dal pannello a cui chiedere il perché.',
    ];

    private const MOTIVO_LEGACY =
        'Percorso vecchio: `LegacyGoneMiddleware` risponde 410/302 senza mai invocare '
        . 'l’handler, che è una chiusura che non fa niente. Non c’è niente da motivare.';

    /**
     * Le mutazioni amministrative senza `audit_reason` e senza esenzione, come
     * `METODO percorso [middleware]`, e quante rotte sono state guardate.
     *
     * @param array<string, string> $esenti
     * @return array{0: list<string>, 1: int}
     */
    public static function scoperte(Router $router, array $esenti = self::ESENTI): array
    {
        $fuori = [];
        $controllate = 0;
        foreach (self::mutazioniAmministrative($router) as $chiave => $middleware) {
            $controllate++;
            if (self::chiedeLaMotivazione($middleware) || isset($esenti[$chiave])) {
                continue;
            }
            $fuori[] = $chiave . '   [' . implode(' ', $middleware) . ']';
        }
        sort($fuori);
        return [$fuori, $controllate];
    }

    /**
     * Le mutazioni amministrative, per chiave `METODO percorso`.
     *
     * @return array<string, list<string>> middleware di ciascuna
     */
    public static function mutazioniAmministrative(Router $router): array
    {
        $fuori = [];
        foreach ($router->routes() as $rotta) {
            $scrive = array_values(array_intersect($rotta->methods, self::SCRIVONO));
            if ($scrive === [] || !self::amministrativa($rotta->pattern, $rotta->middleware)) {
                continue;
            }
            /** @var list<string> $middleware */
            $middleware = array_values($rotta->middleware);
            $fuori[$scrive[0] . ' ' . $rotta->pattern] = $middleware;
        }
        return $fuori;
    }

    /** @param array<int, string> $middleware */
    public static function chiedeLaMotivazione(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if ($m === 'audit_reason' || str_starts_with($m, 'audit_reason:')) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int, string> $middleware */
    private static function amministrativa(string $percorso, array $middleware): bool
    {
        if ($percorso === '/admin' || str_starts_with($percorso, '/admin/') || str_starts_with($percorso, '/api/admin/')) {
            return true;
        }
        if (\in_array('super_admin_required', $middleware, true)) {
            return true;
        }
        $zone = self::zone($middleware);
        return $zone !== [] && array_diff($zone, ['admin']) === [];
    }

    /**
     * Le zone dei middleware di ruolo di una rotta (`role:admin` → admin).
     *
     * @param array<int, string> $middleware
     * @return list<string>
     */
    private static function zone(array $middleware): array
    {
        $zone = [];
        foreach ($middleware as $m) {
            if (str_starts_with($m, 'role:')) {
                foreach (explode(',', substr($m, 5)) as $z) {
                    if ($z !== '') {
                        $zone[] = $z;
                    }
                }
            }
        }
        return array_values(array_unique($zone));
    }

    private function rotteDelSito(): Router
    {
        $router = new Router();
        require \dirname(__DIR__, 3) . '/routes/web.php';
        return $router;
    }

    #[Test]
    public function ogni_mutazione_amministrativa_chiede_la_motivazione(): void
    {
        [$scoperte, $controllate] = self::scoperte($this->rotteDelSito());

        // Una regola che non guarda niente dice sempre di sì: le mutazioni
        // amministrative erano 98 il 23/9/2026 (81 sotto /admin e /api/admin).
        self::assertGreaterThan(90, $controllate, 'le rotte controllate sono quelle dell’amministratore, non zero');
        self::assertSame([], $scoperte, sprintf(
            "%d mutazioni amministrative non chiedono la motivazione (ADR-008).\n\n"
            . "Aggiungi `audit_reason:<azione>,<risorsa>` alla rotta in routes/web.php, e fai "
            . "mandare X-Audit-Reason (o il campo _audit_reason) al suo chiamante; se la rotta "
            . "non modifica niente, mettila in ESENTI con il perché.\n\nScoperte:\n  %s",
            \count($scoperte),
            implode("\n  ", $scoperte)
        ));
    }

    #[Test]
    public function le_esenzioni_possono_solo_diminuire(): void
    {
        $mutazioni = self::mutazioniAmministrative($this->rotteDelSito());

        $sparite = [];
        $coperte = [];
        foreach (array_keys(self::ESENTI) as $chiave) {
            if (!isset($mutazioni[$chiave])) {
                $sparite[] = $chiave;
            } elseif (self::chiedeLaMotivazione($mutazioni[$chiave])) {
                $coperte[] = $chiave;
            }
        }

        self::assertSame([], $sparite, "Esenzioni che non corrispondono più a una mutazione "
            . "amministrativa: toglile da ESENTI.\n  " . implode("\n  ", $sparite));
        self::assertSame([], $coperte, "Esenzioni la cui rotta chiede ormai la motivazione: "
            . "toglile da ESENTI.\n  " . implode("\n  ", $coperte));
    }

    /**
     * I parametri del middleware finiscono in `privileged_access_log.action` e
     * `resource_type` (VARCHAR(64)): due parole, non una frase, e mai vuote.
     */
    #[Test]
    public function azione_e_risorsa_sono_due_nomi_brevi(): void
    {
        $storti = [];
        foreach (self::mutazioniAmministrative($this->rotteDelSito()) as $chiave => $middleware) {
            foreach ($middleware as $m) {
                if (!str_starts_with($m, 'audit_reason:')) {
                    continue;
                }
                $parti = explode(',', substr($m, \strlen('audit_reason:')));
                $buone = \count($parti) === 2
                    && preg_match('/^[a-z][a-z0-9_]{2,63}$/', $parti[0]) === 1
                    && preg_match('/^[a-z][a-z0-9_]{2,63}$/', $parti[1]) === 1;
                if (!$buone) {
                    $storti[] = $chiave . ' → ' . $m;
                }
            }
        }

        self::assertSame([], $storti, "`audit_reason:<azione>,<risorsa>`, in minuscolo con il trattino basso:\n  "
            . implode("\n  ", $storti));
    }

    /**
     * La zona dell'amministratore di istituto non ha mutazioni. Quando ne
     * avrà una, la motivazione non gliela chiederebbe nessuno:
     * `RequiresAuditReasonMiddleware` salta chi non è super-admin. Prima si
     * decide se ADR-008 vale anche per lui (e si estende il middleware), poi
     * si aggiorna questa prova.
     */
    #[Test]
    public function la_zona_istituto_non_ha_mutazioni_senza_una_decisione(): void
    {
        $trovate = [];
        foreach ($this->rotteDelSito()->routes() as $rotta) {
            $scrive = array_values(array_intersect($rotta->methods, self::SCRIVONO));
            if ($scrive !== [] && \in_array('istituto', self::zone($rotta->middleware), true)) {
                $trovate[] = $scrive[0] . ' ' . $rotta->pattern;
            }
        }

        self::assertSame([], $trovate, "Mutazioni nella zona `istituto`: decidi se ADR-008 vale per "
            . "l'amministratore di istituto (RequiresAuditReasonMiddleware oggi lo salta).\n  "
            . implode("\n  ", $trovate));
    }

    /**
     * La controprova, su un Router finto: la regola si accorge di una rotta
     * scoperta in ciascuno dei tre modi di essere amministrativa, e lascia
     * stare quelle che non lo sono o che la motivazione la chiedono.
     */
    #[Test]
    public function la_regola_si_accorge_di_una_mutazione_scoperta(): void
    {
        $noop = static fn(): string => '';
        $router = new Router();
        $router->group(['middleware' => ['auth', 'role:admin', 'log']], function (Router $r) use ($noop) {
            $r->post('/api/admin/finta', $noop)->middleware('csrf');
            $r->post('/api/admin/finta-coperta', $noop)->middleware('csrf', 'audit_reason:finta_azione,finta');
            $r->post('/finta-solo-admin', $noop)->middleware('csrf');
            $r->get('/admin/finta-lettura', $noop);
            $r->any('/finta-esente', $noop)->middleware('csrf');
        });
        $router->group(['middleware' => ['auth', 'role:teacher', 'log']], function (Router $r) use ($noop) {
            $r->post('/api/teacher/finta', $noop)->middleware('csrf');
            $r->post('/finta-super', $noop)->middleware('super_admin_required', 'csrf');
        });

        [$scoperte, $controllate] = self::scoperte($router, ['POST /finta-esente' => 'prova']);

        self::assertSame(5, $controllate, 'tre scoperte, una coperta, una esente; lettura e docente fuori');
        self::assertSame([
            'POST /api/admin/finta   [auth role:admin log csrf]',
            'POST /finta-solo-admin   [auth role:admin log csrf]',
            'POST /finta-super   [auth role:teacher log super_admin_required csrf]',
        ], $scoperte);
    }
}
