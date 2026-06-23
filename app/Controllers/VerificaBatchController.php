<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\TexBuilder;
use App\Services\TexBuilder\Selection;
use App\Services\TexBuilder\VersionPicker;
use App\Services\Verifica\VerificaDocumentService;
use Throwable;

/**
 * G22.S15.bis Fase 5+ — Split di VerificaController (era 969 righe).
 *
 * Responsabilita': bundle multi-file di un batch verifica per export
 * verso File System Access API (VSC) o ZIP download.
 *
 * Endpoint:
 *   GET /api/verifica/batch/{batchId}/files → bundle multi-file (FS Access)
 *   GET /api/verifica/batch/{batchId}/zip   → ZIP download fallback
 *
 * Helper privati condivisi via VerificaSharedHelpersTrait
 * (teacherId, teacherRecord, statusFor, resolveInstituteCodeForTeacher,
 *  buildBatchFilename, slugifyForFilename, ...).
 */
final class VerificaBatchController
{
    use VerificaSharedHelpersTrait;

    private VerificaDocumentService $svc;
    private TexBuilder $tex;

    public function __construct(
        ?VerificaDocumentService $svc = null,
        ?TexBuilder $tex = null
    ) {
        $this->svc = $svc ?? new VerificaDocumentService();
        $this->tex = $tex ?? new TexBuilder();
    }

