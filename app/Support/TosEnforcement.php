<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Toggle runtime del gate ToS/AUP.
 *
 * Il flag stava solo in `.env`, e quello era il posto sbagliato per tre motivi
 * concreti: `.env` è tracciato da git e viene caricato sul server dal deploy
 * FTP (quindi il valore migra fra ambienti senza che nessuno lo decida),
 * girarlo richiede accesso al filesystem del VPS, e non lascia traccia di chi
 * l'abbia girato. Per un controllo che impedisce l'accesso al servizio, l'ultimo
 * punto è quello che pesa: accendere o spegnere un muro legale è una decisione,
 * e va a registro come tale.
 *
 * Stessa meccanica di DeploymentMode (ADR-017): override runtime su file JSON
 * scritto atomicamente, env come default iniziale. Dal 23/9/2026 la meccanica
 * sta in SostituzioneSuFile, comune alle cinque classi (A-38).
 *
 * Priorità lookup:
 *   1. `storage/config/tos_enforcement.json` — scritto da /admin/system/deployment
 *      (switch immediato, nessun restart di php-fpm).
 *   2. `TOS_ENFORCE` in .env, via config `multitenancy.tos_enforce`.
 *   3. Default `false` — spento. Un'installazione che non ha mai deciso nulla
 *      non deve murare fuori i propri docenti.
 */
final class TosEnforcement
{
    private const FILE = 'tos_enforcement.json';

    /** Il gate è attivo? */
    public static function isEnabled(): bool
    {
        $runtime = self::loadRuntime();
        if ($runtime !== null) {
            return (bool) $runtime['enabled'];
        }
        // La lettura dell'ambiente sta in app/Config/multitenancy.php
        // (Config::booleanoDallAmbiente, A-35). Qui la stessa regola sul
        // valore: non (bool), che farebbe valere vera la stringa 'false'.
        return Config::interpretaBooleano(Config::get('multitenancy.tos_enforce', false)) ?? false;
    }

    /**
     * Stato corrente per il pannello admin.
     *
     * @return array{enabled: bool, source: string, updated_at: ?string, updated_by: ?string}
     */
    public static function snapshot(): array
    {
        $runtime = self::loadRuntime();
        return [
            'enabled'    => self::isEnabled(),
            'source'     => $runtime === null ? 'env' : 'runtime_override',
            'updated_at' => $runtime['updated_at'] ?? null,
            'updated_by' => $runtime['updated_by'] ?? null,
        ];
    }

    /**
     * Scrive l'override runtime, con chi ha deciso e perché. Atomico
     * (SostituzioneSuFile): una richiesta concorrente legge il file vecchio o
     * quello nuovo, mai un JSON troncato — che qui significherebbe gate
     * spento per errore.
     */
    public static function persistRuntime(bool $enabled, string $actor, string $reason): void
    {
        SostituzioneSuFile::scrivi(self::FILE, [
            'enabled'    => $enabled,
            'updated_at' => date('c'),
            'updated_by' => $actor,
            'reason'     => $reason,
        ]);
    }

    /** Rimuove l'override: si torna a quanto dice l'env. */
    public static function clearRuntime(): bool
    {
        return SostituzioneSuFile::togli(self::FILE);
    }

    /**
     * L'override, o null se non c'è o è corrotto: un `enabled` che non sia un
     * booleano vero e proprio conta come corrotto. Si ricade sull'env invece di
     * indovinare, e SostituzioneSuFile lo scrive nel registro delle anomalie:
     * un gate legale non deve accendersi o spegnersi per un JSON malformato.
     *
     * @return array<string,mixed>|null
     */
    private static function loadRuntime(): ?array
    {
        return SostituzioneSuFile::leggi(
            self::FILE,
            static fn(array $dati): bool => is_bool($dati['enabled'] ?? null),
        );
    }

    /** Reset cache (test). */
    public static function resetCache(): void
    {
        SostituzioneSuFile::dimentica(self::FILE);
    }
}
