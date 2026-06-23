<?php

declare(strict_types=1);

namespace App\Controllers\Risdoc;

use App\Core\Request;
use App\Core\Response;
use App\Services\AclPolicy;
use App\Services\Risdoc\CurriculumDataRepository;
use App\Services\Risdoc\Permission;
use App\Support\CurriculumLookup;

/**
 * ADR-025 (B) — Dati curriculari risdoc (obiettivi/competenze/abilità/conoscenze/
 * programmi/minimi) come dati ISTITUZIONALI dinamici.
 *
 *   GET  /api/risdoc/curriculum-options?dataset&indirizzo&classe&materia
 *        → opzioni risolte: override istituto → globale (DB) → file statico.
 *          Risposta = ARRAY JSON (stesso formato del file statico legacy).
 *   POST /api/risdoc/curriculum-options        (admin) → upsert override
 *   POST /api/risdoc/curriculum-options/delete (admin) → elimina override
 *
 * Codici (dataset/indirizzo/classe/materia) CANONICI dinamici da curriculum_entries.
 */
final class CurriculumOptionsController
{
    private CurriculumDataRepository $repo;

    public function __construct(?CurriculumDataRepository $repo = null)
    {
        $this->repo = $repo ?? new CurriculumDataRepository();
    }

    /** Whitelist anti-traversal: dataset 'a/b', codici alfanumerici. */
    private function clean(string $v, bool $slash = false): string
    {
        $pattern = $slash ? '/[^A-Za-z0-9_\-\/]/' : '/[^A-Za-z0-9_\-]/';
        $v = preg_replace($pattern, '', $v) ?? '';
        return trim($v, '/');
    }

    public function options(Request $req): Response
    {
        $dataset   = $this->clean((string)($req->query['dataset']   ?? ''), true);
        $indirizzo = $this->clean((string)($req->query['indirizzo'] ?? ''));
        $classe    = $this->clean((string)($req->query['classe']    ?? ''));
        $materia   = $this->clean((string)($req->query['materia']   ?? ''));
        if ($dataset === '' || $indirizzo === '' || $classe === '' || $materia === '' || str_contains($dataset, '..')) {
            return Response::json([], 200); // input incompleto → nessuna opzione
        }

        $tid = Permission::currentTeacherId();
        $instituteId = (int)(CurriculumLookup::instituteForTeacher($tid ?: null) ?? 0);

        // 1) DB: override istituto → globale.
        $opts = $this->repo->find($instituteId, $dataset, $indirizzo, $classe, $materia);

        // 2) Fallback file statico (default/seed non ancora importato).
        if ($opts === null) {
            $matLower = strtolower($materia);
            $rel = $dataset . '/' . $indirizzo . '/' . $matLower . '/'
                 . $indirizzo . '_' . $classe . '_' . $matLower . '.json';
            $abs = dirname(__DIR__, 3) . '/storage/templates/risdoc/' . $rel;
            if (is_file($abs)) {
                $decoded = json_decode((string)file_get_contents($abs), true);
                if (is_array($decoded)) {
                    $opts = $decoded;
                }
            }
        }

        $r = new Response(json_encode(array_values($opts ?? []), JSON_UNESCAPED_UNICODE), 200);
        $r->headers['Content-Type']  = 'application/json';
        $r->headers['Cache-Control'] = 'private, no-cache';
        return $r;
    }

    public function save(Request $req): Response
    {
        if (!AclPolicy::isSuperAdmin()) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $raw  = (string)file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];
        $dataset   = $this->clean((string)($body['dataset']   ?? ''), true);
        $indirizzo = $this->clean((string)($body['indirizzo'] ?? ''));
        $classe    = $this->clean((string)($body['classe']    ?? ''));
        $materia   = $this->clean((string)($body['materia']   ?? ''));
        $options   = $body['options'] ?? null;
        if ($dataset === '' || $indirizzo === '' || $classe === '' || $materia === '' || !is_array($options)) {
            return Response::json(['ok' => false, 'error' => 'invalid_payload'], 400);
        }
        // institute_id: 0 = globale; altrimenti istituto del super-admin (o esplicito).
        $instituteId = isset($body['institute_id']) ? (int)$body['institute_id']
            : (int)(CurriculumLookup::instituteForTeacher(Permission::currentTeacherId() ?: null) ?? 0);
        $this->repo->save($instituteId, $dataset, $indirizzo, $classe, $materia, $options, Permission::currentTeacherId());
        return Response::json(['ok' => true, 'institute_id' => $instituteId, 'count' => count($options)]);
    }

    public function delete(Request $req): Response
    {
        if (!AclPolicy::isSuperAdmin()) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $raw  = (string)file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];
        $dataset   = $this->clean((string)($body['dataset']   ?? ''), true);
        $indirizzo = $this->clean((string)($body['indirizzo'] ?? ''));
        $classe    = $this->clean((string)($body['classe']    ?? ''));
        $materia   = $this->clean((string)($body['materia']   ?? ''));
        // Simmetrico a save(): institute_id assente → istituto del docente
        // (ambito "Mio istituto"); esplicito 0 → globale.
        $instituteId = isset($body['institute_id']) ? (int)$body['institute_id']
            : (int)(CurriculumLookup::instituteForTeacher(Permission::currentTeacherId() ?: null) ?? 0);
        if ($dataset === '' || $indirizzo === '' || $classe === '' || $materia === '') {
            return Response::json(['ok' => false, 'error' => 'invalid_payload'], 400);
        }
        $ok = $this->repo->delete($instituteId, $dataset, $indirizzo, $classe, $materia);
        return Response::json(['ok' => $ok]);
    }
}
