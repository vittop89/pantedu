<?php

declare(strict_types=1);

namespace App\Services\TexCompile;

/**
 * Il controllo `tex` della diagnostica, nelle sue due domande (19/9/2026).
 *
 * Il servizio TeX è un'unità systemd **sull'host**; l'applicazione vive nel
 * container. Dall'8 settembre 2026 il container non lo raggiungeva — cercava
 * il TeX sul proprio 127.0.0.1 — mentre l'host sì. Un controllo fatto
 * dall'host avrebbe detto «regge» per tutti gli undici giorni del guasto.
 *
 * Quindi due domande, e ognuna dal posto in cui significa qualcosa:
 *
 *   - `diretto()`: il TeX risponde **da qui**? Nel container è la domanda
 *     giusta (la fa il passo 8-bis del rilascio, come www-data). Sull'host
 *     conta per i lavori a orario (coda delle compilazioni, prewarm TikZ), che
 *     girano lì;
 *   - `attraversoNginx()`: l'applicazione che serve le pagine risponde di sì a
 *     /health/tex? Sull'host è l'unico modo di sapere se il **container** ci
 *     arriva. La domanda passa da nginx con le intestazioni del passo 8 del
 *     rilascio, perché è nginx a sapere qual è il container in servizio.
 */
final class ControlloTex
{
    /**
     * @param string $daDove come dirlo nella prova: «dal container», «dall'host»
     * @return array{esito: string, prova: string}
     */
    public static function diretto(TexCompileClient $client, string $daDove): array
    {
        $sonda = $client->sonda();
        $servizio = $client->servizio();
        if ($sonda['ok']) {
            return ['esito' => 'regge', 'prova' => "il servizio TeX risponde {$daDove} ({$servizio}, {$sonda['ms']} ms)"];
        }
        $dettaglio = $sonda['errno'] !== 0 ? "errno {$sonda['errno']}" : "HTTP {$sonda['http']}";
        return ['esito' => 'guasto', 'prova' => "il servizio TeX non risponde {$daDove}: {$sonda['classe']} "
            . "({$dettaglio}) su {$servizio}. PDF, anteprime e TikZ non in cache falliscono. "
            . 'Vedi docs/ops/tex-dal-container.md.'];
    }

    /**
     * @param string $base  dove risponde nginx, senza barra finale (sull'host: https://127.0.0.1)
     * @param string $host  il nome del sito, per il vhost giusto
     * @return array{esito: string, prova: string}
     */
    public static function attraversoNginx(string $base, string $host): array
    {
        $ch = curl_init($base . '/health/tex');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // La sonda dell'applicazione ha un tetto di cinque secondi: qui ce
            // ne vuole di più, o un TeX lento sembrerebbe un nginx fermo.
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Host: ' . $host, 'X-Forwarded-For: 127.0.0.1'],
            // Il certificato è quello di Cloudflare verso l'origine, per il
            // nome del sito: su 127.0.0.1 non si può verificare. Come il
            // passo 8 del rilascio (curl -k).
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $corpo = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $corpo === false) {
            return ['esito' => 'guasto', 'prova' => 'nginx non risponde a /health/tex: '
                . TexIrraggiungibile::classe($errno) . " (errno {$errno})."];
        }
        $dati = json_decode((string)$corpo, true);
        $tex = \is_array($dati) && \array_key_exists('tex', $dati) ? $dati['tex'] : null;

        if ($tex === true) {
            return ['esito' => 'regge', 'prova' => "l'applicazione in servizio raggiunge il TeX (/health/tex attraverso nginx)"];
        }
        if ($tex === false) {
            $errore = \is_string($dati['errore'] ?? null) ? $dati['errore'] : 'motivo non detto';
            return ['esito' => 'guasto', 'prova' => "l'applicazione in servizio NON raggiunge il TeX: {$errore} "
                . "(/health/tex attraverso nginx, HTTP {$http}). È il guasto dell'8 settembre 2026: "
                . 'indirizzo, ascolto o firewall, vedi docs/ops/tex-dal-container.md.'];
        }
        if ($tex === 'non_configurato') {
            return ['esito' => 'guasto', 'prova' => "l'applicazione in servizio non ha la configurazione del TeX "
                . '(TEX_COMPILE_ENDPOINT o TEX_COMPILE_SECRET vuoti nel container), mentre qui c’è.'];
        }
        return ['esito' => 'guasto', 'prova' => "/health/tex attraverso nginx risponde HTTP {$http} senza la "
            . 'risposta attesa: la rotta non c’è (container vecchio?) o il WAF la sfida.'];
    }
}
