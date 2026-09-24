<?php

declare(strict_types=1);

namespace App\Services\Waf;

use App\Core\Database;
use App\Repositories\Waf\WafConfigRepository;
use PDO;

/**
 * Chiede a CrowdSec CTI che reputazione ha un indirizzo, su richiesta.
 *
 * Perché su richiesta e non in blocco. La sincronizzazione delle liste
 * (`WafThreatIntelService`) riempie `waf_threat_ips` da quattro fonti, e quello
 * è il lavoro di massa. Questa è un'altra cosa: l'amministratore guarda una
 * riga nel pannello e si chiede «chi è». La CTI risponde a quella domanda con
 * reputazione, rumore di fondo, classificazioni e nome dell'operatore di rete.
 *
 * Perché non fa parte della sincronizzazione. L'endpoint a forma di elenco
 * (`/v2/fire`) non è incluso nel piano gratuito — verificato l'8 settembre 2026
 * con una chiave vera: 403. `/v2/smoke/{ip}` invece risponde 200, e lavora su
 * un indirizzo per volta. Voce 82 del debito.
 *
 * Perché la cache. Il piano gratuito ha una quota giornaliera. Senza cache,
 * riaprire la stessa pagina la brucerebbe su indirizzi già visti; e si mette in
 * cache anche l'esito negativo, altrimenti un errore verrebbe ritentato a ogni
 * apertura — che è il modo più veloce di restare senza.
 */
final class WafCtiService
{
    /** Quanto vale una risposta prima di richiederla. */
    private const VALIDA_ORE = 24;

    /** Quanto vale un fallimento prima di riprovare: molto meno, ma non zero. */
    private const FALLITA_ORE = 1;

    private const URL = 'https://cti.api.crowdsec.net/v2/smoke/';

    /** @var callable(string, string): array{stato:int, corpo:string} */
    private $lettore;

    /** @var callable(): string */
    private $chiaveCti;

    /**
     * @param callable(string, string): array{stato:int, corpo:string}|null $lettore
     *        iniettabile per le prove: riceve indirizzo e chiave, torna la risposta.
     * @param callable(): string|null $chiaveCti
     *        anche la chiave si inietta: in prova non c'è il pannello WAF da cui
     *        leggerla, e senza questo il servizio uscirebbe prima di provare
     *        qualunque cosa — con le prove verdi per la ragione sbagliata.
     */
    public function __construct(
        private readonly ?PDO $pdo = null,
        ?callable $lettore = null,
        ?callable $chiaveCti = null,
    ) {
        $this->lettore = $lettore ?? self::lettoreHttp();
        $this->chiaveCti = $chiaveCti ?? static fn (): string =>
            trim((string)(new WafConfigRepository())->get('crowdsec_api_key', ''));
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Che cosa sappiamo di questo indirizzo.
     *
     * @return array{
     *   ok: bool, ip: string, dalla_cache: bool, quando: string,
     *   reputazione?: string, fiducia?: string, rumore?: string,
     *   operatore?: string, asn?: string, paese?: string, inverso?: string,
     *   classificazioni?: list<string>, ultimo_avvistamento?: string,
     *   motivo?: string
     * }
     */
    public function guarda(string $ip, bool $forza = false): array
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return ['ok' => false, 'ip' => $ip, 'dalla_cache' => false,
                    'quando' => '', 'motivo' => 'indirizzo non valido'];
        }

        if (!$forza) {
            $inCache = $this->dallaCache($ip);
            if ($inCache !== null) {
                return $inCache;
            }
        }

        $chiave = ($this->chiaveCti)();
        if ($chiave === '') {
            // Non si mette in cache: appena la chiave c'è, deve funzionare.
            return ['ok' => false, 'ip' => $ip, 'dalla_cache' => false, 'quando' => '',
                    'motivo' => 'chiave CTI non configurata (pannello WAF → Config)'];
        }

        $risposta = ($this->lettore)($ip, $chiave);
        $ok = $risposta['stato'] === 200;

        if (!$ok) {
            $motivo = match ($risposta['stato']) {
                403 => 'la chiave non è autorizzata su questo endpoint (piano CTI)',
                404 => 'CrowdSec non ha niente su questo indirizzo',
                429 => 'quota giornaliera esaurita',
                default => 'CrowdSec ha risposto ' . $risposta['stato'],
            };
            $esito = ['ok' => false, 'ip' => $ip, 'dalla_cache' => false,
                      'quando' => date('c'), 'motivo' => $motivo];
            $this->salva($ip, $esito, false);
            return $esito;
        }

