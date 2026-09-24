<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use RuntimeException;

/**
 * Le sostituzioni a runtime scritte dal pannello in `storage/config/*.json`.
 *
 * Cinque interruttori dell'istanza si decidono dal pannello
 * /admin/system/deployment e non solo dall'ambiente: lo scenario
 * (DeploymentScenario), il modo legacy (DeploymentMode), la registrazione
 * degli studenti (StudentRegistration), il gate ToS/AUP (TosEnforcement) e
 * l'obbligo del secondo fattore (TwoFactorEnforcement). Il pannello scrive un
 * file JSON, che vince sulla variabile corrispondente.
 *
 * Fino al 23/9/2026 ciascuna delle cinque classi si scriveva da sé percorso,
 * cache e scrittura atomica, e ciascuna faceva una cosa diversa davanti a un
 * file corrotto (revisione architetturale del 23/9/2026, A-38): tre
 * scrivevano una riga in `error_log`, due — DeploymentMode e
 * StudentRegistration — lo ignoravano in silenzio. Un file corrotto è
 * l'errore che nessuno vede: il pannello dice «env», e chi aveva deciso
 * qualcosa non sa che la sua decisione non vale più.
 *
 * Qui la meccanica, una volta sola:
 *
 *   - il percorso: `<app.paths.storage>/config/<nome>`;
 *   - la cache per richiesta, per percorso (un test, o un processo che cambia
 *     `app.paths.storage`, non legge il file di un'altra cartella);
 *   - la scrittura atomica (tmp + rename, 0640): una richiesta concorrente
 *     legge il file vecchio o quello nuovo, mai un JSON troncato;
 *   - il file corrotto — illeggibile, non JSON, non un oggetto, o che non
 *     supera la convalida di chi lo usa — vale come assente, e **si dice**:
 *     una riga in `error_log` e l'anomalia `sostituzione_illeggibile`, che la
 *     diagnostica legge a timer (App\Support\Anomalia). Chi lo usa ricade
 *     sull'ambiente, come prima.
 *
 * Che cosa c'è dentro, e se registra chi ha deciso, lo decide chi lo usa:
 * TwoFactorEnforcement, TosEnforcement e DeploymentScenario scrivono
 * `updated_by` e `reason`, come prima.
 */
final class SostituzioneSuFile
{
    /**
     * Per percorso: i dati letti, o false se il file manca o non vale.
     *
     * @var array<string, array<string, mixed>|false>
     */
    private static array $cache = [];

    public static function percorso(string $nome): string
    {
        return (string) Config::get('app.paths.storage') . '/config/' . $nome;
    }

    /**
     * I dati del file, o null se manca o è corrotto.
     *
     * @param callable(array<string, mixed>): bool $valido la convalida di chi
     *                                                    lo usa: false = corrotto
     * @return array<string, mixed>|null
     */
    public static function leggi(string $nome, callable $valido): ?array
    {
        $percorso = self::percorso($nome);
        if (array_key_exists($percorso, self::$cache)) {
            $dati = self::$cache[$percorso];
            return $dati === false ? null : $dati;
        }
        if (!is_file($percorso)) {
            self::$cache[$percorso] = false;
            return null;
        }
        $grezzo = @file_get_contents($percorso);
        $dati = is_string($grezzo) ? json_decode($grezzo, true) : null;
        if (!is_array($dati) || !$valido($dati)) {
            self::segnalaCorrotto($nome, $percorso, is_string($grezzo) ? 'contenuto non valido' : 'illeggibile');
            self::$cache[$percorso] = false;
            return null;
        }
        /** @var array<string, mixed> $dati */
        self::$cache[$percorso] = $dati;
        return $dati;
    }

    /**
     * Scrive il file in modo atomico e dimentica la cache.
     *
     * @param array<string, mixed> $dati
     * @throws RuntimeException cannot_create_config_dir | cannot_encode_config
     *                          | cannot_write_config | cannot_rename_config
     */
    public static function scrivi(string $nome, array $dati): void
    {
        $percorso = self::percorso($nome);
        $cartella = \dirname($percorso);
        if (!is_dir($cartella) && !mkdir($cartella, 0750, true) && !is_dir($cartella)) {
            throw new RuntimeException('cannot_create_config_dir');
        }
        $json = json_encode($dati, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('cannot_encode_config');
        }
        $tmp = $percorso . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('cannot_write_config');
        }
        if (!rename($tmp, $percorso)) {
            @unlink($tmp);
            throw new RuntimeException('cannot_rename_config');
        }
        @chmod($percorso, 0640);
        unset(self::$cache[$percorso]);
    }

    /** Toglie il file: torna a valere l'ambiente. */
    public static function togli(string $nome): bool
    {
        $percorso = self::percorso($nome);
        unset(self::$cache[$percorso]);
        return !is_file($percorso) || @unlink($percorso);
    }

    /** Dimentica quello che si era letto (test, e dopo una scrittura altrui). */
    public static function dimentica(string $nome): void
    {
        unset(self::$cache[self::percorso($nome)]);
    }

    private static function segnalaCorrotto(string $nome, string $percorso, string $perche): void
    {
        error_log("[sostituzione] storage/config/{$nome} {$perche}: vale l'ambiente ({$percorso})");
        Anomalia::registra(
            'sostituzione_illeggibile',
            "Il file di sostituzione storage/config/{$nome}, scritto dal pannello /admin/system/deployment, "
                . "è {$perche}: si ignora e vale l'ambiente. Si corregge dal pannello, o togliendo il file.",
            ['file' => $nome],
            $nome,
        );
    }
}
