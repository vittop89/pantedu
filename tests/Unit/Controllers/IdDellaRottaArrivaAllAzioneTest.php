<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Response;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\RottaVera;

/**
 * L'id nel percorso arriva all'azione: pannello WAF e catalogo GeoGebra
 * (23/9/2026, revisione architetturale, A-5).
 *
 * Le cinque azioni del WAF con `{id}` nel percorso e GeoGebraCatalogController::get
 * leggevano l'id da `$req->params`, che Request non ha. Misurato quel giorno:
 * «Disattiva» rispondeva 404 a ogni regola, «Modifica» 400, le tre
 * eliminazioni `{ok:true}` senza cancellare niente, «Apri» del catalogo 400.
 *
 * Qui la richiesta passa dalla strada vera (tests/Support/RottaVera.php): la
 * tabella delle rotte di routes/web.php, Router::match che estrae l'id,
 * Kernel::invoke che chiama l'azione. I middleware del gruppo (porta del
 * super-amministratore, CSRF, motivazione) non c'entrano col difetto e restano
 * fuori; la sessione è già quella di chi passa la porta. La stessa strada su
 * MariaDB, dalla creazione all'eliminazione: tests/Integration/
 * PannelloWafRegoleEListeTest.php. Il database è SQLite in memoria con le tre
 * tabelle del WAF, e ogni prova guarda anche le righe che NON dovevano
 * cambiare: un'azione che toccasse sempre la prima riga passerebbe altrimenti.
 */
final class IdDellaRottaArrivaAllAzioneTest extends TestCase
{
    private const DOCENTE = 7;
    /** Due voci del catalogo GeoGebra, con id nella forma ULID del servizio. */
    private const PRIMA = '01J0000000000000000000000A';
    private const SECONDA = '01J0000000000000000000000B';

    private ?PDO $pdo = null;
    private ?PDO $pdoPrima = null;
    private mixed $dbPrima = null;
    private mixed $datiPrima = null;
    private string $cartella = '';

    protected function setUp(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Le colonne che WafRulesService legge e scrive (migrazione 048).
        $this->pdo->exec(
            'CREATE TABLE waf_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE,
                description TEXT, enabled INTEGER NOT NULL DEFAULT 1,
                priority INTEGER NOT NULL DEFAULT 100, conditions TEXT NOT NULL,
                action TEXT NOT NULL, match_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP, created_by INTEGER
            );
            CREATE TABLE waf_blocked_ips (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ip_or_cidr TEXT NOT NULL,
                reason TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT, created_by INTEGER
            );
            CREATE TABLE waf_whitelisted_ips (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ip_or_cidr TEXT NOT NULL,
                reason TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT, created_by INTEGER
            );'
        );
        // Due righe per tabella: l'azione deve toccare la seconda, non la
        // prima. Indirizzi dei blocchi riservati alla documentazione.
        $cond = '{"logic":"AND","conditions":[{"field":"url","operator":"contains","value":"/finta"}]}';
        $regola = $this->pdo->prepare(
            'INSERT INTO waf_rules (id, name, enabled, conditions, action) VALUES (?, ?, 1, ?, ?)'
        );
        $regola->execute([1, 'prima', $cond, 'block']);
        $regola->execute([2, 'seconda', $cond, 'block']);
        foreach (['waf_blocked_ips', 'waf_whitelisted_ips'] as $tabella) {
            $voce = $this->pdo->prepare("INSERT INTO {$tabella} (id, ip_or_cidr, reason) VALUES (?, ?, 'prova')");
            $voce->execute([1, '192.0.2.1']);
            $voce->execute([2, '2001:db8::2']);
        }

        $conn = new ReflectionProperty(Database::class, 'pdo');
        $prima = $conn->getValue();
        $this->pdoPrima = $prima instanceof PDO ? $prima : null;
        $conn->setValue(null, $this->pdo);
        $this->dbPrima = Config::get('database.enabled');
        Config::set('database.enabled', true);
        $this->datiPrima = Config::get('app.paths.data_base');

