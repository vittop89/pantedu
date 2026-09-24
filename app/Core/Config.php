<?php

namespace App\Core;

final class Config
{
    private static array $items = [];
    private static bool $loaded = false;

    public static function load(string $configDir): void
    {
        foreach (glob($configDir . '/*.php') as $file) {
            $name = basename($file, '.php');
            self::$items[$name] = require $file;
        }
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;
        foreach ($segments as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return $default;
            }
            $value = $value[$seg];
        }
        return $value;
    }

    /**
     * Sovrascrive una voce a runtime.
     *
     * Esiste per i test — stesso ruolo di Database::reset() — cosi' che una
     * politica governata da configurazione (per esempio
     * `security.totp_required_roles`) sia verificabile senza dover riscrivere
     * i file di config o passare da variabili d'ambiente. Il codice
     * applicativo legge la configurazione, non la cambia: usarlo fuori dai
     * test significa nascondere in un punto qualsiasi una decisione che
     * dovrebbe stare in app/Config.
     */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &self::$items;
        foreach ($segments as $seg) {
            if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
                $ref[$seg] = [];
            }
            $ref = &$ref[$seg];
        }
        $ref = $value;
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    /**
     * La cartella dei dati d'istanza: PANTEDU_DATA_PATH, o la base del
     * repository se la variabile manca **o è vuota** (14/9/2026).
     *
     * I file di configurazione dicevano «se vuoto, i path restano dentro la
     * base del repo», ma scrivevano `$_ENV['PANTEDU_DATA_PATH'] ?? …`, che vale
     * solo per una variabile assente. Con `PANTEDU_DATA_PATH=` vuota, come in
     * `.env.example`, `storage` diventava `/storage`: i registri fallivano in
     * silenzio, e le sessioni a file (ADR-039) sono andate in 500 nel job di
     * accessibilità della CI. La regola sta qui, e i file di configurazione la
     * chiamano.
     */
    public static function cartellaDati(): string
    {
        $valore = $_ENV['PANTEDU_DATA_PATH'] ?? '';
        return is_string($valore) && trim($valore) !== '' ? $valore : dirname(__DIR__, 2);
    }

    /** @var array<string, true> interruttori con un valore sconosciuto già segnalati in questo processo */
    private static array $sconosciutiSegnalati = [];

    /**
     * Un interruttore dall'ambiente, letto in un modo solo (23/9/2026).
     *
     * Fino a quel giorno i booleani si leggevano in sei modi (revisione
     * architetturale del 23/9/2026, A-35): `(bool)`, che fa valere vera la
     * stringa `'false'`; `=== '1'`, che ignora `true`; `=== 'true'`, che
     * ignora `1` (`APP_VITE_DEV=1`, come dice la guida, non accendeva Vite);
     * `filter_var`, due elenchi scritti a mano. Qui la regola è una:
     *
     *   - vero:  `1`, `true`, `yes`, `on`;
     *   - falso: `0`, `false`, `no`, `off`;
     *   senza distinzione di maiuscole e senza gli spazi ai lati;
     *   - variabile assente o vuota: il predefinito. Una riga `CHIAVE=` in
     *     `.env.example` vuol dire «non ho deciso», non «falso» (voce 71 del
     *     registro del debito);
     *   - qualunque altro valore: il predefinito, e una riga in `error_log`
     *     che nomina la variabile (non il valore), una volta per processo.
     *     Chi sceglie il predefinito sceglie il lato prudente: un refuso non
     *     deve spegnere una difesa.
     *
     * Si legge `$_ENV`: i file `.env` caricati da Dotenv, e l'ambiente del
     * processo solo dove PHP lo copia lì (`variables_order` con la E). Con
     * `$ancheDalProcesso` si guarda anche `getenv()` quando `$_ENV` non ha la
     * variabile: lo fanno le quattro letture che lo facevano già prima del
     * 23/9/2026 (XSS_SANITIZE_ENABLED, RISDOC_INSTITUTIONAL_TEMPLATES,
     * FM_CRITICAL_CSS, PDF_IMPORT_PURGE_ONLY). Le altre no: allargare la
     * sorgente di un interruttore cambierebbe che cosa decide un'unità
     * systemd con `Environment=` sull'host, dove la PHP da riga di comando
     * non copia l'ambiente in `$_ENV` — e per qualcuna (la retention GDPR) il
     * cambio sarebbe irreversibile.
     *
     * Si chiama dai file di `app/Config/`, che restano il solo posto dove si
     * legge l'ambiente (ADR-049): il resto del codice legge la configurazione.
     */
    public static function booleanoDallAmbiente(string $nome, bool $predefinito, bool $ancheDalProcesso = false): bool
    {
        $grezzo = self::grezzoDallAmbiente($nome, $ancheDalProcesso);
        if ($grezzo === null) {
            return $predefinito;
        }
        $valore = self::interpretaBooleano($grezzo);
        if ($valore !== null) {
            return $valore;
        }
        if (!isset(self::$sconosciutiSegnalati[$nome])) {
            self::$sconosciutiSegnalati[$nome] = true;
            error_log(sprintf(
                '[configurazione] %s non vale né 1/0, true/false, yes/no, on/off: resta il predefinito (%s)',
                $nome,
                $predefinito ? 'vero' : 'falso',
            ));
        }
        return $predefinito;
    }

    /**
     * La regola di `booleanoDallAmbiente()` su un valore già in mano: vero,
     * falso, o null se il valore non è un interruttore.
     */
    public static function interpretaBooleano(mixed $valore): ?bool
    {
        if (is_bool($valore)) {
            return $valore;
        }
        if (is_int($valore)) {
            return match ($valore) {
                1 => true,
                0 => false,
                default => null,
            };
        }
        if (!is_string($valore)) {
            return null;
        }
        return match (strtolower(trim($valore))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    /**
     * Un testo dall'ambiente: il valore, o il predefinito se la variabile
     * manca **o è vuota** (23/9/2026). È la regola di `cartellaDati()` e di
     * `${VAR:-predefinito}` negli script bash: con `?? '…'` una riga vuota in
     * `.env` vince sul predefinito, e `BACKUP_DIR=` faceva cercare i
     * salvataggi alla radice del disco (A-35, voce 71).
     */
    public static function testoDallAmbiente(string $nome, string $predefinito): string
    {
        return self::grezzoDallAmbiente($nome, false) ?? $predefinito;
    }

    /** Il valore della variabile senza spazi ai lati, o null se manca o è vuota. */
    private static function grezzoDallAmbiente(string $nome, bool $ancheDalProcesso): ?string
    {
        $valore = $_ENV[$nome] ?? ($ancheDalProcesso ? getenv($nome) : null);
        if ($valore === false || $valore === null) {
            return null;
        }
        if ($valore === true) {
            return '1';
        }
        if (!is_scalar($valore)) {
            return null;
        }
        $valore = trim((string) $valore);
        return $valore === '' ? null : $valore;
    }
}
