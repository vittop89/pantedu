<?php

namespace App\Repositories;

use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use PDO;

/**
 * Repository per la tabella `teacher_content` (Phase 13 — multi-materia
 * multi-tipo). Affianca `ExerciseRepository` (legacy admin-imports).
 *
 * Tipi di content supportati: mappa | esercizio | lab | verifica.
 * subject_code è libero (qualunque codice presente in
 * curriculum_entries kind=materie). Visibility: draft|published|archived.
 *
 * Phase 25.D3 — envelope encryption dual-write:
 *   - `CRYPTO_DUAL_WRITE=true` (env) → ogni create/update popola ANCHE
 *     body_html_ct/iv/tag/kv e body_pt_ct/iv/tag/kv (estratto da metadata.body_pt).
 *     Plaintext columns restano popolate per backward-compat durante backfill.
 *   - `CRYPTO_READ_FROM=ciphertext` (env, default plaintext) → find() decifra
 *     da `*_ct` e ignora plaintext (Phase D13 post-backfill verificato).
 *
 * Note: metadata_json resta plaintext (necessario per JSON_EXTRACT queries
 * su stats.has_tikz, stats.difficulty_max, etc.). Solo `metadata.body_pt`
 * (sensitive content del docente) viene estratto e cifrato separatamente.
 */
class TeacherContentRepository
{
    // Opzione A (migration 078) — content_type collassato a 4 valori. I nomi
    // legacy non avevano branching reale: lab → esercizio ;
    // bes/risdoc/didattica/documento → document. Resta esercizio≠verifica
    // (filtro esami + accoppiamento pool + path eser/verifiche).
    public const TYPES = ['mappa', 'esercizio', 'verifica', 'document'];

    /**
     * ADR-027 — FORMATO di rendering derivato dal content_type. È la VERA
     * distinzione (come si renderizza un documento), separata dal nome/pannello:
     *   - map      → iframe/drawio (mappa, link, file)
     *   - exercise → contract strutturato (esercizio/verifica)
     *   - document → Portable Text libero (document = ex bes/risdoc/didattica)
     * I branch di rendering/creazione devono usare formatOf(), NON i nomi-tipo.
     */
    public static function formatOf(string $type): string
    {
        return match ($type) {
            'mappa' => 'map',
            'esercizio', 'verifica' => 'exercise',
            default => 'document',
        };
    }
    // Phase 19 — valori source-of-truth: \App\Domain\ContentVisibility.
    // Manteniamo costante array per retro-compat con call-site string-based.
    public const VISIBILITIES = ['draft', 'published', 'archived'];

    private ?TeacherCryptoService $crypto = null;

    public function __construct(?TeacherCryptoService $crypto = null)
    {
        // Phase 25.D3 — lazy: il crypto service è creato solo se necessario
        // (feature flag on). Permette test legacy senza KMS configurato.
        $this->crypto = $crypto;
    }

    /** Phase 25.D3 — true se dual-write encryption è attivo. */
    private function dualWriteEnabled(): bool
    {
        return ($_ENV['CRYPTO_DUAL_WRITE'] ?? '') === '1'
            || ($_ENV['CRYPTO_DUAL_WRITE'] ?? '') === 'true';
    }

    /** Phase 25.D3 — true se le read devono usare i ciphertext (post-backfill). */
    private function readFromCiphertext(): bool
    {
        return ($_ENV['CRYPTO_READ_FROM'] ?? '') === 'ciphertext';
    }

    /** Lazy crypto service. */
    private function crypto(): TeacherCryptoService
    {
        if ($this->crypto === null) {
            $this->crypto = new TeacherCryptoService();
        }
        return $this->crypto;
    }

