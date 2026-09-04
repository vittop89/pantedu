<?php

declare(strict_types=1);

namespace App\Services\Waf;

/**
 * WAF Scoring Engine — porting da Lua a PHP del prompt
 * docs/todo/waf_security_prompt.md Parte 4.
 *
 * Input: array fingerprint dal browser (POST /waf/fingerprint).
 * Output: punteggio rischio bot 0..100.
 *   0     = umano (passa diretto)
 *   100   = bot certo (blocco)
 *
 * Soglie default (override via waf_config DB):
 *   0..40  → pass        accesso diretto
 *   41..70 → soft        challenge invisibile o interstitial
 *   71..100→ block       403 o checkbox umano
 */
final class WafScoringService
{
    /**
     * Calcola score 0-100 dal fingerprint payload.
     *
     * @param array<string,mixed> $fp Payload JSON decodificato dal browser.
     * @return int Score 0-100 (clamp).
     */
    public function calculateScore(array $fp): int
    {
        $risk = 0;

        // =====================================================================
        // SEGNALI DI BOT (aumentano il rischio)
        // =====================================================================

        // Bot dichiarato nello UA — peso massimo
        if (($fp['headlessUA'] ?? false) === true) {
            $risk += 40;
        }

        // Nessun movimento mouse in 500ms (bot non simulano mouse)
        if (($fp['mouseMoved'] ?? null) === false) {
            $risk += 15;
        }

        // Plugin vuoti (Chrome reale ha almeno PDF viewer)
        if (($fp['plugins'] ?? '') === '') {
            $risk += 8;
        }

        // Touch points = 0 su mobile UA (incoerenza UA/hardware)
        $platform = (string)($fp['platform'] ?? '');
        $isMobileUA = (bool)preg_match('/Android|iPhone|iPad/', $platform);
        if ($isMobileUA && (int)($fp['maxTouchPoints'] ?? 0) === 0) {
            $risk += 10;
        }

        // WebGL non disponibile (headless spesso disabilita GPU)
        if (($fp['webglRenderer'] ?? '') === 'no_webgl') {
            $risk += 8;
        }

        // Canvas restituisce errore (sandbox headless)
        if (($fp['canvasHash'] ?? '') === 'error') {
            $risk += 10;
        }

        // Audio fingerprint assente
        if (($fp['audioFingerprint'] ?? '') === 'no_audio') {
            $risk += 6;
        }

        // CPU cores = 0 o non dichiarato (headless a volte ritorna 0)
        if ((int)($fp['cpuCores'] ?? 0) === 0) {
            $risk += 5;
        }

        // Device memory = 0 (headless a volte non lo espone)
        if ((int)($fp['deviceMemory'] ?? 0) === 0) {
            $risk += 3;
        }

        // Nessuna lingua / lingua vuota
        $lang = $fp['language'] ?? null;
        if ($lang === null || $lang === '') {
            $risk += 5;
        }

        // window.chrome assente su UA che dichiara Chrome
        if (($fp['windowChrome'] ?? null) === false) {
            $risk += 10;
        }

        // Nessun Service Worker support (browser moderni reali lo hanno)
        if (($fp['hasServiceWorker'] ?? null) === false) {
            $risk += 4;
        }

        // localStorage bloccato (sandboxing aggressivo)
        if (($fp['hasLocalStorage'] ?? null) === false) {
            $risk += 4;
        }

        // Screen width o height = 0 (headless senza viewport configurato)
        if ((int)($fp['screenW'] ?? 0) === 0 || (int)($fp['screenH'] ?? 0) === 0) {
            $risk += 12;
        }

        // Viewport uguale a screen (headless usa spesso viewport = screen)
        $sw = (int)($fp['screenW'] ?? 0);
        $sh = (int)($fp['screenH'] ?? 0);
        $vw = (int)($fp['viewportW'] ?? 0);
        $vh = (int)($fp['viewportH'] ?? 0);
        if ($sw > 0 && $sw === $vw && $sh === $vh) {
            $risk += 3;
        }

        // devicePixelRatio = 1 esatto su display ad alta risoluzione (segnale debole)
        $dpr = (float)($fp['devicePixelRatio'] ?? 0);
        if ($dpr === 1.0 && $sw >= 1920) {
            $risk += 2;
        }

        // Entropia mouse troppo bassa anche se mouse si è mosso (movimento finto)
        if (($fp['mouseMoved'] ?? null) === true && (int)($fp['mouseEntropy'] ?? 0) < 10) {
            $risk += 8;
        }

        // =====================================================================
        // SEGNALI UMANI (riducono il rischio)
        // =====================================================================

        if (($fp['mouseMoved'] ?? null) === true && (int)($fp['mouseEntropy'] ?? 0) > 100) {
            $risk -= 8;
        }

        if (($fp['scrolled'] ?? null) === true) {
            $risk -= 5;
        }

        if (($fp['touchDetected'] ?? null) === true) {
            $risk -= 5;
        }

        if (!empty($fp['plugins'])) {
            $risk -= 4;
        }

        $audio = $fp['audioFingerprint'] ?? null;
        if ($audio !== null && $audio !== 'no_audio' && $audio !== '') {
            $risk -= 3;
        }

        // Clamp 0..100
        if ($risk < 0) {
            $risk = 0;
        }
        if ($risk > 100) {
            $risk = 100;
        }

        return $risk;
    }

