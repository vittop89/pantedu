<?php

declare(strict_types=1);

namespace App\Services\Tikz;

use App\Services\TikzElementsService;
use RuntimeException;

/**
 * I modelli TikZ versionati nel repository e il loro allineamento con la
 * biblioteca dell'istanza (24/9/2026, ADR-050).
 *
 * La biblioteca vera è il JSON d'istanza che scrive il pannello admin
 * (`TikzElementsService`). Fino al 24/9/2026 era l'unico posto: un modello
 * migliorato non poteva passare da una pull request e arrivare in produzione
 * con il rilascio. Ora i modelli che si curano nel codice stanno in
 * `storage/templates/tikz/` — un `.tex` ciascuno e `modelli.json` che dice
 * dove vanno — e il rilascio li porta nell'istanza (passo 8-quater di
 * `tools/webhook/deploy-container.sh`, con `tools/tikz/sincronizza_modelli.php`).
 *
 * Il pannello resta libero: per ogni modello del manifesto, nel gruppo
 * indicato,
 *   - se nell'istanza non c'è, si crea;
 *   - se c'è uguale, non si fa niente;
 *   - se c'è una versione che il manifesto dichiara superata (la sua
 *     impronta è in `sostituisce`), si sostituisce;
 *   - se c'è altro — l'amministratore l'ha cambiato dal pannello, o una
 *     versione vecchia non è stata dichiarata — non si tocca, e lo si dice.
 * Nessun modello dell'istanza fuori dal manifesto viene mai toccato.
 */
final class ModelliTikzVersionati
{
    public const CARTELLA_REL = 'storage/templates/tikz';
    public const MANIFESTO    = 'modelli.json';

    public const CREA       = 'crea';
    public const AGGIORNA   = 'aggiorna';
    public const ALLINEATO  = 'allineato';
    public const MODIFICATO = 'modificato';

    // Proprietà dichiarate e assegnate nel costruttore, senza `readonly`
    // promosso: il lettore PHP di semgrep non lo regge e leggerebbe il file
    // solo in parte (tools/ci/cancello-semgrep.mjs).
    private TikzElementsService $biblioteca;

    private string $cartella;

    /**
     * @param string $cartella la cartella dei modelli versionati: la passa chi
     *        chiama (tools/tikz/sincronizza_modelli.php, le prove). Il servizio
     *        non ricava percorsi dalla propria posizione: in `app/` quella forma
     *        è riservata ai dati d'istanza (tools/ci/no-scritture-nel-repository.mjs).
     */
    public function __construct(TikzElementsService $biblioteca, string $cartella)
    {
        $this->biblioteca = $biblioteca;
        $this->cartella = rtrim($cartella, '/');
    }

    /**
     * L'impronta con cui si riconosce una versione: sha256 del testo con gli
     * a capo di Unix e senza spazi finali (il pannello e i browser possono
     * cambiare `\r\n` e l'ultimo a capo senza che il modello cambi).
     */
    public static function impronta(string $contenuto): string
    {
        return hash('sha256', rtrim(str_replace("\r\n", "\n", $contenuto)));
    }

