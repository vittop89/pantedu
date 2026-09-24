<?php

declare(strict_types=1);

namespace App\Services\Waf;

/**
 * Il controllo `crowdsec` della diagnostica (23/9/2026).
 *
 * Il bouncer CrowdSec gira dove gira l'applicazione, cioè nel container. Fino
 * al 23/9 il suo URL aveva un valore predefinito, `http://127.0.0.1:8080`: la
 * porta di serie della LAPI sull'host, che nel container è il nginx
 * dell'applicazione. Con la chiave impostata e l'URL lasciato lì, il bouncer
 * avrebbe interrogato il sito, ricevuto un 404, e — fail-open, per invariante —
 * lasciato passare tutti, mentre il pannello del WAF lo dava «raggiungibile»
 * (A-15 della revisione architetturale del 23/9/2026).
 *
 * Misurato in produzione il 23/9/2026, in sola lettura: né URL né chiave
 * impostati, quindi il bouncer è spento e non interroga niente. Il guasto
 * descritto sopra era latente: sarebbe comparso il giorno in cui qualcuno
 * avesse aggiunto la sola chiave.
 *
 * Le risposte:
 *
 *   - **spento** (né URL né chiave): è uno stato, non un guasto →
 *     `non_applicabile`, come GeoIP non configurato;
 *   - **configurato a metà** (uno dei due): il bouncer è spento, ma qualcuno
 *     voleva accenderlo → guasto;
 *   - **risponde la LAPI** con la chiave accettata → regge;
 *   - **la LAPI rifiuta la chiave**, **risponde qualcos'altro** (per esempio
 *     il nginx dell'applicazione), **nessuna risposta** → guasto.
 *
 * E una regola per l'host. Il timer della diagnostica gira sull'host, dove
 * 127.0.0.1:8080 **è** la LAPI: da lì un URL sulla loopback risponderebbe
 * bene mentre il bouncer nel container parla con sé stesso — lo stesso inganno
 * del TeX dall'8 al 19 settembre (ControlloTex). Quindi, sull'host di
 * un'installazione a container, un URL sulla loopback è un guasto qualunque
 * cosa risponda. Il passo 8-bis del rilascio fa la domanda dal container, e lì
 * la risposta vera arriva da sé.
 */
final class ControlloCrowdSec
{
    /**
     * @param array{configured: bool, mancano: list<string>, risposta: ?string, http: int, tipo: string,
     *              servizio: string, error: ?string} $stato quello di WafCrowdSecBouncerService::status()
     * @param string $url    CROWDSEC_LAPI_URL, per la regola della loopback
     * @param string $daDove come dirlo nella prova: «dal container», «dall'host», «da qui»
     * @param bool   $sullHostDiUnContainer la diagnostica gira sull'host, e l'applicazione
     *                                      la serve un container (c'è l'upstream di nginx)
     * @return array{esito: string, prova: string}
     */
    public static function esito(array $stato, string $url, string $daDove, bool $sullHostDiUnContainer): array
    {
        if (!$stato['configured']) {
            if (\count($stato['mancano']) >= 2) {
                return ['esito' => 'non_applicabile', 'prova' => 'bouncer spento: CROWDSEC_LAPI_URL e '
                    . 'CROWDSEC_LAPI_KEY non sono impostate. Il livello CrowdSec del WAF non c’è, di '
                    . 'proposito; gli altri livelli non dipendono da lui.'];
            }
            return ['esito' => 'guasto', 'prova' => 'bouncer configurato a metà: manca '
                . implode(' e ', $stato['mancano']) . ', quindi è spento e non interroga niente. '
                . 'O si imposta anche quella (un URL che il container raggiunge, non 127.0.0.1), o si '
                . 'toglie l’altra.'];
        }

        $servizio = $stato['servizio'];
        if ($sullHostDiUnContainer && self::loopback($url)) {
            return ['esito' => 'guasto', 'prova' => "CROWDSEC_LAPI_URL punta alla loopback ({$servizio}). "
                . 'Il bouncer gira nel container, dove quell’indirizzo è il container stesso: sulla 8080 '
                . 'risponde il nginx dell’applicazione (docker/nginx.conf), e fail-open il bouncer '
                . 'lascia passare tutti. Da qui, sull’host, a quell’indirizzo risponde la LAPI dell’host: '
                . 'una risposta che non dice niente del bouncer, e che quindi non conta.'];
        }

        $dettaglio = $stato['http'] > 0
            ? 'HTTP ' . $stato['http'] . ($stato['tipo'] !== '' ? ', ' . $stato['tipo'] : '')
            : ($stato['error'] ?? 'nessuna risposta');
        return match ($stato['risposta']) {
            WafCrowdSecBouncerService::RISPOSTA_LAPI => ['esito' => 'regge', 'prova' => "risponde la LAPI "
                . "{$daDove} su {$servizio} ({$dettaglio}), con la chiave accettata."
                . ($sullHostDiUnContainer
                    ? ' Dal container passa dal bridge: se in mezzo c’è un firewall, lo dice il giro dentro '
                        . 'il container al rilascio (passo 8-bis).'
                    : '')],
            WafCrowdSecBouncerService::RISPOSTA_CHIAVE_RIFIUTATA => ['esito' => 'guasto', 'prova' => "su "
                . "{$servizio} risponde la LAPI ma rifiuta la chiave ({$dettaglio}, «access forbidden»): "
                . 'il bouncer non riceve decisioni e, fail-open, lascia passare tutti. La chiave si '
                . 'rigenera sull’host con «cscli bouncers add».'],
            WafCrowdSecBouncerService::RISPOSTA_NON_LAPI => ['esito' => 'guasto', 'prova' => "su "
                . "{$servizio} risponde qualcosa che non è la LAPI ({$dettaglio}): il bouncer non riceve "
                . 'decisioni e, fail-open, lascia passare tutti. Se l’indirizzo è la loopback e questa '
                . 'diagnostica gira nel container, è il nginx dell’applicazione.'],
            default => ['esito' => 'guasto', 'prova' => "la LAPI su {$servizio} non risponde {$daDove} "
                . "({$dettaglio}): il bouncer, fail-open, lascia passare tutti."],
        };
    }

    /** L'host dell'URL è la macchina stessa? */
    public static function loopback(string $url): bool
    {
        $host = strtolower(trim((string)parse_url($url, PHP_URL_HOST), '[]'));
        return $host === 'localhost' || $host === '::1' || $host === '0.0.0.0' || str_starts_with($host, '127.');
    }
}
