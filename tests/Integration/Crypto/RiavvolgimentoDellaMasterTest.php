<?php

declare(strict_types=1);

namespace Tests\Integration\Crypto;

use App\Core\Config;
use App\Core\Database;
use App\Services\Crypto\ComandoDiRiavvolgimento;
use App\Services\Crypto\RiavvolgimentoDellaMaster;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Crypto\TeacherRecoveryService;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Il cambio della chiave master non perde niente (A-88, 23/9/2026).
 *
 * ── Che cosa si prova ─────────────────────────────────────────────────────
 *
 * Tre docenti creati qui. «Uno» ha tre versioni di chiave, un contenuto per
 * versione e una chiave di recupero attiva; «due» ha due versioni, la prima
 * ancora col prefisso HKDF storico, e una chiave di recupero revocata;
 * «fuori» ha una versione e non è nell'elenco del giro, per provare che
 * l'elenco limita davvero. Si riavvolge da A a B, e poi si decifra con i
 * servizi veri (TeacherCryptoService, TeacherRecoveryService): con la sola B
 * tutto si apre, con la sola A niente. Poi: il secondo giro non ricifra
 * niente, la prova non scrive un byte, un guasto a metà lascia ogni docente
 * intero e un secondo giro finisce, un elemento che non si apre si elenca e
 * non si tocca, e l'uscita dello script non contiene niente di segreto.
 *
 * Il percorso di produzione, senza elenco, si prova in sola lettura: la
 * prova su tutto il database delle prove deve contare anche gli elementi di
 * questi docenti, e i suoi elementi devono essere le righe che il database
 * conta (le righe altrui, avvolte con altre chiavi, risultano illeggibili e
 * non si toccano). Un docente con la sola chiave di recupero si prova a
 * parte: la UNION che lo trova serve anche alla verifica.
 *
 * ── Chiavi e righe ────────────────────────────────────────────────────────
 *
 * A e B le genera la prova: la master di sviluppo non si usa, e le righe
 * degli altri non si toccano (il giro è limitato ai docenti della prova).
 * I due registri che i servizi scrivono, crypto_access_log e
 * teacher_recovery_audit, sono append-only: per questa connessione li copre
 * una tabella TEMPORANEA con lo stesso nome, così nessuna riga arriva a
 * quelli veri (misurato il 23/9 su MariaDB 10.11: la tabella vera resta con
 * le righe che aveva). Utenti, chiavi e chiavi di recupero della prova si
 * cancellano in tearDown, che gira anche quando la prova fallisce.
 */
final class RiavvolgimentoDellaMasterTest extends TestCase
{
    private const FIRMATO = ['bundle' => 'prova-riavvolgimento', 'righe' => 3];

