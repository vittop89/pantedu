<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Phase G9 — Service modernizzato per print_info.
 *
 * Refactoring di App\Services\VerificheService::savePrintInfo / loadPrintInfo
 * (Phase 12 carryover) con:
 *   - input shape stabile e validato (DTO-like array)
 *   - return shape stabile (no mix di success/data/message)
 *   - errori dominio con codice stabile (RuntimeException msg)
 *   - separazione storage backend (DB + dual-write JSON) dietro interfaccia
 *
 * Storage:
 *   DB:   tabella `print_info` con (user_id, page_key, indirizzo, classe,
 *         materia, n_print, ...) — gia' esistente da Phase 12.
 *   JSON: print_info/{username}.json — solo come ripiego quando il DB non
 *         è disponibile (la doppia scrittura DB+JSON è stata tolta il
 *         2026-09-05).
 *
 * Page key: "{indirizzo}_{classe}_{materia}" (slug per row unique).
 */
final class PrintInfoService
{
    /**
     * Campi accettati dal payload save (whitelist).
     * G20.6 — estesi per coprire tutti i campi #wrapInfoSchool + #wrapInfoVer
     * (versione, studente nome/cognome, flag compensa/dsa/griglie/misure,
     * verTitle/verTitlePrefix). Mantiene la persistenza simmetrica con il
     * client che ora invia il payload completo.
     */
    private const SAVE_FIELDS = [
        // identificatori
        'indirizzo', 'classe', 'materia',
        // #wrapInfoSchool
        'sezione', 'anno', 'verTime',
        'nPrint', 'nPrintDSA', 'nPrintDIS',
        'addressSchool', 'istituto',
        'versione',
        // 'nome' e 'cognome' dello studente: **NON si salvano** (22/9/2026).
        // L'interfaccia li offre per stampare una verifica nominativa, e il
        // client li manda insieme al resto; il server li scartava? No: li
        // ammetteva, e finivano in chiaro in `extra_json`, sotto una chiave che
        // porta istituto e sezione. La DPIA dichiarava il contrario — «scritto
        // a mano dal docente DOPO la stampa, non registrato in DB» — e R20
        // fondava su questo il «nei campi strutturati nessun alunno è
        // identificabile, per costruzione».
        //
        // Misurato in produzione prima di togliere: zero righe su tre con quei
        // campi valorizzati. Nessun nome è mai stato salvato.
        //
        // Fuori da questa lista `normalize()` non li copia: restano nel
        // browser per la stampa e non arrivano al database. La funzione resta,
        // la conservazione no.
        // #wrapInfoVer
        'compensa', 'dsa', 'griglie', 'misure',
        'verTitlePrefix', 'verTitle',
    ];

    /** Required fields per identificare la chiave (page_key) univoca. */
    private const KEY_FIELDS = ['indirizzo', 'classe', 'materia'];

    public function save(string $username, array $payload): array
    {
        $clean = $this->normalize($payload, requireKey: true);
        $key   = $this->makeKey($clean);

        $useDb = $this->dbAvailable($username);
        if ($useDb) {
            $this->dbUpsert($username, $key, $clean);
        }
        if (!$useDb) {
            // G19.4 — JSON store ora teacher-scoped (`print_info/{username}.json`)
            // invece del file globale unico. Risolve la teacher-isolation bug:
            // due teacher con stesso `key` (ar_2s_MAT) non si sovrascrivono.
            $this->jsonUpsert($username, $key, $clean);
        }

        return [
            'key'      => $key,
            'data'     => $clean,
            'storage'  => $useDb ? 'db' : 'json',
        ];
    }

