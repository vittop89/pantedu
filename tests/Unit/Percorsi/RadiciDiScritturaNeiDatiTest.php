<?php

declare(strict_types=1);

namespace Tests\Unit\Percorsi;

use App\Controllers\SecurityAdminController;
use App\Controllers\TeacherDrawioLibraryController;
use App\Core\Config;
use App\Services\PrintInfoService;
use App\Services\TexBuilder\VersionPicker;
use PHPUnit\Framework\TestCase;

/**
 * Le radici di SCRITTURA dell'applicazione stanno nella cartella dei dati
 * d'istanza, non nella radice del repository.
 *
 * Dall'8 settembre 2026 l'applicazione gira in un container e quella radice è
 * l'immagine, che `docker/Dockerfile` dichiara immutabile. Una scrittura lì
 * dentro riesce — `mkdir` e `file_put_contents` rispondono `true`, l'interfaccia
 * dice «salvato» — e sparisce al rilascio successivo insieme allo strato
 * scrivibile di overlayfs. Nessun errore, nessuna traccia.
 *
 * Ogni prova qui sotto ha il suo verso opposto: non basta vedere il percorso
 * giusto, bisogna vedere che quello vecchio non c'è più.
 */
final class RadiciDiScritturaNeiDatiTest extends TestCase
{
    private string $dati;
    private string $repository;
    private mixed $datiPrima;
    private mixed $envPrima;

    protected function setUp(): void
    {
        $this->repository = \dirname(__DIR__, 3);
        $this->dati = sys_get_temp_dir() . '/pantedu-radici-' . bin2hex(random_bytes(6));
        mkdir($this->dati, 0775, true);
        $this->datiPrima = Config::get('app.paths.data_base');
        $this->envPrima  = $_ENV['PANTEDU_DATA_PATH'] ?? null;
        Config::set('app.paths.data_base', $this->dati);
    }

    protected function tearDown(): void
    {
        // Se la radice versionata torna scrivibile, la sonda finisce nel
        // repository: misurato contro il codice di prima, che creava davvero
        // `img/sonda.png`. Una prova non lascia niente in giro nemmeno quando
        // è lei a fallire.
        @unlink($this->repository . '/img/sonda.png');
        Config::set('app.paths.data_base', $this->datiPrima);
        if ($this->envPrima === null) {
            unset($_ENV['PANTEDU_DATA_PATH']);
        } else {
            $_ENV['PANTEDU_DATA_PATH'] = $this->envPrima;
        }
        $this->rimuovi($this->dati);
    }

    /**
     * La whitelist di `FileService`: prima le radici nascevano TUTTE da
     * `dirname(__DIR__, 2)`. È la causa comune di diversi punti a valle
     * (`/files/save-latex`, le stampe), e si corregge in un posto solo.
     * (`/files/save-tex` stava in questo elenco fino al 20/9/2026: la rotta
     * non c'è più, era il giro morto del vecchio editor TikZ.)
     *
     * E stanno sotto `storage/`, non accanto: in produzione `<dati>` è di root
     * e solo `<dati>/storage` è di www-data (docs/ops/ripristino.md), nel
     * salvataggio notturno e nel controllo d'avvio del container. Il
     * 20/9/2026 si è misurato che una radice fratello non è scrivibile da chi
     * serve le pagine, e la scrittura falliva rispondendo «salvato».
     */
    public function testLeRadiciDiScritturaStannoSottoStorageNeiDati(): void
    {
        $_ENV['PANTEDU_DATA_PATH'] = $this->dati;
        $cfg = require $this->repository . '/app/Config/filesystem.php';

        self::assertNotSame([], $cfg['roots'], 'la whitelist non deve essere vuota');
        foreach ($cfg['roots'] as $etichetta => $percorso) {
            if (in_array($etichetta, $cfg['roots_sola_lettura'], true)) {
                continue;
            }
            self::assertStringStartsWith(
                $this->dati . '/storage/',
                $percorso,
                "la radice «{$etichetta}» deve stare sotto storage/, nella cartella dei dati",
            );
        }
    }

    public function testLaRadiceVersionataRestaNellImmagineEdEDiSolaLettura(): void
    {
        // `img/` è l'unica delle radici versionata in git (18 file) e lo
        // serve il web server: puntarlo ai dati aveva svuotato
        // `GET /files/list?directory=img` — la whitelist governa anche le
        // LETTURE — misurato: da 15 voci a 0.
        $_ENV['PANTEDU_DATA_PATH'] = $this->dati;
        $cfg = require $this->repository . '/app/Config/filesystem.php';

        self::assertSame($this->repository . '/img', $cfg['roots']['img']);
        self::assertContains('img', $cfg['roots_sola_lettura']);

        $files = new \App\Services\FileService($cfg);
        self::assertNotSame([], $files->listDirectory('img'), 'le immagini versionate si leggono');
    }

