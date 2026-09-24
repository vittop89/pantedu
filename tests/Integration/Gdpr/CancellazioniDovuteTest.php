<?php

declare(strict_types=1);

namespace Tests\Integration\Gdpr;

use App\Core\Config;
use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Gdpr\DeletionRequestService;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le cancellazioni dell'art. 17 si eseguono quando sono dovute, e soltanto
 * quelle (23/9/2026).
 *
 * `DeletionRequestService::executeOverdue()` è quello che il timer
 * `pantedu-gdpr-deletions` lancia ogni giorno: per ogni richiesta confermata
 * e arrivata a fine periodo di ripensamento distrugge le chiavi del docente
 * (i suoi contenuti cifrati diventano illeggibili) e anonimizza l'account.
 * Nessuna prova lo sorvegliava (revisione del 23/9/2026, A-24). Un errore
 * qui ha due facce, e tutte e due sono gravi: una cancellazione promessa che
 * non avviene, o una che avviene a chi non l'ha chiesta o ci ha ripensato.
 *
 * ── I due versi, nello stesso giro ────────────────────────────────────────
 *
 * Il verso «deve cancellare» è l'azione distruttiva: da solo lo passerebbe una
 * funzione che cancella tutti. Per questo, accanto al docente la cui richiesta
 * è dovuta, ce ne sono quattro che devono restare intatti — richiesta non
 * ancora scaduta, annullata, mai confermata, con il link scaduto — e si
 * controlla che lo siano alla fine dello stesso giro, chiavi comprese.
 *
 * Le richieste si creano con il servizio vero (`request`, `confirm`,
 * `cancel`); si sposta all'indietro soltanto `execute_after`, al posto dei
 * trenta giorni di attesa.
 *
 * ── Niente resta nel database, e niente di altri si tocca ─────────────────
 *
 * `executeOverdue()` apre una transazione sua, quindi la prova non può stare
 * dentro una transazione: crea utenti propri, con nome unico e marca
 * temporale, e in tearDown li cancella (a cascata richieste e chiavi) insieme
 * alle righe del registro della cifratura che li riguardano, anche quando la
 * prova fallisce. Le chiavi sono generate dalla prova con una chiave del KMS
 * casuale, che non è quella di nessuna istanza.
 *
 * Il servizio sceglie da sé, su tutta la tabella, quali richieste eseguire.
 * Se la scelta si allargasse per un difetto — proprio quello che questa prova
 * cerca — toccherebbe le richieste di altri, anche non dovute. Per questo la
 * prova esegue solo se in `deletion_requests` non c'è nessuna riga che non ha
 * creato lei: altrimenti si ferma, invece di cancellare account non suoi. Nel
 * database delle prove (`pantedu_test`, e quello nuovo della CI) la tabella è
 * vuota.
 */