    /**
     * Segnali server-side che il client NON può auto-dichiarare a proprio
     * vantaggio (a differenza del fingerprint JS, interamente client-supplied).
     *
     * Confronta lo UA *reale* (header HTTP, visto dal server) con quello
     * dichiarato nel fingerprint e con l'header Accept-Language, e applica un
     * floor di rischio per UA noti di automazione/CLI a prescindere dal flag
     * `headlessUA` auto-dichiarato.
     *
     * @param array<string,mixed> $fp     Fingerprint JS (client-supplied).
     * @param array<string,mixed> $server $_SERVER reali.
     * @return int Rischio aggiuntivo 0..100 (da sommare al fingerprint score).
     */
    public function serverSignals(array $fp, array $server): int
    {
        $risk = 0;
        $serverUa = trim((string)($server['HTTP_USER_AGENT'] ?? ''));
        $clientUa = trim((string)($fp['userAgent'] ?? ''));

        // 1. UA noti di automazione/CLI/librerie HTTP → floor alto, non
        //    aggirabile dichiarando headlessUA=false nel fingerprint.
        $automationRe = '/(headless|puppeteer|playwright|selenium|phantomjs|'
            . 'electron|python-requests|python-urllib|curl\/|wget\/|libwww|'
            . 'go-http-client|java\/|okhttp|axios\/|node-fetch|httpclient|'
            . 'scrapy|httrack|bot|spider|crawler)/i';
        if ($serverUa === '' || preg_match($automationRe, $serverUa)) {
            $risk += 60;
        }

        // 2. UA dichiarato nel fingerprint ≠ UA reale dell'header → manomissione.
        if ($clientUa !== '' && $serverUa !== '' && !hash_equals($serverUa, $clientUa)) {
            $risk += 30;
        }

        // 3. Nessun Accept-Language: i browser reali lo inviano sempre; i client
        //    automatici spesso no.
        if (trim((string)($server['HTTP_ACCEPT_LANGUAGE'] ?? '')) === '') {
            $risk += 12;
        }

        // 4. Nessun header Accept (browser reali lo inviano) → segnale debole.
        if (trim((string)($server['HTTP_ACCEPT'] ?? '')) === '') {
            $risk += 8;
        }

        return $risk > 100 ? 100 : $risk;
    }

    /**
     * Mappa score → challenge in base alle soglie configurate.
     *
     * @param int $score        0..100
     * @param int $thresholdPass  default 40
     * @param int $thresholdBlock default 70
     * @return string "pass" | "soft" | "block"
     */
    public function getChallenge(int $score, int $thresholdPass = 40, int $thresholdBlock = 70): string
    {
        if ($score <= $thresholdPass) {
            return 'pass';
        }
        if ($score <= $thresholdBlock) {
            return 'soft';
        }
        return 'block';
    }

    /**
     * Hash sha256 del fingerprint payload (per dedup e log).
     *
     * @param array<string,mixed> $fp
     */
    public function fingerprintHash(array $fp): string
    {
        ksort($fp);
        return hash('sha256', json_encode($fp, JSON_THROW_ON_ERROR));
    }
}
