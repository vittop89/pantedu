<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Policies\ExerciseAccessPolicy;
use App\Support\Sources\SourcesRegistry;

/**
 * StudySourcesController — estratto da ContentStudyController (ADR-029).
 * Metodi: sourcesCommonJson, originsJson, sourcesSave, sourcesRegistrySave, sourcesRegistryJson, checkedOriginsJson, checkedOriginsSave.
 * Helper condivisi duplicati: resolveUserId, privateFilesInstituteId.
 */
final class StudySourcesController
{
    /** Phase 16 — GET /api/teacher/sources.json — fonti personali del docente.
     *
     *  G22.S15.bis — `sources.registry.json` è ora la sola source-of-truth.
     *  L'endpoint legge dal registry e trasforma il formato runtime per
     *  retro-compat con il client legacy che si aspetta dict per-code:
     *
     *  Registry (canonico, scritto da fonti.php / PUT /api/teacher/sources.registry.json):
     *      { sources: [ { key, book, volume, authors }, ... ] }
     *      `volume` può contenere "Vol.X Ed.Y - PUBLISHER" (split runtime)
     *
     *  Risposta (formato legacy, atteso dal client):
     *      { sources: { "<code>": { code, title, volume, publisher, authors } } }
     *
     *  Privacy: per-teacher (auth required, path derivato da Auth::user()).
     *  Fallback: se il registry è assente, legge l'eventuale legacy
     *  `sources.json` (docenti mai migrati). Se non c'è né l'uno né l'altro il
     *  docente è nuovo: `{sources: {}, registro: "assente"}`, e nessun file
     *  scritto (23/9/2026, A-43; il perché nel corpo).
     *
     *  `sources` è sempre un oggetto per codice, anche vuoto: `{}` e non `[]`.
     *
     *  Il file `sources.json` legacy non è più scritto: `sourcesSave` (PUT)
     *  scrive direttamente sul registry. Vedi `tools/migrate_sources_json_to_registry.php`. */
    public function sourcesCommonJson(Request $req): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        if (!$tid) {
            return Response::json(['error' => 'teacher_not_found'], 404);
        }
        $iid = $this->privateFilesInstituteId($tid);
        if (!$iid) {
            return Response::json(\App\Services\SenzaScuola::risposta(), 404);
        }
        $storage  = \App\Support\Storage\StorageFactory::default();
        $regKey   = "institutes/$iid/private/$tid/sources.registry.json";
        $legacyKey = "institutes/$iid/private/$tid/sources.json";

        // 1) Tenta lettura registry (formato canonico).
        try {
            $bytes = $storage->get($regKey);
            $reg = json_decode((string)$bytes, true);
            if (is_array($reg) && is_array($reg['sources'] ?? null)) {
                return Response::json(['sources' => self::perCodice(SourcesRegistry::toLegacyDict($reg['sources']))]);
            }
        } catch (\Throwable) {
/* registry assente → fallback */
        }

        // 2) Fallback retro-compat: legge sources.json legacy se presente.
        $data = self::vecchioFile($storage, $legacyKey);
        if ($data !== null) {
            $data['sources'] = self::perCodice($data['sources']);
            return Response::json($data);
        }

