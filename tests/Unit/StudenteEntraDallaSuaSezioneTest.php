<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Repositories\UserRepository;
use App\Services\BlockList;
use App\Services\RateLimiter;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Uno studente che arriva da un vecchio indirizzo di sezione entra nella sua
 * (23/9/2026, revisione architetturale A-75).
 *
 * Un redirect come `/eser_sc1s` porta con sé una sezione, e `Auth::attempt`
 * chiede a `User::canAccessSection()` se lo studente ci appartiene. La
 * risposta la dà `course`, che `UserRepository::hydrate` non passava mai: per
 * uno studente del database era sempre null, e il login da quegli indirizzi
 * falliva sempre con `unauthorized`, anche nella propria sezione. E dall'altra
 * parte il codice della sezione si componeva «sc1s», mentre il formato di
 * `course` è «sc.1s» (`StudentProfileService::course`, lo stesso della
 * registrazione): passare `course` da solo non sarebbe bastato.
 *
 * Sul codice di prima `il_corso_arriva_dal_database` e
 * `lo_studente_entra_dalla_sua_sezione` fallivano; le altre due sono la
 * controprova che il controllo non risponde sempre sì, nemmeno a una sezione
 * con lo stesso indirizzo o la stessa classe.
 */
final class StudenteEntraDallaSuaSezioneTest extends TestCase
{
    private const PASSWORD = 'segreto-di-prova';

    private ?bool $dbAbilitatoPrima = null;

    protected function setUp(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        $this->dbAbilitatoPrima = (bool)Config::get('database.enabled');
        Config::set('database.enabled', true);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Le colonne di `users` che il repository legge; indirizzo e classe
        // dalla migrazione 091.
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, role TEXT,
            first_name TEXT, last_name TEXT, email TEXT, password_hash TEXT,
            active INTEGER, created_at TEXT, indirizzo TEXT, classe TEXT
        )');
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $ins = $pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                active, created_at, indirizzo, classe)
             VALUES (?, ?, "Zz", "Prova", ?, ?, 1, "2026-09-01 08:00:00", ?, ?)'
        );
        $ins->execute(['zz_studente', 'student', 'zz_studente@example.invalid', $hash, 'sc', '1s']);
        $ins->execute(['zz_senza_classe', 'student', 'zz_senza_classe@example.invalid', $hash, null, null]);
        $ins->execute(['zz_docente', 'teacher', 'zz_docente@example.invalid', $hash, null, null]);
        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $pdo);
    }

    protected function tearDown(): void
    {
        Database::reset();
        Config::set('database.enabled', $this->dbAbilitatoPrima ?? true);
    }

    /**
     * @param array{indirizzo:string,classe:string} $sezione
     * @return array{0: ?\App\Domain\User, 1: ?string}
     */
    private function accedi(string $utente, array $sezione): array
    {
        $vuoto = sys_get_temp_dir() . '/pantedu-nessun-blocco-' . bin2hex(random_bytes(4)) . '.json';
        return Auth::attempt(
            $utente,
            self::PASSWORD,
            $sezione,
            null,
            new UserRepository(),
            new BlockList($vuoto, $vuoto),
            new RateLimiter('zz_prova_sezione_' . bin2hex(random_bytes(4))),
            establishSession: false,
        );
    }

    #[Test]
    public function il_corso_arriva_dal_database(): void
    {
        self::assertSame('sc.1s', (new UserRepository())->find('zz_studente')?->course);
        self::assertNull((new UserRepository())->find('zz_senza_classe')?->course, 'senza classe, nessun corso');
    }

    #[Test]
    public function lo_studente_entra_dalla_sua_sezione(): void
    {
        // Come la ricava Auth::sectionFromUrl() da un redirect `/eser_sc1s`.
        [$utente, $motivo] = $this->accedi('zz_studente', ['indirizzo' => 'sc', 'classe' => '1s']);

        self::assertNull($motivo);
        self::assertSame('zz_studente', $utente?->username);
    }

    #[Test]
    public function lo_studente_non_entra_da_un_altra_sezione(): void
    {
        [$utente, $motivo] = $this->accedi('zz_studente', ['indirizzo' => 'ar', 'classe' => '5s']);
        self::assertNull($utente);
        self::assertSame(Auth::REASON_UNAUTHORIZED, $motivo);

        // Lo stesso indirizzo con un'altra classe, e la stessa classe in un
        // altro indirizzo: il confronto è sul corso intero, non su una metà.
        [$utente, $motivo] = $this->accedi('zz_studente', ['indirizzo' => 'sc', 'classe' => '2s']);
        self::assertNull($utente, 'da sc.1s non si entra in sc.2s');
        self::assertSame(Auth::REASON_UNAUTHORIZED, $motivo);
        [$utente, $motivo] = $this->accedi('zz_studente', ['indirizzo' => 'ar', 'classe' => '1s']);
        self::assertNull($utente, 'da sc.1s non si entra in ar.1s');
        self::assertSame(Auth::REASON_UNAUTHORIZED, $motivo);

        [$utente, $motivo] = $this->accedi('zz_senza_classe', ['indirizzo' => 'sc', 'classe' => '1s']);
        self::assertNull($utente, 'uno studente senza classe non ha una sezione in cui entrare');
        self::assertSame(Auth::REASON_UNAUTHORIZED, $motivo);
    }

    #[Test]
    public function il_docente_entra_da_qualunque_sezione(): void
    {
        [$utente, $motivo] = $this->accedi('zz_docente', ['indirizzo' => 'ar', 'classe' => '5s']);
        self::assertNull($motivo);
        self::assertSame('zz_docente', $utente?->username);
    }
}
