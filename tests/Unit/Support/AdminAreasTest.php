<?php
declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\AdminAreas;
use App\Support\DeploymentScenario;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La matrice area × scenario del pannello (piano classi-credenziali-scenari, B).
 * Lo scenario e' passato esplicito: nessuna Config, nessun file.
 */
final class AdminAreasTest extends TestCase
{
    #[Test]
    public function la_matrice_e_quella_del_piano(): void
    {
        $p = DeploymentScenario::PERSONAL;
        $c = DeploymentScenario::COLLEAGUES;
        $i = DeploymentScenario::INSTITUTE;
        $attesa = [
            'registrations'            => [$p => 'inert',  $c => 'active', $i => 'active'],
            // ADR-041 — gli incarichi decidono anche le sezioni dei docenti, in ogni scenario.
            'sections'                 => [$p => 'active', $c => 'active', $i => 'active'],
            'student_registration'     => [$p => 'inert',  $c => 'inert',  $i => 'active'],
            'class_credentials'        => [$p => 'active', $c => 'active', $i => 'inert'],
            'students_without_section' => [$p => 'inert',  $c => 'inert',  $i => 'active'],
        ];
        $this->assertSame($attesa, AdminAreas::matrix());
    }

    #[Test]
    public function un_area_sconosciuta_e_sempre_attiva(): void
    {
        // Le aree non elencate non dipendono dallo scenario: chi chiede per
        // sbaglio una chiave inesistente non deve veder sparire nulla.
        $this->assertTrue(AdminAreas::isActive('waf', DeploymentScenario::PERSONAL));
        $this->assertNull(AdminAreas::notice('waf', DeploymentScenario::PERSONAL));
    }

    #[Test]
    public function le_approvazioni_sono_in_evidenza_solo_con_i_colleghi(): void
    {
        $this->assertTrue(AdminAreas::isHighlighted('registrations', DeploymentScenario::COLLEAGUES));
        $this->assertFalse(AdminAreas::isHighlighted('registrations', DeploymentScenario::INSTITUTE));
        $this->assertFalse(AdminAreas::isHighlighted('registrations', DeploymentScenario::PERSONAL));
    }

    #[Test]
    public function l_avviso_dice_scenario_motivo_e_con_cosa_si_attiva(): void
    {
        $n = AdminAreas::notice('students_without_section', DeploymentScenario::PERSONAL);
        $this->assertNotNull($n);
        $this->assertSame(1, $n['number']);
        $this->assertSame('Studenti senza sezione', $n['label']);
        $this->assertStringContainsString('account studente', $n['why']);
        $this->assertStringContainsString('scenario 3', $n['activates_with']);
        $this->assertNull(AdminAreas::notice('students_without_section', DeploymentScenario::INSTITUTE), 'attiva: nessun avviso');
        $this->assertNull(AdminAreas::notice('sections', DeploymentScenario::PERSONAL), 'gli incarichi contano in ogni scenario (ADR-041)');
    }

    #[Test]
    public function l_elenco_delle_inerti_segue_lo_scenario(): void
    {
        $chiavi = static fn(string $s) => array_column(AdminAreas::inert($s), 'key');
        $this->assertSame(
            ['registrations', 'student_registration', 'students_without_section'],
            $chiavi(DeploymentScenario::PERSONAL)
        );
        $this->assertSame(
            ['student_registration', 'students_without_section'],
            $chiavi(DeploymentScenario::COLLEAGUES)
        );
        $this->assertSame(['class_credentials'], $chiavi(DeploymentScenario::INSTITUTE));
        foreach (AdminAreas::inert(DeploymentScenario::PERSONAL) as $a) {
            $this->assertStringStartsWith('/admin', $a['href'], 'ogni area inerte resta raggiungibile');
        }
    }
}
