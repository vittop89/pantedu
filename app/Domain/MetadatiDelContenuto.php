<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;
use stdClass;

/**
 * Le regole con cui si scrivono i metadati di un contenuto del docente
 * (`teacher_content_data.metadata_json`).
 *
 * Fino al 19/9/2026 l'aggiornamento li sostituiva per intero con quello che
 * mandava il modale ✎, e il modale li ricostruiva da zero: a ogni «Salva»
 * una mappa riceveva `layout: exercises` e un `body_pt` d'esempio, perdeva
 * `mappa.href_hide` e `mappa.drawio_id`, e un esercizio perdeva `contract_key`,
 * cioè i suoi quesiti (analisi C-export-bodypt e F-metadati-modifica-mappa).
 *
 * Due regole:
 *
 *   1. la patch. Chi cambia solo alcune chiavi manda `metadata_patch`, un
 *      oggetto JSON con le sole chiavi cambiate; `null` toglie la chiave. Il
 *      server la fonde con i metadati salvati, che non devono passare dal
 *      browser: il corpo di un documento non viaggia con il modale, e un
 *      salvataggio dell'editor fatto nel frattempo non viene riscritto. Il
 *      campo `metadata`, che sostituisce tutto, resta per chi ha già in mano
 *      i metadati interi (l'editor del documento, TeacherContentAdapter).
 *   2. il tipo. Una mappa non ha `layout`, `body_pt` né `doc_roles`: è il suo
 *      file drawio o il suo link. Se arrivano, per qualunque via, si ignorano.
 *
 * I metadati salvati e la patch si leggono come oggetti, non come array: così
 * un `{}` dentro i metadati resta `{}` e non diventa `[]` riscrivendolo.
 */
final class MetadatiDelContenuto
{
    /** Le chiavi che il modale scriveva nelle mappe senza che le mappe le usino. */
    private const NON_DELLE_MAPPE = ['layout', 'body_pt', 'doc_roles'];

    /** @return list<string> le chiavi che un contenuto di questo tipo non accetta */
    public static function chiaviNonAmmesse(string $tipo): array
    {
        return $tipo === 'mappa' ? self::NON_DELLE_MAPPE : [];
    }

    /**
     * I metadati interi in arrivo, senza le chiavi che il tipo non accetta.
     *
     * @param array<mixed> $meta
     * @return array<mixed>
     */
    public static function perIlTipo(array $meta, string $tipo): array
    {
        foreach (self::chiaviNonAmmesse($tipo) as $chiave) {
            unset($meta[$chiave]);
        }
        return $meta;
    }

    /**
     * La patch dal testo JSON che arriva nella richiesta.
     *
     * @throws InvalidArgumentException se non è un oggetto JSON
     */
    public static function patchDa(string $json): stdClass
    {
        $patch = json_decode($json, false);
        if (!$patch instanceof stdClass) {
            throw new InvalidArgumentException('metadata_patch_non_valida');
        }
        return $patch;
    }

    /**
     * La patch senza le chiavi che il tipo non accetta.
     */
    public static function patchPerIlTipo(stdClass $patch, string $tipo): stdClass
    {
        $filtrata = clone $patch;
        foreach (self::chiaviNonAmmesse($tipo) as $chiave) {
            unset($filtrata->{$chiave});
        }
        return $filtrata;
    }

    /** Vero se la patch non cambia niente. */
    public static function patchVuota(stdClass $patch): bool
    {
        return get_object_vars($patch) === [];
    }

    /**
     * I metadati salvati con la patch applicata: ogni chiave della patch
     * sostituisce quella salvata, `null` la toglie, le altre restano come sono.
     *
     * @param string|null $salvati il `metadata_json` della riga
     * @return string|null il JSON da scrivere, null se non resta nessuna chiave
     */
    public static function fondi(?string $salvati, stdClass $patch): ?string
    {
        $meta = ($salvati !== null && $salvati !== '') ? json_decode($salvati, false) : null;
        if (!$meta instanceof stdClass) {
            // Metadati assenti, o non un oggetto: si parte da vuoto.
            $meta = new stdClass();
        }
        foreach (get_object_vars($patch) as $chiave => $valore) {
            if ($valore === null) {
                unset($meta->{$chiave});
            } else {
                $meta->{$chiave} = $valore;
            }
        }
        if (get_object_vars($meta) === []) {
            return null;
        }
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Un null qui cancellerebbe i metadati: meglio non scrivere niente.
            throw new \RuntimeException('metadati_non_codificabili');
        }
        return $json;
    }

    /**
     * Il corpo sfilato dai metadati, per chi lo cifra a parte (il dual-write:
     * `body_pt` va nelle colonne cifrate e non resta in chiaro). Si lavora
     * sull'oggetto, non su un array: passare da `json_decode($json, true)`
     * trasformerebbe ogni `{}` annidato in `[]` e il `json_encode` successivo
     * lo riscriverebbe così (revisione della PR #145, 20/9/2026).
     *
     * @param string|null $json i metadati, com'è l'uscita di `fondi()`
     * @return array{0: string|null, 1: string|null} i metadati senza il corpo
     *         (null se non resta nessuna chiave) e il corpo (null se non c'era)
     */
    public static function sfilaIlCorpo(?string $json): array
    {
        $meta = ($json !== null && $json !== '') ? json_decode($json, false) : null;
        if (!$meta instanceof stdClass) {
            // Niente da sfilare: i metadati restano come sono arrivati.
            return [$json, null];
        }
        if (!property_exists($meta, 'body_pt')) {
            return [$json, null];
        }
        $corpo = json_encode($meta->body_pt, JSON_UNESCAPED_UNICODE);
        unset($meta->body_pt);
        if (get_object_vars($meta) === []) {
            return [null, $corpo !== false ? $corpo : null];
        }
        $resto = json_encode($meta, JSON_UNESCAPED_UNICODE);
        if ($resto === false) {
            // Come in fondi(): un null qui cancellerebbe i metadati.
            throw new \RuntimeException('metadati_non_codificabili');
        }
        return [$resto, $corpo !== false ? $corpo : null];
    }
}
