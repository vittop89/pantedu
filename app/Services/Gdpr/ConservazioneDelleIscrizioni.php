<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Config;
use DateInterval;
use DateTimeImmutable;
use RuntimeException;

/**
 * Il termine delle domande di iscrizione (24/9/2026).
 *
 * ── Che cosa non andava ────────────────────────────────────────────────────
 *
 * Le domande in attesa stanno in un file, `registrations.json`
 * (`auth.paths.registrations`), non nel database. Il lavoro notturno
 * `tools/gdpr/anonymize_expired.php` le «cancellava dopo 30 giorni» con
 * `DELETE FROM registrations`, una tabella che nessun codice scrive (misurato
 * con git grep: solo `database/schema.sql` e un conteggio del monitor). Il
 * termine dichiarato non si applicava a niente: nome, email, hash della
 * password, scuole, data di nascita, email del genitore, IP e User-Agent in
 * chiaro di chi non veniva mai approvato restavano per sempre. Con loro lo
 * storico delle decisioni (nome utente, email, esito, motivazione del
 * rifiuto), che nessun codice rilegge, e la copia di ogni account approvato in
 * `users.json` (`auth.paths.registered_users`), hash della password compreso,
 * che il login non legge dalla Phase 18 (`UserRepository` sta sul database).
 *
 * ── La regola, in un posto solo ────────────────────────────────────────────
 *
 * {@see self::ripulisci()} la applica ai dati; la usano il lavoro notturno
 * ({@see self::applica()}) e `RegistrationService` a ogni scrittura del file,
 * così una domanda vecchia se ne va alla prima occasione, qualunque arrivi
 * prima:
 *
 *   - una domanda in attesa da più di N giorni (`retention.pending_registration_days`,
 *     30) si cancella; una domanda senza una data leggibile anche, perché il
 *     suo termine non si può dimostrare (le scrive tutte `submit()`, con la
 *     data: una voce senza è fuori dalla forma);
 *   - dalle domande che restano si tolgono `ip` e `user_agent`: le domande
 *     nuove non li hanno più, questa riga svuota quelle di prima;
 *   - lo storico delle decisioni (`history`) si toglie: esito, chi ha deciso e
 *     motivazione stanno già nel registro delle attività
 *     (`registration_approved`, `registration_rejected`), con il termine di
 *     quel registro;
 *   - la copia degli utenti in `users.json` si cancella intera: non la scrive
 *     più nessuno e nessuno la legge. Anche se non si legge: la si segnala
 *     (il giro esce con 1 quella notte) e la si cancella lo stesso, perché
 *     un file che resta perché è rotto si segnalerebbe ogni notte per sempre;
 *   - i temporanei di una scrittura interrotta (`registrations.json.*`, più
 *     vecchi di un'ora) hanno le stesse domande dentro: se ne vanno anche loro.
 *
 * E chi legge le domande legge solo quelle nel termine
 * ({@see self::domandeInTermine()}): l'elenco da approvare, i conteggi dei due
 * cruscotti e i controlli di nome utente ed email non vedono una domanda
 * scaduta nelle ore fra la scadenza e il giro, e l'approvazione la rifiuta.
 */
final class ConservazioneDelleIscrizioni
{
    public const GIORNI_IN_ATTESA = 30;

    /** @var list<string> i campi che le domande non tengono più */
    public const CAMPI_TOLTI = ['ip', 'user_agent'];

    /**
     * Da quanti secondi un temporaneo di scrittura è certamente abbandonato:
     * una scrittura dura millisecondi, e un'ora non tocca quella di uno
     * scrittore che sta lavorando adesso.
     */
    public const TEMPORANEO_ABBANDONATO_SECONDI = 3600;

    // Senza `readonly`, né promosso né dichiarato: semgrep (immagine della CI)
    // non legge i file che lo usano (tools/ci/semgrep-censimento.json).
    private string $percorsoIscrizioni;
    private string $percorsoCopiaUtenti;
    private int $giorniInAttesa;

