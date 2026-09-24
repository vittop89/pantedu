<?php

declare(strict_types=1);

namespace Tests\Integration\Crypto;

use App\Controllers\ImportBundleController;
use App\Controllers\TeacherRecoveryController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Crypto\TeacherRecoveryService;
use App\Services\Maps\MapBlobStore;
use App\Support\ImprontaIp;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il registro delle chiavi di recupero tiene l'impronta con chiave dell'IP,
 * non l'IP e non lo SHA-256 nudo (24/9/2026).
 *
 * I due punti che lo scrivono dalla richiesta — la revoca della chiave
 * (`TeacherRecoveryController`) e la verifica del codice all'import di un
 * pacchetto (`ImportBundleController`) — prendono l'indirizzo da
 * `REMOTE_ADDR` e lo passano in chiaro a `TeacherRecoveryService`; nel
 * registro `teacher_recovery_audit` entra solo come impronta
 * (`RequestFingerprint::ipHash`, cioè `ImprontaIp`). Nessuna prova lo
 * guardava: qui si misura la riga scritta, attraverso i controller veri.
 *
 * Nei due versi: la riga ha l'impronta con chiave dell'indirizzo della
 * richiesta, e non l'indirizzo, non lo SHA-256 senza chiave (la formula fino
 * al 24/9, su cui la prova falliva), non l'impronta di un altro indirizzo.
 * Database vero, un docente con nome unico, tutto in una transazione
 * annullata alla fine.
 */
final class ImprontaNelRegistroDelRecuperoTest extends TestCase
{
    private const IP = '192.0.2.61';
    private const ALTRO_IP = '192.0.2.62';
    private const UA = 'ProvaImprontaRecupero/1.0';
    private const SEGRETO = 'segreto-di-prova-per-il-registro-del-recupero-0123456789';

    private PDO $pdo;
    private int $docente = 0;
    private string $kms = '';
    private string $cartella = '';
    private mixed $segretoPrima = null;
    /** @var array<string, mixed> */
    private array $serverPrima = [];

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
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->segretoPrima = Config::get('waf.hmac_secret');
        Config::set('waf.hmac_secret', self::SEGRETO);
        $this->serverPrima = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = self::IP;
        $_SERVER['HTTP_USER_AGENT'] = self::UA;

        $this->pdo->beginTransaction();
        $nome = 'zzimprec' . date('His') . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Recupero", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        $this->docente = (int)$this->pdo->lastInsertId();
        $_SESSION = [
            'autenticato' => true, 'username' => $nome, 'user_id' => $this->docente,
            'user_role' => 'teacher', 'is_super_admin' => false,
        ];
        // Una chiave master della prova: niente dipende da quella di .env.local.
        $this->kms = bin2hex(random_bytes(32));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_SERVER = $this->serverPrima;
        Config::set('waf.hmac_secret', $this->segretoPrima);
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->cartella !== '' && is_dir($this->cartella)) {
            array_map('unlink', glob($this->cartella . '/*/*') ?: []);
            array_map('rmdir', glob($this->cartella . '/*', GLOB_ONLYDIR) ?: []);
            @rmdir($this->cartella);
        }
    }

    #[Test]
    public function la_revoca_scrive_l_impronta_con_chiave_dell_indirizzo(): void
    {
        $controller = new TeacherRecoveryController(new TeacherRecoveryService($this->kms));
        $controller->revoke(new Request(''));

        $this->impronteGiuste($this->riga('revoke'));
    }

    #[Test]
    public function la_verifica_all_import_scrive_l_impronta_con_chiave_dell_indirizzo(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-imprec-' . bin2hex(random_bytes(6));
        $blob = new MapBlobStore(new TeacherCryptoService($this->kms), $this->cartella);
        $controller = new ImportBundleController(new TeacherRecoveryService($this->kms), null, $blob);
        $corpo = json_encode([
            // Un codice che ha la forma giusta e non è quello di nessuno: la
            // verifica fallisce, e il tentativo si registra.
            'recovery_code' => bin2hex(random_bytes(32)),
            'manifest' => [
                'version' => 1, 'exported_at' => date('c'), 'exporter_user_id' => $this->docente,
                'files' => [], 'hmac' => base64_encode(random_bytes(32)),
            ],
            'files' => [],
            'conflict_strategy' => 'skip',
        ], JSON_THROW_ON_ERROR);
        $risposta = $controller->preview(new Request($corpo));
        self::assertSame(403, $risposta->status, $risposta->body);

        $riga = $this->riga('use');
        self::assertSame(0, (int)$riga['success']);
        $this->impronteGiuste($riga);
    }

    /** @param array<string, mixed> $riga */
    private function impronteGiuste(array $riga): void
    {
        // In esadecimale, perché un confronto fallito si legga.
        $ip = bin2hex((string)$riga['ip_hash']);
        self::assertSame(bin2hex((string)ImprontaIp::di(self::IP)), $ip,
            "l'impronta con chiave dell'indirizzo della richiesta");
        self::assertStringNotContainsString(self::IP, (string)$riga['ip_hash'], 'non l\'indirizzo in chiaro');
        self::assertNotSame(hash('sha256', self::IP), $ip, 'non lo SHA-256 senza chiave di prima');
        self::assertNotSame(bin2hex((string)ImprontaIp::di(self::ALTRO_IP)), $ip);
        self::assertSame(hash('sha256', self::UA), bin2hex((string)$riga['ua_hash']), 'lo User-Agent come SHA-256');
    }

    /** @return array<string, mixed> l'unica riga del docente per quell'azione */
    private function riga(string $azione): array
    {
        $st = $this->pdo->prepare('SELECT * FROM teacher_recovery_audit WHERE user_id = ? AND action = ?');
        $st->execute([$this->docente, $azione]);
        $righe = $st->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $righe, "una riga «{$azione}» nel registro");
        return $righe[0];
    }
}
