<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\RequiresAuditReasonMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Una motivazione con le lettere accentate arriva intera nel registro
 * (23/9/2026).
 *
 * ── Il difetto ────────────────────────────────────────────────────────────
 *
 * Il browser scrive un'intestazione HTTP un byte per carattere, in
 * ISO-8859-1: `fetch` con `X-Audit-Reason: Visibilità …` manda il byte 0xE0
 * per la «à» (js/modules/core/audit-reason.js lascia passare le accentate
 * proprio perché stanno in ISO-8859-1). Il middleware passava quei byte così
 * com'erano a `PrivilegedAccessLogger`, che li scrive in una colonna utf8mb4:
 * non sono UTF-8 valido, e MariaDB in modo stretto rifiuta la riga; il
 * ripiego su file passa da `json_encode`, che su UTF-8 non valido restituisce
 * false, e scrive una riga vuota. In tutti e due i casi la motivazione di una
 * mutazione amministrativa — la cosa che ADR-008 chiede di conservare — si
 * perdeva senza errori. Trovato scrivendo i motivi della A-69, che ne hanno
 * parecchi con le accentate.
 *
 * ── Che cosa guarda ───────────────────────────────────────────────────────
 *
 * Il middleware intero con un super-admin, e il registro su file in una
 * cartella usa e getta (database spento). Nei due versi: i byte ISO-8859-1
 * del browser diventano UTF-8; una motivazione già in UTF-8 (curl, il campo
 * `_audit_reason` di un modulo) resta com'è, senza una seconda conversione.
 * E la lunghezza si conta in caratteri: dieci lettere accentate bastano.
 */
final class MotivazioneConLeAccentateTest extends TestCase
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
        $this->registri = sys_get_temp_dir() . '/motivazione-accentate-' . bin2hex(random_bytes(6));
        mkdir($this->registri, 0700, true);
        Config::set('database.enabled', false);
        Config::set('app.paths.logs', $this->registri);
        Config::set('audit.reason_mode', 'enforce');

        $_SESSION = [
            'autenticato'    => true,
            'username'       => 'accentate_' . bin2hex(random_bytes(4)),
            'user_id'        => 999,
            'user_role'      => 'administrator',
            'is_super_admin' => true,
            'claims_at'      => time(),
        ];
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
        $_POST = [];
        unset($_SERVER['HTTP_X_AUDIT_REASON']);
    }

    /**
     * Manda la richiesta e restituisce se è arrivata al controller e la
     * motivazione scritta nel registro.
     *
     * @param array<string, string> $post
     * @return array{0: bool, 1: string|null}
     */
    private function manda(?string $intestazione, array $post = []): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/api/admin/prova-accentate';
        unset($_SERVER['HTTP_X_AUDIT_REASON']);
        if ($intestazione !== null) {
            $_SERVER['HTTP_X_AUDIT_REASON'] = $intestazione;
        }
        $_POST = $post;
        $req = new Request();
        $_POST = [];

        $arrivata = false;
        (new RequiresAuditReasonMiddleware())->handle($req, static function () use (&$arrivata): Response {
            $arrivata = true;
            return Response::json(['ok' => true]);
        });

        $righe = file($this->registri . '/privileged_access.log', FILE_IGNORE_NEW_LINES) ?: [];
        $riga = json_decode((string)end($righe), true);
        return [$arrivata, \is_array($riga) ? ($riga['reason'] ?? null) : null];
    }

    #[Test]
    public function i_byte_iso_8859_1_del_browser_arrivano_come_utf8(): void
    {
        // «Visibilità del modello cambiata, però è giusto», come la scrive fetch.
        $dalBrowser = mb_convert_encoding('Visibilità del modello cambiata, però è giusto', 'ISO-8859-1', 'UTF-8');
        self::assertFalse(mb_check_encoding($dalBrowser, 'UTF-8'), 'la premessa: non è UTF-8');

        [$arrivata, $motivo] = $this->manda($dalBrowser);

        self::assertTrue($arrivata);
        self::assertSame('Visibilità del modello cambiata, però è giusto', $motivo, 'la motivazione si è persa nel registro');
    }

    #[Test]
    public function una_motivazione_gia_in_utf8_resta_com_e(): void
    {
        [$arrivata, $motivo] = $this->manda('Visibilità cambiata dal terminale');
        self::assertTrue($arrivata);
        self::assertSame('Visibilità cambiata dal terminale', $motivo, 'convertita due volte');

        [$arrivata, $motivo] = $this->manda(null, ['_audit_reason' => 'Studente spostato: però è una rettifica']);
        self::assertTrue($arrivata);
        self::assertSame('Studente spostato: però è una rettifica', $motivo);
    }

    #[Test]
    public function la_lunghezza_si_conta_in_caratteri(): void
    {
        // Dieci caratteri, venti byte in UTF-8: bastano. Nove no.
        [$arrivata] = $this->manda(null, ['_audit_reason' => str_repeat('è', 10)]);
        self::assertTrue($arrivata, 'dieci lettere accentate sono una motivazione di dieci caratteri');

        [$arrivata] = $this->manda(null, ['_audit_reason' => str_repeat('è', 9)]);
        self::assertFalse($arrivata, 'nove caratteri non bastano, anche se sono diciotto byte');

        // 255 caratteri con le accentate: la colonna è VARCHAR(255) in
        // caratteri, e auditReason() taglia a 255 caratteri, non byte.
        [$arrivata] = $this->manda(null, ['_audit_reason' => str_repeat('à', 255)]);
        self::assertTrue($arrivata, '255 caratteri stanno nella colonna');
    }
}
