<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\ClsNormalizer;

/**
 * Codice classe: anno di corso («3») oppure sezione («3A»).
 *
 * LA REGOLA, scritta in un posto solo (piano docs/plans/classi-credenziali-scenari.md, A):
 *
 *   «3»  e' l'anno di corso — vale per tutte le sue sezioni, anche quelle future
 *   «3A» e' la sezione      — vale solo per se'
 *
 * Quindi chi guarda da «3A» vede cio' che e' etichettato «3A» e cio' che e'
 * etichettato «3»; chi guarda da «3» vede solo «3». L'asimmetria e' voluta:
 * senza una sezione dichiarata non si riceve il materiale di una sezione a caso.
 *
 * E' nata perche' la regola viveva gia' negli incarichi (TeacherSectionService)
 * mentre il filtro dei contenuti confrontava la classe alla lettera: la
 * credenziale «3A» non vedeva i contenuti «3», cioe' tutti quelli scritti
 * finora, dato che i docenti etichettano per anno. Ora incarichi, gate di
 * visibilita' e repository leggono la regola da qui.
 *
 * Puro: nessun DB, nessuna sessione. I codici legacy con suffisso («2s») si
 * riducono all'anno come fa ClsNormalizer, cosi' un vecchio segnalibro non
 * diventa la sezione «S».
 */
final class ClassCode
{
    /** Anno + sezione facoltativa: "1", "1A", "3BS". */
    public const PATTERN = '/^([1-9])([A-Z0-9]{0,5})$/';

    /** Trim e riduzione del suffisso legacy; il caso resta quello dato. */
    public static function normalize(?string $classe): string
    {
        $c = trim((string)$classe);
        return $c === '' ? '' : ClsNormalizer::shrink($c);
    }

    /** Anno di corso («1A» → «1»), null se il codice non e' riconosciuto. */
    public static function anno(?string $classe): ?string
    {
        return preg_match(self::PATTERN, strtoupper(self::normalize($classe)), $m) ? $m[1] : null;
    }

    /**
     * «3» si', «3A» no. Dal 15/9/2026 (ADR-042) un anno esiste una volta per
     * corso: per risolverlo nel catalogo serve anche l'indirizzo.
     */
    public static function isAnno(?string $classe): bool
    {
        return preg_match(self::PATTERN, strtoupper(self::normalize($classe)), $m) === 1 && $m[2] === '';
    }

    /** «3A» si', «3» no. */
    public static function isSezione(?string $classe): bool
    {
        return preg_match(self::PATTERN, strtoupper(self::normalize($classe)), $m) === 1 && $m[2] !== '';
    }

    /**
     * Un'etichetta (di un incarico o di un contenuto) raggiunge chi guarda da $classe?
     *
     * Confronto senza distinzione di maiuscole: nei cataloghi convivono «3B» e «1b».
     */
    public static function covers(?string $etichetta, ?string $classe): bool
    {
        $e = strtoupper(self::normalize($etichetta));
        $c = strtoupper(self::normalize($classe));
        if ($e === '' || $c === '') {
            return false;
        }
        if ($e === $c) {
            return true;
        }
        if (!preg_match(self::PATTERN, $e, $me) || !preg_match(self::PATTERN, $c, $mc)) {
            return false;
        }
        // Stesso anno, e l'etichetta non specifica la sezione: copre tutte.
        return $me[1] === $mc[1] && $me[2] === '';
    }

    /**
     * Etichette che raggiungono chi guarda da $classe: la classe stessa e, se e'
     * una sezione, il suo anno. E' l'insieme da mettere in un IN (...) SQL.
     *
     * @return list<string>
     */
    public static function covering(?string $classe): array
    {
        $c = self::normalize($classe);
        if ($c === '') {
            return [];
        }
        $anno = self::anno($c);
        return ($anno !== null && strtoupper($c) !== $anno) ? [$c, $anno] : [$c];
    }
}
