<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Drive;

use App\Services\Drive\StatoDiDrive;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lo stato di Drive (ADR-038, 14/9/2026): quello dell'installazione, il
 * permesso concesso da Google e il rifiuto che tocca al docente. Ogni
 * decisione nei due versi.
 */
final class StatoDiDriveTest extends TestCase
{
    private const CREDENZIALI = ['client_id' => 'id-di-prova', 'client_secret' => 'segreto-di-prova'];

    #[Test]
    public function l_installazione_e_spenta_finche_drive_enabled_non_e_vero(): void
    {
        $this->assertSame(StatoDiDrive::SPENTO, StatoDiDrive::dellInstallazione([]), 'senza configurazione');
        $this->assertSame(StatoDiDrive::SPENTO, StatoDiDrive::dellInstallazione(['oauth' => self::CREDENZIALI]), 'le credenziali da sole non accendono');
        foreach ([false, 'false', '0', '', 'no'] as $valore) {
            $this->assertSame(
                StatoDiDrive::SPENTO,
                StatoDiDrive::dellInstallazione(['enabled' => $valore, 'oauth' => self::CREDENZIALI]),
                var_export($valore, true),
            );
        }
    }

    #[Test]
    public function accesa_con_le_credenziali_e_guasta_senza(): void
    {
        foreach ([true, 'true', '1', 'yes'] as $valore) {
            $this->assertSame(
                StatoDiDrive::ACCESO,
                StatoDiDrive::dellInstallazione(['enabled' => $valore, 'oauth' => self::CREDENZIALI]),
                var_export($valore, true),
            );
        }
        $this->assertSame(StatoDiDrive::GUASTO, StatoDiDrive::dellInstallazione(['enabled' => true]));
        $this->assertSame(
            StatoDiDrive::GUASTO,
            StatoDiDrive::dellInstallazione(['enabled' => true, 'oauth' => ['client_id' => 'id-di-prova', 'client_secret' => '']]),
            'ne basta una che manca',
        );
    }

    #[Test]
    public function le_credenziali_mancanti_sono_solo_quelle_vuote(): void
    {
        $this->assertSame([], StatoDiDrive::credenzialiMancanti(self::CREDENZIALI), 'tutte e due presenti');
        $this->assertSame(
            ['GOOGLE_DRIVE_CLIENT_SECRET'],
            StatoDiDrive::credenzialiMancanti(['client_id' => 'id-di-prova', 'client_secret' => '  ']),
            'uno spazio non è un segreto',
        );
        $this->assertSame(
            ['GOOGLE_DRIVE_CLIENT_ID', 'GOOGLE_DRIVE_CLIENT_SECRET'],
            StatoDiDrive::credenzialiMancanti([]),
            'configurazione assente',
        );
    }

    #[Test]
    public function il_permesso_su_drive_si_riconosce_fra_quelli_concessi(): void
    {
        $this->assertTrue(StatoDiDrive::permessoConcesso('openid email https://www.googleapis.com/auth/drive.file'));
        $this->assertTrue(StatoDiDrive::permessoConcesso('https://www.googleapis.com/auth/drive.file'));
    }

    #[Test]
    public function senza_il_permesso_su_drive_no_e_nemmeno_con_un_nome_che_gli_somiglia(): void
    {
        $this->assertFalse(StatoDiDrive::permessoConcesso('openid email'), 'casella di Drive tolta');
        $this->assertFalse(StatoDiDrive::permessoConcesso(''), 'nessun permesso');
        $this->assertFalse(StatoDiDrive::permessoConcesso('https://www.googleapis.com/auth/drive.readonly'), 'solo lettura non basta');
        $this->assertFalse(StatoDiDrive::permessoConcesso('https://www.googleapis.com/auth/drive.file.extra'), 'un prefisso non basta');
    }

    /** Un'eccezione vera di Guzzle, come la produce il rinnovo del token. */
    private static function rifiuto(int $codice, string $corpo): ClientException
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response($codice, ['Content-Type' => 'application/json; charset=utf-8'], $corpo),
        ]))]);
        try {
            $client->post('https://oauth2.googleapis.com/token');
        } catch (ClientException $e) {
            return $e;
        }
        self::fail('il server simulato doveva rifiutare');
    }

    #[Test]
    public function invalid_grant_tocca_al_docente(): void
    {
        $e = self::rifiuto(400, "{\n  \"error\": \"invalid_grant\",\n  \"error_description\": \"Token has been expired or revoked.\"\n}");

        $this->assertSame(StatoDiDrive::ACCESSO_REVOCATO, StatoDiDrive::motivoDaRicollegare($e));
    }

    #[Test]
    public function gli_altri_rifiuti_non_toccano_al_docente(): void
    {
        $this->assertNull(
            StatoDiDrive::motivoDaRicollegare(self::rifiuto(401, '{"error": "invalid_client", "error_description": "The OAuth client was not found."}')),
            'credenziali dell\'installazione sbagliate: guasto generale',
        );
        $this->assertNull(StatoDiDrive::motivoDaRicollegare(self::rifiuto(400, '{"error": "invalid_request"}')), 'altra richiesta sbagliata');
        $this->assertNull(StatoDiDrive::motivoDaRicollegare(self::rifiuto(400, '<html>no</html>')), 'corpo che non è JSON');
        $this->assertNull(StatoDiDrive::motivoDaRicollegare(new \RuntimeException('invalid_grant')), 'la parola nel messaggio non basta');
    }
}