    /**
     * G19.36 — GET /api/verifica/batch/{batchId}/files
     *
     * Ritorna i file `.tex` del batch come JSON `{files: [{name, content}]}`.
     * Usato da `doVsc` (client) per scrivere i file in una cartella picked
     * tramite File System Access API (`showDirectoryPicker`), evitando il
     * download di uno ZIP che l'utente dovrebbe estrarre manualmente.
     *
     * Owner only (stesso check di batchZip).
     */
    /**
     * G20.0 — GET /api/verifica/batch/{batchId}/files
     * Bundle multi-file VSC (modalita' distribuita) per scrittura lato
     * frontend via FS Access API.
     *
     * Response: `{ok, batch_id, files: [{path, content, type}], dist}`
     * dove `path` e' relativo al institute root del docente:
     *   - texCommon/...
     *   - {ind}/griglie/{ind}_{materia}.tex
     *   - {ind}/{cls}/{materia}/verifiche/{titleSlug}/{versionFolder}/main_*.tex
     *   - {ind}/{cls}/{materia}/verifiche/{titleSlug}/{versionFolder}/esercizi_*.tex
     *
     * Il client (topbar-modern.js::writeBatchToFolder) preprend
     * institute_code prefix lato fs root.
     */
    public function batchFiles(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $batchId = (string)($params['batchId'] ?? '');
            if (!preg_match('/^[0-9A-Z]{26}$/', $batchId)) {
                throw new \RuntimeException('verifica_batch_invalid_id');
            }
            $repo = new \App\Repositories\VerificaDocumentRepository();
            $docs = $repo->listForBatch($teacherId, $batchId);
            if (!$docs) {
                return Response::json(['ok' => false, 'error' => 'verifica_batch_empty'], 404);
            }
            $instCode = $this->resolveInstituteCodeForTeacher($teacherId);
            $bundle   = $this->buildVscBundle($teacherId, $docs, $instCode, $batchId);

            return Response::json([
                'ok'             => true,
                'batch_id'       => $batchId,
                'institute_code' => $instCode,
                'files'          => $bundle['files'],
            ], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * G20.0 — Costruisce bundle multi-file per modalita' VSC.
     *
     * Files emessi (path relativi al institute root):
     *   texCommon/...                                     [shared]
     *   {ind}/griglie/{ind}_{materia}.tex                 [per indirizzo]
     *   {ind}/{cls}/{materia}/verifiche/{titleSlug}/{versionFolder}/main_*.tex
     *   {ind}/{cls}/{materia}/verifiche/{titleSlug}/{versionFolder}/esercizi_*.tex
     *
     * Dedup: i file shared (texCommon, griglie) sono scritti una volta.
     */
    private function buildVscBundle(int $teacherId, array $docs, string $instCode, string $batchId): array
    {
        $teacher = $this->teacherRecord($teacherId);
        $instituteName = $this->resolveInstituteNameForTeacher($teacherId);
        $builder = new \App\Services\TexBuilder();
        $files = [];
        $seen  = [];

        // Pre-aggregate kinds per version folder name
        $kindsSet = [];
        foreach ($docs as $doc) {
            $variantKey = (string)($doc['variant'] ?? '');
            if (preg_match('/(SOL|NOR|DSA|DIS)$/', $variantKey, $m)) {
                $kindsSet[$m[1]] = true;
            }
        }
        $kindsOrdered = [];
        foreach (['SOL', 'NOR', 'DSA', 'DIS'] as $k) {
            if (isset($kindsSet[$k])) {
                $kindsOrdered[] = $k;
            }
        }
        $kindsStr = implode('_', $kindsOrdered);

        foreach ($docs as $doc) {
            $variantKey = (string)($doc['variant'] ?? '');
            if (!preg_match('/^[AB]_(SOL|NOR|DSA|DIS)$/', $variantKey, $m)) {
                continue;
            }
            $kind = $m[1];

            $sel = $this->selectionFromDoc($doc);
            if (!$sel) {
                continue;
            }
            $sel->options['includeSolutions'] = ($kind === 'SOL');

            $build = $builder->build($sel, $this->kindToVariant($kind), [
                'mode'           => \App\Services\TexBuilder\BuildResult::MODE_VSC,
                'variant_kind'   => $kind,
                'institute_code' => $instCode,
                'institute_name' => $instituteName,
                'docente_nome'   => $this->formatTeacherName($teacher),
                'tempo_minuti'   => 55,
                'copie'          => ['NOR' => 1, 'DSA' => 0, 'DIS' => 0],
            ]);

            // Calcola path distribuiti
            $ind = (string)$sel->iis;
            $cls = (string)$sel->cls;
            $mat = (string)$sel->mater;
            $titleClean = preg_replace('/\s*[—-]\s*[AB]_(SOL|NOR|DSA|DIS)\s*$/u', '', (string)$doc['title']) ?: 'verifica';
            $titleSlug = self::slugifyForFilename($titleClean) ?: 'verifica';
            $versionLabel = (string)($doc['version_label'] ?? '');
            if ($versionLabel === '') {
                $versionLabel = 'v0';
            }

            $createdAt = (string)($doc['created_at'] ?? '');
            $ts = $createdAt !== '' ? strtotime($createdAt) : false;
            if ($ts === false || $ts === 0) {
                $ts = time();
            }
            $dateStr = date('d_m_Y', $ts);
            $versionFolder = "$versionLabel-$dateStr-$kindsStr";

            $verDir = "$ind/$cls/$mat/verifiche/$titleSlug/$versionFolder";
            $grigliaDir = "$ind/griglie";

            foreach ($build->files as $f) {
                $src = $f['path'];
                // Riassegna path source → distribuito
                if (str_starts_with($src, 'texCommon/')) {
                    $dst = $src; // shared a institute root
                } elseif (preg_match('#^griglie/(.+)$#', $src, $g)) {
                    $dst = "$grigliaDir/" . $g[1];
                } elseif (preg_match('#^versioni/(main_|esercizi_)(NOR|SOL|DSA|DIS)\.tex$#', $src)) {
                    $dst = "$verDir/" . basename($src);
                } else {
                    $dst = $src;
                }
                if (isset($seen[$dst])) {
                    continue;
                }
                $seen[$dst] = true;
                $files[] = [
                    'path'    => $dst,
                    'content' => $f['content'],
                    'type'    => self::detectFileType($dst),
                    'size'    => strlen($f['content']),
                ];
            }
        }

        return ['files' => $files];
    }

    private static function detectFileType(string $path): string
    {
        if (str_starts_with($path, 'texCommon/')) {
            return 'texCommon';
        }
        if (str_contains($path, '/griglie/')) {
            return 'griglia';
        }
        if (str_contains($path, '/main_')) {
            return 'main';
        }
        if (str_contains($path, '/esercizi_')) {
            return 'esercizi';
        }
        return 'other';
    }

    /**
     * G20.0 — GET /api/verifica/batch/{batchId}/zip
     * Bundle ZIP multi-file (texCommon/ + griglie/ + versioni/main_*.tex +
     * versioni/esercizi_*.tex + README.txt). Layout autocontenuto.
     *
     * Regenera il bundle on-the-fly da `verifica_documents.selection_json`
     * usando il nuovo TexBuilder.build(BuildResult::MODE_ZIP).
     */
    public function batchZip(Request $req, array $params): Response
    {
        try {
            $teacherId = $this->teacherId();
            $batchId = (string)($params['batchId'] ?? '');
            if (!preg_match('/^[0-9A-Z]{26}$/', $batchId)) {
                throw new \RuntimeException('verifica_batch_invalid_id');
            }
            $repo = new \App\Repositories\VerificaDocumentRepository();
            $docs = $repo->listForBatch($teacherId, $batchId);
            if (!$docs) {
                return Response::json(['ok' => false, 'error' => 'verifica_batch_empty'], 404);
            }

            $instCode = $this->resolveInstituteCodeForTeacher($teacherId);
            $bundle   = $this->buildZipBundle($teacherId, $docs, $instCode, $batchId);

            $tmp = tempnam(sys_get_temp_dir(), 'fmvbz_');
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
                @unlink($tmp);
                throw new \RuntimeException('verifica_zip_open_failed');
            }
            foreach ($bundle['files'] as $f) {
                $zip->addFromString($f['path'], $f['content']);
            }
            $zip->close();

            $body = (string)file_get_contents($tmp);
            @unlink($tmp);

            $title = (string)($docs[0]['title'] ?? 'verifica');
            $titleClean = preg_replace('/\s*[—-]\s*[AB]_(SOL|NOR|DSA|DIS)\s*$/u', '', $title) ?: $title;
            $titleSlug = self::slugifyForFilename($titleClean) ?: 'verifica';

            return new Response(
                body: $body,
                status: 200,
                headers: [
                    'Content-Type'        => 'application/zip',
                    'Content-Disposition' => 'attachment; filename="' . $titleSlug . '_' . $batchId . '.zip"',
                    'Content-Length'      => (string)\strlen($body),
                    'Cache-Control'       => 'private, no-store',
                ],
            );
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * G20.0 — Costruisce il bundle multi-file (mode ZIP).
     * Files emessi:
     *   texCommon/verifica.sty
     *   texCommon/intestazione.tex
     *   texCommon/ulteriori_misure.tex
     *   texCommon/BES_DSA/{misure_dispensative,compensazione_orale}.tex
     *   griglie/{ind}_{materia}.tex
     *   versioni/main_{NOR,SOL,DSA,DIS}.tex
     *   versioni/esercizi_{NOR,SOL,DSA,DIS}.tex
     *   README.txt
     *
     * Dedup: i file texCommon/griglie sono shared tra varianti, scritti
     * una volta sola.
     *
     * @return array{files: list<array{path:string,content:string}>}
     */
    private function buildZipBundle(int $teacherId, array $docs, string $instCode, string $batchId): array
    {
        $teacher = $this->teacherRecord($teacherId);  // existing private method
        $instituteName = $this->resolveInstituteNameForTeacher($teacherId);
        $builder = new \App\Services\TexBuilder();
        $files = [];
        $seen = [];

        $title = '';
        foreach ($docs as $doc) {
            $variantKey = (string)($doc['variant'] ?? '');
            if (!preg_match('/^[AB]_(SOL|NOR|DSA|DIS)$/', $variantKey, $m)) {
                continue;
            }
            $kind = $m[1];

            $sel = $this->selectionFromDoc($doc);
            if (!$sel) {
                continue;
            }
            if ($title === '') {
                $title = (string)$sel->verTitle;
            }

            $sel->options['includeSolutions'] = ($kind === 'SOL');

            $build = $builder->build($sel, $this->kindToVariant($kind), [
                'mode'           => \App\Services\TexBuilder\BuildResult::MODE_ZIP,
                'variant_kind'   => $kind,
                'institute_code' => $instCode,
                'institute_name' => $instituteName,
                'docente_nome'   => $this->formatTeacherName($teacher),
                'tempo_minuti'   => 55,
                'copie'          => [
                    'NOR' => 1, 'DSA' => 0, 'DIS' => 0,
                ],
            ]);

            foreach ($build->files as $f) {
                if (isset($seen[$f['path']])) {
                    continue; // dedup texCommon/griglie
                }
                $seen[$f['path']] = true;
                $files[] = $f;
            }
        }

        $files[] = [
            'path' => 'README.txt',
            'content' => self::buildZipReadme($batchId, $title, count($docs), $instituteName),
        ];

        return ['files' => $files];
    }

    /** Reverse di TexBuilder::variantToKind */
    private function kindToVariant(string $kind): string
    {
        return match ($kind) {
            'DSA' => \App\Services\TexBuilder\VersionPicker::DSA,
            'DIS' => \App\Services\TexBuilder\VersionPicker::DYSLEXIC,
            default => \App\Services\TexBuilder\VersionPicker::NORMAL,
        };
    }

    /** Ricostruisce Selection da `verifica_documents.selection_json`. */
    private function selectionFromDoc(array $doc): ?\App\Services\TexBuilder\Selection
    {
        $json = (string)($doc['selection_json'] ?? '');
        if ($json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        // selection_json shape: {verTitle, iis, cls, mater, anno, sezione,
        //                       problems, options, context}
        // Selection::fromArray richiede: version, verTitle, selectedIIS,
        // selectedCLS, selectedMATER, anno, sezione, problems, options.
        try {
            return \App\Services\TexBuilder\Selection::fromArray([
                'version'       => $data['options']['version'] ?? 'A',
                'verTitle'      => $data['verTitle'] ?? '',
                'selectedIIS'   => $data['iis'] ?? '',
                'selectedCLS'   => $data['cls'] ?? '',
                'selectedMATER' => $data['mater'] ?? '',
                'anno'          => $data['anno'] ?? (string)date('Y'),
                'sezione'       => $data['sezione'] ?? '',
                'problems'      => $data['problems'] ?? [],
                'options'       => $data['options'] ?? [],
            ]);
        } catch (\Throwable $e) {
            error_log('selectionFromDoc parse fail: ' . $e->getMessage());
            return null;
        }
    }

    private function resolveInstituteNameForTeacher(int $teacherId): string
    {
        // G20.0 — header_label con fallback name
        $stmt = \App\Core\Database::connection()->prepare(
            'SELECT COALESCE(NULLIF(i.header_label, ""), i.name) AS label
             FROM teacher_institutes ti
             JOIN institutes i ON i.id = ti.institute_id
             WHERE ti.user_id = ? ORDER BY ti.created_at LIMIT 1'
        );
        $stmt->execute([$teacherId]);
        $name = $stmt->fetchColumn();
        return is_string($name) && $name !== '' ? $name : '';
    }

    private function formatTeacherName(array $teacher): string
    {
        if (!$teacher) {
            return '';
        }
        $first = trim((string)($teacher['first_name'] ?? ''));
        $last  = trim((string)($teacher['last_name']  ?? ''));
        return trim("$first $last") ?: (string)($teacher['username'] ?? '');
    }

    private static function buildZipReadme(string $batchId, string $title, int $variantCount, string $instituteName): string
    {
        return "Verifica: $title\n"
             . "Istituto: $instituteName\n"
             . "Batch ID: $batchId\n"
             . "Generato: " . date('Y-m-d H:i:s') . "\n"
             . "Numero varianti: $variantCount\n"
             . "\n"
             . "Struttura:\n"
             . "  texCommon/         file LaTeX comuni (preambolo, intestazione, BES/DSA)\n"
             . "  griglie/           griglia di valutazione per indirizzo+materia\n"
             . "  versioni/          main_*.tex per variante + esercizi_*.tex (corpo)\n"
             . "\n"
             . "Per compilare: cd versioni && pdflatex main_NOR.tex (o main_SOL/DSA/DIS)\n";
    }
}
