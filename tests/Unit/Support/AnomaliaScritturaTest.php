<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\Anomalia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Una riga che non si riesce a scrivere non deve far credere di averla scritta
 * (19/9/2026).
 *
 * Misurato in produzione: `anomalie.jsonl` era `pantedu:www-data` 0640, quindi
 * il PHP del container (`www-data`) lo leggeva ma non ci scriveva. E
 * `registra()` aggiornava **prima** lo stato del contenimento del rumore e solo
 * dopo scriveva la riga, con l'errore silenziato. Dal container, quindi, lo
 * stato avanzava e la riga no: il rilascio del 16/9 ha lasciato la sua traccia
 * in `anomalie.jsonl.stato` e nessuna riga nel registro. Per cinque minuti ogni
 * altra occorrenza dello stesso codice veniva poi «contata» e buttata.
 *
 * Adesso la riga si scrive prima, lo stato avanza solo se la scrittura è
 * riuscita, e se non riesce lo si dice in `error_log` (nel container: `docker
 * logs`). E i due file nascono 0660, così chi scrive dall'host (`pantedu`) e
 * chi scrive dal container (`www-data`, stesso gruppo) possono farlo entrambi.
 */
final class AnomaliaScritturaTest extends TestCase
{
    private string $cartella = '';
    private mixed $logsPrima = null;
    private string|false $errorLogPrima = false;
    private int $umaskPrima = 0;

    protected function setUp(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('da root ogni file è scrivibile: la prova non direbbe niente');
        }
        $this->cartella = sys_get_temp_dir() . '/pantedu-anomalia-scrittura-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        $this->logsPrima = Config::get('app.paths.logs');
        Config::set('app.paths.logs', $this->cartella);
        $this->errorLogPrima = ini_set('error_log', $this->cartella . '/php_errors.log');
        // La umask di chi lancia le prove decide i permessi dei file nuovi: la
        // si fissa a quella comune, con cui un file nasce 0644.
        $this->umaskPrima = umask(0022);
    }

    protected function tearDown(): void
    {
        umask($this->umaskPrima);
        Config::set('app.paths.logs', $this->logsPrima);
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @chmod($f, 0600);
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
    }

    /** @return array<string, mixed> */
    private function stato(): array
    {
        $letto = json_decode((string)@file_get_contents(Anomalia::percorso() . '.stato'), true);
        return \is_array($letto) ? $letto : [];
    }

    #[Test]
    public function se_la_riga_non_si_scrive_lo_stato_non_la_da_per_scritta_e_lo_si_dice(): void
    {
        $registro = Anomalia::percorso();
        file_put_contents($registro, '');
        chmod($registro, 0440); // come 0640 per chi sta solo nel gruppo

        Anomalia::registra('prova_scrittura', 'una riga che non può essere scritta');

        self::assertSame('', (string)file_get_contents($registro), 'nessuna riga: il file non è scrivibile');
        self::assertStringContainsString(
            'prova_scrittura',
            (string)@file_get_contents($this->cartella . '/php_errors.log'),
            'il fallimento si dice in error_log, non si silenzia',
        );
        // Lo stato dice «tentata», non «scritta»: la finestra è ripartita (o
        // error_log prenderebbe una riga per occorrenza) ma il conto no.
        self::assertSame(
            1,
            $this->stato()['prova_scrittura|prova_scrittura']['saltate'] ?? null,
            'l’occorrenza rimasta senza riga resta contata',
        );

        // Riparato il permesso, la prima riga che riesce a scriversi porta con
        // sé il conto di quelle che non ci sono riuscite.
        chmod($registro, 0660);
        $stato = $this->stato();
        $stato['prova_scrittura|prova_scrittura']['ultima'] = time() - 600; // fuori finestra
        file_put_contents(Anomalia::percorso() . '.stato', json_encode($stato));
        Anomalia::registra('prova_scrittura', 'la stessa, dopo la riparazione');

        $righe = Anomalia::recenti();
        self::assertSame(['prova_scrittura'], array_column($righe, 'codice'));
        self::assertSame(1, $righe[0]['saltate'] ?? null, 'la riga dichiara l’occorrenza persa');
        self::assertSame(0, $this->stato()['prova_scrittura|prova_scrittura']['saltate'] ?? null);
    }

    /**
     * Il contenimento del rumore vale anche per il messaggio che dice che il
     * rumore non si riesce a scrivere (20/9/2026).
     *
     * Regressione introdotta il 19/9 e misurata da una revisione: spostando
     * l'aggiornamento dello stato dopo la scrittura della riga, un registro non
     * scrivibile faceva sì che il file `.stato` non nascesse mai. Ogni
     * occorrenza dello stesso codice ripassava dal ramo di scrittura, falliva,
     * e stampava la sua riga in `error_log`: cinquanta chiamate, cinquanta
     * righe, e la regola 2 della classe («Non allaga») smetteva di valere
     * proprio nel caso in cui la produzione si trovava.
     */
    #[Test]
    public function con_il_registro_non_scrivibile_error_log_non_allaga(): void
    {
        $registro = Anomalia::percorso();
        file_put_contents($registro, '');
        chmod($registro, 0440);

        for ($i = 0; $i < 50; $i++) {
            Anomalia::registra('prova_diluvio', "occorrenza {$i}");
        }

        $errori = (string)@file_get_contents($this->cartella . '/php_errors.log');
        self::assertSame(
            1,
            substr_count($errori, 'prova_diluvio'),
            'una riga per finestra, non una per occorrenza',
        );
        self::assertSame('', (string)file_get_contents($registro), 'nessuna riga: il file non è scrivibile');
        self::assertSame(
            50,
            $this->stato()['prova_diluvio|prova_diluvio']['saltate'] ?? null,
            'tutte e cinquanta restano contate: nessuna è stata buttata',
        );
    }

    #[Test]
    public function con_il_registro_scrivibile_la_riga_c_e_e_lo_stato_avanza(): void
    {
        // Controprova: nel caso normale non cambia niente, compreso il
        // contenimento del rumore (la seconda occorrenza si conta).
        Anomalia::registra('prova_normale', 'prima occorrenza');
        Anomalia::registra('prova_normale', 'seconda, dentro la finestra');

        self::assertSame(['prova_normale'], array_column(Anomalia::recenti(), 'codice'));
        self::assertSame(1, $this->stato()['prova_normale|prova_normale']['saltate'] ?? null);
        self::assertStringNotContainsString(
            'prova_normale',
            (string)@file_get_contents($this->cartella . '/php_errors.log'),
        );
    }

    #[Test]
    public function il_registro_e_il_suo_stato_nascono_scrivibili_dal_gruppo(): void
    {
        Anomalia::registra('prova_permessi', 'la prima riga crea il file');

        self::assertSame('0660', sprintf('%04o', fileperms(Anomalia::percorso()) & 0o777));
        self::assertSame('0660', sprintf('%04o', fileperms(Anomalia::percorso() . '.stato') & 0o777));
    }
}
