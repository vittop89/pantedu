<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\TrustPagesController;
use App\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le pagine pubbliche dicono quello che i documenti legali dicono (24/9/2026).
 *
 * La rilettura dei documenti prima dell'invio al DPO dell'Istituto ha trovato
 * pagine che smentivano gli allegati: un «DPO» di Pantedu che non è mai stato
 * designato, la chiave master «off-line» (sta sul server), le copie
 * «illeggibili senza la chiave master» (contengono anche quella), il gettone
 * CSRF «ruotato a ogni modifica» (vale per la sessione), consensi «analytics,
 * marketing» che non esistono, collegamenti a rotte che accettano solo POST, e
 * lo scenario dell'istanza mai detto.
 *
 * Nei due versi: le frasi sbagliate non ci sono, quelle giuste sì (una prova
 * che controllasse solo le assenze passerebbe anche con una pagina vuota).
 */
final class PaginePubblicheDiconoIlVeroTest extends TestCase
{
    /** Frasi che non devono più comparire, con il perché. */
    private const FALSE = [
        'Contatta il DPO' => 'Pantedu non ha un DPO designato',
        'Contatto DPO' => 'Pantedu non ha un DPO designato',
        'off-line' => 'la chiave master sta sul server',
        'restano illeggibili senza la chiave master' => 'le copie contengono anche la chiave',
        'rotation su ogni mutazione' => 'il gettone CSRF vale per la sessione',
        'analytics, marketing' => 'non ci sono consensi di questo tipo',
        'href="/me/consents"' => 'risponde JSON',
        'href="/me/profile"' => 'accetta solo POST',
    ];

    private static function sicurezza(): string
    {
        return (string)(new TrustPagesController())->security(new Request(''))->body;
    }

    private static function iTuoiDati(): string
    {
        return (string)(new TrustPagesController())->yourData(new Request(''))->body;
    }

    #[Test]
    public function la_pagina_della_sicurezza_non_dice_il_falso_e_dice_il_vero(): void
    {
        $pagina = self::sicurezza();
        foreach (self::FALSE as $frase => $perche) {
            self::assertStringNotContainsString($frase, $pagina, $perche);
        }
        self::assertStringContainsString('La chiave master sta sul server', $pagina);
        self::assertStringContainsString('legato alla sessione e verificato su ogni richiesta che modifica', $pagina);
        self::assertStringContainsString('Nelle copie di sicurezza fatte prima i dati restano fino alla loro scadenza', $pagina);
    }

    #[Test]
    public function la_pagina_dei_diritti_non_dice_il_falso_e_rimanda_al_titolare(): void
    {
        $pagina = self::iTuoiDati();
        foreach (self::FALSE as $frase => $perche) {
            self::assertStringNotContainsString($frase, $pagina, $perche);
        }
        self::assertStringContainsString('Richieste privacy ed esercizio dei diritti', $pagina);
        self::assertStringContainsString('Scrivi al titolare', $pagina);
    }

    #[Test]
    public function le_pagine_legali_dicono_lo_scenario_dell_istanza(): void
    {
        $pagina = (string)(new TrustPagesController())->informativa(new Request(''))->body;

        self::assertStringContainsString('Scenario attivo: Scenario ', $pagina);
    }
}
