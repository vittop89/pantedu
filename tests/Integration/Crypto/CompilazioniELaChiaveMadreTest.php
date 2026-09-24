<?php

declare(strict_types=1);

namespace Tests\Integration\Crypto;

use App\Core\Database;
use App\Repositories\Risdoc\CompilationRepository;
use App\Services\Crypto\TeacherCryptoService;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le compilazioni dei documenti e la chiave madre (23/9/2026, A-36).
 *
 * `CompilationRepository::seal` cifra il JSON del docente se la chiave c'è.
 * Fino a oggi «c'è» voleva dire «è valida»: una chiave presente ma malformata
 * valeva come assente, e la compilazione — tutto quello che il docente scrive
 * nel documento — finiva in chiaro in `data_json`, senza una riga di errore.
 * Misurato sul codice di prima: `con_una_chiave_malformata_...` falliva perché
 * la riga si scriveva, in chiaro.
 *
 * Le tre chiavi, per la scrittura e per la lettura:
 *   - valida: si scrive cifrato, si rilegge;
 *   - assente (sviluppo senza chiave): si scrive in chiaro e si rilegge, come
 *     sempre;
 *   - malformata: non si scrive niente, e l'errore si registra senza il
 *     valore; una compilazione cifrata non si legge (vuota, non un 500).
 *
 * Tutto dentro una transazione annullata alla fine, anche se la prova
 * fallisce; `$_ENV` e il registro degli errori tornano com'erano.
 */
final class CompilazioniELaChiaveMadreTest extends TestCase
{
    private const VARIABILE = 'KMS_MASTER_KEY';
    private const DATI = '{"campi":{"obiettivi":"testo riservato del docente"}}';

    private PDO $pdo;
    private int $docente = 0;
    private int $modello = 0;
    private string $registro = '';
    private string|false $registroPrima = false;
    /** @var array{0:bool, 1:mixed} */
    private array $envPrima = [false, null];

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 3);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT data_ct FROM risdoc_compilations_data LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazione 101 non disponibili: ' . $e->getMessage());
        }
        $this->envPrima = [\array_key_exists(self::VARIABILE, $_ENV), $_ENV[self::VARIABILE] ?? null];

        $this->registro = sys_get_temp_dir() . '/pantedu-chiave-madre-' . bin2hex(random_bytes(6)) . '.log';
        $this->registroPrima = ini_get('error_log');
        ini_set('error_log', $this->registro);

        $this->pdo->beginTransaction();
        $nome = 'zz_chiave_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES (?, 'teacher', 'Zz', 'Chiave', ?, 'x', 'approved', 1)"
        )->execute([$nome, $nome . '@example.invalid']);
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO risdoc_templates (code, category, num_arg, argomento, source_dir, html_file, source_hash)
             VALUES (?, 'modelli', '00', 'Modello di prova', '/zz', 'zz.html', 'zz')"
        )->execute(['ZZ_CHIAVE_' . bin2hex(random_bytes(3))]);
        $this->modello = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        unset($_ENV[self::VARIABILE]);
        if ($this->envPrima[0]) {
            $_ENV[self::VARIABILE] = $this->envPrima[1];
        }
        if ($this->registro !== '') {
            ini_set('error_log', $this->registroPrima === false ? '' : $this->registroPrima);
            @unlink($this->registro);
        }
    }

    private function salva(CompilationRepository $repo, string $chiaveCompilazione): int
    {
        return $repo->save(
            $this->docente,
            $this->modello,
            $chiaveCompilazione,
            'Prova',
            null,
            null,
            null,
            null,
            self::DATI
        );
    }

    /** @return array{data_json:?string, data_ct:?string}|null */
    private function riga(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT data_json, data_ct FROM risdoc_compilations_data WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    private function righeDelDocente(): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM risdoc_compilations_data WHERE teacher_id = ?');
        $st->execute([$this->docente]);
        return (int)$st->fetchColumn();
    }

    private function registrato(): string
    {
        return is_file($this->registro) ? (string)file_get_contents($this->registro) : '';
    }

    #[Test]
    public function con_la_chiave_valida_si_scrive_cifrato_e_si_rilegge(): void
    {
        $repo = new CompilationRepository(new TeacherCryptoService(bin2hex(random_bytes(32))));
        $id = $this->salva($repo, 'combo_valida');

        $riga = $this->riga($id);
        self::assertNotNull($riga);
        self::assertNull($riga['data_json'], 'niente in chiaro');
        self::assertNotEmpty($riga['data_ct']);
        self::assertSame(json_decode(self::DATI, true), $repo->latestData($this->docente, $this->modello));
    }

    #[Test]
    public function senza_chiave_si_scrive_in_chiaro_come_sempre(): void
    {
        $_ENV[self::VARIABILE] = '';
        $repo = new CompilationRepository();
        $id = $this->salva($repo, 'combo_assente');

        $riga = $this->riga($id);
        self::assertNotNull($riga);
        self::assertSame(self::DATI, $riga['data_json']);
        self::assertNull($riga['data_ct']);
        self::assertSame(json_decode(self::DATI, true), $repo->latestData($this->docente, $this->modello));
        self::assertSame('', $this->registrato(), 'nessun errore: è il caso previsto');
    }

    #[Test]
    public function con_una_chiave_malformata_non_si_scrive_niente_e_l_errore_si_registra(): void
    {
        // Letta dall'ambiente, come in produzione: 32 byte in base64, cioè la
        // chiave giusta nella forma sbagliata.
        $malformata = base64_encode(random_bytes(32));
        $_ENV[self::VARIABILE] = $malformata;
        $repo = new CompilationRepository();

        $errore = null;
        try {
            $this->salva($repo, 'combo_malformata');
        } catch (\RuntimeException $e) {
            $errore = $e;
        }
        self::assertSame(0, $this->righeDelDocente(), 'nessuna riga, né in chiaro né altro');
        self::assertNotNull($errore, 'il salvataggio doveva fallire');
        self::assertStringContainsString('kms_malformata', $errore->getMessage());
        self::assertStringNotContainsString($malformata, $errore->getMessage());

        $registrato = $this->registrato();
        self::assertStringContainsString('[CompilationRepository]', $registrato);
        self::assertStringContainsString('malformata', $registrato);
        self::assertStringNotContainsString($malformata, $registrato, 'il valore non si registra');
    }

    #[Test]
    public function con_una_chiave_malformata_una_compilazione_cifrata_non_si_legge(): void
    {
        $cifrante = new CompilationRepository(new TeacherCryptoService(bin2hex(random_bytes(32))));
        $id = $this->salva($cifrante, 'combo_letta');
        self::assertNotEmpty($this->riga($id)['data_ct'] ?? null);

        $_ENV[self::VARIABILE] = 'zz-non-una-chiave';
        $repo = new CompilationRepository();
        self::assertSame([], $repo->latestData($this->docente, $this->modello));
        self::assertSame('', $repo->find($this->docente, $id)['data_json'] ?? null);
        self::assertStringContainsString('decifratura fallita', $this->registrato());
    }
}
