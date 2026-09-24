<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use App\Services\Ops\UnitaInstallate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il confronto fra le unità del repository e quelle installate, con due
 * cartelle finte (23/9/2026).
 *
 * Dall'8 settembre 2026 il rilascio non installa più le unità di
 * `tools/systemd`, e nessuno confrontava il server con il repository. Qui ogni
 * risposta si prova nei due versi: scatta quando la cartella installata ha lo
 * scarto, tace quando è una copia fedele. E il caso che conta di più: «non ho
 * potuto confrontare» non deve mai uscire come «uguale».
 */
final class UnitaInstallateTest extends TestCase
{
    private string $radice = '';
    private string $repo = '';
    private string $etc = '';

    protected function setUp(): void
    {
        $this->radice = sys_get_temp_dir() . '/pantedu-unita-' . bin2hex(random_bytes(6));
        $this->repo = $this->radice . '/repo';
        $this->etc = $this->radice . '/etc';
        mkdir($this->repo . '/docker.service.d', 0700, true);
        mkdir($this->etc, 0700, true);

        $this->scrivi($this->repo, 'pantedu-pulizia.service', "[Service]\nExecStart=/bin/true\n");
        $this->scrivi($this->repo, 'pantedu-pulizia.timer', "[Timer]\nOnCalendar=03:15\n\n"
            . "[Install]\nWantedBy=timers.target\n");
        $this->scrivi($this->repo, 'pantedu-rilascio.path', "[Path]\nPathExists=/x\n\n"
            . "[Install]\nWantedBy=multi-user.target\n");
        $this->scrivi($this->repo, 'docker.service.d/pantedu-dopo.conf', "[Unit]\nAfter=mariadb.service\n");
        // Gli script non sono unità: non si confrontano.
        $this->scrivi($this->repo, 'pantedu-trigger.sh', "#!/bin/bash\n");
    }

    protected function tearDown(): void
    {
        $this->svuota($this->radice);
    }

    private function scrivi(string $base, string $rel, string $testo): void
    {
        @mkdir(\dirname($base . '/' . $rel), 0700, true);
        file_put_contents($base . '/' . $rel, $testo);
    }

    /** L'installazione come la lascerebbe il vecchio rilascio: copie e timer accesi. */
    private function installaTutto(): void
    {
        $tutte = [
            'pantedu-pulizia.service',
            'pantedu-pulizia.timer',
            'pantedu-rilascio.path',
            'docker.service.d/pantedu-dopo.conf',
        ];
        foreach ($tutte as $rel) {
            $this->scrivi($this->etc, $rel, (string)file_get_contents($this->repo . '/' . $rel));
        }
        mkdir($this->etc . '/timers.target.wants', 0700, true);
        mkdir($this->etc . '/multi-user.target.wants', 0700, true);
        symlink($this->etc . '/pantedu-pulizia.timer', $this->etc . '/timers.target.wants/pantedu-pulizia.timer');
        symlink($this->etc . '/pantedu-rilascio.path', $this->etc . '/multi-user.target.wants/pantedu-rilascio.path');
    }

    private function svuota(string $cartella): void
    {
        if (!is_dir($cartella) || is_link($cartella)) {
            @unlink($cartella);
            return;
        }
        @chmod($cartella, 0700);
        foreach (scandir($cartella) ?: [] as $v) {
            if ($v === '.' || $v === '..') {
                continue;
            }
            $p = $cartella . '/' . $v;
            if (is_dir($p) && !is_link($p)) {
                $this->svuota($p);
            } else {
                @chmod($p, 0600);
                @unlink($p);
            }
        }
        @rmdir($cartella);
    }

    #[Test]
    public function una_copia_fedele_e_tutta_uguale_e_accesa(): void
    {
        $this->installaTutto();

        $u = UnitaInstallate::confronta($this->repo, $this->etc);

        self::assertTrue($u['confrontabile'], $u['perche']);
        self::assertSame(
            [
                'docker.service.d/pantedu-dopo.conf',
                'pantedu-pulizia.service',
                'pantedu-pulizia.timer',
                'pantedu-rilascio.path',
            ],
            $u['uguali'],
            'gli script (.sh) non sono unità e non entrano nel confronto',
        );
        self::assertSame([], $u['diverse']);
        self::assertSame([], $u['mancanti']);
        self::assertSame([], $u['spente']);
        self::assertSame([], $u['orfane']);
        self::assertSame([], $u['illeggibili']);
        self::assertSame(2, $u['accese'], 'il timer e il path');
    }

    #[Test]
    public function un_byte_diverso_e_una_diversa(): void
    {
        $this->installaTutto();
        file_put_contents($this->etc . '/pantedu-pulizia.service', "[Service]\nExecStart=/bin/false\n");
        file_put_contents($this->etc . '/docker.service.d/pantedu-dopo.conf', "[Unit]\n");

        $u = UnitaInstallate::confronta($this->repo, $this->etc);

        self::assertSame(['docker.service.d/pantedu-dopo.conf', 'pantedu-pulizia.service'], $u['diverse']);
        self::assertNotContains('pantedu-pulizia.service', $u['uguali']);
    }

