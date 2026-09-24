<?php

declare(strict_types=1);

namespace Tests\Integration\Waf;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\Waf\WafConfigRepository;
use App\Repositories\Waf\WafSecurityRepository;
use App\Services\Waf\WafBruteforceGuard;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * I tentativi di accesso falliti bloccano quando devono, e soltanto allora
 * (23/9/2026).
 *
 * `WafBruteforceGuard` è il ponte fra un accesso fallito e il blocco: N errori
 * sullo stesso nome utente lo bloccano per un po'; un IP che sbaglia su molti
 * nomi DIVERSI viene bandito, mentre uno studente che sbaglia molte volte la
 * sua password non fa bandire l'IP della scuola. Nessuna prova lo sorvegliava
 * (revisione del 23/9/2026, A-24): una soglia letta male, un conteggio sulla
 * colonna sbagliata o una finestra ignorata avrebbero spento la protezione
 * senza un rosso.
 *
 * ── Contro il database vero, senza lasciare niente ────────────────────────
 *
 * Tutto dentro una transazione sulla connessione condivisa, con rollback in
 * tearDown anche quando la prova fallisce: le righe della prova, e anche la
 * potatura delle righe vecchie di un'ora che il servizio fa a ogni errore.
 * Nomi utente e IP sono unici per giro (IP del blocco riservato alla
 * documentazione, 2001:db8::/32), quindi i conteggi non vedono righe di altri.
 *
 * Le soglie vengono da un `waf_config` in SQLite in memoria: quello vero è
 * condiviso e non si tocca. Sono le più basse che il servizio ammette (3
 * errori per nome, 5 nomi per IP): la prova resta corta e prova anche i
 * minimi. La cache JSON dei blocchi va in una cartella temporanea.
 *
 * ── Un errore interno ─────────────────────────────────────────────────────
 *
 * Il servizio dichiara «best-effort: non solleva mai (non deve rompere il
 * flusso di login)». L'ultima prova descrive che cosa succede davvero, senza
 * approvarlo: niente eccezione, niente blocco, e **nemmeno una riga nel
 * registro degli errori**. La protezione può restare spenta senza che nessuno
 * lo sappia. Se un giorno si decide di scriverlo nel registro, è quella
 * l'asserzione da cambiare.
 */
final class ProtezioneDalBruteForceTest extends TestCase
{
    private const SOGLIA_UTENTE = 3;
    private const SOGLIA_IP     = 5;
    private const BLOCCO_SEC    = 600;

