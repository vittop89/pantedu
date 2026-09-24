<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Waf;

use App\Services\Waf\WafLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il registro di sicurezza non conserva credenziali funzionanti.
 *
 * `GET /accesso-classe/qr/{token}` fa entrare chi lo apre: quel token **è** la
 * credenziale di classe. Viaggiando nel percorso finiva in
 * `waf_logs.request_uri`, che si conserva trenta giorni in chiaro — leggibile
 * da chi amministra il server e da chiunque apra una copia di sicurezza.
 *
 * Provato nei due versi. Una maschera che togliesse troppo sarebbe inutile
 * quanto una che non toglie niente: il registro serve a sapere che cosa è
 * stato chiesto, e il resto dei percorsi deve arrivarci intero.
 */
final class CredenzialiFuoriDalRegistroTest extends TestCase
{
    #[Test]
    public function il_codice_della_credenziale_non_arriva_al_registro(): void
    {
        $percorso = '/accesso-classe/qr/AbC123xyz789QWERTY';
        $pulito = WafLogService::senzaSegreti($percorso);

        self::assertStringNotContainsString('AbC123xyz789QWERTY', $pulito,
            'il token non deve comparire nel registro');
        self::assertStringContainsString('/accesso-classe/qr/', $pulito,
            'ma si deve continuare a sapere che qualcuno ha usato un QR');
    }

    #[Test]
    public function funziona_anche_col_pacchetto_di_classe_e_la_query(): void
    {
        // Più codici separati da virgola = «pacchetto di classe», fino a dodici
        // credenziali in una scansione: se ne sfuggisse una, non servirebbe a
        // niente aver mascherato le altre.
        $pulito = WafLogService::senzaSegreti('/accesso-classe/qr/uno,due,tre?from=bacheca');

        self::assertStringNotContainsString('uno,due,tre', $pulito);
        self::assertStringContainsString('?from=bacheca', $pulito,
            'la query serve alla diagnosi e non è un segreto');
    }

    #[Test]
    public function i_percorsi_normali_arrivano_interi(): void
    {
        // Il verso che di solito non si prova. Una maschera troppo larga
        // renderebbe cieco il registro senza che nessuno se ne accorga: gli
        // esiti continuerebbero a scriversi, e il percorso sarebbe inutile.
        foreach ([
            '/api/risdoc/templates/16/export',
            '/accesso-classe',
            '/accesso-classe/esci',
            '/api/access/student-login',
            '/admin/waf/config?giorno=2026-09-22',
        ] as $percorso) {
            self::assertSame($percorso, WafLogService::senzaSegreti($percorso),
                "il percorso $percorso non ha segreti dentro e deve restare intero");
        }
    }

    #[Test]
    public function il_percorso_vuoto_non_rompe_niente(): void
    {
        self::assertSame('', WafLogService::senzaSegreti(''));
    }
}
