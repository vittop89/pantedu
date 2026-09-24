<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Repositories\Risdoc\OverrideRepository;
use App\Repositories\Risdoc\InstitutionalOverrideRepository;
use App\Core\Database;
use App\Support\SafePath;
use PDO;

/**
 * Risolve il contenuto di un template per un docente:
 *   - se esiste override per (teacher, template, kind, path) → usa quello.
 *   - altrimenti fall-back al file sorgente in storage/templates/.
 *
 * Non fa auth check (responsabilità del controller). Usa Permission separately.
 */
final class TemplateResolver
{
    public function __construct(
        private OverrideRepository $overrides = new OverrideRepository(), // Phase 24.55 — institutional layer (admin-edited via UI)
        private InstitutionalOverrideRepository $institutional = new InstitutionalOverrideRepository()
    ) {
    }

    /**
     * Carica metadata template.
     */
    public function findTemplate(int $templateId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM risdoc_templates WHERE id = ?');
        $stmt->execute([$templateId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Lista template visibili per teacher (owner + collab + visibility granted).
     * Super-admin chiama listAll().
     */
    public function listForTeacher(int $teacherId, ?string $category = null, bool $withBodyPt = false): array
    {
        // G22.S26 — owner_id dropped (migration 047). Modello semplificato:
        // collab + visible (+ tutti i template "institutional" visibili a
        // chi non è né collab né visible, regolato da visibility_scope).
        // Phase 24.58 — colonna `origin` rimossa: si filtra/ordina per category.
        //
        // `visibility_scope` viene selezionato e applicato in coda (vedi sotto):
        // prima la query chiudeva con `WHERE 1=1` e ogni docente riceveva ogni
        // template. Con lo scope di default `public` il risultato coincideva con
        // quanto Permission::canView() avrebbe comunque concesso, quindi non si
        // notava; ma un template messo su `denied`/`classe`/`indirizzo` dal
        // pannello admin (RisdocAdminController::setVisibilityScope) continuava
        // a comparire nella lista di tutti — e con ?with_body_pt=1 anche il suo
        // contenuto.
        $bodyCol = $withBodyPt ? ', t.body_pt' : '';
        $sql = "
            SELECT DISTINCT t.id, t.code, t.category, t.num_arg, t.argomento, t.discipline,
                   t.requires_password, t.visibility_scope$bodyCol,
                   CASE
                     WHEN c.teacher_id = ? THEN 'collab'
                     ELSE 'viewer'
                   END AS role
            FROM risdoc_templates t
            LEFT JOIN risdoc_template_collaborators c ON c.template_id = t.id AND c.teacher_id = ?
            LEFT JOIN risdoc_template_visibility    v ON v.template_id = t.id AND v.teacher_id = ? AND v.visible = 1
            WHERE 1=1
        ";
        $params = array_fill(0, 3, $teacherId);
        if ($category) {
            $sql .= ' AND t.category = ?';
            $params[] = $category;
        }
        $sql .= ' ORDER BY t.category, t.num_arg';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2026-09-04 — i modelli istituzionali (piano annuale, relazione
        // finale, scheda progetto: categoria `modelli`) producono atti che
        // appartengono agli archivi della scuola, non a questa piattaforma
        // (ToS §2.5). Con RISDOC_INSTITUTIONAL_TEMPLATES=false spariscono dal
        // catalogo di ogni docente dell'istanza, autore compreso: la regola
        // vale per chi la scrive come per chi la accetta. I modelli didattici
        // (strumenti compensativi, legislazione) restano.
        if (!self::institutionalTemplatesEnabled()) {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => ($r['category'] ?? '') !== 'modelli'));
        }

        // Lo scope si applica qui e non in SQL: replicarlo nella query
        // significherebbe riscrivere le join su institute/indirizzo/classe già
        // presenti in Permission::canView(), e due copie della stessa regola
        // finiscono per divergere — che è esattamente il difetto che questo
        // filtro sana. `public` (il default, e oggi l'unico valore in uso) non
        // costa alcuna query in più: passa senza chiamare canView().
        return array_values(array_filter($rows, static function (array $r) use ($teacherId): bool {
            if (($r['visibility_scope'] ?? 'public') === 'public') {
                return true;
            }
            if (($r['role'] ?? '') === 'collab') {
                return true;
            }
            return Permission::canView((int)$r['id'], $teacherId);
        }));
    }

    /**
     * I modelli istituzionali sono nel catalogo dei docenti? Default si';
     * `RISDOC_INSTITUTIONAL_TEMPLATES=false` in .env.local li toglie
     * (vedi listForTeacher). Il pannello admin (listAll) li vede sempre.
     */
    public static function institutionalTemplatesEnabled(): bool
    {
        $v = \App\Core\Config::get('app.risdoc_institutional_templates', true);
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public function listAll(?string $category = null, bool $withBodyPt = false): array
    {
        // Phase 24.58 — colonna `origin` rimossa.
        $cols = 'id, code, category, num_arg, argomento, discipline, requires_password';
        if ($withBodyPt) {
            $cols .= ', body_pt';
        }
        $sql = "SELECT $cols FROM risdoc_templates WHERE 1=1";
        $params = [];
        if ($category) {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        $sql .= ' ORDER BY category, num_arg';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Phase 24.50 — Salva il PT AST seed di un template (admin only,
     * gating gestito dal controller).
     */
    public function saveBodyPt(int $templateId, ?array $bodyPt): bool
    {
        $val = $bodyPt === null
            ? null
            : json_encode($bodyPt, JSON_UNESCAPED_UNICODE);
        $stmt = Database::connection()->prepare('UPDATE risdoc_templates SET body_pt = ? WHERE id = ?');
        $stmt->execute([$val, $templateId]);
        return $stmt->rowCount() >= 0;
// rowCount=0 valido se body_pt invariato
    }

    /**
     * Risolve body di un file: override se esiste, altrimenti source file.
     * Ritorna [body, source_version, source] dove source ∈ {override,file,null}.
     *
     * $path è relativo alla source_dir del template (es. html_file value).
     * Per kind='image', ritorna ['image_hash' => ..., 'source' => ...].
     */
    public function resolveFile(int $teacherId, int $templateId, string $kind, string $path, string $instanceKey = ''): ?array
    {
        $tmpl = $this->findTemplate($templateId);
        if (!$tmpl) {
            return null;
        }

        // Phase 24.58 — resolver order multi-instance:
        //   1. teacher override per (teacher, template, instance_key)
        //   2. institutional override (admin-edited via UI)
        //   3. source file su disco (legacy)

        // 1. teacher override (skipare per kind=schema: schema è
        // proprietà istituzionale, il docente non lo modifica)
        if ($teacherId > 0 && $kind !== 'schema') {
            $ov = $this->overrides->find($teacherId, $templateId, $kind, $path, $instanceKey);
            if ($ov) {
                return [
                    'body'           => $ov['body'],
                    'image_hash'     => $ov['image_hash'],
                    'source_version' => $ov['source_version'],
                    'source'         => 'override',
                    'instance_key'   => $instanceKey,
                    'updated_at'     => $ov['updated_at'],
                ];
            }
        }

        // 2. institutional override (admin baseline)
        $iov = $this->institutional->find($templateId, $kind, $path);
        if ($iov) {
            return [
                'body'           => $iov['body'],
                'image_hash'     => $iov['image_hash'],
                'source_version' => $iov['source_version'],
                'source'         => 'institutional',
                'updated_at'     => $iov['updated_at'],
            ];
        }

        // 3. source file
        $abs = $this->resolveSourceFilePath($tmpl, $kind, $path);
        if (!$abs || !is_file($abs)) {
            return null;
        }

        if ($kind === 'image') {
            return [
                'body'           => null,
                'image_hash'     => null,
                'source_version' => $tmpl['source_hash'],
                'source'         => 'file',
                'absolute_path'  => $abs,
            ];
        }
        $body = (string)@file_get_contents($abs);
        return [
            'body'           => $body,
            'image_hash'     => null,
            'source_version' => $tmpl['source_hash'],
            'source'         => 'file',
            'absolute_path'  => $abs,
        ];
    }

    /**
     * Costruisce path assoluto di un file sorgente.
     *
     * kind=html → source_dir/html_file
     * kind=tex  → derivato da html_file con .tex extension, in sibling tex/ dir
     * kind=css  → css_file
     * kind=json → path relativo al template root (storage/templates/<origin>/)
     * kind=image→ path relativo (images/logo_scuola.png) risolto a storage/templates/<origin>/images/...
     */
    public function resolveSourceFilePath(array $tmpl, string $kind, string $path): ?string
    {
        // La base versionata, in un punto solo: da qui si leggono i modelli.
        $base = dirname(__DIR__, 3) . '/';
        // Phase 24.58 — colonna `origin` rimossa: gli asset (json/immagini come
        // loghi/stemma) vivono tutti sotto un'unica cartella storage/templates/risdoc.
        $originBase = $base . 'storage/templates/risdoc';
        $sourceDir  = str_replace('\\', '/', (string)($tmpl['source_dir'] ?? ''));

        // Ogni kind ha la sua cartella, e il percorso finale deve starci dentro
        // (23/9/2026, revisione Risdoc A1). Prima `path` arrivava dalla query di
        // GET /api/risdoc/templates/{id}/file e si attaccava alla cartella così
        // com'era: con kind=schema la cartella era la radice del progetto, e
        // qualunque docente autenticato leggeva un file qualsiasi dell'applicazione,
        // configurazione compresa; con json e image bastava un `..`.
        switch ($kind) {
            case 'html':
                $dir  = $base . $sourceDir;
                $file = $path !== '' ? $path : (string)($tmpl['html_file'] ?? '');
                break;
            case 'tex':
                if (!($tmpl['tex_file'] ?? null)) {
                    return null;
                }
                $dir  = $base . (string)preg_replace('#/php$#', '/tex', $sourceDir);
                $file = $path !== '' ? $path : (string)$tmpl['tex_file'];
                break;
            case 'css':
                if (!($tmpl['css_file'] ?? null)) {
                    return null;
                }
                $dir  = $base . (string)preg_replace('#/php$#', '/css', $sourceDir);
                $file = $path !== '' ? $path : (string)$tmpl['css_file'];
                break;
            case 'json':
                // path relativo alla root dell'origin (es. "competenze_DM2007/competenze_DM2007.json")
            case 'image':
                // path relativo (es. "images/logo_scuola.png")
                $dir  = $originBase;
                $file = $path;
                break;
            case 'schema':
                // Phase 24.56 — schema_path è relativo alla project root
                // (es. "schemas/risdoc/piano-annuale-docente.json"), e sta
                // sempre sotto schemas/risdoc.
                $dir  = $base . 'schemas/risdoc';
                $file = $path;
                if (str_starts_with(str_replace('\\', '/', $file), 'schemas/risdoc/')) {
                    $file = substr($file, \strlen('schemas/risdoc/'));
                }
                break;
            default:
                return null;
        }
        if ($sourceDir === '' && \in_array($kind, ['html', 'tex', 'css'], true)) {
            return null;
        }
        return self::dentroDi($dir, $file);
    }

    /**
     * Il percorso di `$file` dentro `$dir`, oppure null se ne esce (`..`,
     * percorso assoluto, byte nullo) o se è vuoto.
     */
    private static function dentroDi(string $dir, string $file): ?string
    {
        if ($file === '' || SafePath::isAbsolute($file)) {
            return null;
        }
        try {
            return SafePath::resolve(rtrim($dir, '/\\') . '/' . $file, [$dir]);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
