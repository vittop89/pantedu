/**
 * Risdoc Web Components (Plan B) — barrel export.
 *
 * Caricato come modulo ES dal TemplateViewController quando un template
 * ha schema_path valorizzato. Importa Lit da esm.run (CDN ESM) per
 * pilot: in produzione si bundlerebbe con Vite (package.json → lit).
 *
 * Tutti i componenti usano prefix `fm-risdoc-*` per evitare collisioni.
 */

// ADR-026 #3 (2026-05-28) — fm-risdoc-template.js ELIMINATO (motore legacy
// sostituito dal motore unificato <fm-pt-document>+<fm-risdoc-pt-section>).
import "./fm-risdoc-section-header.js";
import "./fm-risdoc-grade-selector.js";
import "./fm-risdoc-giudizio-group.js";
import "./fm-risdoc-giudizio-item.js";
import "./fm-risdoc-nota-textarea.js";
// Phase 22.4c — PT-aware variant: attivo se schema.field.default è PT AST
import "./fm-risdoc-nota-pt-rich.js";
// Phase 24.6 — section unificata PT (opt-in via section.pt_unified)
import "./fm-risdoc-pt-section.js";
// Phase 24.10b — toolbar globale sticky (attiva se schema ha ≥1 pt_unified)
import "./fm-risdoc-pt-toolbar.js";
// Extensions per coverage di tutti i 15 template:
import "./fm-risdoc-text-section.js";
import "./fm-risdoc-checkbox-group.js";
import "./fm-risdoc-dynamic-table.js";
import "./fm-risdoc-info-field.js";
import "./fm-risdoc-form-checkbox.js";
import "./fm-risdoc-static-content.js";
import "./fm-risdoc-glossary-table.js";
import "./fm-risdoc-privacy-block.js";
import "./fm-risdoc-signature-block.js";

console.log("[risdoc-wc] 17 components registered (+ pt-rich/pt-section/pt-toolbar lazy pt-editor)");
