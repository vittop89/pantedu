<?php

declare(strict_types=1);

namespace App\Services\Tex;

/**
 * G22.S15.bis Fase 5+ — Centralized LaTeX escape utility.
 *
 * Single source of truth per l'escape dei caratteri speciali LaTeX nei
 * contenuti testuali. Replace in-progress di 4 implementazioni duplicate
 * sparse nel codebase:
 *   - App\Services\ContractRenderer::escTex()           (rimosso)
 *   - App\Services\Risdoc\TexBuilder::esc()             (rimosso)
 *   - App\Services\Verifica\VerificaTemplateStandard::escapeTex()  (rimosso)
 *   - App\Services\Risdoc\Pt\TexEscape::escape()        (alias kept)
 *
 * Mappa byte-identica con tutte le implementazioni precedenti — zero
 * regression sull'output TeX generato.
 *
 * NB: NON applicare a contenuti `rawTex` (LaTeX intenzionale) né ai
 * placeholder `{{name}}` o `[field-name]` (sostituiti pre-render).
 */
class TexEscape
{
    private const MAP = [
        '\\' => '\\textbackslash{}',
        '&'  => '\\&',
        '%'  => '\\%',
        '$'  => '\\$',
        '#'  => '\\#',
        '_'  => '\\_',
        '{'  => '\\{',
        '}'  => '\\}',
        '~'  => '\\textasciitilde{}',
        '^'  => '\\textasciicircum{}',
    ];

    /**
     * Escape caratteri speciali LaTeX in testo plain.
     * Backslash gestito per primo per evitare doppia-escape.
     */
    public static function escape(string $text): string
    {
        // Backslash separato per evitare di escapare i \\textbackslash{} appena inseriti.
        $out = str_replace('\\', '\\textbackslash{}', $text);
        foreach (self::MAP as $from => $to) {
            if ($from === '\\') {
                continue;
            }
            $out = str_replace($from, $to, $out);
        }
        return $out;
    }
}
