<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le tabelle che tools/audit/purge_old_logs.php svuota esistono, con la colonna
 * della data che il file dichiara (15/9/2026).
 *
 * Il registro art. 30 dichiarava che i link di recupero password (B.6-bis) e i
 * codici del secondo fattore via email (B.6-ter) si purgano con i log d'accesso;
 * lo script non li conteneva. E un nome di tabella o di colonna sbagliato nel file
 * lo script lo salta con un avviso, senza fallire: un verde che non purga niente.
 */
final class TabelleDaPurgareTest extends TestCase
{
    /** @return array<string, array{days:int, ts_col:string}> */
    private static function tabelle(): array
    {
        return require dirname(__DIR__, 2) . '/tools/audit/tabelle_da_purgare.php';
    }

    #[Test]
    public function le_tabelle_dei_link_e_dei_codici_monouso_si_purgano_dopo_un_anno(): void
    {
        $t = self::tabelle();
        foreach (['password_resets', 'two_factor_email_codes', 'email_change_requests'] as $nome) {
            self::assertArrayHasKey($nome, $t, "$nome è dichiarata purgata nel registro");
            self::assertSame(365, $t[$nome]['days']);
        }
    }

    /**
     * Le tabelle trovate senza un termine applicato dalla rilettura legale del
     * 24/9/2026: ognuna ha il suo, e i documenti lo dichiarano.
     */
    #[Test]
    public function le_tabelle_senza_termine_fino_al_24_settembre_ne_hanno_uno(): void
    {
        $attesi = [
            'waf_login_failures' => 1,
            'dpo_requests' => 365,
            'takedown_requests' => 1825,
            'deletion_requests' => 1825,
            'consents' => 3650,
            'parent_consents' => 3650,
            'user_tos_acceptance' => 3650,
            'legal_version_notifications' => 3650,
        ];
        $t = self::tabelle();
        foreach ($attesi as $nome => $giorni) {
            self::assertArrayHasKey($nome, $t, "$nome ha un termine");
            self::assertSame($giorni, $t[$nome]['days'], "il termine di $nome");
        }
        self::assertArrayNotHasKey('crypto_custody_events', $t, 'il registro della chiave resta senza termine, di proposito');
    }

    #[Test]
    public function ogni_tabella_e_ogni_colonna_della_data_esistono_nello_schema(): void
    {
        try {
            $pdo = Database::connection();
            $pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $mancanti = [];
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        foreach (self::tabelle() as $tabella => $conf) {
            $st->execute([$tabella, $conf['ts_col']]);
            if ((int)$st->fetchColumn() === 0) {
                $mancanti[] = "$tabella.{$conf['ts_col']}";
            }
        }
        self::assertSame([], $mancanti, 'tabelle o colonne che lo script salterebbe in silenzio');
    }
}