    public function load(string $username, array $query): ?array
    {
        $clean = $this->normalize($query, requireKey: true, partial: true);
        $key   = $this->makeKey($clean);

        // G19.4 — il JSON teacher-scoped contiene il payload COMPLETO
        // (sezione, anno, verTime, nPrintDSA, nPrintDIS, addressSchool); il
        // DB ha solo i 4 campi indicizzati. Quindi:
        //   1) leggi JSON (full payload)
        //   2) se assente → leggi DB (fallback minimo)
        // Se DB ha indirizzo/classe/materia diversi (incoerenza), prevalgono
        // quelli DB perché sono la fonte di verità per la query key.
        $data = $this->jsonRead($username);
        $jsonRow = $data[$key] ?? null;

        if ($this->dbAvailable($username)) {
            $dbRow = $this->dbLoad($username, $key);
            if ($jsonRow !== null) {
                // Merge: JSON wins for full fields, DB sovrascrive solo indirizzo/classe/materia
                return $dbRow !== null
                    ? array_merge($jsonRow, [
                        'indirizzo' => $dbRow['indirizzo'] ?? $jsonRow['indirizzo'] ?? null,
                        'classe'    => $dbRow['classe']    ?? $jsonRow['classe']    ?? null,
                        'materia'   => $dbRow['materia']   ?? $jsonRow['materia']   ?? null,
                    ])
                    : $jsonRow;
            }
            if ($dbRow !== null) {
                return $dbRow;
            }
        }
        return $jsonRow;
    }

    public function delete(string $username, array $query): bool
    {
        // G20.7 — se il client passa direttamente `page_key` (ottenuto da
        // listForUser), usa quello come fonte di verita': risolve la delete
        // dei record legacy con chiave 3-field (`sc_2s_MAT`) che makeKey
        // ricostruirebbe a 5-field (`sc_2s_MAT_B_`) se il client invia anche
        // `sezione` → mismatch → no delete.
        $explicitKey = trim((string)($query['page_key'] ?? ''));
        if ($explicitKey !== '') {
            return $this->deleteByKey($username, $explicitKey);
        }
        $clean = $this->normalize($query, requireKey: true, partial: true);
        $key   = $this->makeKey($clean);
        $deleted = false;

        if ($this->dbAvailable($username)) {
            $pdo = Database::connection();
            $uid = $this->resolveUserId($pdo, $username);
            if ($uid > 0) {
                $stmt = $pdo->prepare('DELETE FROM print_info_data WHERE user_id = ? AND page_key = ?');
                $stmt->execute([$uid, $key]);
                $deleted = $stmt->rowCount() > 0;
            }
        }
        if (!$this->dbAvailable($username)) {
            $data = $this->jsonRead($username);
            if (isset($data[$key])) {
                unset($data[$key]);
                $this->jsonWrite($username, $data);
                $deleted = true;
            }
        }
        return $deleted;
    }

    /** G20.7 — delete by explicit page_key (legacy 3-field o 5-field, indistinto).
     *  Usato quando il client passa il page_key letto da listForUser. */
    private function deleteByKey(string $username, string $key): bool
    {
        $deleted = false;
        if ($this->dbAvailable($username)) {
            $pdo = Database::connection();
            $uid = $this->resolveUserId($pdo, $username);
            if ($uid > 0) {
                $stmt = $pdo->prepare('DELETE FROM print_info_data WHERE user_id = ? AND page_key = ?');
                $stmt->execute([$uid, $key]);
                if ($stmt->rowCount() > 0) {
                    $deleted = true;
                }
            }
        }
        if (!$this->dbAvailable($username)) {
            $data = $this->jsonRead($username);
            if (isset($data[$key])) {
                unset($data[$key]);
                $this->jsonWrite($username, $data);
                $deleted = true;
            }
        }
        return $deleted;
    }

