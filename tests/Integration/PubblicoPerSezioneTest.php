<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\PublicStudyController;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\TeacherContentRepository;
use App\Services\Study\PublicContentPolicy;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Senza login si vede quello che sta in una sezione pubblica, e nella sidepage
 * di quella sezione (16/9/2026).
 *
 * Segnalato dall'utente: la mappa «Calcolatrice grafica (FX-CG50)», creata nel
 * Laboratorio del docente che pubblica in rete, compariva nella sidepage Mappe
 * della home senza login. Misurato in produzione: il Laboratorio non è pubblico,
 * la lista per tipo (`type=mappa`) prendeva tutte le mappe pubblicate del
 * docente in qualunque sezione, e la vista pubblica della mappa rispondeva 404
 * (quella guarda la sezione). Adesso la lista per tipo resta nelle sezioni
 * pubbliche di quel tipo, e la sidepage dei visitatori chiede per sezione.
 *
 * Nei due versi, tutto in transazione: Mappe pubblica, Laboratorio no.
 */
final class PubblicoPerSezioneTest extends TestCase
{
    private PDO $pdo;
    private TeacherContentRepository $repo;
    private string $argomento = '';
    /** @var array<string, mixed> */
    private array $getPrima = [];
    /** @var array<string, int> */
    private array $id = [];

    protected function setUp(): void
    {
        if (!Database::isAvailable()) {
            self::markTestSkipped('database non disponibile');
        }
        $this->pdo = Database::connection();
        $this->repo = new TeacherContentRepository();
        $sezioni = $this->pdo->query("SELECT section_key, id FROM sidebar_sections WHERE institute_id = 0 AND section_key IN ('mappe', 'lab')")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($sezioni) !== 2) {
            self::markTestSkipped('il modello globale del database di prova non ha le sezioni mappe e lab');
        }
        $this->getPrima = $_GET;
        $this->pdo->beginTransaction();

        $this->pdo->exec("UPDATE sidebar_sections SET publish_public = 1, active = 1 WHERE institute_id = 0 AND section_key = 'mappe'");
        $this->pdo->exec("UPDATE sidebar_sections SET publish_public = 0 WHERE section_key = 'lab'");
        $nome = 'zzpps' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Rete", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        $docente = (int)$this->pdo->lastInsertId();
        PublicContentPolicy::scegliPerLaRete($docente);

        $this->argomento = 'PPS' . bin2hex(random_bytes(4));
        $crea = fn(string $tipo, int $sezione, string $visibilita = 'published'): int => $this->repo->create([
            'teacher_id'   => $docente,
            'content_type' => $tipo,
            'section_id'   => $sezione,
            'subject_code' => 'MAT',
            'topic'        => $this->argomento,
            'title'        => "$tipo $visibilita in sezione $sezione",
            'body_html'    => '<p>x</p>',
            'visibility'   => $visibilita,
        ]);
        $this->id = [
            'mappa_in_mappe'     => $crea('mappa', (int)$sezioni['mappe']),
            'esercizio_in_mappe' => $crea('esercizio', (int)$sezioni['mappe']),
            'mappa_in_lab'       => $crea('mappa', (int)$sezioni['lab']),
            'bozza_in_mappe'     => $crea('mappa', (int)$sezioni['mappe'], 'draft'),
        ];
    }

    protected function tearDown(): void
    {
        $_GET = $this->getPrima;
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * @param array<string, mixed> $filtri
     * @return list<int>
     */
    private function trovati(array $filtri): array
    {
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $this->repo->search($filtri + ['limit' => 500]));
        $miei = array_values(array_intersect($ids, $this->id));
        sort($miei);
        return $miei;
    }

    /** @return list<int> */
    private function attesi(string ...$chiavi): array
    {
        $ids = array_map(fn(string $k): int => $this->id[$k], $chiavi);
        sort($ids);
        return $ids;
    }

    /**
     * @param array<string, string> $query
     * @return list<int>
     */
    private function elencoPubblico(array $query): array
    {
        $_GET = $query + ['topic' => $this->argomento, 'limit' => '500'];
        $risposta = (new PublicStudyController())->publicContentJson(new Request());
        $this->assertSame(200, $risposta->status, (string)$risposta->body);
        $corpo = json_decode((string)$risposta->body, true);
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $corpo['rows'] ?? []);
        $miei = array_values(array_intersect($ids, $this->id));
        sort($miei);
        return $miei;
    }

    #[Test]
    public function la_lista_per_tipo_resta_nelle_sezioni_pubbliche_di_quel_tipo(): void
    {
        $filtri = PublicContentPolicy::scopedFilters(['topic' => $this->argomento], 'mappa');
        $this->assertFalse(PublicContentPolicy::isDeny($filtri));
        $this->assertSame($this->attesi('mappa_in_mappe'), $this->trovati($filtri), 'la mappa del Laboratorio non esce in rete');
        $this->assertSame($this->attesi('mappa_in_mappe'), $this->elencoPubblico(['type' => 'mappa']));
    }

    #[Test]
    public function la_lista_per_sezione_da_tutti_i_tipi_pubblicati_di_una_sezione_pubblica(): void
    {
        $filtri = PublicContentPolicy::scopedFiltersPerSezione(['topic' => $this->argomento], 'mappe');
        $this->assertFalse(PublicContentPolicy::isDeny($filtri));
        $this->assertSame($this->attesi('mappa_in_mappe', 'esercizio_in_mappe'), $this->trovati($filtri));
        $this->assertSame($this->attesi('mappa_in_mappe', 'esercizio_in_mappe'), $this->elencoPubblico(['section' => 'mappe']));
        // La sezione vince sul tipo, come per gli studenti.
        $this->assertSame($this->attesi('mappa_in_mappe', 'esercizio_in_mappe'), $this->elencoPubblico(['section' => 'mappe', 'type' => 'mappa']));
    }

    #[Test]
    public function una_sezione_non_pubblica_non_da_niente(): void
    {
        $this->assertTrue(PublicContentPolicy::isDeny(PublicContentPolicy::scopedFiltersPerSezione([], 'lab')));
        $this->assertTrue(PublicContentPolicy::isDeny(PublicContentPolicy::scopedFiltersPerSezione([], 'sezione_inesistente')));
        $this->assertSame([], $this->elencoPubblico(['section' => 'lab']));
    }

    #[Test]
    public function quello_che_la_lista_mostra_si_apre_e_quello_che_non_si_apre_non_si_mostra(): void
    {
        $mostrati = array_unique(array_merge(
            $this->elencoPubblico(['type' => 'mappa']),
            $this->elencoPubblico(['type' => 'esercizio']),
            $this->elencoPubblico(['section' => 'mappe']),
            $this->elencoPubblico(['section' => 'lab']),
        ));
        foreach ($this->id as $chiave => $id) {
            $riga = $this->repo->find($id);
            $this->assertNotNull($riga);
            $this->assertSame(
                PublicContentPolicy::isPublic($riga),
                in_array($id, $mostrati, true),
                "$chiave: la lista pubblica e la vista pubblica devono dire la stessa cosa"
            );
        }
    }
}
