<?php

declare(strict_types=1);

namespace Tests\Integration\Gdpr;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\SuperAdminAuditMiddleware;
use App\Services\Audit\RequestFingerprint;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use App\Services\Gdpr\Export\Exporters\AuditLogExporter;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * I registri di accesso nel bundle per l'autorità dicono il vero (A-62).
 *
 * ── Il difetto, misurato il 23/9/2026 ─────────────────────────────────────
 *
 * AuditLogExporter chiedeva a `privileged_access_log` e `crypto_access_log`
 * colonne che non esistono, e ingoiava l'errore: il bundle firmato per
 * l'autorità riportava i due registri con zero righe anche quando erano
 * pieni. Il filtro sugli accessi privilegiati, poi, cercava una forma
 * (`resource_type = 'user'`, `resource_id` = id) che nessuno scrittore usa.
 *
 * ── Come si prova ─────────────────────────────────────────────────────────
 *
 * Le righe le scrivono gli scrittori veri — SuperAdminAuditMiddleware (quindi
 * PrivilegedAccessLogger) su una rotta che nomina un docente, e
 * TeacherCryptoService — per un soggetto, un altro docente e un
 * super-amministratore creati apposta. Poi si esporta ciascuno dei tre, e
 * ognuno deve avere le proprie righe e non quelle degli altri.
 *
 * Nell'altro verso: un registro che non si legge (colonne sbagliate, come
 * prima) deve comparire in `errors`, con conteggio null e senza file, non
 * come zero. Lo si ottiene con una tabella TEMPORANEA con lo stesso nome, che
 * per questa connessione copre quella vera senza toccarla: niente DDL sulle
 * tabelle condivise (il database è in comune con altre sessioni), e in
 * MariaDB CREATE/DROP TEMPORARY TABLE non fanno commit implicita (misurato il
 * 23/9 su 10.11: riga inserita prima, sparita dopo il rollback).
 *
 * ── Perché in transazione ─────────────────────────────────────────────────
 *
 * I due registri sono append-only: il trigger lascia cancellare solo
 * all'utente di manutenzione, e l'invariante non si indebolisce per una
 * prova. Scrittori ed exporter usano la stessa connessione
 * (Database::connection()), quindi tutto sta in una transazione annullata
 * alla fine, come RegistroDegliIncidentiImmutabileTest.
 */
