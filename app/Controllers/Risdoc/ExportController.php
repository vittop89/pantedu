<?php

declare(strict_types=1);

namespace App\Controllers\Risdoc;

use App\Core\Request;
use App\Core\Response;
use App\Services\Risdoc\Permission;
use App\Services\Risdoc\TemplateResolver;

/**
 * Export TeX per risdoc per-teacher (Phase 21, U9).
 *
 * POST /api/risdoc/templates/{id}/export
 *   body: form_state (JSON)
 *
 * Flusso:
 *   1. Genera il corpo TeX (`buildFiles()`): dal `body_pt` dell'editor con
 *      PtToTex, altrimenti dallo schema del modello con TexBuilder.
 *   2. Carica main.tex, intestaLAteX_IIS.tex e risdoc.sty con gli override
 *      del docente. (Il ripiego sui `.tex` legacy per categoria,
 *      `processLegacyTex()`, non aveva chiamanti dal 24/4/2026 ed è stato
 *      tolto il 23/9/2026: A-22.)
 *   3. Costruisce lo ZIP e lo CONSEGNA NEL CORPO della risposta
 *      (`App\Support\PacchettoZip`): niente file su disco, niente indirizzo
 *      da cui riscaricarlo, niente cartella da sorvegliare.
 */
final class ExportController
{
    public function __construct(private TemplateResolver $resolver = new TemplateResolver())
    {
    }

    // 21/9/2026 — qui c'era `serve()`, che consegnava un pacchetto già scritto
    // su disco a chi ne indovinava il nome, controllando soltanto che chi
    // chiedeva fosse UN docente e non IL proprietario del pacchetto. Non serve
    // più: il pacchetto esce nel corpo della risposta a chi l'ha chiesto, e su
    // disco non resta niente da servire.

