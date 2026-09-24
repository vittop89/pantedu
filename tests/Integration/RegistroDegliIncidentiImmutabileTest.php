<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * «Append-only» deve essere un vincolo, non una parola nel commento.
 *
 * ── Il difetto, misurato il 22/9/2026 ─────────────────────────────────────
 *
 * La migrazione 060 apre `data_breach_incidents` con «Append-only register
 * dei data breach. Conservazione permanente (audit + accountability Art. 5
 * §2)». Nessun trigger lo imponeva: la migrazione 100 ne mette su altre
 * quattro tabelle, non su questa, e `DataBreachRepository` fa `UPDATE`. Una
 * garanzia dichiarata al Garante e non applicata.
 *
 * ── Perché non si vieta ogni UPDATE ───────────────────────────────────────
 *
 * Questa tabella ha un flusso di lavoro: detected → assessing →
 * notified_garante → notified_users → closed, e le date di notifica si
 * scrivono per forza dopo l'inserimento. Vietare ogni modifica romperebbe il
 * registro invece di proteggerlo — sarebbe il controllo troppo largo, che in
 * questo progetto vale quanto uno assente.
 *
 * Si proteggono i fatti: la data del rilevamento (da lì decorrono le 72 ore
 * dell'art. 33, ed è l'unica che spostata falsifica il rispetto del termine),
 * quella dell'accaduto, e le notifiche già fatte — che non si disdicono.
 *
 * ── Le due direzioni ──────────────────────────────────────────────────────
 *
 * Un trigger provato solo nel verso «respinge» potrebbe respingere tutto, e
 * il registro diventerebbe inutilizzabile senza che nessuno se ne accorga
 * finché non serve. Quindi ogni divieto ha accanto la sua controprova: il
 * flusso normale deve passare.
 *
 * ── Perché con la transazione e non con la cancellazione ──────────────────
 *
 * Le prove di questo progetto non lasciano dati nel database. Qui però la
 * riga di prova **non si può cancellare**: è precisamente ciò che il trigger
 * impedisce. Si lavora quindi dentro una transazione annullata alla fine —
 * niente DDL, che in MariaDB farebbe una commit implicita e vanificherebbe
 * l'annullamento.
 */
final class RegistroDegliIncidentiImmutabileTest extends TestCase
{
    private PDO $pdo;
    private int $id = 0;

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }

        $trigger = $this->pdo->query("SHOW TRIGGERS LIKE 'data_breach_incidents'");
        $nomi = $trigger === false ? [] : $trigger->fetchAll(PDO::FETCH_COLUMN);
        if (!\in_array('trg_incidenti_fatti_immutabili', $nomi, true)) {
            $this->markTestSkipped('migrazione 136 non applicata su questo database');
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->beginTransaction();
        $this->pdo->prepare(
            'INSERT INTO data_breach_incidents (occurred_at, detected_at, severity, description, status)
             VALUES (NOW(), NOW(), "low", ?, "detected")'
        )->execute(['prova automatica ' . bin2hex(random_bytes(6))]);
        $this->id = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return string '' se l'istruzione è passata, il messaggio se è stata respinta */
    private function prova(string $sql): string
    {
        try {
            $this->pdo->exec(str_replace('{id}', (string)$this->id, $sql));
            return '';
        } catch (PDOException $e) {
            return $e->getMessage();
        }
    }

    // ── I fatti non si correggono ────────────────────────────────────────

    #[Test]
    public function la_data_del_rilevamento_non_si_sposta(): void
    {
        $esito = $this->prova('UPDATE data_breach_incidents SET detected_at = "2020-01-01 00:00:00" WHERE id = {id}');

        self::assertStringContainsString(
            'detected_at non si corregge',
            $esito,
            'da quella data decorrono le 72 ore: spostarla falsifica il rispetto del termine'
        );
    }

    #[Test]
    public function la_data_dell_accaduto_non_si_sposta(): void
    {
        $esito = $this->prova('UPDATE data_breach_incidents SET occurred_at = "2020-01-01 00:00:00" WHERE id = {id}');
        self::assertStringContainsString('occurred_at non si corregge', $esito);
    }

    #[Test]
    public function una_riga_non_si_cancella(): void
    {
        $esito = $this->prova('DELETE FROM data_breach_incidents WHERE id = {id}');

        self::assertStringContainsString('non si cancella', $esito);
        self::assertSame(
            1,
            (int)$this->pdo->query('SELECT COUNT(*) FROM data_breach_incidents WHERE id = ' . $this->id)->fetchColumn(),
            'e la riga deve essere ancora lì'
        );
    }

    // ── Una notifica fatta non si disdice ────────────────────────────────

    #[Test]
    public function la_notifica_al_garante_si_scrive_una_volta_sola(): void
    {
        self::assertSame(
            '',
            $this->prova('UPDATE data_breach_incidents SET notified_garante_at = NOW() WHERE id = {id}'),
            'scriverla deve riuscire: è il flusso normale'
        );

        self::assertStringContainsString(
            'non si disdice',
            $this->prova('UPDATE data_breach_incidents SET notified_garante_at = NULL WHERE id = {id}'),
            'ma azzerarla no'
        );
        self::assertStringContainsString(
            'non si disdice',
            $this->prova('UPDATE data_breach_incidents SET notified_garante_at = "2020-01-01 00:00:00" WHERE id = {id}'),
            'né spostarla'
        );
    }

    #[Test]
    public function l_avviso_al_titolare_esterno_si_scrive_una_volta_sola(): void
    {
        self::assertSame(
            '',
            $this->prova(
                'UPDATE data_breach_incidents SET notified_controller_at = NOW(),
                 controller_notification_method = "pec" WHERE id = {id}'
            ),
            'avvisare la scuola deve riuscire'
        );
        self::assertStringContainsString(
            'non si disdice',
            $this->prova('UPDATE data_breach_incidents SET notified_controller_at = NULL WHERE id = {id}')
        );
    }

    // ── La direzione opposta: il flusso di lavoro deve funzionare ────────

    #[Test]
    public function il_flusso_di_lavoro_resta_possibile(): void
    {
        $passi = [
            'avanzare lo stato'        => 'UPDATE data_breach_incidents SET status = "assessing" WHERE id = {id}',
            'scrivere la causa'        => 'UPDATE data_breach_incidents SET root_cause = "prova" WHERE id = {id}',
            'scrivere le mitigazioni'  => 'UPDATE data_breach_incidents SET remedial_actions = "prova" WHERE id = {id}',
            'correggere la stima'      => 'UPDATE data_breach_incidents SET affected_users_count = 3 WHERE id = {id}',
            'indicare l istituto'      => 'UPDATE data_breach_incidents SET titolare_esterno = "Istituto di prova" WHERE id = {id}',
            'chiudere l incidente'     => 'UPDATE data_breach_incidents SET status = "closed" WHERE id = {id}',
        ];

        foreach ($passi as $cosa => $sql) {
            self::assertSame('', $this->prova($sql), "il registro deve restare utilizzabile: {$cosa}");
        }
    }

    // ── Le colonne che dicono di chi è il dato ───────────────────────────

    #[Test]
    public function il_registro_sa_dire_di_chi_e_il_dato(): void
    {
        $colonne = $this->pdo
            ->query("SHOW COLUMNS FROM data_breach_incidents")
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach (['institute_id', 'titolare_esterno', 'notified_controller_at', 'controller_notification_method'] as $c) {
            self::assertContains(
                $c,
                $colonne,
                "senza «{$c}» una fuga di dati di cui è titolare una scuola non è registrabile (R20)"
            );
        }
    }
}
