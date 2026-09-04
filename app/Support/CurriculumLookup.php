<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Auth;
use App\Core\Database;
use PDO;

/**
 * G22.S20 v2.C2 Fase A — Single source of truth per la risoluzione tra
 * code stringa e ID FK in `curriculum_entries` per i 3 kind:
 *   - indirizzi  (es. SCI, ART, LIN)
 *   - classi     (es. 1, 2, 3, 1B)
 *   - materie    (es. MAT, FIS, GEO, CHI)
 *
 * Sostituisce il vecchio helper IndirizzoCode che è ora un thin wrapper
 * (back-compat). Usato dai Repository/Service per il dual write FK durante
 * la fase transitoria dual storage (varchar + indirizzo_id/classe_id/...).
 *
 * Cache statica per-request: ~20-50 entries totali nel catalog,
 * memoization su query iniziale ammortizza i lookup successivi.
 *
 * Convenzioni:
 *   - Tutti i kind hanno schema (kind, institute_id, code, label, active).
 *   - institute_id NULL = row "globali" (fallback quando teacher non ha
 *     match institute-specific, oppure tabelle senza teacher_id come exercises).
 *   - canonicalize($code): per indirizzi normalizza legacy lowercase
 *     ('sc'→'SCI' etc); per classi/materie ritorna UPPER (no special casing).
 *
 * Esempi:
 *   CurriculumLookup::idFromCode('indirizzi', 'sc', 108)   // → 172 (SCI in inst 108)
 *   CurriculumLookup::idFromCode('classi', '1', 108)       // → ID classe 1
 *   CurriculumLookup::codeFromId(172, 'indirizzi')          // → 'SCI'
 *   CurriculumLookup::idFromCodeForTeacher('materie', 'MAT', 77) // → auto-resolve institute
 */
final class CurriculumLookup
{
    /** @var array<string,int> Cache "{kind}|{code}|{instituteId}" → id */
    private static array $idCache = [];
    /** @var array<string,string> Cache "{id}|{kind}" → code */
    private static array $codeCache = [];

    /** Map legacy indirizzo lowercase → canonical UPPER. */
    private const INDIRIZZO_LEGACY_MAP = [
        'sc'   => 'SCI',
        'ar'   => 'ART',
        'cl'   => 'CLA',
        'li'   => 'LIN',
        'ling' => 'LIN',
        'af'   => 'AFM',
    ];

    /** Kind validi per curriculum_entries. */
    public const KINDS = ['indirizzi', 'classi', 'materie'];

    /**
     * Normalizza codice: per indirizzi rimappa legacy + UPPER; per classi/materie
     * ritorna trim+UPPER. Stringa vuota → ''.
     */
    public static function canonicalize(string $kind, ?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        $trimmed = trim($code);
        if ($kind === 'indirizzi') {
            $low = strtolower($trimmed);
            if (isset(self::INDIRIZZO_LEGACY_MAP[$low])) {
                return self::INDIRIZZO_LEGACY_MAP[$low];
            }
            return strtoupper($trimmed);
        }
        if ($kind === 'classi') {
            // Classi: rimuovi prefisso indirizzo legacy ART3/SCI1 → 3/1
            $cleaned = preg_replace('/^[A-Z]{3}/', '', $trimmed) ?? $trimmed;
            // G19.49 / ADR-024 — la forma canonica è SHORT ("2"), NON il legacy
            // "2s"/"2b"/"2S". shrink() allinea la WRITE alla read query
            // (ContentStudyController::studyFilters usa ClsNormalizer::shrink):
            // senza, "2s" veniva salvata "2S" e la query per "2" non matchava
            // → "Nessun item in questo topic". shrink è idempotente su "2".
            $short = ClsNormalizer::shrink(strtolower($cleaned));
            return strtoupper($short);
        }
        // Materie e altri: solo UPPER
        return strtoupper($trimmed);
    }