    /**
     * @param array{
     *   teacher_id?: int,
     *   content_type?: string|string[],
     *   subject_code?: string,
     *   indirizzo?: string,
     *   classe?: string,
     *   topic?: string,
     *   visibility?: string|string[],
     *   q?: string,
     *   limit?: int,
     *   offset?: int,
     * } $f
     * @return list<array>
     */
    public function search(array $f = []): array
    {
        $where = [];
        $args = [];

        if (!empty($f['teacher_id'])) {
            $where[] = 'teacher_id = ?';
            $args[] = (int)$f['teacher_id'];
        }
        // Phase 25.Q — scope per istituto: filtra solo contenuti di docenti
        // membri dell'istituto specificato (via pivot teacher_institutes).
        // Usato da admin-istituto e tenant-aware queries.
        if (!empty($f['institute_id'])) {
            $where[] = 'teacher_id IN (SELECT user_id FROM teacher_institutes WHERE institute_id = ?)';
            $args[] = (int)$f['institute_id'];
        }
        if (!empty($f['content_type'])) {
            $types = (array)$f['content_type'];
            $place = implode(',', array_fill(0, count($types), '?'));
            $where[] = "content_type IN ($place)";
            foreach ($types as $t) {
                $args[] = (string)$t;
            }
        }
        // ADR-027 / migr 079 — filtro per ASSE di rendering (content_format:
        // map/exercise/document), generato dal DB. Evita formatOf() lato app.
        if (!empty($f['content_format'])) {
            $formats = (array)$f['content_format'];
            $place = implode(',', array_fill(0, count($formats), '?'));
            $where[] = "content_format IN ($place)";
            foreach ($formats as $ff) {
                $args[] = (string)$ff;
            }
        }
        if (!empty($f['subject_code'])) {
            $where[] = 'subject_code = ?';
            $args[] = (string)$f['subject_code'];
        }
        // Phase 18 — scope filter STRICT: NULL rows sono admin-only
        // e non vengono esposte nelle route scoped /studio/*.
        // Admin all-view esplicito richiede include_unscoped=true.
        $includeUnscoped = !empty($f['include_unscoped']);
        // Migration 069 — scope-aware student matching. Oltre alla propria
        // (indirizzo,classe) lo studente vede anche:
        //   - publish_scope='general'  → tutti, gated dal filtro subject_code
        //     (clausola AND separata: niente fuga cross-materia);
        //   - publish_scope='classes'  → solo se la coppia è tra i target.
        // Gated dietro 'student_scope' per NON alterare le query docente/admin
        // (che filtrano per teacher_id/istituto e devono vedere solo le proprie).
        $studentScope = !empty($f['student_scope'])
            && !empty($f['indirizzo']) && !empty($f['classe']);
        if ($studentScope) {
            $where[] = '('
                . "(publish_scope = 'class' AND indirizzo = ? AND classe = ?)"
                . " OR publish_scope = 'general'"
                . " OR (publish_scope = 'classes' AND EXISTS ("
                .     'SELECT 1 FROM content_target_classes t '
                .     'WHERE t.content_id = teacher_content.id '
                .     'AND t.indirizzo = ? AND t.classe = ?))'
                . ')';
            $args[] = (string)$f['indirizzo'];
            $args[] = (string)$f['classe'];
            $args[] = (string)$f['indirizzo'];
            $args[] = (string)$f['classe'];
        } else {
            if (!empty($f['indirizzo'])) {
                if ($includeUnscoped) {
                    $where[] = '(indirizzo = ? OR indirizzo IS NULL)';
                    $args[] = (string)$f['indirizzo'];
                } else {
                    $where[] = 'indirizzo = ?';
                    $args[] = (string)$f['indirizzo'];
                }
            }
            if (!empty($f['classe'])) {
                if ($includeUnscoped) {
                    $where[] = '(classe = ? OR classe IS NULL)';
                    $args[] = (string)$f['classe'];
                } else {
                    $where[] = 'classe = ?';
                    $args[] = (string)$f['classe'];
                }
            }
        }
        if (!empty($f['topic'])) {
            $where[] = 'topic = ?';
            $args[] = (string)$f['topic'];
        }
        // ADR-027 — filtro per sezione sidebar (loader unico per section_id).
        if (!empty($f['section_id'])) {
            $where[] = 'section_id = ?';
            $args[] = (int)$f['section_id'];
        }
        // ADR-027 Step 8 — esclude i contenuti delle sezioni nascoste al ruolo
        // (visibilità ancorata alla sezione). section_id NULL = legacy → ammesso
        // (cade sul filtro content_type/scope già applicato).
        if (!empty($f['section_id_not_in']) && is_array($f['section_id_not_in'])) {
            $ids = array_values(array_unique(array_map('intval', $f['section_id_not_in'])));
            $place = implode(',', array_fill(0, count($ids), '?'));
            $where[] = "(section_id IS NULL OR section_id NOT IN ($place))";
            foreach ($ids as $id) {
                $args[] = $id;
            }
        }
        if (!empty($f['visibility'])) {
            $vs = (array)$f['visibility'];
            $place = implode(',', array_fill(0, count($vs), '?'));
            $where[] = "visibility IN ($place)";
            foreach ($vs as $v) {
                $args[] = (string)$v;
            }
        }
        if (!empty($f['q'])) {
            $where[] = '(title LIKE ? OR topic LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$f['q']) . '%';
            $args[] = $like;
            $args[] = $like;
        }
        // Phase 16 Step 4 — filtri su stats denormalizzate in metadata_json.stats.
        // Popolate da ContractRepository::save, usabili qui senza parsing del
        // contract JSON. MySQL JSON path: $.stats.<field>.
        if (!empty($f['has_tikz'])) {
            $where[] = "JSON_EXTRACT(metadata_json, '$.stats.has_tikz') = TRUE";
        }
        if (!empty($f['has_vf'])) {
            $where[] = "JSON_EXTRACT(metadata_json, '$.stats.has_vf') = TRUE";
        }
        if (!empty($f['has_rm'])) {
            $where[] = "JSON_EXTRACT(metadata_json, '$.stats.has_rm') = TRUE";
        }
        if (isset($f['difficulty_max_gte'])) {
            $where[] = "JSON_EXTRACT(metadata_json, '$.stats.difficulty_max') >= ?";
            $args[] = (int)$f['difficulty_max_gte'];
        }
        if (isset($f['item_count_gte'])) {
            $where[] = "JSON_EXTRACT(metadata_json, '$.stats.item_count') >= ?";
            $args[] = (int)$f['item_count_gte'];
        }
        if (!empty($f['source_code'])) {
            // JSON_CONTAINS: verifica che il source_code sia in stats.source_codes[]
            $where[] = "JSON_CONTAINS(JSON_EXTRACT(metadata_json, '$.stats.source_codes'), ?)";
            $args[] = (string)json_encode((string)$f['source_code']);
        }

        // Phase 24.49 — opt-in proiezione completa con metadata_json.
        // Default lean (sidebar/list): proiezione minimale per bandwidth.
        // with_metadata=1 → include metadata_json (necessario per merge
        // category/scope nelle sidepage). body_html resta sempre fuori.
        $cols = 'id, teacher_id, content_type, content_format, subject_code, indirizzo, classe, topic, title, visibility, created_at, updated_at';
        if (!empty($f['with_metadata'])) {
            $cols .= ', metadata_json';
        }
        $sql = "SELECT $cols FROM teacher_content";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY content_type, subject_code, topic, title';
        $limit  = max(1, min(500, (int)($f['limit']  ?? 100)));
        $offset = max(0, (int)($f['offset'] ?? 0));
        $sql .= " LIMIT $limit OFFSET $offset";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Phase 17 — variante "lean" di `search`: ritorna solo le colonne
     * indispensabili per sidebar/topic list (no body_html, no metadata_json).
     * Risparmia bandwidth + json_decode lato client per render rapidi.
     */
    public function searchLean(array $f = []): array
    {
        // Riusa la stessa logica di search() ma SELECT colonne ridotte.
        // Semplice: chiama search() poi strippa i campi pesanti se presenti.
        // (Acceptable: search() non restituisce body_html/metadata_json nella
        //  proiezione di default, quindi è già lean — questo metodo è un
        //  alias semantico per rendere esplicita l'intent al call site.)
        return $this->search($f);
    }

    /** Ritorna un token rappresentativo per ETag su lista (hash di
     *  max(updated_at) + COUNT delle righe filtrate). Calcolabile senza
     *  caricare le righe. Usato dai controller per generare ETag conditional. */
    public function listSignature(array $f): string
    {
        $where = [];
        $args = [];
        if (!empty($f['teacher_id'])) {
            $where[] = 'teacher_id = ?';
            $args[] = (int)$f['teacher_id'];
        }
        if (!empty($f['content_type'])) {
            $where[] = 'content_type = ?';
            $args[] = (string)$f['content_type'];
        }
        if (!empty($f['subject_code'])) {
            $where[] = 'subject_code = ?';
            $args[] = (string)$f['subject_code'];
        }
        if (!empty($f['visibility'])) {
            $where[] = 'visibility = ?';
            $args[] = (string)$f['visibility'];
        }
        $sql = 'SELECT COALESCE(MAX(updated_at), 0) AS m, COUNT(*) AS n FROM teacher_content';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $st = Database::connection()->prepare($sql);
        $st->execute($args);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['m' => '0', 'n' => 0];
        return (string)$row['m'] . ':' . (string)$row['n'];
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM teacher_content WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        if (isset($row['metadata_json']) && $row['metadata_json']) {
            $row['metadata'] = json_decode((string)$row['metadata_json'], true) ?: [];
        } else {
            $row['metadata'] = [];
        }

        // Phase 25.D3 — read path: se CRYPTO_READ_FROM=ciphertext e i campi
        // *_ct sono popolati, decifra. Altrimenti legacy plaintext da
        // body_html / metadata.body_pt.
        if ($this->readFromCiphertext()) {
            $this->decryptInto($row);
        }

        return $row;
    }

    /**
     * Phase 25.D3 — decifra body_html e body_pt sul row. Se ct mancante,
     * lascia il valore plaintext esistente (backward-compat durante backfill).
     */
    private function decryptInto(array &$row): void
    {
        $tid = (int)$row['teacher_id'];
        if ($tid === 0) {
            return;
        }

        // body_html_ct
        if (!empty($row['body_html_ct']) && !empty($row['body_html_iv']) && !empty($row['body_html_tag'])) {
            try {
                $plain = $this->crypto()->decrypt($tid, [
                    'ciphertext' => $row['body_html_ct'],
                    'iv'         => $row['body_html_iv'],
                    'tag'        => $row['body_html_tag'],
                    'kv'         => (int)$row['body_html_kv'],
                ]);
                $row['body_html'] = $plain;
            } catch (\Throwable $e) {
                // Crypto-shredding (Art. 17): teacher_key cancellato → body
                // illeggibile. Marker speciale al consumer (Repository owner
                // dovrebbe filtrare via canView).
                $row['body_html'] = null;
                $row['_crypto_error'] = $e->getMessage();
            }
        }

        // body_pt_ct (extracted from metadata.body_pt prima di encrypt)
        if (!empty($row['body_pt_ct']) && !empty($row['body_pt_iv']) && !empty($row['body_pt_tag'])) {
            try {
                $plain = $this->crypto()->decrypt($tid, [
                    'ciphertext' => $row['body_pt_ct'],
                    'iv'         => $row['body_pt_iv'],
                    'tag'        => $row['body_pt_tag'],
                    'kv'         => (int)$row['body_pt_kv'],
                ]);
                $bodyPt = json_decode($plain, true);
                if (is_array($bodyPt)) {
                    $row['metadata']['body_pt'] = $bodyPt;
                }
            } catch (\Throwable $e) {
                $row['_crypto_error'] = $e->getMessage();
            }
        }
    }

    /**
     * @param array{
     *   teacher_id:int, content_type:string, subject_code:string,
     *   indirizzo?:?string, classe?:?string, topic?:string, title:string,
     *   body_html?:?string, metadata?:array, visibility?:string,
     * } $data
     */
    public function create(array $data): int
    {
        $this->validate($data);
        $teacherId = (int)$data['teacher_id'];
        $bodyHtml  = $data['body_html'] ?? null;
        $metadata  = $data['metadata'] ?? null;

        // Phase 25.D3 — dual-write: se feature flag on, encrypt body_html +
        // body_pt (extracted da metadata) e salva anche nelle colonne *_ct.
        $bodyPtCt = $bodyPtIv = $bodyPtTag = null;
        $bodyPtKv = null;
        $bodyHtmlCt = $bodyHtmlIv = $bodyHtmlTag = null;
        $bodyHtmlKv = null;

        if ($this->dualWriteEnabled() && $teacherId > 0) {
            [$metadata, $bodyPtPlain] = $this->extractBodyPt($metadata);
            if ($bodyPtPlain !== null) {
                $env = $this->crypto()->encrypt($teacherId, $bodyPtPlain);
                $bodyPtCt = $env['ciphertext'];
                $bodyPtIv = $env['iv'];
                $bodyPtTag = $env['tag'];
                $bodyPtKv = $env['kv'];
            }
            if (is_string($bodyHtml) && $bodyHtml !== '') {
                $env = $this->crypto()->encrypt($teacherId, $bodyHtml);
                $bodyHtmlCt = $env['ciphertext'];
                $bodyHtmlIv = $env['iv'];
                $bodyHtmlTag = $env['tag'];
                $bodyHtmlKv = $env['kv'];
            }
        }

        // G22.S20 v2.C2 — Dual write per indirizzo/classe/subject_code + FK ids.
        $indRaw = $data['indirizzo'] ?? null;
        $indId = $indRaw !== null && $indRaw !== ''
            ? \App\Support\CurriculumLookup::idFromCodeForTeacher('indirizzi', (string)$indRaw, $teacherId) : null;
        $indCanon = $indRaw !== null && $indRaw !== ''
            ? \App\Support\CurriculumLookup::canonicalize('indirizzi', (string)$indRaw) : null;

        $clsRaw = $data['classe'] ?? null;
        $clsId = $clsRaw !== null && $clsRaw !== ''
            ? \App\Support\CurriculumLookup::idFromCodeForTeacher('classi', (string)$clsRaw, $teacherId) : null;
        $clsCanon = $clsRaw !== null && $clsRaw !== ''
            ? \App\Support\CurriculumLookup::canonicalize('classi', (string)$clsRaw) : null;

        $subjRaw = $data['subject_code'] ?? null;
        $subjId = $subjRaw !== null && $subjRaw !== ''
            ? \App\Support\CurriculumLookup::idFromCodeForTeacher('materie', (string)$subjRaw, $teacherId) : null;
        $subjCanon = $subjRaw !== null && $subjRaw !== ''
            ? \App\Support\CurriculumLookup::canonicalize('materie', (string)$subjRaw) : $subjRaw;

        // G22.S20 v2.C2 Fase D — write solo FK ids (varchar dropped).
        // ADR-027 Step 5-6 — section_id: ancora il contenuto alla sezione
        // sidebar di creazione (NULL = legacy → fallback content_type).
        $sectionId = isset($data['section_id']) && (int)$data['section_id'] > 0
            ? (int)$data['section_id'] : null;
        $sql = 'INSERT INTO teacher_content_data
                  (teacher_id, content_subtype, section_id, subject_id,
                   indirizzo_id, classe_id, topic, title,
                   body_html, body_html_ct, body_html_iv, body_html_tag, body_html_kv,
                   body_pt_ct, body_pt_iv, body_pt_tag, body_pt_kv,
                   metadata_json, visibility, publish_scope)
                VALUES (?,?,?,?,?,?,?,?,
                        ?,?,?,?,?,
                        ?,?,?,?,
                        ?,?,?)';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            $teacherId,
            $data['content_type'],
            $sectionId,
            $subjId,
            $indId,
            $clsId,
            (string)($data['topic'] ?? ''),
            $data['title'],
            $bodyHtml,
            $bodyHtmlCt, $bodyHtmlIv, $bodyHtmlTag, $bodyHtmlKv,
            $bodyPtCt,   $bodyPtIv,   $bodyPtTag,   $bodyPtKv,
            !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            $data['visibility'] ?? 'draft',
            $this->normalizePublishScope($data['publish_scope'] ?? null),
        ]);
        $newId = (int)Database::connection()->lastInsertId();

