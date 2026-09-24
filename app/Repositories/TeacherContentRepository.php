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

    /** Phase 25.D3 — true se dual-write encryption è attivo (crypto.dual_write). */
    private function dualWriteEnabled(): bool
    {
        return (bool)\App\Core\Config::get('crypto.dual_write', false);
    }

    /** Phase 25.D3 — true se le read devono usare i ciphertext (crypto.read_from). */
    private function readFromCiphertext(): bool
    {
        return \App\Core\Config::get('crypto.read_from', 'plaintext') === 'ciphertext';
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
     *   institute_id?: int,
     *   student_scope?: bool,
     *   content_type?: string|string[],
     *   content_format?: string|string[],
     *   subject_code?: string,
     *   include_unscoped?: bool,
     *   indirizzo?: string,
     *   classe?: string,
     *   classi?: list<string>,
     *   archivio?: bool,
     *   grants?: list<array{teacher_id:int,indirizzo:?string,classi:list<string>,archivio:bool,institute_id?:?int}>,
     *   pub_institute_id?: int|null,
     *   pub_actor_id?: int,
     *   topic?: string,
     *   section_id?: int,
     *   section_id_not_in?: list<int>,
     *   section_id_in_or_null?: list<int>,
     *   visibility?: string|string[],
     *   q?: string,
     *   has_tikz?: bool,
     *   has_vf?: bool,
     *   has_rm?: bool,
     *   difficulty_max_gte?: int,
     *   item_count_gte?: int,
     *   source_code?: string,
     *   with_metadata?: bool,
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
        $instId = (int)($f['institute_id'] ?? 0);
        if ($instId > 0) {
            $where[] = 'teacher_id IN (SELECT user_id FROM teacher_institutes WHERE institute_id = ?)';
            $args[] = $instId;
        }
        // Migration 099 — restringe ai docenti che insegnano DAVVERO nella
        // sezione dello studente. Senza, la clausola qui sopra dice solo
        // "docenti dell'istituto": con due insegnanti di matematica in 1A e 1B
        // lo studente di 1A si trova in elenco anche le verifiche della 1B.
        //
        // SEMPRE ATTIVO, anche a tabella incarichi vuota. Il filtro parte da
        // "nessuno ti raggiunge" e sono gli incarichi ad aprire, non il
        // contrario: senza incarichi lo studente non vede nulla. E' una scelta,
        // non un effetto collaterale — l'alternativa (aprire tutto finche' non
        // si configura) fa sembrare la scuola gia' a posto proprio quando non
        // lo e', e chi non se ne accorge lascia i contenuti visibili a sezioni
        // sbagliate. Meglio un elenco vuoto, che si nota subito.
        //
        // Riguarda SOLO le query studente (student_scope): il docente continua
        // a vedere i propri contenuti, perche' le sue query filtrano per
        // teacher_id e non passano di qui.
        if (!empty($f['student_scope']) && $instId > 0) {
            $sections = new \App\Services\TeacherSectionService();
            $docenti  = $sections->teachersForStudent(
                $instId,
                isset($f['indirizzo']) ? (string)$f['indirizzo'] : null,
                isset($f['classe']) ? (string)$f['classe'] : null
            );
            if ($docenti === []) {
                $where[] = '1 = 0';
            } else {
                $place = implode(',', array_fill(0, count($docenti), '?'));
                $where[] = "teacher_id IN ($place)";
                foreach ($docenti as $tid) {
                    $args[] = $tid;
                }
            }
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
        // ADR-037, fase 2 — la materia chiesta si confronta sulla riga solo
        // quando la domanda non è per scuola; altrimenti sulla pubblicazione
        // (una pubblicazione in un'altra scuola può chiamarla «MATE»). Il
        // filtro si aggiunge sotto, dopo i perimetri.
        $materia = !empty($f['subject_code']) ? (string)$f['subject_code'] : null;
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
        //
        // 2026-09-05 (piano classi-credenziali-scenari, A) — la classe di chi
        // guarda non e' un solo codice ma un insieme: da «3A» si vedono i
        // contenuti «3A» e quelli «3», perche' l'anno copre le sue sezioni
        // (ClassCode, la stessa regola degli incarichi). Prima il confronto era
        // alla lettera, e siccome i docenti etichettano per anno, una
        // credenziale delimitata a «3A» non vedeva nulla oltre ai «generali».
        // `classi` esplicito (dal gate di visibilita') sostituisce l'insieme
        // derivato: e' il punto in cui si aggiungeranno gli anni frequentati.
        // Piano classi, C — portachiavi: un perimetro per credenziale, in OR.
        // Ogni ramo porta il proprio teacher_id e le proprie etichette (e
        // l'archivio, se quella credenziale guarda un anno passato); sostituisce
        // sia la coppia unica sia il filtro teacher_id.
        $grantScopes = (!empty($f['student_scope']) && !empty($f['grants']) && is_array($f['grants']))
            ? $f['grants'] : [];
        //
        // ADR-037, fase 1 (2026-09-13) — le tre regole qui sotto non leggono
        // piu' le sigle della riga e i bersagli: chiedono alle pubblicazioni
        // (App\Support\Pubblicazioni), che portano la scuola. Prima un
        // contenuto nato in una scuola compariva in un'altra se le sigle
        // coincidevano. Le pubblicazioni riproducono class · classes · general
        // (la principale di un contenuto «per piu' classi» e' in bozza, i
        // bersagli nello stato della riga), quindi dentro una scuola nessuno
        // vede qualcosa di diverso da prima.
        if ($grantScopes !== []) {
            $parts = [];
            foreach ($grantScopes as $g) {
                $tid = (int)($g['teacher_id'] ?? 0);
                if ($tid <= 0) {
                    continue;
                }
                $sub = ['teacher_id = ?'];
                $args[] = $tid;
                $classi = array_values(array_unique(array_map('strval', (array)($g['classi'] ?? []))));
                $ind = isset($g['indirizzo']) && (string)$g['indirizzo'] !== '' ? (string)$g['indirizzo'] : null;
                // La scuola della credenziale; senza (credenziali create prima
                // del 13/9/2026) le scuole del docente, come prima.
                $scuola = isset($g['institute_id']) && (int)$g['institute_id'] > 0 ? (int)$g['institute_id'] : null;
                $archivio = !empty($g['archivio']);
                [$perimetro, $a] = $classi !== []
                    ? \App\Support\Pubblicazioni::perimetroDiClasse('teacher_content', $scuola, $classi, $ind, $archivio, $materia)
                    : \App\Support\Pubblicazioni::esiste('teacher_content', $scuola, [], null, 'published', false, $archivio, $materia);
                $sub[] = $perimetro;
                foreach ($a as $v) {
                    $args[] = $v;
                }
                $parts[] = '(' . implode(' AND ', $sub) . ')';
            }
            $where[] = $parts === [] ? '1 = 0' : '(' . implode(' OR ', $parts) . ')';
        }
        $studentScope = $grantScopes === [] && !empty($f['student_scope'])
            && !empty($f['indirizzo']) && !empty($f['classe']);
        if ($grantScopes !== []) {
            // gia' vincolato dal portachiavi: niente filtri di navigazione in AND
        } elseif ($studentScope) {
            $classi = (!empty($f['classi']) && is_array($f['classi']))
                ? array_values(array_unique(array_map('strval', $f['classi'])))
                : \App\Domain\ClassCode::covering((string)$f['classe']);
            if ($classi === []) {
                $classi = [(string)$f['classe']];
            }
            // La scuola dello studente: le pubblicazioni devono stare li'. Il
            // filtro sui docenti della scuola (pivot, sopra) e sugli incarichi
            // resta: chi guarda deve essere raggiunto dal docente E il
            // contenuto deve essere pubblicato nella sua scuola.
            //
            // Piano classi, D — archivio degli anni passati: le verifiche
            // restano fuori, perche' un docente le riusa con la classe piu'
            // giovane e uno studente le passerebbe; entra solo quella che il
            // docente ha marcato «visibile anche dopo l'anno». La marca sta
            // sulla pubblicazione.
            [$perimetro, $a] = \App\Support\Pubblicazioni::perimetroDiClasse(
                'teacher_content',
                $instId > 0 ? $instId : null,
                $classi,
                (string)$f['indirizzo'],
                !empty($f['archivio']),
                $materia
            );
            $where[] = $perimetro;
            foreach ($a as $v) {
                $args[] = $v;
            }
        } elseif (!empty($f['pub_institute_id'])) {
            // ADR-037 — docente e amministratore di istituto nella scuola S:
            // i contenuti con una pubblicazione in S (la principale o una
            // scelta dal docente, fase 2), con indirizzo, classe e materia
            // confrontati sulla pubblicazione; più i contenuti propri senza
            // scuola, con le sigle sulla riga. Lo emette
            // ContentVisibilityPolicy::studyListFilters() per chi vede tutti
            // gli scope, e TeacherContentController per la barra del docente.
            [$nellaScuola, $a] = \App\Support\Pubblicazioni::nellaScuola(
                'teacher_content',
                (int)$f['pub_institute_id'],
                (int)($f['pub_actor_id'] ?? 0),
                !empty($f['indirizzo']) ? (string)$f['indirizzo'] : null,
                !empty($f['classe']) ? (string)$f['classe'] : null,
                $materia,
                $includeUnscoped
            );
            $where[] = $nellaScuola;
            foreach ($a as $v) {
                $args[] = $v;
            }
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
        // La materia sulla riga, quando la domanda non è per scuola (vedi
        // sopra): chi guarda da una classe e chi naviga in una scuola l'hanno
        // già confrontata sulla pubblicazione.
        if ($materia !== null && $grantScopes === [] && !$studentScope && empty($f['pub_institute_id'])) {
            $where[] = 'subject_code = ?';
            $args[] = $materia;
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
        // Il contrario: solo le sezioni dell'elenco, o i contenuti legacy senza
        // sezione. Un elenco vuoto lascia solo questi ultimi, non tutto.
        if (isset($f['section_id_in_or_null']) && is_array($f['section_id_in_or_null'])) {
            $ids = array_values(array_unique(array_map('intval', $f['section_id_in_or_null'])));
            if ($ids === []) {
                $where[] = 'section_id IS NULL';
            } else {
                $place = implode(',', array_fill(0, count($ids), '?'));
                $where[] = "(section_id IS NULL OR section_id IN ($place))";
                foreach ($ids as $id) {
                    $args[] = $id;
                }
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
        // Una mappa non nasce con layout, body_pt o doc_roles (MetadatiDelContenuto).
        if (\is_array($metadata)) {
            $metadata = $this->metadatiPerIlTipo($metadata, (string)$data['content_type'], null);
        }

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
            ? \App\Support\CurriculumLookup::idFromCodeForTeacher('classi', (string)$clsRaw, $teacherId, $indRaw !== null && $indRaw !== '' ? (string)$indRaw : null) : null;
        $clsCanon = $clsRaw !== null && $clsRaw !== ''
            ? \App\Support\CurriculumLookup::canonicalize('classi', (string)$clsRaw) : null;

        $subjRaw = $data['subject_code'] ?? null;
        $subjId = $subjRaw !== null && $subjRaw !== ''
            ? \App\Support\CurriculumLookup::idFromCodeForTeacher('materie', (string)$subjRaw, $teacherId) : null;
        $subjCanon = $subjRaw !== null && $subjRaw !== ''
            ? \App\Support\CurriculumLookup::canonicalize('materie', (string)$subjRaw) : $subjRaw;

        // ADR-037, fase 2 — id già verificati da chi chiama (la copia
        // indipendente in un'altra scuola, CopiaIndipendente): i codici qui
        // sopra si risolvono nella scuola ATTIVA del docente, che può non
        // essere quella di arrivo. Chi passa gli id se ne prende la verifica.
        if (isset($data['indirizzo_id']) && (int)$data['indirizzo_id'] > 0) {
            $indId = (int)$data['indirizzo_id'];
        }
        if (isset($data['classe_id']) && (int)$data['classe_id'] > 0) {
            $clsId = (int)$data['classe_id'];
        }
        if (isset($data['subject_id']) && (int)$data['subject_id'] > 0) {
            $subjId = (int)$data['subject_id'];
        }

        // G22.S20 v2.C2 Fase D — write solo FK ids (varchar dropped).
        // ADR-027 Step 5-6 — section_id: ancora il contenuto alla sezione
        // sidebar di creazione (NULL = legacy → fallback content_type).
        $sectionId = isset($data['section_id']) && (int)$data['section_id'] > 0
            ? (int)$data['section_id'] : null;
        // ADR-037, fase 4c-2 — indirizzo, classe e materia non stanno più nella
        // riga: sono il posto della pubblicazione principale, scritta qui sotto.
        $sql = 'INSERT INTO teacher_content_data
                  (teacher_id, content_subtype, section_id, topic, title,
                   body_html, body_html_ct, body_html_iv, body_html_tag, body_html_kv,
                   body_pt_ct, body_pt_iv, body_pt_tag, body_pt_kv,
                   metadata_json, visibility, publish_scope, archive_visible)
                VALUES (?,?,?,?,?,
                        ?,?,?,?,?,
                        ?,?,?,?,
                        ?,?,?,?)';
        // 23/9/2026 (revisione architetturale 2026-09, A-76) — contenuto,
        // posto principale e riga di audit in una transazione. Prima erano
        // tre scritture sciolte: se la principale falliva (una voce del
        // curriculum sparita nel frattempo, un vincolo) il contenuto restava
        // senza posto, invisibile a chi naviga, e il nuovo tentativo dello
        // stesso titolo rispondeva 409. La transazione si apre solo se chi
        // chiama non ne ha già una (MapsController::create, le prove), come
        // in ImportBundleController::insertMappa: allora decide lui.
        $pdo = Database::connection();
        $propria = !$pdo->inTransaction();
        if ($propria) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $teacherId,
                $data['content_type'],
                $sectionId,
                (string)($data['topic'] ?? ''),
                $data['title'],
                $bodyHtml,
                $bodyHtmlCt, $bodyHtmlIv, $bodyHtmlTag, $bodyHtmlKv,
                $bodyPtCt,   $bodyPtIv,   $bodyPtTag,   $bodyPtKv,
                !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
                $data['visibility'] ?? 'draft',
                $this->normalizePublishScope($data['publish_scope'] ?? null),
                // Piano classi, D — «visibile anche dopo l'anno»: per default no.
                !empty($data['archive_visible']) ? 1 : 0,
            ]);
            $newId = (int)$pdo->lastInsertId();

            // ADR-037, fase 4c — il posto del contenuto è la sua principale, e la
            // scrive solo l'applicazione (App\Support\PostoPrincipale).
            \App\Support\PostoPrincipale::contenuto($pdo, $newId, $indId, $clsId, $subjId);

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

            if ($propria) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($propria && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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

    /**
     * I metadati senza le chiavi che il tipo non accetta
     * (`MetadatiDelContenuto::perIlTipo`), con una differenza: se fra quelle
     * c'è un `body_pt` che **non** è il seme del modale, la cosa lascia una
     * riga nel registro delle anomalie.
     *
     * Perché solo quello. Il seme è il segnaposto che il modale scriveva a
     * ogni «Salva» fino al 19/9/2026: toglierlo è la correzione, non una
     * notizia, e segnalarlo riempirebbe il registro di righe attese — un
     * rapporto che non è mai pulito non si apre più. Un corpo diverso invece è
     * testo che qualcuno ha scritto: lo strumento di pulizia lo risparmia
     * apposta (`PuliziaMetadatiMappe`, «corpo_diverso»), e qui sparirebbe in
     * silenzio. Le due parti devono dire la stessa cosa dello stesso dato
     * (revisione della PR #145, 20/9/2026).
     *
     * @param array<mixed> $metadata
     * @param int|null     $id null in creazione: la riga non c'è ancora
     * @return array<mixed>
     */
    private function metadatiPerIlTipo(array $metadata, string $tipo, ?int $id): array
    {
        $puliti = \App\Domain\MetadatiDelContenuto::perIlTipo($metadata, $tipo);
        $corpo = $metadata['body_pt'] ?? null;
        if (
            $corpo !== null && !\array_key_exists('body_pt', $puliti)
            && !\App\Services\Maps\PuliziaMetadatiMappe::eIlSeme($corpo)
        ) {
            \App\Support\Anomalia::registra(
                'metadati_corpo_tolto_al_tipo',
                // `{$tipo}` per forza: un `»` attaccato entrerebbe nel nome della variabile.
                "Un contenuto di tipo «{$tipo}» ha ricevuto un body_pt che il tipo non accetta: tolto, e non era il"
                . ' seme del modale. Se era testo di qualcuno, da qui non si recupera.',
                // Che cosa dicesse non si scrive: si scrive quanto era grande.
                ['tipo' => $tipo, 'contenuto' => $id, 'blocchi' => \is_array($corpo) ? \count($corpo) : null],
                $tipo,
            );
        }
        return $puliti;
    }

    /**
     * Le colonne del corpo cifrato, uguali da tutte e due le strade dei
     * metadati: piene se c'è un `body_pt` da cifrare, azzerate se non c'è
     * più (altrimenti resterebbe leggibile un corpo che non esiste).
     *
     * @param list<string> $cols
     * @param list<mixed>  $args
     */
    private function colonneDelCorpoCifrato(int $teacherId, ?string $bodyPtJson, array &$cols, array &$args): void
    {
        if ($bodyPtJson === null) {
            $cols[] = 'body_pt_ct = NULL';
            $cols[] = 'body_pt_iv = NULL';
            $cols[] = 'body_pt_tag = NULL';
            $cols[] = 'body_pt_kv = NULL';
            return;
        }
        $env = $this->crypto()->encrypt($teacherId, $bodyPtJson);
        $cols[] = 'body_pt_ct = ?';
        $args[] = $env['ciphertext'];
        $cols[] = 'body_pt_iv = ?';
        $args[] = $env['iv'];
        $cols[] = 'body_pt_tag = ?';
        $args[] = $env['tag'];
        $cols[] = 'body_pt_kv = ?';
        $args[] = $env['kv'];
    }

    /**
     * Update parziale: solo i campi presenti in $data sono aggiornati.
     *
     * I metadati si scrivono in due modi (App\Domain\MetadatiDelContenuto):
     * `metadata` (array) li sostituisce per intero; `metadata_patch`
     * (\stdClass) cambia solo le sue chiavi, e `null` toglie la chiave. In tutti
     * e due i casi una mappa non riceve layout, body_pt né doc_roles.
     */
    public function update(int $id, int $teacherId, array $data): bool
    {
        // Phase 25.R.25 — Pre-fetch state attuale per detection visibility/share transition
        $beforeVisibility = null;
        $beforeShared     = null;
        $contentType      = 'unknown';
        // I metadati salvati, per fondere una `metadata_patch`: null se la riga
        // non c'è o non è di chi scrive.
        $metadatiSalvati  = null;
        $rigaTrovata      = false;
        try {
            $pre = Database::connection()->prepare(
                'SELECT content_subtype, visibility, shared_with_pool, metadata_json
                 FROM teacher_content_data WHERE id = ? AND teacher_id = ? LIMIT 1'
            );
            $pre->execute([$id, $teacherId]);
            if ($row = $pre->fetch(\PDO::FETCH_ASSOC)) {
                $contentType      = (string)$row['content_subtype'];
                $beforeVisibility = (string)$row['visibility'];
                $beforeShared     = (int)$row['shared_with_pool'];
                $metadatiSalvati  = $row['metadata_json'] !== null ? (string)$row['metadata_json'] : null;
                $rigaTrovata      = true;
            }
        } catch (\Throwable) {
        }
        // Il tipo che la riga avrà dopo la scrittura decide quali metadati
        // accetta (MetadatiDelContenuto: una mappa non ha layout né body_pt).
        $tipoDopo = array_key_exists('content_type', $data) ? (string)$data['content_type'] : $contentType;

        $cols = [];
        $args = [];
        // ADR-042 — un anno si risolve con il corso: quello salvato insieme o,
        // se si cambia solo la classe, quello della pubblicazione principale.
        $corsoDellaClasse = isset($data['indirizzo']) && $data['indirizzo'] !== '' ? (string)$data['indirizzo'] : null;
        if (array_key_exists('classe', $data) && !array_key_exists('indirizzo', $data)) {
            foreach (\App\Support\Pubblicazioni::delContenuto($id) as $pubblicazione) {
                if ($pubblicazione['is_primary']) {
                    $corsoDellaClasse = $pubblicazione['indirizzo'];
                    break;
                }
            }
        }
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
                ? \App\Support\CurriculumLookup::idFromCodeForTeacher($kind, (string)$raw, $teacherId, $kind === 'classi' ? $corsoDellaClasse : null) : null;
        }
        // Fase D — solo FK ids accettati (varchar dropped)
        // ADR-037, fase 4c-2 — le tre etichette non si scrivono nella riga: vanno
        // alla pubblicazione principale, dopo la scrittura (PostoPrincipale).
        $allowed = ['content_type','topic','title','visibility'];
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
        // Piano classi, D — «visibile anche dopo l'anno» (archivio degli anni passati).
        if (array_key_exists('archive_visible', $data)) {
            $cols[] = 'archive_visible = ?';
            $args[] = !empty($data['archive_visible']) ? 1 : 0;
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

        // 19/9/2026 — la patch dei metadati: solo le chiavi cambiate, fuse con
        // quelle salvate (MetadatiDelContenuto). Se arrivano anche i metadati
        // interi vincono quelli, ma il controller le rifiuta insieme.
        if (
            array_key_exists('metadata_patch', $data) && !array_key_exists('metadata', $data)
            && $data['metadata_patch'] instanceof \stdClass && $rigaTrovata
        ) {
            $patch = \App\Domain\MetadatiDelContenuto::patchPerIlTipo($data['metadata_patch'], $tipoDopo);
            if (!\App\Domain\MetadatiDelContenuto::patchVuota($patch)) {
                $fusi = \App\Domain\MetadatiDelContenuto::fondi($metadatiSalvati, $patch);
                if ($dualWrite && property_exists($patch, 'body_pt')) {
                    // Il corpo va cifrato: si sfila dall'oggetto fuso e si
                    // scrive qui, senza passare dalla strada dei metadati
                    // interi — che fa `json_decode(..., true)` e appiattirebbe
                    // in `[]` ogni `{}` annidato, proprio la garanzia per cui
                    // la patch si legge come oggetto (revisione della PR #145).
                    [$senzaCorpo, $corpo] = \App\Domain\MetadatiDelContenuto::sfilaIlCorpo($fusi);
                    $cols[] = 'metadata_json = ?';
                    $args[] = $senzaCorpo;
                    $this->colonneDelCorpoCifrato($teacherId, $corpo, $cols, $args);
                } else {
                    // Solo metadata_json: le colonne cifrate del corpo non si
                    // toccano, perché la patch non ha toccato il corpo.
                    $cols[] = 'metadata_json = ?';
                    $args[] = $fusi;
                }
            }
        }

        if (array_key_exists('metadata', $data)) {
            $metadata = \is_array($data['metadata'])
                ? $this->metadatiPerIlTipo($data['metadata'], $tipoDopo, $id)
                : $data['metadata'];
            $bodyPtJson = null;
            if ($dualWrite && is_array($metadata)) {
                [$metadata, $bodyPtJson] = $this->extractBodyPt($metadata);
            }
            $cols[] = 'metadata_json = ?';
            $args[] = !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;
            if ($dualWrite) {
                $this->colonneDelCorpoCifrato($teacherId, $bodyPtJson, $cols, $args);
            }
        }

        // ADR-037, fase 4c — dove sta prima della scrittura, per chi ne cambia
        // una sola etichetta: le altre restano quelle della principale.
        $etichette = array_intersect_key($data, array_flip(['indirizzo_id', 'classe_id', 'subject_id']));
        $posto = $etichette !== []
            ? \App\Support\PostoPrincipale::dove(Database::connection(), \App\Support\PostoPrincipale::CONTENUTO, $id)
            : null;
        if ($posto !== null) {
            // Spostare un contenuto è una modifica della riga, anche se il posto
            // sta nella pubblicazione: chi legge updated_at lo vede cambiato. E
            // una modifica delle sole etichette ha così la sua scrittura.
            $cols[] = 'updated_at = NOW()';
        }

        if (!$cols) {
            // Niente da scrivere (per esempio una patch fatta solo di chiavi
            // che il tipo non accetta): riuscito se la riga è di chi scrive.
            return $rigaTrovata;
        }

        $args[] = $id;
        $args[] = $teacherId;
        $sql = 'UPDATE teacher_content_data SET ' . implode(', ', $cols) . ' WHERE id = ? AND teacher_id = ?';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        // MySQL conta le righe CAMBIATE, non quelle trovate: riscrivere gli
        // stessi valori dà zero. Serve distinguere «non è cambiato niente» da
        // «la riga non esiste o non è tua», altrimenti un salvataggio riuscito
        // torna 404 a chi lo ha chiesto (voce 61 del debito, 2026-09-07).
        $changed = $stmt->rowCount() > 0;

        // Riuscito anche quando non c'era niente da riscrivere, purché la riga
        // sia di chi lo chiede. La verifica di proprietà costa una query, e la
        // si paga solo nel caso raro in cui l'UPDATE non ha toccato righe.
        $ok = $changed || $this->ownsContent($id, $teacherId);

        // ADR-037, fase 4c — la principale segue il posto e lo stato della riga.
        if ($ok) {
            $idOppureNull = static fn(mixed $v): ?int => $v !== null && $v !== '' && (int)$v > 0 ? (int)$v : null;
            if ($posto !== null) {
                \App\Support\PostoPrincipale::contenuto(
                    Database::connection(),
                    $id,
                    array_key_exists('indirizzo_id', $etichette) ? $idOppureNull($etichette['indirizzo_id']) : $posto['indirizzo'],
                    array_key_exists('classe_id', $etichette) ? $idOppureNull($etichette['classe_id']) : $posto['classe'],
                    array_key_exists('subject_id', $etichette) ? $idOppureNull($etichette['subject_id']) : $posto['materia']
                );
            } elseif (array_intersect_key($data, array_flip(['visibility', 'publish_scope', 'archive_visible'])) !== []) {
                \App\Support\PostoPrincipale::statoDelContenuto(Database::connection(), $id);
            }
        }

        // Phase 25.R.25 — Audit log update + transition detection.
        // Sul cambiamento vero, non sull'esito: un salvataggio che non
        // riscrive niente non è una modifica da registrare.
        if ($changed) {
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

    /**
     * I contenuti del docente a cui manca l'indirizzo, raggruppati.
     *
     * Sono invisibili nella navigazione: la ricerca filtra per indirizzo, e
     * chi non ce l'ha non compare mai. Non e' un caso raro — nascono cosi'
     * tutti i contenuti creati prima che gli indirizzi esistessero, e ci
     * finiscono anche quelli che perdono la voce di curriculum a cui
     * puntavano.
     *
     * Raggruppati per (classe, materia, tipo) perche' e' cosi' che si
     * assegnano: cinquantaquattro contenuti uno per uno non li sistema
     * nessuno, dieci gruppi si'.
     *
     * @return list<array{classe:?string,materia:?string,tipo:string,quanti:int,ids:list<int>,titoli:list<string>}>
     */
    /**
     * I contenuti del docente a cui manca una delle tre etichette.
     *
     * Non solo l'indirizzo: senza CLASSE o senza MATERIA un contenuto e'
     * altrettanto irraggiungibile, perche' la rotta di studio le vuole tutte
     * e tre (/studio/{indirizzo}/{classe}/{materia}/{argomento}). Guardare
     * solo l'indirizzo mostrava un terzo del problema.
     *
     * Raggruppa per sezione della sidebar, tipo, materia e classe: e' l'ordine
     * in cui si ragiona, e la sezione conta quanto il resto — le Verifiche non
     * si sistemano con lo stesso criterio delle Risorse docente.
     *
     * @return list<array{sezione:?string,tipo:string,classe:?string,materia:?string,
     *                    mancano:list<string>,quanti:int,ids:list<int>,esercizi:int,
     *                    origine:?string,titoli:list<array{id:int,title:string,apribile:bool}>}>
     */
    public function daCategorizzare(int $teacherId): array
    {
        $st = Database::connection()->prepare(
            'SELECT d.id, d.title, d.content_subtype AS tipo,
                    s.label AS sezione, d.metadata_json,
                    ci.code AS indirizzo, cc.code AS classe, cm.code AS materia
               FROM teacher_content d
               LEFT JOIN sidebar_sections s   ON s.id  = d.section_id
               LEFT JOIN curriculum_entries ci ON ci.id = d.indirizzo_id
               LEFT JOIN curriculum_entries cc ON cc.id = d.classe_id
               LEFT JOIN curriculum_entries cm ON cm.id = d.subject_id
              WHERE d.teacher_id = ?
                AND (d.indirizzo_id IS NULL OR d.classe_id IS NULL OR d.subject_id IS NULL)
              ORDER BY s.position, s.label, d.content_subtype, cm.code, cc.code, d.id'
        );
        $st->execute([$teacherId]);

        $gruppi = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mancano = [];
            foreach (['indirizzo', 'classe', 'materia'] as $campo) {
                if (($r[$campo] ?? null) === null) {
                    $mancano[] = $campo;
                }
            }
            $k = ($r['sezione'] ?? '') . '|' . $r['tipo'] . '|' . ($r['classe'] ?? '')
               . '|' . ($r['materia'] ?? '') . '|' . implode(',', $mancano);
            if (!isset($gruppi[$k])) {
                $gruppi[$k] = [
                    'sezione' => $r['sezione'] !== null ? (string)$r['sezione'] : null,
                    'tipo'    => (string)$r['tipo'],
                    'classe'  => $r['classe'] !== null ? (string)$r['classe'] : null,
                    'materia' => $r['materia'] !== null ? (string)$r['materia'] : null,
                    'mancano' => $mancano,
                    'quanti'  => 0, 'ids' => [], 'titoli' => [],
                    'esercizi' => 0, 'origine' => null,
                ];
            }
            $gruppi[$k]['quanti']++;
            $gruppi[$k]['ids'][] = (int)$r['id'];

            // Per questi contenuti il corpo NON sta nel database: sta in un
            // file "contract" a cui `metadata_json` punta. Le colonne body_*
            // sono vuote per costruzione, e chi guarda la riga nuda pensa che
            // il contenuto sia vuoto. Le statistiche del contract dicono
            // quanto c'e' dentro, ed e' l'unica cosa che lo dice.
            $meta = \json_decode((string)($r['metadata_json'] ?? ''), true);
            $meta = \is_array($meta) ? $meta : [];
            $gruppi[$k]['esercizi'] += (int)($meta['stats']['item_count'] ?? 0);
            if (isset($meta['legacy_folder']) && $gruppi[$k]['origine'] === null) {
                $gruppi[$k]['origine'] = (string)$meta['legacy_folder'];
            }

            if (count($gruppi[$k]['titoli']) < 6) {
                $gruppi[$k]['titoli'][] = [
                    'id'    => (int)$r['id'],
                    'title' => (string)$r['title'],
                    // Solo chi ha un contract ha qualcosa da mostrare: per gli
                    // altri il link porterebbe a un errore, ed e' peggio di
                    // nessun link.
                    'apribile' => isset($meta['contract_key']),
                ];
            }
        }
        return array_values($gruppi);
    }

    /**
     * Mette le etichette mancanti a dei contenuti del docente.
     *
     * Tre vincoli, tutti necessari: solo i contenuti di QUEL docente, solo i
     * campi che oggi sono vuoti, e solo verso voci che esistono davvero per
     * lui. Senza il secondo un doppio invio sovrascrive una scelta gia' fatta;
     * senza il terzo si scrive un id qualunque.
     *
     * Ogni campo e' facoltativo: si puo' mettere solo la materia e decidere la
     * classe dopo. Quello che non arriva non viene toccato.
     *
     * @param  list<int> $ids
     * @return int quante righe sono cambiate
     */
    public function assegnaCategorie(
        int $teacherId,
        array $ids,
        ?int $indirizzoId = null,
        ?int $classeId = null,
        ?int $materiaId = null
    ): int {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
        if ($ids === []) {
            return 0;
        }

        $campi = array_filter([
            'indirizzo_id' => ['indirizzi', $indirizzoId],
            'classe_id'    => ['classi',    $classeId],
            'subject_id'   => ['materie',   $materiaId],
        ], static fn(array $v): bool => $v[1] !== null && $v[1] > 0);
        if ($campi === []) {
            return 0;
        }

        $db  = Database::connection();
        // ADR-035 — la voce è della scuola; dev'essere una che il docente ha
        // spuntato, altrimenti categorizzerebbe con un'etichetta che nei suoi
        // menù non esiste.
        $chk = $db->prepare(
            'SELECT 1 FROM curriculum_entries c
               JOIN curriculum_teacher ct ON ct.curriculum_id = c.id AND ct.user_id = ? AND ct.active = 1
              WHERE c.id = ? AND c.kind = ? LIMIT 1'
        );

        foreach ($campi as [$kind, $valore]) {
            $chk->execute([$teacherId, $valore, $kind]);
            if (!$chk->fetchColumn()) {
                throw new \InvalidArgumentException('voce_non_del_docente:' . $kind);
            }
        }

        // ADR-037, fase 4c — «solo se vuoto» si guarda sulla principale, che è
        // il posto del contenuto, campo per campo: un contenuto che ha già la
        // materia ma non la classe riceve la classe e tiene la materia.
        $place = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT id FROM teacher_content_data WHERE teacher_id = ? AND id IN ($place) ORDER BY id");
        $st->execute(array_merge([$teacherId], $ids));
        $chiavi = ['indirizzo_id' => 'indirizzo', 'classe_id' => 'classe', 'subject_id' => 'materia'];
        $upd = $db->prepare('UPDATE teacher_content_data SET updated_at = NOW() WHERE id = ? AND teacher_id = ?');
        $cambiate = 0;
        $inTx = !$db->inTransaction();
        if ($inTx) {
            $db->beginTransaction();
        }
        try {
            foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $id) {
                $ora = \App\Support\PostoPrincipale::dove($db, \App\Support\PostoPrincipale::CONTENUTO, $id);
                $nuovo = $ora;
                foreach ($campi as $colonna => [, $valore]) {
                    if ($ora[$chiavi[$colonna]] === null) {
                        $nuovo[$chiavi[$colonna]] = $valore;
                        $cambiate++;
                    }
                }
                if ($nuovo !== $ora) {
                    $upd->execute([$id, $teacherId]);
                    \App\Support\PostoPrincipale::contenuto($db, $id, $nuovo['indirizzo'], $nuovo['classe'], $nuovo['materia']);
                }
            }
            if ($inTx) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($inTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $cambiate;
    }
}
