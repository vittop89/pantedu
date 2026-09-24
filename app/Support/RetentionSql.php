<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Le condizioni della retention, in un posto solo.
 *
 * Stanno qui e non dentro `tools/gdpr/anonymize_expired.php` per una ragione
 * precisa: **il test deve provare la stessa stringa che gira in produzione.**
 * Una condizione ricopiata dentro un test si separa dal codice al primo
 * ritocco, e da quel momento il test continua a passare mentre difende una
 * query che non esiste più. Un verde che non misura.
 *
 * Qui dentro ci sono clausole `WHERE` di query che **cancellano dati
 * personali in modo irreversibile**. Ogni modifica va accompagnata da una
 * prova sui quattro casi in `tests/Unit/Support/RetentionInattiviTest.php`.
 */
final class RetentionSql
{
    /**
     * Chi è «inattivo» abbastanza da essere anonimizzato.
     *
     * Tre segnaposto, tutti la stessa data limite: `created_at`,
     * `last_access_at`, `approved_at`.
     *
     * ── Cosa c'era prima, e perché è stato cambiato ────────────────────────
     *
     * Fino al 9 settembre 2026:
     *
     *     status <> 'anonymized'
     *     AND (approved_at IS NULL OR approved_at < ?)
     *     AND created_at < ?
     *
     * Nessun riferimento all'uso, perché il dato non esisteva. Ma
     * `approved_at < limite` non vuol dire «inattivo da due anni»: vuol dire
     * «iscritto da più di due anni», che per chi entra ogni giorno è vero.
     *
     * Misurato sui dati veri: quella condizione, al primo giro utile, avrebbe
     * anonimizzato **un account di ruolo `administrator`** — email sostituita,
     * nome svuotato, password azzerata, `active = 0`. Cioè avrebbe chiuso
     * l'amministratore fuori dal proprio sistema.
     *
     * Non è successo solo perché il timer non era mai partito e la retention
     * girava in simulazione.
     *
     * ── Perché `NULL` non basta a far anonimizzare ─────────────────────────
     *
     * `last_access_at` è nata vuota per tutti (migrazione 108): prima non si
     * registrava niente. `NULL` non significa «non si è mai collegato»,
     * significa «non lo sappiamo». Trattare l'ignoto come inattività
     * vorrebbe dire anonimizzare tutti al primo giro — lo stesso difetto di
     * prima con un'altra colonna.
     *
     * Perciò `NULL` conta solo insieme a un'iscrizione più vecchia del
     * limite: chi è iscritto da tre anni e non ha mai lasciato traccia di un
     * accesso è inattivo davvero.
     */
    public const INATTIVI_WHERE = <<<'SQL'
        status <> 'anonymized'
          AND created_at < ?
          AND (
                last_access_at < ?
                OR (last_access_at IS NULL AND (approved_at IS NULL OR approved_at < ?))
              )
        SQL;
}
