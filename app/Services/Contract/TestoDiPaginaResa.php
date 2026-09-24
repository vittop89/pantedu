<?php

declare(strict_types=1);

namespace App\Services\Contract;

/**
 * Riconosce i pezzi di **pagina resa** finiti dentro un contratto.
 *
 * Il contratto è la sorgente: testo, formule, sorgente TikZ. Quello che il
 * browser fabbrica per mostrarlo — l'SVG compilato, il riquadro rosso quando
 * la compilazione non riesce, lo `<script type="text/tikz">` prima che il
 * client lo sostituisca — non deve tornare indietro. Se torna, la sorgente
 * sparisce e non si ricostruisce: un SVG non ridiventa il TikZ che l'ha
 * prodotto, e il riquadro d'errore non porta niente.
 *
 * È successo il 18 settembre 2026 sulla verifica 75 (esercizi 14 e 16) e,
 * prima, sulla 962: il difetto era nel serializzatore dell'editor ed è
 * corretto (js/modules/editor/campo-in-blocchi.js,
 * js/modules/editor/inline-blocks-markers.js,
 * js/modules/editor/tikz-render-client.js). Questa classe è la seconda rete:
 * se un giorno il serializzatore si rompe di nuovo, o se arriva una scrittura
 * da un'altra strada, la si vede prima che tocchi il disco — non tre giorni
 * dopo in un contratto.
 *
 * Due usi:
 *   - `ContractRepository::save()` rifiuta la scrittura che INTRODUCE uno di
 *     questi marcatori (`comparsi()`);
 *   - `tools/ops/diagnostica.php` conta i contratti che già ne portano
 *     (`trova()`).
 */
final class TestoDiPaginaResa
{
    /**
     * Le chiavi il cui valore è per mestiere markup o dati opachi, e che
     * quindi non si esaminano:
     *
     *   - `script` di un blocco `tikz`: è sorgente TeX, e un blocco tikz con
     *     il suo sorgente è esattamente la cosa giusta;
     *   - `svg` di un blocco `geogebra`: lì l'SVG ci va, è il grafico salvato
     *     insieme allo stato `.ggb` che lo rigenera;
     *   - `ggb_b64` e `data_template_data`: dati codificati.
     */
    private const CHIAVI_ESENTI = ['script', 'svg', 'ggb_b64', 'data_template_data'];

    /**
     * I marcatori, con il nome che finisce nel messaggio d'errore.
     *
     * @return array<string, string> nome → espressione regolare
     */
    public static function marcatori(): array
    {
        return [
            // Il riquadro rosso di js/modules/editor/tikz-render-client.js
            // (renderAll, ramo catch) salvato come contenuto.
            "il riquadro d'errore TikZ" => '/\[TikZ render error\]|fm-tikz-error/i',
            // Lo <script type="text/tikz"> serializzato come testo: capita
            // quando il riconoscitore dei blocchi non lo riconosce (per anni
            // pretendeva `type` come primo attributo, e in produzione il
            // primo attributo era il nonce della CSP, fino al 23/9/2026).
            'uno <script type="text/tikz"> come testo' => '/<script\b[^>]*text\/tikz/i',
            // L'SVG compilato al posto della sua sorgente.
            //
            // Si riconosce dai segni che ci mette il CLIENT, non dal tag
            // `<svg`. Un `<svg` qualunque non basta: il contenuto dei blocchi
            // non passa da HtmlSanitizer prima del salvataggio (lo chiama
            // ContractRenderer in fase di resa, non ContractRepository::save),
            // quindi il testo del docente arriva qui intatto — e un quesito di
            // Informatica che dice «osserva questo codice: <svg …>» si sarebbe
            // visto rifiutare il salvataggio con un messaggio che parla di
            // figure TikZ. Un controllo che blocca il lavoro vero è peggio del
            // guasto che evita.
            //
            // I due segni, e sono entrambi del client:
            //   1. gli attributi che `renderAll` appende all'elemento che
            //      prende il posto dello <script> (tikz-render-client.js,
            //      righe 451-461: hash, source, srckey, tagopen, body);
            //   2. il prefisso degli `id` interni, che `renameSvgIds` riscrive
            //      in `tk<6 esadecimali>_<contatore>_<id originale>` per non
            //      far collidere due SVG nella stessa pagina. Resta anche se
            //      gli attributi si perdono per strada, e nessuno lo scrive a
            //      mano.
            'un <svg> reso' => '/<svg\b[^>]*\bdata-tikz-(?:body|tagopen|hash|srckey|source)\s*='
                . '|\bid\s*=\s*["\']tk[0-9a-f]{0,6}_\d+_/i',
            // Gli attributi che il client appende all'elemento che prende il
            // posto dello <script>: nel contratto non ci sono mai stati.
            // Questo prende anche il `<div>` che avvolge l'SVG e il riquadro
            // d'errore, che non sono un tag `<svg>`.
            'gli attributi data-tikz-* del client' => '/\bdata-tikz-(body|tagopen|hash|srckey)\b/i',
        ];
    }

