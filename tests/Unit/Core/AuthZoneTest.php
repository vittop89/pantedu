<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Controllers\SidepageController;
use App\Core\Auth;
use App\Core\Response;
use App\Support\AuthHelpers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il ruolo dell'amministratore è `administrator`, non `admin` (14/9/2026).
 *
 * In database e in app/Config/roles.php l'amministratore è `administrator`;
 * `admin` era un alias storico di App\Domain\Role, tolto con ADR-040: dava i
 * poteri dell'amministratore a un valore che nessuna zona conosceva. Tre punti lo
 * confrontavano come stringa e lasciavano fuori l'amministratore vero: due
 * moduli JS (intestazione ed elenco delle verifiche da studente), l'helper
 * dell'area del docente (403 su «Sposta di classe», profilo, verifiche) e la
 * guardia degli argomenti di verifiche e BES (403).
 *
 * Qui il lato PHP: le zone che il server scrive sul body (Auth::zone), che sono
 * il contratto dei test JS in tests/js-unit/ruolo-amministratore.test.js, e i
 * due controlli corretti, nei due versi. Senza database: la sessione dice
 * ruolo e super-admin, come dopo il login.
 */
final class AuthZoneTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    private function entraCome(string $ruolo, bool $superAdmin = false): void
    {
        $_SESSION['autenticato']    = true;
        $_SESSION['username']       = 'prova-ruolo';
        $_SESSION['user_role']      = $ruolo;
        $_SESSION['is_super_admin'] = $superAdmin;
        // Claims appena letti: senza, il flag si rilegge dal database
        // (Auth::CLAIMS_TTL_SECONDS, 23/9/2026).
        $_SESSION['claims_at']      = time();
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function zonePerRuolo(): array
    {
        return [
            'studente'       => ['student', ['public', 'student']],
            'docente'        => ['teacher', ['public', 'student', 'teacher']],
            // ADR-040 — e la zona dell'istituto, che l'amministratore della
            // piattaforma condivide con l'amministratore di istituto.
            'amministratore' => ['administrator', ['public', 'student', 'teacher', 'admin', 'istituto']],
            // 15/9/2026 — il collaboratore non esiste più: il vecchio valore non è un
            // ruolo di nessuna zona, nemmeno di quella pubblica (misurato).
            'vecchio valore collaborator' => ['collaborator', []],
        ];
    }

    /** @param list<string> $attese */
    #[Test]
    #[DataProvider('zonePerRuolo')]
    public function le_zone_sul_body_sono_quelle_del_ruolo(string $ruolo, array $attese): void
    {
        $this->entraCome($ruolo);
        $this->assertSame($attese, Auth::zone());
    }

    #[Test]
    public function l_ospite_ha_solo_la_zona_pubblica(): void
    {
        $this->assertSame(['public'], Auth::zone());
    }

    #[Test]
    public function il_super_admin_ha_la_zona_admin_anche_con_un_altro_ruolo(): void
    {
        $this->entraCome('teacher', superAdmin: true);
        $this->assertSame(['public', 'student', 'teacher', 'admin'], Auth::zone());
    }

    /** @return array<string, array{0: string, 1: bool, 2: bool}> */
    public static function areaDelDocente(): array
    {
        return [
            'docente'                => ['teacher', false, true],
            'amministratore'         => ['administrator', false, true],
            // ADR-040 — l'alias non c'è più: il vecchio valore non apre niente,
            // e l'amministratore di istituto non insegna.
            'vecchio valore admin'   => ['admin', false, false],
            'amministratore di istituto' => ['institute_admin', false, false],
            'super-admin con un altro ruolo' => ['student', true, true],
            'vecchio valore collaborator'    => ['collaborator', false, false],
            'studente'               => ['student', false, false],
        ];
    }

    #[Test]
    #[DataProvider('areaDelDocente')]
    public function l_area_del_docente_e_di_chi_insegna_e_del_super_admin(string $ruolo, bool $superAdmin, bool $atteso): void
    {
        $this->entraCome($ruolo, $superAdmin);
        $this->assertSame($atteso, AuthHelpers::isTeacherOrAdmin());
    }

    #[Test]
    public function fuori_dalla_sessione_nessuna_area_del_docente(): void
    {
        $this->assertFalse(AuthHelpers::isTeacherOrAdmin());
    }

    private function guardiaArgomenti(string $categoria): ?Response
    {
        $metodo = new \ReflectionMethod(SidepageController::class, 'enforceCategoryAcl');
        /** @var ?Response $esito */
        $esito = $metodo->invoke(new SidepageController(), $categoria);
        return $esito;
    }

    #[Test]
    public function gli_argomenti_di_verifiche_e_bes_sono_della_zona_del_docente(): void
    {
        foreach (['administrator', 'teacher'] as $ruolo) {
            $this->entraCome($ruolo);
            foreach (['verifiche', 'bes'] as $categoria) {
                $this->assertNull($this->guardiaArgomenti($categoria), "{$ruolo} vede gli argomenti di {$categoria}");
            }
        }
        $this->entraCome('student');
        foreach (['verifiche', 'bes'] as $categoria) {
            $this->assertSame(403, $this->guardiaArgomenti($categoria)?->status, "lo studente no ({$categoria})");
        }
    }
}
