<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Waf;

use App\Services\Waf\WafScoringService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il controllo anti-bot non perde niente togliendo l'impronta del dispositivo.
 *
 * Il 22/9/2026 il fingerprinter ha smesso di spedire i valori che identificano
 * una macchina — l'hash del canvas, vendor e modello della scheda video,
 * l'impronta audio, l'elenco dei plugin, il fuso orario — perché nessuna regola
 * di `WafScoringService` li leggeva: di canvas, WebGL e audio guarda la sola
 * presenza, e di `plugins` soltanto se è vuoto.
 *
 * Questa prova è la dimostrazione che la riduzione è **neutra sul punteggio**:
 * lo stesso browser, descritto alla vecchia maniera e alla nuova, deve prendere
 * lo stesso numero. Se un domani qualcuno aggiungesse una regola che legge uno
 * di quei valori, il punteggio dei due profili divergerebbe e questa prova
 * diventerebbe rossa: è la guardia che impedisce di reintrodurre l'impronta
 * senza accorgersene.
 *
 * Non basta però che i due numeri coincidano: coinciderebbero anche se il
 * punteggio rispondesse sempre zero. Per questo si verifica anche che un
 * browser vero e un browser headless finiscano su due lati opposti della
 * soglia.
 */
final class PunteggioSenzaImprontaTest extends TestCase
{
    /** Le soglie dichiarate in testa a WafScoringService: 0-40 passa, 71+ blocca. */
    private const PASSA = 40;
    private const BLOCCA = 71;

    /** Un browser vero, come lo si descriveva PRIMA: con le impronte dentro. */
    private static function browserVeroVecchiaForma(): array
    {
        return self::browserVero() + [
            'canvasHash'       => 'AAAFTkSuQmCC7Ub8kx1nJqZ9fQpLm2wXv',
            'webglRenderer'    => 'ANGLE (NVIDIA, NVIDIA GeForce RTX 3060 Direct3D11 vs_5_0 ps_5_0)',
            'webglVendor'      => 'Google Inc. (NVIDIA)',
            'audioFingerprint' => '-1234.5678',
            'plugins'          => 'PDF Viewer|Chrome PDF Viewer|Chromium PDF Viewer',
            // I campi che nessuna regola leggeva e che oggi non si spediscono più.
            'timezone'         => 'Europe/Rome',
            'timezoneOffset'   => -120,
            'languages'        => 'it-IT,it,en-US,en',
            'screenDepth'      => 24,
            'screenAvailW'     => 1920,
            'screenAvailH'     => 1040,
            'navigationTiming' => 842,
            'doNotTrack'       => '1',
            'cookieEnabled'    => true,
            'hasWebRTC'        => true,
            'hasIndexedDB'     => true,
            'hasNotification'  => true,
            'hasBattery'       => true,
            'hasCredentials'   => true,
        ];
    }

    /** Lo stesso browser come lo si descrive ORA: solo capacità. */
    private static function browserVeroNuovaForma(): array
    {
        return self::browserVero() + [
            'canvasHash'       => 'ok',
            'webglRenderer'    => 'webgl',
            'audioFingerprint' => 'ok',
            'plugins'          => 'present',
        ];
    }

    /** La parte comune: nessuno di questi campi è cambiato. */
    private static function browserVero(): array
    {
        return [
            'headlessUA'       => false,
            'userAgent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0',
            'platform'         => 'Win32',
            'language'         => 'it-IT',
            'cpuCores'         => 8,
            'deviceMemory'     => 8,
            'maxTouchPoints'   => 0,
            'screenW'          => 1920,
            'screenH'          => 1080,
            'viewportW'        => 1920,
            'viewportH'        => 937,
            'devicePixelRatio' => 1.5,
            'windowChrome'     => true,
            'hasServiceWorker' => true,
            'hasLocalStorage'  => true,
            'mouseMoved'       => true,
            'mouseEntropy'     => 640,
            'scrolled'         => true,
            'touchDetected'    => false,
        ];
    }

    /** Un headless: le sentinelle di assenza sono identiche nelle due forme. */
    private static function headless(array $extra = []): array
    {
        return $extra + [
            'headlessUA'       => true,
            'userAgent'        => 'Mozilla/5.0 HeadlessChrome/140.0.0.0',
            'platform'         => 'Linux x86_64',
            'language'         => '',
            'cpuCores'         => 0,
            'deviceMemory'     => 0,
            'maxTouchPoints'   => 0,
            'screenW'          => 0,
            'screenH'          => 0,
            'viewportW'        => 0,
            'viewportH'        => 0,
            'devicePixelRatio' => 1,
            'windowChrome'     => false,
            'hasServiceWorker' => false,
            'hasLocalStorage'  => false,
            'mouseMoved'       => false,
            'mouseEntropy'     => 0,
            'scrolled'         => false,
            'touchDetected'    => false,
            'canvasHash'       => 'error',
            'webglRenderer'    => 'no_webgl',
            'audioFingerprint' => 'no_audio',
            'plugins'          => '',
        ];
    }

    /**
     * Una base con il rischio gia' sopra lo zero.
     *
     * Serve alle prove sul peso delle sentinelle. Sul profilo del browser
     * perfetto non funzionano: i bonus umani (mouse, scroll, plugin, audio) lo
     * portano cosi' in negativo che il clamp a zero **assorbe** i segnali di
     * bot, e canvas rotto o intatto danno tutti e due 0. Non e' un difetto del
     * punteggio — e' il clamp che fa il suo mestiere — ma su quel profilo la
     * prova non misurerebbe niente.
     */
    private static function profiloNeutro(): array
    {
        return array_merge(self::headless(), [
            'headlessUA'       => false,
            'canvasHash'       => 'ok',
            'webglRenderer'    => 'webgl',
            'audioFingerprint' => 'ok',
            'plugins'          => 'present',
        ]);
    }

