<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Core\Config;
use App\Services\AdminNotificationsService;
use App\Services\InfrastructureMonitorService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La dashboard dell'infrastruttura conta le domande di iscrizione dal file
 * dove stanno (24/9/2026).
 *
 * Le contava con `SELECT COUNT(*) FROM registrations`, una tabella che nessun
 * codice scrive: «Reg. pending» diceva 0 anche con la coda piena. Nei due
 * versi: con due domande nel file dice 2, senza file dice 0.
 *
 * E dalla revisione dello stesso giorno: le domande scadute non si contano,
 * né qui né nel riepilogo dell'amministratore (AdminNotificationsService),
 * anche se il giro notturno non le ha ancora cancellate. Si contano quelle
 * che l'approvazione accetta.
 */
final class MonitorContaLeDomandeVereTest extends TestCase
{
    private string $cartella;
    private mixed $percorsoPrima = null;
    private mixed $databasePrima = null;

    protected function setUp(): void
    {
        $this->percorsoPrima = Config::get('auth.paths.registrations');
        $this->databasePrima = Config::get('database.enabled');
        // Senza database: il conteggio non deve dipenderne.
        Config::set('database.enabled', false);
        $this->cartella = sys_get_temp_dir() . '/pantedu_monitor_' . bin2hex(random_bytes(5));
        mkdir($this->cartella, 0o700, true);
    }

    protected function tearDown(): void
    {
        Config::set('auth.paths.registrations', $this->percorsoPrima);
        Config::set('database.enabled', $this->databasePrima);
        foreach (glob($this->cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella);
    }

    #[Test]
    public function con_due_domande_nel_file_ne_conta_due_e_senza_file_zero(): void
    {
        $file = $this->cartella . '/registrations.json';
        file_put_contents($file, json_encode(['pending' => [
            ['id' => 'a', 'created' => date('Y-m-d H:i:s')],
            ['id' => 'b', 'created' => date('Y-m-d H:i:s')],
        ]]));
        Config::set('auth.paths.registrations', $file);

        self::assertSame(2, (new InfrastructureMonitorService())->snapshot()['counts']['pending_regs']);

        unlink($file);
        self::assertSame(0, (new InfrastructureMonitorService())->snapshot()['counts']['pending_regs']);
    }

    /** Una scaduta e due recenti: se ne contano due. */
    private function unaScadutaEDueRecenti(): string
    {
        $file = $this->cartella . '/registrations.json';
        file_put_contents($file, json_encode(['pending' => [
            ['id' => 'vecchia', 'created' => date('Y-m-d H:i:s', strtotime('-31 days'))],
            ['id' => 'a', 'created' => date('Y-m-d H:i:s', strtotime('-29 days'))],
            ['id' => 'b', 'created' => date('Y-m-d H:i:s')],
        ]]));
        return $file;
    }

    #[Test]
    public function il_monitor_non_conta_le_domande_scadute(): void
    {
        Config::set('auth.paths.registrations', $this->unaScadutaEDueRecenti());

        self::assertSame(2, (new InfrastructureMonitorService())->snapshot()['counts']['pending_regs']);
    }

    #[Test]
    public function il_riepilogo_dell_amministratore_conta_quelle_nel_termine(): void
    {
        $servizio = new AdminNotificationsService(
            registrationsPath: $this->unaScadutaEDueRecenti(),
            accessLogPath:     $this->cartella . '/non-c-e.json',
            blockedCredsPath:  $this->cartella . '/non-c-e.json',
            blockedIpsPath:    $this->cartella . '/non-c-e.json',
        );

        self::assertSame(2, $servizio->summary()['pending_registrations']);

        // L'altro verso: senza file, zero.
        unlink($this->cartella . '/registrations.json');
        self::assertSame(0, $servizio->summary()['pending_registrations']);
    }
}