final class CancellazioniDovuteTest extends TestCase
{
    private PDO $pdo;
    private TeacherCryptoService $cifra;
    private DeletionRequestService $servizio;
    private string $marca = '';
    /** @var list<int> */
    private array $utenti = [];

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 3);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM deletion_requests LIMIT 0');
            $this->pdo->query('SELECT 1 FROM teacher_keys LIMIT 0');
            $this->pdo->query('SELECT 1 FROM crypto_access_log LIMIT 0');
        } catch (\Throwable $e) {
            self::markTestSkipped('DB o tabelle dell\'art. 17 non disponibili: ' . $e->getMessage());
        }

        $this->cifra    = new TeacherCryptoService(bin2hex(random_bytes(32)));
        $this->servizio = new DeletionRequestService($this->cifra);
        $this->marca    = date('YmdHis') . '_' . bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        if ($this->utenti === [] || !isset($this->pdo)) {
            return;
        }
        $segnaposti = implode(',', array_fill(0, count($this->utenti), '?'));
        // Il registro della cifratura non ha una chiave esterna verso users:
        // le sue righe della prova si tolgono a mano. Il resto va a cascata.
        $this->pdo->prepare("DELETE FROM crypto_access_log WHERE teacher_id IN ($segnaposti)")
            ->execute($this->utenti);
        $this->pdo->prepare("DELETE FROM users WHERE id IN ($segnaposti)")
            ->execute($this->utenti);
        $this->utenti = [];
    }

    /** Un docente di prova, con una chiave e un contenuto cifrato. @return array{int, array{ciphertext: string, iv: string, tag: string, kv: int}} */
    private function docente(string $nome): array
    {
        $username = 'zz_oblio_' . $this->marca . '_' . $nome;
        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES (?, 'teacher', 'Zz', 'Oblio', ?, ?, 'approved', 1)",
        )->execute([$username, $username . '@example.test', 'x']);
        $id = (int)$this->pdo->lastInsertId();
        $this->utenti[] = $id;

        return [$id, $this->cifra->encrypt($id, 'contenuto di prova di ' . $nome)];
    }

    private function scadenzaNelPassato(int $utente): void
    {
        $this->pdo->prepare(
            'UPDATE deletion_requests SET execute_after = NOW() - INTERVAL 1 MINUTE WHERE user_id = ?',
        )->execute([$utente]);
    }

    /** @return array<string,mixed> */
    private function account(int $id): array
    {
        $st = $this->pdo->prepare('SELECT email, first_name, last_name, password_hash, active, deleted_at FROM users WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($r, "l'utente {$id} c'è ancora");
        return $r;
    }

    private function statoRichiesta(int $utente): string
    {
        $st = $this->pdo->prepare('SELECT status FROM deletion_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$utente]);
        return (string)$st->fetchColumn();
    }

    private function chiavi(int $utente): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM teacher_keys WHERE teacher_id = ?');
        $st->execute([$utente]);
        return (int)$st->fetchColumn();
    }

    /**
     * Le richieste che NON sono della prova, di qualunque stato: un
     * `executeOverdue()` difettoso potrebbe prenderle.
     */
    private function richiesteDiAltri(): int
    {
        if ($this->utenti === []) {
            return (int)$this->pdo->query('SELECT COUNT(*) FROM deletion_requests')->fetchColumn();
        }
        $segnaposti = implode(',', array_fill(0, count($this->utenti), '?'));
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM deletion_requests WHERE user_id NOT IN ($segnaposti)");
        $st->execute($this->utenti);
        return (int)$st->fetchColumn();
    }

    private function fermatiSeCeNeSonoDiAltri(): void
    {
        if (($altre = $this->richiesteDiAltri()) > 0) {
            self::fail("In deletion_requests ci sono {$altre} righe che non sono di questa prova: "
                . 'executeOverdue() lavora su tutta la tabella, e la prova si ferma per non toccarle.');
        }
    }

    #[Test]
    public function si_esegue_solo_la_richiesta_confermata_e_scaduta(): void
    {
        $this->fermatiSeCeNeSonoDiAltri();

        // Dovuta: confermata, e i trenta giorni sono passati.
        [$dovuto, $busta] = $this->docente('dovuto');
        self::assertTrue($this->servizio->confirm($this->servizio->request($dovuto, 'prova')));
        $this->scadenzaNelPassato($dovuto);

        // Confermata ma dentro il periodo di ripensamento.
        [$inAttesa, $bustaInAttesa] = $this->docente('in_attesa');
        self::assertTrue($this->servizio->confirm($this->servizio->request($inAttesa)));

        // Confermata, poi annullata; la scadenza sarebbe passata.
        [$ripensato, $bustaRipensato] = $this->docente('ripensato');
        self::assertTrue($this->servizio->confirm($this->servizio->request($ripensato)));
        self::assertTrue($this->servizio->cancel($ripensato));
        $this->scadenzaNelPassato($ripensato);

        // Mai confermata (link della email non aperto); scadenza nel passato.
        [$maiConfermato, $bustaMai] = $this->docente('mai_confermato');
        $this->servizio->request($maiConfermato);
        $this->scadenzaNelPassato($maiConfermato);

        // Link aperto dopo la scadenza del gettone: la conferma non vale.
        [$linkScaduto, $bustaScaduto] = $this->docente('link_scaduto');
        $token = $this->servizio->request($linkScaduto);
        $this->pdo->prepare('UPDATE deletion_requests SET expires_at = NOW() - INTERVAL 1 DAY WHERE user_id = ?')
            ->execute([$linkScaduto]);
        self::assertFalse($this->servizio->confirm($token), 'gettone scaduto');
        $this->scadenzaNelPassato($linkScaduto);

        $this->fermatiSeCeNeSonoDiAltri();
        $esito = $this->servizio->executeOverdue();

        self::assertSame(['processed' => 1, 'succeeded' => 1, 'failed' => 0, 'errors' => []], $esito);

        // ── Il docente dovuto: account anonimizzato, chiavi distrutte ──
        $a = $this->account($dovuto);
        self::assertSame("anon-{$dovuto}@invalid.local", $a['email']);
        self::assertSame('', $a['first_name']);
        self::assertSame('', $a['last_name']);
        self::assertSame('', $a['password_hash']);
        self::assertSame(0, (int)$a['active']);
        self::assertNotNull($a['deleted_at']);
        self::assertSame('executed', $this->statoRichiesta($dovuto));
        self::assertSame(0, $this->chiavi($dovuto), 'crypto-shredding: le chiavi non ci sono più');
        try {
            $this->cifra->decrypt($dovuto, $busta);
            self::fail('il contenuto cifrato del docente cancellato si legge ancora');
        } catch (\RuntimeException) {
            // atteso: senza chiave non si decifra
        }
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM crypto_access_log WHERE teacher_id = ? AND operation = 'shred' AND reason = 'art_17_self_service_deletion'",
        );
        $st->execute([$dovuto]);
        self::assertSame(1, (int)$st->fetchColumn(), 'la distruzione delle chiavi è nel registro');

        // ── Gli altri quattro: intatti ──
        foreach (
            [
                'in attesa'      => [$inAttesa, $bustaInAttesa, 'cooling_off', 'in_attesa'],
                'ripensato'      => [$ripensato, $bustaRipensato, 'cancelled', 'ripensato'],
                'mai confermato' => [$maiConfermato, $bustaMai, 'pending_confirm', 'mai_confermato'],
                'link scaduto'   => [$linkScaduto, $bustaScaduto, 'expired', 'link_scaduto'],
            ] as $caso => [$id, $b, $stato, $nome]
        ) {
            $r = $this->account($id);
            self::assertSame('zz_oblio_' . $this->marca . '_' . $nome . '@example.test', $r['email'], $caso);
            self::assertSame('Zz', $r['first_name'], $caso);
            self::assertSame(1, (int)$r['active'], $caso);
            self::assertNull($r['deleted_at'], $caso);
            self::assertSame($stato, $this->statoRichiesta($id), $caso);
            self::assertSame(1, $this->chiavi($id), "{$caso}: chiavi al loro posto");
            self::assertSame('contenuto di prova di ' . $nome, $this->cifra->decrypt($id, $b), $caso);
        }

        // Il giro del giorno dopo non rifà niente. Stessa cautela di prima.
        $this->fermatiSeCeNeSonoDiAltri();
        self::assertSame(['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'errors' => []], $this->servizio->executeOverdue());
    }
}
