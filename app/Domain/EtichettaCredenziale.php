<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * L'etichetta di una credenziale di classe, composta dal server (ADR-044).
 *
 *     {CLASSE}_{INDIRIZZO}_{MATERIE}[_{AGGIUNTA}]
 *
 * - CLASSE e INDIRIZZO sono quelli del perimetro della credenziale, cioè quelli
 *   che il server ha già risolto nel catalogo della scuola: «3B_SCI», «3_SCI».
 *   Una credenziale per tutte le classi comincia con «TUTTE»; una delimitata al
 *   solo indirizzo (possibile via API) comincia con l'indirizzo.
 * - MATERIE sono le sigle scelte dal docente fra le sue materie spuntate
 *   nell'istituto, in ordine alfabetico e separate da «-»: «FIS-GEO-MAT». Senza
 *   materie la parte si omette.
 * - AGGIUNTA è facoltativa: lettere senza accenti, cifre e trattino, al massimo
 *   dodici caratteri, portata in maiuscolo. Serve a distinguere due credenziali
 *   dello stesso docente con lo stesso perimetro («GRUPPO-B», «POMERIGGIO»).
 *
 * Fino al 19 settembre 2026 l'etichetta era testo libero del docente: nel
 * portachiavi dello studente poteva dichiarare una sezione che la credenziale
 * non aveva (misurato in produzione) o contenere un nome (R20 della DPIA). Qui
 * la parte strutturata viene dal perimetro vero, e un nome non ci può finire per
 * costruzione; resta solo l'aggiunta, con l'invito a non scriverne.
 *
 * Pura: nessun accesso a sessione o database. Il controllo che le materie siano
 * del docente e che l'etichetta non sia già usata sta nel repository.
 */
final class EtichettaCredenziale
{
    /** Separa le parti dell'etichetta: nessuna sigla lo contiene, l'aggiunta non lo ammette. */
    public const SEPARATORE = '_';
    /** Separa le sigle delle materie. */
    public const SEPARATORE_MATERIE = '-';
    /** Il primo pezzo di una credenziale non delimitata. */
    public const TUTTE = 'TUTTE';
    public const AGGIUNTA_MAX = 12;
    /**
     * Il pattern HTML dell'aggiunta: la vista lo stampa nell'attributo
     * `pattern`, il server lo usa come /^(?:…)$/. Un testo solo, due usi.
     */
    public const AGGIUNTA_HTML_PATTERN = '[A-Za-z0-9\-]{1,12}';
    /** Quanto ammette la colonna `label`. */
    public const LUNGHEZZA_MAX = 128;

    /**
     * Compone l'etichetta.
     *
     * @param list<string> $materie sigle delle materie, in qualunque ordine e forma
     */
    public static function componi(?string $classe, ?string $indirizzo, array $materie, ?string $aggiunta): string
    {
        $classe    = self::pulita($classe);
        $indirizzo = self::pulita($indirizzo);
        $parti = [];
        if ($classe !== null) {
            $parti[] = $classe;
            if ($indirizzo !== null) {
                $parti[] = $indirizzo;
            }
        } elseif ($indirizzo !== null) {
            $parti[] = $indirizzo;
        } else {
            $parti[] = self::TUTTE;
        }
        $sigle = self::materie($materie);
        if ($sigle !== []) {
            $parti[] = implode(self::SEPARATORE_MATERIE, $sigle);
        }
        $aggiunta = self::pulita($aggiunta);
        if ($aggiunta !== null) {
            $parti[] = $aggiunta;
        }
        return implode(self::SEPARATORE, $parti);
    }

    /**
     * Le sigle delle materie come entrano nell'etichetta: in maiuscolo, senza
     * spazi, una volta sola, in ordine alfabetico. L'ordine è deterministico:
     * due credenziali con le stesse materie hanno la stessa etichetta, e il
     * controllo dei doppioni lo vede.
     *
     * @param list<string> $materie
     * @return list<string>
     */
    public static function materie(array $materie): array
    {
        $out = [];
        foreach ($materie as $m) {
            $s = self::pulita($m);
            if ($s !== null) {
                $out[$s] = true;
            }
        }
        $sigle = array_map('strval', array_keys($out));
        sort($sigle, SORT_STRING);
        return $sigle;
    }

    /** L'aggiunta rispetta la regola: lettere senza accenti, cifre, trattino, da 1 a 12. */
    public static function aggiuntaValida(string $aggiunta): bool
    {
        return preg_match('/^(?:' . self::AGGIUNTA_HTML_PATTERN . ')$/u', $aggiunta) === 1;
    }

    private static function pulita(?string $s): ?string
    {
        $s = strtoupper(trim((string)$s));
        return $s === '' ? null : $s;
    }
}