    /** La base neutra descritta alla vecchia maniera: con le impronte dentro. */
    private static function profiloNeutroVecchiaForma(): array
    {
        return array_merge(self::profiloNeutro(), [
            'canvasHash'       => 'AAAFTkSuQmCC7Ub8kx1nJqZ9fQpLm2wXv',
            'webglRenderer'    => 'ANGLE (NVIDIA, NVIDIA GeForce RTX 3060 Direct3D11 vs_5_0 ps_5_0)',
            'webglVendor'      => 'Google Inc. (NVIDIA)',
            'audioFingerprint' => '-1234.5678',
            'plugins'          => 'PDF Viewer|Chrome PDF Viewer',
            'timezone'         => 'Europe/Rome',
            'languages'        => 'it-IT,it,en-US,en',
            'screenDepth'      => 24,
            'navigationTiming' => 842,
            'hasWebRTC'        => true,
        ]);
    }

    #[Test]
    public function lo_stesso_browser_prende_lo_stesso_punteggio_nelle_due_forme(): void
    {
        $s = new WafScoringService();

        // PRIMA su un profilo NON schiacciato dal clamp. Il primo tentativo di
        // questa prova confrontava il «browser perfetto», che con tutti i bonus
        // umani finisce sotto zero e viene riportato a 0: confrontava 0 con 0 e
        // sarebbe passata anche reintroducendo una regola sul valore del
        // canvas. Misurato il 22/9/2026 provando proprio quella regola.
        $neutroVecchio = $s->calculateScore(self::profiloNeutroVecchiaForma());
        $neutroNuovo   = $s->calculateScore(self::profiloNeutro());

        self::assertGreaterThan(0, $neutroNuovo, 'la base della prova non deve stare sul clamp');
        self::assertLessThan(100, $neutroNuovo, 'né sull altro clamp');
        self::assertSame($neutroVecchio, $neutroNuovo,
            'togliere le impronte non deve spostare il punteggio');

        // E poi anche sul browser perfetto, che è il caso reale più frequente.
        self::assertSame(
            $s->calculateScore(self::browserVeroVecchiaForma()),
            $s->calculateScore(self::browserVeroNuovaForma()),
            'né su un browser vero con tutti i segnali umani',
        );
    }

    #[Test]
    public function lo_stesso_headless_prende_lo_stesso_punteggio_nelle_due_forme(): void
    {
        $s = new WafScoringService();

        $vecchia = self::headless(['timezone' => 'UTC', 'languages' => '', 'screenDepth' => 24]);
        $nuova   = self::headless();

        self::assertSame(
            $s->calculateScore($vecchia),
            $s->calculateScore($nuova),
            'i campi tolti non spostano il punteggio nemmeno per un bot',
        );
    }

    #[Test]
    public function la_prova_non_e_vuota_browser_vero_e_headless_stanno_ai_due_lati(): void
    {
        // Senza questo caso le due prove sopra passerebbero anche se il
        // punteggio rispondesse sempre lo stesso numero a chiunque.
        $s = new WafScoringService();

        $vero = $s->calculateScore(self::browserVeroNuovaForma());
        $bot  = $s->calculateScore(self::headless());

        self::assertLessThanOrEqual(self::PASSA, $vero, "un browser vero deve passare (ha preso $vero)");
        self::assertGreaterThanOrEqual(self::BLOCCA, $bot, "un headless deve essere bloccato (ha preso $bot)");
    }

    #[Test]
    public function la_presenza_del_canvas_conta_ancora(): void
    {
        // Le tre sentinelle devono continuare a pesare: sono il motivo per cui
        // il controllo si fa. Se una smettesse di contare, si starebbe
        // raccogliendo un dato per niente — che è il difetto appena corretto.
        $s = new WafScoringService();
        $ok = self::profiloNeutro();
        $rotto = array_merge($ok, ['canvasHash' => 'error']);

        self::assertGreaterThan(
            $s->calculateScore($ok),
            $s->calculateScore($rotto),
            'un canvas che non disegna deve alzare il rischio',
        );
    }

    #[Test]
    public function la_presenza_di_webgl_e_audio_conta_ancora(): void
    {
        $s = new WafScoringService();
        $ok = self::profiloNeutro();

        self::assertGreaterThan(
            $s->calculateScore($ok),
            $s->calculateScore(array_merge($ok, ['webglRenderer' => 'no_webgl'])),
            'niente WebGL deve alzare il rischio',
        );
        self::assertGreaterThan(
            $s->calculateScore($ok),
            $s->calculateScore(array_merge($ok, ['audioFingerprint' => 'no_audio'])),
            'niente audio deve alzare il rischio',
        );
    }

    #[Test]
    public function plugins_vuoto_pesa_come_prima(): void
    {
        // `plugins` si confronta con la STRINGA vuota. Mandare un numero
        // sembrerebbe più pulito e cambierebbe il comportamento, perché
        // `0 === ''` è falso: il browser senza plugin non prenderebbe più il
        // suo punto di rischio. Per questo si manda 'present' oppure ''.
        $s = new WafScoringService();
        $conPlugin  = self::profiloNeutro();
        $senzaPlugin = array_merge($conPlugin, ['plugins' => '']);

        self::assertGreaterThan(
            $s->calculateScore($conPlugin),
            $s->calculateScore($senzaPlugin),
            "l'assenza di plugin deve continuare ad alzare il rischio",
        );
    }
}