        // Migration 069 — persiste i target del fan-out (publish_scope='classes').
        if (($data['publish_scope'] ?? '') === 'classes' && !empty($data['target_classes'])) {
            $this->setTargetClasses($newId, (array)$data['target_classes']);
        }

        // Phase 25.R.25 — Audit log content_created
        \App\Services\Audit\ContentActionLogger::log(
            \App\Services\Audit\ContentActionLogger::ACTION_CREATED,
            $teacherId,
            $newId,
            (string)$data['content_type'],
            [
                'title'          => $data['title'] ?? null,
                'visibility'     => $data['visibility'] ?? 'draft',
                'subject_code'   => $data['subject_code'] ?? null,
                'indirizzo'      => $data['indirizzo'] ?? null,
                'classe'         => $data['classe'] ?? null,
                'source_id'      => $data['source_content_id'] ?? null,
            ]
        );
        return $newId;
    }

    /**
     * Phase 25.D3 — Estrae `body_pt` (Portable Text array, contenuto sensibile
     * autoredato dal docente) da metadata. Ritorna [metadata_senza_body_pt,
     * body_pt_json_string|null] per cifrare body_pt separatamente mantenendo
     * il resto di metadata in plaintext (necessario per JSON_EXTRACT queries
     * su stats.has_tikz, stats.difficulty_max, etc.).
     *
     * @return array{0: array|null, 1: string|null}
     */
    private function extractBodyPt(?array $metadata): array
    {
        if (!is_array($metadata) || !isset($metadata['body_pt'])) {
            return [$metadata, null];
        }
        $bodyPt = $metadata['body_pt'];
        unset($metadata['body_pt']);
        if (empty($metadata)) {
            $metadata = null;
        }
        $json = json_encode($bodyPt, JSON_UNESCAPED_UNICODE);
        return [$metadata, $json !== false ? $json : null];
    }

    /** Update parziale: solo i campi presenti in $data sono aggiornati. */
    public function update(int $id, int $teacherId, array $data): bool
    {
        // Phase 25.R.25 — Pre-fetch state attuale per detection visibility/share transition
        $beforeVisibility = null;
        $beforeShared     = null;
        $contentType      = 'unknown';
        try {
            $pre = Database::connection()->prepare(
                'SELECT content_subtype, visibility, shared_with_pool
                 FROM teacher_content_data WHERE id = ? AND teacher_id = ? LIMIT 1'
            );
            $pre->execute([$id, $teacherId]);
            if ($row = $pre->fetch(\PDO::FETCH_ASSOC)) {
                $contentType      = (string)$row['content_subtype'];
                $beforeVisibility = (string)$row['visibility'];
                $beforeShared     = (int)$row['shared_with_pool'];
            }
        } catch (\Throwable) {
        }

        $cols = [];
        $args = [];
        // G22.S20 v2.C2 — Dual write FK ids per indirizzo/classe/subject_code.
        foreach (
            [
            'indirizzo'    => ['indirizzo_id', 'indirizzi'],
            'classe'       => ['classe_id',    'classi'],
            'subject_code' => ['subject_id',   'materie'],
            ] as $col => [$idCol, $kind]
        ) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $raw = $data[$col];
            $data[$col] = $raw !== null && $raw !== ''
                ? \App\Support\CurriculumLookup::canonicalize($kind, (string)$raw) : null;
            $data[$idCol] = $raw !== null && $raw !== ''
                ? \App\Support\CurriculumLookup::idFromCodeForTeacher($kind, (string)$raw, $teacherId) : null;
        }
        // Fase D — solo FK ids accettati (varchar dropped)
        $allowed = ['content_type','subject_id','indirizzo_id','classe_id','topic','title','visibility'];
        foreach ($allowed as $c) {
            if (array_key_exists($c, $data)) {
                // La chiave applicativa resta 'content_type', ma la colonna base
                // è stata rinominata content_subtype (migr 079). content_format è
                // generata dal DB → non si scrive mai.
                $col = $c === 'content_type' ? 'content_subtype' : $c;
                $cols[] = "$col = ?";
                $args[] = $data[$c];
            }
        }
        // Migration 069 — publish_scope normalizzato.
        if (array_key_exists('publish_scope', $data)) {
            $cols[] = 'publish_scope = ?';
            $args[] = $this->normalizePublishScope($data['publish_scope']);
        }

        // Phase 25.D3 — body_html e metadata.body_pt richiedono encryption
        // se dual-write attivo. Trattati separatamente da $allowed.
        $dualWrite = $this->dualWriteEnabled() && $teacherId > 0;

        if (array_key_exists('body_html', $data)) {
            $bodyHtml = $data['body_html'];
            $cols[] = 'body_html = ?';
            $args[] = $bodyHtml;
            if ($dualWrite) {
                if (is_string($bodyHtml) && $bodyHtml !== '') {
                    $env = $this->crypto()->encrypt($teacherId, $bodyHtml);
                    $cols[] = 'body_html_ct = ?';
                    $args[] = $env['ciphertext'];
                    $cols[] = 'body_html_iv = ?';
                    $args[] = $env['iv'];
                    $cols[] = 'body_html_tag = ?';
                    $args[] = $env['tag'];
                    $cols[] = 'body_html_kv = ?';
                    $args[] = $env['kv'];
                } else {
                    // Body html cleared → invalida ct
                    $cols[] = 'body_html_ct = NULL'; // no-arg
                    $cols[] = 'body_html_iv = NULL';
                    $cols[] = 'body_html_tag = NULL';
                    $cols[] = 'body_html_kv = NULL';
                }
            }
        }

        if (array_key_exists('metadata', $data)) {
            $metadata = $data['metadata'];
            $bodyPtJson = null;
            if ($dualWrite && is_array($metadata)) {
                [$metadata, $bodyPtJson] = $this->extractBodyPt($metadata);
            }
            $cols[] = 'metadata_json = ?';
            $args[] = !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;
            if ($dualWrite) {
                if ($bodyPtJson !== null) {
                    $env = $this->crypto()->encrypt($teacherId, $bodyPtJson);
                    $cols[] = 'body_pt_ct = ?';
                    $args[] = $env['ciphertext'];
                    $cols[] = 'body_pt_iv = ?';
                    $args[] = $env['iv'];
                    $cols[] = 'body_pt_tag = ?';
                    $args[] = $env['tag'];
                    $cols[] = 'body_pt_kv = ?';
                    $args[] = $env['kv'];
                } else {
                    $cols[] = 'body_pt_ct = NULL';
                    $cols[] = 'body_pt_iv = NULL';
                    $cols[] = 'body_pt_tag = NULL';
                    $cols[] = 'body_pt_kv = NULL';
                }
            }
        }

        if (!$cols) {
            return false;
        }
        $args[] = $id;
        $args[] = $teacherId;
        $sql = 'UPDATE teacher_content_data SET ' . implode(', ', $cols) . ' WHERE id = ? AND teacher_id = ?';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        $ok = $stmt->rowCount() > 0;

        // Migration 069 — sync dei target del fan-out quando publish_scope è
        // presente nel payload. Sync indipendente da $ok (lo scope può restare
        // invariato mentre cambiano solo i target). Ownership verificata da
        // setTargetClasses (controllo teacher_id), e clear se scope != 'classes'.
        if (array_key_exists('publish_scope', $data) && $this->ownsContent($id, $teacherId)) {
            $scope = $this->normalizePublishScope($data['publish_scope']);
            if ($scope === 'classes') {
                $this->setTargetClasses($id, (array)($data['target_classes'] ?? []));
            } else {
                $this->clearTargetClasses($id);
            }
            $ok = true;
        }

        // Phase 25.R.25 — Audit log update + transition detection
        if ($ok) {
            $logger = \App\Services\Audit\ContentActionLogger::class;
            $newVisibility = array_key_exists('visibility', $data) ? (string)$data['visibility'] : null;
            $newShared     = array_key_exists('shared_with_pool', $data) ? (int)(bool)$data['shared_with_pool'] : null;

            // Visibility transition (specifico evento per draft↔published↔archived)
            if ($newVisibility !== null && $newVisibility !== $beforeVisibility) {
                $logger::logVisibilityChange(
                    $teacherId,
                    $id,
                    $contentType,
                    $beforeVisibility,
                    $newVisibility
                );
            } elseif ($newShared !== null && $newShared !== $beforeShared) {
                // Share toggle (shared_with_pool 0↔1)
                $logger::log(
                    $newShared === 1 ? $logger::ACTION_SHARED : $logger::ACTION_UNSHARED,
                    $teacherId,
                    $id,
                    $contentType,
                    ['shared_with_pool_before' => $beforeShared, 'shared_with_pool_after' => $newShared]
                );
            } else {
                // Update generico (solo se non era una transizione specifica già loggata)
                $logger::log(
                    $logger::ACTION_UPDATED,
                    $teacherId,
                    $id,
                    $contentType,
                    ['changed_fields' => array_keys($data)]
                );
            }
        }
        return $ok;
    }

    public function delete(int $id, int $teacherId): bool
    {
        // Pre-fetch content_type per audit log
        $contentType = 'unknown';
        try {
            $pre = Database::connection()->prepare(
                'SELECT content_subtype FROM teacher_content_data WHERE id = ? AND teacher_id = ? LIMIT 1'
            );
            $pre->execute([$id, $teacherId]);
            $contentType = (string)($pre->fetchColumn() ?: 'unknown');
        } catch (\Throwable) {
        }

        $stmt = Database::connection()->prepare(
            'DELETE FROM teacher_content_data WHERE id = ? AND teacher_id = ?'
        );
        $stmt->execute([$id, $teacherId]);
        $ok = $stmt->rowCount() > 0;

        // Phase 25.R.25 — Audit log content_deleted
        if ($ok) {
            \App\Services\Audit\ContentActionLogger::log(
                \App\Services\Audit\ContentActionLogger::ACTION_DELETED,
                $teacherId,
                $id,
                $contentType
            );
        }
        return $ok;
    }

    /** Topic distinct per (subject, type) — utile per popolare la sidebar. */
    public function topics(string $subjectCode, string $contentType, ?string $indirizzo = null, ?string $classe = null, string $visibility = 'published'): array
    {
        // Phase 18 — STRICT scope: NULL rows non esposte nelle route scoped.
        $where = ['subject_code = ?', 'content_type = ?', 'visibility = ?'];
        $args  = [$subjectCode, $contentType, $visibility];
        if ($indirizzo) {
            $where[] = 'indirizzo = ?';
            $args[] = $indirizzo;
        }
        if ($classe) {
            $where[] = 'classe = ?';
            $args[] = $classe;
        }
        $stmt = Database::connection()->prepare(
            'SELECT topic, COUNT(*) AS n
             FROM teacher_content
             WHERE ' . implode(' AND ', $where) . "
             GROUP BY topic
             ORDER BY topic"
        );
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────── Migration 069 — publish scope + fan-out targets ───────

    /** Normalizza publish_scope a uno dei valori enum; default 'class'. */
    private function normalizePublishScope(mixed $scope): string
    {
        $s = is_string($scope) ? strtolower(trim($scope)) : '';
        return in_array($s, ['class', 'classes', 'general'], true) ? $s : 'class';
    }

    /** True se il documento esiste ed è di proprietà del docente. */
    private function ownsContent(int $id, int $teacherId): bool
    {
        $st = Database::connection()->prepare(
            'SELECT 1 FROM teacher_content_data WHERE id = ? AND teacher_id = ? LIMIT 1'
        );
        $st->execute([$id, $teacherId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Rimpiazza i target del fan-out (publish_scope='classes') con l'insieme
     * fornito. Ogni elemento è ['indirizzo'=>..,'classe'=>..] oppure la stringa
     * "indirizzo|classe". Coppie incomplete/duplicate scartate.
     */
    private function setTargetClasses(int $contentId, array $targets): void
    {
        $pairs = [];
        foreach ($targets as $t) {
            if (is_string($t)) {
                [$ind, $cls] = array_pad(explode('|', $t, 2), 2, '');
            } elseif (is_array($t)) {
                $ind = (string)($t['indirizzo'] ?? '');
                $cls = (string)($t['classe'] ?? '');
            } else {
                continue;
            }
            $ind = trim($ind);
            $cls = trim($cls);
            if ($ind === '' || $cls === '') {
                continue;
            }
            $pairs[$ind . '|' . $cls] = [$ind, $cls];
        }
        $db = Database::connection();
        $db->prepare('DELETE FROM content_target_classes WHERE content_id = ?')->execute([$contentId]);
        if (!$pairs) {
            return;
        }
        $ins = $db->prepare(
            'INSERT IGNORE INTO content_target_classes (content_id, indirizzo, classe) VALUES (?,?,?)'
        );
        foreach ($pairs as [$ind, $cls]) {
            $ins->execute([$contentId, $ind, $cls]);
        }
    }

    /** Rimuove tutti i target (scope tornato a class/general). */
    private function clearTargetClasses(int $contentId): void
    {
        Database::connection()
            ->prepare('DELETE FROM content_target_classes WHERE content_id = ?')
            ->execute([$contentId]);
    }

    /**
     * Coppie (indirizzo, classe) DISTINTE usate dal docente nei suoi
     * teacher_content — "le mie classi" per il multi-select del fan-out.
     * Fonte: documenti esistenti (rispecchia dove insegna davvero).
     *
     * @return list<array{indirizzo:string, classe:string}>
     */
    public function teacherClassPairs(int $teacherId): array
    {
        $st = Database::connection()->prepare(
            'SELECT DISTINCT indirizzo, classe
               FROM teacher_content
              WHERE teacher_id = ?
                AND indirizzo IS NOT NULL AND indirizzo <> ""
                AND classe    IS NOT NULL AND classe    <> ""
              ORDER BY indirizzo, classe'
        );
        $st->execute([$teacherId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['indirizzo' => (string)$r['indirizzo'], 'classe' => (string)$r['classe']];
        }
        return $out;
    }

    /** I target correnti del fan-out di un documento (per pre-popolare la UI). */
    public function targetClasses(int $contentId): array
    {
        $st = Database::connection()->prepare(
            'SELECT indirizzo, classe FROM content_target_classes WHERE content_id = ? ORDER BY indirizzo, classe'
        );
        $st->execute([$contentId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function validate(array $data): void
    {
        if (empty($data['teacher_id'])) {
            throw new \InvalidArgumentException('teacher_id_required');
        }
        if (empty($data['title'])) {
            throw new \InvalidArgumentException('title_required');
        }
        if (!in_array($data['content_type'] ?? null, self::TYPES, true)) {
            throw new \InvalidArgumentException('invalid_content_type');
        }
        if (empty($data['subject_code']) || !preg_match('/^[A-Za-z0-9_-]{1,16}$/', (string)$data['subject_code'])) {
            throw new \InvalidArgumentException('invalid_subject_code');
        }
        $vis = $data['visibility'] ?? 'draft';
        if (!in_array($vis, self::VISIBILITIES, true)) {
            throw new \InvalidArgumentException('invalid_visibility');
        }
    }
}
