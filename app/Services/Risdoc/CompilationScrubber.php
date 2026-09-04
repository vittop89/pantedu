<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

/**
 * Scarta, prima del salvataggio, i campi di una compilazione risdoc che per
 * nome si riferiscono a uno studente o a un genitore (2026-09-04).
 *
 * PERCHE'
 *   Negli scenari senza account studente (1 e 2, e il 3 in modalita'
 *   Anonima) la piattaforma non deve conservare dati di studenti: lo dicono
 *   i Termini (§2.4) e la nota al DPO. Un modello pero' puo' avere un campo
 *   "Nome dello studente" — ne aveva uno il Modulo di autorizzazione, tolto —
 *   e un divieto scritto non impedisce di compilarlo. Qui il divieto diventa
 *   un fatto: il valore di quei campi non arriva al database.
 *
 * COSA
 *   Riconosce i campi dal `name` (textField, select, table, checkboxGroup e
 *   le voci di `fields`) e dalle chiavi di `state`: se il nome contiene una
 *   delle radici in PATTERNS, il valore viene svuotato e il nome finisce
 *   nell'elenco `scrubbed` restituito al client, che puo' dirlo al docente.
 *   Il testo libero non e' analizzato: la' resta la responsabilita' del
 *   docente (R20 della DPIA).
 *
 *   Pura: nessun accesso a sessione o DB. Chi decide SE applicarla e'
 *   CompilationController, in base allo scenario.
 */
final class CompilationScrubber
{
    /** Radici (minuscole) che identificano un campo su studente o genitore. */
    public const PATTERNS = [
        'studente', 'student', 'alunn', 'allievo', 'allieva',
        'genitor', 'parent', 'tutore', 'tutor',
        'nascita', 'birth', 'codice_fiscale', 'codicefiscale', 'fiscal',
    ];

    /**
     * @param array<string,mixed> $data  {state, fields, body_pt, ...} come salvato dal client
     * @return array{data: array<string,mixed>, scrubbed: list<string>}
     */
    public static function scrub(array $data): array
    {
        $scrubbed = [];

        if (isset($data['fields']) && \is_array($data['fields'])) {
            foreach ($data['fields'] as $name => $value) {
                if (self::matches((string)$name)) {
                    $data['fields'][$name] = self::emptyLike($value);
                    $scrubbed[] = (string)$name;
                }
            }
        }

        if (isset($data['state']) && \is_array($data['state'])) {
            foreach (array_keys($data['state']) as $key) {
                if (self::matches((string)$key)) {
                    unset($data['state'][$key]);
                    $scrubbed[] = 'state.' . $key;
                }
            }
        }

        if (isset($data['body_pt']) && \is_array($data['body_pt'])) {
            $data['body_pt'] = self::walk($data['body_pt'], $scrubbed);
        }

        return ['data' => $data, 'scrubbed' => array_values(array_unique($scrubbed))];
    }

    /** Un nome di campo si riferisce a uno studente o a un genitore? */
    public static function matches(string $name): bool
    {
        $n = strtolower($name);
        if ($n === '') {
            return false;
        }
        foreach (self::PATTERNS as $p) {
            if (str_contains($n, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<mixed> $blocks
     * @param list<string> $scrubbed
     * @return list<mixed>
     */
    private static function walk(array $blocks, array &$scrubbed): array
    {
        foreach ($blocks as $i => $b) {
            if (!\is_array($b)) {
                continue;
            }
            $name = isset($b['name']) && \is_string($b['name']) ? $b['name'] : '';
            $type = (string)($b['_type'] ?? '');
            if ($name !== '' && self::matches($name)) {
                switch ($type) {
                    case 'textField':
                    case 'select':
                        $b['value'] = '';
                        $scrubbed[] = $name;
                        break;
                    case 'table':
                        $b['rows'] = [];
                        $scrubbed[] = $name;
                        break;
                    case 'checkboxGroup':
                        if (isset($b['items']) && \is_array($b['items'])) {
                            foreach ($b['items'] as $j => $it) {
                                if (\is_array($it) && isset($it['state'])) {
                                    $b['items'][$j]['state'] = '';
                                }
                            }
                        }
                        $scrubbed[] = $name;
                        break;
                    default:
                        break;
                }
            }
            // accordion: i pannelli hanno il proprio body_pt.
            if ($type === 'accordion' && isset($b['items']) && \is_array($b['items'])) {
                foreach ($b['items'] as $j => $it) {
                    if (\is_array($it) && isset($it['body_pt']) && \is_array($it['body_pt'])) {
                        $b['items'][$j]['body_pt'] = self::walk($it['body_pt'], $scrubbed);
                    }
                }
            }
            $blocks[$i] = $b;
        }
        return $blocks;
    }

    private static function emptyLike(mixed $value): mixed
    {
        return match (true) {
            \is_bool($value)  => false,
            \is_array($value) => [],
            default           => '',
        };
    }
}