    public function __construct(
        string $percorsoIscrizioni,
        string $percorsoCopiaUtenti,
        int $giorniInAttesa = self::GIORNI_IN_ATTESA,
    ) {
        if ($giorniInAttesa < 1) {
            throw new RuntimeException('giorni_in_attesa_non_validi');
        }
        $this->percorsoIscrizioni = $percorsoIscrizioni;
        $this->percorsoCopiaUtenti = $percorsoCopiaUtenti;
        $this->giorniInAttesa = $giorniInAttesa;
    }

    /**
     * La regola sui dati del file, senza toccare il disco.
     *
     * @param array<string,mixed> $dati il contenuto di registrations.json
     * @return array{
     *   0: array{pending: list<array<string,mixed>>},
     *   1: array{scadute:int, senza_data:int, ip_tolti:int, storico:int}
     * }
     */
    public static function ripulisci(
        array $dati,
        DateTimeImmutable $ora,
        int $giorni = self::GIORNI_IN_ATTESA,
    ): array {
        $limite = $ora->sub(new DateInterval('P' . max(1, $giorni) . 'D'));
        $conti = ['scadute' => 0, 'senza_data' => 0, 'ip_tolti' => 0, 'storico' => 0];

        $tenute = [];
        $inAttesa = \is_array($dati['pending'] ?? null) ? $dati['pending'] : [];
        foreach ($inAttesa as $voce) {
            if (!\is_array($voce)) {
                $conti['senza_data']++;
                continue;
            }
            $creata = self::data($voce['created'] ?? null);
            if ($creata === null) {
                $conti['senza_data']++;
                continue;
            }
            if ($creata < $limite) {
                $conti['scadute']++;
                continue;
            }
            $prima = \count($voce);
            foreach (self::CAMPI_TOLTI as $campo) {
                unset($voce[$campo]);
            }
            if (\count($voce) !== $prima) {
                $conti['ip_tolti']++;
            }
            $tenute[] = $voce;
        }

        if (\is_array($dati['history'] ?? null)) {
            $conti['storico'] = \count($dati['history']);
        }

        return [['pending' => $tenute], $conti];
    }

    /**
     * Il termine configurato (`retention.pending_registration_days`), in
     * giorni; mai meno di uno.
     */
    public static function giorniConfigurati(): int
    {
        return max(1, (int)Config::get('retention.pending_registration_days', self::GIORNI_IN_ATTESA));
    }

    /**
     * Le domande in attesa ancora nel termine, senza IP né User-Agent: quelle
     * che si possono mostrare, contare e approvare.
     *
     * Il giro notturno cancella le scadute una volta al giorno; fino ad
     * allora stanno ancora nel file, e chi lo leggeva com'era le contava e le
     * lasciava approvare (24/9/2026).
     *
     * @param array<string,mixed> $dati il contenuto di registrations.json
     * @return list<array<string,mixed>>
     */
    public static function domandeInTermine(array $dati, ?DateTimeImmutable $ora = null, ?int $giorni = null): array
    {
        [$puliti] = self::ripulisci(
            $dati,
            $ora ?? new DateTimeImmutable('now'),
            $giorni ?? self::giorniConfigurati(),
        );
        return $puliti['pending'];
    }