    private PDO $pdo;
    private string $a = '';
    private string $b = '';
    /** @var list<int> */
    private array $utenti = [];
    private int $uno = 0;
    private int $due = 0;
    private int $fuori = 0;
    /** @var list<array{docente: int, busta: array{ciphertext: string, iv: string, tag: string, kv: int}, chiaro: string}> */
    private array $buste = [];
    private ?string $firmaPrima = null;
    /** @var array<string, string> */
    private array $fotoFuori = [];
    private string $marca = '';

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 3);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        // Utenti appena creati: la guardia contro la rigenerazione resta accesa.
        Config::set('crypto.allow_regenerate', false);
        if (!Database::isAvailable()) {
            $this->markTestSkipped('DB non disponibile');
        }
        $this->pdo = Database::connection();

        // CREATE TEMPORARY ... LIKE con lo stesso nome non si può (errore
        // 1066): si crea con un altro nome e si rinomina.
        foreach (['crypto_access_log', 'teacher_recovery_audit'] as $registro) {
            $this->pdo->exec("CREATE TEMPORARY TABLE zz_ombra_$registro LIKE $registro");
            $this->pdo->exec("ALTER TABLE zz_ombra_$registro RENAME TO $registro");
        }

        $this->a = bin2hex(random_bytes(32));
        $this->b = bin2hex(random_bytes(32));
        $this->marca = 'zzrwm' . bin2hex(random_bytes(3));
        $this->uno = $this->utente($this->marca . 'uno');
        $this->due = $this->utente($this->marca . 'due');
        $this->fuori = $this->utente($this->marca . 'fuori');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->utenti !== []) {
            $in = implode(',', array_map('intval', $this->utenti));
            $this->pdo->exec("DELETE FROM teacher_recovery_keys WHERE user_id IN ($in)");
            $this->pdo->exec("DELETE FROM teacher_keys WHERE teacher_id IN ($in)");
            $this->pdo->exec("DELETE FROM users WHERE id IN ($in)");
        }
        // Le tabelle temporanee restano finché resta la connessione, che è
        // condivisa con le prove seguenti: si tolgono a mano. TEMPORARY non
        // tocca mai la tabella vera.
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS crypto_access_log');
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS teacher_recovery_audit');
        IstruzioneCheCede::$updatePrimaDelGuasto = PHP_INT_MAX;
        IstruzioneSorvegliata::$nascondi = null;
        IstruzioneSorvegliata::$dopoIDocenti = null;
    }

    #[Test]
    public function da_a_a_b_i_contenuti_si_aprono_con_la_sola_b_e_non_con_la_sola_a(): void
    {
        $this->prepara();
        $ivPrima = $this->iv([$this->uno, $this->due]);

        $esito = $this->giro()->applica();

        self::assertTrue(RiavvolgimentoDellaMaster::riuscito($esito));
        self::assertSame(2, $esito['docenti'], 'il docente fuori dall\'elenco non si conta');
        self::assertSame(5, $esito['chiavi']['totale']);
        self::assertSame(5, $esito['chiavi']['ricifrati']);
        self::assertSame(1, $esito['chiavi']['prefisso_storico']);
        self::assertSame(2, $esito['recupero']['ricifrati'], 'anche la chiave di recupero revocata');

        $conB = new TeacherCryptoService($this->b);
        $conA = new TeacherCryptoService($this->a);
        foreach ($this->buste as $b) {
            if ($b['docente'] === $this->fuori) {
                continue;
            }
            self::assertSame($b['chiaro'], $conB->decrypt($b['docente'], $b['busta']));
            try {
                $conA->decrypt($b['docente'], $b['busta']);
                self::fail("con la sola A «{$b['chiaro']}» si apre ancora");
            } catch (RuntimeException $e) {
                self::assertSame('kek_unwrap_failed', $e->getMessage());
            }
        }

        // La chiave di recupero è la stessa R, riavvolta: la firma del
        // manifesto non cambia, ma la si ottiene solo passando da B.
        self::assertNotNull($this->firmaPrima);
        self::assertSame(
            $this->firmaPrima,
            (new TeacherRecoveryService($this->b))->signManifestForExporter($this->uno, self::FIRMATO)
        );
        self::assertNull((new TeacherRecoveryService($this->a))->signManifestForExporter($this->uno, self::FIRMATO));

        // IV nuovo per ogni elemento: diverso da quello di prima e da tutti
        // gli altri scritti dal giro (un IV fisso passerebbe il primo esame).
        $ivDopo = $this->iv([$this->uno, $this->due]);
        self::assertCount(7, $ivPrima);
        self::assertCount(7, array_unique($ivDopo), 'due elementi con lo stesso IV');
        foreach ($ivPrima as $id => $iv) {
            self::assertNotSame($iv, $ivDopo[$id], "IV riusato per $id");
        }

        // Il docente fuori dall'elenco: identico, e si apre ancora con A.
        self::assertSame($this->fotoFuori, $this->foto([$this->fuori]));
        foreach ($this->buste as $b) {
            if ($b['docente'] === $this->fuori) {
                self::assertSame($b['chiaro'], $conA->decrypt($b['docente'], $b['busta']));
            }
        }

        $verifica = $this->giro()->verifica();
        self::assertTrue(RiavvolgimentoDellaMaster::riuscito($verifica));
        self::assertSame(5, $verifica['chiavi']['con_la_nuova']);
        self::assertSame(2, $verifica['recupero']['con_la_nuova']);
    }

    #[Test]
    public function il_secondo_giro_non_ricifra_niente(): void
    {
        $this->prepara();
        $this->giro()->applica();
        $dopoIlPrimo = $this->foto([$this->uno, $this->due]);

        $secondo = $this->giro()->applica();

        self::assertSame(0, $secondo['chiavi']['ricifrati']);
        self::assertSame(0, $secondo['recupero']['ricifrati']);
        self::assertSame(5, $secondo['chiavi']['con_la_nuova']);
        self::assertSame(2, $secondo['recupero']['con_la_nuova']);
        self::assertSame($dopoIlPrimo, $this->foto([$this->uno, $this->due]));
    }

    #[Test]
    public function la_prova_conta_e_non_scrive_niente(): void
    {
        $this->prepara();
        $prima = $this->foto([$this->uno, $this->due, $this->fuori]);

        $esito = $this->giro()->prova();

        self::assertSame($prima, $this->foto([$this->uno, $this->due, $this->fuori]));
        self::assertTrue(RiavvolgimentoDellaMaster::riuscito($esito));
        self::assertSame(5, $esito['chiavi']['con_la_vecchia']);
        self::assertSame(1, $esito['chiavi']['prefisso_storico']);
        self::assertSame(0, $esito['chiavi']['ricifrati']);
        self::assertSame(2, $esito['recupero']['con_la_vecchia']);
        self::assertSame(0, $esito['recupero']['ricifrati']);
    }

    /** @return iterable<string, array{int, string}> */
    public static function guasti(): iterable
    {
        // «Uno» fa quattro UPDATE (tre versioni e il recupero), poi «due».
        yield 'a metà del primo docente' => [1, 'uno'];
        yield 'al primo UPDATE del secondo docente' => [4, 'due'];
    }

    #[Test]
    #[DataProvider('guasti')]
    public function un_guasto_lascia_ogni_docente_intero_e_il_secondo_giro_finisce(int $updateRiusciti, string $interrotto): void
    {
        $this->prepara();
        $prima = $this->foto([$this->uno, $this->due]);
        $docenteInterrotto = $interrotto === 'uno' ? $this->uno : $this->due;

        try {
            (new RiavvolgimentoDellaMaster(
                $this->connessioneCheCede($updateRiusciti),
                $this->a,
                $this->b,
                [$this->uno, $this->due]
            ))->applica();
            self::fail('il guasto simulato non è arrivato');
        } catch (RuntimeException $e) {
            self::assertStringContainsString("docente $docenteInterrotto:", $e->getMessage());
            self::assertInstanceOf(PDOException::class, $e->getPrevious());
        }

        // Il docente interrotto ha le righe di prima, byte per byte; «due»
        // non è mai stato toccato in nessuno dei due casi.
        $dopo = $this->foto([$this->uno, $this->due]);
        foreach ($prima as $id => $impronta) {
            $delDocente = str_starts_with($id, 'k:' . $docenteInterrotto . '/')
                || $id === 'r:' . $docenteInterrotto
                || str_starts_with($id, 'k:' . $this->due . '/')
                || $id === 'r:' . $this->due;
            if ($delDocente) {
                self::assertSame($impronta, $dopo[$id], "$id è cambiato nonostante il rollback");
            }
        }

        $verifica = $this->giro()->verifica();
        self::assertFalse(RiavvolgimentoDellaMaster::riuscito($verifica));
        $attesi = $interrotto === 'uno'
            ? ["{$this->uno}/v1", "{$this->uno}/v2", "{$this->uno}/v3", "{$this->due}/v1", "{$this->due}/v2"]
            : ["{$this->due}/v1", "{$this->due}/v2"];
        self::assertSame($attesi, $verifica['chiavi']['solo_con_la_vecchia']);

        $secondo = $this->giro()->applica();
        self::assertSame(count($attesi), $secondo['chiavi']['ricifrati']);
        self::assertSame(5 - count($attesi), $secondo['chiavi']['con_la_nuova']);
        self::assertTrue(RiavvolgimentoDellaMaster::riuscito($this->giro()->verifica()));

        $conB = new TeacherCryptoService($this->b);
        foreach ($this->buste as $b) {
            if ($b['docente'] !== $this->fuori) {
                self::assertSame($b['chiaro'], $conB->decrypt($b['docente'], $b['busta']));
            }
        }
    }

    #[Test]
    public function lo_script_interrotto_esce_con_2_e_dice_dove(): void
    {
        $this->prepara();
        $cede = $this->connessioneCheCede(4);
        $uscita = fopen('php://memory', 'w+');
        $errori = fopen('php://memory', 'w+');
        self::assertIsResource($uscita);
        self::assertIsResource($errori);

        $codice = ComandoDiRiavvolgimento::esegui(
            ['--apply', "--docenti={$this->uno},{$this->due}"],
            ['KMS_MASTER_KEY' => $this->a, 'KMS_MASTER_KEY_NEW' => $this->b],
            static fn (): PDO => $cede,
            $uscita,
            $errori
        );
        rewind($errori);
        $testo = (string)stream_get_contents($errori);

        self::assertSame(2, $codice);
        self::assertStringContainsString("Interrotto: riavvolgimento interrotto al docente {$this->due}:", $testo);
        self::assertStringContainsString('Causa: PDOException: guasto simulato', $testo);
        self::assertStringNotContainsString($this->a, $testo);
        self::assertStringNotContainsString($this->b, $testo);
    }

    #[Test]
    public function un_elemento_che_non_si_apre_si_elenca_e_non_si_tocca(): void
    {
        $this->prepara();
        // La versione 2 di «due» avvolta con una terza chiave, che il giro non
        // conosce: non si apre né con A né con B.
        $terza = random_bytes(32);
        $this->pdo->prepare('UPDATE teacher_keys SET wrapped_kek = ? WHERE teacher_id = ? AND key_version = 2')
            ->execute([$this->avvolgi(random_bytes(32), $terza, 'pantedu-teacher-kek-v1', $this->due, 2), $this->due]);
        $prima = $this->foto([$this->due]);

        $prova = $this->giro()->prova();
        self::assertFalse(RiavvolgimentoDellaMaster::riuscito($prova));
        self::assertSame(["{$this->due}/v2"], $prova['chiavi']['illeggibili']);

        [$codice, $uscita] = $this->comando(['--apply', "--docenti={$this->uno},{$this->due}"]);
        self::assertSame(1, $codice);
        self::assertStringContainsString("non si aprono: {$this->due}/v2", $uscita);
        self::assertStringContainsString('ricifrati:              4', $uscita);
        $dopo = $this->foto([$this->due]);
        self::assertSame($prima["k:{$this->due}/v2"], $dopo["k:{$this->due}/v2"]);
        self::assertNotSame($prima["k:{$this->due}/v1"], $dopo["k:{$this->due}/v1"]);
    }

    #[Test]
    public function lo_script_stampa_conteggi_e_id_e_niente_di_segreto(): void
    {
        $this->prepara();
        $prima = $this->grezzi([$this->uno, $this->due]);
        $filtro = "--docenti={$this->uno},{$this->due}";

        // La verifica prima del giro elenca per id tutto quello che si apre
        // solo con la vecchia: è il resoconto più lungo, e deve dire solo id.
        [$codiceVerificaPrima, $uscitaVerificaPrima] = $this->comando(['--verify', $filtro]);
        [$codiceProva, $uscitaProva] = $this->comando([$filtro]);
        [$codice, $uscita] = $this->comando(['--apply', $filtro]);
        [$codiceVerifica, $uscitaVerifica] = $this->comando(['--verify', $filtro]);

        // Prima il controllo sui segreti, poi il contenuto: così una fuga
        // si legge come tale, non come un resoconto che non torna.
        $tutto = $uscitaVerificaPrima . $uscitaProva . $uscita . $uscitaVerifica;
        $segreti = [$this->a, strtoupper($this->a), $this->b, strtoupper($this->b)];
        foreach ([$prima, $this->grezzi([$this->uno, $this->due])] as $righe) {
            foreach ($righe as $blob) {
                $segreti[] = bin2hex($blob);
                $segreti[] = bin2hex(substr($blob, 0, 12));
            }
        }
        foreach ($this->buste as $b) {
            $segreti[] = $b['chiaro'];
            $segreti[] = bin2hex($b['busta']['iv']);
        }
        foreach ($segreti as $i => $segreto) {
            self::assertStringNotContainsString($segreto, $tutto, "l'uscita contiene il segreto n. $i");
        }

        self::assertSame(1, $codiceVerificaPrima);
        self::assertStringContainsString(
            "si aprono solo con la vecchia: {$this->uno}/v1, {$this->uno}/v2, {$this->uno}/v3, {$this->due}/v1, {$this->due}/v2\n",
            $uscitaVerificaPrima
        );
        self::assertStringContainsString("si aprono solo con la vecchia: {$this->uno}, {$this->due}\n", $uscitaVerificaPrima);
        self::assertSame(0, $codiceProva);
        self::assertStringContainsString('prova (non scrive niente)', $uscitaProva);
        self::assertSame(0, $codice);
        self::assertStringContainsString('ricifrati:              5', $uscita);
        self::assertStringContainsString('(di cui con il prefisso storico: 1)', $uscita);
        self::assertSame(0, $codiceVerifica);
        self::assertStringContainsString('esito: tutto a posto', $uscitaVerifica);
    }

    #[Test]
    public function senza_elenco_la_prova_conta_anche_i_docenti_della_prova_e_tutte_le_righe(): void
    {
        $this->prepara();
        // A metà giro, subito dopo l'elenco dei docenti, nasce la chiave di un
        // docente nuovo: a sito acceso succede. Senza una fotografia sola il
        // conteggio la vedrebbe e l'elenco no.
        $nuovo = $this->utente($this->marca . 'nuovo');
        $nata = false;
        IstruzioneSorvegliata::$dopoIDocenti = function () use ($nuovo, &$nata): void {
            $this->pdo->prepare(
                'INSERT INTO teacher_keys (teacher_id, key_version, wrapped_kek, created_at) VALUES (?, 1, ?, NOW())'
            )->execute([$nuovo, random_bytes(60)]);
            $nata = true;
        };

        $esito = (new RiavvolgimentoDellaMaster(
            $this->connessione(IstruzioneSorvegliata::class),
            $this->a,
            $this->b
        ))->prova();

        self::assertTrue($nata, 'la chiave a metà giro non è stata scritta: la prova non prova niente');
        $attese = [
            "{$this->uno}/v1", "{$this->uno}/v2", "{$this->uno}/v3",
            "{$this->due}/v1", "{$this->due}/v2", "{$this->fuori}/v1",
        ];
        foreach ($attese as $id) {
            self::assertContains($id, $esito['chiavi']['solo_con_la_vecchia'], "senza elenco $id non è stato esaminato");
        }
        self::assertContains((string)$this->uno, $esito['recupero']['solo_con_la_vecchia']);
        self::assertContains((string)$this->due, $esito['recupero']['solo_con_la_vecchia']);
        foreach (['chiavi', 'recupero'] as $tipo) {
            self::assertSame(
                $esito[$tipo]['nel_database'],
                $esito[$tipo]['totale'],
                "$tipo: gli elementi esaminati non sono le righe che il database conta"
            );
        }
        self::assertGreaterThanOrEqual(\count($attese), $esito['chiavi']['totale']);
        self::assertNotContains("$nuovo/v1", array_merge($esito['chiavi']['solo_con_la_vecchia'], $esito['chiavi']['illeggibili']));

        // Lo stesso percorso dallo strumento, senza --docenti e in sola
        // lettura: per i due tipi, tanti elementi quante righe, e nessun
        // avviso di righe saltate. Il codice d'uscita qui non distingue: la
        // chiave nata a metà giro, avvolta con byte a caso, è illeggibile.
        [, $uscita] = $this->comando(['--dry-run']);
        self::assertSame(2, preg_match_all('/elementi:\s+(\d+)\n\s+righe nel database:\s+(\d+)\n/', $uscita, $m), $uscita);
        self::assertSame($m[2], $m[1], $uscita);
        self::assertGreaterThanOrEqual(\count($attese) + 1, (int)$m[1][0], "lo strumento senza --docenti non ha esaminato le chiavi della prova\n$uscita");
        self::assertGreaterThanOrEqual(2, (int)$m[1][1], $uscita);
        self::assertStringNotContainsString('non ha guardato tutto', $uscita);
        self::assertMatchesRegularExpression('#non si aprono: [^\n]*\b' . $nuovo . '/v1\b#', $uscita);
    }

    #[Test]
    public function se_il_giro_non_trova_un_docente_il_conteggio_lo_ferma(): void
    {
        $this->prepara();
        $filtro = "--docenti={$this->uno},{$this->due}";
        $sorvegliata = $this->connessione(IstruzioneSorvegliata::class);

        // Nell'altro verso prima: con tutti i docenti trovati, a posto.
        [$codice, $uscita] = $this->comando(['--dry-run', $filtro], $sorvegliata);
        self::assertSame(0, $codice, $uscita);
        self::assertStringContainsString("elementi:               5\n  righe nel database:     5\n", $uscita);
        self::assertStringNotContainsString('non ha guardato tutto', $uscita);

        // Un difetto che perde «due» dall'elenco dei docenti: le sue tre righe
        // restano nel database e il giro le salterebbe senza dirlo. La
        // verifica, dopo l'applicazione, è il caso peggiore: «uno» è tutto
        // nuovo, e senza il conteggio uscirebbe con 0 prima dello scambio.
        IstruzioneSorvegliata::$nascondi = $this->due;
        foreach (['--dry-run', '--apply', '--verify'] as $modo) {
            [$codice, $uscita] = $this->comando([$modo, $filtro], $sorvegliata);
            self::assertSame(1, $codice, "$modo\n$uscita");
            self::assertStringContainsString("elementi:               3\n  righe nel database:     5\n", $uscita, $modo);
            self::assertStringContainsString('il giro non ha guardato tutto', $uscita, $modo);
            self::assertStringContainsString('esito: NON a posto', $uscita, $modo);
        }
        IstruzioneSorvegliata::$nascondi = null;
        $verifica = $this->giro()->verifica();
        self::assertSame(["{$this->due}/v1", "{$this->due}/v2"], $verifica['chiavi']['solo_con_la_vecchia']);
    }

    #[Test]
    public function il_docente_con_la_sola_chiave_di_recupero_si_riavvolge(): void
    {
        // Succede: generate() non chiede una chiave del docente, e shred()
        // cancella le chiavi ma non la chiave di recupero.
        $solo = $this->utente($this->marca . 'solo');
        $recuperoA = new TeacherRecoveryService($this->a);
        self::assertTrue($recuperoA->generate($solo)['ok']);
        $chiavi = $this->pdo->prepare('SELECT COUNT(*) FROM teacher_keys WHERE teacher_id = ?');
        $chiavi->execute([$solo]);
        self::assertSame(0, (int)$chiavi->fetchColumn(), 'il docente della prova deve avere solo la chiave di recupero');
        $firma = $recuperoA->signManifestForExporter($solo, self::FIRMATO);
        self::assertNotNull($firma);
        $giro = new RiavvolgimentoDellaMaster($this->pdo, $this->a, $this->b, [$solo]);

        $prova = $giro->prova();
        self::assertSame(1, $prova['docenti']);
        self::assertSame(["$solo"], $prova['recupero']['solo_con_la_vecchia']);
        self::assertTrue(RiavvolgimentoDellaMaster::riuscito($prova));

        self::assertSame(1, $giro->applica()['recupero']['ricifrati']);
        self::assertSame($firma, (new TeacherRecoveryService($this->b))->signManifestForExporter($solo, self::FIRMATO));
        self::assertNull($recuperoA->signManifestForExporter($solo, self::FIRMATO));

        $verifica = $giro->verifica();
        self::assertSame(1, $verifica['recupero']['con_la_nuova']);
        self::assertTrue(RiavvolgimentoDellaMaster::riuscito($verifica));
    }

    #[Test]
    public function rifiuta_chiavi_uguali_o_malformate(): void
    {
        $casi = [
            'uguali'                 => [$this->a, $this->a],
            'uguali, maiuscole'      => [$this->a, strtoupper($this->a)],
            'nuova troppo corta'     => [$this->a, substr($this->b, 1)],
            'vecchia non esadecimale' => ['g' . substr($this->a, 1), $this->b],
            'nuova assente'          => [$this->a, ''],
            'nuova con un a capo'    => [$this->a, $this->b . "\n"],
        ];
        foreach ($casi as $caso => [$vecchia, $nuova]) {
            try {
                new RiavvolgimentoDellaMaster($this->pdo, $vecchia, $nuova, [$this->uno]);
                self::fail("accettate: $caso");
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString(strtolower($this->a), strtolower($e->getMessage()), $caso);
                self::assertStringNotContainsString(substr($this->b, 1), $e->getMessage(), $caso);
            }
        }
        // E nell'altro verso: due chiavi valide e diverse si accettano.
        self::assertInstanceOf(
            RiavvolgimentoDellaMaster::class,
            new RiavvolgimentoDellaMaster($this->pdo, $this->a, strtoupper($this->b), [$this->uno])
        );
    }

    // ── preparazione ──────────────────────────────────────────────────────

    private function prepara(): void
    {
        $conA = new TeacherCryptoService($this->a);
        $recuperoA = new TeacherRecoveryService($this->a);

        foreach ([1, 2, 3] as $v) {
            if ($v > 1) {
                $conA->rotate($this->uno);
            }
            $this->cifra($conA, $this->uno, "contenuto di uno, versione $v");
        }
        self::assertTrue($recuperoA->generate($this->uno)['ok']);
        $this->firmaPrima = $recuperoA->signManifestForExporter($this->uno, self::FIRMATO);

        $this->cifra($conA, $this->due, 'contenuto di due, versione 1');
        $conA->rotate($this->due);
        $this->cifra($conA, $this->due, 'contenuto di due, versione 2');
        self::assertTrue($recuperoA->generate($this->due)['ok']);
        self::assertTrue($recuperoA->revoke($this->due)['ok']);
        $this->portaAlPrefissoStorico($this->due, 1);
        try {
            $conA->decrypt($this->due, $this->buste[3]['busta']);
            self::fail('col prefisso storico l\'applicazione non doveva aprire la riga');
        } catch (RuntimeException $e) {
            self::assertSame('kek_unwrap_failed', $e->getMessage());
        }

        $this->cifra($conA, $this->fuori, 'contenuto di un docente fuori dal giro');
        $this->fotoFuori = $this->foto([$this->fuori]);
    }

    private function cifra(TeacherCryptoService $servizio, int $docente, string $chiaro): void
    {
        $this->buste[] = ['docente' => $docente, 'busta' => $servizio->encrypt($docente, $chiaro), 'chiaro' => $chiaro];
    }

    /**
     * Riscrive una versione come la scriveva il codice prima del cambio di
     * nome (tools/crypto/migrate_hkdf_prefix.php): stessa KEK, prefisso
     * «progetto-precedente-». Qui la cifratura è riscritta a mano apposta: è una copia
     * indipendente da quella della classe sotto prova.
     */
    private function portaAlPrefissoStorico(int $docente, int $versione): void
    {
        $st = $this->pdo->prepare('SELECT wrapped_kek FROM teacher_keys WHERE teacher_id = ? AND key_version = ?');
        $st->execute([$docente, $versione]);
        $avvolta = (string)$st->fetchColumn();
        $tkek = hash_hkdf('sha256', (string)hex2bin($this->a), 32, (string)$versione, "pantedu-teacher-kek-v1|$docente");
        $kek = openssl_decrypt(
            substr($avvolta, 12, 32),
            'aes-256-gcm',
            $tkek,
            OPENSSL_RAW_DATA,
            substr($avvolta, 0, 12),
            substr($avvolta, 44, 16)
        );
        self::assertIsString($kek);
        $this->pdo->prepare('UPDATE teacher_keys SET wrapped_kek = ? WHERE teacher_id = ? AND key_version = ?')
            ->execute([
                $this->avvolgi($kek, (string)hex2bin($this->a), 'progetto-precedente-teacher-kek-v1', $docente, $versione),
                $docente,
                $versione,
            ]);
    }

    private function avvolgi(string $kek, string $master, string $prefisso, int $docente, int $versione): string
    {
        $tkek = hash_hkdf('sha256', $master, 32, (string)$versione, "$prefisso|$docente");
        $iv = random_bytes(12);
        $tag = '';
        $ct = (string)openssl_encrypt($kek, 'aes-256-gcm', $tkek, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        return $iv . $ct . $tag;
    }

    private function utente(string $nome): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, is_super_admin, created_at)
             VALUES (?, "teacher", "Zz", "Riavvolgimento", ?, "x", "approved", 1, 0, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        $id = (int)$this->pdo->lastInsertId();
        $this->utenti[] = $id;
        return $id;
    }

    // ── strumenti ─────────────────────────────────────────────────────────

    private function giro(): RiavvolgimentoDellaMaster
    {
        return new RiavvolgimentoDellaMaster($this->pdo, $this->a, $this->b, [$this->uno, $this->due]);
    }

    /**
     * @param list<string> $argomenti
     * @return array{0: int, 1: string} codice d'uscita, uscita ed errori insieme
     */
    private function comando(array $argomenti, ?PDO $pdo = null): array
    {
        $pdo ??= $this->pdo;
        $uscita = fopen('php://memory', 'w+');
        $errori = fopen('php://memory', 'w+');
        self::assertIsResource($uscita);
        self::assertIsResource($errori);
        $codice = ComandoDiRiavvolgimento::esegui(
            $argomenti,
            ['KMS_MASTER_KEY' => $this->a, 'KMS_MASTER_KEY_NEW' => $this->b],
            static fn (): PDO => $pdo,
            $uscita,
            $errori
        );
        rewind($uscita);
        rewind($errori);
        return [$codice, (string)stream_get_contents($uscita) . (string)stream_get_contents($errori)];
    }

    /**
     * Impronta di ogni riga dei docenti dati, colonna per colonna.
     *
     * @param list<int> $docenti
     * @return array<string, string> «k:docente/vN» e «r:docente» → sha256
     */
    private function foto(array $docenti): array
    {
        $in = implode(',', array_map('intval', $docenti));
        $foto = [];
        foreach ($this->pdo->query("SELECT * FROM teacher_keys WHERE teacher_id IN ($in) ORDER BY teacher_id, key_version")
            ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $foto['k:' . $r['teacher_id'] . '/v' . $r['key_version']] = hash('sha256', serialize($r));
        }
        foreach ($this->pdo->query("SELECT * FROM teacher_recovery_keys WHERE user_id IN ($in) ORDER BY user_id")
            ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $foto['r:' . $r['user_id']] = hash('sha256', serialize($r));
        }
        return $foto;
    }

    /**
     * I blob avvolti così come stanno nel database.
     *
     * @param list<int> $docenti
     * @return array<string, string>
     */
    private function grezzi(array $docenti): array
    {
        $in = implode(',', array_map('intval', $docenti));
        $grezzi = [];
        foreach ($this->pdo->query("SELECT teacher_id, key_version, wrapped_kek FROM teacher_keys WHERE teacher_id IN ($in)")
            ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $grezzi['k:' . $r['teacher_id'] . '/v' . $r['key_version']] = (string)$r['wrapped_kek'];
        }
        foreach ($this->pdo->query("SELECT user_id, wrapped_recovery FROM teacher_recovery_keys WHERE user_id IN ($in)")
            ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $grezzi['r:' . $r['user_id']] = (string)$r['wrapped_recovery'];
        }
        return $grezzi;
    }

    /**
     * Gli IV (i primi 12 byte) dei blob avvolti, in esadecimale.
     *
     * @param list<int> $docenti
     * @return array<string, string>
     */
    private function iv(array $docenti): array
    {
        return array_map(static fn (string $blob): string => bin2hex(substr($blob, 0, 12)), $this->grezzi($docenti));
    }

    /** Una connessione nuova che fa riuscire $n UPDATE e poi lancia. */
    private function connessioneCheCede(int $n): PDO
    {
        IstruzioneCheCede::$updatePrimaDelGuasto = $n;
        return $this->connessione(IstruzioneCheCede::class);
    }

    /**
     * Una connessione nuova al database delle prove, con le istruzioni della
     * classe data. Non vede le tabelle temporanee di $this->pdo: vale per i
     * giri che non scrivono nei registri.
     *
     * @param class-string<PDOStatement> $istruzione
     */
    private function connessione(string $istruzione): PDO
    {
        $socket = (string)(Config::get('database.socket') ?? '');
        $dsn = $socket !== ''
            ? sprintf(
                '%s:unix_socket=%s;dbname=%s;charset=%s',
                Config::get('database.driver'),
                $socket,
                Config::get('database.name'),
                Config::get('database.charset')
            )
            : sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=%s',
                Config::get('database.driver'),
                Config::get('database.host'),
                Config::get('database.port'),
                Config::get('database.name'),
                Config::get('database.charset')
            );
        return new PDO($dsn, (string)Config::get('database.user'), (string)Config::get('database.pass'), [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STATEMENT_CLASS  => [$istruzione, []],
        ]);
    }
}

