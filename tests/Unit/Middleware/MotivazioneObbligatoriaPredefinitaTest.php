<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\RequiresAuditReasonMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Senza configurazione, la motivazione degli interventi amministrativi è
 * obbligatoria (23/9/2026).
 *
 * ── Il difetto, dalla revisione architetturale del 23/9/2026 (A-37) ───────
 *
 * Se `AUDIT_REASON_MODE` mancava, la motivazione ricadeva su `warn` sia in
 * `app/Config/audit.php` sia nel ripiego del middleware: una mutazione di un
 * super-admin senza `X-Audit-Reason` passava, e il registro annotava solo
 * che la motivazione mancava. La produzione dice `enforce` dal 2/9/2026, ma
 * per una riga di un file. Ora il predefinito è `enforce`, anche per un
 * valore sconosciuto; `warn` e `disabled` vanno chiesti per nome.
 *
 * ── Che cosa guarda ───────────────────────────────────────────────────────
 *
 * Il middleware intero, con un super-admin e una richiesta senza
 * motivazione, non il metodo privato che sceglie il modo: si guarda se la
 * richiesta arriva al controller. Il registro va su file in una cartella usa
 * e getta (database spento nella configurazione), così la prova non scrive
 * né nel database né in `storage/logs` del repository.
 *
 * Nei due versi: senza valore, vuoto o sconosciuto la richiesta si ferma con
 * 400; con `warn` e `disabled` scritti passa, e con una motivazione valida
 * passa anche in `enforce`. Senza il secondo verso, un middleware che
 * rifiutasse tutto passerebbe il primo.
 */
final class MotivazioneObbligatoriaPredefinitaTest extends TestCase
{
    private string $registri = '';

    /** @var array<string, mixed> */
    private array $configPrima = [];

    private const CHIAVI = ['audit.reason_mode', 'database.enabled', 'app.paths.logs'];

    protected function setUp(): void
    {
        foreach (self::CHIAVI as $chiave) {
            $this->configPrima[$chiave] = Config::get($chiave);
        }
        $this->registri = sys_get_temp_dir() . '/motivazione-predefinita-' . bin2hex(random_bytes(6));
        mkdir($this->registri, 0700, true);
        Config::set('database.enabled', false);
        Config::set('app.paths.logs', $this->registri);

        $_SESSION = [];
        $this->entraComeSuperAdmin();
    }

    protected function tearDown(): void
    {
        foreach ($this->configPrima as $chiave => $valore) {
            Config::set($chiave, $valore);
        }
        foreach (glob($this->registri . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->registri);
        $_SESSION = [];
    }

    private function entraComeSuperAdmin(): void
    {
        // Nome unico per prova: non corrisponde a nessun utente vero. I claims
        // della sessione, letti adesso, evitano il database: AclPolicy li
        // crede finché sono freschi (Auth::CLAIMS_TTL_SECONDS, dal 23/9/2026;
        // prima una cache a parte, 'fm_super_admin_cache').
        $nome = 'motivazione_' . bin2hex(random_bytes(4));
        $_SESSION['autenticato'] = true;
        $_SESSION['username']    = $nome;
        $_SESSION['user_id']     = 999;
        $_SESSION['user_role']   = 'administrator';
        $_SESSION['is_super_admin'] = true;
        $_SESSION['claims_at']      = time();
    }

    private function richiesta(?string $motivazione): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/api/admin/prova-motivazione';
        unset($_SERVER['HTTP_X_AUDIT_REASON']);
        if ($motivazione !== null) {
            $_SERVER['HTTP_X_AUDIT_REASON'] = $motivazione;
        }

        return new Request();
    }

    /** @return array{0: bool, 1: Response} arrivata al controller, risposta */
    private function passa(?string $motivazione): array
    {
        $arrivata = false;
        $risposta = (new RequiresAuditReasonMiddleware())->handle(
            $this->richiesta($motivazione),
            static function () use (&$arrivata): Response {
                $arrivata = true;
                return Response::json(['ok' => true]);
            },
        );

        return [$arrivata, $risposta];
    }

    /** @return array<string, array{mixed}> */
    public static function modiNonScritti(): array
    {
        return [
            'configurazione assente'    => [null],
            'valore vuoto'              => [''],
            'valore sconosciuto'        => ['boh'],
            'errore di battitura'       => ['enforced'],
        ];
    }

    #[Test]
    #[DataProvider('modiNonScritti')]
    public function senza_un_modo_scritto_la_motivazione_e_obbligatoria(mixed $modo): void
    {
        Config::set('audit.reason_mode', $modo);

        [$arrivata, $risposta] = $this->passa(null);

        self::assertFalse($arrivata, 'una mutazione senza motivazione è arrivata al controller');
        self::assertSame(400, $risposta->status);
        self::assertStringContainsString('audit_reason_required', (string)$risposta->body);
    }

    /** @return array<string, array{string}> */
    public static function modiPermissiviScritti(): array
    {
        return [
            'warn'     => ['warn'],
            'disabled' => ['disabled'],
            'WARN'     => ['WARN'],
        ];
    }

    #[Test]
    #[DataProvider('modiPermissiviScritti')]
    public function un_modo_permissivo_scritto_per_nome_vale_ancora(string $modo): void
    {
        // L'altro verso: il predefinito non ha cancellato gli altri modi.
        Config::set('audit.reason_mode', $modo);

        [$arrivata, $risposta] = $this->passa(null);

        self::assertTrue($arrivata, "con «{$modo}» scritto la richiesta doveva passare");
        self::assertSame(200, $risposta->status);
    }

    #[Test]
    public function con_una_motivazione_valida_passa_anche_senza_un_modo_scritto(): void
    {
        Config::set('audit.reason_mode', null);

        [$arrivata, $risposta] = $this->passa('correzione di un dato inserito per errore');

        self::assertTrue($arrivata, 'una motivazione valida deve bastare');
        self::assertSame(200, $risposta->status);
    }

    /** @return array<string, array{string|null, string}> */
    public static function valoriDellaConfigurazione(): array
    {
        return [
            'assente'     => [null, 'enforce'],
            'vuoto'       => ['', 'enforce'],
            'sconosciuto' => ['boh', 'enforce'],
            'maiuscolo'   => ['ENFORCE', 'enforce'],
            'warn'        => ['warn', 'warn'],
            'disabled'    => ['disabled', 'disabled'],
        ];
    }

    #[Test]
    #[DataProvider('valoriDellaConfigurazione')]
    public function il_file_di_configurazione_ha_lo_stesso_predefinito(?string $valore, string $atteso): void
    {
        // La stessa regola, dal lato di app/Config/audit.php: senza la chiave
        // nel .env la configurazione dice `enforce`. L'ambiente del processo
        // è il ripiego del file, e se lo imposta chi lancia la suite la prova
        // guarderebbe quello.
        if (getenv('AUDIT_REASON_MODE') !== false) {
            self::markTestSkipped('AUDIT_REASON_MODE impostata nell\'ambiente del processo');
        }
        $envPrima = $_ENV;
        try {
            unset($_ENV['AUDIT_REASON_MODE']);
            if ($valore !== null) {
                $_ENV['AUDIT_REASON_MODE'] = $valore;
            }
            $audit = require \dirname(__DIR__, 3) . '/app/Config/audit.php';
        } finally {
            $_ENV = $envPrima;
        }

        self::assertIsArray($audit);
        self::assertSame($atteso, $audit['reason_mode']);
    }
}
