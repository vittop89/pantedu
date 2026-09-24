<?php

declare(strict_types=1);

namespace Tests\Integration\Gdpr;

use App\Controllers\ParentConsentController;
use App\Core\Database;
use App\Core\Request;
use App\Services\Gdpr\DeletionRequestService;
use App\Services\Gdpr\ParentConsentService;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La migrazione 139 ritira i gettoni rimasti in chiaro (23/9/2026, A-67).
 *
 * Prima del 23/9 `confirm_token` teneva il gettone in chiaro, e quelle righe
 * restano nei database esistenti. Ogni gettone scritto cosi' e' anche in
 * ogni backup fatto finora: trasformarlo in hash lo lascerebbe valido per chi
 * lo legge da li'. La migrazione fa scadere le richieste in attesa e mette al
 * posto di ogni gettone vecchio un segnaposto che non e' un gettone.
 *
 * Nei due versi: le richieste in attesa scadono, quelle gia' chiuse tengono
 * il loro stato; il vecchio gettone non vale piu'; rilanciata, la migrazione
 * non scrive niente. Tutto in transazione → rollback.
 */
final class GettoniComeHashMigrazioneTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!Database::isAvailable()) {
            self::markTestSkipped('database non disponibile');
        }
        $this->pdo = Database::connection();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return list<string> le istruzioni della migrazione, senza commenti */
    private function istruzioni(): array
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/139_gettoni_gdpr_come_hash.sql');
        $sql = (string)preg_replace('/^--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', explode(';', $sql))));
    }

    private function migrazione(): void
    {
        foreach ($this->istruzioni() as $istruzione) {
            $this->pdo->exec($istruzione);
        }
    }

    private function utente(string $ruolo, int $attivo, ?string $nascita = null): int
    {
        $nome = 'zzg139' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, birth_date, created_at)
             VALUES (?, ?, "Zz", "Migrazione", ?, "x", "approved", ?, ?, NOW())'
        )->execute([$nome, $ruolo, "$nome@example.invalid", $attivo, $nascita]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Una richiesta di cancellazione come la scriveva il codice di prima. */
    private function cancellazioneVecchia(string $stato, string $gettone): int
    {
        $this->pdo->prepare(
            'INSERT INTO deletion_requests (user_id, confirm_token, status, requested_at, expires_at)
             VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))'
        )->execute([$this->utente('teacher', 1), $gettone, $stato]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array{0:int,1:int} id della richiesta e dello studente */
    private function consensoVecchio(string $stato, string $gettone): array
    {
        $studente = $this->utente('student', 0, date('Y-m-d', strtotime('-12 years')));
        $this->pdo->prepare(
            'INSERT INTO parent_consents
                (student_user_id, parent_email, confirm_token, status, requested_at, expires_at)
             VALUES (?, "genitore.139@example.invalid", ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))'
        )->execute([$studente, $gettone, $stato]);
        return [(int)$this->pdo->lastInsertId(), $studente];
    }

    /** @return array{status:string, confirm_token:string} */
    private function riga(string $tabella, int $id): array
    {
        $st = $this->pdo->prepare("SELECT status, confirm_token FROM $tabella WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    private function scadenzeRegistrate(int $studente): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM consent_audit
              WHERE user_id = ? AND consent_type = "parent_consent" AND event = "expired"'
        );
        $st->execute([$studente]);
        return (int)$st->fetchColumn();
    }

    #[Test]
    public function le_richieste_in_attesa_scadono_e_i_gettoni_si_ritirano(): void
    {
        $gCanc = bin2hex(random_bytes(32));
        $gCons = bin2hex(random_bytes(32));
        $cancellazione = $this->cancellazioneVecchia('pending_confirm', $gCanc);
        [$consenso, $studente] = $this->consensoVecchio('pending', $gCons);

        $this->migrazione();

        $this->assertSame(
            ['status' => 'expired', 'confirm_token' => "ritirato-$cancellazione"],
            $this->riga('deletion_requests', $cancellazione),
            'la cancellazione in attesa scade e perde il gettone'
        );
        $this->assertSame(
            ['status' => 'expired', 'confirm_token' => "ritirato-$consenso"],
            $this->riga('parent_consents', $consenso),
            'il consenso in attesa scade e perde il gettone'
        );
        $this->assertSame(
            1,
            $this->scadenzeRegistrate($studente),
            'e la scadenza del consenso finisce nel registro del DPO'
        );

        // Il gettone che era nell'email, e nel database, non vale piu'.
        $this->assertFalse(
            (new DeletionRequestService())->confirm($gCanc),
            'il vecchio gettone non conferma la cancellazione'
        );
        $this->assertFalse((new ParentConsentService())->confirm($gCons)['ok'], 'ne\' il consenso');
        $pagina = (new ParentConsentController())->preview(new Request(), ['token' => $gCons]);
        $this->assertSame(404, $pagina->status, 'e non apre la pagina del genitore');
    }

    #[Test]
    public function le_richieste_gia_chiuse_tengono_lo_stato_e_perdono_il_gettone(): void
    {
        $gCanc = bin2hex(random_bytes(32));
        $gCons = bin2hex(random_bytes(32));
        $cancellazione = $this->cancellazioneVecchia('cancelled', $gCanc);
        [$consenso, $studente] = $this->consensoVecchio('confirmed', $gCons);

        $this->migrazione();

        $this->assertSame(
            ['status' => 'cancelled', 'confirm_token' => "ritirato-$cancellazione"],
            $this->riga('deletion_requests', $cancellazione)
        );
        $this->assertSame(
            ['status' => 'confirmed', 'confirm_token' => "ritirato-$consenso"],
            $this->riga('parent_consents', $consenso),
            'un consenso gia\' dato resta dato'
        );
        $this->assertSame(
            0,
            $this->scadenzeRegistrate($studente),
            'e non si registra una scadenza che non c\'e\' stata'
        );

        foreach (['deletion_requests' => $gCanc, 'parent_consents' => $gCons] as $tabella => $gettone) {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM $tabella WHERE confirm_token LIKE ?");
            $st->execute(['%' . $gettone . '%']);
            $this->assertSame(0, (int)$st->fetchColumn(), "in $tabella il vecchio gettone non c'e' piu'");
        }
    }

    #[Test]
    public function rilanciata_non_scrive_niente(): void
    {
        $cancellazione = $this->cancellazioneVecchia('pending_confirm', bin2hex(random_bytes(32)));
        [$consenso, $studente] = $this->consensoVecchio('pending', bin2hex(random_bytes(32)));

        $this->migrazione();
        $prima = [$this->riga('deletion_requests', $cancellazione), $this->riga('parent_consents', $consenso)];
        $this->migrazione();

        $dopo = [$this->riga('deletion_requests', $cancellazione), $this->riga('parent_consents', $consenso)];
        $this->assertSame($prima, $dopo);
        $this->assertSame(1, $this->scadenzeRegistrate($studente), 'un solo evento anche rilanciandola');
    }

    /**
     * Il caso che il rilancio semplice non vede: la migrazione si ferma dopo
     * aver scritto l'evento e prima di far scadere la richiesta. Al giro dopo
     * la richiesta e' ancora in attesa, e senza il NOT EXISTS l'evento si
     * scriverebbe una seconda volta.
     */
    #[Test]
    public function interrotta_dopo_il_registro_non_scrive_l_evento_due_volte(): void
    {
        [, $studente] = $this->consensoVecchio('pending', bin2hex(random_bytes(32)));

        $fatte = 0;
        foreach ($this->istruzioni() as $istruzione) {
            $this->pdo->exec($istruzione);
            $fatte++;
            if (stripos($istruzione, 'INSERT INTO consent_audit') === 0) {
                break;
            }
        }
        $this->assertLessThan(\count($this->istruzioni()), $fatte, 'la prova si ferma davvero a meta\'');
        $this->assertSame(1, $this->scadenzeRegistrate($studente), 'l\'evento e\' scritto');

        $this->migrazione();

        $this->assertSame(1, $this->scadenzeRegistrate($studente), 'e rilanciata non lo riscrive');
    }
}
