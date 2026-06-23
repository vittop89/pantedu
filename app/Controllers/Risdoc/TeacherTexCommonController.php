<?php

declare(strict_types=1);

namespace App\Controllers\Risdoc;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Risdoc\OverrideRepository;
use App\Services\Risdoc\Permission;
use App\Services\TexCompile\TexCompileClient;
use App\Services\Verifica\VerificaTemplateStandard;

/**
 * G22.S13 — Editor dei 3 file texCommon condivisi da TUTTI i template risdoc.
 *
 * Endpoints:
 *   GET  /api/teacher/risdoc/templates/files       - JSON {files:[{path,content,
 *        size,overrideStatus,missing}]} con cascade default → institute → teacher.
 *   POST /api/teacher/risdoc/templates/files/save  - salva overrides:
 *        - super_admin: scope istituzionale (template_id=0 institutional override).
 *        - teacher: scope user (kind=texCommon, template_id=0 = "shared/all-templates").
 *
 * Diversamente da TexFilesController.php (che opera su un template_id specifico),
 * qui il template_id è 0 → override "shared all-templates" che vale per OGNI
 * istanza risdoc di quel docente/istituto. Il loadTexCommon helper in
 * ExportController::buildFiles cerca prima override per template specifico,
 * poi shared (template_id=0), infine il file su disco.
 *
 * NOTE su persistenza: in questo MVP l'override condiviso usa template_id=0
 * come marker. Un'eventuale evoluzione richiede una colonna `scope` enum
 * ('template','shared') in risdoc_teacher_overrides.
 */
final class TeacherTexCommonController
{
    private const TEX_COMMON_FILES = [
        'main.tex',
        'risdoc.sty',
        'intestaLAteX_IIS.tex',
    ];

    public function getFiles(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        if ($tid === 0 && !Permission::isSuperAdmin()) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $isAdmin = Permission::isSuperAdmin();
        $instituteCode = $this->resolveInstituteCode($tid, (string)($req->query['institute'] ?? ''));

        $root = dirname(__DIR__, 3);
        $repo = new OverrideRepository();

        $out = [];
        foreach (self::TEX_COMMON_FILES as $rel) {
            $abs = $root . '/storage/templates/risdoc/texCommon/' . $rel;
            $defaultBody = is_file($abs) ? (string)file_get_contents($abs) : '';

            // Cascade resolution per cosa l'utente VEDE:
            //   - admin → vede institute override (se exists) altrimenti default
            //   - teacher → vede teacher override (se exists) altrimenti institute → default
            $userOverride     = $tid > 0 ? $this->findShared($repo, $tid, $rel) : null;
            $instituteContent = null; // (Institute scope: in MVP solo via super_admin
                                      // che salva con tid=0 + flag — semplificazione: nessuna gestione
                                      // institute layer separato in questo controller; admin → scope user direttamente)

            $effectiveContent = '';
            $status = 'common';
            $missing = false;

            if ($userOverride !== null && $userOverride !== '') {
                $effectiveContent = $userOverride;
                $status = $isAdmin ? 'institute' : 'user';
            } elseif ($defaultBody !== '') {
                $effectiveContent = $defaultBody;
                $status = 'common';
            } else {
                $missing = true;
                $effectiveContent = "% G22.S13 — file mancante: {$rel}\n";
                $status = 'missing';
            }

            $out[] = [
                'path'           => $rel,
                'content'        => $effectiveContent,
                'size'           => strlen($effectiveContent),
                'editable'       => true,
                'overrideStatus' => $status,
                'missing'        => $missing,
            ];
        }
        return Response::json([
            'ok' => true,
            'files' => $out,
            'institute_code' => $instituteCode,
            'is_admin' => $isAdmin,
        ], 200);
    }

