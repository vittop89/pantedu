<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Core\Response;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\RottaVera;

/**
 * Il pannello WAF su MariaDB: una regola si crea, si disattiva, si modifica e
 * si elimina; una voce delle liste nera e bianca si aggiunge e si toglie
 * (23/9/2026, revisione architetturale, A-5).
 *
 * Fino a quel giorno le azioni con `{id}` nel percorso leggevano l'id da una
 * proprietà che Request non ha, e lavoravano sempre sull'id 0: «Disattiva»
 * rispondeva 404, le eliminazioni `{ok:true}` senza cancellare niente. La
 * prova di unità (tests/Unit/Controllers/IdDellaRottaArrivaAllAzioneTest.php)
 * lo fissa su SQLite; questa fa lo stesso giro sul database vero, con una
 * regola e una voce «vicine» create accanto: l'azione deve toccare quella del
 * percorso e nessun'altra.
 *
 * Le richieste passano dalla tabella delle rotte vera e da Kernel::invoke
 * (tests/Support/RottaVera.php), comprese le creazioni: niente righe scritte
 * a mano. Tutto in una transazione che si annulla; nomi e indirizzi hanno una
 * marca unica (indirizzi IPv6 del blocco riservato alla documentazione), e a
 * fine prova si cancella per marca anche ciò che per un errore fosse uscito
 * dalla transazione.
 */
