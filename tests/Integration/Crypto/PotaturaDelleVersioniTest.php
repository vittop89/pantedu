<?php

declare(strict_types=1);

namespace Tests\Integration\Crypto;

use App\Core\Config;
use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\GitHub\GitHubSyncService;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `rotate_kek.php --prune-old-kv` non cancella più le versioni di chiave che
 * servono ancora (23/9/2026, misurato lavorando su A-88).
 *
 * ── Che cosa si è misurato ────────────────────────────────────────────────
 *
 * `--reencrypt` ricifra con la versione nuova solo `body_html` e `body_pt`
 * dei contenuti. Tutto il resto che è cifrato con la KEK del docente —
 * compilazioni risdoc, verifiche, mappe, gettoni di Drive e GitHub, file
 * dell'import — resta con la versione con cui è stato scritto, e la porta
 * scritta accanto (`pat_kv`, `data_kv`, …). `--prune-old-kv` cancellava poi
 * tutte le versioni più vecchie della nuova: quei dati non si aprivano più.
 * Misurato il 23/9/2026 con questa prova sullo script di prima: uscita 0,
 * «pruned old kv: 1 rows», e il gettone GitHub del docente illeggibile
 * (`GitHubSyncService` lo legge come assente).
 *
 * ── Come ──────────────────────────────────────────────────────────────────
 *
 * Un docente creato qui, con la chiave master generata qui: un contenuto con
 * il corpo cifrato e un gettone GitHub cifrato come lo scrive
 * `GitHubSyncService::configure()`, tutti e due con la versione 1. Poi lo
 * script vero, in un processo a parte (`rotate_kek_nella_prova.php`: database
 * delle prove, la chiave della prova, i registri append-only coperti da
 * tabelle temporanee), limitato a quel docente. Si guarda che cosa resta
 * leggibile con i servizi veri. Le righe della prova si cancellano in
 * tearDown, che gira anche quando la prova fallisce; il gettone non è un
 * gettone vero.
 */
final class PotaturaDelleVersioniTest extends TestCase
{
    private const CORPO = '<p>corpo del contenuto della prova</p>';
    private const GETTONE = 'gettone-di-prova-non-valido-0123456789';

