<?php

declare(strict_types=1);

namespace App\Support;

/**
 * I ruoli del pannello «Utenti» degli strumenti dell'amministratore: quali si
 * cercano e quali si assegnano, secondo lo scenario e secondo chi guarda
 * (15/9/2026, osservazione dell'utente sul menu dei ruoli).
 *
 * - Gli account studente esistono solo nello scenario 3 con gli account
 *   nominativi (`DeploymentScenario::studentAccountsEnabled`): negli scenari 1
 *   e 2 «Studente» non si cerca e non si assegna.
 * - Il super-amministratore non vede gli studenti nemmeno lì: l'elenco li
 *   esclude sempre (`UsersAdminController::list`, minimizzazione). Per lui la
 *   voce non c'è, e la pagina dice perché.
 * - L'amministratore di istituto (ADR-040) si cerca nello scenario 3, ma il suo
 *   ruolo non si dà né si toglie da qui: si gestisce dalla pagina degli istituti.
 *
 * Una regola sola per la pagina e per il server: la pagina offre `assegnabili`,
 * e `UsersAdminController::setRole` rifiuta tutto il resto.
 */
final class RuoliNelPannelloUtenti
{
    /** I ruoli che esistono e che il pannello conosce. */
    public const NOTI = ['student', 'teacher', 'institute_admin', 'administrator'];

    /** @return list<string> i ruoli che dal pannello si assegnano */
    public static function assegnabili(bool $accountStudente): array
    {
        return $accountStudente
            ? ['student', 'teacher', 'administrator']
            : ['teacher', 'administrator'];
    }

    /** @return array<string,string> valore → etichetta del filtro per ruolo */
    public static function filtro(bool $accountStudente, bool $superAmministratore, bool $scenarioIstituto): array
    {
        $voci = ['' => 'Tutti i ruoli'];
        if ($accountStudente && !$superAmministratore) {
            $voci['student'] = 'Studente';
        }
        $voci['teacher'] = 'Docente';
        if ($scenarioIstituto) {
            $voci['institute_admin'] = 'Amministratore di istituto';
        }
        $voci['administrator'] = 'Amministratore';
        return $voci;
    }

    /** Gli studenti ci sono ma a chi guarda non si mostrano: la pagina lo dice. */
    public static function studentiNascosti(bool $accountStudente, bool $superAmministratore): bool
    {
        return $accountStudente && $superAmministratore;
    }
}
