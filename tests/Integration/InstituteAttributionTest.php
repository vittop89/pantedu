<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Support\CurriculumLookup;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A quale istituto viene attribuito il lavoro di un docente che ne ha due.
 *
 * Prima vinceva sempre l'id piu' basso, e per un pluri-istituto era un errore
 * silenzioso: qualunque cosa scrivesse, ovunque stesse lavorando, finiva
 * agganciata al vocabolario della scuola con l'id minore. Sul Esempio sono
 * finiti cosi' 229 contenuti sul plesso sbagliato.
 *
 * Ora vince l'istituto corrente della sessione, ma solo quando si sa davvero
 * di chi e' e dove lavora. I casi in cui NON deve vincere contano quanto
 * quello in cui vince: un admin che agisce per conto d'altri non deve
 * attribuire alla propria scuola, e da CLI non c'e' sessione da leggere.
 *
 * DB-gated, tutto in transazione → rollback in tearDown.
 */
final class InstituteAttributionTest extends TestCase
{
    private PDO $pdo;
    private int $basso = 0;
    private int $alto = 0;
    private int $prof = 0;
    private int $altro = 0;
    private bool $inTx = false;
    /** @var array<string,mixed> */
    private array $sessionePrima = [];

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

        $this->sessionePrima = $_SESSION ?? [];
        $_SESSION = [];

        $this->pdo->beginTransaction();
        $this->inTx = true;

        // Due istituti: quello con id minore e' la trappola storica.
        $ins = $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)');
        $ins->execute(['ZZBASSO001', 'SCUOLA ID BASSO', 'Comune Esempio']);
        $this->basso = (int)$this->pdo->lastInsertId();
        $ins->execute(['ZZALTO0001', 'SCUOLA ID ALTO', 'Comune Esempio']);
        $this->alto = (int)$this->pdo->lastInsertId();
        $this->assertLessThan($this->alto, $this->basso, 'il primo istituto deve avere id minore');

        $this->prof  = $this->utente('zzattr', 'teacher');
        $this->altro = $this->utente('zzaltro', 'teacher');
        $link = $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)');
        $link->execute([$this->prof, $this->basso]);
        $link->execute([$this->prof, $this->alto]);
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = $this->sessionePrima;
    }

    private function utente(string $u, string $role): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, ?, "Zz", "Attr", ?, "x", "approved", 1, NOW())'
        )->execute([$u, $role, $u . '@example.invalid']);
        return (int)$this->pdo->lastInsertId();
    }

    /** Simula la sessione di un utente collegato con un istituto corrente. */
    private function sessione(int $userId, string $username, ?int $istitutoCorrente): void
    {
        $_SESSION = [
            'autenticato' => true,
            'username'    => $username,
            'user_id'     => $userId,
            'user_role'   => 'teacher',
        ];
        if ($istitutoCorrente !== null) {
            $_SESSION['current_institute_id'] = $istitutoCorrente;
        }
    }

    #[Test]
    public function senza_sessione_vince_l_id_piu_basso(): void
    {
        // E' il caso della CLI: nessuna sessione da leggere, e il comportamento
        // storico e' l'unica cosa sensata. Gli strumenti a riga di comando
        // passano l'istituto per argomento quando conta.
        $this->assertSame($this->basso, CurriculumLookup::instituteForTeacher($this->prof));
    }

    #[Test]
    public function con_la_propria_sessione_vince_l_istituto_corrente(): void
    {
        // Il caso che risolve il problema: il docente sta lavorando sulla
        // scuola con id piu' ALTO, ed e' li' che il suo lavoro va attribuito.
        $this->sessione($this->prof, 'zzattr', $this->alto);
        $this->assertSame($this->alto, CurriculumLookup::instituteForTeacher($this->prof));
    }

    #[Test]
    public function cambiare_scuola_nel_selettore_cambia_l_attribuzione(): void
    {
        // La cache non deve fissare la prima risposta: il selettore d'istituto
        // esiste proprio per passare da una scuola all'altra dentro la stessa
        // sessione.
        $this->sessione($this->prof, 'zzattr', $this->alto);
        $this->assertSame($this->alto, CurriculumLookup::instituteForTeacher($this->prof));

        $this->sessione($this->prof, 'zzattr', $this->basso);
        $this->assertSame($this->basso, CurriculumLookup::instituteForTeacher($this->prof));
    }

    #[Test]
    public function la_sessione_di_un_altro_non_conta(): void
    {
        // Un admin che agisce sui contenuti di un docente non deve attribuirli
        // alla PROPRIA scuola corrente: sarebbe un errore peggiore di quello
        // che stiamo correggendo, perche' scriverebbe su una scuola che col
        // docente non c'entra.
        $this->sessione($this->altro, 'zzaltro', $this->alto);
        $this->assertSame($this->basso, CurriculumLookup::instituteForTeacher($this->prof));
    }

    #[Test]
    public function un_istituto_a_cui_non_e_collegato_non_conta(): void
    {
        // Sessione sua, ma l'istituto corrente e' una scuola dove non lavora:
        // si torna al ripiego invece di scrivere dove non si deve.
        $orfano = $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)');
        $orfano->execute(['ZZORFANO01', 'SCUOLA ESTRANEA', 'Comune Esempio']);
        $estranea = (int)$this->pdo->lastInsertId();

        $this->sessione($this->prof, 'zzattr', $estranea);
        $this->assertSame($this->basso, CurriculumLookup::instituteForTeacher($this->prof));
    }

    #[Test]
    public function per_chi_ha_un_istituto_solo_non_cambia_niente(): void
    {
        // La stragrande maggioranza dei docenti: qualunque strada si prenda,
        // la risposta e' la stessa. Un cambio di comportamento che li toccasse
        // sarebbe una regressione, non una correzione.
        $solo = $this->utente('zzsolo', 'teacher');
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$solo, $this->alto]);

        $this->assertSame($this->alto, CurriculumLookup::instituteForTeacher($solo));
        $this->sessione($solo, 'zzsolo', $this->alto);
        $this->assertSame($this->alto, CurriculumLookup::instituteForTeacher($solo));
    }
}
