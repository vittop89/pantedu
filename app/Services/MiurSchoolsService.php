<?php

namespace App\Services;

use App\Core\Config;
use RuntimeException;

/**
 * Phase 14 — MIUR schools search (server-side only).
 *
 * Sorgenti: storage/data/scuole_miur.json (statali) + scuole_miur_paritarie.json
 * (paritarie), formato JSON-LD "@graph":[{miur:...}]. Mai esposte al client.
 * Questo servizio:
 *   - legge le sorgenti UNA volta, proietta i campi rilevanti in un indice
 *     compatto memorizzato in storage/cache/scuole_miur_index.json
 *   - rigenera l'indice SOLO se mtime di una sorgente > mtime indice
 *   - mantiene un memo in-process (static) per richieste consecutive
 *
 * Aggiornamento sorgenti: pannello /admin/institutes (super_admin) scarica i
 * file dal catalogo opendata MIUR (dati.istruzione.it) → vedi
 * AdminInstitutesController::miurUpdate().
 *
 * API search(q, limit) fa stripos multibyte sul denominazione+comune.
 */
final class MiurSchoolsService
{
    private const FIELDS = [
        'denom' => 'miur:DENOMINAZIONESCUOLA',
        'type'  => 'miur:DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA',
        'city'  => 'miur:DESCRIZIONECOMUNE',
        'prov'  => 'miur:PROVINCIA',
        'reg'   => 'miur:REGIONE',
        'code'  => 'miur:CODICESCUOLA',
    ];

    /** @var list<array{denom:string,type:string,city:string,prov:string,reg:string,code:string}>|null */
    private static ?array $memo = null;

    /**
     * @param list<string> $sourcePaths file JSON-LD sorgente (uno o più; uniti
     *                                   nell'indice nell'ordine fornito)
     */
    public function __construct(
        private readonly array $sourcePaths,
        private readonly string $indexPath,
    ) {
    }

    public static function fromConfig(): self
    {
        $storage = (string)Config::get('app.paths.storage');
        return new self(
            sourcePaths: [
                $storage . '/data/scuole_miur.json',            // statali (nome storico, back-compat)
                $storage . '/data/scuole_miur_paritarie.json',  // paritarie
            ],
            indexPath:  $storage . '/cache/scuole_miur_index.json',
        );
    }

