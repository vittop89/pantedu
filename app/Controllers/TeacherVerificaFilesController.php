<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\TexBuilder\BuildResult;
use App\Services\TexBuilder\PlaceholderResolver;
use App\Services\TexCompile\TexCompileClient;
use App\Services\Verifica\TemplateFileStore;
use App\Services\Verifica\VerificaTemplateStandard;
use Throwable;

/**
 * G20.1 — File API per docente non-admin: editor delle proprie
 * personalizzazioni dei modelli verifica. Scope auto-bounded a
 * `t_{teacher_id}`.
 *
 * Endpoint:
 *   GET    /api/teacher/verifica/files                    — list
 *   GET    /api/teacher/verifica/files/read?path=         — read raw + cascade default
 *   POST   /api/teacher/verifica/files/write              — write (json {path,content})
 *   POST   /api/teacher/verifica/files/delete             — delete personalizzazione (torna a istituto/default)
 *   POST   /api/teacher/verifica/files/copy-from-default  — copia istituto o default come base
 *
 * Cascade visibile al docente: `t_{id}` → `{institute_code}` → `_default`.
 * Il file e' considerato "personalizzato dal docente" solo se esiste in
 * `t_{id}/`. Altrimenti deriva da istituto o comune.
 */
final class TeacherVerificaFilesController
{
    public function listFiles(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $teacherScope   = TemplateFileStore::teacherScope($tid);
        // G20.7 — query `?institute=<code>` permette al docente con piu'
        // istituti di selezionare quale scope di override considerare. Il
        // codice e' validato contro teacher_institutes (no ext-injection).
        $hint = trim((string)($req->query['institute'] ?? ''));
        $instituteCode  = $this->resolveInstituteCode($tid, $hint !== '' ? $hint : null);

        // Build una list manuale che include 3-livello cascade info per ogni path
        $allPaths = TemplateFileStore::ALLOWED_PATHS;

        // Aggiungi griglie esistenti (default + istituto + teacher)
        $foundGriglie = [];
        foreach ([TemplateFileStore::SCOPE_DEFAULT, $instituteCode, $teacherScope] as $sc) {
            if (!$sc) {
                continue;
            }
            $dir = TemplateFileStore::rootDir() . "/$sc/griglie";
            if (is_dir($dir)) {
                foreach (glob($dir . '/*.tex') ?: [] as $f) {
                    $name = basename($f);
                    if (TemplateFileStore::isAllowedGrigliaPath("griglie/$name")) {
                        $foundGriglie["griglie/$name"] = true;
                    }
                }
            }
        }
        $allPaths = array_merge($allPaths, array_keys($foundGriglie));

        $files = [];
        foreach ($allPaths as $rel) {
            $tFile = TemplateFileStore::rootDir() . "/$teacherScope/$rel";
            $iFile = $instituteCode ? TemplateFileStore::rootDir() . "/$instituteCode/$rel" : null;
            $dFile = TemplateFileStore::rootDir() . '/' . TemplateFileStore::SCOPE_DEFAULT . "/$rel";

            $isMine     = is_file($tFile);
            $hasInstitute = $iFile && is_file($iFile);
            $hasDefault = is_file($dFile);

            // Source effettiva (chi vince la cascade)
            $source = $isMine ? 'teacher'
                    : ($hasInstitute ? 'institute'
                    : ($hasDefault ? 'default' : 'missing'));

            $size = $isMine ? (int)@filesize($tFile)
                  : ($hasInstitute ? (int)@filesize($iFile)
                  : ($hasDefault ? (int)@filesize($dFile) : 0));

            $files[] = [
                'path'           => $rel,
                'is_mine'        => $isMine,
                'has_institute'  => $hasInstitute,
                'has_default'    => $hasDefault,
                'source'         => $source,
                'size'           => $size,
            ];
        }

        return Response::json([
            'ok'              => true,
            'teacher_id'      => $tid,
            'institute_code'  => $instituteCode,
            'files'           => $files,
        ]);
    }