    private PDO $pdo;
    private string $a = '';
    private int $docente = 0;
    private int $contenuto = 0;

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 3);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        Config::set('crypto.allow_regenerate', false);
        if (!Database::isAvailable()) {
            $this->markTestSkipped('DB non disponibile');
        }
        $this->pdo = Database::connection();
        foreach (['crypto_access_log', 'teacher_recovery_audit'] as $registro) {
            $this->pdo->exec("CREATE TEMPORARY TABLE zz_ombra_$registro LIKE $registro");
            $this->pdo->exec("ALTER TABLE zz_ombra_$registro RENAME TO $registro");
        }

        $this->a = bin2hex(random_bytes(32));
        $nome = 'zzpot' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, is_super_admin, created_at)
             VALUES (?, "teacher", "Zz", "Potatura", ?, "x", "approved", 1, 0, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        $this->docente = (int)$this->pdo->lastInsertId();

        $servizio = new TeacherCryptoService($this->a);
        $corpo = $servizio->encrypt($this->docente, self::CORPO);
        $this->pdo->prepare(
            'INSERT INTO teacher_content_data
                (teacher_id, content_subtype, title, body_html_ct, body_html_iv, body_html_tag, body_html_kv)
             VALUES (?, "document", ?, ?, ?, ?, ?)'
        )->execute([$this->docente, $nome, $corpo['ciphertext'], $corpo['iv'], $corpo['tag'], $corpo['kv']]);
        $this->contenuto = (int)$this->pdo->lastInsertId();

        // La stessa forma che scrive GitHubSyncService::configure(): iv, tag e
        // testo cifrato in un blob solo, e la versione accanto.
        $gettone = $servizio->encrypt($this->docente, self::GETTONE);
        $this->pdo->prepare(
            'INSERT INTO teacher_github_sync (user_id, repo_owner, repo_name, branch, pat_encrypted, pat_kv)
             VALUES (?, "zz", "zz", "main", ?, ?)'
        )->execute([$this->docente, $gettone['iv'] . $gettone['tag'] . $gettone['ciphertext'], $gettone['kv']]);

        self::assertSame(1, $corpo['kv']);
        self::assertSame(1, $gettone['kv']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->docente > 0) {
            foreach (
                [
                    'DELETE FROM teacher_github_sync WHERE user_id = ?',
                    'DELETE FROM teacher_content_data WHERE teacher_id = ?',
                    'DELETE FROM teacher_keys WHERE teacher_id = ?',
                    'DELETE FROM users WHERE id = ?',
                ] as $sql
            ) {
                $this->pdo->prepare($sql)->execute([$this->docente]);
            }
        }
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS crypto_access_log');
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS teacher_recovery_audit');
    }

    #[Test]
    public function reencrypt_ricifra_i_corpi_e_lascia_il_resto_alla_versione_vecchia(): void
    {
        [$codice, $uscita] = $this->script(['--teacher=' . $this->docente, '--reencrypt']);

        self::assertSame(0, $codice, $uscita);
        self::assertSame([1, 2], $this->versioni(), 'la rotazione aggiunge la versione 2 e tiene la 1');
        self::assertSame(2, $this->colonna('SELECT body_html_kv FROM teacher_content_data WHERE id = ?', $this->contenuto));
        self::assertSame(1, $this->colonna('SELECT pat_kv FROM teacher_github_sync WHERE user_id = ?', $this->docente));
        self::assertSame(self::CORPO, $this->corpo(), $uscita);
        self::assertSame(self::GETTONE, $this->gettone(), $uscita);
    }

    #[Test]
    public function prune_old_kv_si_rifiuta_e_non_toglie_la_versione_che_serve_al_gettone(): void
    {
        [$codice, $uscita] = $this->script(['--teacher=' . $this->docente, '--reencrypt', '--prune-old-kv']);

        // Prima i dati, poi il codice d'uscita: sul codice di prima questa è la
        // riga che fallisce, e dice che cosa si è perso.
        self::assertSame(self::GETTONE, $this->gettone(), "il gettone con la versione 1 non si legge più (uscita $codice)\n$uscita");
        self::assertSame([1], $this->versioni(), "rifiutato prima di ruotare: nessuna versione nuova\n$uscita");
        self::assertSame(self::CORPO, $this->corpo());
        self::assertSame(2, $codice, $uscita);
        self::assertStringContainsString('--prune-old-kv è disattivato', $uscita);
    }

    // ── strumenti ─────────────────────────────────────────────────────────

    /**
     * @param list<string> $argomenti
     * @return array{0: int, 1: string} codice d'uscita, uscita ed errori insieme
     */
    private function script(array $argomenti): array
    {
        $comando = array_merge([PHP_BINARY, __DIR__ . '/rotate_kek_nella_prova.php'], $argomenti);
        // Un ambiente minimo, come MigrazioneCheFallisceTest: il figlio legge
        // .env e .env.local da sé, e la chiave della prova arriva a parte.
        $ambiente = [
            'APP_ENV'              => 'testing',
            'PATH'                 => (string)getenv('PATH'),
            'HOME'                 => (string)getenv('HOME'),
            'PROVA_KMS_MASTER_KEY' => $this->a,
        ];
        // stdin chiuso subito: la conferma «ENTER» dello script legge la fine
        // del file e prosegue, invece di aspettare il terminale.
        $proc = proc_open(
            $comando,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__, 3),
            $ambiente
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $uscita = (string)stream_get_contents($pipes[1]);
        $errori = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $codice = proc_close($proc);
        self::assertStringNotContainsString($this->a, $uscita . $errori, 'lo script non ripete la chiave');
        return [$codice, $uscita . $errori];
    }

    /** @return list<int> */
    private function versioni(): array
    {
        $st = $this->pdo->prepare('SELECT key_version FROM teacher_keys WHERE teacher_id = ? ORDER BY key_version');
        $st->execute([$this->docente]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    private function colonna(string $sql, int $id): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute([$id]);
        return (int)$st->fetchColumn();
    }

    private function corpo(): ?string
    {
        $st = $this->pdo->prepare(
            'SELECT body_html_ct, body_html_iv, body_html_tag, body_html_kv FROM teacher_content_data WHERE id = ?'
        );
        $st->execute([$this->contenuto]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($r);
        try {
            return (new TeacherCryptoService($this->a))->decrypt($this->docente, [
                'ciphertext' => (string)$r['body_html_ct'],
                'iv'         => (string)$r['body_html_iv'],
                'tag'        => (string)$r['body_html_tag'],
                'kv'         => (int)$r['body_html_kv'],
            ]);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Il gettone come lo legge il servizio: GitHubSyncService::loadPat() è
     * privata, e restituisce null quando non riesce a decifrare.
     */
    private function gettone(): ?string
    {
        $leggi = new \ReflectionMethod(GitHubSyncService::class, 'loadPat');
        $valore = $leggi->invoke(new GitHubSyncService(new TeacherCryptoService($this->a)), $this->docente);
        return \is_string($valore) ? $valore : null;
    }
}