    private PDO $pdo;
    private string $dati = '';
    private string $marca = '';
    private string|false $registroPrima = false;
    /** @var array<string,mixed> */
    private array $configPrima = [];

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 3);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM waf_login_failures LIMIT 0');
            $this->pdo->query('SELECT 1 FROM waf_blocked_credentials LIMIT 0');
            $this->pdo->query('SELECT 1 FROM waf_blocked_ips LIMIT 0');
        } catch (\Throwable $e) {
            self::markTestSkipped('DB o tabelle del WAF (migrazione 086) non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();

        $this->marca = date('YmdHis') . bin2hex(random_bytes(3));
        $this->dati  = sys_get_temp_dir() . '/pantedu-bruteforce-' . $this->marca;
        mkdir($this->dati, 0775, true);
        // Niente import di file vecchi: si prova il servizio, non la migrazione
        // della cache. I valori di prima si rimettono in tearDown.
        foreach (['auth.paths.blocked_credentials_legacy', 'auth.paths.blocked_ips_legacy'] as $chiave) {
            $this->configPrima[$chiave] = Config::get($chiave);
        }
        Config::set('auth.paths.blocked_credentials_legacy', $this->dati . '/vecchio/blocked_credentials.json');
        Config::set('auth.paths.blocked_ips_legacy', $this->dati . '/vecchio/blocked_ips.json');
        $this->scordaLImport();
        $this->registroPrima = ini_get('error_log');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->registroPrima !== false) {
            ini_set('error_log', $this->registroPrima);
        }
        foreach ($this->configPrima as $chiave => $valore) {
            Config::set($chiave, $valore);
        }
        $this->scordaLImport();
        if ($this->dati !== '' && is_dir($this->dati)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dati, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dati);
        }
    }

    /** L'import della cache legacy gira una volta per processo: fra una prova e l'altra si scorda. */
    private function scordaLImport(): void
    {
        $p = new \ReflectionProperty(WafSecurityRepository::class, 'importDone');
        $p->setValue(null, false);
    }

    /** Le soglie della prova, in un waf_config tutto suo. */
    private function configurazione(): WafConfigRepository
    {
        $sqlite = new PDO('sqlite::memory:');
        $sqlite->exec('CREATE TABLE waf_config (config_key TEXT PRIMARY KEY, config_value TEXT)');
        $ins = $sqlite->prepare('INSERT INTO waf_config (config_key, config_value) VALUES (?, ?)');
        foreach (
            [
                'bf_user_threshold'        => self::SOGLIA_UTENTE,
                'bf_user_window_sec'       => 900,
                'bf_user_lock_sec'         => self::BLOCCO_SEC,
                'bf_ip_distinct_threshold' => self::SOGLIA_IP,
                'bf_ip_window_sec'         => 600,
                'bf_ip_ban_sec'            => self::BLOCCO_SEC,
            ] as $k => $v
        ) {
            $ins->execute([$k, (string)$v]);
        }
        return new WafConfigRepository($sqlite);
    }

    private function sicurezza(): WafSecurityRepository
    {
        return new WafSecurityRepository(
            $this->pdo,
            $this->dati . '/storage/security/blocked_credentials.json',
            $this->dati . '/storage/security/blocked_ips.json',
        );
    }

    private function guardia(): WafBruteforceGuard
    {
        return new WafBruteforceGuard($this->pdo, $this->configurazione(), $this->sicurezza());
    }

    private function utente(string $n = 'a'): string
    {
        return 'zz_bf_' . $this->marca . '_' . $n;
    }

    private function ip(int $n = 1): string
    {
        return '2001:db8:' . substr($this->marca, -4) . '::' . dechex($n);
    }

    private function bloccato(string $utente): bool
    {
        return $this->sicurezza()->isCredentialBlocked($utente);
    }

    private function bandito(string $ip): bool
    {
        return $this->sicurezza()->isIpBlockedForSection($ip);
    }

    /** @return array<string,mixed>|null */
    private function rigaDelBlocco(string $utente): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT source, expires_at, expires_at > NOW() AS in_vigore
               FROM waf_blocked_credentials WHERE username = ?',
        );
        $st->execute([$utente]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    #[Test]
    public function sotto_la_soglia_il_nome_non_si_blocca_alla_soglia_si(): void
    {
        $g = $this->guardia();
        $u = $this->utente();

        for ($i = 1; $i < self::SOGLIA_UTENTE; $i++) {
            $g->registerFailure($this->ip(), $u);
        }
        self::assertFalse($this->bloccato($u), 'sotto la soglia');
        self::assertNull($this->rigaDelBlocco($u));

        $g->registerFailure($this->ip(), $u);
        self::assertTrue($this->bloccato($u), 'alla soglia');

        $riga = $this->rigaDelBlocco($u);
        self::assertNotNull($riga);
        self::assertSame('auth_bruteforce', $riga['source']);
        self::assertNotNull($riga['expires_at'], 'il blocco è temporaneo, non per sempre');
        self::assertSame(1, (int)$riga['in_vigore']);

        self::assertFalse($this->bloccato($this->utente('b')), 'un altro nome resta libero');
    }

    /** Lo studente che sbaglia la sua password non fa bandire l'IP della scuola. */
    #[Test]
    public function molti_errori_sullo_stesso_nome_non_bandiscono_l_ip(): void
    {
        $g = $this->guardia();
        for ($i = 0; $i < self::SOGLIA_IP + 3; $i++) {
            $g->registerFailure($this->ip(), $this->utente());
        }

        self::assertFalse($this->bandito($this->ip()), 'un nome solo non è credential stuffing');
        self::assertTrue($this->bloccato($this->utente()), 'il nome, invece, sì');
    }

    #[Test]
    public function molti_nomi_diversi_dallo_stesso_ip_lo_bandiscono(): void
    {
        $g = $this->guardia();
        for ($i = 1; $i < self::SOGLIA_IP; $i++) {
            $g->registerFailure($this->ip(), $this->utente("n{$i}"));
        }
        self::assertFalse($this->bandito($this->ip()), 'sotto la soglia');

        $g->registerFailure($this->ip(), $this->utente('ultimo'));
        self::assertTrue($this->bandito($this->ip()), 'alla soglia');

        $st = $this->pdo->prepare(
            'SELECT section, source, expires_at > NOW() AS in_vigore FROM waf_blocked_ips WHERE ip_or_cidr = ?',
        );
        $st->execute([$this->ip()]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($riga);
        self::assertNull($riga['section'], 'lista nera globale, che applica il WafMiddleware');
        self::assertSame('auth_bruteforce', $riga['source']);
        self::assertSame(1, (int)$riga['in_vigore']);

        self::assertFalse($this->bandito($this->ip(2)), 'un altro IP resta libero');
    }

    #[Test]
    public function un_accesso_riuscito_azzera_il_conto(): void
    {
        $g = $this->guardia();
        $u = $this->utente();
        for ($i = 1; $i < self::SOGLIA_UTENTE; $i++) {
            $g->registerFailure($this->ip(), $u);
        }
        $g->clearOnSuccess($u);
        $g->registerFailure($this->ip(), $u);

        self::assertFalse($this->bloccato($u), 'dopo l\'accesso riuscito il conto riparte da zero');
    }

    /** Gli errori più vecchi della finestra non contano (e quelli di un'ora si potano). */
    #[Test]
    public function gli_errori_fuori_dalla_finestra_non_contano(): void
    {
        $u = $this->utente();
        $vecchi = $this->pdo->prepare(
            'INSERT INTO waf_login_failures (ip, username, created_at) VALUES (?, ?, NOW() - INTERVAL 20 MINUTE)',
        );
        for ($i = 1; $i < self::SOGLIA_UTENTE; $i++) {
            $vecchi->execute([$this->ip(), $u]);
        }

        $this->guardia()->registerFailure($this->ip(), $u);

        self::assertFalse($this->bloccato($u), 'finestra di 15 minuti: quelli di 20 minuti fa non contano');
    }

    /**
     * Anche il ban IP ha la sua finestra (bf_ip_window_sec): un IP che sbaglia
     * su molti nomi diversi ma SPARSI nel tempo — più vecchi della finestra,
     * dentro l'ora della potatura — non viene bandito. Serve a non punire il
     * NAT di una scuola per errori accumulati in un'ora intera.
     *
     * Nomi distinti «vecchi» (20 minuti fa, la finestra è di 10) fino a uno
     * sotto la soglia, poi un errore fresco su un nome nuovo: dentro la
     * finestra c'è un nome solo. Se la finestra si ignora, i nomi vecchi
     * contano e l'IP viene bandito: è la mutazione che questa prova sorveglia
     * (nella COUNT(DISTINCT) `AND created_at >= (NOW() - INTERVAL ? SECOND)`
     * sostituito con `AND ? > 0`).
     */
    #[Test]
    public function i_nomi_diversi_fuori_dalla_finestra_non_bandiscono_l_ip(): void
    {
        $ip = $this->ip();
        $vecchi = $this->pdo->prepare(
            'INSERT INTO waf_login_failures (ip, username, created_at) VALUES (?, ?, NOW() - INTERVAL 20 MINUTE)',
        );
        // Soglia - 1 nomi distinti, tutti fuori dalla finestra di 10 minuti.
        for ($i = 1; $i < self::SOGLIA_IP; $i++) {
            $vecchi->execute([$ip, $this->utente("vecchio{$i}")]);
        }

        // Un errore fresco su un nome nuovo: nella finestra ce n'è uno solo.
        $this->guardia()->registerFailure($ip, $this->utente('fresco'));

        self::assertFalse(
            $this->bandito($ip),
            'finestra dell\'IP di 10 minuti: i nomi di 20 minuti fa non contano per il ban',
        );

        // Controprova nell'altro verso: gli stessi nomi distinti DENTRO la
        // finestra fanno scattare il ban. Un IP diverso, per non sommarsi.
        $ip2 = $this->ip(2);
        $g = $this->guardia();
        for ($i = 1; $i < self::SOGLIA_IP; $i++) {
            $g->registerFailure($ip2, $this->utente("dentro{$i}"));
        }
        self::assertFalse($this->bandito($ip2), 'sotto la soglia, nella finestra');
        $g->registerFailure($ip2, $this->utente('dentro_ultimo'));
        self::assertTrue($this->bandito($ip2), 'alla soglia, nella finestra: bandito');
    }

    #[Test]
    public function senza_un_ip_non_si_registra_niente(): void
    {
        $g = $this->guardia();
        $u = $this->utente();
        for ($i = 0; $i < self::SOGLIA_UTENTE + 1; $i++) {
            $g->registerFailure('', $u);
            $g->registerFailure('0.0.0.0', $u);
            $g->registerFailure('   ', $u);
        }

        $st = $this->pdo->prepare('SELECT COUNT(*) FROM waf_login_failures WHERE username = ?');
        $st->execute([$u]);
        self::assertSame(0, (int)$st->fetchColumn());
        self::assertFalse($this->bloccato($u));
    }

    /**
     * Il database risponde ma la tabella non c'è (una migrazione mancata): il
     * servizio non solleva, non blocca, e non lo scrive da nessuna parte.
     */
    #[Test]
    public function un_errore_interno_non_solleva_non_blocca_e_non_lo_dice(): void
    {
        $registro = $this->dati . '/errori.log';
        ini_set('error_log', $registro);

        $senzaTabelle = new PDO('sqlite::memory:');
        $g = new WafBruteforceGuard($senzaTabelle, $this->configurazione(), $this->sicurezza());
        $u = $this->utente();

        for ($i = 0; $i < self::SOGLIA_UTENTE + 2; $i++) {
            $g->registerFailure($this->ip(), $u);
        }
        $g->clearOnSuccess($u);

        self::assertFalse($this->bloccato($u), 'nessun blocco');
        self::assertFalse($this->bandito($this->ip()), 'nessun bando');
        clearstatcache();
        self::assertSame(
            '',
            is_file($registro) ? (string)file_get_contents($registro) : '',
            'oggi l\'errore non arriva al registro: vedi il commento in testa',
        );

        // La controprova che il registro catturato funziona: se il servizio
        // scrivesse, qui si vedrebbe.
        error_log('[prova] il registro della prova riceve');
        clearstatcache();
        self::assertStringContainsString('il registro della prova riceve', (string)@file_get_contents($registro));
    }
}
