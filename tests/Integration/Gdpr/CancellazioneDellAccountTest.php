<?php

declare(strict_types=1);

namespace Tests\Integration\Gdpr;

use App\Core\Config;
use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Gdpr\AnonimizzazioneDegliInattivi;
use App\Services\Gdpr\CancellazioneDellAccount;
use App\Services\Gdpr\DeletionRequestService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La cancellazione di un account (art. 17 e 730 giorni di inattività) non
 * lascia dati della persona fuori da quello che i documenti dicono che resta,
 * e non tocca niente di un altro docente (24/9/2026, rilievo DOC-18).
 *
 * ── Che cosa si prepara ────────────────────────────────────────────────────
 *
 * Due docenti con gli stessi dati: una riga di `users` completa (email, nome,
 * scuola, indirizzo, classe, data di nascita, secondo fattore), scuola e
 * incarico, una classe spuntata, un esercizio con titolo, argomento e corpo in
 * chiaro e il suo contratto su disco, una mappa cifrata con il suo blob, una
 * versione precedente, le preferenze di stampa con i contatori DSA/DIS, il
 * verbale dei Termini con IP e User-Agent, il collegamento a Drive con l'email
 * Google, una credenziale di classe, una richiesta di cancellazione con un
 * motivo scritto a mano, e i file che l'applicazione scrive a nome loro
 * (scelte, stampe, modelli, importazioni dai PDF). Ogni testo porta un
 * segno unico del docente.
 *
 * ── I due versi ────────────────────────────────────────────────────────────
 *
 * Dopo la cancellazione del primo: il suo segno, il suo nome utente e il suo
 * IP non si trovano più in nessuna colonna di testo del database (tolte le
 * tabelle `waf_*`, che la prova non scrive e che da sole valgono sei secondi
 * di scansione) né in nessun file dei dati; le sue righe non ci sono più e la
 * sua riga di `users` è il segnaposto. Il secondo, il collega, è identico a
 * prima: righe, file, chiave che decifra, segno ritrovato nel database. Un
 * collegamento simbolico dalla cartella del primo a un file del collega si
 * toglie come collegamento, e il file del collega resta.
 *
 * ── Niente resta ───────────────────────────────────────────────────────────
 *
 * La routine apre transazioni sue e la prova non può stare dentro una
 * transazione: crea utenti, una scuola e una voce del curricolo con un nome
 * unico, e in tearDown li cancella (a cascata il resto) con le righe senza
 * chiave esterna (versioni, registro della cifratura), anche se la prova
 * fallisce. I file stanno in una cartella temporanea, che si cancella; la
 * configurazione dei percorsi si rimette com'era. La chiave del KMS è casuale.
 */
final class CancellazioneDellAccountTest extends TestCase
{
    /** Le tabelle in cui la prova scrive righe del docente: tabella => colonna. */
    private const RIGHE_DELLA_PROVA = [
        'teacher_institutes'              => 'user_id',
        'teacher_sections'                => 'user_id',
        'curriculum_teacher'              => 'user_id',
        'teacher_content_data'            => 'teacher_id',
        'print_info_data'                 => 'user_id',
        'teacher_drive_oauth'             => 'teacher_id',
        'teacher_access_credentials_data' => 'teacher_id',
        'teacher_keys'                    => 'teacher_id',
    ];

