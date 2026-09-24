<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit;

use App\Core\AccessLogger;
use App\Core\Config;
use App\Core\Database;
use App\Services\Audit\ActivityLogger;
use App\Services\Audit\PercorsoSenzaGettoni;
use App\Services\Waf\WafLogService;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\RottaVera;

/**
 * I registri scrivono il percorso senza query string e senza gettoni
 * (23/9/2026).
 *
 * `audit_activity_log` conservava per due anni l'URI intero. Il caso concreto
 * della revisione: il gettone permanente del QR di classe
 * (`/accesso-classe/qr/{token}`), registrato ogni volta che la rotta risponde
 * 429 dietro il NAT della scuola. E ogni conferma del consenso del genitore,
 * una POST, lasciava il suo `/parent-consent/{token}`; nella query viaggiano
 * i gettoni del ripristino della password, delle conferme e degli indirizzi
 * firmati (revisione architetturale 2026-09, A-81). La stessa regola vale per
 * `access_log.json`, dove arrivano REQUEST_URI e il `redirect` del login.
 *
 * Provato nei due versi: i gettoni e la query spariscono, i percorsi normali
 * restano interi. E sulla tabella delle rotte vera: ogni rotta con un gettone
 * nel percorso è mascherata, così una rotta nuova non entra dimenticata.
 */
final class PercorsiSenzaGettoniTest extends TestCase
{
    /** I nomi di parametro che, in una rotta, sono un gettone. */
    private const PARAMETRI_GETTONE = ['token', 'gettone', 'secret', 'segreto', 'sig', 'signature', 'firma', 'code', 'codice', 'hash'];

    private const GETTONE = 'GettoneDiProva0123456789abcdef';