        $_SESSION = [
            'autenticato'    => true,
            'username'       => 'zz_super_admin_prova',
            'user_id'        => 0,
            'user_role'      => 'admin',
            'is_super_admin' => true,
            // Claims appena letti (Auth::CLAIMS_TTL_SECONDS, 23/9/2026).
            'claims_at'      => time(),
        ];
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->pdoPrima);
        $this->pdo = null;
        Config::set('database.enabled', $this->dbPrima);
        Config::set('app.paths.data_base', $this->datiPrima);
        $_SESSION = [];
        if ($this->cartella !== '') {
            $this->cancella($this->cartella);
        }
    }

    #[Test]
    public function le_azioni_del_waf_agiscono_sull_id_del_percorso(): void
    {
        $esito = $this->chiama('POST', '/admin/waf/api/rules/2/toggle');
        $this->assertSame(200, $esito->status, (string)$esito->body);
        $this->assertSame(['ok' => true, 'enabled' => false], $this->json($esito));
        $this->assertSame([1 => 1, 2 => 0], $this->colonna('waf_rules', 'enabled'), 'si disattiva la seconda');

        $esito = $this->chiama('PUT', '/admin/waf/api/rules/2', ['name' => 'seconda rinominata']);
        $this->assertSame(200, $esito->status, (string)$esito->body);
        $this->assertSame(['ok' => true], $this->json($esito));
        $this->assertSame([1 => 'prima', 2 => 'seconda rinominata'], $this->colonna('waf_rules', 'name'));

        $esito = $this->chiama('DELETE', '/admin/waf/api/rules/2');
        $this->assertSame(['ok' => true], $this->json($esito));
        $this->assertSame([1], array_keys($this->colonna('waf_rules', 'name')), 'resta solo la prima regola');

        foreach (['blacklist' => 'waf_blocked_ips', 'whitelist' => 'waf_whitelisted_ips'] as $lista => $tabella) {
            $esito = $this->chiama('DELETE', "/admin/waf/api/{$lista}/2");
            $this->assertSame(200, $esito->status, "{$lista}: " . $esito->body);
            $this->assertSame(['ok' => true], $this->json($esito));
            $this->assertSame([1 => '192.0.2.1'], $this->colonna($tabella, 'ip_or_cidr'), "{$lista}: tolta la seconda");
        }
    }

    #[Test]
    public function un_id_che_non_c_e_risponde_404_e_non_tocca_niente(): void
    {
        foreach (
            [
                ['PUT', '/admin/waf/api/rules/99', ['name' => 'fantasma']],
                ['DELETE', '/admin/waf/api/rules/99', []],
                ['POST', '/admin/waf/api/rules/99/toggle', []],
                ['DELETE', '/admin/waf/api/blacklist/99', []],
                ['DELETE', '/admin/waf/api/whitelist/99', []],
            ] as [$metodo, $percorso, $campi]
        ) {
            $esito = $this->chiama($metodo, $percorso, $campi);
            $this->assertSame(404, $esito->status, "{$metodo} {$percorso}: " . $esito->body);
            $this->assertSame(['error' => 'not_found'], $this->json($esito), "{$metodo} {$percorso}");
        }
        $this->assertSame([1 => 'prima', 2 => 'seconda'], $this->colonna('waf_rules', 'name'));
        $this->assertSame([1 => 1, 2 => 1], $this->colonna('waf_rules', 'enabled'));
        $this->assertCount(2, $this->colonna('waf_blocked_ips', 'ip_or_cidr'));
        $this->assertCount(2, $this->colonna('waf_whitelisted_ips', 'ip_or_cidr'));
    }

    #[Test]
    public function un_id_che_non_e_un_intero_positivo_risponde_400(): void
    {
        // `{id}` accetta qualunque segmento: «abc» e «0» arrivano all'azione.
        foreach (['abc', '0', '2x'] as $id) {
            foreach (
                [
                    ['PUT', "/admin/waf/api/rules/{$id}"],
                    ['DELETE', "/admin/waf/api/rules/{$id}"],
                    ['POST', "/admin/waf/api/rules/{$id}/toggle"],
                    ['DELETE', "/admin/waf/api/blacklist/{$id}"],
                    ['DELETE', "/admin/waf/api/whitelist/{$id}"],
                ] as [$metodo, $percorso]
            ) {
                $esito = $this->chiama($metodo, $percorso, ['name' => 'fantasma']);
                $this->assertSame(400, $esito->status, "{$metodo} {$percorso}: " . $esito->body);
                $this->assertSame(['error' => 'invalid_id'], $this->json($esito), "{$metodo} {$percorso}");
            }
        }
        $this->assertSame([1 => 'prima', 2 => 'seconda'], $this->colonna('waf_rules', 'name'));
        $this->assertCount(2, $this->colonna('waf_blocked_ips', 'ip_or_cidr'));
        $this->assertCount(2, $this->colonna('waf_whitelisted_ips', 'ip_or_cidr'));
    }

    #[Test]
    public function il_catalogo_geogebra_apre_la_voce_del_percorso(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-geogebra-' . bin2hex(random_bytes(6));
        $dir = $this->cartella . '/storage/objects/teachers/' . self::DOCENTE;
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/geogebra-catalog.json', (string)json_encode(['items' => [
            ['id' => self::PRIMA, 'label' => 'Prima', 'ggb_b64' => 'UEsDA', 'svg_cached' => '', 'ts' => 1],
            ['id' => self::SECONDA, 'label' => 'Seconda', 'ggb_b64' => 'UEsDB', 'svg_cached' => '', 'ts' => 2],
        ]]));
        Config::set('app.paths.data_base', $this->cartella);
        $_SESSION = [
            'autenticato' => true,
            'username'    => 'docente.di.prova',
            'user_id'     => self::DOCENTE,
            'user_role'   => 'teacher',
        ];

        $esito = $this->chiama('GET', '/geogebra/catalog/' . self::SECONDA);
        $this->assertSame(200, $esito->status, (string)$esito->body);
        $corpo = $this->json($esito);
        $this->assertIsArray($corpo['item'] ?? null);
        $this->assertSame(self::SECONDA, $corpo['item']['id'] ?? null, 'la voce del percorso, non la prima');
        $this->assertSame('UEsDB', $corpo['item']['ggb_b64'] ?? null);

        $esito = $this->chiama('GET', '/geogebra/catalog/01J00000000000000000000ZZZ');
        $this->assertSame(404, $esito->status, (string)$esito->body);
        $this->assertSame(['ok' => false, 'error' => 'not_found'], $this->json($esito));
    }

    /** @param array<string, string> $campi i campi del modulo ($_POST) */
    private function chiama(string $metodo, string $percorso, array $campi = []): Response
    {
        return RottaVera::chiama($metodo, $percorso, $campi);
    }

    /** @return array<mixed> */
    private function json(Response $esito): array
    {
        $dati = json_decode($esito->body, true);
        $this->assertIsArray($dati, 'risposta JSON: ' . $esito->body);
        return $dati;
    }

    /** @return array<int, mixed> la colonna per id */
    private function colonna(string $tabella, string $colonna): array
    {
        $this->assertNotNull($this->pdo);
        $righe = $this->pdo->query("SELECT id, {$colonna} AS v FROM {$tabella} ORDER BY id");
        $this->assertNotFalse($righe);
        $fuori = [];
        foreach ($righe->fetchAll(PDO::FETCH_ASSOC) as $riga) {
            $fuori[(int)$riga['id']] = \is_numeric($riga['v']) && $colonna === 'enabled' ? (int)$riga['v'] : $riga['v'];
        }
        return $fuori;
    }

    private function cancella(string $percorso): void
    {
        if (is_dir($percorso)) {
            foreach ((array)scandir($percorso) as $voce) {
                if ($voce !== '.' && $voce !== '..' && \is_string($voce)) {
                    $this->cancella($percorso . '/' . $voce);
                }
            }
            @rmdir($percorso);
        } elseif (is_file($percorso)) {
            @unlink($percorso);
        }
    }
}
