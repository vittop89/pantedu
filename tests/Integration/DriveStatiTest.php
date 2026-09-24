<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\DriveController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\DriveOAuthRepository;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Drive\DriveClient;
use App\Services\Drive\DriveDaRicollegare;
use App\Services\Drive\StatoDiDrive;
use Google\Service\Drive as GoogleDriveService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gli stati di Drive sul database vero (ADR-038, 14/9/2026).
 *
 * Google non si chiama: al suo posto un gestore HTTP di Guzzle che risponde
 * come Google (le forme delle risposte sono quelle misurate con la libreria
 * lo stesso giorno). Il resto è vero: la riga del collegamento, la cifratura
 * del token, la cache delle cartelle, il controller.
 *
 * Nei due versi: il collegamento diventa da ricollegare quando Google rifiuta
 * il token o il permesso manca, e resta attivo quando Google risponde bene o
 * quando il guasto è dell'installazione. DB-gated, tutto in transazione →
 * rollback in tearDown.
 */
final class DriveStatiTest extends TestCase
{
    private const TOKEN_OK = ['access_token' => 'ya29.prova', 'expires_in' => 3599, 'token_type' => 'Bearer'];

    private PDO $pdo;
    private int $docente = 0;
    private DriveOAuthRepository $repo;
    /** @var array<int, array<string, mixed>> */
    private array $richieste = [];
    /** @var array<string, mixed> */
    private array $drivePrima = [];
    /** @var array<string, mixed> */
    private array $serverPrima = [];
    /** @var array<string, mixed> */
    private array $getPrima = [];

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
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
        $this->pdo->beginTransaction();

        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES ("zzdrivestati", "teacher", "Zz", "Drive", "zzdrivestati@example.invalid", "x", "approved", 1, NOW())'
        )->execute();
        $this->docente = (int)$this->pdo->lastInsertId();

        // Una chiave maestra di prova: il token si cifra davvero, e la riga delle chiavi sparisce con il rollback.
        $this->repo = new DriveOAuthRepository(new TeacherCryptoService(bin2hex(random_bytes(32))));

        $drive = Config::get('drive', []);
        $this->drivePrima = is_array($drive) ? $drive : [];
        $this->accendi();

        $this->serverPrima = $_SERVER;
        $this->getPrima = $_GET;
        $_SESSION = ['autenticato' => true, 'username' => 'zzdrivestati', 'user_role' => 'teacher', 'user_id' => $this->docente];
    }

    protected function tearDown(): void
    {
        Config::set('drive', $this->drivePrima);
        $_SERVER = $this->serverPrima;
        $_GET = $this->getPrima;
        $_SESSION = [];
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function accendi(bool $acceso = true): void
    {
        Config::set('drive', array_replace($this->drivePrima, [
            'enabled' => $acceso,
            'oauth'   => ['client_id' => 'id-di-prova', 'client_secret' => 'segreto-di-prova', 'redirect_uri' => 'https://example.invalid/teacher/drive/callback'],
        ]));
    }

    /** Un client di Drive che parla con un Google simulato, e ne tiene le richieste. */
    private function client(Response ...$risposte): DriveClient
    {
        $pila = HandlerStack::create(new MockHandler($risposte));
        $pila->push(Middleware::history($this->richieste));
        return new DriveClient($this->repo, $pila);
    }

    private static function json(int $codice, array $corpo): Response
    {
        return new Response($codice, ['Content-Type' => 'application/json; charset=utf-8'], (string)json_encode($corpo));
    }

    private function collega(string $permessi = 'openid email https://www.googleapis.com/auth/drive.file'): void
    {
        $this->repo->upsert($this->docente, 'token-di-prova', $permessi, 'zzdrivestati@example.invalid');
    }

    /** @return array<string, mixed> */
    private function collegamento(): array
    {
        $meta = $this->repo->getMetadata($this->docente);
        $this->assertNotNull($meta, 'il collegamento c\'è');
        return $meta;
    }

    private function cartelleInCache(): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM teacher_drive_folder_cache WHERE teacher_id = ?');
        $st->execute([$this->docente]);
        return (int)$st->fetchColumn();
    }

    private function mettiCartelle(): void
    {
        $this->pdo->prepare('UPDATE teacher_drive_oauth SET drive_root_id = "radice-di-prima" WHERE teacher_id = ?')->execute([$this->docente]);
        $this->pdo->prepare(
            'INSERT INTO teacher_drive_folder_cache (teacher_id, folder_path, drive_folder_id) VALUES (?, "SCUOLA", "cartella-di-prima"), (?, "SCUOLA/MAT", "sotto-di-prima")'
        )->execute([$this->docente, $this->docente]);
    }

    #[Test]
    public function google_rifiuta_il_token_il_collegamento_va_ricollegato_e_non_si_riprova(): void
    {
        $this->collega();
        $client = $this->client(self::json(400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']));

        try {
            $client->getDriveFor($this->docente);
            $this->fail('doveva dire che il collegamento va rifatto');
        } catch (DriveDaRicollegare $e) {
            $this->assertSame(StatoDiDrive::ACCESSO_REVOCATO, $e->motivo);
        }
        $meta = $this->collegamento();
        $this->assertSame('da_ricollegare', $meta['stato']);
        $this->assertSame(StatoDiDrive::ACCESSO_REVOCATO, $meta['stato_motivo']);
        $this->assertNotNull($meta['stato_dal']);
        $this->assertCount(1, $this->richieste);

        // La seconda volta Google non si chiama: la coda del server simulato è vuota,
        // e una richiesta darebbe un'altra eccezione.
        try {
            $client->getDriveFor($this->docente);
            $this->fail('doveva dire di nuovo che il collegamento va rifatto');
        } catch (DriveDaRicollegare $e) {
            $this->assertSame(StatoDiDrive::ACCESSO_REVOCATO, $e->motivo);
        }
        $this->assertCount(1, $this->richieste, 'nessuna richiesta in più');
    }

    #[Test]
    public function un_rinnovo_senza_il_permesso_su_drive_va_ricollegato_per_i_permessi(): void
    {
        $this->collega();
        $client = $this->client(self::json(200, self::TOKEN_OK + ['scope' => 'openid email']));

        try {
            $client->getDriveFor($this->docente);
            $this->fail('senza il permesso su Drive il collegamento non serve');
        } catch (DriveDaRicollegare $e) {
            $this->assertSame(StatoDiDrive::PERMESSI_INSUFFICIENTI, $e->motivo);
        }
        $this->assertSame(StatoDiDrive::PERMESSI_INSUFFICIENTI, $this->collegamento()['stato_motivo']);
    }

    #[Test]
    public function un_rinnovo_con_il_permesso_lascia_il_collegamento_attivo(): void
    {
        $this->collega();
        $client = $this->client(self::json(200, self::TOKEN_OK + ['scope' => 'openid email https://www.googleapis.com/auth/drive.file']));

        $this->assertInstanceOf(GoogleDriveService::class, $client->getDriveFor($this->docente));
        $meta = $this->collegamento();
        $this->assertSame('attivo', $meta['stato']);
        $this->assertNull($meta['stato_motivo']);
    }

    #[Test]
    public function credenziali_dell_installazione_sbagliate_non_si_scaricano_sul_docente(): void
    {
        $this->collega();
        $client = $this->client(self::json(401, ['error' => 'invalid_client', 'error_description' => 'The OAuth client was not found.']));

        try {
            $client->getDriveFor($this->docente);
            $this->fail('doveva passare l\'errore così com\'è');
        } catch (DriveDaRicollegare) {
            $this->fail('non è un problema del docente');
        } catch (ClientException $e) {
            $this->assertSame(401, $e->getResponse()->getStatusCode());
        }
        $this->assertSame('attivo', $this->collegamento()['stato'], 'il docente non deve ricollegare niente');
    }

    #[Test]
    public function con_drive_spento_non_si_chiama_google(): void
    {
        $this->collega();
        $this->accendi(false);
        $client = $this->client();

        try {
            $client->getDriveFor($this->docente);
            $this->fail('con Drive spento non si prova');
        } catch (\RuntimeException $e) {
            $this->assertSame('drive_spento', $e->getMessage());
        }
        $this->assertCount(0, $this->richieste);
        $this->assertSame('attivo', $this->collegamento()['stato'], 'e il collegamento non cambia');
    }

    #[Test]
    public function un_nuovo_consenso_rimette_attivo_e_dimentica_le_cartelle_di_prima(): void
    {
        $this->collega();
        $this->mettiCartelle();
        $this->repo->segnaDaRicollegare($this->docente, StatoDiDrive::ACCESSO_REVOCATO);
        $this->assertSame('da_ricollegare', $this->collegamento()['stato']);

        $this->collega();

        $meta = $this->collegamento();
        $this->assertSame('attivo', $meta['stato']);
        $this->assertNull($meta['stato_motivo']);
        $this->assertNull($meta['stato_dal']);
        $this->assertNull($meta['drive_root_id'], 'la radice di prima può essere di un altro account');
        $this->assertSame(0, $this->cartelleInCache(), 'e così le sue cartelle');
    }

    #[Test]
    public function il_consenso_che_aggiorna_solo_i_permessi_rimette_attivo_e_tiene_le_cartelle(): void
    {
        $this->collega();
        $this->mettiCartelle();
        $this->repo->segnaDaRicollegare($this->docente, StatoDiDrive::PERMESSI_INSUFFICIENTI);

        $this->repo->updateScopeOnly($this->docente, 'openid email https://www.googleapis.com/auth/drive.file', null);

        $meta = $this->collegamento();
        $this->assertSame('attivo', $meta['stato']);
        $this->assertSame('radice-di-prima', $meta['drive_root_id'], 'stesso consenso, stesso account');
        $this->assertSame(2, $this->cartelleInCache());
    }

    #[Test]
    public function scollegare_toglie_anche_le_cartelle(): void
    {
        $this->collega();
        $this->mettiCartelle();
        $this->assertSame(2, $this->cartelleInCache());

        $this->repo->delete($this->docente);

        $this->assertNull($this->repo->getMetadata($this->docente));
        $this->assertSame(0, $this->cartelleInCache());
    }

    #[Test]
    public function il_giro_prende_i_collegamenti_attivi_e_salta_quelli_da_ricollegare(): void
    {
        $this->collega();
        $prima = $this->repo->collegamentiPerStato();
        $this->assertContains($this->docente, $this->repo->docentiDaSincronizzare(0, 1000));

        $this->repo->segnaDaRicollegare($this->docente, StatoDiDrive::ACCESSO_REVOCATO);

        $this->assertNotContains($this->docente, $this->repo->docentiDaSincronizzare(0, 1000));
        $dopo = $this->repo->collegamentiPerStato();
        $this->assertSame($prima['attivo'] - 1, $dopo['attivo']);
        $this->assertSame($prima['da_ricollegare'] + 1, $dopo['da_ricollegare']);
    }

    #[Test]
    public function il_primo_motivo_resta_finche_non_si_ricollega(): void
    {
        $this->collega();
        $this->repo->segnaDaRicollegare($this->docente, StatoDiDrive::PERMESSI_INSUFFICIENTI);
        $this->repo->segnaDaRicollegare($this->docente, StatoDiDrive::ACCESSO_REVOCATO);

        $this->assertSame(StatoDiDrive::PERMESSI_INSUFFICIENTI, $this->collegamento()['stato_motivo']);
    }

    /** @return array<string, mixed> */
    private function stato(): array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/teacher/drive/status.json';
        $_GET = [];
        $risposta = (new DriveController($this->client(), $this->repo))->status(new Request());
        $this->assertSame(200, $risposta->status);
        /** @var array<string, mixed> $corpo */
        $corpo = json_decode($risposta->body, true) ?: [];
        return $corpo;
    }

    #[Test]
    public function lo_stato_dice_l_installazione_e_il_collegamento(): void
    {
        $this->assertSame(['ok' => true, 'istanza' => 'acceso', 'connected' => false], $this->stato());

        $this->collega();
        $this->repo->segnaDaRicollegare($this->docente, StatoDiDrive::ACCESSO_REVOCATO);
        $corpo = $this->stato();
        $this->assertTrue($corpo['connected']);
        $this->assertSame('da_ricollegare', $corpo['stato']);
        $this->assertSame(StatoDiDrive::ACCESSO_REVOCATO, $corpo['motivo']);
        $this->assertNotEmpty($corpo['dal']);

        $this->accendi(false);
        $this->assertSame('spento', $this->stato()['istanza']);
        Config::set('drive.oauth.client_secret', '');
        Config::set('drive.enabled', true);
        $this->assertSame('guasto', $this->stato()['istanza']);
    }

    private function collegati(): \App\Core\Response
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/teacher/drive/connect';
        $_GET = [];
        return (new DriveController($this->client(), $this->repo))->connect(new Request());
    }

    #[Test]
    public function collegarsi_con_drive_spento_torna_al_cruscotto_e_con_drive_acceso_va_da_google(): void
    {
        $this->accendi(false);
        $spento = $this->collegati();
        $this->assertSame(302, $spento->status);
        $this->assertSame('/area-docente/dashboard?drive=non_disponibile', $spento->headers['Location'] ?? null);

        $this->accendi();
        $acceso = $this->collegati();
        $this->assertSame(302, $acceso->status);
        $verso = (string)($acceso->headers['Location'] ?? '');
        $this->assertStringStartsWith('https://accounts.google.com/', $verso);

        // Il consenso va chiesto con `prompt=consent`: è quello che fa rilasciare a
        // Google un nuovo refresh_token a chi aveva già autorizzato l'app. Il
        // vecchio `approval_prompt=force` Google lo ignora (15/9/2026: dopo
        // scollegare, il ricollegamento falliva).
        parse_str((string)parse_url($verso, PHP_URL_QUERY), $parametri);
        $this->assertSame('consent', $parametri['prompt'] ?? null);
        $this->assertSame('offline', $parametri['access_type'] ?? null);
        $this->assertArrayNotHasKey('approval_prompt', $parametri);
    }

    private function ritornoDaGoogle(string $permessi): \App\Core\Response
    {
        $_SESSION['drive_oauth_state'] = 'stato-di-prova';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/teacher/drive/callback?state=stato-di-prova&code=codice-di-prova';
        $_GET = ['state' => 'stato-di-prova', 'code' => 'codice-di-prova'];
        $client = $this->client(self::json(200, self::TOKEN_OK + ['refresh_token' => 'token-di-prova', 'scope' => $permessi]));
        return (new DriveController($client, $this->repo))->callback(new Request());
    }

    #[Test]
    public function un_consenso_senza_il_permesso_su_drive_non_si_salva(): void
    {
        $risposta = $this->ritornoDaGoogle('openid email');

        $this->assertSame('/area-docente/dashboard?drive=permessi', $risposta->headers['Location'] ?? null);
        $this->assertFalse($this->repo->isConnected($this->docente), 'nessun collegamento salvato');
        $this->assertCount(1, $this->richieste, "solo lo scambio del codice: l'email non si chiede");
    }

    private function cruscotto(): string
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/area-docente/dashboard';
        $_GET = [];
        // Una pagina intera, non una parziale: LayoutModesTest lasciava HTTP_X_PARTIAL=1
        // (seme 1789398653), e il cruscotto usciva senza la barra.
        unset($_SERVER['HTTP_X_PARTIAL']);
        $risposta = (new \App\Controllers\TeacherController())->dashboard(new Request());
        $this->assertSame(200, $risposta->status);
        return $risposta->body;
    }

    #[Test]
    public function il_cruscotto_offre_drive_secondo_l_installazione_e_il_collegamento(): void
    {
        $this->accendi(false);
        $spento = $this->cruscotto();
        $this->assertStringNotContainsString('id="fm-drive-section"', $spento, 'spento, e nessun collegamento: Drive non si offre');
        $this->assertStringContainsString('data-fm-drive="spento"', $spento, 'e la barra lo sa');

        $this->collega();
        $this->assertStringContainsString('id="fm-drive-section"', $this->cruscotto(), 'spento con un collegamento di prima: si deve poter scollegare');

        $this->repo->delete($this->docente);
        $this->accendi();
        $acceso = $this->cruscotto();
        $this->assertStringContainsString('id="fm-drive-section"', $acceso, 'acceso: Drive si offre');
        $this->assertStringContainsString('data-fm-drive="acceso"', $acceso);
    }

    #[Test]
    public function scollegarsi_si_puo_anche_con_drive_spento(): void
    {
        $this->collega();
        $this->accendi(false);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/teacher/drive/disconnect';

        $risposta = (new DriveController($this->client(), $this->repo))->disconnect(new Request(''));

        $this->assertSame(200, $risposta->status);
        $this->assertFalse($this->repo->isConnected($this->docente));
    }
}
