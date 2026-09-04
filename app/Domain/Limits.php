<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * G22.S15.bis Fase 5+ — PROBLEM-15 fix: limiti dimensionali centralizzati.
 *
 * Sostituisce le constants sparse in 6+ controller/service. Quando si aggiunge
 * un nuovo cap (es. nuovo upload type) NON lo si hardcoda nel controller —
 * si aggiunge una constant qui e si referenzia.
 *
 * Convenzione naming: {ENTITA'}_{CAMPO}_MAX_BYTES, espresso in bytes.
 * Comments inline indicano il valore in unità human-readable.
 *
 * NB: per retro-compat le classi che già hanno costanti private (es.
 * VerificaDocumentService::MAX_TEX_BYTES) restano funzionali; al refactor
 * incrementale si delega a queste constants centrali.
 */
final class Limits
{
    /** Plaintext TEX di una verifica (post-build, pre-encrypt). */
    public const VERIFICA_TEX_MAX_BYTES = 4 * 1024 * 1024;       // 4 MiB

    /** PDF compilato di una verifica (upload da docente o output VPS). */
    public const VERIFICA_PDF_MAX_BYTES = 30 * 1024 * 1024;      // 30 MiB

    /** Selection JSON inviato a /api/verifica/save-tex(-batch). */
    public const SELECTION_PAYLOAD_MAX_BYTES = 2 * 1024 * 1024;  // 2 MiB

    /** File template verifica (texCommon/, versioni/, griglie/, BES_DSA/). */
    public const TEMPLATE_FILE_MAX_BYTES = 200 * 1024;           // 200 KB

    /** Sorgente TikZ inviato a /tikz/render. */
    public const TIKZ_SOURCE_MAX_BYTES = 1 * 1024 * 1024;        // 1 MiB

    /** Mappa drawio caricata via POST /api/maps (PDF/XML/PNG/HTML). */
    public const MAP_FILE_MAX_BYTES = 50 * 1024 * 1024;          // 50 MiB

    /** Libreria shape drawio del docente (.xml). */
    public const DRAWIO_LIBRARY_MAX_BYTES = 1 * 1024 * 1024;     // 1 MiB

    /** Drawio inline-XML embed (URL fragment limit ~2MB compresso). */
    public const DRAWIO_INLINE_MAX_BYTES = 800 * 1024;           // 800 KB

    /** SVG GeoGebra inviato a /api/verifica/{id}/geogebra-attach (base64-encoded). */
    public const GEOGEBRA_SVG_MAX_BYTES = 4 * 1024 * 1024;       // 4 MiB

    /** Page size default per liste paginate (search, list endpoints). */
    public const DEFAULT_PAGE_SIZE = 50;

    /** Page size massima per liste (cap defensive vs DOS). */
    public const MAX_PAGE_SIZE = 500;

    /** Page size hard-cap per teacher_content search (alta cardinalità). */
    public const MAX_LIST_LIMIT = 2500;
}
