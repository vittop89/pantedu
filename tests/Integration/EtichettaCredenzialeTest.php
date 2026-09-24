<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\TeacherCredentialRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'etichetta delle credenziali di classe la compone il server (ADR-044):
 * classe e indirizzo del perimetro, materie scelte fra quelle spuntate dal
 * docente nell'istituto, aggiunta facoltativa. Prima era testo libero: in
 * produzione le due etichette dichiaravano una sezione mentre il perimetro era
 * l'anno (misurato il 19 settembre 2026).
 *
 * Fixture isolata in transazione (rollback in tearDown): un istituto con un
 * indirizzo, l'anno 3 e la sezione 3B, quattro materie; il docente ne ha
 * spuntate tre, un collega nessuna.
 */
final class EtichettaCredenzialeTest extends TestCase
{
    private PDO $pdo;
    private TeacherCredentialRepository $repo;
    private int $scuola = 0;
    private int $docente = 0;
    private int $collega = 0;
    private bool $inTx = false;

    private const IND = 'ZET';

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$basePath/$f")) {
                \Dotenv\Dotenv::createMutable($basePath, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($basePath . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT materie, aggiunta FROM teacher_access_credentials LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazione 134 non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        \App\Support\CurriculumLookup::resetCache();

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZETIC01', 'SCUOLA DELLE ETICHETTE', 'Comune Esempio']);
        $this->scuola = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("UPDATE institutes SET sezioni_docenti = 'tutti' WHERE id = ?")->execute([$this->scuola]);
        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool, origine)
             VALUES (?, ?, ?, ?, ?, 1, 0, "istituto")'
        );
        $voce->execute(['indirizzi', $this->scuola, self::IND, 'Scientifico', null]);
        $voce->execute(['classi', $this->scuola, '3', 'Terza', self::IND]);
        $voce->execute(['classi', $this->scuola, '3B', 'Terza B', self::IND]);
        $materie = [];
        foreach (['ZMA' => 'Matematica', 'ZFI' => 'Fisica', 'ZGE' => 'Geografia', 'ZIN' => 'Informatica'] as $code => $label) {
            $voce->execute(['materie', $this->scuola, $code, $label, null]);
            $materie[$code] = (int)$this->pdo->lastInsertId();
        }
        $this->docente = $this->utente('zzetichette_doc');
        $this->collega = $this->utente('zzetichette_col');
        $spunta = $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active, sospesa_dalla_scuola) VALUES (?, ?, 1, 0)');
        foreach (['ZMA', 'ZFI', 'ZGE'] as $code) {
            $spunta->execute([$materie[$code], $this->docente]);
        }
        $this->repo = new TeacherCredentialRepository();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        \App\Support\CurriculumLookup::resetCache();
    }

    private function utente(string $username): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", ?, ?, "x", "approved", 1, NOW())'
        )->execute([$username, $username, $username . '@example.invalid']);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $this->scuola]);
        return $id;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array{id:int,label:string}
     */
    private function crea(array $extra, ?int $docente = null): array
    {
        return $this->repo->crea($docente ?? $this->docente, $extra + [
            'username' => 'zz-etic-' . substr(uniqid(), -7) . random_int(10, 99),
            'password' => 'Pa55!etichetta',
            'institute_id' => $this->scuola,
        ]);
    }

    private function codiceDi(callable $azione): string
    {
        try {
            $azione();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return '(nessun rifiuto)';
    }

    /** @return array<string,mixed> */
    private function riga(int $id): array
    {
        $st = $this->pdo->prepare('SELECT label, materie, aggiunta FROM teacher_access_credentials_data WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($r);
        return $r;
    }

    #[Test]
    public function l_etichetta_la_compone_il_server_e_il_label_del_client_si_ignora(): void
    {
        $c = $this->crea([
            'label' => 'Prof Rossi 3A mattina',
            'indirizzo' => self::IND, 'classe' => '3', 'materie' => 'ZMA,ZFI',
        ]);
        $this->assertSame('3_ZET_ZFI-ZMA', $c['label'], 'anno, indirizzo, materie in ordine alfabetico');
        $r = $this->riga($c['id']);
        $this->assertSame('3_ZET_ZFI-ZMA', $r['label']);
        $this->assertSame('ZFI,ZMA', $r['materie'], 'le parti restano, per ricomporla');
        $this->assertNull($r['aggiunta']);
    }

    #[Test]
    public function la_sezione_resta_sezione_e_l_aggiunta_diventa_maiuscola(): void
    {
        $c = $this->crea(['indirizzo' => self::IND, 'classe' => '3b', 'materie' => ['ZGE'], 'aggiunta' => 'gruppo-b']);
        $this->assertSame('3B_ZET_ZGE_GRUPPO-B', $c['label']);
        $this->assertSame('GRUPPO-B', $this->riga($c['id'])['aggiunta']);
    }

    #[Test]
    public function per_tutte_le_classi_comincia_con_tutte(): void
    {
        $this->assertSame('TUTTE_ZFI-ZGE-ZMA', $this->crea(['materie' => 'ZGE,ZMA,ZFI'])['label']);
    }

    #[Test]
    public function una_materia_non_spuntata_e_rifiutata(): void
    {
        $this->assertSame('materia_non_tua', $this->codiceDi(fn() => $this->crea(['materie' => 'ZMA,ZIN'])), 'ZIN è della scuola, non del docente');
        $this->assertSame('materia_non_tua', $this->codiceDi(fn() => $this->crea(['materie' => 'ZMA'], $this->collega)), 'il collega non ha spunte');
        $this->assertSame('TUTTE', $this->crea([], $this->collega)['label'], 'senza materie si crea');
    }

    #[Test]
    public function la_stessa_etichetta_non_si_ripete_se_non_con_un_aggiunta(): void
    {
        $base = ['indirizzo' => self::IND, 'classe' => '3', 'materie' => 'ZMA'];
        $prima = $this->crea($base);
        $this->assertSame('etichetta_gia_usata', $this->codiceDi(fn() => $this->crea($base)));
        $this->assertSame('etichetta_gia_usata', $this->codiceDi(fn() => $this->crea(['materie' => 'zma'] + $base)), 'anche con la sigla in minuscolo');
        // Verso opposto: con un'aggiunta si crea.
        $this->assertSame('3_ZET_ZMA_POMERIGGIO', $this->crea(['aggiunta' => 'pomeriggio'] + $base)['label']);
        // Un altro docente può avere la stessa etichetta.
        $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active, sospesa_dalla_scuola)
                              SELECT id, ?, 1, 0 FROM curriculum_entries WHERE institute_id = ? AND kind = "materie" AND code = "ZMA"')
            ->execute([$this->collega, $this->scuola]);
        $this->assertSame('3_ZET_ZMA', $this->crea($base, $this->collega)['label']);
        // Una credenziale scaduta non occupa l'etichetta.
        $this->pdo->prepare('UPDATE teacher_access_credentials_data SET expires_at = "2020-01-01" WHERE id = ?')->execute([$prima['id']]);
        $this->assertSame('3_ZET_ZMA', $this->crea($base)['label']);
    }

    #[Test]
    public function un_aggiunta_fuori_regola_e_rifiutata(): void
    {
        foreach (['Prof Rossi', 'a_b', '1234567890123', 'àè'] as $a) {
            $this->assertSame('aggiunta_non_valida', $this->codiceDi(fn() => $this->crea(['aggiunta' => $a])), $a);
        }
    }

    #[Test]
    public function un_etichetta_libera_si_rigenera_sul_suo_perimetro(): void
    {
        // Una credenziale nata prima del 19 settembre 2026: etichetta libera,
        // materie NULL.
        $classe = (int)$this->pdo->query("SELECT id FROM curriculum_entries WHERE institute_id = {$this->scuola} AND kind = 'classi' AND code = '3'")->fetchColumn();
        $ind = (int)$this->pdo->query("SELECT id FROM curriculum_entries WHERE institute_id = {$this->scuola} AND kind = 'indirizzi'")->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO teacher_access_credentials_data (teacher_id, label, access_username, password_hash, indirizzo_id, classe_id, institute_id, active)
             VALUES (?, "3A mattina", ?, "x", ?, ?, ?, 1)'
        )->execute([$this->docente, 'zz-etic-libera-' . substr(uniqid(), -6), $ind, $classe, $this->scuola]);
        $id = (int)$this->pdo->lastInsertId();
        $this->assertNull($this->riga($id)['materie'], 'libera: nessuna parte salvata');

        $this->assertSame('3_ZET_ZFI-ZMA_A', $this->repo->setEtichetta($this->docente, $id, ['ZMA', 'ZFI'], 'a'));
        $r = $this->riga($id);
        $this->assertSame('3_ZET_ZFI-ZMA_A', $r['label']);
        $this->assertSame('ZFI,ZMA', $r['materie']);
        $this->assertSame('A', $r['aggiunta']);
        // Rigenerarla uguale non si scontra con sé stessa.
        $this->assertSame('3_ZET_ZFI-ZMA_A', $this->repo->setEtichetta($this->docente, $id, 'ZFI,ZMA', 'A'));
        // Una materia non sua no; un altro docente nemmeno la vede.
        $this->assertSame('materia_non_tua', $this->codiceDi(fn() => $this->repo->setEtichetta($this->docente, $id, 'ZIN', null)));
        $this->assertNull($this->repo->setEtichetta($this->collega, $id, 'ZMA', null));
        $this->assertSame('3_ZET_ZFI-ZMA_A', $this->riga($id)['label'], 'i rifiuti non hanno scritto');
    }

    #[Test]
    public function un_etichetta_libera_senza_istituto_prende_le_materie_del_docente(): void
    {
        // La forma delle credenziali nate prima del 13 settembre 2026, quando
        // l'istituto non si scriveva: `institute_id` NULL, `materie` NULL,
        // etichetta libera. Sono 25 righe su 26 nel database di sviluppo, e
        // sono proprio quelle che l'ADR-044 (punto 10) chiede al docente di
        // rigenerare. Fino alla revisione di questa PR le materie del docente
        // si cercavano solo nell'istituto della riga: con NULL l'elenco era
        // vuoto e ogni sigla diventava `materia_non_tua`, mentre il modulo le
        // mostrava tutte spuntate.
        $this->pdo->prepare(
            'INSERT INTO teacher_access_credentials_data (teacher_id, label, access_username, password_hash, institute_id, active)
             VALUES (?, "3A mattina", ?, "x", NULL, 1)'
        )->execute([$this->docente, 'zz-etic-senza-ist-' . substr(uniqid(), -6)]);
        $id = (int)$this->pdo->lastInsertId();

        $this->assertSame('TUTTE_ZFI-ZMA', $this->repo->setEtichetta($this->docente, $id, ['ZMA', 'ZFI'], null));
        $this->assertSame('ZFI,ZMA', $this->riga($id)['materie']);
        // L'anteprima della stessa riga dice la stessa etichetta.
        $this->assertSame(
            ['label' => 'TUTTE_ZFI-ZMA', 'gia_usata' => false],
            $this->repo->anteprimaEtichetta($this->docente, ['id' => $id, 'materie' => 'ZFI,ZMA'])
        );
        // Verso opposto: il ripiego non allarga il perimetro delle sigle
        // ammesse. ZIN è della scuola ma il docente non l'ha spuntata.
        $this->assertSame('materia_non_tua', $this->codiceDi(fn() => $this->repo->setEtichetta($this->docente, $id, 'ZIN', null)));
        $this->assertSame('TUTTE_ZFI-ZMA', $this->riga($id)['label'], 'il rifiuto non ha scritto');
    }

    #[Test]
    public function rigenerare_sull_etichetta_di_un_altra_credenziale_e_rifiutato(): void
    {
        $a = $this->crea(['indirizzo' => self::IND, 'classe' => '3', 'materie' => 'ZMA']);
        $b = $this->crea(['indirizzo' => self::IND, 'classe' => '3', 'materie' => 'ZMA', 'aggiunta' => 'B']);
        $this->assertSame('etichetta_gia_usata', $this->codiceDi(fn() => $this->repo->setEtichetta($this->docente, $b['id'], 'ZMA', null)));
        $this->assertSame('3_ZET_ZMA_B', $this->riga($b['id'])['label']);
        $this->assertSame('3_ZET_ZMA', $this->riga($a['id'])['label']);
    }

    #[Test]
    public function riportare_in_vita_una_scaduta_non_lascia_due_etichette_uguali(): void
    {
        $base = ['indirizzo' => self::IND, 'classe' => '3', 'materie' => 'ZMA'];
        $a = $this->crea($base);
        // A scade: la sua etichetta torna libera (punto 8 dell'ADR-044) e B se
        // la prende. Fino alla revisione di questa PR bastava rimettere una
        // scadenza futura ad A per averne due vive e indistinguibili: nel
        // portachiavi lo studente vedeva due voci uguali e non sapeva quale
        // «Esci» premere.
        $this->pdo->prepare('UPDATE teacher_access_credentials_data SET expires_at = "2020-01-01" WHERE id = ?')->execute([$a['id']]);
        $b = $this->crea($base);
        $this->assertSame($a['label'], $b['label'], 'la stessa etichetta: una scaduta, una viva');

        $fra30 = date('Y-m-d', strtotime('+30 days'));
        $this->assertSame('etichetta_in_conflitto', $this->codiceDi(fn() => $this->repo->setExpiry($this->docente, $a['id'], $fra30)));
        $this->assertSame('etichetta_in_conflitto', $this->codiceDi(fn() => $this->repo->setExpiry($this->docente, $a['id'], null)), 'nemmeno «senza scadenza»');
        $st = $this->pdo->prepare('SELECT expires_at FROM teacher_access_credentials_data WHERE id = ?');
        $st->execute([$a['id']]);
        $this->assertSame('2020-01-01', $st->fetchColumn(), 'e la scadenza non si è mossa');

        // Verso opposto: rigenerata l'etichetta di A, la scadenza si sposta; e
        // una credenziale non scaduta si sposta come prima.
        $this->assertSame('3_ZET_ZMA_RIPRESA', $this->repo->setEtichetta($this->docente, $a['id'], 'ZMA', 'ripresa'));
        $this->assertTrue($this->repo->setExpiry($this->docente, $a['id'], $fra30));
        $this->assertTrue($this->repo->setExpiry($this->docente, $b['id'], $fra30));
    }

    #[Test]
    public function l_anteprima_dice_l_etichetta_e_se_e_gia_usata(): void
    {
        $dati = ['institute_id' => $this->scuola, 'indirizzo' => self::IND, 'classe' => '3', 'materie' => 'ZGE,ZMA'];
        $prima = $this->repo->anteprimaEtichetta($this->docente, $dati);
        $this->assertSame(['label' => '3_ZET_ZGE-ZMA', 'gia_usata' => false], $prima);
        $c = $this->crea($dati);
        $this->assertSame(['label' => '3_ZET_ZGE-ZMA', 'gia_usata' => true], $this->repo->anteprimaEtichetta($this->docente, $dati));
        // Per la credenziale stessa, la sua etichetta non è «già usata».
        $this->assertSame(['label' => '3_ZET_ZGE-ZMA', 'gia_usata' => false], $this->repo->anteprimaEtichetta($this->docente, ['id' => $c['id'], 'materie' => 'ZMA,ZGE']));
        $this->assertSame('aggiunta_non_valida', $this->codiceDi(fn() => $this->repo->anteprimaEtichetta($this->docente, ['aggiunta' => 'a b'] + $dati)));
        $this->assertSame('not_found', $this->codiceDi(fn() => $this->repo->anteprimaEtichetta($this->collega, ['id' => $c['id']])));
    }
}