    /**
     * Il giro del lavoro notturno sui due file.
     *
     * In simulazione conta e non scrive. I due file sono indipendenti: un
     * problema con uno non ferma l'altro, e finisce in `problemi`, che il
     * lavoro notturno trasforma in un'uscita con 1.
     *
     *   - `registrations.json` che c'è ma non si legge, o non è JSON, non si
     *     tocca: dentro ci sono domande vere, e «zero domande scadute» detto
     *     di un file che non si è potuto aprire sarebbe un verde che non ha
     *     guardato.
     *   - `users.json` che non si legge si segnala **e si cancella lo stesso**
     *     (24/9/2026): è la copia da togliere comunque, e finché restava il
     *     giro falliva ogni notte senza mai toglierla.
     *
     * @return array{
     *   iscrizioni_presenti:bool, iscrizioni_lette:bool, in_attesa:int,
     *   scadute:int, senza_data:int, ip_tolti:int, storico:int,
     *   copia_presente:bool, copia_voci:int, temporanei:int, scritto:bool,
     *   problemi:list<string>
     * }
     */
    public function applica(DateTimeImmutable $ora, bool $simula): array
    {
        $esito = [
            'iscrizioni_presenti' => false,
            'iscrizioni_lette'    => false,
            'in_attesa'           => 0,
            'scadute'             => 0,
            'senza_data'          => 0,
            'ip_tolti'            => 0,
            'storico'             => 0,
            'copia_presente'      => false,
            'copia_voci'          => 0,
            'temporanei'          => 0,
            'scritto'             => false,
            'problemi'            => [],
        ];

        if (is_file($this->percorsoIscrizioni)) {
            $esito['iscrizioni_presenti'] = true;
            try {
                $dati = self::leggiFile($this->percorsoIscrizioni);
                $esito['iscrizioni_lette'] = true;
                [$puliti, $conti] = self::ripulisci($dati, $ora, $this->giorniInAttesa);
                $esito = array_replace($esito, $conti);
                $esito['in_attesa'] = \count($puliti['pending']);
                if (!$simula && $puliti !== $dati) {
                    self::scriviFile($this->percorsoIscrizioni, $puliti);
                    $esito['scritto'] = true;
                }
            } catch (RuntimeException $e) {
                $esito['problemi'][] = $e->getMessage();
            }
        }

        if ($this->percorsoIscrizioni !== '') {
            $esito['temporanei'] = self::temporaneiAbbandonati($this->percorsoIscrizioni, $ora, $simula);
            if (!$simula && $esito['temporanei'] > 0) {
                $esito['scritto'] = true;
            }
        }

        if ($this->percorsoCopiaUtenti !== '' && is_file($this->percorsoCopiaUtenti)) {
            $esito['copia_presente'] = true;
            try {
                $copia = self::leggiFile($this->percorsoCopiaUtenti);
                $esito['copia_voci'] = \is_array($copia['users'] ?? null) ? \count($copia['users']) : 0;
            } catch (RuntimeException $e) {
                // Si dice, una volta: la notte dopo il file non c'è più.
                $esito['problemi'][] = $e->getMessage()
                    . ($simula ? ' (da cancellare comunque)' : ' (cancellato comunque)');
            }
            if (!$simula) {
                if (!@unlink($this->percorsoCopiaUtenti)) {
                    $esito['problemi'][] = 'non riesco a cancellare la copia degli utenti';
                } else {
                    $esito['scritto'] = true;
                }
            }
        }
        if (!$simula && $this->percorsoCopiaUtenti !== '') {
            // Il temporaneo della vecchia scrittura della copia ha lo stesso
            // contenuto: se c'è, se ne va con lei.
            @unlink($this->percorsoCopiaUtenti . '.tmp');
        }

        return $esito;
    }

    /**
     * I temporanei di `registrations.json` rimasti da una scrittura
     * interrotta: `<file>.tmp` (il nome fisso di prima del 24/9/2026) e
     * `<file>.<sei caratteri>` di {@see self::scriviFile()}, più vecchi di
     * {@see self::TEMPORANEO_ABBANDONATO_SECONDI}. In simulazione li conta.
     */
    private static function temporaneiAbbandonati(string $percorso, DateTimeImmutable $ora, bool $simula): int
    {
        $limite = $ora->getTimestamp() - self::TEMPORANEO_ABBANDONATO_SECONDI;
        $trovati = 0;
        foreach (glob($percorso . '.*', GLOB_NOSORT) ?: [] as $file) {
            $coda = substr($file, \strlen($percorso) + 1);
            if (!preg_match('/^(tmp|[A-Za-z0-9]{6})$/', $coda) || !is_file($file)) {
                continue;
            }
            $modificato = @filemtime($file);
            if ($modificato === false || $modificato > $limite) {
                continue;
            }
            $trovati++;
            if (!$simula) {
                @unlink($file);
            }
        }
        return $trovati;
    }

