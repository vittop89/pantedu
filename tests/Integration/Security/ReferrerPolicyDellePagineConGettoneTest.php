<?php

declare(strict_types=1);

namespace Tests\Integration\Security;

use App\Controllers\AccountController;
use App\Controllers\PasswordResetController;
use App\Controllers\SelfServiceController;
use App\Controllers\TrustPagesController;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\SecurityHeadersMiddleware;
use App\Services\Gdpr\ConsentService;
use App\Services\Gdpr\DeletionRequestService;
use App\Services\Gdpr\RichiestaDiCancellazione;
use App\Services\Mailer;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le pagine vere con un gettone nell'indirizzo escono con
 * `Referrer-Policy: no-referrer` e il meta; le altre con la politica di
 * sempre (24/9/2026).
 *
 * Il difetto, misurato dal revisore: la pagina `/me/confirm-deletion?token=…`
 * mandava il gettone ancora valido come Referer al POST del pulsante e al
 * caricamento di fogli di stile e script, e CsrfMiddleware lo scriveva nel
 * registro delle anomalie quando il CSRF non passava. Lo stesso schema hanno
 * la pagina del cambio email (`/me/account/email/conferma?token=…`) e quella
 * del ripristino della password (`/password/reset?token=…`).
 *
 * Qui ogni pagina passa da SecurityHeadersMiddleware, come nel Kernel, e si
 * guardano l'intestazione e il meta:
 *   - le pagine dei tre collegamenti (anche con un gettone che non vale: il
 *     gettone è nell'indirizzo lo stesso) → `no-referrer` e il meta;
 *   - «I tuoi dati», l'esito della richiesta, la conferma fatta (POST, senza
 *     gettone nell'indirizzo) e il JSON della GET → la politica di sempre, e
 *     nessun meta.
 *
 * Il meccanismo, senza database: tests/Unit/Middleware/
 * SecurityHeadersReferrerPolicyTest.php. Qui tutto in una transazione
 * annullata alla fine.
 */
final class ReferrerPolicyDellePagineConGettoneTest extends TestCase
{
    private const DI_SEMPRE = 'strict-origin-when-cross-origin';
    private const META = '<meta name="referrer" content="no-referrer">';

    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $configPrima = [];
    private string $cartella = '';
    private string $registroPrima = '';
    private int $idUtente = 0;

    protected function setUp(): void
    {
        if (!Database::isAvailable()) {
            self::markTestSkipped('database non disponibile');
        }
        $this->pdo = Database::connection();
        $this->configPrima = (array)(new \ReflectionProperty(Config::class, 'items'))->getValue();
        $this->cartella = sys_get_temp_dir() . '/pantedu_referer_' . bin2hex(random_bytes(4));
        mkdir($this->cartella, 0700, true);
        $this->registroPrima = (string)ini_get('error_log');
        ini_set('error_log', $this->cartella . '/errori.log');
        Config::set('app.paths.logs', $this->cartella);
        Config::set('app.url', 'https://istanza.example.test');
        Config::set('app.env', 'production');
        Config::set('security.expose_deletion_debug_token', false);

        $this->pdo->beginTransaction();
        $utente = 'zz_referer_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Referer", ?, "x", "approved", 1, NOW())'
        )->execute([$utente, $utente . '@example.test']);
        $this->idUtente = (int)$this->pdo->lastInsertId();
        $_SESSION = [
            'autenticato' => true, 'username' => $utente, 'user_id' => $this->idUtente,
            'user_role' => 'teacher', 'is_super_admin' => false,
        ];
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->configPrima !== []) {
            (new \ReflectionProperty(Config::class, 'items'))->setValue(null, $this->configPrima);
        }
        ini_set('error_log', $this->registroPrima);
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
        $_SESSION = [];
        unset($_SERVER['HTTP_ACCEPT']);
    }

    #[Test]
    public function le_pagine_dei_collegamenti_non_mandano_il_referer(): void
    {
        $gettone = (new DeletionRequestService())->request($this->idUtente);
        $inventato = str_repeat('0f', 32);

        $pagine = [
            'la conferma della cancellazione' => fn(): Response => $this->controllore()->confirmDeletion(
                $this->get('/me/confirm-deletion', ['token' => $gettone])
            ),
            'la conferma della cancellazione, con un gettone che non vale' => fn(): Response =>
                $this->controllore()->confirmDeletion($this->get('/me/confirm-deletion', ['token' => $inventato])),
            'la conferma del cambio email' => fn(): Response => (new AccountController())->confermaPagina(
                $this->get('/me/account/email/conferma', ['token' => $inventato])
            ),
            'il ripristino della password' => fn(): Response => (new PasswordResetController())->showReset(
                $this->get('/password/reset', ['token' => $inventato])
            ),
        ];
        foreach ($pagine as $quale => $pagina) {
            $risposta = $this->attraverso($pagina);
            self::assertSame(['no-referrer'], $this->politiche($risposta), "$quale: l'intestazione");
            self::assertStringContainsString(self::META, $risposta->body, "$quale: il meta");
        }
        self::assertStringContainsString(
            'name="token" value="' . $gettone . '"',
            $this->attraverso($pagine['la conferma della cancellazione'])->body,
            'precondizione: è davvero la pagina con il pulsante'
        );
    }

    #[Test]
    public function le_altre_pagine_hanno_la_politica_di_sempre(): void
    {
        $gettone = (new DeletionRequestService())->request($this->idUtente);

        $pagine = [
            '«I tuoi dati»' => fn(): Response => (new TrustPagesController())->yourData(
                $this->get('/privacy/your-data', [])
            ),
            'la conferma fatta (POST, senza gettone nell\'indirizzo)' => fn(): Response =>
                $this->controllore()->confirmDeletionSubmit(
                    $this->post('/me/confirm-deletion', ['token' => $gettone, '_csrf' => Csrf::token()])
                ),
            // Dopo la conferma qui sopra la cancellazione è in ripensamento, e
            // una richiesta nuova risponderebbe 409: la si annulla prima.
            'l\'esito della richiesta' => function (): Response {
                (new DeletionRequestService())->cancel($this->idUtente);
                return $this->controllore()->requestDeletion(
                    $this->post('/me/request-deletion', ['_csrf' => Csrf::token()])
                );
            },
        ];
        foreach ($pagine as $quale => $pagina) {
            $risposta = $this->attraverso($pagina);
            self::assertSame(200, $risposta->status, "$quale: precondizione, la pagina c'è");
            self::assertSame([self::DI_SEMPRE], $this->politiche($risposta), "$quale: l'intestazione");
            self::assertStringNotContainsString('name="referrer"', $risposta->body, "$quale: nessun meta");
        }

        // La GET in JSON: niente fogli né script da caricare, la politica di sempre.
        $json = $this->attraverso(fn(): Response => $this->controllore()->confirmDeletion(
            $this->get('/me/confirm-deletion', ['token' => $gettone], 'application/json')
        ));
        self::assertSame([self::DI_SEMPRE], $this->politiche($json));
    }

    // ── Appoggi ─────────────────────────────────────────────────────────────

    private function controllore(): SelfServiceController
    {
        $mailer = new Mailer('noreply@istanza.example.test', 'Pantedu', static fn(): bool => true);
        return new SelfServiceController(
            new ConsentService(),
            new DeletionRequestService(),
            new RichiestaDiCancellazione(null, static fn(): Mailer => $mailer),
        );
    }

    /** @param array<string, string> $query */
    private function get(string $percorso, array $query, string $accept = 'text/html,application/xhtml+xml'): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $percorso . ($query !== [] ? '?' . http_build_query($query) : '');
        $_SERVER['HTTP_ACCEPT'] = $accept;
        $_GET = $query;
        $_POST = [];
        return new Request('');
    }

    /** @param array<string, string> $campi */
    private function post(string $percorso, array $campi): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $percorso;
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
        $_GET = [];
        $_POST = $campi;
        return new Request('');
    }

    /** @param callable(): Response $pagina */
    private function attraverso(callable $pagina): Response
    {
        return (new SecurityHeadersMiddleware('relaxed'))->handle(new Request(''), static fn(): Response => $pagina());
    }

    /** @return list<string> */
    private function politiche(Response $risposta): array
    {
        $valori = [];
        foreach ($risposta->headers as $nome => $valore) {
            if (strcasecmp((string)$nome, 'Referrer-Policy') === 0) {
                $valori[] = (string)$valore;
            }
        }
        return $valori;
    }
}