    /**
     * Risolve code → INT FK in curriculum_entries.
     *
     * Strategia lookup:
     *   - kind 'indirizzi'/'classi': filtra owner_user_id IS NULL (institute-level).
     *   - kind 'materie' con $ownerUserId fornito: filtra owner_user_id=$ownerUserId
     *     (per-docente). Se $ownerUserId NULL: ritorna riga institute-level (anchor
     *     usato da exercises). Match per (kind, code=canon, institute_id=$inst).
     *
     * @return int|null id FK, null se code non valido / non in catalog
     */
    public static function idFromCode(
        string $kind,
        ?string $code,
        ?int $instituteId = null,
        ?int $ownerUserId = null,
    ): ?int {
        $canon = self::canonicalize($kind, $code);
        if ($canon === '') {
            return null;
        }

        $cacheKey = $kind . '|' . $canon . '|' . ($instituteId ?? 'NULL') . '|' . ($ownerUserId ?? 'NULL');
        if (isset(self::$idCache[$cacheKey])) {
            return self::$idCache[$cacheKey];
        }

        $db = Database::connection();
        // G22.S22 — TUTTI i kind (indirizzi/classi/materie) sono per-docente
        // post-refactor full catalog ownership. Usiamo owner_user_id quando
        // disponibile, anchor (owner NULL) come fallback per exercises.
        $useOwner = in_array($kind, self::KINDS, true);

        if ($instituteId !== null) {
            if ($useOwner && $ownerUserId !== null) {
                $stmt = $db->prepare(
                    'SELECT id FROM curriculum_entries
                      WHERE kind = ? AND code = ? AND institute_id = ? AND owner_user_id = ?
                      LIMIT 1'
                );
                $stmt->execute([$kind, $canon, $instituteId, $ownerUserId]);
            } else {
                $stmt = $db->prepare(
                    'SELECT id FROM curriculum_entries
                      WHERE kind = ? AND code = ? AND institute_id = ? AND owner_user_id IS NULL
                      LIMIT 1'
                );
                $stmt->execute([$kind, $canon, $instituteId]);
            }
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                self::$idCache[$cacheKey] = (int)$id;
                self::$codeCache[$id . '|' . $kind] = $canon;
                return (int)$id;
            }
        }
        // Fallback institute-level globale (legacy indirizzi senza istituto)
        $stmt = $db->prepare(
            'SELECT id FROM curriculum_entries
              WHERE kind = ? AND code = ? AND institute_id IS NULL AND owner_user_id IS NULL
              LIMIT 1'
        );
        $stmt->execute([$kind, $canon]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        self::$idCache[$cacheKey] = (int)$id;
        self::$codeCache[$id . '|' . $kind] = $canon;
        return (int)$id;
    }