    private PDO $pdo;
    private TeacherCryptoService $cifra;
    private string $marca = '';
    private string $radice = '';
    private int $istituto = 0;
    private int $voce = 0;
    /** @var list<int> */
    private array $utenti = [];
    /** @var list<int> */
    private array $contenuti = [];
    /** @var array<string, mixed> */
    private array $configPrima = [];

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
            foreach (['users', 'teacher_content_data', 'content_versions', 'user_tos_acceptance', 'teacher_keys', 'crypto_access_log', 'deletion_requests'] as $t) {
                $this->pdo->query("SELECT 1 FROM `{$t}` LIMIT 0");
            }
        } catch (\Throwable $e) {
            self::markTestSkipped('DB o tabelle non disponibili: ' . $e->getMessage());
        }

        $this->marca = date('ymdHis') . bin2hex(random_bytes(3));
        $this->cifra = new TeacherCryptoService(bin2hex(random_bytes(32)));

        $this->radice = sys_get_temp_dir() . '/pantedu-cancellazione-' . $this->marca;
        if (!mkdir($this->radice . '/storage/objects', 0700, true)) {
            self::fail('cartella temporanea non creata: ' . $this->radice);
        }
        foreach (['app.paths.storage', 'storage.local.root', 'storage.default_provider', 'app.url'] as $k) {
            $this->configPrima[$k] = Config::get($k);
        }
        Config::set('app.paths.storage', $this->radice . '/storage');
        Config::set('storage.local.root', $this->radice . '/storage/objects');
        Config::set('storage.default_provider', 'local');

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZC1' . $this->marca, 'ISTITUTO CANCELLAZIONE ' . $this->marca, 'Prova']);
        $this->istituto = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine)
             VALUES ('classi', ?, '2A', 'Seconda A', 'SCI', 1, 'istituto')"
        )->execute([$this->istituto]);
        $this->voce = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->utenti !== []) {
                $u = implode(',', array_fill(0, count($this->utenti), '?'));
                if ($this->contenuti !== []) {
                    $c = implode(',', array_fill(0, count($this->contenuti), '?'));
                    $this->pdo->prepare("DELETE FROM content_versions WHERE content_id IN ($c)")->execute($this->contenuti);
                }
                $this->pdo->prepare("DELETE FROM content_versions WHERE actor_user_id IN ($u)")->execute($this->utenti);
                $this->pdo->prepare("DELETE FROM crypto_access_log WHERE teacher_id IN ($u)")->execute($this->utenti);
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($u)")->execute($this->utenti);
            }
            if ($this->voce > 0) {
                $this->pdo->prepare('DELETE FROM curriculum_entries WHERE id = ?')->execute([$this->voce]);
            }
            if ($this->istituto > 0) {
                $this->pdo->prepare('DELETE FROM institutes WHERE id = ?')->execute([$this->istituto]);
            }
        }
        $this->utenti = [];
        $this->contenuti = [];
        foreach ($this->configPrima as $k => $v) {
            Config::set($k, $v);
        }
        if ($this->radice !== '' && is_dir($this->radice)) {
            self::svuota($this->radice);
        }
    }

    // ── Preparazione ────────────────────────────────────────────────────────

    /**
     * Un docente con i suoi dati, nel database e sul disco.
     *
     * @return array{id:int, username:string, segno:string, ip:string, busta:array{ciphertext:string,iv:string,tag:string,kv:int}, file:list<string>, esercizio:int}
     */
    private function docente(string $nome, string $creato = '2026-01-10 10:00:00', ?string $ultimoAccesso = null): array
    {
        $segno    = 'zzc1' . $this->marca . $nome;
        $username = 'zz' . $nome . '.c1' . $this->marca;
        $ip       = '2001:db8:c1:' . substr($this->marca, -4) . '::' . \strlen($nome) . substr(bin2hex($nome), 0, 4);

        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, birth_date, password_hash,
                                totp_secret, totp_enabled, status, active, institute_id, indirizzo, classe,
                                created_at, approved_at, last_access_at)
             VALUES (?, 'teacher', ?, ?, ?, '1980-01-01', 'x', ?, 1, 'approved', 1, ?, 'SCI', '2A', ?, ?, ?)"
        )->execute([
            $username, 'Nome' . $segno, 'Cognome' . $segno, $segno . '@example.test', random_bytes(20),
            $this->istituto, $creato, $creato, $ultimoAccesso,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->utenti[] = $id;

        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$id, $this->istituto]);
        $this->pdo->prepare("INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe, note) VALUES (?, ?, 'SCI', '2A', ?)")
            ->execute([$id, $this->istituto, 'incarico ' . $segno]);
        $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active, label_override) VALUES (?, ?, 1, ?)')
            ->execute([$this->voce, $id, 'etichetta ' . $segno]);

        // Un esercizio in chiaro, con il contratto su disco.
        $chiave = "institutes/{$this->istituto}/private/{$id}/esercizi/MAT/argomento.contract.json";
        $this->pdo->prepare(
            "INSERT INTO teacher_content_data (teacher_id, content_subtype, topic, title, body_html, metadata_json)
             VALUES (?, 'esercizio', ?, ?, ?, ?)"
        )->execute([
            $id, 'argomento-' . $segno, 'Titolo ' . $segno, '<p>Traccia ' . $segno . '</p>',
            json_encode(['contract_key' => $chiave, 'nota' => $segno]),
        ]);
        $esercizio = (int)$this->pdo->lastInsertId();
        $this->contenuti[] = $esercizio;
        $this->pdo->prepare(
            'INSERT INTO content_versions (content_id, version, snapshot_json, actor_user_id, actor_name) VALUES (?, 1, ?, ?, ?)'
        )->execute([$esercizio, json_encode(['title' => 'Titolo ' . $segno]), $id, $username]);

        // Una mappa cifrata con la chiave del docente, con il blob.
        $busta = $this->cifra->encrypt($id, 'Contenuto cifrato ' . $segno);
        $this->pdo->prepare(
            "INSERT INTO teacher_content_data (teacher_id, content_subtype, topic, title, body_html_ct, body_html_iv,
                                               body_html_tag, body_html_kv, map_blob_path)
             VALUES (?, 'mappa', ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $id, 'mappe-' . $segno, 'Mappa ' . $segno, $busta['ciphertext'], $busta['iv'], $busta['tag'],
            $busta['kv'], $id . '/01C1TEST.bin',
        ]);
        $this->contenuti[] = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO print_info_data (user_id, page_key, n_print, extra_json) VALUES (?, ?, 3, ?)')
            ->execute([$id, '/studio/' . $segno, json_encode(['nPrintDSA' => 2, 'nPrintDIS' => 1])]);
        $this->pdo->prepare(
            "INSERT INTO user_tos_acceptance (user_id, tos_version, aup_version, accepted_at, accepted_ip, user_agent)
             VALUES (?, '1.5', '1.0', '2026-02-01 08:00:00', ?, ?)"
        )->execute([$id, $ip, 'Mozilla/5.0 ' . $segno]);
        $this->pdo->prepare(
            "INSERT INTO teacher_drive_oauth (teacher_id, refresh_token_ct, refresh_token_iv, refresh_token_tag,
                                              refresh_token_kv, scope, email)
             VALUES (?, ?, ?, ?, 1, 'drive.file', ?)"
        )->execute([$id, random_bytes(64), random_bytes(12), random_bytes(16), 'google.' . $segno . '@example.test']);
        $this->pdo->prepare(
            'INSERT INTO teacher_access_credentials_data (teacher_id, label, access_username, password_hash, institute_id)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, 'Classe ' . $segno, 'c1' . $this->marca . $nome, 'x', $this->istituto]);

        // I file che l'applicazione scrive a nome del docente.
        $s = $this->radice . '/storage';
        $file = [
            "$s/objects/$chiave"                                          => '{"title":"Titolo ' . $segno . '"}',
            "$s/objects/institutes/{$this->istituto}/private/$id/pdf-import/5/contracts.json" => $segno,
            "$s/objects/teachers/$id/tikz-workspace.json"                 => $segno,
            "$s/maps_enc/$id/01C1TEST.bin"                                => $busta['ciphertext'],
            "$s/verifiche_enc/$id/01C1VER.bin"                            => random_bytes(32),
            "$s/templates/verifiche/t_$id/header.tex"                     => '% ' . $segno,
            "$s/config/pdf-import/teacher-$id/keys.json"                  => $segno,
            "$s/cache/pdf-import/teacher-$id/risposta.json"               => $segno,
            "$s/data/scelte/$username/studio_esercizio.json"              => $segno,
            "$s/data/print_info/$username.json"                           => $segno,
            "$s/temp/teachers/$username/tex/stampa.tex"                   => $segno,
        ];
        // Un file in ogni cartella dell'elenco della routine: un percorso
        // aggiunto lì si prova da solo (24/9/2026: ne mancavano tre).
        foreach (CancellazioneDellAccount::cartelleDelDocente($id) as $relativa) {
            $file["$s/$relativa/dall-elenco.txt"] = $segno;
        }
        foreach ($file as $percorso => $contenuto) {
            if (!is_dir(\dirname($percorso))) {
                mkdir(\dirname($percorso), 0700, true);
            }
            file_put_contents($percorso, $contenuto);
        }

        return [
            'id' => $id, 'username' => $username, 'segno' => $segno, 'ip' => $ip,
            'busta' => $busta, 'file' => array_keys($file), 'esercizio' => $esercizio,
        ];
    }

    /** @return int la richiesta, confermata e a fine ripensamento */
    private function richiestaDovuta(int $utente, string $motivo): int
    {
        $svc = new DeletionRequestService($this->cifra);
        self::assertTrue($svc->confirm($svc->request($utente, $motivo)));
        $this->pdo->prepare('UPDATE deletion_requests SET execute_after = NOW() - INTERVAL 1 MINUTE WHERE user_id = ?')
            ->execute([$utente]);
        $st = $this->pdo->prepare("SELECT id FROM deletion_requests WHERE user_id = ? AND status = 'cooling_off'");
        $st->execute([$utente]);
        return (int)$st->fetchColumn();
    }

    // ── Misure ──────────────────────────────────────────────────────────────

    /**
     * In quante righe del database compare ciascuno dei testi, per tabella.
     * Tutte le colonne di testo e binarie delle tabelle vere, tranne `waf_*`.
     *
     * @param list<string> $testi
     * @return array<string, int> tabella => righe (solo quelle con almeno una)
     */
    private function doveCompare(array $testi): array
    {
        $colonne = $this->pdo->query(
            "SELECT c.TABLE_NAME, c.COLUMN_NAME
               FROM information_schema.COLUMNS c
               JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
              WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
                AND c.TABLE_NAME NOT LIKE 'waf\\_%'
                AND c.DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json',
                                    'binary','varbinary','tinyblob','blob','mediumblob','longblob')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $perTabella = [];
        foreach ($colonne as $c) {
            $perTabella[$c['TABLE_NAME']][] = $c['COLUMN_NAME'];
        }
        $trovate = [];
        foreach ($perTabella as $tabella => $cc) {
            $dove = [];
            $valori = [];
            foreach ($cc as $col) {
                foreach ($testi as $testo) {
                    $dove[] = "`{$col}` LIKE ?";
                    $valori[] = '%' . $testo . '%';
                }
            }
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM `{$tabella}` WHERE " . implode(' OR ', $dove));
            $st->execute($valori);
            $n = (int)$st->fetchColumn();
            if ($n > 0) {
                $trovate[$tabella] = $n;
            }
        }
        ksort($trovate);
        return $trovate;
    }

    /** @return array<string, string> percorso => impronta, dei file sotto la cartella temporanea */
    private function fileDeiDati(): array
    {
        $out = [];
        $voci = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->radice, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($voci as $voce) {
            /** @var \SplFileInfo $voce */
            if ($voce->isLink()) {
                $out[$voce->getPathname()] = 'collegamento → ' . (string)readlink($voce->getPathname());
            } elseif ($voce->isFile()) {
                $out[$voce->getPathname()] = hash_file('sha256', $voce->getPathname()) ?: '';
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Il file sta in una delle cartelle del docente?
     *
     * @param array{id:int, username:string} $d
     */
    private function eDelDocente(string $percorso, array $d): bool
    {
        $rel = substr($percorso, \strlen($this->radice . '/storage/'));
        $id = preg_quote((string)$d['id'], '#');
        $nome = preg_quote($d['username'], '#');
        return preg_match(
            "#^(objects/institutes/[^/]+/private/{$id}/|objects/teachers/{$id}/|maps_enc/{$id}/|verifiche_enc/{$id}/"
            . "|templates/verifiche/t_{$id}/|config/pdf-import/teacher-{$id}/|cache/pdf-import/teacher-{$id}/"
            . "|templates/drawio/teachers/{$id}/|overrides/teacher_{$id}/|cache/tikz/teacher_{$id}/"
            . "|data/scelte/{$nome}/|data/print_info/{$nome}\\.json$|temp/teachers/{$nome}/)#",
            $rel
        ) === 1;
    }

    /** @return array<string, int> quante righe del docente in ogni tabella della prova */
    private function righe(int $utente, ?int $esercizio = null): array
    {
        $out = [];
        foreach (self::RIGHE_DELLA_PROVA as $tabella => $colonna) {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM `{$tabella}` WHERE `{$colonna}` = ?");
            $st->execute([$utente]);
            $out[$tabella] = (int)$st->fetchColumn();
        }
        if ($esercizio !== null) {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM content_versions WHERE content_id = ?');
            $st->execute([$esercizio]);
            $out['content_versions'] = (int)$st->fetchColumn();
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function utente(int $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($r, "la riga di users {$id} c'è ancora");
        return $r;
    }

    /** @return array<string, mixed>|false */
    private function verbale(int $id): array|false
    {
        $st = $this->pdo->prepare('SELECT tos_version, aup_version, accepted_at, accepted_ip, user_agent FROM user_tos_acceptance WHERE user_id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Il docente cancellato: segnaposto, nessuna riga sua, nessun file suo,
     * nessuna traccia dei suoi testi, verbale senza IP, chiave distrutta.
     *
     * @param array{id:int, username:string, segno:string, ip:string, busta:array{ciphertext:string,iv:string,tag:string,kv:int}, file:list<string>, esercizio:int} $d
     */
    private function assertCancellato(array $d, string $motivo): void
    {
        $u = $this->utente($d['id']);
        self::assertSame('anon-' . $d['id'], $u['username'], 'nome utente sostituito');
        self::assertSame('anon-' . $d['id'] . '@invalid.local', $u['email']);
        self::assertSame('', $u['first_name']);
        self::assertSame('', $u['last_name']);
        self::assertSame('', $u['password_hash']);
        self::assertNull($u['birth_date'], 'data di nascita');
        self::assertNull($u['institute_id'], 'scuola');
        self::assertNull($u['indirizzo'], 'indirizzo');
        self::assertNull($u['classe'], 'classe');
        self::assertNull($u['totp_secret'], 'secondo fattore');
        self::assertSame(0, (int)$u['active']);
        self::assertSame('anonymized', $u['status']);
        self::assertNotNull($u['deleted_at']);

        self::assertSame(
            array_fill_keys(array_keys(self::RIGHE_DELLA_PROVA), 0) + ['content_versions' => 0],
            $this->righe($d['id'], $d['esercizio']),
            'nessuna riga del docente'
        );

        $v = $this->verbale($d['id']);
        self::assertIsArray($v, 'il verbale dei Termini resta');
        self::assertSame(['1.5', '1.0', '2026-02-01 08:00:00', '', null], array_values($v), 'restano data e versioni, IP e User-Agent no');

        try {
            $this->cifra->decrypt($d['id'], $d['busta']);
            self::fail('il contenuto cifrato del docente cancellato si legge ancora');
        } catch (\RuntimeException) {
            // atteso: senza chiave non si decifra
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM crypto_access_log WHERE teacher_id = ? AND operation = 'shred' AND reason = ?");
        $st->execute([$d['id'], $motivo]);
        self::assertSame(1, (int)$st->fetchColumn(), 'la distruzione della chiave è nel registro, una volta');

        foreach ($d['file'] as $percorso) {
            self::assertFileDoesNotExist($percorso);
        }
        foreach ($this->fileDeiDati() as $percorso => $impronta) {
            self::assertFalse($this->eDelDocente($percorso, $d), "file del docente rimasto: {$percorso}");
            if (!str_starts_with($impronta, 'collegamento')) {
                self::assertStringNotContainsString($d['segno'], (string)file_get_contents($percorso), "testo rimasto in {$percorso}");
            }
        }

        self::assertSame([], $this->doveCompare([$d['segno'], $d['username'], $d['ip']]), 'testi del docente rimasti nel database');
    }

    // ── Le prove ────────────────────────────────────────────────────────────

    #[Test]
    public function l_art_17_cancella_la_persona_e_non_tocca_il_collega(): void
    {
        $d = $this->docente('doc');
        $c = $this->docente('col');

        // Il docente ha modificato un contenuto del collega: la versione è del
        // collega e resta, con il nome utente nuovo accanto all'id.
        $this->pdo->prepare(
            'INSERT INTO content_versions (content_id, version, snapshot_json, actor_user_id, actor_name) VALUES (?, 2, ?, ?, ?)'
        )->execute([$c['esercizio'], json_encode(['title' => 'Titolo ' . $c['segno']]), $d['id'], $d['username']]);
        $versioneDelCollega = (int)$this->pdo->lastInsertId();

        // Un collegamento dalla cartella del docente a un file del collega.
        $bersaglio = $this->radice . "/storage/objects/teachers/{$c['id']}/tikz-workspace.json";
        self::assertTrue(symlink($bersaglio, $this->radice . "/storage/objects/teachers/{$d['id']}/collegamento.json"));

        // Il collega ha una richiesta mai confermata, con il suo motivo.
        (new DeletionRequestService($this->cifra))->request($c['id'], 'motivo ' . $c['segno']);

        $richiesta = $this->richiestaDovuta($d['id'], 'motivo ' . $d['segno']);
        self::assertGreaterThan(0, $richiesta);

        $righeCollega = $this->righe($c['id'], $c['esercizio']);
        $utenteCollega = $this->utente($c['id']);
        $verbaleCollega = $this->verbale($c['id']);
        $fileCollega = array_filter(
            $this->fileDeiDati(),
            fn(string $p): bool => !$this->eDelDocente($p, $d),
            ARRAY_FILTER_USE_KEY
        );
        self::assertCount(\count($c['file']), $fileCollega, 'la misura separa i file dei due docenti');
        $comparivaIlCollega = $this->doveCompare([$c['segno']]);
        self::assertNotSame([], $comparivaIlCollega, 'la misura trova i testi di un docente quando ci sono');

        (new DeletionRequestService($this->cifra))->executeOne($richiesta, $d['id']);

        $this->assertCancellato($d, 'art_17_self_service_deletion');

        $st = $this->pdo->prepare('SELECT status, reason FROM deletion_requests WHERE id = ?');
        $st->execute([$richiesta]);
        self::assertSame(['status' => 'executed', 'reason' => null], $st->fetch(PDO::FETCH_ASSOC), 'la richiesta resta, eseguita, senza il motivo');

        $st = $this->pdo->prepare('SELECT actor_user_id, actor_name FROM content_versions WHERE id = ?');
        $st->execute([$versioneDelCollega]);
        self::assertSame(
            ['actor_user_id' => $d['id'], 'actor_name' => 'anon-' . $d['id']],
            $st->fetch(PDO::FETCH_ASSOC),
            'la versione del collega resta, con il nome del segnaposto'
        );

        // ── Il collega: com'era ──
        self::assertSame($righeCollega, $this->righe($c['id'], $c['esercizio']), 'righe del collega');
        self::assertSame($utenteCollega, $this->utente($c['id']), 'riga di users del collega');
        self::assertSame($verbaleCollega, $this->verbale($c['id']), 'verbale del collega');
        self::assertSame($fileCollega, $this->fileDeiDati(), 'file del collega, e nient\'altro');
        self::assertFileExists($bersaglio, 'il collegamento non si segue');
        self::assertSame('Contenuto cifrato ' . $c['segno'], $this->cifra->decrypt($c['id'], $c['busta']));
        self::assertSame($comparivaIlCollega, $this->doveCompare([$c['segno']]), 'i testi del collega sono dove erano');
    }

    #[Test]
    public function a_730_giorni_si_cancella_nello_stesso_modo_e_solo_chi_e_inattivo(): void
    {
        // Date nel passato lontano: con «adesso» al 1/1/2003 il limite è il
        // 1/1/2001, e nessun account vero è stato creato prima.
        $ora = new DateTimeImmutable('2003-01-01 04:15:00');
        $inattivo = $this->docente('ina', '2000-01-10 10:00:00', '2000-06-01 10:00:00');
        $attivo   = $this->docente('att', '2000-01-10 10:00:00', '2002-06-01 10:00:00');
        $utenteAttivo = $this->utente($attivo['id']);
        $righeAttivo = $this->righe($attivo['id'], $attivo['esercizio']);

        // Dal 24/9/2026 la cancellazione per inattività arriva dopo l'ultimo
        // avviso via email (AvvisiDiInattivita): un mittente finto che accetta.
        $mailer = new \App\Services\Mailer('noreply@istanza.example.test', 'Pantedu', static fn(): bool => true);
        Config::set('app.url', 'https://istanza.example.test');
        $svc = new AnonimizzazioneDegliInattivi(
            $this->pdo,
            new CancellazioneDellAccount($this->pdo, $this->cifra),
            static fn(): \App\Services\Mailer => $mailer,
        );

        // Prima di toccare qualcosa: la scelta deve essere esattamente la
        // prova, altrimenti ci si ferma invece di cancellare account non suoi.
        $scelti = $svc->inattivi($ora, 730);
        if ($scelti !== [$inattivo['id']]) {
            self::fail('La scelta degli inattivi non è solo l\'account della prova: ' . json_encode($scelti)
                . '. Mi fermo per non cancellare account non miei.');
        }

        // In prova si conta e basta: nessuno da cancellare ancora, l'ultimo
        // avviso da mandare.
        $esito = $svc->esegui($ora, 730, true);
        self::assertSame([], $esito['trovati']);
        self::assertSame([$inattivo['id']], array_column($esito['avvisi'], 'id'));
        self::assertSame($inattivo['username'], $this->utente($inattivo['id'])['username'], 'in prova non cambia niente');

        // Il primo giro vero manda l'ultimo avviso e non cancella.
        $esito = $svc->esegui($ora, 730, false);
        self::assertSame(0, $esito['cancellati']);
        self::assertSame($inattivo['username'], $this->utente($inattivo['id'])['username']);

        // Sette giorni dopo l'avviso, sì.
        $esito = $svc->esegui($ora->modify('+8 days'), 730, false);
        self::assertSame(['trovati' => [$inattivo['id']], 'cancellati' => 1, 'errori' => []], [
            'trovati' => $esito['trovati'], 'cancellati' => $esito['cancellati'], 'errori' => $esito['errori'],
        ]);

        $this->assertCancellato($inattivo, AnonimizzazioneDegliInattivi::MOTIVO);

        self::assertSame($utenteAttivo, $this->utente($attivo['id']), 'l\'account attivo resta com\'era');
        self::assertSame($righeAttivo, $this->righe($attivo['id'], $attivo['esercizio']));
        self::assertSame('Contenuto cifrato ' . $attivo['segno'], $this->cifra->decrypt($attivo['id'], $attivo['busta']));

        // Il giro del mese dopo non lo riprende: è già anonimizzato.
        self::assertSame([], $svc->inattivi($ora, 730));
    }

    #[Test]
    public function si_puo_ripetere_e_la_seconda_volta_non_trova_niente(): void
    {
        $d = $this->docente('rip');
        $routine = new CancellazioneDellAccount($this->pdo, $this->cifra);

        $prima = $routine->esegui($d['id'], 'prova_ripetizione');
        self::assertGreaterThan(0, $prima['file']);
        self::assertSame(2, $prima['righe']['teacher_content_data']);

        $seconda = $routine->esegui($d['id'], 'prova_ripetizione');
        self::assertSame(0, $seconda['file'], 'nessun file la seconda volta');
        self::assertSame([], array_filter($seconda['righe']), 'nessuna riga toccata la seconda volta: ' . json_encode($seconda['righe']));
        self::assertSame('anon-' . $d['id'], $this->utente($d['id'])['username']);
    }

    #[Test]
    public function se_un_file_non_si_cancella_il_database_non_si_tocca(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('da root una cartella in sola lettura si svuota lo stesso: la prova non misurerebbe niente');
        }
        $d = $this->docente('blo');
        $cartella = $this->radice . "/storage/objects/teachers/{$d['id']}";
        chmod($cartella, 0500);
        try {
            (new CancellazioneDellAccount($this->pdo, $this->cifra))->esegui($d['id'], 'prova_blocco');
            self::fail('la routine è andata avanti con un file che non si cancellava');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('il database non è stato toccato', $e->getMessage());
        } finally {
            chmod($cartella, 0700);
        }
        $u = $this->utente($d['id']);
        self::assertSame($d['username'], $u['username'], 'la riga di users è quella di prima');
        self::assertSame(1, (int)$u['active']);
        self::assertSame(2, $this->righe($d['id'])['teacher_content_data'], 'i contenuti sono ancora lì');
        self::assertGreaterThan(
            0,
            $this->righe($d['id'])['teacher_keys'],
            'la chiave c\'è ancora: si distrugge per ultima, e niente di irreversibile è successo'
        );

        // Tolto l'ostacolo, il giro dopo finisce il lavoro.
        (new CancellazioneDellAccount($this->pdo, $this->cifra))->esegui($d['id'], 'prova_blocco');
        self::assertSame('anon-' . $d['id'], $this->utente($d['id'])['username']);
        self::assertDirectoryDoesNotExist($cartella);
    }

    /**
     * Una cancellazione confermata non si annulla più quando la sua ora è
     * passata (24/9/2026): il giro notturno la sta eseguendo o la riprenderà.
     * Nell'altro verso, prima dell'ora si annulla come sempre.
     */
    #[Test]
    public function a_esecuzione_cominciata_la_richiesta_non_si_annulla(): void
    {
        $d = $this->docente('ann');
        $servizio = new \App\Services\Gdpr\DeletionRequestService($this->cifra, new CancellazioneDellAccount($this->pdo, $this->cifra));
        $inserisci = $this->pdo->prepare(
            "INSERT INTO deletion_requests (user_id, status, confirm_token, requested_at, confirmed_at, execute_after)
             VALUES (?, 'cooling_off', ?, NOW(), NOW(), ?)"
        );
        $stato = $this->pdo->prepare('SELECT status FROM deletion_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1');

        $inserisci->execute([$d['id'], hash('sha256', 'passata' . $this->marca), '2020-01-01 00:00:00']);
        self::assertFalse($servizio->cancel($d['id']), 'con l\'ora passata non si annulla');
        $stato->execute([$d['id']]);
        self::assertSame('cooling_off', $stato->fetchColumn());

        $this->pdo->prepare('DELETE FROM deletion_requests WHERE user_id = ?')->execute([$d['id']]);
        $inserisci->execute([$d['id'], hash('sha256', 'futura' . $this->marca), '2037-12-31 00:00:00']);
        self::assertTrue($servizio->cancel($d['id']), 'prima dell\'ora si annulla');
        $stato->execute([$d['id']]);
        self::assertSame('cancelled', $stato->fetchColumn());
    }

    /**
     * Ogni riferimento a `users` nel database è deciso: si cancella o resta,
     * con il perché. Una tabella nuova con un id di utente fa fallire la
     * prova finché qualcuno non la mette in uno dei due elenchi.
     */
    #[Test]
    public function ogni_riferimento_a_un_utente_e_classificato(): void
    {
        $classificati = [];
        foreach (CancellazioneDellAccount::RIGHE_DA_CANCELLARE as $tabella => $colonna) {
            $classificati[] = "{$tabella}.{$colonna}";
        }
        $classificati = array_merge(
            $classificati,
            CancellazioneDellAccount::DISTRUZIONE_DELLA_CHIAVE,
            array_keys(CancellazioneDellAccount::RIFERIMENTI_CHE_RESTANO),
        );

        $trovati = $this->pdo->query(
            "SELECT CONCAT(k.TABLE_NAME, '.', k.COLUMN_NAME)
               FROM information_schema.KEY_COLUMN_USAGE k
              WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'users'
             UNION
             SELECT CONCAT(c.TABLE_NAME, '.', c.COLUMN_NAME)
               FROM information_schema.COLUMNS c
               JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
              WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
                AND c.COLUMN_NAME IN ('user_id', 'teacher_id', 'owner_user_id', 'member_user_id', 'actor_user_id', 'student_user_id')"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotEmpty($trovati, 'la misura trova i riferimenti');

        $nonClassificati = array_values(array_diff($trovati, $classificati));
        sort($nonClassificati);
        self::assertSame([], $nonClassificati, 'riferimenti a users che la cancellazione non ha deciso');

        $colonne = $this->pdo->query(
            "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
        )->fetchAll(PDO::FETCH_COLUMN);
        $inesistenti = array_values(array_diff($classificati, $colonne));
        self::assertSame([], $inesistenti, 'voci degli elenchi che non esistono nel database');
    }

    private static function svuota(string $cartella): void
    {
        $voci = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cartella, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($voci as $voce) {
            /** @var \SplFileInfo $voce */
            if ($voce->isDir() && !$voce->isLink()) {
                @chmod($voce->getPathname(), 0700);
                @rmdir($voce->getPathname());
            } else {
                @unlink($voce->getPathname());
            }
        }
        @rmdir($cartella);
    }
}
