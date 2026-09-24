<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export;

/**
 * Phase 25.R.23 — Contratto per un exporter (Strategy pattern).
 *
 * Ogni tipo di contenuto utente (profilo, mappe, verifiche, ...) implementa
 * questa interfaccia. UserDataExportService li registra in un registry
 * pluggable — aggiungere un nuovo tipo di documento esportabile in futuro
 * richiede solo:
 *
 *   1. Creare nuova classe in app/Services/Gdpr/Export/Exporters/
 *   2. Implementare ContentExporterInterface
 *   3. Registrare in UserDataExportService::default()
 *
 * Esempio nuovo exporter (es. quiz interattivi):
 *
 *   final class QuizExporter implements ContentExporterInterface
 *   {
 *       public function getKey(): string { return 'quiz'; }
 *       public function getLabel(): string { return 'Quiz interattivi'; }
 *       public function getCategory(): string { return 'content'; }
 *       public function isAvailableForSelfService(): bool { return true; }
 *       public function isAvailableForAuthority(): bool { return true; }
 *       public function export(ExportContext $ctx): ExportSection
 *       {
 *           $section = new ExportSection('quiz', 'content/quiz', $this->getLabel());
 *           // query DB + decrypt + addFile/addJsonFile
 *           return $section;
 *       }
 *   }
 */
interface ContentExporterInterface
{
    /** Identificatore unico (snake_case, es. 'mappe', 'verifiche'). */
    public function getKey(): string;

    /** Label umano (es. "Mappe didattiche"). */
    public function getLabel(): string;

    /**
     * Categoria per raggruppamento UI:
     *   - 'profile'  → dati anagrafici, consensi
     *   - 'content'  → contenuti creati dal docente
     *   - 'meta'     → metadati derivati (audit, share)
     */
    public function getCategory(): string;

    /** True se accessibile da self-service /me/export-data (Art. 15/20 GDPR). */
    public function isAvailableForSelfService(): bool;

    /** True se accessibile da admin authority-export (Art. 6(1)(c) decreto). */
    public function isAvailableForAuthority(): bool;

    /**
     * Genera la sezione export.
     *
     * NB: implementazioni devono gestire decrypt errors gracefully — meglio
     * una section vuota con summary._error che un'eccezione che blocca l'export.
     */
    public function export(ExportContext $ctx): ExportSection;
}
