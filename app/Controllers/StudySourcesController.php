<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Policies\ExerciseAccessPolicy;
use App\Repositories\TeacherContentRepository;

/**
 * StudySourcesController — estratto da ContentStudyController (ADR-029).
 * Metodi: sourcesCommonJson, originsJson, sourcesSave, sourcesRegistrySave, sourcesRegistryJson, checkedOriginsJson, checkedOriginsSave.
 * Helper condivisi duplicati: resolveUserId, firstInstituteId.
 */
final class StudySourcesController
{
    private TeacherContentRepository $repo;

    public function __construct(?TeacherContentRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
    }

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
     *  `sources.json` (docenti mai migrati). Seed iniziale dal template
     *  `.github/workflows/deploy/config/SOURCES_COMMON.json` solo se né
     *  registry né legacy esistono.
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
        $iid = $this->firstInstituteId($tid);
        if (!$iid) {
            return Response::json(['error' => 'institute_not_found'], 404);
        }
        $storage  = \App\Support\Storage\StorageFactory::default();
        $regKey   = "institutes/$iid/private/$tid/sources.registry.json";
        $legacyKey = "institutes/$iid/private/$tid/sources.json";

        // 1) Tenta lettura registry (formato canonico).
        try {
            $bytes = $storage->get($regKey);
            $reg = json_decode((string)$bytes, true);
            if (is_array($reg) && is_array($reg['sources'] ?? null)) {
                return Response::json(['sources' => self::registryArrayToLegacyDict($reg['sources'])]);
            }
        } catch (\Throwable) {
/* registry assente → fallback */
        }

        // 2) Fallback retro-compat: legge sources.json legacy se presente.
        try {
            $bytes = $storage->get($legacyKey);
            $data = json_decode((string)$bytes, true);
            if (is_array($data) && is_array($data['sources'] ?? null)) {
                return Response::json($data);
            }
        } catch (\Throwable) {
/* né registry né legacy → seed */
        }

        // 3) Primo accesso: seed dal template comune e ritorna il dict.
        $base = (string)Config::get('app.paths.base');
        $seedPath = $base . '/.github/workflows/deploy/config/SOURCES_COMMON.json';
        $seedBytes = is_file($seedPath) ? (string)@file_get_contents($seedPath) : '{"sources":{}}';
        $seed = json_decode($seedBytes, true);
        if (!is_array($seed)) {
            $seed = ['sources' => new \stdClass()];
        }
        // Persiste come registry (formato canonico) per i prossimi accessi.
        try {
            $regSeed = self::legacyDictToRegistryArray(is_array($seed['sources'] ?? null) ? $seed['sources'] : []);
            $storage->put($regKey, (string)json_encode([
                '$schema'      => 'pantedu.sources.v1',
                'teacher_id'   => $tid,
                'institute_id' => $iid,
                'generated_at' => date('c'),
                'count'        => count($regSeed),
                'sources'      => $regSeed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
/* best-effort */
        }
        return Response::json($seed);
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
        $iid = $this->firstInstituteId($tid);
        if (!$iid) {
            return Response::json(['error' => 'institute_not_found'], 404);
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
        $iid = $this->firstInstituteId($tid);
        if (!$iid) {
            return Response::json(['error' => 'institute_not_found'], 404);
        }

        $raw = (string)@file_get_contents('php://input');
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
                $list[] = [
                    'key'     => $key,
                    'book'    => (string)($r['book']    ?? ''),
                    'volume'  => (string)($r['volume']  ?? ''),
                    'authors' => (string)($r['authors'] ?? ''),
                ];
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
            $list = self::legacyDictToRegistryArray($data['sources']);
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
        $iid = $this->firstInstituteId($tid);
        if (!$iid) {
            return Response::json(['error' => 'institute_not_found'], 404);
        }
        $raw = (string)@file_get_contents('php://input');
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
            $list[] = [
                'key'     => $key,
                'book'    => (string)($r['book']    ?? ''),
                'volume'  => (string)($r['volume']  ?? ''),
                'authors' => (string)($r['authors'] ?? ''),
            ];
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
        $iid = $this->firstInstituteId($tid);
        if (!$iid) {
            return Response::json(['error' => 'institute_not_found'], 404);
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
        $iid = $this->firstInstituteId($tid);
        if (!$iid) {
            return Response::json(['error' => 'institute_not_found'], 404);
        }
        $raw = (string)@file_get_contents('php://input');
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
     *  client-side on origin change). */
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
        $iid = $this->firstInstituteId($tid);
        if (!$iid) {
            return Response::json(['error' => 'institute_not_found'], 404);
        }
        try {
            $key = "institutes/$iid/private/$tid/sources.registry.json";
            $bytes = \App\Support\Storage\StorageFactory::default()->get($key);
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

    private function firstInstituteId(int $teacherId): int
    {
        return \App\Support\TeacherContextResolver::firstInstituteId($teacherId);
    }
}