    /**
     * I punti del contratto che portano testo di pagina resa.
     *
     * @param  mixed                          $dato    il contratto, o un pezzo
     * @param  string                         $percorso da dove si è partiti
     * @return list<array{percorso: string, marcatore: string, testo: string}>
     */
    public static function trova(mixed $dato, string $percorso = ''): array
    {
        $trovati = [];
        self::cammina($dato, $percorso, $trovati);
        return $trovati;
    }

    /**
     * I marcatori che il contratto nuovo porta e quello vecchio non portava.
     *
     * Si confronta il TESTO, non il percorso: spostare un quesito non deve
     * far scattare niente, e un contratto già rovinato deve restare
     * modificabile — altrimenti la rete impedirebbe proprio di ripararlo.
     *
     * @param  array<mixed>|null $prima il contratto com'era in archivio (null = nuovo)
     * @param  array<mixed>      $dopo  il contratto che si vuole scrivere
     * @return list<array{percorso: string, marcatore: string, testo: string}>
     */
    public static function comparsi(?array $prima, array $dopo): array
    {
        $nuovi = self::trova($dopo);
        if ($nuovi === [] || $prima === null) {
            return $nuovi;
        }
        $gia = [];
        foreach (self::trova($prima) as $v) {
            $gia[$v['testo']] = true;
        }
        return array_values(array_filter($nuovi, static fn(array $v): bool => !isset($gia[$v['testo']])));
    }

    /**
     * @param list<array{percorso: string, marcatore: string, testo: string}> $trovati
     */
    private static function cammina(mixed $dato, string $percorso, array &$trovati): void
    {
        if (\is_string($dato)) {
            foreach (self::marcatori() as $nome => $regola) {
                if (preg_match($regola, $dato) === 1) {
                    $trovati[] = [
                        'percorso'  => $percorso === '' ? '(radice)' : $percorso,
                        'marcatore' => $nome,
                        'testo'     => $dato,
                    ];
                    return;
                }
            }
            return;
        }
        if (!\is_array($dato)) {
            return;
        }
        foreach ($dato as $chiave => $valore) {
            if (\is_string($chiave) && \in_array($chiave, self::CHIAVI_ESENTI, true)) {
                continue;
            }
            self::cammina($valore, $percorso === '' ? (string)$chiave : "$percorso.$chiave", $trovati);
        }
    }

    /**
     * Il messaggio che vede chi ha provato a salvare. In italiano: lo legge
     * un docente, non un registro.
     *
     * @param list<array{percorso: string, marcatore: string, testo: string}> $trovati
     */
    public static function spiegazione(array $trovati): string
    {
        $primo = $trovati[0] ?? null;
        $dove = $primo !== null ? " (in «{$primo['percorso']}»: {$primo['marcatore']})" : '';
        return 'Il salvataggio è stato rifiutato: il contenuto contiene pezzi della pagina '
            . 'già disegnata invece della sua sorgente' . $dove . '. '
            . 'Succede quando una figura TikZ non si è compilata: ricarica la pagina, '
            . 'aspetta che le figure compaiano e riprova. Il contenuto salvato non è stato toccato.';
    }
}