    public function testSullaRadiceVersionataLaScritturaSiRifiuta(): void
    {
        // Il verso opposto: la trappola che si voleva togliere — una `delete`
        // su `/img/…` che cancella un file dell'immagine — la toglie il
        // codice, non il percorso.
        $_ENV['PANTEDU_DATA_PATH'] = $this->dati;
        $files = new \App\Services\FileService(require $this->repository . '/app/Config/filesystem.php');

        foreach ([
            'save' => fn() => $files->save('img', 'sonda.png', 'x', 'image'),
            'delete' => fn() => $files->delete('img', 'sonda.png'),
            'clearRootContents' => fn() => $files->clearRootContents('img'),
        ] as $nome => $azione) {
            try {
                $azione();
                self::fail("«{$nome}» su una radice versionata doveva essere rifiutata");
            } catch (\RuntimeException $e) {
                self::assertSame('read_only_root', $e->getMessage());
            }
        }

        // Controprova: la stessa scrittura su una radice dei dati riesce.
        self::assertFileExists($files->save('temp', 'sonda.tex', 'x', 'tex'));
    }

    public function testSenzaVariabileLeRadiciRestanoNelRepository(): void
    {
        // Il verso opposto, e non è un dettaglio: in sviluppo locale
        // PANTEDU_DATA_PATH è vuota e tutto deve continuare a funzionare come
        // prima, altrimenti la correzione rompe il banco di prova.
        unset($_ENV['PANTEDU_DATA_PATH']);
        $cfg = require $this->repository . '/app/Config/filesystem.php';

        foreach ($cfg['roots'] as $etichetta => $percorso) {
            self::assertStringStartsWith(
                $this->repository,
                $percorso,
                "senza la variabile, «{$etichetta}» torna nel repository",
            );
        }
    }

    public function testIlPreamboloDelleVerificheStaNeiDati(): void
    {
        // Sola copia, nessun riscontro nel database: se sparisce, il preambolo
        // torna a quello di serie e nessuno sa perché.
        self::assertSame(
            $this->dati . '/storage/data/verifica_preamble.tex',
            VersionPicker::overrideFilePath(),
        );
    }

    public function testIlPreamboloNonStaPiuNelRepository(): void
    {
        self::assertStringStartsNotWith(
            $this->repository . '/storage',
            VersionPicker::overrideFilePath(),
        );
    }

    public function testLeSoglieDiSicurezzaStannoNeiDati(): void
    {
        // `SecurityAdminController` partiva da `app.paths.base`, che è SEMPRE
        // la radice del repository (app/Config/app.php), mai `data_base`.
        $percorsi = $this->percorsiPrivati(new SecurityAdminController(), [
            'alertConfigPath', 'accessLogPath',
        ]);

        foreach ($percorsi as $nome => $percorso) {
            self::assertStringStartsWith(
                $this->dati . '/storage/',
                $percorso,
                "«{$nome}» deve stare sotto storage/, nella cartella dei dati",
            );
        }
    }

    public function testLeSoglieNonSalvateSonoUnErrore(): void
    {
        // Il guasto misurato il 20/9/2026: con la cartella dei dati non
        // scrivibile il pannello rispondeva 200 `{"ok":true}` e il file non
        // nasceva. Qui la cartella non si può creare perché al suo posto c'è
        // un FILE: `mkdir` fallisce per chiunque, anche per root — la stessa
        // prova vale sul portatile e sul runner.
        $ostacolo = $this->dati . '/storage/ostacolo';
        mkdir(\dirname($ostacolo), 0775, true);
        file_put_contents($ostacolo, 'non sono una cartella');

        $r = (new SecurityAdminController(alertConfigPath: $ostacolo . '/alerts/config.json'))
            ->setConfig($this->richiestaConSoglia('42'));

        self::assertSame(500, $r->status);
        $corpo = json_decode((string)$r->body, true);
        self::assertSame('persist_failed', $corpo['error'] ?? null);
        self::assertTrue(empty($corpo['ok']), 'una scrittura fallita non risponde «ok»');
    }

    public function testLaControprovaDelleSoglieSalvate(): void
    {
        // Il verso opposto: con una cartella scrivibile il 200 c'è, e il file
        // pure — con dentro il valore che si è mandato. Senza questa metà, la
        // prova di sopra la supererebbe una funzione che risponde sempre 500.
        $dove = $this->dati . '/storage/security/alerts/config.json';

        $r = (new SecurityAdminController(alertConfigPath: $dove))
            ->setConfig($this->richiestaConSoglia('42'));

        self::assertSame(200, $r->status);
        self::assertFileExists($dove);
        $salvato = json_decode((string)file_get_contents($dove), true);
        self::assertSame(
            42,
            $salvato['security_alerts']['excessive_access']['threshold_per_section'] ?? null,
        );
    }