    /**
     * Lettura stretta: il file deve aprirsi ed essere un oggetto JSON.
     *
     * @return array<string,mixed>
     */
    public static function leggiFile(string $percorso): array
    {
        $grezzo = @file_get_contents($percorso);
        if ($grezzo === false) {
            throw new RuntimeException('non riesco a leggere ' . basename($percorso));
        }
        if (trim($grezzo) === '') {
            return [];
        }
        $dati = json_decode($grezzo, true);
        if (!\is_array($dati)) {
            throw new RuntimeException(basename($percorso) . ' non è JSON valido');
        }
        return $dati;
    }

    /**
     * Scrittura atomica (temporaneo e rinomina), con i permessi che servono ai
     * due scrittori.
     *
     * Il file lo scrivono `www-data` nel container (le domande) e `pantedu`
     * sull'host (questo lavoro), che si incontrano nel gruppo della cartella
     * (docs/ops/diagnostica.md, «Chi scrive il registro»). Il file nuovo nasce
     * 0660 e con il gruppo della cartella, come fa `Anomalia` per il registro
     * delle anomalie: con la umask comune sarebbe 0644, cioè leggibile da
     * chiunque arrivi alla cartella, e del gruppo primario di chi l'ha scritto.
     *
     * Il temporaneo ha un nome suo per ogni scrittura (`tempnam`, nella stessa
     * cartella: la rinomina è atomica solo dentro lo stesso file system). Fino
     * al 24/9/2026 era `<file>.tmp` per tutti: due scrittori insieme — una
     * domanda dal sito e il giro notturno — scrivevano lo stesso temporaneo,
     * e uno dei due rinominava il contenuto dell'altro, o non trovava più il
     * suo.
     *
     * @param array<string,mixed> $dati
     */
    public static function scriviFile(string $percorso, array $dati): void
    {
        $cartella = \dirname($percorso);
        if (!is_dir($cartella) && !@mkdir($cartella, 0o770, true) && !is_dir($cartella)) {
            throw new RuntimeException('cannot_create_dir');
        }
        $json = json_encode($dati, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('write_failed');
        }
        // tempnam, se la cartella non si scrive, ripiega sulla cartella
        // temporanea di sistema: lì la rinomina non sarebbe atomica, o non
        // riuscirebbe. Un temporaneo fuori dalla cartella è un errore.
        $tmp = @tempnam($cartella, basename($percorso) . '.');
        if ($tmp === false || \dirname($tmp) !== realpath($cartella)) {
            if (\is_string($tmp)) {
                @unlink($tmp);
            }
            throw new RuntimeException('write_failed');
        }
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('write_failed');
        }
        @chmod($tmp, 0o660);
        $gruppo = @filegroup($cartella);
        if ($gruppo !== false && @filegroup($tmp) !== $gruppo) {
            @chgrp($tmp, $gruppo);
        }
        if (!@rename($tmp, $percorso)) {
            @unlink($tmp);
            throw new RuntimeException('rename_failed');
        }
    }

    private static function data(mixed $valore): ?DateTimeImmutable
    {
        if (!\is_string($valore) || trim($valore) === '') {
            return null;
        }
        $data = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($valore));
        if ($data === false) {
            return null;
        }
        $errori = DateTimeImmutable::getLastErrors();
        if (\is_array($errori) && ($errori['warning_count'] > 0 || $errori['error_count'] > 0)) {
            return null;
        }
        return $data;
    }
}
