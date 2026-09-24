<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\Maps\PuliziaMetadatiMappe;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lo strumento che toglie dalle mappe il body_pt d'esempio scritto dal modale
 * ✎ (tools/maps/pulisci_metadati_mappe.php), nei due versi: pulisce la mappa
 * con il seme esatto, e lascia stare una mappa con un corpo diverso anche di
 * poco, un esercizio con il seme, una mappa con il solo layout. La prova a
 * secco non scrive; la scrittura non tocca updated_at.
 *
 * Tutto nella transazione del test, limitato a un docente creato qui: il
 * database di prova è condiviso.
 */
final class PuliziaMetadatiMappeTest extends TestCase
{
    private PDO $pdo;
    private int $docente = 0;
    private bool $inTx = false;
    private const IERI = '2026-01-02 03:04:05';

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$basePath/$f")) {
                \Dotenv\Dotenv::createMutable($basePath, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($basePath . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT metadata_json, body_pt_ct FROM teacher_content_data LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, "teacher", "Zz", "Pulizia", ?, "x", "approved", 1, NOW())'
        )->execute(['zzpulizia', 'zzpulizia@example.invalid']);
        $this->docente = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function riga(string $tipo, ?string $metadatiJson): int
    {
        $this->pdo->prepare(
            'INSERT INTO teacher_content_data (teacher_id, content_subtype, topic, title, metadata_json, visibility, updated_at)
             VALUES (?, ?, "9.9", ?, ?, "draft", ?)'
        )->execute([$this->docente, $tipo, 'zz-pulizia-' . uniqid('', true), $metadatiJson, self::IERI]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Una riga con il corpo nelle colonne cifrate, come la scrive il
     * dual-write: `TeacherContentRepository::extractBodyPt` toglie `body_pt`
     * da `metadata_json` prima di cifrarlo, quindi nei metadati in chiaro il
     * corpo NON c'è.
     */
    private function rigaColCorpoCifrato(?string $metadatiJson): int
    {
        $id = $this->riga('mappa', $metadatiJson);
        $this->pdo->prepare(
            'UPDATE teacher_content_data
                SET body_pt_ct = ?, body_pt_iv = ?, body_pt_tag = ?, body_pt_kv = 1, updated_at = ?
              WHERE id = ?'
        )->execute([random_bytes(48), random_bytes(12), random_bytes(16), self::IERI, $id]);
        return $id;
    }

    /** @return array{0:?string,1:string} metadata_json e updated_at */
    private function stato(int $id): array
    {
        $st = $this->pdo->prepare('SELECT metadata_json, updated_at FROM teacher_content_data WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($r);
        return [$r['metadata_json'] !== null ? (string)$r['metadata_json'] : null, (string)$r['updated_at']];
    }

    private function registrate(int $id): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM content_action_log WHERE content_id = ? AND action = "content_updated"');
        $st->execute([$id]);
        return (int)$st->fetchColumn();
    }

    #[Test]
    public function pulisce_solo_le_mappe_con_il_seme_esatto(): void
    {
        $seme = PuliziaMetadatiMappe::SEME;
        $quasiSeme = $seme;
        $quasiSeme[1]['children'][0]['text'] = 'Una riga scritta dal docente';

        $conLink = $this->riga('mappa', (string)json_encode(['layout' => 'exercises', 'mappa' => ['href' => 'https://example.org/m', 'display' => 'show'], 'body_pt' => $seme], JSON_UNESCAPED_UNICODE));
        $soloSeme = $this->riga('mappa', (string)json_encode(['layout' => 'exercises', 'body_pt' => $seme], JSON_UNESCAPED_UNICODE));
        $corpoDiverso = $this->riga('mappa', (string)json_encode(['layout' => 'exercises', 'body_pt' => $quasiSeme], JSON_UNESCAPED_UNICODE));
        $soloLayout = $this->riga('mappa', '{"layout":"exercises","mappa":{"display":"hide"}}');
        $esercizio = $this->riga('esercizio', (string)json_encode(['layout' => 'exercises', 'body_pt' => $seme], JSON_UNESCAPED_UNICODE));
        $pulita = $this->riga('mappa', '{"mappa":{"display":"show"}}');
        $prima = [];
        foreach ([$conLink, $soloSeme, $corpoDiverso, $soloLayout, $esercizio, $pulita] as $id) {
            $prima[$id] = $this->stato($id);
        }

        // Prova a secco: dice, non scrive.
        $secco = (new PuliziaMetadatiMappe($this->pdo))->esegui(false, $this->docente);
        $this->assertSame([$conLink, $soloSeme], array_column($secco['da_pulire'], 'id'));
        $this->assertSame([], $secco['pulite']);
        foreach ($prima as $id => $stato) {
            $this->assertSame($stato, $this->stato($id), "la prova a secco non tocca $id");
        }

        $esito = (new PuliziaMetadatiMappe($this->pdo))->esegui(true, $this->docente);

        $this->assertSame([$conLink, $soloSeme], $esito['pulite']);
        $this->assertSame([$corpoDiverso], $esito['corpo_diverso']);
        $this->assertSame([$soloLayout], $esito['solo_layout']);
        $this->assertSame([], $esito['cambiate_nel_frattempo']);

        $this->assertSame(['{"mappa":{"href":"https:\/\/example.org\/m","display":"show"}}', self::IERI], $this->stato($conLink));
        $this->assertSame([null, self::IERI], $this->stato($soloSeme), 'senza chiavi i metadati tornano NULL');
        $this->assertSame(1, $this->registrate($conLink), 'la pulizia lascia la sua riga nel registro');

        foreach ([$corpoDiverso, $soloLayout, $esercizio, $pulita] as $id) {
            $this->assertSame($prima[$id], $this->stato($id), "$id non si tocca");
            $this->assertSame(0, $this->registrate($id));
        }
    }

    #[Test]
    public function una_modifica_arrivata_dopo_la_lettura_vince(): void
    {
        $id = $this->riga('mappa', (string)json_encode(['layout' => 'exercises', 'body_pt' => PuliziaMetadatiMappe::SEME], JSON_UNESCAPED_UNICODE));
        // La riga cambia fra la lettura e la scrittura: lo si simula con un
        // PDO che, al momento di preparare l'UPDATE, riscrive prima la riga.
        $pdo = new class ($this->pdo, $id) extends PDO {
            public function __construct(private PDO $vero, private int $id)
            {
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (str_starts_with(ltrim($query), 'UPDATE')) {
                    $this->vero->prepare('UPDATE teacher_content_data SET metadata_json = ? WHERE id = ?')
                        ->execute(['{"mappa":{"display":"show"}}', $this->id]);
                }
                return $this->vero->prepare($query, $options);
            }
        };

        $esito = (new PuliziaMetadatiMappe($pdo))->esegui(true, $this->docente);

        $this->assertSame([], $esito['pulite']);
        $this->assertSame([$id], $esito['cambiate_nel_frattempo']);
        $this->assertSame('{"mappa":{"display":"show"}}', $this->stato($id)[0]);
    }

    /**
     * 20/9/2026, revisione della PR #145 — il controllo del corpo cifrato
     * stava dopo «non ha body_pt» e dopo il confronto con il seme, cioè dopo
     * due porte che una riga col corpo cifrato non passa mai: il dual-write
     * il `body_pt` lo toglie da `metadata_json` per cifrarlo. La riga finiva
     * fra le «lasciate stare, con il solo layout» e chi leggeva il rapporto
     * non sapeva che quella mappa un corpo ce l'ha.
     */
    #[Test]
    public function una_mappa_col_corpo_cifrato_si_elenca_per_quello_che_e(): void
    {
        $metadati = '{"layout":"exercises","mappa":{"display":"hide"}}';
        $cifrata = $this->rigaColCorpoCifrato($metadati);
        // Il caso che il dual-write non produce, ma che la difesa già copriva:
        // corpo in chiaro uguale al seme E colonna cifrata piena.
        $doppia = $this->rigaColCorpoCifrato(
            (string)json_encode(['layout' => 'exercises', 'body_pt' => PuliziaMetadatiMappe::SEME], JSON_UNESCAPED_UNICODE)
        );
        // Controprova: le stesse identiche righe senza le colonne cifrate.
        $inChiaro = $this->riga('mappa', $metadati);
        $prima = [$cifrata => $this->stato($cifrata), $doppia => $this->stato($doppia)];

        $esito = (new PuliziaMetadatiMappe($this->pdo))->esegui(true, $this->docente);

        $this->assertSame([$cifrata, $doppia], $esito['corpo_cifrato']);
        $this->assertSame([$inChiaro], $esito['solo_layout'], 'senza colonne cifrate resta una mappa col solo layout');
        $this->assertSame([], $esito['pulite']);
        foreach ($prima as $id => $stato) {
            $this->assertSame($stato, $this->stato($id), "$id non si tocca");
            $this->assertSame(0, $this->registrate($id));
        }
    }

    #[Test]
    public function il_seme_dello_strumento_e_quello_che_scriveva_il_modale(): void
    {
        $fixture = json_decode((string)file_get_contents(dirname(__DIR__) . '/Fixtures/seme-esercizi-body-pt.json'), true);
        // La stessa fixture la confronta tests/js-unit/modale-modifica-metadati.test.js
        // con exercisesSeedPt().
        $this->assertSame($fixture, PuliziaMetadatiMappe::SEME);
    }
}
