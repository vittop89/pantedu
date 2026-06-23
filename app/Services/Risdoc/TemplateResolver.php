<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Core\Database;
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
        // chi non è né collab né visible, regolato da visibility_scope a
        // valle in Permission::canView).
        // Phase 24.58 — colonna `origin` rimossa: si filtra/ordina per category.
        $bodyCol = $withBodyPt ? ', t.body_pt' : '';
        $sql = "
            SELECT DISTINCT t.id, t.code, t.category, t.num_arg, t.argomento, t.discipline,
                   t.requires_password$bodyCol,
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        $root = dirname(__DIR__, 3);
// from app/Services/Risdoc/ → project root
        $srcDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$tmpl['source_dir']);
        // Phase 24.58 — colonna `origin` rimossa: gli asset (json/immagini come
        // loghi/stemma) vivono tutti sotto un'unica cartella storage/templates/risdoc.
        $originBase = $root . '/storage/templates/risdoc';
        switch ($kind) {
            case 'html':
                return $srcDir . DIRECTORY_SEPARATOR . ($path !== '' ? $path : $tmpl['html_file']);
            case 'tex':
                if (!$tmpl['tex_file']) {
                    return null;
                }
                $texDir = preg_replace('#/php$#', '/tex', str_replace('\\', '/', (string)$tmpl['source_dir']));

                return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $texDir)
                     . DIRECTORY_SEPARATOR . ($path !== '' ? $path : $tmpl['tex_file']);
            case 'css':
                if (!$tmpl['css_file']) {
                    return null;
                }
                        $cssDir = preg_replace('#/php$#', '/css', str_replace('\\', '/', (string)$tmpl['source_dir']));

                return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cssDir)
                     . DIRECTORY_SEPARATOR . ($path !== '' ? $path : $tmpl['css_file']);
            case 'json':
                        // path relativo alla root dell'origin (es. "competenze_DM2007/competenze_DM2007.json")


                return $originBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            case 'image':
                        // path relativo (es. "images/logo_scuola.png") → storage/templates/<origin>/images/...


                return $originBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            case 'schema':
                        // Phase 24.56 — schema_path è relativo alla project root
                        // (es. "schemas/risdoc/piano-annuale-docente.json").


                return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        }
        return null;
    }
}
