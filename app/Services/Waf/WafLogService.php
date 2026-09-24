<?php

declare(strict_types=1);

namespace App\Services\Waf;

use App\Core\Database;
use PDO;

/**
 * WAF Log Service — insert async-friendly + query per dashboard.
 *
 * Schema: waf_logs (id, ts, ip, country, asn, user_agent, request_uri,
 *   method, referer, score, challenge, outcome, rule_id, session_token,
 *   fp_hash, request_id)
 */
final class WafLogService
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Toglie dal percorso i valori che SONO una credenziale.
     *
     * `\/accesso-classe\/qr\/{token}` fa entrare chi lo apre: quel token e' la
     * credenziale di classe, e apre l'intero perimetro pubblicato di un
     * docente. Registrato in chiaro resterebbe trenta giorni dentro
     * `waf_logs.request_uri`, leggibile da chi amministra il server e da
     * chiunque apra una copia di sicurezza: un registro di sicurezza che
     * conserva credenziali funzionanti lavora contro se stesso (art. 32 §1
     * lett. b). Il resto del percorso serve e resta.
     *
     * Si maschera qui, dove passano tutte le scritture del registro, e non nei
     * singoli chiamanti: un chiamante nuovo che se ne dimenticasse
     * reintrodurrebbe il difetto in silenzio.
     *
     * 23/9/2026 — l'elenco delle rotte con un gettone nel percorso sta in
     * PercorsoSenzaGettoni, lo stesso del registro delle operazioni e di
     * quello degli accessi: da oggi vi entra anche `/parent-consent/{token}`.
     * La query resta, qui: serve alla diagnosi.
     */
    public static function senzaSegreti(string $percorso): string
    {
        return \App\Services\Audit\PercorsoSenzaGettoni::mascherati($percorso);
    }

    /**
     * Inserisce una entry di log. Errori silenziati (no propaga al middleware).
     *
     * @param array{ip:string,country?:string|null,asn?:string|null,user_agent?:string|null,
     *              request_uri?:string|null,method?:string|null,referer?:string|null,
     *              score?:int|null,challenge?:string|null,outcome:string,rule_id?:int|null,
     *              session_token?:string|null,fp_hash?:string|null,request_id?:string|null} $data
     */
    public function log(array $data): void
    {
        try {
            $sql = 'INSERT INTO waf_logs (ip, country, asn, user_agent, request_uri, method,
                       referer, score, challenge, outcome, rule_id, session_token, fp_hash, request_id)
                    VALUES (:ip, :country, :asn, :ua, :uri, :method,
                       :referer, :score, :challenge, :outcome, :rule_id, :st, :fph, :rid)';
            $stmt = $this->db()->prepare($sql);
            $stmt->execute([
                ':ip'        => $data['ip'],
                ':country'   => $data['country'] ?? null,
                ':asn'       => $data['asn'] ?? null,
                ':ua'        => $this->trunc((string)($data['user_agent'] ?? ''), 512),
                ':uri'       => $this->trunc(self::senzaSegreti((string)($data['request_uri'] ?? '')), 512),
                ':method'    => $data['method'] ?? 'GET',
                ':referer'   => $this->trunc(self::senzaSegreti((string)($data['referer'] ?? '')), 512),
                ':score'     => $data['score'] ?? null,
                ':challenge' => $data['challenge'] ?? null,
                ':outcome'   => $data['outcome'],
                ':rule_id'   => $data['rule_id'] ?? null,
                ':st'        => $data['session_token'] ?? null,
                ':fph'       => $data['fp_hash'] ?? null,
                ':rid'       => $data['request_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Il WAF non deve bloccare il sito se il database non risponde: la
            // richiesta prosegue. Ma una riga di registro persa in silenzio e'
            // il guasto peggiore che questo progetto conosca — il 22/9/2026 si
            // e' scoperto che il controllo anti-bot non veniva registrato da
            // settimane, perche' un valore di 21 caratteri non entrava in una
            // colonna da 16 e l'eccezione finiva qui dentro. Adesso si sente.
            \App\Support\Anomalia::registra(
                'waf_log_non_scritto',
                'Una richiesta scrutinizzata dal filtro di sicurezza non e\' stata registrata: '
                    . 'il registro del WAF sta perdendo righe.',
                ['esito' => (string)($data['outcome'] ?? '?'), 'errore' => $e->getMessage()],
                'waf_log_non_scritto',
            );
        }
    }

    /**
     * Ultimi N log per dashboard.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * Le richieste scrutinizzate, dalla più recente.
     *
     * 21/9/2026 — con la sola quantità non si risponde alla domanda vera di
     * chi amministra, che è «che cosa è successo fra le dieci e le undici».
     * Cinquanta richieste, durante una lezione, sono pochi secondi. Da qui i
     * due estremi: si passano già normalizzati (`Y-m-d H:i:s`), perché la
     * validazione di quello che arriva dall'indirizzo sta nel controller.
     *
     * @param string|null $dalle inizio compreso
     * @param string|null $alle  fine compresa
     * @return list<array<string,mixed>>
     */
    public function recent(
        int $limit = 100,
        ?string $outcomeFilter = null,
        ?string $dalle = null,
        ?string $alle = null,
    ): array {
        try {
            $dove = [];
            if ($outcomeFilter !== null) {
                $dove[] = 'outcome = :o';
            }
            if ($dalle !== null) {
                $dove[] = 'ts >= :dalle';
            }
            if ($alle !== null) {
                $dove[] = 'ts <= :alle';
            }
            $where = $dove === [] ? '' : 'WHERE ' . implode(' AND ', $dove);
            $sql = "SELECT * FROM waf_logs $where ORDER BY id DESC LIMIT " . max(1, min(1000, $limit));
            $stmt = $this->db()->prepare($sql);
            if ($outcomeFilter !== null) {
                $stmt->bindValue(':o', $outcomeFilter);
            }
            if ($dalle !== null) {
                $stmt->bindValue(':dalle', $dalle);
            }
            if ($alle !== null) {
                $stmt->bindValue(':alle', $alle);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Counter per dashboard.
     *
     * @return array{today:int, hour:int, last_5min:int, total:int, blocked_today:int}
     */
    public function counters(): array
    {
        try {
            $sql = "SELECT
                SUM(CASE WHEN ts >= NOW() - INTERVAL 1 DAY  THEN 1 ELSE 0 END) AS today,
                SUM(CASE WHEN ts >= NOW() - INTERVAL 1 HOUR THEN 1 ELSE 0 END) AS hour,
                SUM(CASE WHEN ts >= NOW() - INTERVAL 5 MINUTE THEN 1 ELSE 0 END) AS last_5min,
                COUNT(*) AS total,
                SUM(CASE WHEN ts >= NOW() - INTERVAL 1 DAY AND outcome = 'block' THEN 1 ELSE 0 END) AS blocked_today
                FROM waf_logs";
            $row = $this->db()->query($sql)?->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'today'         => (int)($row['today'] ?? 0),
                'hour'          => (int)($row['hour'] ?? 0),
                'last_5min'     => (int)($row['last_5min'] ?? 0),
                'total'         => (int)($row['total'] ?? 0),
                'blocked_today' => (int)($row['blocked_today'] ?? 0),
            ];
        } catch (\Throwable) {
            return ['today' => 0, 'hour' => 0, 'last_5min' => 0, 'total' => 0, 'blocked_today' => 0];
        }
    }

    /**
     * Top IP per volume (ultimi N giorni).
     *
     * @return list<array{ip:string,count:int,country:?string,last_outcome:?string}>
     */
    public function topIps(int $days = 7, int $limit = 20): array
    {
        try {
            // SUBSTRING_INDEX trick: per ogni IP prendiamo il valore associato
            // alla riga più recente di country/outcome/user_agent.
            // user_agent: SEPARATOR '|||' per evitare collisione con ',' che
            // può occorrere nelle stringhe UA.
            $sql = "SELECT ip, COUNT(*) AS count,
                    SUBSTRING_INDEX(GROUP_CONCAT(country ORDER BY ts DESC), ',', 1) AS country,
                    SUBSTRING_INDEX(GROUP_CONCAT(outcome ORDER BY ts DESC), ',', 1) AS last_outcome,
                    SUBSTRING_INDEX(GROUP_CONCAT(IFNULL(user_agent,'') ORDER BY ts DESC SEPARATOR '|||'), '|||', 1) AS last_user_agent
                    FROM waf_logs
                    WHERE ts >= NOW() - INTERVAL :d DAY
                    GROUP BY ip ORDER BY count DESC LIMIT :lim";
            $stmt = $this->db()->prepare($sql);
            $stmt->bindValue(':d', $days, PDO::PARAM_INT);
            $stmt->bindValue(':lim', max(1, min(100, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Distribuzione score (istogramma 0-100 a bin di 10).
     *
     * @return list<array{bucket:int,count:int}>
     */
    public function scoreDistribution(int $days = 7): array
    {
        try {
            $sql = "SELECT FLOOR(score/10)*10 AS bucket, COUNT(*) AS count
                    FROM waf_logs
                    WHERE ts >= NOW() - INTERVAL :d DAY AND score IS NOT NULL
                    GROUP BY bucket ORDER BY bucket ASC";
            $stmt = $this->db()->prepare($sql);
            $stmt->bindValue(':d', $days, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Richieste/minuto suddivise per outcome (ultime N ore).
     *
     * @return list<array{minute:string,outcome:string,count:int}>
     */
    public function rpmByOutcome(int $hours = 6): array
    {
        try {
            $sql = "SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:%i:00') AS minute, outcome, COUNT(*) AS count
                    FROM waf_logs
                    WHERE ts >= NOW() - INTERVAL :h HOUR
                    GROUP BY minute, outcome ORDER BY minute ASC";
            $stmt = $this->db()->prepare($sql);
            $stmt->bindValue(':h', $hours, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Distribuzione geografica: top N country in ultimi N giorni.
     *
     * @return list<array{country:?string,count:int,blocked:int}>
     */
    public function topCountries(int $days = 7, int $limit = 15): array
    {
        try {
            $sql = "SELECT country, COUNT(*) AS count,
                    SUM(CASE WHEN outcome LIKE 'block%' THEN 1 ELSE 0 END) AS blocked
                    FROM waf_logs
                    WHERE ts >= NOW() - INTERVAL :d DAY AND country IS NOT NULL AND country != ''
                    GROUP BY country ORDER BY count DESC LIMIT :lim";
            $stmt = $this->db()->prepare($sql);
            $stmt->bindValue(':d', $days, PDO::PARAM_INT);
            $stmt->bindValue(':lim', max(1, min(50, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Outcome breakdown (pie chart): conteggio per outcome ultimi N giorni.
     *
     * @return list<array{outcome:string,count:int}>
     */
    public function outcomeBreakdown(int $days = 7): array
    {
        try {
            $sql = "SELECT outcome, COUNT(*) AS count
                    FROM waf_logs
                    WHERE ts >= NOW() - INTERVAL :d DAY
                    GROUP BY outcome ORDER BY count DESC";
            $stmt = $this->db()->prepare($sql);
            $stmt->bindValue(':d', $days, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Live blocks: IP bloccati di recente aggregati da waf_logs.
     *
     * Cross-source view: include TUTTI i blocked_* (manual, geo, threat_intel,
     * crowdsec, score, rule) — non solo waf_blocked_ips/waf_blacklist. Risolve
     * la frammentazione tra sorgenti di block (vedi /admin/waf/blocks).
     *
     * @return list<array{ip:string,country:?string,sources:string,last_outcome:string,count:int,first_seen:string,last_seen:string}>
     */
    public function liveBlocks(int $hours = 24, int $limit = 100): array
    {
        try {
            $sql = "SELECT ip, COUNT(*) AS count,
                    SUBSTRING_INDEX(GROUP_CONCAT(country ORDER BY ts DESC), ',', 1) AS country,
                    SUBSTRING_INDEX(GROUP_CONCAT(outcome ORDER BY ts DESC), ',', 1) AS last_outcome,
                    GROUP_CONCAT(DISTINCT outcome ORDER BY outcome) AS sources,
                    MIN(ts) AS first_seen,
                    MAX(ts) AS last_seen
                FROM waf_logs
                WHERE ts >= NOW() - INTERVAL :h HOUR
                  AND outcome LIKE 'blocked\\_%'
                GROUP BY ip
                ORDER BY count DESC, last_seen DESC
                LIMIT :lim";
            $stmt = $this->db()->prepare($sql);
            $stmt->bindValue(':h', max(1, $hours), PDO::PARAM_INT);
            $stmt->bindValue(':lim', max(1, min(500, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Cleanup log oltre retention (chiama da cron). */
    public function purgeOlderThan(int $days): int
    {
        try {
            $stmt = $this->db()->prepare('DELETE FROM waf_logs WHERE ts < NOW() - INTERVAL :d DAY');
            $stmt->bindValue(':d', max(1, $days), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function trunc(string $s, int $max): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) : $s;
    }
}