        /** @var array<string,mixed>|null $dati */
        $dati = json_decode($risposta['corpo'], true);
        if (!is_array($dati)) {
            $esito = ['ok' => false, 'ip' => $ip, 'dalla_cache' => false,
                      'quando' => date('c'), 'motivo' => 'risposta illeggibile'];
            $this->salva($ip, $esito, false);
            return $esito;
        }

        $esito = self::traduci($ip, $dati);
        $this->salva($ip, $esito, true);
        return $esito;
    }

    /**
     * Dalla risposta di CrowdSec ai campi che servono a chi guarda il pannello.
     *
     * @param array<string,mixed> $dati
     * @return array<string,mixed>
     */
    private static function traduci(string $ip, array $dati): array
    {
        /** @var array<string,mixed> $posizione */
        $posizione = is_array($dati['location'] ?? null) ? $dati['location'] : [];
        /** @var array<string,mixed> $classificazioni */
        $classificazioni = is_array($dati['classifications'] ?? null) ? $dati['classifications'] : [];

        $etichette = [];
        foreach (['false_positives', 'classifications'] as $chiave) {
            foreach ((array)($classificazioni[$chiave] ?? []) as $voce) {
                if (is_array($voce) && isset($voce['label'])) {
                    $etichette[] = (string)$voce['label'];
                }
            }
        }

        return [
            'ok'                  => true,
            'ip'                  => $ip,
            'dalla_cache'         => false,
            'quando'              => date('c'),
            'reputazione'         => (string)($dati['reputation'] ?? ''),
            'fiducia'             => (string)($dati['confidence'] ?? ''),
            'rumore'              => (string)($dati['background_noise'] ?? ''),
            'operatore'           => (string)($dati['as_name'] ?? ''),
            'asn'                 => (string)($dati['as_num'] ?? ''),
            'paese'               => (string)($posizione['country'] ?? ''),
            'inverso'             => (string)($dati['reverse_dns'] ?? ''),
            'classificazioni'     => array_values(array_unique($etichette)),
            'ultimo_avvistamento' => (string)($dati['history']['last_seen'] ?? ''),
        ];
    }

    /** @return array<string,mixed>|null */
    private function dallaCache(string $ip): ?array
    {
        $st = $this->db()->prepare(
            'SELECT payload, ok, fetched_at FROM waf_cti_cache WHERE ip = ? LIMIT 1'
        );
        $st->execute([$ip]);
        /** @var array{payload:string, ok:int, fetched_at:string}|false $riga */
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        if ($riga === false) {
            return null;
        }

        $ore = ((int)$riga['ok'] === 1) ? self::VALIDA_ORE : self::FALLITA_ORE;
        if (strtotime($riga['fetched_at']) < time() - $ore * 3600) {
            return null;
        }

        $dati = json_decode($riga['payload'], true);
        if (!is_array($dati)) {
            return null;
        }
        $dati['dalla_cache'] = true;
        return $dati;
    }

    /** @param array<string,mixed> $esito */
    private function salva(string $ip, array $esito, bool $ok): void
    {
        // Cancella-e-inserisci invece di `ON DUPLICATE KEY UPDATE`: quella è
        // sintassi MariaDB, e le prove girano su SQLite. Con una sintassi sola
        // le prove esercitano lo stesso codice della produzione, invece di una
        // variante che nessuno ha mai messo in servizio.
        //
        // La riga è una cache con chiave primaria: se due richieste per lo
        // stesso indirizzo arrivano insieme, la peggiore conseguenza è che una
        // delle due riscrive quello che l'altra ha appena scritto — lo stesso
        // contenuto.
        $db = $this->db();
        $db->prepare('DELETE FROM waf_cti_cache WHERE ip = ?')->execute([$ip]);
        $db->prepare(
            'INSERT INTO waf_cti_cache (ip, payload, ok, fetched_at)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $ip,
            json_encode($esito, JSON_UNESCAPED_UNICODE),
            $ok ? 1 : 0,
            date('Y-m-d H:i:s'),
        ]);
    }

    /** @return callable(string, string): array{stato:int, corpo:string} */
    private static function lettoreHttp(): callable
    {
        return static function (string $ip, string $chiave): array {
            $ch = curl_init(self::URL . rawurlencode($ip));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => ['x-api-key: ' . $chiave, 'Accept: application/json'],
                // L'istanza, non il dominio di produzione (23/9/2026).
                CURLOPT_USERAGENT      => \App\Support\IndirizzoPubblico::agente('pantedu-waf/25.I'),
            ]);
            $corpo = curl_exec($ch);
            $stato = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ['stato' => $stato, 'corpo' => is_string($corpo) ? $corpo : ''];
        };
    }
}