    #[Test]
    public function una_unita_che_manca_e_non_installata_e_non_anche_spenta(): void
    {
        $this->installaTutto();
        unlink($this->etc . '/timers.target.wants/pantedu-pulizia.timer');
        unlink($this->etc . '/pantedu-pulizia.timer');

        $u = UnitaInstallate::confronta($this->repo, $this->etc);

        self::assertSame(['pantedu-pulizia.timer'], $u['mancanti']);
        self::assertSame([], $u['spente'], 'contarla due volte non direbbe niente di più');
    }

    #[Test]
    public function un_timer_installato_senza_collegamento_e_spento(): void
    {
        $this->installaTutto();
        unlink($this->etc . '/timers.target.wants/pantedu-pulizia.timer');

        $u = UnitaInstallate::confronta($this->repo, $this->etc);

        self::assertSame(['pantedu-pulizia.timer'], $u['spente']);
        self::assertContains('pantedu-pulizia.timer', $u['uguali'], 'il contenuto è giusto: è spento, non diverso');
        self::assertSame(1, $u['accese'], 'resta acceso il path');
    }

    #[Test]
    public function il_collegamento_si_cerca_nel_bersaglio_dichiarato(): void
    {
        // Il path dichiara multi-user.target: un collegamento in timers.target.wants
        // non lo accende. Se il controllo guardasse un posto fisso, lo darebbe acceso.
        $this->installaTutto();
        unlink($this->etc . '/multi-user.target.wants/pantedu-rilascio.path');
        symlink($this->etc . '/pantedu-rilascio.path', $this->etc . '/timers.target.wants/pantedu-rilascio.path');

        $u = UnitaInstallate::confronta($this->repo, $this->etc);

        self::assertSame(['pantedu-rilascio.path'], $u['spente']);
    }

    #[Test]
    public function una_unita_nostra_che_il_repository_non_ha_piu_e_orfana(): void
    {
        $this->installaTutto();
        $this->scrivi($this->etc, 'pantedu-vecchia@crowdsec.timer', "[Timer]\n");
        $this->scrivi($this->etc, 'docker.service.d/pantedu-vecchio.conf', "[Unit]\n");
        // Le unità del sistema stanno nella stessa cartella e non ci riguardano.
        $this->scrivi($this->etc, 'ssh.service', "[Service]\n");
        $this->scrivi($this->etc, 'docker.service.d/override.conf', "[Service]\n");
        // Un'unità mascherata è un collegamento a /dev/null: non è un file installato.
        symlink('/dev/null', $this->etc . '/pantedu-mascherata.service');

        $u = UnitaInstallate::confronta($this->repo, $this->etc);

        self::assertSame(['pantedu-vecchia@crowdsec.timer', 'docker.service.d/pantedu-vecchio.conf'], $u['orfane']);
    }

    #[Test]
    public function la_cartella_installata_che_non_ce_non_e_un_uguale(): void
    {
        $u = UnitaInstallate::confronta($this->repo, $this->radice . '/non-esiste');

        self::assertFalse($u['confrontabile']);
        self::assertStringContainsString('non c’è', $u['perche']);
        self::assertSame([], $u['uguali']);
    }

    #[Test]
    public function un_repository_senza_unita_non_e_un_uguale(): void
    {
        // Zero unità da confrontare darebbe zero differenze: il verde su niente.
        $vuoto = $this->radice . '/vuoto';
        mkdir($vuoto);
        $this->installaTutto();

        $u = UnitaInstallate::confronta($vuoto, $this->etc);

        self::assertFalse($u['confrontabile']);
        self::assertStringContainsString('nessuna unità', $u['perche']);
    }

    #[Test]
    public function un_file_che_non_si_legge_non_ho_potuto_confrontarlo(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('da root ogni file si legge: la prova non direbbe niente');
        }
        $this->installaTutto();
        chmod($this->etc . '/pantedu-pulizia.service', 0000);

        $u = UnitaInstallate::confronta($this->repo, $this->etc);

        self::assertSame(['pantedu-pulizia.service'], $u['illeggibili']);
        self::assertNotContains('pantedu-pulizia.service', $u['uguali']);
        self::assertNotContains('pantedu-pulizia.service', $u['diverse']);
    }

    #[Test]
    public function una_cartella_installata_che_non_si_legge_non_e_un_uguale(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('da root ogni cartella si legge: la prova non direbbe niente');
        }
        $this->installaTutto();
        chmod($this->etc, 0000);

        $u = UnitaInstallate::confronta($this->repo, $this->etc);

        chmod($this->etc, 0700);
        self::assertFalse($u['confrontabile']);
        self::assertStringContainsString('non si legge', $u['perche']);
    }
}
