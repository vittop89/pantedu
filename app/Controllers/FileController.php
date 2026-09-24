<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\FileService;
use App\Support\Validator;
use Throwable;

/**
 * HTTP facade for FileService. Each endpoint validates input, then
 * delegates to the service which enforces path + extension + size
 * guards. Legacy responses (plain text) are preserved where callers
 * expect them; new endpoints return JSON.
 */
final class FileController
{
    public function __construct(private ?FileService $files = null)
    {
        $this->files ??= new FileService();
    }

    /** POST /files/save-latex — fileName, fileContent; targets verifiche/temp */
    public function saveLatex(Request $req): Response
    {
        try {
            $v       = new Validator($req->post);
            $name    = $v->filename('fileName', ['tex'], 120);
            $content = $v->string('fileContent', max: 5 * 1024 * 1024);
            $path    = $this->files->save('verifiche_temp', $name, $content, 'tex');
            return Response::json(['ok' => true, 'path' => $this->publicize($path)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST /files/delete-folder — folderPath (absolute-ish from webroot) */
    public function deleteFolder(Request $req): Response
    {
        try {
            $v          = new Validator($req->post);
            $folderPath = $v->webPath('folderPath');
            [$label, $rel] = $this->splitWebrootPath($folderPath);
            $ok = $this->files->deleteFolder($label, $rel);
            return Response::json(['ok' => $ok]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST /files/delete — filePath */
    public function deleteFile(Request $req): Response
    {
        try {
            $v        = new Validator($req->post);
            $filePath = $v->webPath('filePath');
            [$label, $rel] = $this->splitWebrootPath($filePath);
            $ok = $this->files->delete($label, $rel);
            return Response::json(['ok' => $ok]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** GET /files/list?directory=... */
    public function list(Request $req): Response
    {
        try {
            $v   = new Validator($req->query);
            $dir = $v->webPath('directory');
            [$label, $rel] = $this->splitWebrootPath($dir);
            return Response::json($this->files->listDirectory($label, $rel));
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /files/clear-temp — svuota /temp e /verifiche/temp.
     *
     * Solo POST dal 23/9/2026 (A-77): il gestore non guarda il verbo, e con
     * `any()` una GET arrivava qui senza gettone CSRF.
     */
    public function clearTemp(Request $req): Response
    {
        $removed = $this->files->clearRootContents('temp')
                 + $this->files->clearRootContents('verifiche_temp');
        return Response::json(['ok' => true, 'removed' => $removed]);
    }

    /**
     * POST /save_pdf_file.php — body JSON: salva PDF compilato sotto
     * verifiche/tex_pdf/{iis}/{iis+cls}/{materia}/{verTitle}/{versionFolder}/.
     */
    public function savePdf(Request $req): Response
    {
        try {
            $data = $req->json();
            // Validator applicato a ogni campo input — niente raw POST path.
            $slug = '/^[A-Za-z0-9_\-]{1,32}$/';
            $iis = (string)($data['selectedIIS']   ?? '');
            $cls = (string)($data['selectedCLS']   ?? '');
            $mat = (string)($data['selectedMATER'] ?? '');
            if (!preg_match($slug, $iis)) {
                return Response::json(['success' => false, 'error' => 'invalid_iis'], 400);
            }
            if (!preg_match($slug, $cls)) {
                return Response::json(['success' => false, 'error' => 'invalid_cls'], 400);
            }
            if (!preg_match($slug, $mat)) {
                return Response::json(['success' => false, 'error' => 'invalid_mat'], 400);
            }
            $verTitle      = (string)($data['verTitle']      ?? '');
            $versionFolder = (string)($data['versionFolder'] ?? '');
            // versionFolder opzionale — ora esplicitamente validato.
            if ($versionFolder !== '' && !preg_match($slug, $versionFolder)) {
                return Response::json(['success' => false, 'error' => 'invalid_versionFolder'], 400);
            }
            $fileName = (string)($data['fileName'] ?? '');
            $pdfB64   = (string)($data['pdfContent'] ?? '');
            if ($verTitle === '' || $fileName === '' || $pdfB64 === '') {
                return Response::json(['success' => false, 'error' => 'missing_field'], 400);
            }
            // verTitle sanitizzato: solo alfanumerico + accentate
            $verTitleSafe = trim(str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9_\-àèéìòù ]/', '', $verTitle)), '_');
            if ($verTitleSafe === '') {
                return Response::json(['success' => false, 'error' => 'invalid_verTitle'], 400);
            }
            // fileName forzato a .pdf senza path components
            $fileNameSafe = basename(pathinfo($fileName, PATHINFO_FILENAME)) . '.pdf';
            if (!preg_match('/^[A-Za-z0-9_\-àèéìòù. ]{1,200}$/u', $fileNameSafe)) {
                return Response::json(['success' => false, 'error' => 'invalid_fileName'], 400);
            }

            $pdfContent = base64_decode($pdfB64, strict: true);
            if ($pdfContent === false) {
                return Response::json(['success' => false, 'error' => 'invalid_base64'], 400);
            }
            if (strlen($pdfContent) > 50 * 1024 * 1024) { // 50MB cap
                return Response::json(['success' => false, 'error' => 'pdf_too_large'], 413);
            }
            // MIME check: magic-bytes "%PDF-"
            try {
                \App\Support\MimeSniffer::assertAllowed($pdfContent, ['pdf']);
            } catch (\Throwable $e) {
                return Response::json(['success' => false, 'error' => 'mime_mismatch'], 400);
            }

            // Dati d'istanza (PANTEDU_DATA_PATH), non la radice del
            // repository: in produzione `/var/www/pantedu/verifiche` non
            // esiste nemmeno sull'host, e tutto quello che è passato di qui
            // dall'8/9/2026 è finito nello strato scrivibile del container.
            //
            // Sotto `storage/`, come ogni altra scrittura: `<dati>` è di root
            // e solo `storage/` è di www-data, nel salvataggio e nei controlli
            // d'avvio.
            $base = \App\Support\PercorsiDati::base(dirname(__DIR__, 2)) . '/storage/verifiche/tex_pdf';
            $rel  = $iis . '/' . ($iis . $cls) . '/' . $mat . '/' . $verTitleSafe
                  . ($versionFolder !== '' ? '/' . $versionFolder : '');
            $full = $base . '/' . $rel;
            if (!is_dir($full) && !mkdir($full, 0755, true) && !is_dir($full)) {
                return Response::json(['success' => false, 'error' => 'mkdir_failed'], 500);
            }
            $filePath = $full . '/' . $fileNameSafe;
            if (file_put_contents($filePath, $pdfContent, LOCK_EX) === false) {
                return Response::json(['success' => false, 'error' => 'write_failed'], 500);
            }
            return Response::json([
                'success'  => true,
                'filePath' => 'verifiche/tex_pdf/' . $rel . '/' . $fileNameSafe,
                'fileSize' => filesize($filePath),
            ]);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Legacy code supplies a path relative to the document root
     * (e.g. "/eser/ar/eser_ar5s/MAT/foo.tex"). Convert that to
     * (rootLabel, relative) using the FileService root map.
     *
     * @return array{0:string,1:string}
     */
    private function splitWebrootPath(string $webroot): array
    {
        $clean = ltrim(str_replace('\\', '/', $webroot), '/');
        $parts = explode('/', $clean, 2);
        $first = $parts[0];
        $rest  = $parts[1] ?? '';

        // L'INVERSO ESATTO di publicize(), e per primo: dal 20/9/2026 le
        // radici stanno sotto `storage/`, quindi il percorso che questa
        // funzione riceve è quello che publicize() ha appena restituito —
        // «/storage/verifiche/temp/x.tex», non più «/verifiche/temp/x.tex».
        // Guardando il solo primo segmento si sarebbe letto «storage», che
        // non è nessuna radice: salva → cancella rispondeva
        // `unknown_root_in_path`. Si prende il prefisso PIÙ LUNGO, altrimenti
        // `storage/temp` finirebbe dentro una radice che comincia uguale.
        $miglioreLabel = null;
        $migliorePrefisso = '';
        foreach ($this->radiciRelative() as $label => $prefisso) {
            if ($prefisso === '' || strlen($prefisso) <= strlen($migliorePrefisso)) {
                continue;
            }
            if ($clean === $prefisso || str_starts_with($clean, $prefisso . '/')) {
                $miglioreLabel = $label;
                $migliorePrefisso = $prefisso;
            }
        }
        if ($miglioreLabel !== null) {
            return [$miglioreLabel, ltrim(substr($clean, strlen($migliorePrefisso)), '/')];
        }

        // Two-segment roots (e.g. verifiche_temp = verifiche/temp)
        // Forma vecchia, di quando la radice era `<dati>/verifiche/temp`:
        // resta per i percorsi che un client ha ancora in mano.
        if ($first === 'verifiche' && str_starts_with($rest, 'temp')) {
            return ['verifiche_temp', ltrim(substr($rest, 4), '/')];
        }

        $roots = $this->files->roots();
        if (isset($roots[$first])) {
            return [$first, $rest];
        }

        // Fallbacks by first segment match
        $labelMap = [
            'img' => 'img', 'eser' => 'eser', 'verifiche' => 'verifiche',
            'risdoc' => 'risdoc', 'lab' => 'lab', 'didattica' => 'didattica',
            'mappe' => 'mappe', 'drafts' => 'drafts', 'tex_pdf' => 'tex_pdf',
            'strcomp_bes_altro' => 'strcomp', 'temp' => 'temp',
        ];
        $label = $labelMap[$first] ?? null;
        if ($label === null) {
            throw new \RuntimeException('unknown_root_in_path');
        }
        return [$label, $rest];
    }

    /**
     * Ogni radice come la vede publicize(): il percorso relativo alla
     * cartella dei dati o, per le radici versionate, a quella del codice.
     *
     * @return array<string,string> etichetta => prefisso senza barre ai lati
     */
    private function radiciRelative(): array
    {
        $basi = [
            \App\Support\PercorsiDati::base(dirname(__DIR__, 2)),
            rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/'),
        ];
        $fuori = [];
        foreach ($this->files->roots() as $label => $root) {
            $root = rtrim(str_replace('\\', '/', (string)$root), '/');
            foreach ($basi as $base) {
                if ($base !== '' && str_starts_with($root, $base . '/')) {
                    $fuori[$label] = ltrim(substr($root, strlen($base)), '/');
                    break;
                }
            }
        }

        return $fuori;
    }

    /**
     * Da percorso assoluto a percorso «da webroot».
     *
     * Le radici di scrittura sono passate ai dati d'istanza, che in
     * produzione stanno fuori dal repository: si toglie di davanti la radice
     * dei dati e, per i percorsi rimasti nel codice, quella del repository.
     * Senza il primo dei due ogni risposta avrebbe riportato un `basename()`
     * nudo al posto del percorso.
     */
    private function publicize(string $absolutePath): string
    {
        $p = str_replace('\\', '/', $absolutePath);
        $radici = [
            \App\Support\PercorsiDati::base(dirname(__DIR__, 2)),
            rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/'),
        ];
        foreach ($radici as $base) {
            if ($base !== '' && str_starts_with($p, $base . '/')) {
                return substr($p, strlen($base));
            }
        }
        return basename($p);
    }
}