/**
 * Un'istruzione che fa riuscire i primi N UPDATE e al successivo lancia, come
 * farebbe una connessione caduta o un lock scaduto a metà giro.
 */
final class IstruzioneCheCede extends PDOStatement
{
    public static int $updatePrimaDelGuasto = PHP_INT_MAX;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        if (stripos(ltrim($this->queryString), 'UPDATE') === 0) {
            if (self::$updatePrimaDelGuasto <= 0) {
                throw new PDOException('guasto simulato');
            }
            self::$updatePrimaDelGuasto--;
        }
        return parent::execute($params);
    }
}

/**
 * Un'istruzione che si lascia guardare dentro nel momento in cui il giro legge
 * l'elenco dei docenti (la sola query con UNION): può togliere un docente
 * dall'elenco, come farebbe un difetto, o far scrivere qualcun altro subito
 * dopo, come a sito acceso.
 */
final class IstruzioneSorvegliata extends PDOStatement
{
    public static ?int $nascondi = null;
    public static ?\Closure $dopoIDocenti = null;

    protected function __construct()
    {
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $righe = parent::fetchAll($mode, ...$args);
        if (!str_contains($this->queryString, 'UNION')) {
            return $righe;
        }
        if (self::$nascondi !== null) {
            $righe = array_values(array_filter($righe, static fn ($id): bool => (int)$id !== self::$nascondi));
        }
        if (self::$dopoIDocenti !== null) {
            $dopo = self::$dopoIDocenti;
            self::$dopoIDocenti = null;
            $dopo();
        }
        return $righe;
    }
}