    public function saveFiles(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        if ($tid === 0 && !Permission::isSuperAdmin()) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $raw = (string)file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body) || !isset($body['files']) || !is_array($body['files'])) {
            return Response::json(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $allowed = array_flip(self::TEX_COMMON_FILES);
        $repo = new OverrideRepository();
        $saved = [];
        $skipped = [];
        foreach ($body['files'] as $f) {
            if (!is_array($f) || !isset($f['path'], $f['content'])) {
                continue;
            }
            $path = (string)$f['path'];
            if (!isset($allowed[$path])) {
                $skipped[] = $path;
                continue;
            }
            try {
                // template_id=0 → shared "all-templates" override scope.
                $repo->saveText(
                    $tid,
                    0,
                    'texCommon',
                    $path,
                    (string)$f['content'],
                    'shared-' . date('Y-m-d')
                );
                $saved[] = $path;
            } catch (\Throwable $e) {
                return Response::json([
                    'ok' => false,
                    'error' => 'save_failed',
                    'detail' => $e->getMessage(),
                    'path' => $path,
                ], 500);
            }
        }
        return Response::json(['ok' => true, 'saved' => $saved, 'skipped' => $skipped], 200);
    }

    /**
     * G22.S15.bis Fase 5 — POST /api/teacher/risdoc/templates/files/preview-pdf
     *
     * Compila un singolo file texCommon risdoc (main.tex, risdoc.sty,
     * intestaLAteX_IIS.tex) producendo un PDF di anteprima. Per i frammenti
     * (.sty, header) wrappa in un documento sintetico tramite buildPreviewTex
     * (stessa logica di TeacherVerificaFilesController).
     *
     * Body JSON: { path: string, content?: string }
     * Risposta: application/pdf binary | JSON error
     */
    public function previewPdf(Request $req): Response
    {
        $tid = Permission::currentTeacherId();
        if ($tid === 0 && !Permission::isSuperAdmin()) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $path    = (string)($payload['path'] ?? '');
        $content = isset($payload['content']) ? (string)$payload['content'] : null;

        if ($path === '' || !\in_array($path, self::TEX_COMMON_FILES, true)) {
            return Response::json(['ok' => false, 'error' => 'path_required_or_invalid'], 400);
        }

        // Se content non fornito, usa cascade default→teacher override
        if ($content === null) {
            $root = dirname(__DIR__, 3);
            $abs = $root . '/storage/templates/risdoc/texCommon/' . $path;
            $defaultBody = is_file($abs) ? (string)file_get_contents($abs) : '';
            $repo = new OverrideRepository();
            $userOverride = $tid > 0 ? $this->findShared($repo, $tid, $path) : null;
            $content = ($userOverride !== null && $userOverride !== '') ? $userOverride : $defaultBody;
            if ($content === '') {
                return Response::json(['ok' => false, 'error' => 'file_not_found'], 404);
            }
        }

        $client = TexCompileClient::tryDefault(60);
        if (!$client) {
            return Response::json(['ok' => false, 'error' => 'tex_compile_disabled'], 503);
        }

        // G22.S15.bis Fase 5 — main.tex referenzia texCommon/risdoc.sty +
        // intestaLAteX_IIS.tex via \input/\usepackage. Per compilare main.tex
        // dobbiamo inviare TUTTI e 3 i file come bundle (compileBundle del VPS).
        // Per .sty / fragment wrappa con buildPreviewTex (singolo file).
        if (basename($path) === 'main.tex') {
            // Costruisci bundle con tutti i file: usa il content fornito per
            // 'main.tex' e cascade per gli altri.
            $files = [];
            $root = dirname(__DIR__, 3);
            $repo = new OverrideRepository();
            foreach (self::TEX_COMMON_FILES as $rel) {
                if ($rel === $path) {
                    // main.tex: usa il content fornito (potenzialmente modificato)
                    $files[] = ['path' => 'texCommon/' . $rel, 'content' => $content];
                    // Inoltre crea un main.tex root-level che fa \input{texCommon/main}
                    continue;
                }
                $abs = $root . '/storage/templates/risdoc/texCommon/' . $rel;
                $defaultBody = is_file($abs) ? (string)file_get_contents($abs) : '';
                $userOverride = $tid > 0 ? $this->findShared($repo, $tid, $rel) : null;
                $body = ($userOverride !== null && $userOverride !== '') ? $userOverride : $defaultBody;
                $files[] = ['path' => 'texCommon/' . $rel, 'content' => $body];
            }
            // Root main.tex che include il main.tex texCommon (path relativi
            // dentro main.tex = relativi al root del bundle).
            $files[] = ['path' => 'main.tex', 'content' => "\\input{texCommon/main}\n"];
            $result = $client->compileBundle(
                files: $files,
                mainPath: 'main.tex',
                docId: 'risdoc-tpl-bundle-' . substr(md5($path), 0, 12),
                engine: (string)Config::get('tex_compile.default_engine', 'pdflatex'),
                passes: 2,
            );
        } else {
            // .sty e fragment: wrap singolo
            $tex = $this->buildPreviewTex($path, $content);
            $result = $client->compile(
                texSource: $tex,
                docId: 'risdoc-tpl-preview-' . substr(md5($path), 0, 12),
                engine: (string)Config::get('tex_compile.default_engine', 'pdflatex'),
                passes: 2,
            );
        }

        if (!$result['ok']) {
            return Response::json([
                'ok' => false,
                'error' => 'tex_compile_failed',
                'log_excerpt' => $this->extractFirstLatexError((string)($result['log'] ?? '')),
                'http_status' => $result['http_status'] ?? 0,
                'duration_ms' => $result['duration_ms'] ?? null,
            ], 422);
        }

        return new Response(
            body: (string)$result['pdf'],
            status: 200,
            headers: [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Compile-Duration-Ms' => (string)($result['duration_ms'] ?? ''),
            ],
        );
    }

    /** Wrap content in tex doc compilabile (stessa logica di TeacherVerificaFilesController). */
    private function buildPreviewTex(string $path, string $content): string
    {
        if (str_contains($content, '\\documentclass')) {
            return $content;
        }

        $title = 'Anteprima — ' . $path;
        $isStyle = str_ends_with(strtolower($path), '.sty')
                || preg_match('/\\\\(?:NeedsTeXFormat|ProvidesPackage)\b/', $content);

        if ($isStyle) {
            $stripped = preg_replace([
                '/\\\\NeedsTeXFormat\{[^}]*\}(?:\[[^\]]*\])?\s*/',
                '/\\\\ProvidesPackage\{[^}]*\}(?:\[[^\]]*\])?\s*/',
                '/\\\\endinput\b\s*$/',
            ], '', $content);
            $titleEsc = $this->escTexTitle($title);
            $pathEsc  = $this->escTexTitle($path);
            return "\\documentclass[a4paper,12pt]{article}\n"
                 . "\\title{{$titleEsc}}\n"
                 . "\\makeatletter\n{$stripped}\n\\makeatother\n"
                 . "\\begin{document}\n"
                 . "\\section*{Anteprima pacchetto: \\texttt{{$pathEsc}}}\n"
                 . "Pacchetto caricato senza errori. Comandi e ambienti definiti sono ora disponibili.\n\n"
                 . "\\smallskip\n"
                 . "\\textit{Per vedere l'effetto in un documento completo, apri \\texttt{main.tex}.}\n"
                 . "\\end{document}\n";
        }

        // Fragment generico: wrap in article minimale + body example
        $titleEsc = $this->escTexTitle($title);
        $pathEsc  = $this->escTexTitle($path);
        return "\\documentclass[a4paper,12pt]{article}\n"
             . "\\title{{$titleEsc}}\n"
             . "\\usepackage[utf8]{inputenc}\n"
             . "\\usepackage[T1]{fontenc}\n"
             . "\\begin{document}\n"
             . "\\section*{Anteprima frammento: \\texttt{{$pathEsc}}}\n"
             . $content . "\n"
             . "\\end{document}\n";
    }

    private function escTexTitle(string $s): string
    {
        return strtr($s, [
            '\\' => '\\textbackslash{}', '_' => '\\_', '#' => '\\#',
            '&' => '\\&', '$' => '\\$', '%' => '\\%', '{' => '\\{',
            '}' => '\\}', '~' => '\\textasciitilde{}', '^' => '\\textasciicircum{}',
        ]);
    }

    private function extractFirstLatexError(string $log): string
    {
        if ($log === '') {
            return '';
        }
        if (preg_match('/^!\s*[^\n]+/m', $log, $m)) {
            return mb_substr($m[0], 0, 300);
        }
        return mb_substr($log, -300);
    }

    /** Lookup override "shared" (template_id=0) per il teacher e file. */
    private function findShared(OverrideRepository $repo, int $tid, string $relPath): ?string
    {
        $ov = $repo->find($tid, 0, 'texCommon', $relPath);
        return ($ov && isset($ov['body'])) ? (string)$ov['body'] : null;
    }

    private function resolveInstituteCode(int $tid, string $hint): string
    {
        if ($tid <= 0) {
            return '';
        }
        try {
            $db = Database::connection();
            if ($hint !== '' && preg_match('/^[A-Z0-9_-]{1,32}$/', $hint)) {
                $st = $db->prepare('SELECT institute_code FROM teacher_institutes ti
                                     JOIN institutes i ON i.id=ti.institute_id
                                     WHERE ti.teacher_id=? AND i.institute_code=? LIMIT 1');
                $st->execute([$tid, $hint]);
                $r = $st->fetchColumn();
                if ($r) {
                    return (string)$r;
                }
            }
            $st = $db->prepare('SELECT institute_code FROM teacher_institutes ti
                                 JOIN institutes i ON i.id=ti.institute_id
                                 WHERE ti.teacher_id=? ORDER BY ti.id DESC LIMIT 1');
            $st->execute([$tid]);
            $r = $st->fetchColumn();
            return $r ? (string)$r : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
