<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Services\PdfImport\Provider\ProviderInterface;

/**
 * Phase PDF-Import — estrazione vision di UNA pagina → item grezzi.
 *
 * Riceve i byte PNG di una pagina, invoca il provider con un system prompt IT
 * (fidato), e fa il parsing robusto del JSON prodotto (output del modello =
 * SEMPRE dato non fidato). Defense-in-depth: i campi testuali estratti vengono
 * passati da PiiMasker prima di essere restituiti/persistiti (LLM02), così
 * eventuali PII non finiscono in chiamate LLM downstream (enrichment/soluzioni).
 */
final class ExtractionPipeline
{
    /** System prompt IT (fidato) — definisce lo schema di estrazione. */
    public const SYSTEM_PROMPT = <<<TXT
Sei un estrattore di esercizi da pagine PDF di libri/verifiche scolastiche di
matematica e fisica. Estrai OGNI esercizio visibile nella pagina e rispondi
ESCLUSIVAMENTE con un array JSON valido (nessun testo prima o dopo, nessun
markdown). Per ciascun esercizio includi questi campi:

- "number": numero/identificativo dell'esercizio come stampato (stringa)
- "page_number": il numero di pagina STAMPATO sul libro (es. l'etichetta
  "pag. 1164" o il numero a piè/inizio pagina) che precede l'esercizio. Propaga
  lo STESSO numero a tutti gli esercizi fino alla successiva etichetta di pagina.
  NON usare l'indice del file/della scansione. "" solo se nessun numero è visibile.
- "badge_color": colore del badge/numero se presente: "red"|"blue"|"green"|"orange" o ""
- "difficulty": sotto/accanto al numero dell'esercizio c'è una fila di 3 indicatori
  (pallini o quadratini). Conta SOLO quelli ATTIVI = COLORATI (rossi/pieni); quelli
  SPENTI = GRIGI o VUOTI (solo contorno) NON contano. Esempi: rosso-grigio-grigio = 1,
  rosso-rosso-grigio = 2, rosso-rosso-rosso = 3. difficulty = numero di indicatori
  COLORATI (1-3). Guarda bene il COLORE di ciascuno dei 3: distingui il rosso/pieno
  dal grigio/spento; NON contare i grigi e NON mettere 3 "di default" (molti
  esercizi sono 1 o 2). 0 solo se non c'è alcuna fila di indicatori.
- "badge_box": riquadro che racchiude IL NUMERO dell'esercizio E la fila di pallini
  di difficoltà sotto/accanto, come [x0,y0,x1,y1] in FRAZIONI 0-1 della pagina
  (x0,y0 = angolo alto-sinistra; x1,y1 = basso-destra). Stringi il riquadro attorno
  a numero+pallini (poco margine). [] se non visibile.
- "text": testo dell'esercizio. Per le formule usa SOLO i delimitatori LaTeX
  \\(...\\) (inline) e \\[...\\] (display). NON usare MAI il dollaro ($...$ o $$...$$)
- "shared_instruction": eventuale consegna condivisa del blocco (stringa, "" se assente)
- "container_name": titolo/sezione del contenitore se presente (stringa, "" se assente)
- "sub_items": lista di {"letter": "...", "text": "..."}; lettere MAIUSCOLE
  (A,B,C,D) = opzioni a scelta multipla; minuscole (a,b,c) = sotto-problemi o
  affermazioni Vero/Falso. Lista vuota se non ci sono sotto-voci.
- "has_figure": true/false se l'esercizio contiene una figura/grafico
- "figure_description": breve descrizione della figura (stringa, "" se assente)
- "solution": soluzione se stampata sulla pagina (stringa, "" se assente)

REGOLE:
1. NON inventare esercizi non presenti. NON duplicare la consegna condivisa
   dentro ogni "text".
2. NON trascrivere dati personali (nomi di studenti, codici fiscali, email):
   ometti completamente eventuali dati anagrafici.
3. Mantieni le formule in LaTeX corretto con delimitatori \\(...\\)/\\[...\\]
   (mai $). Non aggiungere commenti.
4. La risposta DEVE essere SOLO un array JSON: inizia con [ e finisci con ].
   Niente testo introduttivo, niente ```; usa virgolette doppie e nessuna virgola
   finale (JSON valido).
TXT;

    /**
     * Estrae gli item da una pagina.
     *
     * @return array{items:list<array<string,mixed>>, model:string,
     *   tokens_in:int, tokens_out:int, pii_redactions:int, raw_ok:bool}
     */
    public function extractPage(ProviderInterface $client, string $pagePng, int $pageNumber): array
    {
        $userPrompt = "Estrai gli esercizi dalla pagina (indice $pageNumber). "
            . 'Rispondi SOLO con l\'array JSON, senza altro testo.';

        $res  = $client->extract($pagePng, OperationPrompts::resolve('extraction'), $userPrompt);
        $text = (string)($res['text'] ?? '');

        $parsed = self::parseJsonArray($text);
        $rawOk  = $parsed !== null;
        $items  = $parsed ?? [];

        // Normalizza + maschera PII sui campi testuali (defense-in-depth).
        $totalRedactions = 0;
        $norm = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $norm[] = $this->normalizeItem($it, $pageNumber, $totalRedactions);
        }

        return [
            'items'          => $norm,
            'model'          => (string)($res['model'] ?? ''),
            'tokens_in'      => (int)($res['tokens_in'] ?? 0),
            'tokens_out'     => (int)($res['tokens_out'] ?? 0),
            'pii_redactions' => $totalRedactions,
            'raw_ok'         => $rawOk,
        ];
    }

    // ── Fase 1 (legacy NumberScanner): passata vision MINIMALE che legge SOLO i
    //    numeri dei badge → task piccolo = meno allucinazioni sui numeri. Il
    //    risultato corregge i numeri dell'estrazione vera (reconcileNumbers). ──
    public const SCAN_SYSTEM = <<<TXT
Sei un lettore di badge numerici su pagine di libri scolastici di matematica e
fisica. Il tuo UNICO compito: trovare i NUMERI dei BADGE ESERCIZIO visibili.
Un badge esercizio è un numero in un cerchio o rettangolo COLORATO (rosso, blu,
verde, arancione) a sinistra del testo di ogni esercizio. Di solito numeri a 2-4
cifre.
NON includere:
- numeri di PAGINA (in basso/alto, piccoli, es. 1, 2, 3…)
- numeri di sotto-punto o elenco (a), b), 1., 2. …)
- numeri in apice/pedice (note, esponenti)
- numeri DENTRO il testo degli esercizi
Elenca i badge dall'alto al basso nell'ordine in cui appaiono. Se lo stesso numero
badge appare più volte (più sezioni), includilo tante volte quante appare.
Rispondi SOLO con un array JSON di stringhe: ["64","65","66"]. Se nessun badge: [].
Nessun testo aggiuntivo.
TXT;

    /**
     * @return array{numbers:list<string>, tokens_in:int, tokens_out:int}
     */
    public function scanNumbers(ProviderInterface $client, string $pagePng, int $pageNumber): array
    {
        $res = $client->extract($pagePng, OperationPrompts::resolve('numbers'), "Leggi i numeri dei badge (pagina $pageNumber).");
        // NB: decodifica diretta (l'array è di STRINGHE ["7","11",…]); parseJsonArray
        // scarterebbe gli elementi non-array. Fence-strip+fallback via LlmJson.
        $parsed = LlmJson::decodeArray((string)($res['text'] ?? ''));
        if (isset($parsed['numbers']) && is_array($parsed['numbers'])) {
            $parsed = $parsed['numbers'];
        }
        $nums = [];
        foreach ((is_array($parsed) ? $parsed : []) as $n) {
            if (is_array($n)) {
                $n = $n['number'] ?? ($n['n'] ?? '');
            }
            $s = trim((string)$n);
            if ($s !== '' && preg_match('/^\d{1,6}$/', $s)) {
                $nums[] = $s;
            }
        }
        return [
            'numbers'    => $nums,
            'tokens_in'  => (int)($res['tokens_in'] ?? 0),
            'tokens_out' => (int)($res['tokens_out'] ?? 0),
        ];
    }

    /**
     * Allinea per ORDINE i numeri estratti a quelli scansionati. Best-effort:
     * corregge SOLO se i conteggi combaciano (evita disallineamenti azzardati).
     *
     * @param list<array<string,mixed>> $items
     * @param list<string> $scanned
     * @return list<array<string,mixed>>
     */
    public static function reconcileNumbers(array $items, array $scanned): array
    {
        $scanned = array_values(array_filter(
            array_map(static fn($s) => trim((string)$s), $scanned),
            static fn($s) => $s !== ''
        ));
        if ($items === [] || count($scanned) !== count($items)) {
            return $items;
        }
        $i = 0;
        foreach ($items as &$it) {
            if (is_array($it)) {
                $it['number'] = $scanned[$i];
            }
            $i++;
        }
        unset($it);
        return $items;
    }

    /**
     * Normalizza un item grezzo allo schema canonico + maschera PII.
     */
    private function normalizeItem(array $it, int $pageNumber, int &$redactions): array
    {
        $maskStr = static function (mixed $v) use (&$redactions): string {
            $s = is_string($v) ? $v : '';
            if ($s === '') {
                return '';
            }
            $c = 0;
            $s = PiiMasker::mask($s, $c);
            $redactions += $c;
            return $s;
        };

        $subItems = [];
        foreach ((array)($it['sub_items'] ?? []) as $sub) {
            if (!is_array($sub)) {
                continue;
            }
            $subItems[] = [
                'letter' => (string)($sub['letter'] ?? ''),
                'text'   => $maskStr($sub['text'] ?? ''),
            ];
        }

        $diff = (int)($it['difficulty'] ?? 0);
        $diff = max(0, min(4, $diff));

        $color = strtolower((string)($it['badge_color'] ?? ''));
        if (!in_array($color, ['red', 'blue', 'green', 'orange', ''], true)) {
            $color = '';
        }

        // badge_box: [x0,y0,x1,y1] frazioni 0-1, validato (per il crop-zoom difficoltà).
        $box = $it['badge_box'] ?? null;
        $badgeBox = [];
        if (is_array($box) && count($box) === 4) {
            $b = array_map(static fn($v) => max(0.0, min(1.0, (float)$v)), array_values($box));
            if ($b[2] > $b[0] && $b[3] > $b[1]) {
                $badgeBox = $b;
            }
        }

        return [
            'number'             => (string)($it['number'] ?? ''),
            'page_number'        => (string)($it['page_number'] ?? (string)$pageNumber),
            'badge_color'        => $color,
            'difficulty'         => $diff,
            'badge_box'          => $badgeBox,
            'text'               => $maskStr($it['text'] ?? ''),
            'shared_instruction' => $maskStr($it['shared_instruction'] ?? ''),
            'container_name'     => (string)($it['container_name'] ?? ''),
            'sub_items'          => $subItems,
            'has_figure'         => (bool)($it['has_figure'] ?? false),
            'figure_description' => $maskStr($it['figure_description'] ?? ''),
            'solution'           => $maskStr($it['solution'] ?? ''),
            '_source_page'       => $pageNumber,
        ];
    }

    /**
     * Parsing robusto: rimuove eventuali code-fence, isola il primo array JSON
     * (o oggetto con chiave "exercises"), decodifica. Ritorna null se illeggibile.
     *
     * @return list<array<string,mixed>>|null
     */
    public static function parseJsonArray(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Strip code fences ```json ... ```
        $text = (string)preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            // Fallback: isola il primo blocco [...] o {...}.
            $start = strpos($text, '[');
            $altStart = strpos($text, '{');
            if ($start === false || ($altStart !== false && $altStart < $start)) {
                $start = $altStart;
            }
            if ($start === false) {
                return null;
            }
            $open  = $text[$start];
            $close = $open === '[' ? ']' : '}';
            $end   = strrpos($text, $close);
            if ($end === false || $end <= $start) {
                return null;
            }
            $slice = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($slice, true);
            if (!is_array($decoded)) {
                return null;
            }
        }

        // Forma {"exercises":[...]} (o sinonimi) → estrai la lista. Alcuni modelli
        // avvolgono l'array con chiavi diverse.
        foreach (['exercises', 'esercizi', 'items', 'data', 'result', 'results'] as $wrap) {
            if (isset($decoded[$wrap]) && is_array($decoded[$wrap])) {
                $decoded = $decoded[$wrap];
                break;
            }
        }
        // Singolo oggetto esercizio → wrap.
        if ($decoded !== [] && array_keys($decoded) !== range(0, count($decoded) - 1)) {
            $decoded = [$decoded];
        }
        return array_values(array_filter($decoded, 'is_array'));
    }
}