    /**
     * Inverso: id → code. Lookup veloce con cache.
     */
    public static function codeFromId(?int $id, ?string $kind = null): ?string
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        if ($kind !== null && isset(self::$codeCache[$id . '|' . $kind])) {
            return self::$codeCache[$id . '|' . $kind];
        }
        $sql = 'SELECT code, kind FROM curriculum_entries WHERE id = ?';
        $args = [$id];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $args[] = $kind;
        }
        $sql .= ' LIMIT 1';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        self::$codeCache[$id . '|' . $row['kind']] = (string)$row['code'];
        return (string)$row['code'];
    }

    /**
     * A quale istituto si attribuisce il lavoro di questo docente, adesso.
     *
     * Prima si prendeva sempre l'istituto con l'id piu' basso fra quelli a cui
     * il docente e' collegato. Per chi ne ha uno solo e' corretto e non cambia
     * niente. Per chi ne ha due e' un errore silenzioso: qualunque cosa scriva,
     * ovunque stia lavorando, finisce agganciata al vocabolario della scuola
     * che per caso ha l'id minore.
     *
     * Non e' un'ipotesi. Sul Esempio 229 contenuti erano finiti sul plesso
     * sbagliato per questo motivo — prima il docente era collegato al solo 108
     * e tutto si risolveva li'; dal collegamento al 106, che ha id minore,
     * tutto si e' spostato di la'. Nessun errore, nessun avviso: solo materiale
     * agganciato a un indirizzo che gli studenti di quella scuola non hanno.
     *
     * Ora vince l'istituto CORRENTE della sessione — quello che il docente ha
     * scelto nel selettore e che TenantMiddleware tiene aggiornato — con tre
     * condizioni, tutte necessarie:
     *
     *   1. deve esserci una sessione autenticata (da CLI non c'e', e va bene:
     *      gli strumenti a riga di comando passano l'istituto per argomento o
     *      lavorano su tutti);
     *   2. il docente da risolvere dev'essere l'utente collegato. Un admin che
     *      agisce sui contenuti di un altro non deve attribuirli alla PROPRIA
     *      scuola corrente: li' l'id piu' basso e' meno sbagliato di un
     *      istituto che col docente non c'entra;
     *   3. il docente dev'essere davvero collegato a quell'istituto, altrimenti
     *      si scriverebbe su una scuola in cui non lavora.
     *
     * Fuori da queste, si torna al comportamento di prima. Che resta imperfetto
     * per i pluri-istituto, ma e' l'unica cosa sensata quando non si sa dove
     * l'utente stia lavorando.
     */
    public static function instituteForTeacher(?int $teacherId): ?int
    {
        if ($teacherId === null || $teacherId <= 0) {
            return null;
        }

        $corrente = self::currentInstituteIfOwn($teacherId);
        // La cache include l'istituto corrente: cambiare scuola dal selettore
        // deve cambiare la risposta, non pescare quella di prima.
        $chiave = $teacherId . '|' . ($corrente ?? 0);
        static $cache = [];
        if (array_key_exists($chiave, $cache)) {
            return $cache[$chiave];
        }
        if ($corrente !== null) {
            return $cache[$chiave] = $corrente;
        }

        $stmt = Database::connection()->prepare(
            'SELECT institute_id FROM teacher_institutes
              WHERE user_id = ? ORDER BY institute_id ASC LIMIT 1'
        );
        $stmt->execute([$teacherId]);
        $id = $stmt->fetchColumn();
        return $cache[$chiave] = $id !== false ? (int)$id : null;
    }

    /**
     * L'istituto corrente, ma solo se e' davvero di questo docente e lui ci
     * lavora. Null in tutti gli altri casi, compreso quello della CLI.
     */
    private static function currentInstituteIfOwn(int $teacherId): ?int
    {
        try {
            if (!Auth::check()) {
                return null;
            }
            if ((int)(Auth::user()['id'] ?? 0) !== $teacherId) {
                return null;
            }
            $corrente = (int)(Auth::currentInstitute() ?? 0);
            if ($corrente <= 0) {
                return null;
            }
            $chk = Database::connection()->prepare(
                'SELECT 1 FROM teacher_institutes WHERE user_id = ? AND institute_id = ? LIMIT 1'
            );
            $chk->execute([$teacherId, $corrente]);
            return $chk->fetchColumn() ? $corrente : null;
        } catch (\Throwable) {
            // Un problema nel leggere la sessione non deve impedire di salvare:
            // si ricade sul comportamento storico.
            return null;
        }
    }

    /**
     * Helper combinato: code + teacherId → id (auto-resolves institute_id).
     *
     * G22.S22 — Tutti i kind sono per-docente. La lookup usa owner_user_id=
     * $teacherId. Se non esiste la riga per-teacher, viene auto-creata
     * clonando l'institute anchor (riga owner_user_id IS NULL).
     */
    public static function idFromCodeForTeacher(string $kind, ?string $code, ?int $teacherId): ?int
    {
        $inst = self::instituteForTeacher($teacherId);
        if ($teacherId === null || $teacherId <= 0) {
            return self::idFromCode($kind, $code, $inst);
        }
        $id = self::idFromCode($kind, $code, $inst, $teacherId);
        if ($id !== null) {
            return $id;
        }
        return self::ensureEntryForTeacher($kind, $teacherId, (string)$code, $inst);
    }

    /**
     * G22.S22 — Auto-crea riga per-docente clonando anchor institute-level
     * per qualunque kind (indirizzi/classi/materie). Idempotente.
     * Ritorna null se anchor mancante.
     */
    public static function ensureEntryForTeacher(string $kind, int $teacherId, string $code, ?int $instituteId): ?int
    {
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }
        $canon = self::canonicalize($kind, $code);
        if ($canon === '' || $instituteId === null) {
            return null;
        }

        $db = Database::connection();
        // 1. Anchor institute-level
        $stmt = $db->prepare(
            'SELECT id, label, grp, indirizzo, active FROM curriculum_entries
              WHERE kind = ? AND code = ? AND institute_id = ? AND owner_user_id IS NULL
              LIMIT 1'
        );
        $stmt->execute([$kind, $canon, $instituteId]);
        $anchor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$anchor) {
            return null;
        }

        // 2. INSERT IGNORE
        //
        // `indirizzo` va copiato come tutto il resto. Restava indietro, e per le
        // CLASSI non e' un dettaglio: e' il campo che dice a quale corso
        // appartiene la sezione (migration 100). Senza, la copia del docente
        // diventa una sezione trasversale che ricompare sotto ogni indirizzo —
        // ed e' cio' che i selettori mostrano, quindi il filtro per corso non
        // poteva funzionare per nessuno.
        $ins = $db->prepare(
            'INSERT IGNORE INTO curriculum_entries
                (kind, institute_id, owner_user_id, code, label, grp, indirizzo, active, shared_with_pool)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $ins->execute([
            $kind, $instituteId, $teacherId, $canon,
            (string)$anchor['label'], $anchor['grp'], $anchor['indirizzo'], (int)$anchor['active'],
        ]);

        // 3. Re-lookup
        $stmt = $db->prepare(
            'SELECT id FROM curriculum_entries
              WHERE kind = ? AND code = ? AND institute_id = ? AND owner_user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$kind, $canon, $instituteId, $teacherId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        $cacheKey = $kind . '|' . $canon . '|' . $instituteId . '|' . $teacherId;
        self::$idCache[$cacheKey] = (int)$id;
        self::$codeCache[$id . '|' . $kind] = $canon;
        return (int)$id;
    }

    /** @deprecated G22.S22 — usa ensureEntryForTeacher('materie', ...). */
    public static function ensureMateriaForTeacher(int $teacherId, string $code, ?int $instituteId): ?int
    {
        return self::ensureEntryForTeacher('materie', $teacherId, $code, $instituteId);
    }

    /**
     * Fase C — Preload completo del catalog all'init bootstrap.
     * Carica TUTTI gli indirizzi/classi/materie in cache statica con
     * 1 sola query, evitando lookup ripetuti durante la request.
     *
     * Idempotente: chiamabile più volte senza effetti collaterali.
     */
    public static function preload(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        try {
            $stmt = Database::connection()->query(
                'SELECT id, kind, institute_id, owner_user_id, code
                   FROM curriculum_entries
                  WHERE kind IN ("indirizzi", "classi", "materie")
                    AND active = 1'
            );
            foreach ($stmt as $r) {
                // G22.S21 — chiave cache include owner_user_id (4-dimensional).
                $cacheKey = $r['kind'] . '|' . $r['code']
                    . '|' . ($r['institute_id'] ?? 'NULL')
                    . '|' . ($r['owner_user_id'] ?? 'NULL');
                self::$idCache[$cacheKey] = (int)$r['id'];
                self::$codeCache[$r['id'] . '|' . $r['kind']] = (string)$r['code'];
            }
            $loaded = true;
        } catch (\Throwable) {
            // best-effort: se DB non disponibile, lookup runtime farà fallback
        }
    }

    /** Reset cache (solo testing). */
    public static function resetCache(): void
    {
        self::$idCache = [];
        self::$codeCache = [];
    }
}