    /** Lista tutte le print_info del docente. Per UI dashboard / debug.
     *
     *  G19.18 — il DB ha solo i 4 campi indicizzati (indirizzo/classe/
     *  materia/n_print); i campi estesi (sezione/istituto/anno/verTime/
     *  nPrintDSA/nPrintDIS/addressSchool) vivono nel JSON teacher-scoped.
     *  Quindi: leggi PRIMA dal JSON (full payload), poi MERGE con DB rows
     *  che non hanno una corrispondente entry JSON (defensive: in caso di
     *  desync, no record perso).
     */
    public function listForUser(string $username): array
    {
        $byKey = [];
        // 1) JSON: payload completo (sezione/istituto/...)
        $jsonAll = $this->jsonRead($username);
        foreach ($jsonAll as $key => $row) {
            $row['page_key'] = $key;
            $byKey[$key] = $row;
        }
        // 2) DB: aggiungi entry mancanti dal JSON (fallback minimo)
        if ($this->dbAvailable($username)) {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'SELECT pi.* FROM print_info pi JOIN users u ON u.id = pi.user_id
                 WHERE u.username = ? ORDER BY pi.indirizzo, pi.classe, pi.materia'
            );
            $stmt->execute([$username]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $key = (string)($r['page_key'] ?? '');
                if ($key !== '' && !isset($byKey[$key])) {
                    $byKey[$key] = self::rowWithExtra($r);
                }
            }
        }
        return array_values($byKey);
    }

    // ─────── helpers ───────

    private function normalize(array $payload, bool $requireKey, bool $partial = false): array
    {
        $out = [];
        foreach (self::SAVE_FIELDS as $f) {
            if (array_key_exists($f, $payload)) {
                $out[$f] = trim((string)$payload[$f]);
            }
        }
        if ($requireKey) {
            foreach (self::KEY_FIELDS as $f) {
                if (empty($out[$f])) {
                    throw new RuntimeException("print_info_missing_field:$f");
                }
            }
        }
        if (!$partial) {
            $out['timestamp'] = date('Y-m-d H:i:s');
        }
        return $out;
    }

    /**
     * G19.18 — Chiave estesa con `sezione` + `istituto` per supportare
     * configurazioni distinte per (cls, sez, ind, ist, mat). Es:
     *   "ar_2_MAT_B_Esempio" vs "ar_2_MAT_C_Esempio"
     *   (stessa classe ma sezioni diverse → 2 record distinti).
     *
     * Back-compat: se sezione e istituto sono assenti/empty, fallback al
     * key 3-field legacy (`{ind}_{cls}_{mat}`) — i record salvati pre-G19.18
     * restano leggibili.
     */
    private function makeKey(array $clean): string
    {
        $base = $clean['indirizzo'] . '_' . $clean['classe'] . '_' . $clean['materia'];
        $sez  = trim((string)($clean['sezione']  ?? ''));
        $ist  = trim((string)($clean['istituto'] ?? ''));
        if ($sez === '' && $ist === '') {
            return $base;
        }
        // Slug istituto/sezione per evitare caratteri unsafe nel key
        $slugSez = preg_replace('/[^A-Za-z0-9_-]+/', '', $sez) ?: '';
        $slugIst = preg_replace('/[^A-Za-z0-9_-]+/', '', $ist) ?: '';
        return $base . '_' . $slugSez . '_' . $slugIst;
    }

    private function dbAvailable(string $username): bool
    {
        return Config::get('database.enabled')
            && Database::isAvailable()
            && $username !== '';
    }

    private function resolveUserId(PDO $pdo, string $username): int
    {
        return \App\Support\TeacherContextResolver::userIdFromUsername($username);
    }

    private function dbUpsert(string $username, string $key, array $clean): void
    {
        $pdo = Database::connection();
        $uid = $this->resolveUserId($pdo, $username);
        if ($uid <= 0) {
            return;
        }
        // Fase D — solo FK ids (varchar dropped). Migr 106 — i campi estesi
        // (sezione, istituto, anno, verTime, ...) stanno in extra_json: con la
        // fine della doppia scrittura JSON (2026-09-05) si perdevano al «Carica».
        $L = \App\Support\CurriculumLookup::class;
        $stmt = $pdo->prepare(
            'INSERT INTO print_info_data
                (user_id, page_key, indirizzo_id, classe_id, materia_id, n_print, extra_json)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                indirizzo_id=VALUES(indirizzo_id),
                classe_id=VALUES(classe_id),
                materia_id=VALUES(materia_id),
                n_print=VALUES(n_print),
                extra_json=VALUES(extra_json)'
        );
        $stmt->execute([
            $uid, $key,
            !empty($clean['indirizzo']) ? $L::idFromCodeForTeacher('indirizzi', (string)$clean['indirizzo'], $uid) : null,
            !empty($clean['classe'])    ? $L::idFromCodeForTeacher('classi', (string)$clean['classe'], $uid, !empty($clean['indirizzo']) ? (string)$clean['indirizzo'] : null) : null,
            !empty($clean['materia'])   ? $L::idFromCodeForTeacher('materie', (string)$clean['materia'], $uid) : null,
            (int)($clean['nPrint'] ?? 0),
            self::encodeExtra($clean),
        ]);
    }

    /**
     * Il payload normalizzato per intero: anche indirizzo/classe/materia, così
     * un codice non presente nel curriculum (FK nulla → colonna della vista
     * NULL) torna comunque com'era stato salvato.
     */
    private static function encodeExtra(array $clean): ?string
    {
        if ($clean === []) {
            return null;
        }
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return \is_string($json) ? $json : null;
    }

    /**
     * Riga della vista → record completo: i campi estesi tornano dal JSON,
     * la colonna grezza sparisce, la chiave resta come indirizzo/classe/materia
     * della vista (o dalla page_key se il codice non è risolvibile).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function rowWithExtra(array $row): array
    {
        $raw = $row['extra_json'] ?? null;
        unset($row['extra_json']);
        if (\is_string($raw) && $raw !== '') {
            $extra = json_decode($raw, true);
            if (\is_array($extra)) {
                foreach ($extra as $k => $v) {
                    if (!array_key_exists($k, $row) || $row[$k] === null) {
                        $row[$k] = $v;
                    }
                }
            }
        }
        if (isset($row['n_print']) && !isset($row['nPrint'])) {
            $row['nPrint'] = (string)$row['n_print'];
        }
        return $row;
    }

    private function dbLoad(string $username, string $key): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT pi.* FROM print_info pi JOIN users u ON u.id = pi.user_id
             WHERE u.username = ? AND pi.page_key = ? LIMIT 1'
        );
        $stmt->execute([$username, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::rowWithExtra($row) : null;
    }

    /** G19.4 — path teacher-scoped (per evitare cross-overwrite tra docenti).
     *  Username sanitizzato: solo `[a-zA-Z0-9._-]` (no path traversal). */
    private function jsonPath(string $username = ''): string
    {
        // Dati d'istanza (PANTEDU_DATA_PATH), non la radice del repository.
        // Questo è il ripiego usato solo quando il database non risponde
        // (save() scrive qui solo `if (!$useDb)`), ma il percorso lo calcolava
        // anche la lettura — e il mkdir che stava qui bastava a creare
        // `storage/data/print_info` vuota nel container a ogni pagina di
        // stampa aperta. La cartella adesso la fa solo chi scrive davvero
        // ({@see self::jsonWrite()}, che ha già il suo mkdir).
        $radice = \App\Support\PercorsiDati::base(dirname(__DIR__, 2));
        if ($username === '') {
            // Legacy fallback (file globale): mantenuto solo per migrazione
            // del file esistente (vedi jsonRead con dual-read).
            return $radice . '/storage/data/print_info.json';
        }
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $username) ?? 'unknown';
        return $radice . '/storage/data/print_info/' . $safe . '.json';
    }

    /** G19.4 — read teacher-scoped + (opzionale) merge col file legacy globale
     *  durante il periodo di migrazione. */
    private function jsonRead(string $username = ''): array
    {
        $f = $this->jsonPath($username);
        if (!is_file($f)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($f), true);
        return \is_array($data) ? $data : [];
    }

    private function jsonWrite(string $username, array $data): void
    {
        $path = $this->jsonPath($username);
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function jsonUpsert(string $username, string $key, array $payload): void
    {
        $data = $this->jsonRead($username);
        $data[$key] = $payload;
        $this->jsonWrite($username, $data);
    }
}