        // 3) Docente nuovo: l'elenco è vuoto, e la risposta lo dice.
        //
        // 23/9/2026 (revisione architetturale 2026-09, A-43) — qui si
        // cercava `.github/workflows/deploy/config/SOURCES_COMMON.json` per
        // dare al docente un elenco di partenza e salvarlo come suo registro.
        // Quel file è stato cancellato il 24/6/2026 (1d66d772) e l'immagine
        // non porta `.github`: il codice ripiegava su un elenco vuoto senza
        // dirlo, scriveva un registro vuoto al primo GET — da lì un docente
        // che non ha mai scelto le fonti non si distingueva più da uno che le
        // ha tolte tutte — e rispondeva `{"sources":[]}`, una lista al posto
        // dell'oggetto per codice. Il modello era l'elenco dei libri di un
        // docente (matematica e fisica), non un catalogo per tutti: i libri
        // da cui partire oggi sono quelli del catalogo delle adozioni
        // dell'istituto, che il docente aggiunge dalla pagina delle fonti
        // (ADR-036). Niente file scritto: il registro nasce al primo
        // salvataggio.
        return Response::json(['sources' => new \stdClass(), 'registro' => 'assente']);
    }

    /**
     * L'elenco per codice sempre come oggetto JSON: `[]` (lista) rompe chi ci
     * aggiunge una chiave e poi lo rimanda. Non basta il caso vuoto: un
     * codice `"0"` (ammesso dalla regola dei codici) fa di un array PHP una
     * lista, e `json_encode` lo scriveva `[{…}]`, senza il codice.
     *
     * @param array<mixed> $dict
     */
    private static function perCodice(array $dict): \stdClass
    {
        return (object)$dict;
    }

    /**
     * Il vecchio `sources.json` (forma per codice) di un docente mai
     * migrato; null se non c'è o non porta un elenco `sources`.
     *
     * @return array{sources: array<mixed>}|null
     */
    private static function vecchioFile(\App\Support\Storage\StorageProvider $storage, string $chiave): ?array
    {
        try {
            $data = json_decode($storage->get($chiave), true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data) || !is_array($data['sources'] ?? null)) {
            return null;
        }
        /** @var array{sources: array<mixed>} $data */
        return $data;
    }

    /** Phase 16 — GET /api/teacher/origins.json — lista ordinata dei code
     *  origine del docente. G22.S15.bis: derivata dal registry canonico
     *  (`sources.registry.json`); fallback al legacy `sources.json` per
     *  docenti mai migrati. */
    public function originsJson(Request $req): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        if (!$tid) {
            return Response::json(['error' => 'teacher_not_found'], 404);
        }
        $iid = $this->privateFilesInstituteId($tid);
        if (!$iid) {
            return Response::json(\App\Services\SenzaScuola::risposta(), 404);
        }
        $storage = \App\Support\Storage\StorageFactory::default();
        $codes = [];
        // 1) Registry canonico
        try {
            $bytes = $storage->get("institutes/$iid/private/$tid/sources.registry.json");
            $reg = json_decode((string)$bytes, true);
            foreach (($reg['sources'] ?? []) as $r) {
                if (is_array($r) && !empty($r['key'])) {
                    $codes[] = (string)$r['key'];
                }
            }
        } catch (\Throwable) {
/* try legacy */
        }
        // 2) Fallback legacy sources.json (pre-migration teachers)
        if (!$codes) {
            try {
                $bytes = $storage->get("institutes/$iid/private/$tid/sources.json");
                $data = json_decode((string)$bytes, true);
                if (is_array($data['sources'] ?? null)) {
                    $codes = array_keys($data['sources']);
                }
            } catch (\Throwable) {
/* nothing */
            }
        }
        $codes = array_values(array_unique(array_filter($codes, 'is_string')));
        sort($codes);
        return Response::json($codes);
    }

    /** Phase 16 — PUT /api/teacher/sources.json — salva le fonti personali.
     *  G22.S15.bis: scrive sul registry canonico (`sources.registry.json`),
     *  NON più sul legacy `sources.json`. Accetta sia il formato dict legacy
     *  `{sources:{<code>:{...}}}` (dal client editor inline) sia il formato
     *  registry array `{sources:[{key,book,volume,authors}]}`.
     *  Rifiuta upload >256KB. */
    public function sourcesSave(Request $req): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        if (!$tid) {
            return Response::json(['error' => 'teacher_not_found'], 404);
        }
        $iid = $this->privateFilesInstituteId($tid);
        if (!$iid) {
            return Response::json(\App\Services\SenzaScuola::risposta(), 404);
        }

        $raw = $req->rawBody();
        if (strlen($raw) > 262144) {
            return Response::json(['error' => 'payload_too_large'], 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['sources'])) {
            return Response::json(['error' => 'invalid_payload', 'message' => 'atteso {sources:{}|[]}'], 422);
        }
        $list = [];
        if (is_array($data['sources']) && array_is_list($data['sources'])) {
            // Formato registry-array
            foreach ($data['sources'] as $r) {
                if (!is_array($r)) {
                    return Response::json(['error' => 'invalid_source'], 422);
                }
                $key = (string)($r['key'] ?? '');
                if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $key)) {
                    return Response::json(['error' => 'invalid_code', 'code' => $key], 422);
                }
                $voce = [
                    'key'     => $key,
                    'book'    => (string)($r['book']    ?? ''),
                    'volume'  => (string)($r['volume']  ?? ''),
                    'authors' => (string)($r['authors'] ?? ''),
                ];
                // ADR-036 — una fonte presa dal catalogo delle adozioni porta
                // con se' l'ISBN e l'editore: e' cosi' che la pagina delle
                // fonti riconosce un libro gia' preso. Facoltativi.
                foreach (['isbn', 'editore'] as $extra) {
                    $val = trim((string)($r[$extra] ?? ''));
                    if ($val !== '') {
                        $voce[$extra] = mb_substr($val, 0, 128);
                    }
                }
                $list[] = $voce;
            }
        } elseif (is_array($data['sources'])) {
            // Formato dict legacy → conversione registry
            foreach ($data['sources'] as $code => $src) {
                if (!is_string($code) || !preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $code)) {
                    return Response::json(['error' => 'invalid_code', 'code' => $code], 422);
                }
                if (!is_array($src)) {
                    return Response::json(['error' => 'invalid_source', 'code' => $code], 422);
                }
            }
            $list = SourcesRegistry::fromLegacyDict($data['sources']);
        } else {
            return Response::json(['error' => 'invalid_payload'], 422);
        }
        $regKey = "institutes/$iid/private/$tid/sources.registry.json";
        $payload = [
            '$schema'      => 'pantedu.sources.v1',
            'teacher_id'   => $tid,
            'institute_id' => $iid,
            'generated_at' => date('c'),
            'count'        => count($list),
            'sources'      => $list,
        ];
        try {
            \App\Support\Storage\StorageFactory::default()
                ->put($regKey, (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            return Response::json(['error' => 'storage_error', 'message' => $e->getMessage()], 500);
        }
        return Response::json(['ok' => true, 'count' => count($list)]);
    }

    /** G22.S15.bis — PUT /api/teacher/sources.registry.json — salva il
     *  registry nel formato canonico. Body: `{sources:[{key,book,volume,authors},...]}`.
     *  Usato dalla pagina /area-docente/fonti che lavora già nativamente in
     *  formato registry-array. */
    public function sourcesRegistrySave(Request $req): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        if (!$tid) {
            return Response::json(['error' => 'teacher_not_found'], 404);
        }
        $iid = $this->privateFilesInstituteId($tid);
        if (!$iid) {
            return Response::json(\App\Services\SenzaScuola::risposta(), 404);
        }
        $raw = $req->rawBody();
        if (strlen($raw) > 262144) {
            return Response::json(['error' => 'payload_too_large'], 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['sources']) || !is_array($data['sources'])) {
            return Response::json(['error' => 'invalid_payload', 'message' => 'atteso {sources:[...]}'], 422);
        }
        $list = [];
        foreach ($data['sources'] as $r) {
            if (!is_array($r)) {
                return Response::json(['error' => 'invalid_source'], 422);
            }
            $key = (string)($r['key'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $key)) {
                return Response::json(['error' => 'invalid_code', 'code' => $key], 422);
            }
            $voce = [
                'key'     => $key,
                'book'    => (string)($r['book']    ?? ''),
                'volume'  => (string)($r['volume']  ?? ''),
                'authors' => (string)($r['authors'] ?? ''),
            ];
            // ADR-036 — una fonte presa dal catalogo delle adozioni porta con
            // sé l'ISBN e l'editore: così la pagina delle fonti la riconosce
            // come già presente anche se il docente ne ritocca il titolo.
            foreach (['isbn', 'editore'] as $extra) {
                $val = trim((string)($r[$extra] ?? ''));
                if ($val !== '') {
                    $voce[$extra] = mb_substr($val, 0, 128);
                }
            }
            $list[] = $voce;
        }
        $regKey = "institutes/$iid/private/$tid/sources.registry.json";
        $payload = [
            '$schema'      => 'pantedu.sources.v1',
            'teacher_id'   => $tid,
            'institute_id' => $iid,
            'generated_at' => date('c'),
            'count'        => count($list),
            'sources'      => $list,
        ];
        try {
            \App\Support\Storage\StorageFactory::default()
                ->put($regKey, (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            return Response::json(['error' => 'storage_error', 'message' => $e->getMessage()], 500);
        }
        return Response::json(['ok' => true, 'count' => count($list)]);
    }

    /** Phase 20 — GET /api/teacher/checked-origins.json — preferenze di
     *  filtro per pagina (mappa pageName → array codes selezionati). Per-
     *  teacher in `institutes/{iid}/private/{tid}/checked_origins.json`.
     *  Sostituisce il file globale `/origins/checked_origins.json`. */
    public function checkedOriginsJson(Request $req): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        if (!$tid) {
            return Response::json(['error' => 'teacher_not_found'], 404);
        }
        $iid = $this->privateFilesInstituteId($tid);
        if (!$iid) {
            return Response::json(\App\Services\SenzaScuola::risposta(), 404);
        }
        $key = "institutes/$iid/private/$tid/checked_origins.json";
        try {
            $bytes = \App\Support\Storage\StorageFactory::default()->get($key);
        } catch (\Throwable) {
            return Response::json(new \stdClass());
        }
        $data = json_decode((string)$bytes, true);
        return Response::json(is_array($data) ? $data : new \stdClass());
    }

    /** Phase 20 — PUT /api/teacher/checked-origins.json — salva preferenze
     *  di filtro per pagina. Body: `{ "<pageName>": ["code1","code2"], ... }`.
     *  Se `pageName` mappa a array vuoto viene rimosso. Cap 128KB. */
    public function checkedOriginsSave(Request $req): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        if (!$tid) {
            return Response::json(['error' => 'teacher_not_found'], 404);
        }
        $iid = $this->privateFilesInstituteId($tid);
        if (!$iid) {
            return Response::json(\App\Services\SenzaScuola::risposta(), 404);
        }
        $raw = $req->rawBody();
        if (strlen($raw) > 131072) {
            return Response::json(['error' => 'payload_too_large'], 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return Response::json(['error' => 'invalid_payload'], 422);
        }
        $clean = [];
        foreach ($data as $pageName => $codes) {
            if (!is_string($pageName) || $pageName === '') {
                continue;
            }
            if (!is_array($codes) || $codes === []) {
                continue;
            }
            $pageName = substr($pageName, 0, 256);
            $clean[$pageName] = array_values(array_filter(array_map(
                fn($c) => is_string($c) ? substr($c, 0, 64) : null,
                $codes
            )));
        }
        $key = "institutes/$iid/private/$tid/checked_origins.json";
        try {
            \App\Support\Storage\StorageFactory::default()
                ->put($key, (string)json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            return Response::json(['error' => 'storage_error', 'message' => $e->getMessage()], 500);
        }
        return Response::json(['ok' => true, 'pages' => count($clean)]);
    }

    /** Phase 16 — GET /api/teacher/sources.registry.json.
     *  Serve la source registry del teacher autenticato (per rebuild badge
     *  client-side on origin change).
     *
     *  Senza registro (23/9/2026, A-43): le fonti del vecchio `sources.json`
     *  convertite, con `registro: "da-sources-json"`, oppure nessuna fonte
     *  con `registro: "assente"`. Sempre 200, e nessun file scritto. */
    public function sourcesRegistryJson(Request $req): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        if (!$tid) {
            return Response::json(['error' => 'teacher_not_found'], 404);
        }
        $iid = $this->privateFilesInstituteId($tid);
        if (!$iid) {
            return Response::json(\App\Services\SenzaScuola::risposta(), 404);
        }
        $key = "institutes/$iid/private/$tid/sources.registry.json";
        $storage = \App\Support\Storage\StorageFactory::default();
        // 23/9/2026 (A-43) — il docente che non ha ancora un registro ne ha
        // uno vuoto, non un 404: la pagina delle fonti mostrava «Errore» dove
        // doveva dire «Nessuna fonte registrata». Prima non si vedeva quasi
        // mai, perché il primo GET di sources.json scriveva un registro vuoto;
        // quella scrittura non c'è più.
        //
        // Chi ha ancora il vecchio `sources.json` riceve le sue fonti,
        // convertite come le converte il salvataggio (SourcesRegistry), e
        // `registro: "da-sources-json"`. Non un elenco vuoto: «Aggiungi» dal
        // catalogo e il salvataggio della pagina rimandano l'elenco che hanno
        // letto, e il registro che ne nasce ha la precedenza sul vecchio file
        // (sourcesCommonJson) — con zero fonti lette, le vecchie sparivano.
        // Il GET non scrive: il registro nasce al primo salvataggio, con
        // quello che la pagina ha mostrato (una fonte tolta lì resta tolta).
        if (!$storage->exists($key)) {
            $vecchio = self::vecchioFile($storage, "institutes/$iid/private/$tid/sources.json");
            $fonti = $vecchio !== null ? SourcesRegistry::fromLegacyDict($vecchio['sources']) : [];
            return Response::json([
                '$schema'  => 'pantedu.sources.v1',
                'count'    => count($fonti),
                'sources'  => $fonti,
                'registro' => $vecchio !== null ? 'da-sources-json' : 'assente',
            ]);
        }
        try {
            $bytes = $storage->get($key);
            $data = json_decode($bytes, true);
            if (!is_array($data)) {
                return Response::json(['error' => 'invalid_registry'], 500);
            }
            return Response::json($data);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'not_found', 'message' => $e->getMessage()], 404);
        }
    }

    // ---- helper condivisi (copia da ContentStudyController, ADR-029) ----

    private function resolveUserId(string $username): int
    {
        return \App\Support\TeacherContextResolver::userIdFromUsername($username);
    }

    /**
     * Qui si resta sulla casa dei file privati, non sull'istituto attivo
     * (23/9/2026, A-7): il registro delle fonti lo leggono dalla casa anche
     * la pagina di studio, la stampa e le verifiche (ContractRenderer,
     * BadgeRenderer). Spostare su questo controller solo il salvataggio
     * separerebbe dove si scrive da dove si legge.
     */
    private function privateFilesInstituteId(int $teacherId): int
    {
        return \App\Support\TeacherContextResolver::privateFilesInstituteId($teacherId);
    }
}