    public function readFile(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $path = (string)($req->query['path'] ?? '');
        $hint = trim((string)($req->query['institute'] ?? ''));
        $teacherScope  = TemplateFileStore::teacherScope($tid);
        $instituteCode = $this->resolveInstituteCode($tid, $hint !== '' ? $hint : null);

        try {
            $content        = TemplateFileStore::readCascade($path, $tid, $instituteCode);
            $defaultContent = TemplateFileStore::read(TemplateFileStore::SCOPE_DEFAULT, $path);
            $instituteContent = $instituteCode
                ? TemplateFileStore::readRaw($instituteCode, $path)
                : null;
            $isMine         = TemplateFileStore::readRaw($teacherScope, $path) !== null;

            return Response::json([
                'ok'                => true,
                'path'              => $path,
                'content'           => $content,
                'default'           => $defaultContent,
                'institute_content' => $instituteContent,
                'is_mine'           => $isMine,
                'has_institute'     => $instituteContent !== null,
                'has_default'       => $defaultContent !== null,
                'institute_code'    => $instituteCode,
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function writeFile(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $path    = (string)($payload['path'] ?? '');
        $content = (string)($payload['content'] ?? '');
        try {
            TemplateFileStore::write(TemplateFileStore::teacherScope($tid), $path, $content);
            return Response::json(['ok' => true, 'bytes' => strlen($content)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function deleteFile(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $path = (string)($payload['path'] ?? '');
        try {
            $deleted = TemplateFileStore::delete(TemplateFileStore::teacherScope($tid), $path);
            return Response::json(['ok' => true, 'deleted' => $deleted]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * G21.3 — POST /api/teacher/verifica/files/preview-pdf
     *
     * Compila il template (frammento o file completo) via VPS tex-compile-vps
     * e ritorna il PDF binario inline. Per i frammenti, wrappa in un .tex
     * standalone con preambolo esteso (riusa VerificaTemplateStandard).
     *
     * Body JSON:
     *   { path: string, content?: string }
     *   - path: usato per inferire se è frammento (texCommon/, griglie/) o
     *           documento completo (versioni/)
     *   - content: se omesso, legge il file salvato; altrimenti usa il testo
     *              dato (per preview di edit non ancora salvati)
     *
     * Risposta success: application/pdf binario
     * Risposta errore:  application/json { ok:false, error, log_excerpt }
     */
    public function previewPdf(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $path    = (string)($payload['path'] ?? '');
        $content = isset($payload['content']) ? (string)$payload['content'] : null;

        if ($path === '') {
            return Response::json(['ok' => false, 'error' => 'path_required'], 400);
        }

        // Se content non fornito, leggi cascade (teacher → istituto → default)
        if ($content === null) {
            $hint = trim((string)($payload['institute'] ?? ''));
            $instituteCode = $this->resolveInstituteCode($tid, $hint !== '' ? $hint : null);
            $teacherScope  = TemplateFileStore::teacherScope($tid);
            $content = TemplateFileStore::readRaw($teacherScope, $path)
                    ?? ($instituteCode ? TemplateFileStore::readRaw($instituteCode, $path) : null)
                    ?? TemplateFileStore::read(TemplateFileStore::SCOPE_DEFAULT, $path)
                    ?? '';
            if ($content === '') {
                return Response::json(['ok' => false, 'error' => 'file_not_found'], 404);
            }
        }

        // G21.4 — sostituisci placeholder {{KEY}} con valori reali dal teacher.
        // Senza questo, intestazione.tex ha {{TEACHER_NAME}}, {{CLASSE}}, ecc.
        // che pdflatex interpreta come errore di parentesi graffe sbilanciate.
        $stmt = Database::connection()->prepare(
            'SELECT username, first_name, last_name, email FROM users WHERE id = ?'
        );
        $stmt->execute([$tid]);
        $teacher = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $context = VerificaTemplateStandard::buildContext($teacher, []);

        // G22.16 — multi-file: se il client invia l'albero file e il target è
        // un documento completo (\documentclass), compila come BUNDLE così
        // \input{/intestazione}, texCommon/ ecc. risolvono dentro la sandbox
        // del tex-compile. Altrimenti wrap single-source per i frammenti.
        $files = [];
        $filesPayload = $payload['files'] ?? null;
        if (is_array($filesPayload)) {
            foreach ($filesPayload as $f) {
                if (
                    isset($f['path'], $f['content'])
                    && is_string($f['path']) && $f['path'] !== ''
                    && is_string($f['content'])
                ) {
                    $files[] = ['path' => $f['path'], 'content' => $f['content']];
                }
            }
        }
        $useBundle = $files !== [] && str_contains($content, '\\documentclass');

        // G22.17 — placeholder di layout (path) risolti PRIMA di substitute():
        // vedi previewPathVars(). Su frammenti (no bundle) resta no-op.
        $pathVars = $useBundle ? $this->previewPathVars($path, $files) : [];

        $contentSubstituted = VerificaTemplateStandard::substitute(
            strtr($content, $pathVars),
            $context,
        );

        // Compile via VPS — G22.S15.bis Fase 5+ factory centralizzato.
        $client = TexCompileClient::tryDefault(60);
        if (!$client) {
            return Response::json(['ok' => false, 'error' => 'tex_compile_disabled'], 503);
        }

        $docId = 'tpl-preview-' . substr(md5($path), 0, 12);
        $engine = (string)Config::get('tex_compile.default_engine', 'pdflatex');

        if ($useBundle) {
            // Il target nel bundle porta il contenuto corrente (override editor
            // o versione salvata appena letta); se assente, aggiungilo.
            // G22.17 — gli ALTRI file arrivano raw dai buffer dell'editor: vanno
            // sostituiti anche loro, altrimenti intestazione.tex compila
            // `{{DOCENTE_NOME}}` letterale e sbilancia le graffe.
            $hasMain = false;
            foreach ($files as $i => $f) {
                if ($f['path'] === $path) {
                    $files[$i]['content'] = $contentSubstituted;
                    $hasMain = true;
                    continue;
                }
                $files[$i]['content'] = VerificaTemplateStandard::substitute(
                    strtr($f['content'], $pathVars),
                    $context,
                );
            }
            if (!$hasMain) {
                $files[] = ['path' => $path, 'content' => $contentSubstituted];
            }
            $files = $this->addPreviewStubs($files, $path, $pathVars);
            $result = $client->compileBundle(
                files: $files,
                mainPath: $path,
                docId: $docId,
                engine: $engine,
                passes: 2,
            );
        } else {
            // Wrap intelligente: se il content ha già \documentclass, usalo
            // as-is. Altrimenti wrap con preambolo esteso per i frammenti.
            $tex = $this->buildPreviewTex($path, $contentSubstituted);
            $result = $client->compile(
                texSource: $tex,
                docId: $docId,
                engine: $engine,
                passes: 2,
            );
        }

        if (!$result['ok']) {
            return Response::json([
                'ok'    => false,
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
                'Content-Type'  => 'application/pdf',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Compile-Duration-Ms' => (string)($result['duration_ms'] ?? ''),
            ],
        );
    }

    /**
     * G22.17 — placeholder di LAYOUT per l'anteprima di un `versioni/main_*.tex`.
     *
     * A save time li risolve TexBuilder via PlaceholderResolver; nell'editor
     * modelli non esiste ancora una verifica, e `substitute()` non conoscendoli
     * li strippa: `\input{{{ESERCIZI_FILE}}}` diventa `\input{}` e
     * `\usepackage{{{TEXCOMMON_DIR}}/verifica}` diventa `\usepackage{/verifica}`.
     * Qui si riusano gli stessi prefissi del layout ZIP — che è esattamente il
     * layout del bundle inviato al tex-compile (main in `versioni/`, `texCommon`
     * e `griglie` sibling) — puntando alla prima griglia davvero presente.
     *
     * Sostituzione GREZZA (str_replace, non `substitute()`): sono path, e
     * TexEscape trasformerebbe `esercizi_NOR` in `esercizi\_NOR`.
     *
     * @param list<array{path:string,content:string}> $files
     * @return array<string,string> placeholder → valore non escapato
     */
    private function previewPathVars(string $mainPath, array $files): array
    {
        $variant = preg_match('/main_([A-Z]+)\.tex$/', $mainPath, $m) ? $m[1] : 'NOR';

        // Griglia: preferisci sc_MAT (default storico), altrimenti la prima del
        // bundle. Se non ce n'è nessuna ci pensa addPreviewStubs().
        $griglie = [];
        foreach ($files as $f) {
            if (preg_match('#(?:^|/)griglie/([A-Za-z0-9]+)_([A-Za-z0-9]+)\.tex$#', $f['path'], $g)) {
                $griglie[$g[1] . '_' . $g[2]] = [$g[1], $g[2]];
            }
        }
        [$ind, $mat] = $griglie['sc_MAT'] ?? ($griglie === [] ? ['sc', 'MAT'] : reset($griglie));

        $prefixes = PlaceholderResolver::pathPrefixes(BuildResult::MODE_ZIP);

        return [
            '{{TEXCOMMON_DIR}}'  => $prefixes['TEXCOMMON_DIR'],
            '{{GRIGLIE_DIR}}'    => $prefixes['GRIGLIE_DIR'],
            '{{ESERCIZI_FILE}}'  => 'esercizi_' . $variant,
            '{{INDIRIZZO_CODE}}' => $ind,
            '{{MATERIA_CODE}}'   => $mat,
            '{{GRIGLIA_SUFFIX}}' => '',
            // compensazione_orale.tex è nel bundle → riga attiva in anteprima.
            '{{COMPENSA_OPEN}}'  => '',
            '{{COMPENSA_CLOSE}}' => '',
            '{{COPIE_NOR}}'      => '1',
            '{{COPIE_SOL}}'      => '1',
            '{{COPIE_DSA}}'      => '1',
            '{{COPIE_DIS}}'      => '1',
        ];
    }

    /**
     * G22.17 — stub dei file che TexBuilder genera a save time e che nell'albero
     * dei modelli non esistono (`tikz_preamble`, `esercizi_*`, `fonti_SOL`).
     * Servono perché `-interaction=nonstopmode` NON recupera su un `\input`
     * mancante: si ferma con "Emergency stop" e non produce PDF.
     *
     * @param list<array{path:string,content:string}> $files
     * @param array<string,string>                    $pathVars da previewPathVars()
     * @return list<array{path:string,content:string}>
     */
    private function addPreviewStubs(array $files, string $mainPath, array $pathVars): array
    {
        $variant = preg_match('/main_([A-Z]+)\.tex$/', $mainPath, $m) ? $m[1] : 'NOR';
        $dir     = dirname($mainPath);
        $dir     = ($dir === '.' || $dir === '') ? '' : rtrim($dir, '/') . '/';

        $esercizi = <<<'TEX'
            \section*{Esercizi}
            \begin{enumerate}
              \item Corpo di esempio: in anteprima gli esercizi reali non esistono ancora.
            \end{enumerate}
            TEX;

        $stubs = [
            $dir . 'tikz_preamble.tex'              => "% anteprima modelli: nessuna macro TikZ da hoistare\n",
            $dir . 'esercizi_' . $variant . '.tex'  => $esercizi . "\n",
            'griglie/' . ($pathVars['{{INDIRIZZO_CODE}}'] ?? 'sc') . '_'
                . ($pathVars['{{MATERIA_CODE}}'] ?? 'MAT') . '.tex' => "% anteprima modelli: griglia non disponibile\n",
        ];
        if ($variant === 'SOL') {
            $stubs[$dir . 'fonti_SOL.tex'] = "% anteprima modelli: nessuna fonte\n";
        }

        $have = array_column($files, 'path');
        foreach ($stubs as $p => $body) {
            if (!in_array($p, $have, true)) {
                $files[] = ['path' => $p, 'content' => $body];
            }
        }
        return $files;
    }

    /**
     * G21.3 — Costruisce un .tex standalone compilabile dal frammento.
     *
     * Logica:
     *   - Se contiene \documentclass → as-is (è file completo come main_*.tex)
     *   - Altrimenti wrap con preambolo esteso + body opzionale
     */
    private function buildPreviewTex(string $path, string $content): string
    {
        if (str_contains($content, '\\documentclass')) {
            return $content;
        }

        $title = 'Anteprima — ' . basename($path);

        // G22.S15.bis Fase 5 — file .sty (style/package): il contenuto va
        // in PREAMBOLO non nel body, altrimenti pdflatex stampa i nomi dei
        // pacchetti come testo (es. "wrapfig geometry enumitem...").
        // Strip directives meta valide solo dentro un .sty file vero
        // (\NeedsTeXFormat, \ProvidesPackage, \endinput finale) che danno
        // errore se messe dentro un wrapper article.
        $isStyle = str_ends_with(strtolower($path), '.sty')
                || preg_match('/\\\\(?:NeedsTeXFormat|ProvidesPackage)\b/', $content);

        if ($isStyle) {
            $stripped = preg_replace([
                '/\\\\NeedsTeXFormat\{[^}]*\}(?:\[[^\]]*\])?\s*/',
                '/\\\\ProvidesPackage\{[^}]*\}(?:\[[^\]]*\])?\s*/',
                '/\\\\endinput\b\s*$/',
            ], '', $content);
            return "\\documentclass[a4paper,12pt]{article}\n"
                 . "\\title{" . self::escTexTitle($title) . "}\n"
                 . "\\makeatletter\n"
                 . $stripped . "\n"
                 . "\\makeatother\n"
                 . "\\begin{document}\n"
                 . "\\section*{Anteprima pacchetto: \\texttt{" . self::escTexTitle(basename($path)) . "}}\n"
                 . "Pacchetto caricato senza errori. Comandi e ambienti definiti sono ora disponibili.\n\n"
                 . "\\smallskip\n"
                 . "\\textit{Per vedere l'effetto del pacchetto in un documento completo, "
                 . "apri \\texttt{versioni/main\\_NOR.tex} o un'altra variante.}\n"
                 . "\\end{document}\n";
        }

        $preamble = VerificaTemplateStandard::extendedPreamble($title);

        // G22.15 — NIENTE "Esempio corpo verifica": il placeholder riempiva
        // la prima pagina della preview e nascondeva il contenuto vero
        // (griglie/criteri/footer). Il frammento si compila direttamente
        // dopo il preamble: quello che vedi è quello che salvi.
        return $preamble . "\n" . $content . "\n\n\\end{document}\n";
    }

    /** Escape per uso dentro \title{...} / \texttt{...}: protegge _ \ # & $ % { } ~ ^. */
    private static function escTexTitle(string $s): string
    {
        return strtr($s, [
            '\\' => '\\textbackslash{}',
            '_'  => '\\_',
            '#'  => '\\#',
            '&'  => '\\&',
            '$'  => '\\$',
            '%'  => '\\%',
            '{'  => '\\{',
            '}'  => '\\}',
            '~'  => '\\textasciitilde{}',
            '^'  => '\\textasciicircum{}',
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

    public function copyFromBase(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $path   = (string)($payload['path'] ?? '');
        $hint   = trim((string)($payload['institute'] ?? ''));
        $instituteCode = $this->resolveInstituteCode($tid, $hint !== '' ? $hint : null);
        try {
            // Prefer institute → default
            $content = $instituteCode
                ? (TemplateFileStore::readRaw($instituteCode, $path) ?? TemplateFileStore::read(TemplateFileStore::SCOPE_DEFAULT, $path))
                : TemplateFileStore::read(TemplateFileStore::SCOPE_DEFAULT, $path);
            if ($content === null) {
                return Response::json(['ok' => false, 'error' => 'base_missing'], 404);
            }
            TemplateFileStore::write(TemplateFileStore::teacherScope($tid), $path, $content);
            return Response::json(['ok' => true, 'bytes' => strlen($content)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    private function teacherId(): int
    {
        if (!Auth::check()) {
            return 0;
        }
        $u = Auth::user();
        return (int)($u['id'] ?? 0);
    }

    /**
     * Risolve il codice istituto attivo del docente.
     * G20.7 — se `$hint` (es. `XXPS00000A`) e' presente E il docente ha un
     * link teacher_institutes su quel codice, lo restituisce. Altrimenti
     * fallback al primo istituto del docente (created_at asc).
     */
    private function resolveInstituteCode(int $teacherId, ?string $hint = null): ?string
    {
        if ($hint !== null && $hint !== '') {
            $stmt = Database::connection()->prepare(
                'SELECT i.code FROM teacher_institutes ti
                 JOIN institutes i ON i.id = ti.institute_id
                 WHERE ti.user_id = ? AND i.code = ? LIMIT 1'
            );
            $stmt->execute([$teacherId, $hint]);
            $code = $stmt->fetchColumn();
            if (is_string($code) && $code !== '') {
                return $code;
            }
        }
        $stmt = Database::connection()->prepare(
            'SELECT i.code FROM teacher_institutes ti
             JOIN institutes i ON i.id = ti.institute_id
             WHERE ti.user_id = ? ORDER BY ti.created_at LIMIT 1'
        );
        $stmt->execute([$teacherId]);
        $code = $stmt->fetchColumn();
        return is_string($code) && $code !== '' ? $code : null;
    }
}
