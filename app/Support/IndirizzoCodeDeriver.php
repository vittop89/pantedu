<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Deriva il codice di un indirizzo dalla descrizione MIUR.
 *
 * Il dataset ALUSECGRADOINDSTA ("Studenti scuola secondaria di secondo grado
 * per indirizzo") espone la colonna `Indirizzo` con valori discorsivi:
 * "liceo scientifico", "liceo scientifico scienze applicate",
 * "amministrazione finanza e marketing". Il curriculum della piattaforma vuole
 * invece un codice `^[A-Z]{3,6}$`.
 *
 * Regole:
 *   - una sola parola significativa  → prime tre lettere   (scientifico → SCI)
 *   - due o piu' parole significative → iniziali            (amministrazione
 *     finanza e marketing → AFM; scientifico scienze applicate → SSA)
 *   - iniziali troppo corte → si allunga attingendo alla prima parola
 *     (scienze umane → SCU)
 *   - collisione dentro la STESSA scuola → si allunga finche' non e' univoco
 *
 * La distinzione fra "liceo sportivo" (SPO) e "liceo scientifico a curvatura
 * sportiva" (SCS) e' il caso che rende necessario tutto questo: due indirizzi
 * diversi della stessa scuola che condividono parole e che con le sole prime
 * tre lettere collasserebbero.
 */
final class IndirizzoCodeDeriver
{
    /**
     * Parole che non distinguono un indirizzo dall'altro. "liceo" e "istituto"
     * sono contenitori — il grado sta gia' in TipoPercorso — e le preposizioni
     * non portano informazione: e' il motivo per cui "amministrazione finanza
     * E marketing" fa AFM e non AFEM.
     */
    private const STOPWORDS = [
        'liceo', 'licei', 'istituto', 'istituti', 'indirizzo', 'opzione',
        'percorso', 'settore', 'articolazione', 'nuovo', 'nuovi',
        'a', 'ad', 'e', 'ed', 'di', 'del', 'dello', 'della', 'dei', 'degli',
        'delle', 'da', 'dal', 'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno',
        'una', 'con', 'per', 'in', 'su', 'ed', 'al', 'allo', 'alla',
    ];

    private const MIN_LEN = 3;
    private const MAX_LEN = 6;

    /**
     * Parole significative di una descrizione MIUR, normalizzate.
     *
     * @return list<string>
     */
    public static function words(string $descrizione): array
    {
        $s = mb_strtolower(trim($descrizione), 'UTF-8');
        $s = strtr($s, [
            'à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i',
            'í' => 'i', 'ò' => 'o', 'ó' => 'o', 'ù' => 'u', 'ú' => 'u',
        ]);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';

        $out = [];
        foreach (preg_split('/\s+/', trim($s)) ?: [] as $w) {
            if ($w === '' || in_array($w, self::STOPWORDS, true)) {
                continue;
            }
            $out[] = $w;
        }
        // Tutto stopword (es. "liceo" da solo): meglio la descrizione grezza
        // che nessun codice.
        if ($out === []) {
            foreach (preg_split('/\s+/', trim($s)) ?: [] as $w) {
                if ($w !== '') {
                    $out[] = $w;
                }
            }
        }
        return $out;
    }

    /**
     * Codice base, senza tenere conto di eventuali collisioni.
     */
    public static function base(string $descrizione): string
    {
        $words = self::words($descrizione);
        if ($words === []) {
            return '';
        }

        if (count($words) === 1) {
            return self::clip(strtoupper(substr($words[0], 0, self::MIN_LEN)));
        }

        $code = '';
        foreach ($words as $w) {
            $code .= $w[0];
        }
        // Due parole corte danno due lettere: si completa dalla prima, cosi'
        // "scienze umane" diventa SCU e non SU.
        if (strlen($code) < self::MIN_LEN) {
            $first = $words[0];
            $need = self::MIN_LEN - strlen($code);
            $code = substr($first, 0, 1 + $need) . substr($code, 1);
        }
        return self::clip(strtoupper($code));
    }

    /**
     * Codice univoco rispetto a quelli gia' assegnati nella stessa scuola.
     *
     * @param list<string> $giaUsati codici gia' presenti (case-insensitive)
     */
    public static function unique(string $descrizione, array $giaUsati): string
    {
        $taken = array_map('strtoupper', $giaUsati);
        $code = self::base($descrizione);
        if ($code === '' || !in_array($code, $taken, true)) {
            return $code;
        }

        // Prima strada: le iniziali, che distinguono cio' che le prime tre
        // lettere confondono (scientifico vs scientifico scienze applicate).
        $words = self::words($descrizione);
        if (count($words) > 1) {
            $ini = '';
            foreach ($words as $w) {
                $ini .= $w[0];
            }
            $ini = self::clip(strtoupper($ini));
            if ($ini !== '' && strlen($ini) >= self::MIN_LEN && !in_array($ini, $taken, true)) {
                return $ini;
            }
        }

        // Seconda strada: si allunga con le lettere successive della prima
        // parola, poi si accetta un suffisso numerico solo come ultima risorsa.
        $first = strtoupper($words[0] ?? '');
        for ($len = self::MIN_LEN + 1; $len <= self::MAX_LEN; $len++) {
            $cand = substr($first, 0, $len);
            if (strlen($cand) >= self::MIN_LEN && !in_array($cand, $taken, true)) {
                return $cand;
            }
        }
        for ($n = 2; $n <= 9; $n++) {
            $cand = substr($code, 0, self::MAX_LEN - 1) . $n;
            if (!in_array($cand, $taken, true)) {
                return $cand;
            }
        }
        return $code;
    }

    private static function clip(string $s): string
    {
        return substr($s, 0, self::MAX_LEN);
    }
}
