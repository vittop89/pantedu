<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\AuthController;
use App\Controllers\SelfServiceController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit\RequestFingerprint;
use App\Support\ImprontaIp;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Login e consensi usano l'IP di EdgeContext, sul database vero (23/9/2026).
 *
 * `AuthController` prendeva `Client-IP`, poi il primo elemento di
 * `X-Forwarded-For`; `SelfServiceController` l'intero `X-Forwarded-For`. Li
 * sceglie il client, e nginx li lascia passare. Tre conseguenze (revisione
 * architetturale 2026-09, A-63):
 *   - il blocco dell'IP per sezione si aggirava mandando un indirizzo
 *     inventato;
 *   - con l'indirizzo di un altro si faceva scattare il suo blocco;
 *   - l'hash dell'IP nei consensi era falsificabile, e non si confrontava con
 *     quello degli altri registri.
 *
 * Provato nei due versi, e anche dal proxy fidato, per il login e per i
 * consensi: lì `CF-Connecting-IP` vale, altrimenti «ignorare sempre gli
 * header» (solo `REMOTE_ADDR`) passerebbe la prova.
 *
 * L'utente è uno studente senza corso: superata la password e il controllo
 * dell'IP, il login si ferma su «sezione non autorizzata». Così i due esiti —
 * IP bloccato o no — si distinguono senza aprire una sessione.
 *
 * Tutto dentro una transazione annullata alla fine; registro degli accessi e
 * liste di blocco in una cartella temporanea. Indirizzi dei blocchi riservati
 * alla documentazione (RFC 5737).
 */
final class IpDelLoginEDeiConsensiTest extends TestCase
{
    private const BLOCCATO = '203.0.113.50';
    private const LIBERO = '192.0.2.20';
    private const INVENTATO = '192.0.2.98';
    private const PROXY_FIDATO = '198.51.100.7';
    private const SEZIONE_URL = '/eser/sci/eser_sci1s/';

    private PDO $pdo;
    /**
     * La password dell'utente di prova, nuova a ogni giro: una scritta nel
     * file è una credenziale nel repository, e gitleaks la ferma (23/9/2026).
     */
    private string $password = '';
    private string $tmp = '';
    /** @var array<string, mixed> */
    private array $configPrima = [];
    private string $utente = '';
    private int $idUtente = 0;
    /** @var array<string, mixed> */
    private array $serverPrima = [];
    /** @var array<string, mixed> */
    private array $postPrima = [];

