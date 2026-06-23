<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use Throwable;

/**
 * G22.S15.bis Fase 5+ — Helper privati condivisi tra VerificaController
 * (split: Compile/Sync/Batch). Estratti per evitare duplicate inheritance.
 *
 * Helper inclusi:
 *   - teacherId(): id docente loggato (throw se unauth/forbidden)
 *   - readJsonBody(): JSON body validato (max 2 MiB)
 *   - statusFor(Throwable): exception → HTTP status code
 *   - latexErrorExcerpt(string): estrae prima riga "! ..." dal log LaTeX
 *
 * Tutti private (non accessibili dal call-site esterno). Utilizzati
 * uniformemente tra controller verifica per coerenza errori HTTP.
 */
trait VerificaSharedHelpersTrait
{
    private function teacherId(): int
    {
        // G22.S15.bis Fase 5+ — delegate role check + username retrieval.
        $username = \App\Support\AuthHelpers::teacherUsernameOrThrow();
        // PROBLEM-1 fix: delega al resolver canonico invece di duplicare la query.
        $id = \App\Support\TeacherContextResolver::userIdFromUsername($username);
        if ($id <= 0) {
            throw new \RuntimeException('unauthenticated');
        }
        return $id;
    }

    /**
     * Carica il record completo dell'utente (first_name/last_name/email) per
     * costruire il template_context. Auth::user() ritorna solo username/role,
     * serve query DB per i campi anagrafici.
     */
    private function teacherRecord(int $teacherId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, username, email, first_name, last_name, role
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$teacherId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: [];
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!\is_string($raw) || $raw === '') {
            throw new \RuntimeException('empty_payload');
        }
        if (\strlen($raw) > 2 * 1024 * 1024) {
            throw new \RuntimeException('payload_too_large');
        }
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('invalid_json');
        }
        return $decoded;
    }

    /**
     * PROBLEM-13 — exhaustive exception → HTTP status mapping per tutti i
     * dominio errors di VerificaDocumentService + Verifica*Service.
     *
     * Categorie:
     *   - 401 unauthenticated
     *   - 403 forbidden, verifica_forbidden, tex_compile_disabled
     *   - 404 verifica_not_found, verifica_pdf_missing, verifica_manifest_empty
     *   - 413 verifica_*_too_large (TEX, PDF, file, svg)
     *   - 422 verifica_pdf_invalid, verifica_*_empty/_invalid/_required
     *   - 500 verifica_save_failed, svg_to_pdf_failed (fallback failure)
     *   - 400 default (validation/unknown)
     */
    private function statusFor(Throwable $e): int
    {
        $msg = $e->getMessage();
        return match (true) {
            $msg === 'unauthenticated' => 401,
            $msg === 'forbidden',
            $msg === 'verifica_forbidden',
            $msg === 'tex_compile_disabled' => 403,
            $msg === 'verifica_not_found',
            $msg === 'verifica_pdf_missing',
            $msg === 'verifica_manifest_empty' => 404,
            $msg === 'verifica_pdf_too_large',
            $msg === 'verifica_tex_too_large',
            $msg === 'verifica_file_too_large',
            $msg === 'svg_too_large' => 413,
            $msg === 'verifica_pdf_invalid',
            $msg === 'verifica_pdf_empty',
            $msg === 'verifica_tex_empty',
            $msg === 'verifica_files_empty',
            $msg === 'verifica_file_invalid',
            $msg === 'verifica_file_path_invalid',
            $msg === 'verifica_invalid_teacher',
            $msg === 'verifica_materia_required',
            $msg === 'verifica_title_required',
            $msg === 'verifica_no_variants_to_generate',
            $msg === 'svg_b64_invalid' => 422,
            $msg === 'verifica_save_failed',
            str_starts_with($msg, 'svg_to_pdf_failed') => 500,
            default => 400,
        };
    }

    /**
     * G21 — estrae la prima riga "! ..." dal log LaTeX (errore principale).
     * Restituisce stringa breve user-friendly per UI (toast/error banner).
     * Niente PII: il log LaTeX riguarda sintassi del .tex, non dati alunno.
     */
    private function latexErrorExcerpt(string $log, int $maxChars = 500): string
    {
        if ($log === '') {
            return '';
        }
        if (preg_match('/^(!\s*[^\n]+)/m', $log, $m)) {
            $line = trim($m[1]);
            return mb_strlen($line) > $maxChars
                ? mb_substr($line, 0, $maxChars) . '…'
                : $line;
        }
        return mb_substr($log, -$maxChars);
    }

    /**
     * G27.tikz.warn — estrae dal log pdflatex i warning critici che indicano
     * un PDF "compilato ma incompleto":
     *   - Undefined control sequence \X     → macro mancante (TikZ template
     *     senza preamble hoistato → figure non disegnate ma compile success)
     *   - Missing \begin{document}, Missing $ inserted, Runaway argument
     *     → strutturali, di solito fatali ma a volte producono PDF parziale
     *
     * Ritorna lista dedupata `[{type, message}]` per `warnings[]` nella
     * response API. Limite 10 entry per evitare flood UI. Vuoto se compile
     * "pulita".
     *
     * @return list<array{type:string,message:string}>
     */
    private function extractCompileWarnings(string $log): array
    {
        if ($log === '') {
            return [];
        }
        $warnings = [];
        $seen = [];
        // 1) Undefined control sequence — pattern multilinea TeX:
        //    "! Undefined control sequence.\nl.NN ...\n              \SetPoints{...}"
        if (
            preg_match_all(
                '/!\s*Undefined control sequence\.\s*\n(?:l\.\d+[^\n]*\n)?\s*(?:[^\n]*\n)?\s*\\\\(\w+)/',
                $log,
                $m
            )
        ) {
            foreach ($m[1] as $macro) {
                $macroFull = '\\' . $macro;
                $key = 'undef:' . $macroFull;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $warnings[] = [
                    'type'    => 'undefined_macro',
                    'message' => "Macro '$macroFull' non definita. "
                               . "Se proviene da un template TikZ con \\newcommand custom, "
                               . "verifica che il preamble sia stato hoistato (G27.tikz.hoist).",
                ];
                if (count($warnings) >= 10) {
                    return $warnings;
                }
            }
        }
        // 2) Generic structural fallbacks
        $patterns = [
            'missing_begin_document' => '/!\s*Missing \\\\begin\{document\}/',
            'missing_dollar'         => '/!\s*Missing \$ inserted/',
            'runaway_argument'       => '/!\s*Runaway argument/',
            'extra_alignment_tab'    => '/!\s*Extra alignment tab/',
        ];
        foreach ($patterns as $type => $rx) {
            if (preg_match($rx, $log) && !isset($seen[$type])) {
                $seen[$type] = true;
                $warnings[] = [
                    'type'    => $type,
                    'message' => trim(str_replace('_', ' ', $type)) . ' rilevato nel log compile.',
                ];
                if (count($warnings) >= 10) {
                    return $warnings;
                }
            }
        }
        return $warnings;
    }

    /**
     * G19.49 — risolve istituto code via primo teacher_institutes.
     * G22.S15.bis Fase 5+ — delegate a TeacherContextResolver canonico.
     */
    private function resolveInstituteCodeForTeacher(int $teacherId): string
    {
        return \App\Support\TeacherContextResolver::instituteCodeForTeacher($teacherId);
    }

    /**
     * G19 — slug per filename: lowercase, ASCII, alphanumerico + `_-`.
     * Static perche' chiamato da buildBatchFilename (anch'esso static).
     */
    private static function slugifyForFilename(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        $s = (string)preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = trim((string)preg_replace('/_+/', '_', $s), '_');
        return $s;
    }

    /**
     * G19 — filename verifica per Content-Disposition / UI download.
     * Pattern: `{materia}-{slug}-{verToken}-{variant}-stampe.tex` per varianti
     * batch, oppure `verifica_{id}.tex` per legacy single.
     */
    private static function buildBatchFilename(array $doc, string $variant): string
    {
        if ($variant === '') {
            return 'verifica_' . (int)$doc['id'] . '.tex';
        }
        $materia = strtolower((string)($doc['materia'] ?? 'mat'));
        $materia = self::slugifyForFilename($materia) ?: 'mat';

        $title = (string)($doc['title'] ?? '');
        $title = (string)preg_replace('/\s*[—-]\s*[AB]_(SOL|NOR|DSA|DIS)\s*$/u', '', $title);
        $slug  = self::slugifyForFilename($title);
        if ($slug === '') {
            $slug = 'verifica_' . (int)$doc['id'];
        }
        if (\strlen($slug) > 24) {
            $slug = substr($slug, 0, 24);
        }

        if (!preg_match('/^([AB])_(SOL|NOR|DSA|DIS)$/', $variant, $m)) {
            return 'verifica_' . (int)$doc['id'] . '.tex';
        }
        $verLetter = $m[1];
        $variantUp = $m[2];
        $verToken = $verLetter === 'A' ? '_' : 'rec';
        if ($variantUp === 'SOL') {
            return "{$materia}-{$slug}-{$verToken}-SOL.tex";
        }
        return "{$materia}-{$slug}-{$verToken}-{$variantUp}-stampe.tex";
    }

    /**
     * Formatta doc verifica per JSON response (publicView): selezione campi
     * pubblici + URL canonici. Centralizzato per uniformita' API.
     */
    private function publicView(array $doc): array
    {
        $variant = (string)($doc['variant'] ?? '');
        $texFilename = $variant !== ''
            ? self::buildBatchFilename($doc, $variant)
            : 'verifica_' . (int)$doc['id'] . '.tex';
        return [
            'id'             => $doc['id'],
            'materia'        => $doc['materia'],
            'indirizzo'      => $doc['indirizzo'] ?? null,
            'classe'         => $doc['classe'] ?? null,
            'title'          => $doc['title'],
            'variant'        => $variant,
            'batch_id'       => (string)($doc['batch_id'] ?? ''),
            'version_label'  => (string)($doc['version_label'] ?? ''),
            'fm_db_section'  => $doc['fm_db_section'],
            'tex_size'       => $doc['tex_size'],
            'has_pdf'        => !empty($doc['pdf_blob_path']),
            'pdf_filename'   => $doc['pdf_filename'] ?? null,
            'pdf_size'       => $doc['pdf_size'] ?? null,
            'pdf_uploaded_at' => $doc['pdf_uploaded_at'] ?? null,
            'created_at'     => $doc['created_at'],
            'updated_at'     => $doc['updated_at'],
            'exercise_ids'   => $doc['exercise_ids'],
            'tex_url'        => '/api/verifica/' . $doc['id'] . '/tex',
            'tex_filename'   => $texFilename,
            'pdf_url'        => !empty($doc['pdf_blob_path']) ? '/api/verifica/' . $doc['id'] . '/pdf' : null,
            // G22.S23 — toggle pool sharing (visibilità ai colleghi)
            'shared_with_pool' => !empty($doc['shared_with_pool']),
        ];
    }
}
