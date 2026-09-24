<?php

declare(strict_types=1);

namespace Tests\Integration\Waf;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\Waf\WafSecurityRepository;
use App\Services\BlockList;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La cache JSON dei blocchi c'è dal primo avvio, e se non si può scrivere lo
 * dice.
 *
 * Il database è la fonte di verità, ma i blocchi per SEZIONE si verificano
 * solo leggendo il file: `App\Services\BlockList::isIpBlockedForSection()`,
 * che `Auth::attempt()` interroga a ogni accesso, non ha un ramo che passa dal
 * database. E la sincronizzazione DB→JSON gira **dopo una mutazione**.
 *
 * Il 20/9/2026 questi due file sono passati da `<dati>/log/data` a
 * `<dati>/storage/security`: dal rilascio fino al primo blocco o sblocco fatto
 * a mano il file nuovo non esisteva, e ogni IP risultava non bloccato. Adesso
 * la cache mancante si rifà dal database alla prima istanziazione.
 */
final class CacheDeiBlocchiTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private string $dati = '';
    private string $ip = '';
    private string $sezione = '';

    protected function setUp(): void
    {
        $basePath = \dirname(__DIR__, 3);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$basePath/$f")) {
                \Dotenv\Dotenv::createMutable($basePath, $f)->safeLoad();
            }
        }
        Config::load($basePath . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT id FROM waf_blocked_ips LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o tabelle WAF non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;

        // Nome unico con marca temporale: la prova non lascia niente, e non
        // inciampa in righe di altri.
        $this->ip      = '203.0.113.' . random_int(2, 250);
        $this->sezione = 'ZZ' . substr((string)time(), -4);
        $ins = $this->pdo->prepare(
            'INSERT INTO waf_blocked_ips (ip_or_cidr, reason, section, source) VALUES (?, ?, ?, ?)',
        );
        $ins->execute([$this->ip, 'prova cache dei blocchi', $this->sezione, 'manual']);

        $this->dati = sys_get_temp_dir() . '/pantedu-blocchi-' . bin2hex(random_bytes(6));
        mkdir($this->dati, 0775, true);
        // I percorsi legacy puntano dove non c'è niente: qui si prova la
        // rigenerazione dal database, non l'import di un file vecchio.
        Config::set('auth.paths.blocked_credentials_legacy', $this->dati . '/vecchio/blocked_credentials.json');
        Config::set('auth.paths.blocked_ips_legacy', $this->dati . '/vecchio/blocked_ips.json');
        $this->scordaLImport();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->inTx = false;
        $this->rimuovi($this->dati);
        $this->scordaLImport();
    }

    /** L'import gira una volta per processo: fra una prova e l'altra si scorda. */
    private function scordaLImport(): void
    {
        $p = new \ReflectionProperty(WafSecurityRepository::class, 'importDone');
        $p->setValue(null, false);
    }

    private function percorso(string $nome): string
    {
        return $this->dati . '/storage/security/' . $nome;
    }

    private function repository(?string $cred = null, ?string $ips = null): WafSecurityRepository
    {
        return new WafSecurityRepository(
            $this->pdo,
            $cred ?? $this->percorso('blocked_credentials.json'),
            $ips ?? $this->percorso('blocked_ips.json'),
        );
    }

    #[Test]
    public function la_cache_mancante_si_rifa_dal_database(): void
    {
        self::assertFileDoesNotExist($this->percorso('blocked_ips.json'), 'si parte senza cache');

        $this->repository();

        self::assertFileExists($this->percorso('blocked_ips.json'));
        self::assertFileExists($this->percorso('blocked_credentials.json'));

        $lista = new BlockList($this->percorso('blocked_credentials.json'), $this->percorso('blocked_ips.json'));
        self::assertTrue(
            $lista->isIpBlockedForSection($this->ip, $this->sezione),
            'il blocco che sta nel database deve valere anche il giorno del rilascio',
        );
    }

    #[Test]
    public function il_verso_opposto_chi_non_e_bloccato_passa(): void
    {
        // Senza questa metà, la prova di sopra la supererebbe una funzione che
        // risponde sempre «bloccato».
        $this->repository();
        $lista = new BlockList($this->percorso('blocked_credentials.json'), $this->percorso('blocked_ips.json'));

        self::assertFalse($lista->isIpBlockedForSection('198.51.100.77', $this->sezione), 'altro IP');
        self::assertFalse($lista->isIpBlockedForSection($this->ip, $this->sezione . 'X'), 'altra sezione');
    }

    #[Test]
    public function una_cache_che_ce_gia_non_si_tocca(): void
    {
        $ips = $this->percorso('blocked_ips.json');
        mkdir(\dirname($ips), 0775, true);
        file_put_contents($ips, '[]');
        file_put_contents($this->percorso('blocked_credentials.json'), '[]');

        $this->repository();

        self::assertSame('[]', (string)file_get_contents($ips), 'la cache esistente resta quella che è');
    }

    #[Test]
    public function se_la_cache_non_si_puo_scrivere_la_funzione_lo_dice(): void
    {
        // Al posto della cartella c'è un FILE: `mkdir` fallisce per chiunque,
        // anche per root — la stessa prova vale sul portatile e sul runner.
        $ostacolo = $this->dati . '/ostacolo';
        file_put_contents($ostacolo, 'non sono una cartella');
        $repo = $this->repository($ostacolo . '/c.json', $ostacolo . '/i.json');

        // `assertSame(false, ...)` e non `assertFalse`: prima queste due non
        // restituivano niente, e un `null` letto come booleano sarebbe passato
        // per un «no» ben detto.
        self::assertSame(false, $this->sincronizza($repo, 'syncIpsToJson'));
        self::assertSame(false, $this->sincronizza($repo, 'syncCredentialsToJson'));
        self::assertFileDoesNotExist($ostacolo . '/i.json');
    }

    #[Test]
    public function la_controprova_una_cartella_buona_riesce(): void
    {
        $repo = $this->repository();

        self::assertSame(true, $this->sincronizza($repo, 'syncIpsToJson'));
        self::assertSame(true, $this->sincronizza($repo, 'syncCredentialsToJson'));
        self::assertFileExists($this->percorso('blocked_ips.json'));
    }

    private function sincronizza(WafSecurityRepository $repo, string $metodo): mixed
    {
        return (new \ReflectionMethod(WafSecurityRepository::class, $metodo))->invoke($repo);
    }

    private function rimuovi(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
