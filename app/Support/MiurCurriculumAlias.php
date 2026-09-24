<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Decisioni umane su come leggere le descrizioni MIUR — indirizzi e materie.
 *
 * IndirizzoCodeDeriver ricava una sigla dalle parole e, per costruzione,
 * preferisce una sigla brutta a una fusione: due descrizioni diverse non
 * devono mai finire nello stesso codice. Ma non sa se due descrizioni diverse
 * indichino la stessa cosa — "artistico" e "artistico biennio comune" sono lo
 * stesso indirizzo, "SCIENZE MOTORIE E SPORTIVE" e "Educazione Motoria" la
 * stessa materia — e nessuna regola sulle parole puo' dedurlo.
 *
 * Quella e' una decisione, e vive in docs/curriculum/miur_alias.json insieme
 * al motivo per cui e' stata presa.
 *
 * Il confronto e' sulle parole significative, la stessa normalizzazione del
 * derivatore: cosi' "LICEO ARTISTICO - BIENNIO COMUNE" e "Liceo artistico,
 * biennio comune" sono la stessa voce e non serve indovinare la punteggiatura.
 *
 * Le due famiglie stanno in sezioni separate perche' i codici sono per KIND:
 * GEO e' "Geometria" fra le materie e non ha niente a che vedere con un
 * eventuale GEO fra gli indirizzi. Mescolarle produrrebbe collisioni finte e
 * nasconderebbe quelle vere.
 */
final class MiurCurriculumAlias
{
    public const KINDS = ['indirizzi', 'materie'];

    /** @var array<string, array{code:string,label:?string,note:?string,miur:string}> */
    private array $perParole = [];

    /** @param array<int, array<string,mixed>> $voci */
    public function __construct(array $voci = [])
    {
        foreach ($voci as $v) {
            $miur = trim((string)($v['miur'] ?? ''));
            // `code` e' OBBLIGATORIO, ed e' l'identita' della voce.
            //
            // Verrebbe da pensare che una voce con la sola `label` basti a
            // "sistemare il nome" lasciando derivare il codice. Non basta:
            // appena l'import scrive l'etichetta riscritta al posto della
            // descrizione MIUR, le due stringhe non si somigliano piu' — "L.
            // ART. IND. ARTI FIGU.( CURV..." contro "Artistico - arti
            // figurative" — e il confronto sulle parole non ritrova la riga.
            // Il secondo import creerebbe un doppione accanto a quella che
            // aveva creato lui stesso. Una voce senza codice si scarta.
            $code = strtoupper(trim((string)($v['code'] ?? '')));
            $label = isset($v['label']) ? trim((string)$v['label']) : '';
            if ($miur === '' || $code === '' || !preg_match('/^[A-Z]{3,6}$/', $code)) {
                continue;
            }
            $chiave = implode(' ', IndirizzoCodeDeriver::words($miur));
            if ($chiave === '') {
                continue;
            }
            $this->perParole[$chiave] = [
                'code'  => $code,
                'label' => $label !== '' ? $label : null,
                'note'  => isset($v['note']) ? (string)$v['note'] : null,
                'miur'  => $miur,
            ];
        }
    }

    /**
     * Carica una sezione del registro.
     *
     * File assente = nessun alias, non un errore: il derivatore da solo
     * funziona, e su un'installazione che non ha ancora il registro non c'e'
     * niente da correggere.
     *
     * File PRESENTE ma non utilizzabile, invece, e' un errore che va detto per
     * intero. Un registro che non si carica fa proporre sigle derivate al posto
     * di quelle decise, e i doppioni nascono in silenzio. Il messaggio distingue
     * i tre casi perche' la cura e' diversa per ciascuno: un permesso si
     * sistema con chmod, un JSON rotto si sistema con l'editor, e sapere quale
     * dei due sia evita di cercare nel posto sbagliato — soprattutto quando il
     * problema si vede solo in produzione.
     */
    public static function fromFile(string $kind = 'indirizzi', ?string $path = null): self
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new RuntimeException("kind sconosciuto: $kind");
        }
        $path ??= dirname(__DIR__, 2) . '/docs/curriculum/miur_alias.json';
        if (!is_file($path)) {
            return new self();
        }
        if (!is_readable($path)) {
            throw new RuntimeException(sprintf(
                'registro alias non leggibile: %s (permessi: %s, proprietario uid %s; '
                . 'PHP gira come %s). Serve lettura per tutti: chmod a+r sul file '
                . 'e a+rx sulle cartelle che lo contengono.',
                $path,
                substr(sprintf('%o', (int)@fileperms($path)), -4),
                (string)@fileowner($path),
                function_exists('posix_geteuid') ? (string)posix_geteuid() : 'utente sconosciuto'
            ));
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("registro alias illeggibile in lettura: $path");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf(
                "registro alias non e' JSON valido: %s (%s, %d byte letti)",
                $path,
                json_last_error_msg(),
                strlen($raw)
            ));
        }
        $voci = $data[$kind] ?? [];
        return new self(is_array($voci) ? $voci : []);
    }

    /**
     * Stato del registro, per il pannello: c'e', si legge, si capisce?
     *
     * Serve a vederlo PRIMA di caricare un file da 50 MB e scoprire a fine
     * elaborazione che l'import non poteva partire.
     *
     * @return array{path:string,exists:bool,readable:bool,valid:bool,detail:string,indirizzi:int,materie:int}
     */
    public static function status(?string $path = null): array
    {
        $path ??= dirname(__DIR__, 2) . '/docs/curriculum/miur_alias.json';
        $out = ['path' => $path, 'exists' => is_file($path), 'readable' => false,
                'valid' => false, 'detail' => '', 'indirizzi' => 0, 'materie' => 0];
        if (!$out['exists']) {
            $out['detail'] = 'assente: si usano solo le sigle derivate';
            return $out;
        }
        $out['readable'] = is_readable($path);
        if (!$out['readable']) {
            $out['detail'] = 'presente ma non leggibile da PHP (permessi)';
            return $out;
        }
        foreach (self::KINDS as $kind) {
            try {
                $out[$kind] = self::fromFile($kind, $path)->count();
            } catch (RuntimeException $e) {
                $out['detail'] = $e->getMessage();
                return $out;
            }
        }
        $out['valid'] = true;
        $out['detail'] = 'caricato';
        return $out;
    }

    /**
     * @return array{code:string,label:?string,note:?string,miur:string}|null
     */
    public function lookup(string $descrizione): ?array
    {
        $chiave = implode(' ', IndirizzoCodeDeriver::words($descrizione));
        return $this->perParole[$chiave] ?? null;
    }

    /**
     * Tutte le voci, per chi deve confrontarle con cio' che c'e' a registro.
     *
     * @return list<array{code:string,label:?string,note:?string,miur:string}>
     */
    public function all(): array
    {
        return array_values($this->perParole);
    }

    public function count(): int
    {
        return count($this->perParole);
    }

    /**
     * Codici assegnati a piu' descrizioni diverse. Non e' un errore — e'
     * proprio il caso d'uso di "artistico" e "artistico biennio comune" — ma
     * chi rilegge il registro deve poterlo vedere invece di scoprirlo dai dati.
     *
     * @return array<string, list<string>> codice → descrizioni MIUR
     */
    public function unificazioni(): array
    {
        $perCodice = [];
        foreach ($this->perParole as $v) {
            $perCodice[$v['code']][] = $v['miur'];
        }
        return array_filter($perCodice, static fn(array $d): bool => count($d) > 1);
    }
}
