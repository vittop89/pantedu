<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;
use Throwable;

/**
 * ContentTemplateController — estratto da TeacherContentController (ADR-029).
 * Metodi: templatesJson, templatesSave.
 * Helper condivisi duplicati: teacherId, firstInstituteId.
 */
final class ContentTemplateController
{
    private TeacherContentRepository $repo;

    public function __construct(?TeacherContentRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
    }

    /** Phase 20 — GET /api/teacher/templates.json. Restituisce i template
     *  personali del docente (VF/RM/Collect) per il seed di nuovi gruppi.
     *  Fallback: formato canonico con intro default + items default. */
    public function templatesJson(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $raw = TemplateDefaults::readRaw($tid);
        if ($raw !== null) {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return Response::json($data);
            }
        }
        return Response::json(TemplateDefaults::seedDefault());
    }

    /** Phase 20 — PUT /api/teacher/templates.json. Salva i template
     *  personali del docente. Body JSON:
     *    `{ VF: {intro, items:[{question,answer,justification}]}, RM: ..., Collect: ... }`
     *  Sanitizza tutti i campi di testo come stringhe (<=8KB per template). */
    public function templatesSave(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $iid = $this->firstInstituteId($tid);
        if ($iid <= 0) {
            return Response::json(['error' => 'institute_not_found'], 404);
        }
        $raw = (string)@file_get_contents('php://input');
        if (strlen($raw) > 65536) {
            return Response::json(['error' => 'payload_too_large'], 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return Response::json(['error' => 'invalid_payload'], 422);
        }

        $clean = [];
        $str = fn($v, int $cap = 4000) => is_string($v) ? mb_substr($v, 0, $cap) : '';

        foreach (['VF', 'RM', 'Collect'] as $k) {
            if (!isset($data[$k]) || !is_array($data[$k])) {
                continue;
            }
            $entry = [
                'title' => $str($data[$k]['title'] ?? '', 128),
                'intro' => $str($data[$k]['intro'] ?? '', 512),
                'items' => [],
            ];
            $items = is_array($data[$k]['items'] ?? null) ? $data[$k]['items'] : [];
            foreach (array_slice($items, 0, 20) as $it) {
                if (!is_array($it)) {
                    continue;
                }
                if ($k === 'VF') {
                    $ans = $str($it['answer'] ?? 'V', 1);
                    $entry['items'][] = [
                        'question'      => $str($it['question'] ?? '', 2000),
                        'answer'        => ($ans === 'F' ? 'F' : 'V'),
                        'justification' => $str($it['justification'] ?? '', 2000),
                    ];
                } elseif ($k === 'RM') {
                    $opts = is_array($it['options'] ?? null) ? $it['options'] : [];
                    $optsClean = [];
                    foreach (array_slice($opts, 0, 16) as $op) {
                        if (!is_array($op)) {
                            continue;
                        }
                        $optsClean[] = [
                            'content' => $str($op['content'] ?? '', 2000),
                            'correct' => !empty($op['correct']),
                        ];
                    }
                    $entry['items'][] = [
                        'question'      => $str($it['question'] ?? '', 2000),
                        'options'       => $optsClean,
                        'justification' => $str($it['justification'] ?? '', 2000),
                    ];
                } else {
                    $entry['items'][] = [
                        'question' => $str($it['question'] ?? '', 4000),
                        'solution' => $str($it['solution'] ?? '', 4000),
                    ];
                }
            }
            if ($entry['items']) {
                $clean[$k] = $entry;
            }
        }

        $key = "institutes/$iid/private/$tid/templates.json";
        try {
            \App\Support\Storage\StorageFactory::default()->put(
                $key,
                (string)json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            return Response::json(['error' => 'storage_error'], 500);
        }
        return Response::json(['ok' => true, 'blocks' => count($clean)]);
    }

    // ---- helper condivisi (copia da TeacherContentController, ADR-029) ----

    private function teacherId(): int
    {
        $u = Auth::user();
        if (!$u) {
            return 0;
        }
        return \App\Support\TeacherContextResolver::userIdFromUsername((string)($u['username'] ?? ''));
    }

    private function firstInstituteId(int $teacherId): int
    {
        return \App\Support\TeacherContextResolver::firstInstituteId($teacherId);
    }
}
