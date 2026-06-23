<?php

declare(strict_types=1);

namespace App\Controllers\Risdoc;

use App\Core\Request;
use App\Core\Response;
use App\Services\Risdoc\Permission;
use App\Services\Risdoc\TemplateResolver;

/**
 * Override editor (Phase 21, U6).
 *
 * GET /risdoc/edit/{id}  → editor split-view HTML | TeX | CSS + guida mapping.
 * Accesso solo per owner/collab/super-admin.
 */
final class TemplateEditorController
{
    public function __construct(private TemplateResolver $resolver = new TemplateResolver())
    {
    }

    public function show(Request $req, array $params): Response
    {
        $id  = (int)($params['id'] ?? 0);
        $tid = Permission::currentTeacherId();
        if (!Permission::canEdit($id, $tid)) {
            return Response::html('<h1>403</h1><p>Non hai permessi di edit per questo template.</p>', 403);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::html('<h1>404</h1><p>Template non trovato.</p>', 404);
        }

        $logicSpec = $tmpl['logic_spec'] ? json_decode((string)$tmpl['logic_spec'], true) : null;
        ob_start();
        $title = htmlspecialchars(str_replace('_', ' ', (string)$tmpl['argomento']), ENT_QUOTES);
        $category = htmlspecialchars((string)$tmpl['category'], ENT_QUOTES);
        $numArg   = htmlspecialchars((string)$tmpl['num_arg'], ENT_QUOTES);
        $hasHtml = !empty($tmpl['html_file']);
        $hasTex  = !empty($tmpl['tex_file']);
        $hasCss  = !empty($tmpl['css_file']);
        require __DIR__ . '/../../../views/risdoc/edit.php';
        $html = ob_get_clean();
        $r = new Response($html, 200);
        $r->headers['Content-Type']  = 'text/html; charset=UTF-8';
        $r->headers['Cache-Control'] = 'private, no-cache';
        return $r;
    }
}