    public function export(Request $req, array $params): Response
    {
        $id  = (int)($params['id'] ?? 0);
        $tid = Permission::currentTeacherId();
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['error' => 'template_not_found'], 404);
        }

        $formState = $this->parseFormState((string)($req->post['form_state'] ?? ''));

        try {
            $built = $this->buildFiles($id, $tid, $tmpl, $formState);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'texbuilder_failed', 'detail' => $e->getMessage()], 500);
        }
        $root      = $built['root'];
        $docName   = $built['docName'];
        $mainFinal = $built['mainFinal'];
        $doc       = $built['doc'];
        $styBody   = $built['styBody'];
        $headBody  = $built['headBody'];
        // 21/9/2026 — il pacchetto si consegna nella risposta e non resta su
        // disco. Prima veniva scritto in `<dati>/storage/risdoc-tmp/` e il
        // client tornava a prenderselo con un secondo giro: una cartella da
        // sorvegliare, una durata da promettere, e una rotta che lo dava a *un*
        // docente qualunque invece che al suo proprietario. La ragione per cui
        // nasceva su disco («con Apache root = repo root, il ZIP serve essere
        // raggiungibile via URL») è morta con il passaggio a container e nginx.
        try {
            $byte = \App\Support\PacchettoZip::byte(
                function (\ZipArchive $zip) use ($mainFinal, $docName, $doc, $styBody, $headBody, $built, $root): void {
                    $zip->addFromString('main.tex', $mainFinal);
                    $zip->addFromString($docName, $doc);
                    if ($styBody !== '') {
                        $zip->addFromString('texCommon/risdoc.sty', $styBody);
                    }
                    if ($headBody !== '') {
                        $zip->addFromString('texCommon/intestaLAteX_IIS.tex', $headBody);
                    }
                    if (($built['istitutoBody'] ?? '') !== '') {
                        $zip->addFromString('texCommon/risdoc-istituto.tex', (string)$built['istitutoBody']);
                    }
                    // Images: se presenti nella sorgente origin/images. Il logo
                    // della scuola non sta piu' nel repo: e' un file dell'istanza
                    // per l'Istituto del docente (InstituteAssets), aggiunto qui
                    // col nome che il template si aspetta. Se manca,
                    // l'intestazione lo salta.
                    $imgDir = $root . '/storage/templates/risdoc/images';
                    if (is_dir($imgDir)) {
                        foreach (glob($imgDir . '/*') ?: [] as $img) {
                            if (is_file($img) && basename($img) !== 'logo_scuola.png') {
                                $zip->addFile($img, 'images/' . basename($img));
                            }
                        }
                    }
                    if (!empty($built['logoPath']) && is_file((string)$built['logoPath'])) {
                        $zip->addFile((string)$built['logoPath'], 'images/logo_scuola.png');
                    }
                },
                'risdoc_',
            );
        } catch (\Throwable $e) {
            return Response::json(['error' => 'zip_open_failed', 'detail' => $e->getMessage()], 500);
        }

        $nome = $this->sanitizeName((string)($tmpl['argomento'] ?? 'modello'));
        return new Response($byte, 200, \App\Support\PacchettoZip::intestazioni($nome, $byte));
    }

    private function parseFormState(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    /**
     * G22.S11 — build set di file (riusabile fra export ZIP e modal multi-file
     * preview/compile). Risolve schema-driven body via TexBuilder, carica i
     * 3 texCommon files con override per-teacher (kind='texCommon'), applica
     * landscape + style overrides al .sty.
     *
     * Ritorna: [
     *   'root'      => repo root,
     *   'docName'   => '<argomento>.tex',
     *   'mainFinal' => main.tex con %[filetex] sostituito,
     *   'doc'       => body .tex generato da schema+state (read-only nel modal),
     *   'styBody'   => risdoc.sty (overridable),
     *   'headBody'  => intestaLAteX_IIS.tex (overridable),
     *   'overrides' => [path => 'user' | 'common']  (status per UI tree),
     * ]
     */
    public function buildFiles(int $id, int $tid, array $tmpl, array $formState): array
    {
        $root = dirname(__DIR__, 3);
        // 21/9/2026 — lo stato che vede il compilatore porta anche quello che
        // il documento sa da sé: chi lo scrive, per quale Istituto, in quale
        // anno scolastico. Prima `[field-nome_docente]` finiva nel PDF come
        // segnaposto, perché nessuno lo riempiva. Solo aggiunte: un valore
        // scelto da una persona non si tocca (StatoDelDocumento::arricchito).
        $ctx = [
            'fields' => (array)($formState['fields'] ?? []),
            'state'  => \App\Services\Risdoc\StatoDelDocumento::arricchito(
                (array)($formState['state'] ?? []),
                \App\Services\Risdoc\StatoDelDocumento::perDocente($tid),
            ),
        ];
        $bodyPt = (array)($formState['body_pt'] ?? []);
        // ADR-026 — UNIFY: se c'è il body_pt dell'editor, è LA fonte di verità →
        // render via PtToTex (formule, renderMode checkbox, celle widget, valori,
        // sectionbox: coerente con editor/anteprima/vista web). Lo schema
        // (TexBuilder) resta il FALLBACK per i template senza body_pt.
        if (count($bodyPt) > 0) {
            $doc = \App\Services\Risdoc\Pt\PtToTex::render($bodyPt, $ctx);
        } else {
            $schemaPath = (string)($tmpl['schema_path'] ?? '');
            if ($schemaPath === '' || !is_file($root . '/' . ltrim($schemaPath, '/'))) {
                throw new \RuntimeException('schema_not_set');
            }
            $doc = (new \App\Services\Risdoc\TexBuilder($root . '/' . ltrim($schemaPath, '/')))->build($ctx);
        }

        $repo = new \App\Repositories\Risdoc\OverrideRepository();
        $overrides = [];
        $loadTexCommon = function (string $rel) use ($root, $tid, $id, $repo, &$overrides): string {
            $abs = $root . '/storage/templates/risdoc/texCommon/' . $rel;
            $base = is_file($abs) ? (string)file_get_contents($abs) : '';
            $ov = $repo->find($tid, $id, 'texCommon', $rel);
            if ($ov && $ov['body'] !== null) {
                $overrides['texCommon/' . $rel] = 'user';
                return (string)$ov['body'];
            }
            $overrides['texCommon/' . $rel] = $base !== '' ? 'common' : 'missing';
            return $base;
        };
        $mainTpl  = $loadTexCommon('main.tex');
        $docName  = $this->sanitizeName((string)$tmpl['argomento']) . '.tex';
        $mainFinal = str_replace('%[filetex]', '\\input{' . $docName . '}', $mainTpl);

        // Toggle intestazione istituto (checkbox nella pagina HTML — header
        // section). Default INCLUSA. Se disattivata, commenta la riga
        // \input{texCommon/intestaLAteX_IIS(.tex)} in main.tex.
        $includeHeader = !array_key_exists('includeHeader', (array)($formState['state'] ?? []))
            || filter_var($formState['state']['includeHeader'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
        if (!$includeHeader) {
            $mainFinal = preg_replace(
                '/^[ \t]*\\\\input\{texCommon\/intestaLAteX_IIS(?:\.tex)?\}.*$/m',
                '% [intestazione istituto disattivata dal docente]',
                $mainFinal
            ) ?? $mainFinal;
        }
        $styBody  = $loadTexCommon('risdoc.sty');
        $headBody = $loadTexCommon('intestaLAteX_IIS.tex');
        // 2026-09-04 — dati dell'Istituto per l'intestazione: il modello e'
        // generico e non ne incorpora nessuno. Cascata: override del docente
        // (per questo modello, poi condiviso), override dell'istanza (super
        // admin, template 0), infine generato dal profilo del docente.
        $istitutoBody = $this->loadIstitutoTex($tid, $id, $repo, $overrides);

        // Landscape orientation marker
        $orientation = (string)(($formState['state']['pageOrientation']
                             ?? $formState['pageOrientation'] ?? 'portrait'));
        if ($orientation === 'landscape' && $styBody !== '') {
            $styBody = str_replace('%[landscape]', 'landscape,', $styBody);
        }

        // Style overrides (colori sectionbox dalla toolbar)
        $styleOv = (array)($formState['state']['styleOverrides'] ?? []);
        if ($styleOv && $styBody !== '') {
            $overrideLines = [];
            $hexToRgb = function ($hex) {
                $h = ltrim((string)$hex, '#');
                if (strlen($h) === 3) {
                    $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
                }
                if (!preg_match('/^[0-9a-f]{6}$/i', $h)) {
                    return null;
                }
                return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
            };
            $map = [
                'sectionboxBg'     => 'colorBackTitleSec',
                'sectionboxBorder' => 'borderColor',
                'titleText'        => 'titleTextColor',
            ];
            foreach ($map as $key => $colorName) {
                $rgb = $hexToRgb($styleOv[$key] ?? '');
                if ($rgb) {
                    $overrideLines[] = sprintf('\\definecolor{%s}{RGB}{%d,%d,%d}', $colorName, $rgb[0], $rgb[1], $rgb[2]);
                }
            }
            if ($overrideLines) {
                $injected = "\n% Phase 24.30 — Style overrides via toolbar\n" . implode("\n", $overrideLines) . "\n";
                $styBody = preg_replace(
                    '/((?:\\\\definecolor\{[^}]+\}\{[^}]+\}\{[^}]+\}\s*\n)+)/',
                    '$1' . $injected,
                    $styBody,
                    1
                ) ?? $styBody . $injected;
            }
        }

        return [
            'root'      => $root,
            'docName'   => $docName,
            'mainFinal' => $mainFinal,
            'doc'       => $doc,
            'styBody'   => $styBody,
            'headBody'  => $headBody,
            'istitutoBody' => $istitutoBody,
            'logoPath'  => \App\Services\Risdoc\InstituteAssets::logoPathFor($tid),
            'overrides' => $overrides,
        ];
    }

    /**
     * texCommon/risdoc-istituto.tex: i dati dell'Istituto per l'intestazione.
     *
     * Override del docente per questo modello → file dell'istanza per il suo
     * Istituto (<storage>/risdoc/istituti/<id>.tex, InstituteAssets) →
     * generato dal profilo: nome e citta' dell'Istituto dichiarato. Nessun
     * Istituto e' scritto nel codice o nei modelli. (Un override "condiviso"
     * con template_id 0 non e' possibile: la chiave esterna lo rifiuta.)
     *
     * @param array<string,string> $overrides  stato per l'albero dei file (UI)
     */
    private function loadIstitutoTex(int $tid, int $templateId, \App\Repositories\Risdoc\OverrideRepository $repo, array &$overrides): string
    {
        $rel = 'risdoc-istituto.tex';
        $ov = $repo->find($tid, $templateId, 'texCommon', $rel);
        if ($ov && $ov['body'] !== null && trim((string)$ov['body']) !== '') {
            $overrides['texCommon/' . $rel] = 'user';
            return (string)$ov['body'];
        }
        $inst = \App\Services\Risdoc\InstituteAssets::headerTexFor($tid);
        if ($inst !== null) {
            $overrides['texCommon/' . $rel] = 'institutional';
            return $inst;
        }
        $overrides['texCommon/' . $rel] = 'generated';
        $i = \App\Services\Risdoc\InstituteAssets::instituteFor($tid);
        $lines = [
            '% Generato dall\'export dal profilo del docente (2026-09-04). Per',
            '% indirizzo, telefono, PEC e codice fiscale: override di questo file',
            '% dal pannello dei file TeX (docente) o dall\'amministrazione (istanza).',
        ];
        if ($i !== null) {
            $lines[] = '\\renewcommand*{\\risdocIstitutoNome}{' . $this->escapeTex($i['name']) . '}';
            if (($i['city'] ?? '') !== '') {
                $lines[] = '\\renewcommand*{\\risdocIstitutoSede}{' . $this->escapeTex((string)$i['city']) . '}';
            }
        }
        return implode("\n", $lines) . "\n";
    }

    private function escapeTex(string $s): string
    {
        return strtr($s, ['\\' => '\\textbackslash{}', '&' => '\\&', '%' => '\\%', '$' => '\\$',
                          '#' => '\\#', '_' => '\\_', '{' => '\\{', '}' => '\\}',
                          '~' => '\\textasciitilde{}', '^' => '\\textasciicircum{}']);
    }

    public function sanitizeName(string $n): string
    {
        return preg_replace('/[^\w.\-]/', '_', $n) ?: 'documento';
    }
}