    /**
     * Il manifesto, letto e controllato: ogni voce con gruppo, etichetta,
     * tipo, file esistente e impronte ben formate.
     *
     * @return list<array{gruppo:string, etichetta:string, tipo:string, file:string, contenuto:string, sostituisce:list<string>}>
     */
    public function modelli(): array
    {
        $file = $this->cartella . '/' . self::MANIFESTO;
        $dati = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!\is_array($dati) || !\is_array($dati['modelli'] ?? null)) {
            throw new RuntimeException("manifesto_illeggibile: $file");
        }
        $modelli = [];
        $visti = [];
        foreach ($dati['modelli'] as $i => $m) {
            $dove = "voce $i";
            if (!\is_array($m)) {
                throw new RuntimeException("manifesto_voce_non_valida: $dove");
            }
            $gruppo    = (string) ($m['gruppo'] ?? '');
            $etichetta = trim((string) ($m['etichetta'] ?? ''));
            $tipo      = (string) ($m['tipo'] ?? '');
            $rel       = (string) ($m['file'] ?? '');
            if (!str_starts_with($gruppo, 'gruppo-') || $etichetta === '' || !\in_array($tipo, ['tikz', 'latex'], true)) {
                throw new RuntimeException("manifesto_voce_non_valida: $dove");
            }
            if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) {
                throw new RuntimeException("manifesto_file_non_valido: $dove");
            }
            $percorso = $this->cartella . '/' . $rel;
            $contenuto = is_file($percorso) ? (string) file_get_contents($percorso) : '';
            if (trim($contenuto) === '') {
                throw new RuntimeException("manifesto_file_mancante: $rel");
            }
            $sostituisce = [];
            foreach ((array) ($m['sostituisce'] ?? []) as $h) {
                if (!\is_string($h) || preg_match('/^[0-9a-f]{64}$/', $h) !== 1) {
                    throw new RuntimeException("manifesto_impronta_non_valida: $dove");
                }
                $sostituisce[] = $h;
            }
            $chiave = $gruppo . "\0" . mb_strtolower($etichetta);
            if (isset($visti[$chiave])) {
                throw new RuntimeException("manifesto_voce_doppia: $gruppo / $etichetta");
            }
            $visti[$chiave] = true;
            $modelli[] = [
                'gruppo'      => $gruppo,
                'etichetta'   => $etichetta,
                'tipo'        => $tipo,
                'file'        => $rel,
                'contenuto'   => rtrim(str_replace("\r\n", "\n", $contenuto)) . "\n",
                'sostituisce' => $sostituisce,
            ];
        }
        return $modelli;
    }

    /**
     * Che cosa farebbe l'allineamento, senza scrivere niente.
     *
     * @return list<array{gruppo:string, etichetta:string, file:string, azione:string}>
     */
    public function piano(): array
    {
        $indice = $this->biblioteca->readIndex();
        $piano = [];
        foreach ($this->modelli() as $m) {
            $piano[] = [
                'gruppo'    => $m['gruppo'],
                'etichetta' => $m['etichetta'],
                'file'      => $m['file'],
                'azione'    => self::azione($m, $indice[$m['gruppo']] ?? []),
            ];
        }
        return $piano;
    }

    /**
     * Esegue il piano: crea e aggiorna con lo stesso servizio del pannello
     * (lock e scrittura atomica), non tocca i modificati.
     *
     * @return list<array{gruppo:string, etichetta:string, file:string, azione:string}>
     */
    public function applica(): array
    {
        $fatto = [];
        foreach ($this->modelli() as $m) {
            // L'indice si rilegge a ogni voce: fra una e l'altra l'amministratore
            // può aver salvato dal pannello.
            $elementi = $this->biblioteca->readIndex()[$m['gruppo']] ?? [];
            $azione = self::azione($m, $elementi);
            if ($azione === self::CREA) {
                $this->biblioteca->createElement($m['gruppo'], '', $m['tipo'], $m['etichetta'], $m['contenuto']);
            } elseif ($azione === self::AGGIORNA) {
                $this->biblioteca->editElement($m['gruppo'], -1, '', '', $m['tipo'], $m['etichetta'], $m['contenuto'], $m['etichetta']);
            }
            $fatto[] = ['gruppo' => $m['gruppo'], 'etichetta' => $m['etichetta'], 'file' => $m['file'], 'azione' => $azione];
        }
        return $fatto;
    }

    /**
     * @param array{etichetta:string, contenuto:string, sostituisce:list<string>} $m
     * @param list<array<string, mixed>> $elementi
     */
    private static function azione(array $m, array $elementi): string
    {
        foreach ($elementi as $el) {
            if (mb_strtolower(trim((string) ($el['label'] ?? ''))) !== mb_strtolower($m['etichetta'])) {
                continue;
            }
            $attuale = self::impronta((string) ($el['content'] ?? ''));
            if ($attuale === self::impronta($m['contenuto'])) {
                return self::ALLINEATO;
            }
            return \in_array($attuale, $m['sostituisce'], true) ? self::AGGIORNA : self::MODIFICATO;
        }
        return self::CREA;
    }
}
