<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

/**
 * Phase PDF-Import — tracciamento provenienza IA (AI Act art. 50(2)).
 *
 * Il Reg. UE 2024/1689 art. 50(2) impone al fornitore di marcare in formato
 * leggibile da macchina gli output sintetici, MA esclude i sistemi che svolgono
 * "una funzione assistiva per l'editing standard" o che "non alterano in modo
 * sostanziale i dati di input forniti dal deployer o la loro semantica".
 *
 * Da qui la distinzione fra due classi di operazione:
 *
 *   ASSISTIVA  → trascrive ciò che è già stampato sulla pagina
 *                (estrazione OCR/vision, conteggio dei pallini di difficoltà).
 *                Registrata per l'audit trail, NON marcata verso lo studente.
 *
 *   GENERATIVA → produce contenuto nuovo o ne altera la semantica
 *                (soluzioni, argomenti, traduzioni). Fa scattare la marcatura.
 *
 * La provenienza è tracciata PER CAMPO, non per riga: la stessa riga può avere
 * un testo trascritto dal libro e una soluzione generata dal modello, e solo la
 * seconda va marcata. `SolutionGenerator` scrive infatti la soluzione solo se
 * quella stampata sul libro mancava.
 *
 * I dati vivono in `$row['ai_meta']` dentro `contracts.json`, nello storage
 * cifrato della sessione, che ha già il TTL di PDF_IMPORT_RETENTION_DAYS: non
 * introduce alcuna nuova persistenza.
 */
final class AiProvenance
{
    /** Chiave in cui si accumula la provenienza dentro la riga di sessione. */
    public const META_KEY = 'ai_meta';

    public const OP_EXTRACTION  = 'extraction';
    public const OP_DIFFICULTY  = 'difficulty';
    public const OP_SOLUTIONS   = 'solutions';
    public const OP_TOPICS      = 'topics';
    public const OP_TRANSLATION = 'translation';

    /** Operazioni che alterano sostanzialmente il contenuto → art. 50(2) si applica. */
    private const GENERATIVE = [
        self::OP_SOLUTIONS,
        self::OP_TOPICS,
        self::OP_TRANSLATION,
    ];

    /**
     * Campo della riga di sessione → campo corrispondente nell'item del contract
     * (cfr. ExerciseInserter::baseItem). Serve a non far conoscere ai generatori
     * lo schema del contract: ciascuno marca il proprio campo, la traduzione
     * avviene qui.
     */
    private const CONTRACT_FIELD = [
        'solution'   => 'solution',
        'topic'      => 'category_label',
        'payload'    => 'question',
        'difficulty' => 'difficulty',
    ];

    public static function isGenerative(string $op): bool
    {
        return \in_array($op, self::GENERATIVE, true);
    }

    /**
     * Registra che $field della riga è stato prodotto dall'operazione $op del
     * modello $model. Idempotente per campo: una nuova marcatura sostituisce la
     * precedente (l'ultimo generatore che ha scritto è quello che conta).
     *
     * @param array<string,mixed> $row riga di sessione (modificata in place)
     */
    public static function stamp(array &$row, string $field, string $op, string $model): void
    {
        if ($field === '' || $op === '') {
            return;
        }
        $meta = (array)($row[self::META_KEY] ?? []);
        $fields = (array)($meta['fields'] ?? []);
        $fields[$field] = [
            'op'    => $op,
            'model' => $model,
            'at'    => gmdate(DATE_ATOM),
        ];
        $meta['fields'] = $fields;
        $row[self::META_KEY] = $meta;
    }

    /**
     * Campi dell'ITEM del contract prodotti da operazioni generative, ordinati e
     * deduplicati. Vuoto = niente da marcare.
     *
     * @param array<string,mixed> $row
     * @return list<string>
     */
    public static function generativeFields(array $row): array
    {
        $out = [];
        foreach (self::entries($row) as $field => $e) {
            if (!self::isGenerative((string)($e['op'] ?? ''))) {
                continue;
            }
            $out[self::CONTRACT_FIELD[$field] ?? $field] = true;
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    /**
     * Operazioni generative distinte (op+model), per l'audit trail dell'item.
     *
     * @param array<string,mixed> $row
     * @return list<array{op:string,model:string,at:string}>
     */
    public static function generativeOps(array $row): array
    {
        $seen = [];
        foreach (self::entries($row) as $e) {
            $op = (string)($e['op'] ?? '');
            if (!self::isGenerative($op)) {
                continue;
            }
            $model = (string)($e['model'] ?? '');
            $key = $op . '|' . $model;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = [
                'op'    => $op,
                'model' => $model,
                'at'    => (string)($e['at'] ?? ''),
            ];
        }
        ksort($seen);
        return array_values($seen);
    }

    public static function hasGenerative(array $row): bool
    {
        return self::generativeFields($row) !== [];
    }

    /**
     * Blocco `ai` da innestare nell'item del contract, oppure null se la riga
     * non contiene nulla di generato.
     *
     * `human_reviewed` è un fatto, non una decorazione: l'inserimento avviene
     * solo dopo lo step di revisione del docente in PDF-Import. È l'elemento su
     * cui poggia l'eccezione dell'art. 50(4), 2º comma (controllo editoriale).
     *
     * @param array<string,mixed> $row
     * @return array{generated:bool,fields:list<string>,ops:list<array>,human_reviewed:bool}|null
     */
    public static function itemBlock(array $row): ?array
    {
        $fields = self::generativeFields($row);
        if ($fields === []) {
            return null;
        }
        return [
            'generated'      => true,
            'fields'         => $fields,
            'ops'            => self::generativeOps($row),
            'human_reviewed' => true,
        ];
    }

    /**
     * Voci di provenienza della riga, campo → {op, model, at}.
     *
     * @param array<string,mixed> $row
     * @return array<string,array<string,mixed>>
     */
    private static function entries(array $row): array
    {
        $meta = $row[self::META_KEY] ?? null;
        if (!is_array($meta)) {
            return [];
        }
        $fields = $meta['fields'] ?? null;
        if (!is_array($fields)) {
            return [];
        }
        $out = [];
        foreach ($fields as $field => $e) {
            if (is_string($field) && is_array($e)) {
                $out[$field] = $e;
            }
        }
        return $out;
    }
}