    /**
     * Il registro degli accessi si legge dove lo scrive chi lo scrive.
     *
     * Misurato il 20/9/2026: cinque accessi falliti scritti da `AccessLogger`,
     * e `failedLogins24h()` ne contava zero, perché guardava
     * `<dati>/log/data/access_log.json` — un percorso che dalla Phase 25.J non
     * scrive più nessuno. Il riepilogo per l'amministratore diceva «nessun
     * accesso fallito» mentre qualcuno provava le password.
     */
    public function testIlRiepilogoContaGliAccessiFallitiCheSonoStatiScritti(): void
    {
        $registro = \App\Core\AccessLogger::percorsoDelRegistro($this->dati);
        self::assertSame($this->dati . '/storage/logs/access_log.json', $registro);

        // Gli accessi falliti si scrivono come li scrive davvero
        // `AuthController::login()`: `login_failed:<motivo>`, via AccessLogger.
        $logger = new \App\Core\AccessLogger($this->dati . '/storage/logs');
        for ($i = 0; $i < 5; $i++) {
            $logger->logAccess('malintenzionato', 'unknown', '/', 'login_failed:bad_password');
        }
        // Controprova: un accesso riuscito non deve entrare nel conto.
        $logger->logAccess('docente', 'teacher', '/', 'access');

        $riepilogo = new \App\Services\AdminNotificationsService(
            registrationsPath: $this->dati . '/storage/data/registrations.json',
            accessLogPath:     $registro,
            blockedCredsPath:  $this->dati . '/storage/security/blocked_credentials.json',
            blockedIpsPath:    $this->dati . '/storage/security/blocked_ips.json',
        );
        $metodo = new \ReflectionMethod($riepilogo, 'failedLogins24h');

        self::assertSame(5, $metodo->invoke($riepilogo));
    }

    public function testITreLettoriDelRegistroGuardanoLoStessoFile(): void
    {
        // Il percorso lo dice `AccessLogger`, non se lo costruisce ognuno per
        // conto suo: se i tre divergono di nuovo, questa prova cade.
        $atteso = \App\Core\AccessLogger::percorsoDelRegistro($this->dati);

        $letti = [
            'notifiche'  => $this->percorsiPrivati(
                \App\Services\AdminNotificationsService::default(),
                ['accessLogPath'],
            )['accessLogPath'],
            'statistiche' => $this->percorsiPrivati(
                \App\Services\AdminAnalyticsService::default(),
                ['accessLogPath'],
            )['accessLogPath'],
            'sicurezza'  => $this->percorsiPrivati(
                new SecurityAdminController(),
                ['accessLogPath'],
            )['accessLogPath'],
            'anomalie'   => $this->percorsiPrivati(
                \App\Services\AnomalyDetectionService::default(),
                ['accessLogPath'],
            )['accessLogPath'],
        ];

        foreach ($letti as $chi => $percorso) {
            self::assertSame($atteso, $percorso, "«{$chi}» legge un registro diverso da quello scritto");
        }
    }

    private function richiestaConSoglia(string $valore): \App\Core\Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/api/admin/security/config';
        $_POST = ['ea_threshold_per_section' => $valore];
        $req = new \App\Core\Request();
        $_POST = [];

        return $req;
    }

    public function testLeSoglieDiSicurezzaAccettanoAncoraUnPercorsoEsplicito(): void
    {
        // Controprova: chi passa il percorso deve continuare a vincere,
        // altrimenti le prove d'integrazione non possono isolarsi.
        $mio = $this->dati . '/altrove/config.json';
        $percorsi = $this->percorsiPrivati(
            new SecurityAdminController(alertConfigPath: $mio),
            ['alertConfigPath'],
        );

        self::assertSame($mio, $percorsi['alertConfigPath']);
    }

    public function testLeLibrerieDrawioStannoNeiDati(): void
    {
        // In git di `storage/templates/drawio` non c'è niente: il file è
        // l'unica copia, e non ha nessun riscontro nel database. In servizio
        // dal 16/9/2026, cioè tutto dentro l'era del container.
        $metodo = new \ReflectionMethod(TeacherDrawioLibraryController::class, 'teacherDir');
        $dir = $metodo->invoke(new TeacherDrawioLibraryController(), 77);

        self::assertSame($this->dati . '/storage/templates/drawio/teachers/77', $dir);
    }

    public function testLePreferenzeDiStampaStannoNeiDatiESenzaCreareNiente(): void
    {
        // Ripiego usato solo quando il database non risponde; ma il percorso
        // lo calcolava anche la lettura, e il `mkdir` che stava lì dentro è
        // ciò che ha creato `storage/data/print_info` vuota in produzione.
        $metodo = new \ReflectionMethod(PrintInfoService::class, 'jsonPath');
        $percorso = $metodo->invoke(new PrintInfoService(), 'docente.uno');

        self::assertSame(
            $this->dati . '/storage/data/print_info/docente.uno.json',
            $percorso,
        );
        self::assertDirectoryDoesNotExist(
            $this->dati . '/storage/data/print_info',
            'chiedere il percorso non deve creare la cartella',
        );
    }

    /**
     * @param  list<string>         $nomi
     * @return array<string,string>
     */
    private function percorsiPrivati(object $oggetto, array $nomi): array
    {
        $fuori = [];
        foreach ($nomi as $nome) {
            $p = new \ReflectionProperty($oggetto, $nome);
            $fuori[$nome] = (string) $p->getValue($oggetto);
        }
        return $fuori;
    }

    private function rimuovi(string $dir): void
    {
        if (!is_dir($dir)) {
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
