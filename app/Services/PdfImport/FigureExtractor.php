<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Services\PdfImport\Provider\ProviderInterface;
use App\Services\TexCompile\TikzRenderClient;

/**
 * Phase PDF-Import — generazione TikZ per gli esercizi con figura.
 *
 * Per le row con has_figure, chiede al modello (testo) di produrre codice TikZ
 * a partire dalla descrizione della figura, e — se il microservizio TikZ è
 * configurato — ne genera un'anteprima SVG riusando TikzRenderClient
 * (tools/tex-compile-vps /render-tikz). Niente nuova infra.
 */
final class FigureExtractor
{
    private const SYSTEM = <<<TXT
Sei un assistente esperto di TikZ/PGF per figure scolastiche di matematica e
fisica. Dato il testo di un esercizio e la descrizione della sua figura, produci
SOLO il codice TikZ del corpo (tra \\begin{tikzpicture} e \\end{tikzpicture}),
senza preamble, senza spiegazioni, senza markdown. Usa coordinate semplici e
pacchetti base. Se la figura non è ricostruibile, rispondi con una tikzpicture
vuota con un commento "% figura non ricostruibile".
TXT;

    public function __construct(
        private readonly ?TikzRenderClient $tikz = null,
    ) {
    }

    /**
     * Genera il TikZ per una row. Ritorna ['tikz'=>string, 'svg'=>?string,
     * 'tokens_in'=>int, 'tokens_out'=>int].
     */
    public function generate(ProviderInterface $client, array $row): array
    {
        $payload = (array)($row['payload'] ?? []);
        $question = (string)($payload['question'] ?? '');
        $figDesc  = (string)($payload['figure_description'] ?? '');

        $userPrompt = "Esercizio:\n" . PromptGuard::fence($question)
            . "\n\nDescrizione figura:\n" . PromptGuard::fence($figDesc)
            . "\n\nGenera il codice TikZ.";

        $res  = $client->complete(self::SYSTEM, $userPrompt);
        $tikz = self::stripFences((string)($res['text'] ?? ''));

        $svg = null;
        if ($this->tikz !== null && $tikz !== '') {
            try {
                $out = $this->tikz->render($tikz);
                if (is_array($out) && !empty($out['ok']) && !empty($out['svg'])) {
                    $svg = (string)$out['svg'];
                }
            } catch (\Throwable) {
                $svg = null; // preview best-effort
            }
        }

        return [
            'tikz'       => $tikz,
            'svg'        => $svg,
            'tokens_in'  => (int)($res['tokens_in'] ?? 0),
            'tokens_out' => (int)($res['tokens_out'] ?? 0),
        ];
    }

    private static function stripFences(string $s): string
    {
        $s = trim($s);
        $s = (string)preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $s);
        return trim($s);
    }
}
