<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\Mailer;
use App\Services\Risdoc\ScadenzaDelleBozze;
use App\Services\Risdoc\SpazzataDelleBozze;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il giro notturno cancella quello che deve, e soltanto quello.
 *
 * ── Perché contro un database vero ────────────────────────────────────────
 *
 * La regola dei giorni si prova senza database ({@see \Tests\Unit\Services\Risdoc\ScadenzaDelleBozzeTest}).
 * Qui si prova ciò che quella prova non può vedere: che le due colonne nuove
 * escano dalla vista, che l'UPDATE non sposti `updated_at`, che la DELETE
 * colpisca la tabella e non la vista, e che una riga si cancelli davvero.
 *
 * ── Le due direzioni ──────────────────────────────────────────────────────
 *
 * Il verso «deve cancellare» è l'azione distruttiva: se lo si provasse da
 * solo, una funzione che cancella tutto lo passerebbe. Per ogni riga che deve
 * sparire ce n'è una accanto che deve restare, e si controlla che sia ancora
 * lì alla fine dello stesso giro.
 *
 * ── Niente resta nel database ─────────────────────────────────────────────
 *
 * Tutto dentro una transazione con rollback in tearDown, anche quando la prova
 * fallisce. La posta è finta: nessun messaggio esce di qui.
 */