    /**
     * @return list<array{denom:string,type:string,city:string,prov:string,reg:string,code:string}>
     */
    public function search(string $query, int $limit = 15): array
    {
        $q = trim($query);
        if (mb_strlen($q) < 3) {
            return [];
        }
        $limit = max(1, min($limit, 25));

        // Multi-word AND: "Esempio Comune Esempio" matcha chiavi che contengono
        // entrambe le parole (in qualsiasi ordine). Parole < 2 ignorate.
        $words = preg_split('/\s+/', $this->normalize($q)) ?: [];
        $words = array_filter($words, fn($w) => mb_strlen((string)$w) >= 2);
        if (!$words) {
            return [];
        }

        $index = $this->loadIndex();
        $out   = [];
        foreach ($index as $row) {
            $key = $row['_key'];
            $allIn = true;
            foreach ($words as $w) {
                if (strpos($key, $w) === false) {
                    $allIn = false;
                    break;
                }
            }
            if (!$allIn) {
                continue;
            }
            unset($row['_key']);
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Elenco tipologie (DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA) distinte
     * per una DENOMINAZIONESCUOLA (esatta, case-insensitive). Serve al passo
     * "seleziona indirizzo" post-scelta istituto.
     *
     * @return list<string>
     */
    public function typesForDenomination(string $denom): array
    {
        $needle = $this->normalize($denom);
        $out = [];
        foreach ($this->loadIndex() as $row) {
            if ($this->normalize($row['denom']) === $needle && $row['type'] !== '') {
                $out[$row['type']] = true;
            }
        }
        ksort($out);
        return array_keys($out);
    }

    /**
     * Stato dei file sorgente (per il pannello admin: esiste? size, mtime).
     *
     * @return list<array{path:string,name:string,exists:bool,size:int,mtime:?int}>
     */
    public function sourcesStatus(): array
    {
        $out = [];
        foreach ($this->sourcePaths as $sp) {
            $exists = is_file($sp);
            $out[] = [
                'path'   => $sp,
                'name'   => basename($sp),
                'exists' => $exists,
                'size'   => $exists ? (int)filesize($sp) : 0,
                'mtime'  => $exists ? filemtime($sp) : null,
            ];
        }
        return $out;
    }

    /**
     * Stato indice compatto (esiste? size, mtime). NON forza il rebuild
     * (cheap: solo stat filesystem) per non rallentare il page-load admin.
     *
     * @return array{exists:bool,size:int,mtime:?int}
     */
    public function indexStatus(): array
    {
        $exists = is_file($this->indexPath);
        return [
            'exists' => $exists,
            'size'   => $exists ? (int)filesize($this->indexPath) : 0,
            'mtime'  => $exists ? filemtime($this->indexPath) : null,
        ];
    }

    /**
     * Forza la rigenerazione dell'indice dalle sorgenti correnti.
     * Ritorna il numero di record indicizzati. Usato post-download admin.
     */
    public function rebuild(): int
    {
        self::$memo = null;
        return $this->rebuildIndex();
    }

    // ───────────── internals ─────────────

    /** @return list<array{denom:string,type:string,city:string,prov:string,reg:string,code:string,_key:string}> */
    private function loadIndex(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $needsRebuild = !is_file($this->indexPath);
        if (!$needsRebuild) {
            $idxMtime = filemtime($this->indexPath);
            foreach ($this->sourcePaths as $sp) {
                if (is_file($sp) && filemtime($sp) > $idxMtime) {
                    $needsRebuild = true;
                    break;
                }
            }
        }
        if ($needsRebuild) {
            $this->rebuildIndex();
        }
        $raw = @file_get_contents($this->indexPath);
        if ($raw === false) {
            throw new RuntimeException('miur_index_read_failed');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('miur_index_corrupt');
        }
        self::$memo = $data;
        return self::$memo;
    }

    /** Rigenera l'indice unendo tutte le sorgenti esistenti. @return int record. */
    private function rebuildIndex(): int
    {
        $out = [];
        $any = false;
        foreach ($this->sourcePaths as $sp) {
            if (!is_file($sp)) {
                continue; // sorgente opzionale (es. paritarie non ancora scaricate)
            }
            $any = true;
            $raw = file_get_contents($sp);
            if ($raw === false) {
                throw new RuntimeException('miur_source_read_failed');
            }
            $data = json_decode($raw, true);
            unset($raw); // libera la stringa grezza (file grandi)
            $rows = $data['@graph'] ?? null;
            if (!is_array($rows)) {
                throw new RuntimeException('miur_source_malformed');
            }
            foreach ($rows as $r) {
                $denom = trim((string)($r[self::FIELDS['denom']] ?? ''));
                if ($denom === '') {
                    continue;
                }
                $city = trim((string)($r[self::FIELDS['city']] ?? ''));
                $type = trim((string)($r[self::FIELDS['type']] ?? ''));
                $prov = trim((string)($r[self::FIELDS['prov']] ?? ''));
                $reg  = trim((string)($r[self::FIELDS['reg']]  ?? ''));
                $code = trim((string)($r[self::FIELDS['code']] ?? ''));
                $out[] = [
                    'denom' => $denom,
                    'type'  => $type,
                    'city'  => $city,
                    'prov'  => $prov,
                    'reg'   => $reg,
                    'code'  => $code,
                    '_key'  => $this->normalize($denom . ' ' . $city),
                ];
            }
            unset($data, $rows);
        }
        if (!$any) {
            throw new RuntimeException('miur_source_missing');
        }

        $dir = dirname($this->indexPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('miur_cache_dir_failed');
        }
        $tmp = $this->indexPath . '.tmp';
        if (file_put_contents($tmp, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new RuntimeException('miur_index_write_failed');
        }
        if (!rename($tmp, $this->indexPath)) {
            @unlink($tmp);
            throw new RuntimeException('miur_index_rename_failed');
        }
        return count($out);
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return (string)($ascii !== false ? $ascii : $s);
    }
}