final class RegistriPerLAutoritaTest extends TestCase
{
    private PDO $pdo;
    private string $marca = '';
    private int $soggetto = 0;
    private int $altro = 0;
    private int $amministratore = 0;

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 3);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        // Utenti appena creati: nessun dato cifrato da proteggere.
        Config::set('crypto.allow_regenerate', false);
        if (!Database::isAvailable()) {
            $this->markTestSkipped('DB non disponibile');
        }
        $this->pdo = Database::connection();
        $this->pdo->beginTransaction();

        $this->marca = 'zzaut' . bin2hex(random_bytes(3));
        $this->soggetto       = $this->utente($this->marca . 'sog', 'teacher', 0);
        $this->altro          = $this->utente($this->marca . 'alt', 'teacher', 0);
        $this->amministratore = $this->utente($this->marca . 'adm', 'administrator', 1);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        // Le tabelle temporanee non seguono il rollback e la connessione resta
        // aperta per le prove seguenti: vanno tolte a mano. TEMPORARY non
        // tocca mai la tabella vera.
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS privileged_access_log');
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS crypto_access_log');
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function ciascun_soggetto_ha_le_sue_righe_e_non_quelle_degli_altri(): void
    {
        $scritte = $this->scriviRighe();

        // Il docente: la lettura che lo nomina (bersaglio), le operazioni
        // sulla sua chiave, sue e dell'amministratore.
        $sezione = $this->esporta($this->soggetto);
        $sommario = $sezione->summary;
        self::assertSame(1, $sommario['privileged_access_count']);
        self::assertSame(3, $sommario['crypto_access_count']);
        self::assertSame(1, $sommario['privileged_access_total'] ?? null);
        self::assertSame(3, $sommario['crypto_access_total'] ?? null);
        self::assertSame([], $sommario['errors'] ?? 'manca «errors» nel riepilogo');

        $priv = $this->righe($sezione, 'privileged_access_log');
        self::assertSame([$scritte['lettura_soggetto']], array_column($priv, 'id'));
        self::assertSame('target', $priv[0]['relation']);
        self::assertSame($this->amministratore, $priv[0]['user_id']);
        self::assertSame('/api/admin/analytics/teacher/' . $this->soggetto, $priv[0]['resource_id']);
        self::assertSame('super_admin', $priv[0]['actor_role']);
        // Gli hash escono in esadecimale: in binario json_encode fallirebbe e
        // il file resterebbe vuoto.
        self::assertSame(strtoupper(bin2hex((string)RequestFingerprint::ipHash())), $priv[0]['ip_hash']);

        $crypto = $this->righe($sezione, 'crypto_access_log');
        self::assertEqualsCanonicalizing($scritte['crypto_soggetto'], array_column($crypto, 'id'));
        self::assertSame([$this->soggetto], array_values(array_unique(array_column($crypto, 'teacher_id'))));
        $decifrata = $this->riga($crypto, $scritte['decifrata_da_amministratore']);
        self::assertSame('target', $decifrata['relation']);
        self::assertSame($this->amministratore, $decifrata['accessor_id']);
        self::assertSame('decrypt', $decifrata['operation']);
        self::assertSame('decreto di prova', $decifrata['reason']);
        foreach ($crypto as $r) {
            if ($r['id'] !== $scritte['decifrata_da_amministratore']) {
                self::assertSame('actor+target', $r['relation'], 'operazione del docente sulla propria chiave');
            }
        }

        // L'amministratore: le tre letture che ha fatto (attore) e la
        // decifratura della chiave altrui, niente delle operazioni dei docenti.
        $sezione = $this->esporta($this->amministratore);
        self::assertSame([], $sezione->summary['errors'] ?? 'manca «errors» nel riepilogo');
        $priv = $this->righe($sezione, 'privileged_access_log');
        self::assertEqualsCanonicalizing(
            [$scritte['lettura_soggetto'], $scritte['lettura_altro'], $scritte['lettura_id_simile']],
            array_column($priv, 'id'),
        );
        self::assertSame(['actor'], array_values(array_unique(array_column($priv, 'relation'))));
        $crypto = $this->righe($sezione, 'crypto_access_log');
        self::assertSame([$scritte['decifrata_da_amministratore']], array_column($crypto, 'id'));
        self::assertSame('actor', $crypto[0]['relation']);

        // L'altro docente: la sua lettura e la sua chiave, niente del soggetto.
        // La lettura di `/teacher/<id del soggetto>0` non è di nessuno dei due.
        $sezione = $this->esporta($this->altro);
        $priv = $this->righe($sezione, 'privileged_access_log');
        self::assertSame([$scritte['lettura_altro']], array_column($priv, 'id'));
        $crypto = $this->righe($sezione, 'crypto_access_log');
        self::assertEqualsCanonicalizing($scritte['crypto_altro'], array_column($crypto, 'id'));
        self::assertSame([$this->altro], array_values(array_unique(array_column($crypto, 'teacher_id'))));
    }

    #[Test]
    public function col_tetto_di_righe_il_riepilogo_dice_anche_il_totale(): void
    {
        $scritte = $this->scriviRighe();

        $sezione = $this->esporta($this->soggetto, 2);
        self::assertSame(2, $sezione->summary['crypto_access_count']);
        self::assertSame(3, $sezione->summary['crypto_access_total'] ?? null);
        self::assertSame(2, $sezione->summary['criteria']['limit'] ?? null);
        self::assertCount(2, $this->righe($sezione, 'crypto_access_log'));
        // Dalle più recenti: la decifratura è l'ultima scritta.
        self::assertSame($scritte['decifrata_da_amministratore'], $this->righe($sezione, 'crypto_access_log')[0]['id']);
        self::assertSame(1, $sezione->summary['privileged_access_count']);
        self::assertSame(1, $sezione->summary['privileged_access_total'] ?? null);
    }

    #[Test]
    public function un_registro_che_non_si_legge_si_dichiara_invece_di_contare_zero(): void
    {
        $this->scriviRighe();

        // Le stesse tabelle con le colonne che mancano, come chiedeva la
        // versione di prima: coprono quelle vere per questa connessione.
        $this->pdo->exec('CREATE TEMPORARY TABLE privileged_access_log (id BIGINT, accessed_at DATETIME)');
        $this->pdo->exec('CREATE TEMPORARY TABLE crypto_access_log (id INT, occurred_at DATETIME)');

        // Il guasto si vede anche nel log del server, non solo nel bundle.
        $logServer = (string)tempnam(sys_get_temp_dir(), 'pantedu-a62-');
        $logPrima = ini_set('error_log', $logServer);
        try {
            $sezione = $this->esporta($this->soggetto);
            $scrittoNelLog = (string)file_get_contents($logServer);
        } finally {
            ini_set('error_log', $logPrima === false ? '' : $logPrima);
            @unlink($logServer);
        }
        self::assertStringContainsString('privileged_access_log non letto', $scrittoNelLog);
        self::assertStringContainsString('crypto_access_log non letto', $scrittoNelLog);
        $sommario = $sezione->summary;
        self::assertCount(2, $sommario['errors'] ?? [], 'i due registri illeggibili vanno in «errors»');
        self::assertStringStartsWith('privileged_access_log: ', $sommario['errors'][0]);
        self::assertStringStartsWith('crypto_access_log: ', $sommario['errors'][1]);
        $chiavi = ['privileged_access_count', 'privileged_access_total', 'crypto_access_count', 'crypto_access_total'];
        foreach ($chiavi as $chiave) {
            self::assertArrayHasKey($chiave, $sommario);
            self::assertNull($sommario[$chiave], "$chiave: non letto non vuol dire zero");
        }
        $percorsi = array_map(static fn($f) => $f->relativePath, $sezione->files);
        self::assertNotContains('audit/privileged_access_log.json', $percorsi);
        self::assertNotContains('audit/crypto_access_log.json', $percorsi);
        // Il registro che si legge esce lo stesso: un guasto non ne spegne un altro.
        self::assertContains('audit/crypto_custody_events.json', $percorsi);
        self::assertSame(0, $sommario['custody_events_count']);

        // Controprova: tolte le coperture, gli stessi registri si leggono.
        $this->pdo->exec('DROP TEMPORARY TABLE privileged_access_log');
        $this->pdo->exec('DROP TEMPORARY TABLE crypto_access_log');
        $sezione = $this->esporta($this->soggetto);
        self::assertSame([], $sezione->summary['errors'] ?? 'manca «errors» nel riepilogo');
        self::assertSame(1, $sezione->summary['privileged_access_count']);
        self::assertSame(3, $sezione->summary['crypto_access_count']);
    }

    // ── Aiuti ─────────────────────────────────────────────────────────────

    /**
     * Le righe, scritte dagli scrittori veri. Ne restituisce gli id.
     *
     * @return array{lettura_soggetto:int, lettura_altro:int, lettura_id_simile:int,
     *               crypto_soggetto:list<int>, decifrata_da_amministratore:int, crypto_altro:list<int>}
     */
    private function scriviRighe(): array
    {
        // Il super-amministratore apre le statistiche di tre docenti: il
        // soggetto, l'altro, e un id che comincia con le cifre del soggetto.
        $_SESSION = [
            'autenticato' => true, 'username' => $this->marca . 'adm', 'user_id' => $this->amministratore,
            'user_role' => 'administrator', 'is_super_admin' => true,
        ];
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10'; // RFC 5737, indirizzo di documentazione
        $_SERVER['HTTP_USER_AGENT'] = 'prova-esportazione-autorita';
        $prima = $this->ultimoId('privileged_access_log');
        foreach ([$this->soggetto, $this->altro, $this->soggetto . '0'] as $docente) {
            $this->leggiStatistiche((string)$docente);
        }
        $letture = $this->idDopo('privileged_access_log', $prima, 'user_id', [$this->amministratore]);
        self::assertCount(3, $letture, 'il middleware scrive una riga per lettura');

        // Chiavi e operazioni: il soggetto cifra (wrap + encrypt), il
        // super-amministratore decifra un suo contenuto con un motivo,
        // l'altro docente cifra il suo.
        $crypto = new TeacherCryptoService(bin2hex(random_bytes(32)));
        $prima = $this->ultimoId('crypto_access_log');
        $busta = $crypto->encrypt($this->soggetto, 'contenuto del soggetto');
        $proprie = $this->idDopo('crypto_access_log', $prima, 'teacher_id', [$this->soggetto]);
        $crypto->decrypt($this->soggetto, $busta, $this->amministratore, 'decreto di prova');
        $decifrata = $this->idDopo('crypto_access_log', max($proprie), 'accessor_id', [$this->amministratore]);
        $crypto->encrypt($this->altro, 'contenuto dell altro');
        $altro = $this->idDopo('crypto_access_log', max($decifrata), 'teacher_id', [$this->altro]);
        self::assertCount(2, $proprie, 'wrap + encrypt');
        self::assertCount(1, $decifrata);
        self::assertCount(2, $altro, 'wrap + encrypt');

        return [
            'lettura_soggetto'            => $letture[0],
            'lettura_altro'               => $letture[1],
            'lettura_id_simile'           => $letture[2],
            'crypto_soggetto'             => [...$proprie, $decifrata[0]],
            'decifrata_da_amministratore' => $decifrata[0],
            'crypto_altro'                => $altro,
        ];
    }

    private function leggiStatistiche(string $docente): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/admin/analytics/teacher/' . $docente;
        (new SuperAdminAuditMiddleware())->handle(
            new Request(),
            static fn(Request $r): Response => Response::json(['ok' => true]),
            'admin_read',
            'cross_teacher_analytics',
        );
    }

    private function esporta(int $utente, ?int $limite = null): ExportSection
    {
        $exporter = $limite === null ? new AuditLogExporter() : new AuditLogExporter($limite);
        return $exporter->export(new ExportContext(
            userId: $utente,
            scope: ExportContext::SCOPE_AUTHORITY,
            requestorId: $this->amministratore,
            reason: 'prova A-62',
        ));
    }

    /** @return list<array<string,mixed>> il file JSON del registro, come lo riceve l'autorità */
    private function righe(ExportSection $sezione, string $registro): array
    {
        foreach ($sezione->files as $f) {
            if ($f->relativePath === "audit/$registro.json") {
                $righe = json_decode($f->content, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($righe);
                return $righe;
            }
        }
        self::fail("manca audit/$registro.json nel bundle");
    }

    /**
     * @param list<array<string,mixed>> $righe
     * @return array<string,mixed>
     */
    private function riga(array $righe, int $id): array
    {
        foreach ($righe as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        self::fail("manca la riga $id");
    }

    private function utente(string $nome, string $ruolo, int $super): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, is_super_admin, created_at)
             VALUES (?, ?, "Zz", "Autorita", ?, "x", "approved", 1, ?, NOW())'
        )->execute([$nome, $ruolo, "$nome@example.invalid", $super]);
        return (int)$this->pdo->lastInsertId();
    }

    private function ultimoId(string $tabella): int
    {
        return (int)$this->pdo->query("SELECT COALESCE(MAX(id), 0) FROM $tabella")->fetchColumn();
    }

    /**
     * Gli id scritti dopo $id per gli utenti di questa prova: il database è
     * condiviso, e un'altra sessione può scrivere negli stessi registri.
     *
     * @param list<int> $utenti
     * @return list<int>
     */
    private function idDopo(string $tabella, int $id, string $colonna, array $utenti): array
    {
        $segnaposto = implode(',', array_fill(0, count($utenti), '?'));
        $st = $this->pdo->prepare("SELECT id FROM $tabella WHERE id > ? AND $colonna IN ($segnaposto) ORDER BY id");
        $st->execute([$id, ...$utenti]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}
