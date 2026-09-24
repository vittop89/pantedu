<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use RuntimeException;

/**
 * Toggle runtime dell'obbligo di verifica in due passaggi.
 *
 * PERCHE' (2026-09-01)
 *
 * L'obbligo si governava con due variabili d'ambiente — `SECURITY_TOTP_ENABLED`
 * e `SECURITY_TOTP_REQUIRED_ROLES` — che per accendere richiedevano accesso al
 * filesystem del VPS, un riavvio di php-fpm e nessuna traccia di chi avesse
 * deciso. Per un controllo che puo' impedire l'accesso al servizio a un intero
 * ruolo, l'ultimo punto e' quello che pesa. Stessa storia, e stessa soluzione,
 * del gate ToS/AUP: override runtime su file JSON, env come default iniziale,
 * decisione a registro.
 *
 * TRE STATI, NON DUE
 *
 * Un booleano non bastava a esprimere quello che serve davvero decidere:
 *
 *   off     — nessun obbligo. Chi vuole attiva comunque la 2FA dal proprio
 *             profilo, e in tal caso gli viene chiesta al login: spegnere
 *             l'obbligo non toglie a nessuno una protezione che ha scelto.
 *   admins  — obbligatoria per amministratori e super-admin. E' il primo
 *             scalino sensato: sono gli account il cui furto costa di piu'.
 *   all     — obbligatoria anche per i docenti.
 *
 * Alzare lo scalino non chiude nessuno fuori all'istante: chi rientra nei ruoli
 * obbligati viene accompagnato alla pagina d'iscrizione e puo' completarla
 * subito (AuthMiddleware, ENROL2FA_ALLOWLIST).
 */
final class TwoFactorEnforcement
{
    public const MODE_OFF    = 'off';
    public const MODE_ADMINS = 'admins';
    public const MODE_ALL    = 'all';

    /** @var array<string, list<string>> ruoli obbligati per modalita' */
    private const ROLES = [
        self::MODE_OFF    => [],
        // ADR-040 — l'amministratore di istituto amministra dati personali
        // dell'Istituto: il secondo fattore gli si chiede come agli
        // amministratori.
        self::MODE_ADMINS => ['super_admin', 'administrator', 'institute_admin'],
        self::MODE_ALL    => ['super_admin', 'administrator', 'institute_admin', 'teacher'],
    ];

    /** Il file del pannello: meccanica in SostituzioneSuFile (A-38). */
    private const FILE = 'twofactor_enforcement.json';

    /** Modalita' corrente: off | admins | all. */
    public static function mode(): string
    {
        $runtime = self::loadRuntime();
        if ($runtime !== null) {
            return (string) $runtime['mode'];
        }

        // Nessun override: si deduce dall'env, che resta il default iniziale.
        $enabled = Config::interpretaBooleano(Config::get('security.totp_enabled', false)) ?? false;
        $roles = Config::get('security.totp_required_roles', []);
        $roles = is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
        if ($roles === []) {
            return self::MODE_OFF;
        }
        if (!$enabled) {
            // Interruttore spento e ruoli elencati: la configurazione si
            // contraddice, e vale il lato prudente (23/9/2026, A-35).
            //
            // Fino a quel giorno `security.totp_enabled` era `(bool)` della
            // stringa, e `SECURITY_TOTP_ENABLED=false` valeva ACCESO: con dei
            // ruoli elencati l'obbligo c'era. Adesso 'false' si legge falso;
            // se qui si spegnesse l'obbligo, un'istanza con quella riga
            // perderebbe al rilascio un secondo fattore che oggi chiede, senza
            // che nessuno l'abbia deciso. Si tiene l'obbligo e lo si dice nel
            // registro delle anomalie, che la diagnostica legge a timer: per
            // spegnerlo si svuota SECURITY_TOTP_REQUIRED_ROLES, o si decide dal
            // pannello (/admin/system/deployment), che scrive chi e perché.
            self::segnalaContraddizione($roles);
        }
        return in_array('teacher', $roles, true) ? self::MODE_ALL : self::MODE_ADMINS;
    }

    /** Una riga di anomalia per processo: mode() si chiama più volte per richiesta. */
    private static bool $contraddizioneSegnalata = false;

    /** @param list<string> $roles */
    private static function segnalaContraddizione(array $roles): void
    {
        if (self::$contraddizioneSegnalata) {
            return;
        }
        self::$contraddizioneSegnalata = true;
        Anomalia::registra(
            'secondo_fattore_interruttore_contraddetto',
            'SECURITY_TOTP_ENABLED dice spento ma SECURITY_TOTP_REQUIRED_ROLES elenca dei ruoli: '
                . 'l\'obbligo del secondo fattore resta per quei ruoli. Per spegnerlo si svuota l\'elenco '
                . 'dei ruoli, o si decide dal pannello.',
            ['ruoli' => $roles],
        );
    }

    /** @return list<string> ruoli per cui la 2FA e' obbligatoria adesso */
    public static function requiredRoles(): array
    {
        return self::ROLES[self::mode()] ?? [];
    }

    public static function isRequiredFor(string $role): bool
    {
        return in_array($role, self::requiredRoles(), true);
    }

    /**
     * Stato corrente per il pannello admin.
     *
     * @return array{mode:string, roles:list<string>, source:string, updated_at:?string, updated_by:?string}
     */
    public static function snapshot(): array
    {
        $runtime = self::loadRuntime();
        return [
            'mode'       => self::mode(),
            'roles'      => self::requiredRoles(),
            'source'     => $runtime === null ? 'env' : 'runtime_override',
            'updated_at' => $runtime['updated_at'] ?? null,
            'updated_by' => $runtime['updated_by'] ?? null,
        ];
    }

    /**
     * Scrive l'override runtime, con chi ha deciso e perché. Atomico
     * (SostituzioneSuFile): una richiesta concorrente legge il file vecchio o
     * quello nuovo, mai un JSON troncato — che qui significherebbe obbligo
     * spento per errore.
     */
    public static function persistRuntime(string $mode, string $actor, string $reason): void
    {
        if (!isset(self::ROLES[$mode])) {
            throw new RuntimeException('invalid_mode');
        }
        SostituzioneSuFile::scrivi(self::FILE, [
            'mode'       => $mode,
            'updated_at' => date('c'),
            'updated_by' => $actor,
            'reason'     => $reason,
        ]);
    }

    /** Rimuove l'override: torna a valere l'env. */
    public static function clearRuntime(): bool
    {
        return SostituzioneSuFile::togli(self::FILE);
    }

    /**
     * L'override, o null se non c'è o è corrotto. Corrotto vuol dire anche una
     * modalità sconosciuta: si ricade sull'env invece di indovinare, e
     * SostituzioneSuFile lo scrive nel registro delle anomalie. Un obbligo
     * d'accesso non deve accendersi, né spegnersi, per un JSON malformato.
     *
     * @return array<string,mixed>|null
     */
    private static function loadRuntime(): ?array
    {
        return SostituzioneSuFile::leggi(
            self::FILE,
            static fn(array $dati): bool => is_string($dati['mode'] ?? null) && isset(self::ROLES[$dati['mode']]),
        );
    }

    /** Reset cache (test). */
    public static function resetCache(): void
    {
        SostituzioneSuFile::dimentica(self::FILE);
        self::$contraddizioneSegnalata = false;
    }
}
