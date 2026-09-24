<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use PDO;

/**
 * ADR-037, invariante S6 — le pubblicazioni dei contenuti e delle verifiche
 * del docente nell'esportazione dei suoi dati (Art. 15 e 20 GDPR).
 *
 * Una pubblicazione dice dove e con che stato un contenuto o una verifica del
 * docente è visibile: scuola, indirizzo, classe, materia, principale o no,
 * stato, visibile negli anni passati. Sono dati del docente come i contenuti, quindi
 * seguono le stesse regole dell'esportazione dei contenuti: tutte, bozze
 * comprese, senza filtro di visibilità, solo del proprietario. Nessun dato di
 * studenti: una pubblicazione non ne contiene.
 */
final class PubblicazioniExporter implements ContentExporterInterface
{
    public function getKey(): string
    {
        return 'pubblicazioni';
    }

    public function getLabel(): string
    {
        return 'Pubblicazioni dei contenuti e delle verifiche';
    }

    public function getCategory(): string
    {
        return 'content';
    }

    public function isAvailableForSelfService(): bool
    {
        return true;
    }

    public function isAvailableForAuthority(): bool
    {
        return true;
    }

    public function export(ExportContext $ctx): ExportSection
    {
        $section = new ExportSection('pubblicazioni', 'content', $this->getLabel());
        $righe = [];
        try {
            // Contenuti e verifiche (dalla fase 3): una riga per pubblicazione,
            // con l'id del documento nella colonna della sua fonte.
            $st = Database::connection()->prepare(
                'SELECT p.id, p.teacher_content_id AS contenuto_id, p.verifica_document_id AS verifica_id,
                        COALESCE(d.title, v.title) AS titolo,
                        i.name AS scuola, p.institute_id AS scuola_id,
                        ci.code AS indirizzo, cc.code AS classe, cm.code AS materia,
                        p.is_primary AS principale, p.visibility AS stato,
                        p.archive_visible AS visibile_negli_anni_passati,
                        p.created_at, p.updated_at
                   FROM content_publications p
                   LEFT JOIN teacher_content_data d    ON d.id = p.teacher_content_id
                   LEFT JOIN verifica_documents_data v ON v.id = p.verifica_document_id
                   LEFT JOIN institutes i          ON i.id  = p.institute_id
                   LEFT JOIN curriculum_entries ci ON ci.id = p.indirizzo_id
                   LEFT JOIN curriculum_entries cc ON cc.id = p.classe_id
                   LEFT JOIN curriculum_entries cm ON cm.id = p.subject_id
                  WHERE d.teacher_id = ? OR v.teacher_id = ?
                  ORDER BY p.teacher_content_id IS NULL, p.teacher_content_id, p.verifica_document_id,
                           p.is_primary DESC, p.id'
            );
            $st->execute([$ctx->userId, $ctx->userId]);
            $righe = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // Tabella assente (migrazione 117 non applicata): lo si dice nel
            // riepilogo invece di esportare una sezione vuota che sembra piena.
            $section->setSummary(['disponibile' => false, 'motivo' => 'pubblicazioni non ancora presenti']);
            return $section;
        }
        foreach ($righe as &$r) {
            $r['principale'] = (bool)$r['principale'];
            $r['visibile_negli_anni_passati'] = (bool)$r['visibile_negli_anni_passati'];
        }
        unset($r);
        $section->addJsonFile('pubblicazioni.json', $righe);
        $section->setSummary(['disponibile' => true, 'pubblicazioni' => count($righe)]);
        return $section;
    }
}