final class SpazzataDelleBozzeTest extends TestCase
{
    private PDO $pdo;
    private int $docente = 0;
    private int $modello = 0;
    /** @var list<array{to:string,subject:string,body:string}> */
    private array $inviate = [];

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 2);
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

        // Senza la migrazione 138 non c'è niente da provare, e dirlo è
        // meglio che un verde: una prova saltata si vede, una che non misura no.
        $colonne = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'risdoc_compilations_data'
                AND COLUMN_NAME IN ('exported_at', 'expiry_warned_at')"
        )->fetchColumn();
        if ($colonne !== 2) {
            $this->markTestSkipped('migrazione 138 non applicata su questo database');
        }

        $this->pdo->beginTransaction();

        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES (?, 'teacher', 'Zz', 'Scaduta', ?, 'x', 'approved', 1)"
        )->execute(['zz_bozze_prof', 'zz_bozze_prof@example.test']);
        $this->docente = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO risdoc_templates (code, category, num_arg, argomento, source_dir, html_file, source_hash)
             VALUES (?, 'modelli', '00', 'Piano di prova', '/zz', 'zz.html', 'zz')"
        )->execute(['ZZ_BOZZE_' . bin2hex(random_bytes(3))]);
        $this->modello = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Una compilazione con le date che voglio io. `updated_at` si scrive
     * esplicitamente: `ON UPDATE CURRENT_TIMESTAMP` scatta sugli UPDATE, non
     * sugli INSERT con un valore dichiarato.
     */
    private function bozza(string $etichetta, string $modificata, ?string $scaricata, ?string $avvisata = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO risdoc_compilations_data
                 (teacher_id, template_id, compilation_key, label, data_json,
                  created_at, updated_at, exported_at, expiry_warned_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->docente,
            $this->modello,
            'combo_' . $etichetta . '_' . bin2hex(random_bytes(3)),
            $etichetta,
            '{"campi":{}}',
            $modificata,
            $modificata,
            $scaricata,
            $avvisata,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    private function spazzata(): SpazzataDelleBozze
    {
        $this->inviate = [];
        $posta = fn(): Mailer => new Mailer(
            'noreply@example.test',
            'Pantedu',
            function (string $to, string $subject, string $body): bool {
                $this->inviate[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
                return true;
            }
        );

        return new SpazzataDelleBozze($this->pdo, $posta);
    }

    private function esiste(int $id): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM risdoc_compilations_data WHERE id = ?');
        $st->execute([$id]);

        return (bool)$st->fetchColumn();
    }

    private function quando(string $espressione): string
    {
        return (string)$this->pdo->query("SELECT DATE_FORMAT({$espressione}, '%Y-%m-%d %H:%i:%s')")->fetchColumn();
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Controllo positivo sulla lettura: se il censimento non vede le righe
     * appena inserite, tutto il resto di questa classe passerebbe misurando
     * zero.
     */
    #[Test]
    public function il_censimento_vede_le_bozze_e_le_sue_date(): void
    {
        $id = $this->bozza('fresca', $this->quando('NOW()'), null);

        $censimento = $this->spazzata()->censimento();
        $mia = null;
        foreach ($censimento['righe'] as $r) {
            if ((int)$r['id'] === $id) {
                $mia = $r;
            }
        }

        self::assertNotNull($mia, 'la riga appena inserita deve comparire nel censimento');
        self::assertSame('fresca', $mia['etichetta']);
        self::assertSame('Piano di prova', $mia['modello'], 'il nome del modello esce dalla giunzione');
        self::assertSame(ScadenzaDelleBozze::VIVA, $mia['stato']);
        self::assertNotSame('', $censimento['adesso'], "l'ora arriva dal database");
    }

    /**
     * Il verso che cancella. Scaricata venti giorni fa, avvisata dieci giorni
     * fa: è matura e deve sparire.
     */
    #[Test]
    public function una_bozza_scaduta_e_avvisata_si_cancella(): void
    {
        $morta = $this->bozza('scaduta', $this->quando('NOW() - INTERVAL 20 DAY'), $this->quando('NOW() - INTERVAL 20 DAY'), $this->quando('NOW() - INTERVAL 10 DAY'));
        $viva  = $this->bozza('di oggi', $this->quando('NOW()'), $this->quando('NOW()'));

        $esito = $this->spazzata()->gira(true);

        self::assertFalse($this->esiste($morta), 'la bozza scaduta e avvisata doveva sparire');
        self::assertTrue($this->esiste($viva), 'e quella di oggi doveva restare: se sparisce, cancella tutto');
        self::assertGreaterThanOrEqual(1, $esito['cancellate']);
        self::assertGreaterThan(0, $esito['guardate'], '«zero cancellate» non è un esito: si dice quante se ne sono guardate');
    }

    /**
     * La promessa: prima l'avviso. Una riga scaduta che nessuno ha annunciato
     * non si cancella, nemmeno se è scaduta da un mese.
     */
    #[Test]
    public function una_bozza_scaduta_ma_mai_avvisata_non_si_cancella(): void
    {
        $id = $this->bozza('scaduta muta', $this->quando('NOW() - INTERVAL 40 DAY'), $this->quando('NOW() - INTERVAL 40 DAY'));

        $esito = $this->spazzata()->gira(true);

        self::assertTrue($this->esiste($id), 'senza avviso non si cancella: è il patto con il docente');
        self::assertGreaterThanOrEqual(1, $esito['scadute_in_attesa'], 'e il ritardo deve essere visibile nel resoconto');

        // Ma l'avviso parte stanotte (23/9/2026, revisione Risdoc A2): prima
        // la riga restava muta per sempre, e non si cancellava mai.
        self::assertNotNull($this->avvisataIl($id), 'la bozza scaduta e muta riceve l\'avviso la prima notte');
        self::assertCount(1, $this->inviate, 'un messaggio al docente');
        self::assertStringContainsString('scaduta muta', $this->inviate[0]['body']);
    }

    /**
     * E tre giorni dopo l'avviso si cancella, come le altre. Il tempo non si
     * può far passare: si sposta indietro la data dell'avviso, che è ciò che
     * il giro guarda.
     */
    #[Test]
    public function tre_giorni_dopo_l_avviso_tardivo_la_bozza_si_cancella(): void
    {
        $id = $this->bozza('scaduta e poi avvisata', $this->quando('NOW() - INTERVAL 40 DAY'), $this->quando('NOW() - INTERVAL 40 DAY'));

        $this->spazzata()->gira(true);
        self::assertTrue($this->esiste($id), 'la prima notte solo l\'avviso');
        self::assertNotNull($this->avvisataIl($id));

        // `updated_at = updated_at`: senza, la colonna salta a NOW() (ON UPDATE)
        // e la bozza torna viva — il giro farebbe bene a non cancellarla.
        $this->pdo->prepare(
            'UPDATE risdoc_compilations_data
                SET expiry_warned_at = expiry_warned_at - INTERVAL ' . (SpazzataDelleBozze::ATTESA_DOPO_AVVISO_GIORNI + 1) . ' DAY,
                    updated_at = updated_at
              WHERE id = ?'
        )->execute([$id]);

        $esito = $this->spazzata()->gira(true);

        self::assertFalse($this->esiste($id), 'avvisata da più di tre giorni: si cancella');
        self::assertSame([], $this->inviate, 'e non riceve un secondo avviso');
        self::assertGreaterThanOrEqual(1, $esito['cancellate']);
    }

    private function avvisataIl(int $id): ?string
    {
        $st = $this->pdo->prepare('SELECT expiry_warned_at FROM risdoc_compilations_data WHERE id = ?');
        $st->execute([$id]);
        $v = $st->fetchColumn();

        return $v === false || $v === null ? null : (string)$v;
    }

    /**
     * E nemmeno se l'avviso è appena partito: servono i tre giorni.
     */
    #[Test]
    public function una_bozza_avvisata_ieri_non_si_cancella_oggi(): void
    {
        $id = $this->bozza('avvisata ieri', $this->quando('NOW() - INTERVAL 30 DAY'), $this->quando('NOW() - INTERVAL 30 DAY'), $this->quando('NOW() - INTERVAL 1 DAY'));

        $this->spazzata()->gira(true);

        self::assertTrue($this->esiste($id), 'un avviso di ieri non ha ancora avuto il tempo di essere letto');
    }

    /**
     * L'avviso: parte, dice le cose giuste, e segna la riga.
     */
    #[Test]
    public function a_pochi_giorni_dalla_fine_parte_l_avviso_e_la_riga_viene_segnata(): void
    {
        // Scaricata 9 giorni fa: ne mancano 6, dentro il preavviso di 7.
        $id = $this->bozza('Relazione 3B', $this->quando('NOW() - INTERVAL 9 DAY'), $this->quando('NOW() - INTERVAL 9 DAY'));

        $spazzata = $this->spazzata();
        $esito = $spazzata->gira(true);

        self::assertSame(1, $esito['avvisi'][SpazzataDelleBozze::INVIATA] ?? 0, 'un docente, un messaggio');
        self::assertCount(1, $this->inviate);
        self::assertSame('zz_bozze_prof@example.test', $this->inviate[0]['to']);
        self::assertStringContainsString('Relazione 3B', $this->inviate[0]['body'], "l'etichetta serve a riconoscerla");
        self::assertStringContainsString('aprila e modificala', $this->inviate[0]['body'], 'e deve dire come tenerla');

        $st = $this->pdo->prepare('SELECT expiry_warned_at FROM risdoc_compilations_data WHERE id = ?');
        $st->execute([$id]);
        self::assertNotNull($st->fetchColumn(), 'segnata come avvisata, altrimenti riparte domani');

        self::assertTrue($this->esiste($id), 'avvisare non è cancellare');
    }

    /**
     * Un solo messaggio per docente, anche con più bozze in scadenza: tre
     * email in fila sono tre email che si ignorano.
     */
    #[Test]
    public function piu_bozze_dello_stesso_docente_fanno_un_messaggio_solo(): void
    {
        $quando = $this->quando('NOW() - INTERVAL 9 DAY');
        $this->bozza('Prima', $quando, $quando);
        $this->bozza('Seconda', $quando, $quando);
        $this->bozza('Terza', $quando, $quando);

        $this->spazzata()->gira(true);

        self::assertCount(1, $this->inviate);
        foreach (['Prima', 'Seconda', 'Terza'] as $etichetta) {
            self::assertStringContainsString($etichetta, $this->inviate[0]['body']);
        }
        // L'oggetto viaggia codificato secondo RFC 2047 (`=?UTF-8?B?...?=`):
        // confrontarlo grezzo passerebbe solo per caso, e fallirebbe sempre.
        self::assertStringContainsString('3 bozze', mb_decode_mimeheader($this->inviate[0]['subject']));
    }

    /**
     * Segnare l'avviso non deve spostare `updated_at`: se lo spostasse, la
     * scadenza si allontanerebbe proprio mentre si annuncia che arriva, e la
     * riga non morirebbe mai.
     */
    #[Test]
    public function segnare_l_avviso_non_sposta_la_data_di_modifica(): void
    {
        $quando = $this->quando('NOW() - INTERVAL 9 DAY');
        $id = $this->bozza('Non toccarmi', $quando, $quando);

        $this->spazzata()->gira(true);

        $st = $this->pdo->prepare('SELECT updated_at FROM risdoc_compilations_data WHERE id = ?');
        $st->execute([$id]);
        self::assertSame($quando, (string)$st->fetchColumn(), 'updated_at doveva restare ferma');
    }

    /**
     * La prova senza scrittura non scrive. Se scrivesse, chi la lancia per
     * vedere cosa succederebbe se la troverebbe già successa.
     */
    #[Test]
    public function la_prova_non_scrive_niente(): void
    {
        $morta = $this->bozza('scaduta', $this->quando('NOW() - INTERVAL 20 DAY'), $this->quando('NOW() - INTERVAL 20 DAY'), $this->quando('NOW() - INTERVAL 10 DAY'));
        $inScadenza = $this->bozza('in scadenza', $this->quando('NOW() - INTERVAL 9 DAY'), $this->quando('NOW() - INTERVAL 9 DAY'));

        $esito = $this->spazzata()->gira(false);

        self::assertTrue($this->esiste($morta), 'la prova non cancella');
        self::assertSame(0, $esito['cancellate']);
        self::assertSame([], $this->inviate, 'e non manda posta');

        $st = $this->pdo->prepare('SELECT expiry_warned_at FROM risdoc_compilations_data WHERE id = ?');
        $st->execute([$inScadenza]);
        self::assertNull($st->fetchColumn(), 'e non segna niente');

        // ...ma dice che cosa farebbe: altrimenti non servirebbe a nulla.
        self::assertGreaterThanOrEqual(1, $esito['da_cancellare']);
        self::assertGreaterThanOrEqual(1, $esito['da_avvisare']);
    }

    /**
     * Senza posta configurata la riga viene segnata lo stesso, e il motivo
     * esce nel resoconto. Il contrario sarebbe una conservazione che non
     * finisce mai per un indirizzo mancante.
     */
    #[Test]
    public function senza_posta_la_riga_si_segna_lo_stesso_e_si_dice(): void
    {
        $quando = $this->quando('NOW() - INTERVAL 9 DAY');
        $id = $this->bozza('Senza posta', $quando, $quando);

        $esito = (new SpazzataDelleBozze($this->pdo, static fn(): ?Mailer => null))->gira(true);

        self::assertSame(1, $esito['avvisi'][SpazzataDelleBozze::SENZA_POSTA] ?? 0);

        $st = $this->pdo->prepare('SELECT expiry_warned_at FROM risdoc_compilations_data WHERE id = ?');
        $st->execute([$id]);
        self::assertNotNull($st->fetchColumn(), 'segnata lo stesso: altrimenti resterebbe per sempre');
    }

    /**
     * Un invio fallito, invece, non segna: si ritenta la notte dopo.
     */
    #[Test]
    public function un_invio_fallito_non_segna_e_si_ritenta(): void
    {
        $quando = $this->quando('NOW() - INTERVAL 9 DAY');
        $id = $this->bozza('Posta rotta', $quando, $quando);

        $posta = static fn(): Mailer => new Mailer(
            'noreply@example.test',
            'Pantedu',
            static fn(string $to, string $subject, string $body): bool => false
        );
        $esito = (new SpazzataDelleBozze($this->pdo, $posta))->gira(true);

        self::assertSame(1, $esito['avvisi'][SpazzataDelleBozze::NON_PARTITA] ?? 0);

        $st = $this->pdo->prepare('SELECT expiry_warned_at FROM risdoc_compilations_data WHERE id = ?');
        $st->execute([$id]);
        self::assertNull($st->fetchColumn(), 'un guasto della posta non consuma l\'avviso');
    }

    /**
     * Il buco trovato provando il giro con la posta assente (22/9/2026).
     *
     * Una riga non si cancella finché l'avviso non è partito. Se la posta è
     * rotta l'avviso non parte **mai**: la riga resta scaduta e muta per
     * sempre, e — questo è il punto — non entra mai fra le mature, perché le
     * mature richiedono `expiry_warned_at`. Un controllo che guardasse solo le
     * mature resterebbe verde mentre l'istanza non cancella più niente.
     *
     * Provato nei due versi: scaduta da poco e muta non è bloccata (è il caso
     * normale, il giro di stanotte la annuncerà); scaduta da due settimane e
     * ancora muta lo è.
     */
    #[Test]
    public function una_bozza_scaduta_da_troppo_e_mai_avvisata_risulta_bloccata(): void
    {
        $appena = $this->bozza('scaduta ieri', $this->quando('NOW() - INTERVAL 16 DAY'), $this->quando('NOW() - INTERVAL 16 DAY'));
        $vecchia = $this->bozza('scaduta da due settimane', $this->quando('NOW() - INTERVAL 40 DAY'), $this->quando('NOW() - INTERVAL 40 DAY'));

        $censimento = $this->spazzata()->censimento();
        $bloccate = SpazzataDelleBozze::bloccate($censimento['righe'], $censimento['adesso']);
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $bloccate);

        self::assertContains($vecchia, $ids, 'scaduta da due settimane e mai annunciata: è bloccata');
        self::assertNotContains(
            $appena,
            $ids,
            'scaduta da un giorno e muta è il caso normale: il giro di stanotte la annuncia'
        );
    }

    /**
     * E una riga avvisata non è bloccata, neanche se scaduta da un mese: sta
     * solo aspettando i tre giorni, o li ha già passati ed è fra le mature.
     */
    #[Test]
    public function una_bozza_avvisata_non_e_mai_bloccata(): void
    {
        $id = $this->bozza(
            'avvisata e scaduta',
            $this->quando('NOW() - INTERVAL 40 DAY'),
            $this->quando('NOW() - INTERVAL 40 DAY'),
            $this->quando('NOW() - INTERVAL 1 DAY')
        );

        $censimento = $this->spazzata()->censimento();
        $ids = array_map(
            static fn(array $r): int => (int)$r['id'],
            SpazzataDelleBozze::bloccate($censimento['righe'], $censimento['adesso'])
        );

        self::assertNotContains($id, $ids, 'l\'avviso è partito: non è un blocco, è un\'attesa');
    }

    /**
     * La colonna nuova esce dalla vista. È la trappola della migrazione 101,
     * ripetuta: `SELECT rc.*` viene espanso alla creazione, e senza ricreare
     * la vista il repository non vedrebbe mai `exported_at`.
     */
    #[Test]
    public function la_vista_espone_le_colonne_nuove(): void
    {
        $quando = $this->quando('NOW() - INTERVAL 2 DAY');
        $id = $this->bozza('Dalla vista', $quando, $quando);

        $st = $this->pdo->prepare('SELECT exported_at, expiry_warned_at FROM risdoc_compilations WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($r, 'la riga deve uscire dalla vista');
        self::assertArrayHasKey('exported_at', $r, 'senza la ricreazione della vista questa chiave non esisterebbe');
        self::assertSame($quando, (string)$r['exported_at']);
    }
}
