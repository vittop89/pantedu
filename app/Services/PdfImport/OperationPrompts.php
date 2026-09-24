<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

/**
 * Phase PDF-Import — registro dei PROMPT di sistema per operazione.
 *
 * Centralizza i prompt di default (definiti nei rispettivi servizi) e risolve
 * l'eventuale override impostato dall'admin (PromptStore). I call-site usano
 * resolve($key) invece del const, così i prompt sono modificabili dalla pagina
 * /teacher/pdf-import/models senza deploy.
 */
final class OperationPrompts
{
    /** key => etichetta UI (ordine di visualizzazione). */
    public const KEYS = [
        'extraction'        => 'Estrazione esercizi (vision: testo, colori, difficoltà)',
        'difficulty'        => 'Numero + Difficoltà — crop-zoom badge (vision)',
        'numbers'           => 'Scansione numeri badge (vision, opzionale)',
        'topics'            => 'Argomento automatico',
        'translation'       => 'Traduzione in italiano',
        'solutions_algebra' => 'Soluzioni — Algebra (generico)',
        'solutions_fratta'  => 'Soluzioni — Eq./diseq. fratte',
        'solutions_irrazionale' => 'Soluzioni — Irrazionali (radici)',
        'solutions_valore_assoluto' => 'Soluzioni — Valore assoluto',
        'solutions_sistema' => 'Soluzioni — Sistemi',
        'solutions_disequazione' => 'Soluzioni — Disequazioni',
        'solutions_esponenziale' => 'Soluzioni — Esponenziali',
        'solutions_logaritmica' => 'Soluzioni — Logaritmiche',
        'solutions_physics' => 'Soluzioni — Fisica',
        'solutions_theory'  => 'Soluzioni — Teoria',
        'solutions_vf'      => 'Soluzioni — Vero/Falso',
    ];

    /** @return array<string,string> default di codice per ogni key. */
    public static function defaults(): array
    {
        return [
            'extraction'        => ExtractionPipeline::SYSTEM_PROMPT,
            'difficulty'        => DifficultyRefiner::SYSTEM,
            'numbers'           => ExtractionPipeline::SCAN_SYSTEM,
            'topics'            => TopicGenerator::SYSTEM,
            'translation'       => TranslationGenerator::SYSTEM,
            'solutions_algebra' => SolutionGenerator::SYS_ALGEBRA,
            'solutions_fratta'  => SolutionGenerator::SYS_FRATTA,
            'solutions_irrazionale' => SolutionGenerator::SYS_IRRAZIONALE,
            'solutions_valore_assoluto' => SolutionGenerator::SYS_VALORE_ASSOLUTO,
            'solutions_sistema' => SolutionGenerator::SYS_SISTEMA,
            'solutions_disequazione' => SolutionGenerator::SYS_DISEQUAZIONE,
            'solutions_esponenziale' => SolutionGenerator::SYS_ESPONENZIALE,
            'solutions_logaritmica' => SolutionGenerator::SYS_LOGARITMICA,
            'solutions_physics' => SolutionGenerator::SYS_PHYSICS,
            'solutions_theory'  => SolutionGenerator::SYS_THEORY,
            'solutions_vf'      => SolutionGenerator::SYS_VF,
        ];
    }

    /** Prompt effettivo: override admin (PromptStore) → default di codice. */
    public static function resolve(string $key, ?PromptStore $store = null): string
    {
        $store ??= new PromptStore();
        $ovr = $store->get($key);
        if ($ovr !== null) {
            return $ovr;
        }
        return self::defaults()[$key] ?? '';
    }

    /** Stato per la UI: key => {label, value (effettivo), default, overridden}. */
    public static function status(): array
    {
        $store = new PromptStore();
        $def = self::defaults();
        $out = [];
        foreach (self::KEYS as $key => $label) {
            $ovr = $store->get($key);
            $out[$key] = [
                'label'      => $label,
                'value'      => $ovr ?? ($def[$key] ?? ''),
                'default'    => $def[$key] ?? '',
                'overridden' => $ovr !== null,
            ];
        }
        return $out;
    }
}
