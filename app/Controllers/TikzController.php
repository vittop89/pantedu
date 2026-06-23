<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\FileService;
use App\Services\TikzElementsService;
use App\Services\TikzService;
use App\Services\Tikz\TeacherTemplateOverridesService;
use App\Support\Validator;
use Throwable;

final class TikzController
{
    private TikzService $tikz;
    private TikzElementsService $elements;
    private TeacherTemplateOverridesService $overrides;

    public function __construct(
        ?TikzService $service = null,
        ?TikzElementsService $elements = null,
        ?TeacherTemplateOverridesService $overrides = null,
    ) {
        $this->tikz = $service ?? new TikzService(new FileService());
        $this->elements = $elements ?? new TikzElementsService();
        $this->overrides = $overrides ?? new TeacherTemplateOverridesService();
    }

    /** POST /tikz/save-svg — filePath, folderName, fileName, svgContent */
    public function saveSvg(Request $req): Response
    {
        try {
            $v = new Validator($req->post);
            $filePath   = $v->webPath('filePath', extPattern: ['php', 'html']);
            $folderName = $v->string('folderName', regex: '#^[A-Za-z0-9_\-/. ]+$#', max: 255);
            $fileName   = $v->string('fileName', regex: '#^[A-Za-z0-9_\-.]+\.svg$#', max: 180);
            $svg        = $v->string('svgContent', max: 10 * 1024 * 1024);
            $result     = $this->tikz->saveSvg($filePath, $folderName, $fileName, $svg);
            return Response::json(['success' => true] + $result);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST /tikz/delete-svg — filePath, fileName (Phase 16 lifecycle). */
    public function deleteSvg(Request $req): Response
    {
        try {
            $v = new Validator($req->post);
            $filePath = $v->string('filePath', regex: '#^[A-Za-z0-9_\-/. ]*$#', max: 255);
            $fileName = $v->string('fileName', regex: '#^[A-Za-z0-9_\-.]+\.svg$#', max: 180);
            $result   = $this->tikz->deleteSvg($filePath, $fileName);
            return Response::json(['success' => true] + $result);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** GET /tikz/content?group=...&index=... */
    public function content(Request $req): Response
    {
        try {
            $v = new Validator($req->query);
            $group = $v->string('group', regex: '#^[A-Za-z0-9_\- ]+$#', max: 80);
            $index = $v->int('index', min: 0, max: 9999);
            $modelli = $req->query['file'] ?? 'modelli_tikz.php';
            $content = $this->tikz->getContent((string)$modelli, $group, $index);
            return new Response(
                body: $content,
                status: 200,
                headers: ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        } catch (Throwable $e) {
            return new Response(
                body: $e->getMessage(),
                status: 400,
                headers: ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }
    }

    /** GET /tikz/ensure-json?force=true|false */
    public function ensureJson(Request $req): Response
    {
        try {
            $force = ($req->query['force'] ?? 'false') === 'true';
            $out   = $this->tikz->ensureJson('views/admin/templates/modelli_tikz.php', 'storage/data/modelli_tikz.json', $force);
            return Response::json(['success' => true] + $out);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
    /** POST /tikz/edit_tikz_element.php — edit/rename/move element */
    public function editElement(Request $req): Response
    {
        try {
            $v = new Validator($req->post);
            $groupName    = $v->string('groupName', max: 120);
            // Phase 16: elementIndex o elementLabel (almeno uno dei due).
            $elementIndex = isset($req->post['elementIndex']) && $req->post['elementIndex'] !== ''
                ? $v->int('elementIndex', min: 0, max: 9999) : -1;
            $elementLabel = $v->string('elementLabel', required: false, default: '', max: 200);
            $newGroupName = $v->string('newGroupName', required: false, default: '', max: 120);
            $moveToGroup  = $v->string('moveToGroup', required: false, default: '', max: 120);
            $elementType  = $v->string('elementType', regex: '#^(tikz|latex)$#', default: 'tikz');
            $label        = $v->string('label', max: 200);
            $code         = $v->string('code', max: 200 * 1024);
            $out = $this->elements->editElement($groupName, $elementIndex, $newGroupName, $moveToGroup, $elementType, $label, $code, $elementLabel);

            // G22.S15.bis — propagazione cross-teacher: rinomina/move/label-change
            // del default admin migra le chiavi degli override docenti per non
            // creare orfani. Best-effort: errori loggati ma non rompono la response.
            $migratedTeachers = 0;
            try {
                if ($out['renamed'] === true) {
                    // Gruppo intero rinominato: $groupName → $out['group'] (gruppo-NEW)
                    $migratedTeachers += $this->overrides->migrateRenameGroup((string)$out['originalGroup'], (string)$out['group']);
                } elseif ($out['moved'] === true) {
                    // Singolo elemento spostato di gruppo (label invariato): originalGroup → group
                    $migratedTeachers += $this->overrides->migrateMoveElement((string)$out['originalGroup'], (string)$out['group'], (string)$out['label']);
                }
                // Label change DENTRO lo stesso gruppo (no rename/no move): se elementLabel
                // input differisce dal label finale, migra la chiave.
                if (
                    $out['renamed'] !== true && $out['moved'] !== true
                    && $elementLabel !== '' && $elementLabel !== (string)$out['label']
                ) {
                    $migratedTeachers += $this->overrides->migrateRenameLabel((string)$out['group'], $elementLabel, (string)$out['label']);
                }
            } catch (Throwable $_) {
/* migrazione orfani non blocca la response admin */
            }

            return Response::json([
                'success'           => true,
                'message'           => 'Elemento modificato con successo',
                'group'             => $out['group'],
                'originalGroup'     => $out['originalGroup'],
                'renamed'           => $out['renamed'],
                'moved'             => $out['moved'],
                'label'             => $out['label'],
                'migrated_teachers' => $migratedTeachers,
            ]);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST /tikz/delete_tikz_element.php — remove element or whole group */
    public function deleteElement(Request $req): Response
    {
        try {
            $v = new Validator($req->post);
            $groupName   = $v->string('groupName', max: 120);
            $wholeGroup  = ($req->post['deleteWholeGroup'] ?? '') === 'true';
            $label       = $v->string('elementLabel', required: false, default: '', max: 200);
            $out = $this->elements->deleteElement($groupName, $label, $wholeGroup);

            // G22.S15.bis — cleanup orfani cross-teacher: gli override docenti
            // riferiti all'elemento/gruppo eliminato vanno rimossi.
            $migratedTeachers = 0;
            try {
                if ($wholeGroup || ($out['groupRemoved'] ?? false) === true) {
                    $migratedTeachers += $this->overrides->migrateDeleteGroup($groupName);
                } elseif ($label !== '') {
                    $migratedTeachers += $this->overrides->migrateDeleteElement($groupName, $label);
                }
            } catch (Throwable $_) {
/* cleanup orfani non blocca la response admin */
            }

            return Response::json([
                'success'           => true,
                'message'           => $wholeGroup ? 'Gruppo eliminato con successo' : 'Elemento eliminato con successo',
                'group'             => $out['group'],
                'deletedLabel'      => $out['deletedLabel'],
                'migrated_teachers' => $migratedTeachers,
            ]);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST /tikz/save_new_tikz_element.php — append new tikz|latex element */
    public function saveNewElement(Request $req): Response
    {
        try {
            $v = new Validator($req->post);
            $groupName     = $v->string('groupName', required: false, default: '', max: 120);
            $existingGroup = $v->string('existingGroup', required: false, default: '', max: 120);
            $elementType   = $v->string('elementType', regex: '#^(tikz|latex)$#', default: 'tikz');
            $label         = $v->string('label', max: 200);
            $code          = $v->string('code', max: 200 * 1024);
            $out           = $this->elements->createElement($groupName, $existingGroup, $elementType, $label, $code);
            return Response::json([
                'success' => true,
                'message' => 'Elemento salvato con successo',
                'group'   => $out['group'],
                'label'   => $out['label'],
            ]);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST /tikz/generate_tikz_json.php — rebuild elements + traccia JSON */
    public function generateJson(Request $req): Response
    {
        try {
            $out = $this->elements->generateAll(
                'views/admin/templates/modelli_tikz.php',
                'storage/data/modelli_tikz_elements.json',
                'storage/data/modelli_tikz_traccia.json',
            );
            return Response::json(['success' => true] + $out);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
