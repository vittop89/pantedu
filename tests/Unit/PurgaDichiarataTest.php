<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La purga dichiarata si può davvero fare (15/9/2026).
 *
 * Misurato in produzione: l'utenza di manutenzione aveva DELETE su cinque
 * tabelle, scritte a mano nello script che la crea, mentre la lista della purga
 * ne aveva otto; il trigger append-only di consent_audit vietava la purga che
 * registro e informativa promettono dopo dieci anni; e lo script di purga,
 * davanti a un DELETE negato, usciva con successo. Qui si tengono insieme:
 * lista della purga, permessi dell'utenza, trigger.
 */
final class PurgaDichiarataTest extends TestCase
{
    private static function base(): string
    {
        return dirname(__DIR__, 2);
    }

    #[Test]
    public function i_permessi_dell_utenza_di_manutenzione_vengono_dalla_lista_della_purga(): void
    {
        $script = (string)file_get_contents(self::base() . '/tools/security/create_maintenance_db_user.sh');

        self::assertStringContainsString('tools/audit/tabelle_da_purgare.php', $script, 'i DELETE si ricavano dalla lista');
        self::assertDoesNotMatchRegularExpression('/GRANT DELETE ON \\\\`\$\{DB_NAME\}\\\\`\.[a-z_]+ /', $script, 'nessun DELETE scritto a mano su una tabella');
    }

    #[Test]
    public function ogni_tabella_da_purgare_protetta_da_trigger_lascia_cancellare_la_manutenzione(): void
    {
        $tabelle = require self::base() . '/tools/audit/tabelle_da_purgare.php';
        $trigger = (string)file_get_contents(self::base() . '/tools/security/apply_audit_append_only.php');
        preg_match_all("/'([a-z_]+)'\s*=>\s*\['purgeable'\s*=>\s*(true|false)\]/", $trigger, $m, PREG_SET_ORDER);
        self::assertNotEmpty($m, 'la configurazione dei trigger si legge');

        $bloccate = [];
        foreach ($m as [, $tabella, $purgabile]) {
            if (isset($tabelle[$tabella]) && $purgabile !== 'true') {
                $bloccate[] = $tabella;
            }
        }
        self::assertSame([], $bloccate, 'tabelle da purgare che il trigger non lascerebbe cancellare');
    }

    #[Test]
    public function lo_script_di_purga_esce_in_errore_se_una_tabella_non_si_purga(): void
    {
        $script = (string)file_get_contents(self::base() . '/tools/audit/purge_old_logs.php');
        self::assertMatchesRegularExpression('/if \(\$falliti !== \[\]\) \{[^}]*exit\(1\);/s', $script);
    }

    /**
     * Gli avvisi di inattività (migrazione 142, 24/9/2026) restano cinque anni,
     * come dichiara l'informativa (§5): sono la prova che l'avviso è partito.
     * La tabella era nata senza una voce nella lista, e quindi senza termine.
     */
    #[Test]
    public function gli_avvisi_di_inattivita_hanno_il_termine_dell_informativa(): void
    {
        /** @var array<string, array{days:int, ts_col:string}> $lista */
        $lista = require self::base() . '/tools/audit/tabelle_da_purgare.php';
        self::assertArrayHasKey('avvisi_di_inattivita', $lista);
        self::assertSame(1825, $lista['avvisi_di_inattivita']['days']);
        self::assertSame('inviato_at', $lista['avvisi_di_inattivita']['ts_col']);

        $migrazione = (string)file_get_contents(self::base() . '/database/migrations/142_avvisi_di_inattivita.sql');
        self::assertMatchesRegularExpression('/^\s*inviato_at\s+DATETIME/m', $migrazione, 'la colonna della data esiste');
        $informativa = (string)file_get_contents(self::base() . '/docs/privacy/informativa.md');
        self::assertMatchesRegularExpression('/\| Avvisi di inattività partiti[^|]*\| 5 anni \|/u', $informativa, 'e il termine è quello dichiarato');
    }
}