    protected function setUp(): void
    {
        $this->password = 'prova-' . bin2hex(random_bytes(8));
        $base = \dirname(__DIR__, 2);
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

        $this->configPrima = $this->items();
        $this->serverPrima = $_SERVER;
        $this->postPrima = $_POST;
        $this->tmp = sys_get_temp_dir() . '/pantedu_ip_login_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0750, true);
        file_put_contents($this->tmp . '/blocked_ips.json', json_encode([
            ['ip' => self::BLOCCATO, 'section' => 'sci1s'],
        ]));
        file_put_contents($this->tmp . '/blocked_credentials.json', '[]');
        Config::set('auth.paths.blocked_ips', $this->tmp . '/blocked_ips.json');
        Config::set('auth.paths.blocked_credentials', $this->tmp . '/blocked_credentials.json');
        Config::set('app.paths.logs', $this->tmp);
        Config::set('waf.trusted_proxies', '198.51.100.0/24');
        $_SESSION = [];
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'WAF_EDGE_TRUSTED'] as $k) {
            unset($_SERVER[$k]);
        }

        $this->pdo->beginTransaction();
        $this->utente = 'zz_ip_edge_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, is_super_admin)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0)'
        )->execute([
            $this->utente, 'student', 'Prova', 'Ip', $this->utente . '@example.test',
            password_hash($this->password, PASSWORD_BCRYPT, ['cost' => 4]), 'approved',
        ]);
        $this->idUtente = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->configPrima !== []) {
            (new \ReflectionProperty(Config::class, 'items'))->setValue(null, $this->configPrima);
        }
        if ($this->tmp !== '') {
            foreach (glob($this->tmp . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($this->tmp);
        }
        $_SESSION = [];
        // Gli indirizzi di prova non restano alle prove che girano dopo.
        if ($this->serverPrima !== []) {
            $_SERVER = $this->serverPrima;
            $_POST = $this->postPrima;
        }
    }

    // ── Il blocco dell'IP per sezione ─────────────────────────────────────

    #[Test]
    public function un_x_forwarded_for_inventato_non_aggira_il_blocco(): void
    {
        $esito = $this->login([
            'REMOTE_ADDR' => self::BLOCCATO,
            'HTTP_CLIENT_IP' => self::INVENTATO,
            'HTTP_X_FORWARDED_FOR' => self::INVENTATO,
        ]);
        self::assertSame(Auth::REASON_IP_BLOCKED, $esito, "l'indirizzo bloccato resta bloccato");
    }

    #[Test]
    public function l_indirizzo_di_un_altro_non_fa_scattare_il_suo_blocco(): void
    {
        $esito = $this->login([
            'REMOTE_ADDR' => self::LIBERO,
            'HTTP_X_FORWARDED_FOR' => self::BLOCCATO,
        ]);
        self::assertSame(Auth::REASON_UNAUTHORIZED, $esito,
            "l'IP non è bloccato, e il login arriva al controllo della sezione");
    }

    #[Test]
    public function dal_proxy_fidato_conta_cf_connecting_ip(): void
    {
        $esito = $this->login([
            'REMOTE_ADDR' => self::PROXY_FIDATO,
            'HTTP_CF_CONNECTING_IP' => self::BLOCCATO,
        ]);
        self::assertSame(Auth::REASON_IP_BLOCKED, $esito, 'dietro il CDN il blocco vale per il visitatore vero');
    }

    // ── L'hash dell'IP nei consensi ───────────────────────────────────────

    #[Test]
    public function il_consenso_registra_l_hash_dell_ip_vero_uguale_agli_altri_registri(): void
    {
        $this->consenti([
            'REMOTE_ADDR' => self::LIBERO,
            'HTTP_CLIENT_IP' => self::INVENTATO,
            'HTTP_X_FORWARDED_FOR' => self::INVENTATO . ', ' . self::PROXY_FIDATO,
        ]);

        $atteso = bin2hex((string)ImprontaIp::di(self::LIBERO));
        self::assertSame(64, \strlen($atteso), 'con il segreto del server l\'impronta c\'è');
        self::assertNotSame(hash('sha256', self::LIBERO), $atteso, 'impronta con chiave, non lo SHA-256 nudo');
        self::assertSame(
            ['consents' => $atteso, 'consent_audit' => $atteso],
            $this->hashDelConsenso(),
            "l'hash è quello dell'indirizzo di connessione, non di quello inventato",
        );
        self::assertSame($atteso, bin2hex((string)RequestFingerprint::ipHash()),
            'lo stesso hash degli altri registri di audit: si possono confrontare');
    }

    #[Test]
    public function dal_proxy_fidato_il_consenso_registra_l_hash_del_visitatore(): void
    {
        // Il verso opposto: dietro il CDN la connessione è del proxy, e
        // l'indirizzo vero sta in CF-Connecting-IP. Una formula «solo
        // REMOTE_ADDR» passerebbe la prova di sopra e qui scriverebbe l'hash
        // del proxy.
        $this->consenti([
            'REMOTE_ADDR' => self::PROXY_FIDATO,
            'HTTP_CF_CONNECTING_IP' => self::LIBERO,
        ]);

        $atteso = bin2hex((string)ImprontaIp::di(self::LIBERO));
        self::assertSame(64, \strlen($atteso), 'con il segreto del server l\'impronta c\'è');
        self::assertSame(
            ['consents' => $atteso, 'consent_audit' => $atteso],
            $this->hashDelConsenso(),
            "l'hash è quello del visitatore, non quello del proxy",
        );
        self::assertSame($atteso, bin2hex((string)RequestFingerprint::ipHash()),
            'e coincide con quello degli altri registri di audit');
    }

    /**
     * Uno studente dà il consenso «analytics» con questi valori di connessione.
     *
     * @param array<string, string> $server
     */
    private function consenti(array $server): void
    {
        foreach ($server as $k => $v) {
            $_SERVER[$k] = $v;
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/me/consents/grant';
        $_POST = ['type' => 'analytics'];
        $_SESSION = [
            'autenticato' => true, 'username' => $this->utente, 'user_id' => $this->idUtente,
            'user_role' => 'student', 'is_super_admin' => false,
        ];

        $risposta = (new SelfServiceController())->consentGrant(new Request(''));
        self::assertSame(200, $risposta->status, 'il consenso si registra');
    }

    /**
     * L'hash dell'IP del consenso nelle due tabelle, in esadecimale perché un
     * rosso si legga.
     *
     * @return array{consents: string, consent_audit: string}
     */
    private function hashDelConsenso(): array
    {
        $stmt = $this->pdo->prepare('SELECT HEX(ip_hash) FROM consents WHERE user_id = ? AND consent_type = ?');
        $stmt->execute([$this->idUtente, 'analytics']);
        $consents = strtolower((string)$stmt->fetchColumn());

        $stmt = $this->pdo->prepare('SELECT HEX(ip_hash) FROM consent_audit WHERE user_id = ? AND event = ?');
        $stmt->execute([$this->idUtente, 'granted']);
        $audit = strtolower((string)$stmt->fetchColumn());

        return ['consents' => $consents, 'consent_audit' => $audit];
    }

    /**
     * Un login con password giusta verso una sezione; restituisce il motivo
     * del rifiuto letto dal rimando, o «entrato».
     *
     * @param array<string, string> $server
     */
    private function login(array $server): string
    {
        foreach ($server as $k => $v) {
            $_SERVER[$k] = $v;
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/login';
        $_POST = ['username' => $this->utente, 'password' => $this->password, 'redirect' => self::SEZIONE_URL];

        $risposta = (new AuthController())->login(new Request(''));
        return $this->motivo($risposta);
    }

    private function motivo(Response $risposta): string
    {
        $dove = (string)($risposta->headers['Location'] ?? '');
        parse_str((string)parse_url($dove, PHP_URL_QUERY), $query);
        $errore = $query['error'] ?? null;
        return \is_string($errore) ? $errore : 'entrato';
    }

    /** @return array<string, mixed> */
    private function items(): array
    {
        return (array)(new \ReflectionProperty(Config::class, 'items'))->getValue();
    }
}