    private ?PDO $pdo = null;
    private ?bool $dbAbilitatoPrima = null;
    private string $cartella = '';

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            Database::reset();
            Config::set('database.enabled', $this->dbAbilitatoPrima ?? true);
            $this->pdo = null;
        }
        if ($this->cartella !== '') {
            foreach (glob($this->cartella . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->cartella);
        }
    }

    #[Test]
    public function il_registro_delle_operazioni_non_conserva_gettoni_ne_query(): void
    {
        $this->databaseInMemoria();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';

        // Il caso della revisione: il QR di classe respinto dal limitatore.
        ActivityLogger::request('GET', '/accesso-classe/qr/' . self::GETTONE . '?from=bacheca', 429);
        // Un pacchetto di classe: più codici separati da virgola.
        ActivityLogger::request('GET', '/accesso-classe/qr/uno' . self::GETTONE . ',due' . self::GETTONE, 429);
        // La conferma del consenso del genitore, una POST: si registra sempre.
        ActivityLogger::request('POST', '/parent-consent/' . self::GETTONE, 200);
        // Un gettone nella query.
        ActivityLogger::request('GET', '/me/confirm-deletion?token=' . self::GETTONE, 403);
        // Un evento di dominio prende il percorso da REQUEST_URI.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/parent-consent/' . self::GETTONE . '?esito=conferma';
        ActivityLogger::event('parent_consent_granted', subjectType: 'user', subjectId: '42');

        $percorsi = $this->pdo?->query('SELECT path FROM audit_activity_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([
            '/accesso-classe/qr/<omesso>',
            '/accesso-classe/qr/<omesso>',
            '/parent-consent/<omesso>',
            '/me/confirm-deletion',
            '/parent-consent/<omesso>',
        ], $percorsi);
    }

    #[Test]
    public function i_percorsi_normali_restano_interi(): void
    {
        // Il verso che di solito non si prova: una maschera troppo larga
        // renderebbe il registro cieco senza che nessuno se ne accorga.
        foreach ([
            '/api/teacher/content/9/delete',
            '/accesso-classe',
            '/accesso-classe/esci',
            '/accesso-classe/pacchetto.svg',
            '/api/access/student-login',
            '/public/sidebar/SCI-1S',
            '/api/risdoc/templates/16/instances/abc/rename',
            '/parent-consent',
        ] as $percorso) {
            self::assertSame($percorso, PercorsoSenzaGettoni::perIlRegistro($percorso));
        }
        // Della query si toglie la query, e basta.
        self::assertSame('/admin/waf/config', PercorsoSenzaGettoni::perIlRegistro('/admin/waf/config?giorno=2026-09-22'));
        self::assertSame('', PercorsoSenzaGettoni::perIlRegistro(''));
    }

    #[Test]
    public function il_registro_del_waf_usa_lo_stesso_elenco_e_tiene_la_query(): void
    {
        // In waf_logs la query serve alla diagnosi e resta; il gettone no.
        // Da oggi anche quello del consenso del genitore.
        self::assertSame(
            '/parent-consent/<omesso>?esito=conferma',
            WafLogService::senzaSegreti('/parent-consent/' . self::GETTONE . '?esito=conferma'),
        );
        self::assertSame(
            'https://esempio.test/accesso-classe/qr/<omesso>',
            WafLogService::senzaSegreti('https://esempio.test/accesso-classe/qr/' . self::GETTONE),
            'anche in un indirizzo completo, come il referer',
        );
    }

    #[Test]
    public function la_maschera_non_guarda_le_maiuscole_del_prefisso(): void
    {
        // Il router distingue le maiuscole, e `/Parent-Consent/{token}` non
        // arriva alla rotta: risponde 404. Ma una POST si registra sempre, 404
        // compreso, e il gettone dentro è quello vero. La maschera protegge il
        // registro, non la rotta: vale qualunque sia la forma del prefisso.
        $this->databaseInMemoria();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        ActivityLogger::request('POST', '/Parent-Consent/' . self::GETTONE, 404);

        $percorsi = $this->pdo?->query('SELECT path FROM audit_activity_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['/Parent-Consent/<omesso>'], $percorsi);
        self::assertSame(
            '/ACCESSO-CLASSE/QR/<omesso>?from=bacheca',
            WafLogService::senzaSegreti('/ACCESSO-CLASSE/QR/' . self::GETTONE . '?from=bacheca'),
            'e così nel registro del WAF',
        );
    }

    #[Test]
    public function il_registro_degli_accessi_non_conserva_gettoni_ne_query(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-percorsi-gettoni-' . bin2hex(random_bytes(4));
        $registro = new AccessLogger($this->cartella);
        $registro->logAccess('zz_docente', 'teacher', '/me/confirm-deletion?token=' . self::GETTONE, 'access');
        $registro->logAccess('zz_docente', 'teacher', '/accesso-classe/qr/' . self::GETTONE, 'login_failed:x');
        $registro->logAccess('zz_docente', 'teacher', '/eser/sci/eser_sci1s/', 'login');

        $grezzo = (string)file_get_contents($this->cartella . '/access_log.json');
        self::assertStringNotContainsString(self::GETTONE, $grezzo);
        $voci = json_decode($grezzo, true);
        self::assertIsArray($voci);
        self::assertSame(
            ['/me/confirm-deletion', '/accesso-classe/qr/<omesso>', '/eser/sci/eser_sci1s/'],
            array_column($voci, 'linkref'),
        );
        self::assertSame('sci', $voci[2]['institute_code'], 'la sezione si ricava ancora dal percorso');
    }

    #[Test]
    public function ogni_rotta_con_un_gettone_nel_percorso_e_mascherata(): void
    {
        $rotte = self::rotteConGettone(array_map(
            static fn($r) => $r->pattern,
            RottaVera::rotte()->routes(),
        ));

        // Non una prova vuota: oggi sono queste, e ci devono essere.
        self::assertContains('/accesso-classe/qr/{token}', $rotte);
        self::assertContains('/parent-consent/{token}', $rotte);

        foreach ($rotte as $rotta) {
            $esempio = self::esempio($rotta);
            self::assertStringNotContainsString(self::GETTONE, PercorsoSenzaGettoni::perIlRegistro($esempio),
                "{$rotta} porta un gettone nel percorso: va in PercorsoSenzaGettoni::PREFISSI_CON_GETTONE");
        }
    }

    #[Test]
    public function la_guardia_delle_rotte_scatta_su_una_rotta_nuova(): void
    {
        // Controprova della prova sopra: una rotta con un gettone che
        // l'elenco non conosce si riconosce, e il suo gettone passerebbe.
        $rotte = self::rotteConGettone(['/invito/{token}/accetta', '/api/teacher/content/{id}', '/public/sidebar/{key}']);
        self::assertSame(['/invito/{token}/accetta'], $rotte);
        self::assertStringContainsString(self::GETTONE, PercorsoSenzaGettoni::perIlRegistro(self::esempio($rotte[0])));
    }

    /**
     * @param list<string> $modelli
     * @return list<string>
     */
    private static function rotteConGettone(array $modelli): array
    {
        $trovate = [];
        foreach ($modelli as $modello) {
            preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)[?*]?\}#', $modello, $m);
            if (array_intersect(array_map('strtolower', $m[1]), self::PARAMETRI_GETTONE) !== []) {
                $trovate[] = $modello;
            }
        }
        return array_values(array_unique($trovate));
    }

    /** Il percorso di una rotta con il gettone di prova al posto dei parametri-gettone, e 42 negli altri. */
    private static function esempio(string $modello): string
    {
        return (string)preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)[?*]?\}#',
            static fn(array $m) => \in_array(strtolower($m[1]), self::PARAMETRI_GETTONE, true) ? self::GETTONE : '42',
            $modello,
        );
    }

    private function databaseInMemoria(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::fail('pdo_sqlite non disponibile: la prova non guarderebbe niente');
        }
        $this->dbAbilitatoPrima = (bool)Config::get('database.enabled');
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Le colonne di database/migrations/098 che ActivityLogger scrive.
        $this->pdo->exec(
            'CREATE TABLE audit_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                occurred_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actor_user_id INTEGER, actor_name TEXT, actor_role TEXT,
                action TEXT, method TEXT, path TEXT, status INTEGER, outcome TEXT,
                subject_type TEXT, subject_id TEXT, details_json TEXT,
                ip_hash BLOB, ua_hash BLOB, request_id TEXT
            )'
        );
        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->pdo);
        Config::set('database.enabled', true);
    }
}
