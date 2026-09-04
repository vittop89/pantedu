<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\InstituteRepository;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Una scuola nuova nasce solo con un codice MIUR vero.
 *
 * Il guard sta in upsertCanonical perche' i percorsi che creano un istituto
 * sono tre — wizard admin, collegamento del docente, iscrizione dello
 * studente — e tutti passano di li'. Metterlo nei controller avrebbe voluto
 * dire scrivere tre volte la stessa regola, e il quarto percorso che qualcuno
 * aggiungera' non l'avrebbe.
 *
 * La distinzione che conta e' fra CREARE e USARE: le righe sintetiche gia' a
 * tabella (l'istituto 108 e' nato cosi') restano valide e riconoscibili. Il
 * guard blocca solo le nuove.
 *
 * DB-gated, tutto in transazione → rollback in tearDown.
 */
final class InstituteMiurCodeGuardTest extends TestCase
{
    private PDO $pdo;
    private InstituteRepository $repo;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($base . '/app/Config');

        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        $this->repo = new InstituteRepository();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return list<array{0:string}> */
    public static function codiciFinti(): array
    {
        return [
            // Il formato che si generava in iscrizione: e' la forma con cui e'
            // nato l'istituto 108. Qui serve un codice che NON esista gia', o
            // upsertCanonical riuserebbe la riga invece di provare a crearla.
            ['MIUR-SCUOLA-MAI-VISTA-QUI'],
            ['SCUOLA-TEST'],
            ['ABC'],                 // troppo corto
            // Codici inventati su base XX: un codice reale con una cifra in
            // piu' restava nel clone pubblico, perche' la regola del sanitizer
            // cerca la parola intera (2026-09-04).
            ['XXPS00000A1'],         // un carattere di troppo
            ['X1PS00000A'],          // la provincia sono due LETTERE
            [''],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('codiciFinti')]
    public function un_codice_non_miur_non_crea_la_scuola(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->upsertCanonical($code, 'SCUOLA INVENTATA ' . uniqid(), 'Comune Esempio');
    }

    #[Test]
    public function una_forma_sintetica_gia_a_tabella_viene_riusata_non_rifiutata(): void
    {
        // Sfumatura che conta: upsertCanonical prima CERCA e solo dopo crea. Un
        // codice sintetico che esiste gia' non finisce mai nel guard, e non
        // deve: chi e' iscritto li' continua a entrare.
        $nome = 'SCUOLA SINTETICA ESISTENTE ' . uniqid();
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['MIUR-SINTETICA-ESISTE', $nome, 'Comune Esempio']);
        $id = (int)$this->pdo->lastInsertId();

        $this->assertSame($id, $this->repo->upsertCanonical('MIUR-SINTETICA-ESISTE', $nome, 'Comune Esempio'));
    }

    #[Test]
    public function un_codice_miur_vero_la_crea(): void
    {
        $id = $this->repo->upsertCanonical('VBPS99999Z', 'SCUOLA DI PROVA ' . uniqid(), 'Comune Esempio');
        $this->assertGreaterThan(0, $id);
    }

    #[Test]
    public function una_riga_sintetica_gia_esistente_resta_usabile(): void
    {
        // Il guard blocca la CREAZIONE, non l'uso: l'istituto 108 esiste, ha
        // docenti e contenuti dentro, e deve continuare a funzionare.
        $nome = 'SCUOLA STORICA ' . uniqid();
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['MIUR-STORICA-COMUNE ESEMPIO', $nome, 'Comune Esempio']);
        $id = (int)$this->pdo->lastInsertId();

        $this->assertSame($id, $this->repo->upsertCanonical('MIUR-STORICA-COMUNE ESEMPIO', $nome, 'Comune Esempio'));
    }

    #[Test]
    public function un_codice_reale_promuove_la_riga_sintetica(): void
    {
        // La cura per le righe nate prima del guard: quando arriva il codice
        // vero, la stessa scuola lo adotta invece di sdoppiarsi.
        $nome = 'SCUOLA DA PROMUOVERE ' . uniqid();
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['MIUR-PROMUOVERE-COMUNE ESEMPIO', $nome, 'Comune Esempio']);
        $id = (int)$this->pdo->lastInsertId();

        $this->assertSame($id, $this->repo->upsertCanonical('VBPS88888Y', $nome, 'Comune Esempio'));

        $st = $this->pdo->prepare('SELECT code FROM institutes WHERE id = ?');
        $st->execute([$id]);
        $this->assertSame('VBPS88888Y', (string)$st->fetchColumn(), 'la riga ha adottato il codice vero');
    }

    #[Test]
    public function il_riconoscitore_di_codici_e_quello_che_dice_di_essere(): void
    {
        foreach (['XXPS00000A', 'XXIS00000X', 'RMPS12345A'] as $vero) {
            $this->assertTrue(InstituteRepository::isRealMiurCode($vero), $vero);
        }
        foreach (['MIUR-ESEMPIO-COMUNE ESEMPIO-ART', 'XXPS00000A', 'VBPS-0010', ''] as $finto) {
            $this->assertFalse(InstituteRepository::isRealMiurCode($finto), $finto);
        }
    }
}