final class PannelloWafRegoleEListeTest extends TestCase
{
    private PDO $pdo;
    private string $marca = '';
    /** Prefisso IPv6 unico per giro, nel 2001:db8::/32 della documentazione. */
    private string $rete = '';

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->marca = 'zz_prova_waf_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $this->rete = sprintf('2001:db8:%x:%x::', random_int(1, 0xffff), random_int(1, 0xffff));
        $_SESSION = [
            'autenticato'    => true,
            'username'       => 'zz_super_admin_prova',
            'user_id'        => 0,
            'user_role'      => 'admin',
            'is_super_admin' => true,
            // Claims appena letti: l'utente non esiste nel database, e senza
            // questa marca il flag si rileggerebbe da lì (23/9/2026).
            'claims_at'      => time(),
        ];
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($this->marca !== '') {
                $this->pdo->prepare('DELETE FROM waf_rules WHERE name LIKE ?')->execute([$this->marca . '%']);
                foreach (['waf_blocked_ips', 'waf_whitelisted_ips'] as $tabella) {
                    $this->pdo->prepare("DELETE FROM {$tabella} WHERE ip_or_cidr LIKE ?")->execute([$this->rete . '%']);
                }
            }
        }
        $_SESSION = [];
    }

    #[Test]
    public function una_regola_si_crea_si_disattiva_si_modifica_e_si_elimina(): void
    {
        $vicina = $this->creaRegola($this->marca . ' vicina');
        $id = $this->creaRegola($this->marca);
        $this->assertNotSame($vicina, $id);
        $altre = $this->altreRegole($id);
        $idAltre = array_map(static fn(array $r): int => (int)$r['id'], $altre);
        $this->assertContains($vicina, $idAltre, 'la vicina è fra le altre');

        $corpo = $this->json(RottaVera::chiama('POST', "/admin/waf/api/rules/{$id}/toggle"), 200);
        $this->assertSame(['ok' => true, 'enabled' => false], $corpo);
        $this->assertSame(0, (int)$this->valore('SELECT enabled FROM waf_rules WHERE id = ?', $id), 'disattivata');

        $nome = $this->marca . ' rinominata';
        $corpo = $this->json(RottaVera::chiama('PUT', "/admin/waf/api/rules/{$id}", ['name' => $nome]), 200);
        $this->assertSame(['ok' => true], $corpo);
        $this->assertSame($nome, $this->valore('SELECT name FROM waf_rules WHERE id = ?', $id));

        $corpo = $this->json(RottaVera::chiama('DELETE', "/admin/waf/api/rules/{$id}"), 200);
        $this->assertSame(['ok' => true], $corpo);
        $this->assertFalse($this->valore('SELECT name FROM waf_rules WHERE id = ?', $id), 'eliminata');

        // La seconda volta non c'è più niente da eliminare, e lo si dice.
        $corpo = $this->json(RottaVera::chiama('DELETE', "/admin/waf/api/rules/{$id}"), 404);
        $this->assertSame(['error' => 'not_found'], $corpo);

        $this->assertSame($altre, $this->altreRegole($id), 'la vicina e le altre regole sono come prima');
    }

    #[Test]
    public function una_voce_si_toglie_dalla_lista_nera_e_da_quella_bianca(): void
    {
        foreach (['blacklist' => 'waf_blocked_ips', 'whitelist' => 'waf_whitelisted_ips'] as $lista => $tabella) {
            $vicina = $this->aggiungiVoce($lista, $tabella, $this->rete . '1');
            $id = $this->aggiungiVoce($lista, $tabella, $this->rete . '2');
            $altre = (int)$this->valore("SELECT COUNT(*) FROM {$tabella} WHERE id <> ?", $id);

            $corpo = $this->json(RottaVera::chiama('DELETE', "/admin/waf/api/{$lista}/{$id}"), 200);
            $this->assertSame(['ok' => true], $corpo, $lista);
            $this->assertFalse($this->valore("SELECT id FROM {$tabella} WHERE id = ?", $id), "{$lista}: tolta");
            $this->assertSame(
                $this->rete . '1',
                $this->valore("SELECT ip_or_cidr FROM {$tabella} WHERE id = ?", $vicina),
                "{$lista}: la vicina resta"
            );
            $this->assertSame(
                $altre,
                (int)$this->valore("SELECT COUNT(*) FROM {$tabella} WHERE id <> ?", $id),
                "{$lista}: le altre restano"
            );

            $corpo = $this->json(RottaVera::chiama('DELETE', "/admin/waf/api/{$lista}/{$id}"), 404);
            $this->assertSame(['error' => 'not_found'], $corpo, $lista);
        }
    }

    /** Una regola dal pannello (POST /admin/waf/api/rules); il suo id. */
    private function creaRegola(string $nome): int
    {
        $corpo = $this->json(RottaVera::chiama('POST', '/admin/waf/api/rules', [
            'name'       => $nome,
            'action'     => 'log_only',
            'enabled'    => '1',
            'conditions' => json_encode([
                'logic'      => 'AND',
                'conditions' => [['field' => 'url', 'operator' => 'equals', 'value' => '/' . $this->marca]],
            ], JSON_THROW_ON_ERROR),
        ]), 200);
        $id = (int)($corpo['id'] ?? 0);
        $this->assertGreaterThan(0, $id, "la regola «{$nome}» ha un id");
        return $id;
    }

    /** Una voce dal pannello (POST /admin/waf/api/{lista}); il suo id. */
    private function aggiungiVoce(string $lista, string $tabella, string $ip): int
    {
        $corpo = $this->json(RottaVera::chiama('POST', "/admin/waf/api/{$lista}", [
            'ip_or_cidr' => $ip,
            'reason'     => $this->marca,
        ]), 200);
        $this->assertSame(['ok' => true], $corpo, $lista);
        $id = (int)$this->valore("SELECT id FROM {$tabella} WHERE ip_or_cidr = ?", $ip);
        $this->assertGreaterThan(0, $id, "{$lista}: la voce {$ip} c'è");
        return $id;
    }

    /** @return array<mixed> il corpo JSON, dopo aver controllato lo stato */
    private function json(Response $esito, int $stato): array
    {
        $this->assertSame($stato, $esito->status, $esito->body);
        $dati = json_decode($esito->body, true);
        $this->assertIsArray($dati, 'risposta JSON: ' . $esito->body);
        return $dati;
    }

    private function valore(string $sql, int|string $parametro): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$parametro]);
        return $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> le altre regole: id, nome, stato */
    private function altreRegole(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, enabled FROM waf_rules WHERE id <> ? ORDER BY id');
        $stmt->execute([$id]);
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
