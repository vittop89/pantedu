<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Admin\AdminSectionsController;
use App\Core\Database;
use App\Core\Request;
use App\Services\AvvisoIncarichiTolti;
use App\Services\Contenuti\MaterialiSuSezioniNonAmmesse;
use App\Services\Mailer;
use App\Support\Anomalia;
use App\Support\IndirizzoPubblico;
use App\Support\PostoPrincipale;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Togliere incarichi a un docente in /admin/sections (15/9/2026, richiesta
 * dell'utente): l'anteprima di che cosa ha su quelle sezioni, per tipo, e
 * l'email al docente quando gli si toglie qualcosa.
 *
 * Nei due versi: si contano i suoi materiali sulla sezione e non quelli di una
 * sezione di un altro indirizzo, né la sezione chiesta con l'indirizzo sbagliato; l'email parte quando si toglie e non
 * quando si assegna soltanto; senza indirizzo valido non parte, e il messaggio
 * all'amministratore lo dice. Posta finta: il trasporto raccoglie i messaggi.
 * Tutto in transazione → rollback.
 *
 * Istituto «solo incaricati». Il docente ha l'incarico su 2A e 3A dello
 * scientifico. Sulla 2A: una mappa (principale), un esercizio con la principale
 * sull'anno e un posto in più in 2A, una verifica generata, una credenziale
 * attiva e una spenta. Sulla 2AA dell'artistico, un esercizio che non deve contare.
 * (Nel catalogo una sigla è unica per istituto: uq_curriculum_voce.)
 */
final class AvvisoIncarichiToltiTest extends TestCase
{
    private PDO $pdo;
    private int $ist = 0;
    private int $prof = 0;
    /** @var array<string,int> */
    private array $voce = [];
    /** @var list<array{to:string,subject:string,body:string}> */
    private array $inviate = [];
    /** @var array<string, mixed> */
    private array $getPrima = [];

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
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
        $this->pdo->beginTransaction();
        $this->getPrima = $_GET;
        $_POST = [];

        $this->pdo->prepare("INSERT INTO institutes (code, name, city, active, sezioni_docenti) VALUES ('ZZAVVINC1', 'ISTITUTO DEGLI AVVISI', 'Comune Esempio', 1, 'solo_incaricati')")->execute();
        $this->ist = (int)$this->pdo->lastInsertId();
        $ins = $this->pdo->prepare("INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine) VALUES (?, ?, ?, ?, ?, 1, 'istituto')");
        foreach ([
            ['indirizzi', 'SCI', 'Scientifico', null, 'SCI'],
            ['indirizzi', 'ART', 'Artistico', null, 'ART'],
            ['materie', 'MAT', 'Matematica', null, 'MAT'],
            ['classi', '2', 'Seconda', 'SCI', '2'],
            ['classi', '2A', '2A', 'SCI', '2A'],
            ['classi', '3A', '3A', 'SCI', '3A'],
            ['classi', '2AA', '2AA', 'ART', '2AA'],
        ] as [$kind, $code, $label, $ind, $chiave]) {
            $ins->execute([$kind, $this->ist, $code, $label, $ind]);
            $this->voce[$chiave] = (int)$this->pdo->lastInsertId();
        }

        $this->prof = $this->docente('zz_avvinc_prof', 'zz_avvinc_prof@example.test');
        $inc = $this->pdo->prepare("INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, 'SCI', ?)");
        foreach (['2A', '3A'] as $c) {
            $inc->execute([$this->prof, $this->ist, $c]);
        }
        $sp = $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active, sospesa_dalla_scuola) VALUES (?, ?, 1, 0)');
        foreach (['SCI', 'MAT', '2', '2A', '3A'] as $k) {
            $sp->execute([$this->voce[$k], $this->prof]);
        }

        $this->contenuto('mappa', '2A', 'SCI');
        $es = $this->contenuto('esercizio', '2', null);
        $this->pdo->prepare(
            "INSERT INTO content_publications (teacher_content_id, institute_id, indirizzo_id, classe_id, subject_id, is_primary, origine, visibility, archive_visible)
             VALUES (?, ?, ?, ?, ?, 0, 'docente', 'published', 0)"
        )->execute([$es, $this->ist, $this->voce['SCI'], $this->voce['2A'], $this->voce['MAT']]);
        $this->pdo->prepare('INSERT INTO verifica_documents_data (teacher_id, title) VALUES (?, "Verifica della 2A")')->execute([$this->prof]);
        PostoPrincipale::verifica($this->pdo, (int)$this->pdo->lastInsertId(), $this->voce['SCI'], $this->voce['2A'], $this->voce['MAT']);
        $this->contenuto('esercizio', '2AA', 'ART');

        $cred = $this->pdo->prepare(
            'INSERT INTO teacher_access_credentials_data (teacher_id, label, access_username, password_hash, indirizzo_id, classe_id, institute_id, active)
             VALUES (?, ?, ?, "x", ?, ?, ?, ?)'
        );
        $cred->execute([$this->prof, '2A attiva', 'zzavv' . bin2hex(random_bytes(3)), $this->voce['SCI'], $this->voce['2A'], $this->ist, 1]);
        $cred->execute([$this->prof, '2A spenta', 'zzavv' . bin2hex(random_bytes(3)), $this->voce['SCI'], $this->voce['2A'], $this->ist, 0]);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = $this->getPrima;
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function docente(string $username, string $email): int
    {
        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES (?, 'teacher', 'Zz', 'Avvisato', ?, 'x', 'approved', 1)"
        )->execute([$username, $email]);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $this->ist]);
        return $id;
    }

    private function contenuto(string $tipo, string $classe, ?string $indirizzo): int
    {
        $this->pdo->prepare('INSERT INTO teacher_content_data (teacher_id, content_subtype, title) VALUES (?, ?, ?)')
            ->execute([$this->prof, $tipo, "Zz $tipo $classe"]);
        $id = (int)$this->pdo->lastInsertId();
        PostoPrincipale::contenuto($this->pdo, $id, $indirizzo !== null ? $this->voce[$indirizzo] : null, $this->voce[$classe], $this->voce['MAT']);
        return $id;
    }

    /** Il controller con la posta finta: i messaggi finiscono in $this->inviate. */
    private function controller(bool $conPosta = true): AdminSectionsController
    {
        $posta = $conPosta
            ? fn(): Mailer => new Mailer('noreply@example.test', 'Pantedu', function (string $to, string $subject, string $body): bool {
                $this->inviate[] = ['to' => $to, 'subject' => (string)mb_decode_mimeheader($subject), 'body' => $body];
                return true;
            })
            : static fn(): ?Mailer => null;
        return new AdminSectionsController(null, null, null, new AvvisoIncarichiTolti($this->pdo, $posta));
    }

    /** @return array<string, string> la query del ritorno */
    private function ritorno(\App\Core\Response $risposta): array
    {
        parse_str((string)parse_url((string)($risposta->headers['Location'] ?? ''), PHP_URL_QUERY), $q);
        return array_map('strval', $q);
    }

    #[Test]
    public function si_contano_i_materiali_della_sezione_per_tipo_e_non_quelli_di_un_altro_indirizzo(): void
    {
        $righe = (new MaterialiSuSezioniNonAmmesse($this->pdo))->sulleSezioni($this->prof, $this->ist, 'SCI', ['2a', '3A']);

        self::assertSame(['2A', '3A'], array_column($righe, 'code'));
        self::assertEquals(['esercizio' => 1, 'mappa' => 1], $righe[0]['tipi'], 'la mappa e l\'esercizio con il posto in più, non quello dell\'artistico');
        self::assertSame(1, $righe[0]['verifiche']);
        self::assertSame(1, $righe[0]['posti']);
        self::assertSame(1, $righe[0]['credenziali'], 'la credenziale spenta non conta');
        self::assertSame([], $righe[1]['tipi'], 'sulla 3A niente');
        self::assertSame('1 mappa · 1 esercizio · 1 verifica generata (1 posto in più)', AvvisoIncarichiTolti::descrivi($righe[0]));
        self::assertSame('nessun materiale', AvvisoIncarichiTolti::descrivi($righe[1]));

        // Controprova: la 2AA dell'artistico ha il suo esercizio, e solo quello;
        // la 2A chiesta come artistico non è una sezione dell'artistico, e non conta niente.
        $art = (new MaterialiSuSezioniNonAmmesse($this->pdo))->sulleSezioni($this->prof, $this->ist, 'ART', ['2AA', '2A']);
        self::assertSame(['esercizio' => 1], $art[0]['tipi']);
        self::assertSame([], $art[1]['tipi']);
        self::assertSame(0, $art[1]['verifiche']);
        self::assertSame(0, $art[1]['credenziali'], 'la credenziale è dello scientifico');
    }

    #[Test]
    public function l_anteprima_dice_il_nome_del_docente_e_che_cosa_c_e(): void
    {
        $_GET = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->prof, 'indirizzo' => 'SCI', 'classi' => '2A,3A'];
        $r = $this->controller()->anteprimaRevoca(new Request());
        $dati = json_decode($r->body, true);

        self::assertSame(200, $r->status);
        self::assertSame('Zz Avvisato', $dati['docente']);
        self::assertSame('solo_incaricati', $dati['modalita']);
        self::assertSame('si', $dati['email']);
        self::assertSame(3, $dati['sezioni'][0]['materiali']);
        self::assertSame(0, $dati['sezioni'][1]['materiali']);

        $_GET = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->prof, 'classi' => 'DROP TABLE'];
        self::assertSame(400, $this->controller()->anteprimaRevoca(new Request())->status, 'sigle non valide: niente conteggi');
    }

    #[Test]
    public function togliere_un_incarico_manda_l_email_al_docente(): void
    {
        $_POST = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->prof, 'indirizzo' => 'SCI', 'classe' => ['3A'], 'stato' => '2A,3A'];

        $q = $this->ritorno($this->controller()->assign(new Request()));

        self::assertStringContainsString('tolte 2A', $q['ok'] ?? '', $q['error'] ?? '');
        self::assertStringContainsString('Email inviata a Zz Avvisato', $q['ok']);
        self::assertCount(1, $this->inviate);
        self::assertSame('zz_avvinc_prof@example.test', $this->inviate[0]['to']);
        self::assertStringContainsString('2A', $this->inviate[0]['subject']);
        $testo = $this->inviate[0]['body'];
        self::assertStringContainsString('Ciao Zz', $testo);
        self::assertStringContainsString('- 2A: 1 mappa · 1 esercizio · 1 verifica generata (1 posto in più)', $testo);
        self::assertStringContainsString('/area-docente/sposta-di-classe', $testo);
        self::assertStringContainsString('La tua credenziale di classe', $testo);
        self::assertStringContainsString('non compare più', $testo, 'con «solo incaricati» la sezione sparisce dai menù');
    }

    #[Test]
    public function assegnare_soltanto_non_manda_niente(): void
    {
        $_POST = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->prof, 'indirizzo' => 'SCI', 'classe' => ['2A', '3A'], 'stato' => '2A'];

        $q = $this->ritorno($this->controller()->assign(new Request()));

        self::assertStringContainsString('assegnate', $q['ok'] ?? '', $q['error'] ?? '');
        self::assertStringNotContainsString('mail', $q['ok']);
        self::assertSame([], $this->inviate);
    }

    #[Test]
    public function senza_indirizzo_valido_o_senza_posta_l_email_non_parte_e_si_dice(): void
    {
        $this->pdo->prepare('UPDATE users SET email = "non-e-un-indirizzo" WHERE id = ?')->execute([$this->prof]);
        $_POST = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->prof, 'indirizzo' => 'SCI', 'classe' => ['3A'], 'stato' => '2A,3A'];
        $q = $this->ritorno($this->controller()->assign(new Request()));
        self::assertStringContainsString('non ha un indirizzo valido', $q['ok'] ?? '');
        self::assertSame([], $this->inviate);

        $this->pdo->prepare('UPDATE users SET email = "zz_avvinc_prof@example.test" WHERE id = ?')->execute([$this->prof]);
        $this->contenuto('mappa', '3A', 'SCI');
        $_POST = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->prof, 'indirizzo' => 'SCI', 'classe' => [], 'stato' => '3A'];
        $q = $this->ritorno($this->controller(false)->assign(new Request()));
        self::assertStringContainsString('non manda posta', $q['ok'] ?? '', $q['error'] ?? '');
    }

    /**
     * 15/9/2026, scelta dell'utente: togliendo una classe su cui il docente non ha
     * materiali né credenziali non gli si scrive, e la finestra lo dice prima.
     * Nell'altro verso, con un materiale sulla stessa classe l'email parte.
     */
    #[Test]
    public function senza_materiali_ne_credenziali_non_si_scrive_e_con_un_materiale_si(): void
    {
        $avviso = new AvvisoIncarichiTolti($this->pdo, fn() => null);
        self::assertSame(AvvisoIncarichiTolti::NIENTE_DA_FARE, $avviso->anteprima($this->prof, $this->ist, 'SCI', ['3A'])['email']);

        $_POST = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->prof, 'indirizzo' => 'SCI', 'classe' => ['2A'], 'stato' => '2A,3A'];
        $q = $this->ritorno($this->controller()->assign(new Request()));
        self::assertStringContainsString('non aveva materiali né credenziali', $q['ok'] ?? '', $q['error'] ?? '');
        self::assertSame([], $this->inviate, 'nessuna email');

        $this->pdo->prepare('INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, "SCI", "3A")')->execute([$this->prof, $this->ist]);
        $this->contenuto('mappa', '3A', 'SCI');
        $_POST = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->prof, 'indirizzo' => 'SCI', 'classe' => ['2A'], 'stato' => '2A,3A'];
        $q = $this->ritorno($this->controller()->assign(new Request()));
        self::assertStringContainsString('Email inviata', $q['ok'] ?? '', $q['error'] ?? '');
        self::assertCount(1, $this->inviate);
    }

    /**
     * 15/9/2026, segnalato dall'utente: rispondendo all'email, la risposta andava
     * a dpo@. L'email invita a rispondere se il docente pensa a un errore, e
     * decidere è dell'amministratore che ha tolto l'incarico: la risposta va a lui.
     */
    #[Test]
    public function la_risposta_del_docente_arriva_all_amministratore_che_ha_tolto_l_incarico(): void
    {
        $intestazioni = [];
        // Il Mailer fuori dalla freccia: una fn cattura per valore, e il riferimento
        // a $intestazioni andrebbe perso.
        $mailer = new Mailer('noreply@example.test', 'Pantedu', static function (string $to, string $subject, string $body, string $headers) use (&$intestazioni): bool {
            $intestazioni[] = $headers;
            return true;
        });
        $posta = static fn(): Mailer => $mailer;
        $avviso = new AvvisoIncarichiTolti($this->pdo, $posta);
        $rispostaA = static function (string $h): string {
            return preg_match('/^Reply-To: (.+)$/mi', $h, $m) === 1 ? trim($m[1]) : '';
        };
        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES ('zz_avvinc_admin', 'administrator', 'Zz', 'Amministratore', 'zz_avvinc_admin@example.test', 'x', 'approved', 1)"
        )->execute();
        $admin = (int)$this->pdo->lastInsertId();
        $primaReplyTo = \App\Core\Config::get('mail.reply_to', '');
        $primoDpo = \App\Core\Config::get('mail.dpo_email', '');
        try {
            \App\Core\Config::set('mail.reply_to', '');

            self::assertSame(AvvisoIncarichiTolti::INVIATA, $avviso->invia($this->prof, $this->ist, 'SCI', ['2A'], $admin));
            self::assertSame('zz_avvinc_admin@example.test', $rispostaA($intestazioni[0]), "all'amministratore che l'ha tolto");

            // Controprove: senza chi l'ha tolto, o con un suo indirizzo non valido,
            // la casella delle risposte della piattaforma; senza nemmeno quella, i diritti.
            \App\Core\Config::set('mail.reply_to', 'risposte@example.test');
            $avviso->invia($this->prof, $this->ist, 'SCI', ['2A'], null);
            self::assertSame('risposte@example.test', $rispostaA($intestazioni[1]));
            $this->pdo->prepare('UPDATE users SET email = "non-valido" WHERE id = ?')->execute([$admin]);
            $avviso->invia($this->prof, $this->ist, 'SCI', ['2A'], $admin);
            self::assertSame('risposte@example.test', $rispostaA($intestazioni[2]));
            \App\Core\Config::set('mail.reply_to', '');
            \App\Core\Config::set('mail.dpo_email', 'dpo@scuola.example');
            $avviso->invia($this->prof, $this->ist, 'SCI', ['2A'], null);
            self::assertSame('dpo@scuola.example', $rispostaA($intestazioni[3]));

            // 23/9/2026 (A-13): senza nemmeno la casella dei diritti, il
            // Reply-To resta il mittente. Il ripiego era la casella del DPO
            // di produzione, anche per un'altra istanza.
            \App\Core\Config::set('mail.dpo_email', '');
            $avviso->invia($this->prof, $this->ist, 'SCI', ['2A'], null);
            self::assertSame('noreply@example.test', $rispostaA($intestazioni[4]));
            foreach ($intestazioni as $h) {
                self::assertStringNotContainsStringIgnoringCase('pantedu.eu', $h);
            }
        } finally {
            \App\Core\Config::set('mail.reply_to', $primaReplyTo);
            \App\Core\Config::set('mail.dpo_email', $primoDpo);
        }
    }

    /**
     * 23/9/2026 (A-13): il collegamento dell'avviso è quello dell'istanza
     * (`app.url`), e senza `app.url` l'avviso non parte — il collegamento è
     * l'azione che il docente deve fare. Fino a quel giorno il ripiego era il
     * dominio di produzione. L'anomalia resta nel registro, che qui è una
     * cartella della prova.
     */
    #[Test]
    public function l_avviso_porta_al_sito_dell_istanza_e_senza_app_url_non_parte(): void
    {
        $avviso = new AvvisoIncarichiTolti($this->pdo, fn(): Mailer => new Mailer('noreply@example.test', 'Pantedu', function (string $to, string $subject, string $body): bool {
            $this->inviate[] = ['to' => $to, 'subject' => (string)mb_decode_mimeheader($subject), 'body' => $body];
            return true;
        }));
        $cartella = sys_get_temp_dir() . '/pantedu_avvinc_' . bin2hex(random_bytes(6));
        mkdir($cartella, 0700, true);
        $primaUrl = \App\Core\Config::get('app.url');
        $primiLog = \App\Core\Config::get('app.paths.logs');
        $errorLogPrima = ini_set('error_log', $cartella . '/errori.log');
        try {
            \App\Core\Config::set('app.paths.logs', $cartella);

            \App\Core\Config::set('app.url', 'https://scuola.example/');
            self::assertSame(AvvisoIncarichiTolti::INVIATA, $avviso->invia($this->prof, $this->ist, 'SCI', ['2A']));
            self::assertStringContainsString("https://scuola.example/area-docente/sposta-di-classe\n", $this->inviate[0]['body']);
            self::assertStringNotContainsStringIgnoringCase('pantedu.eu', $this->inviate[0]['body']);

            \App\Core\Config::set('app.url', '');
            self::assertSame(AvvisoIncarichiTolti::NON_PARTITA, $avviso->invia($this->prof, $this->ist, 'SCI', ['2A']));
            self::assertCount(1, $this->inviate, 'senza app.url l\'avviso non parte, e non porta altrove');
            $flussi = [];
            foreach (Anomalia::recenti() as $riga) {
                if (($riga['codice'] ?? '') === IndirizzoPubblico::ANOMALIA) {
                    $flussi[] = (string)($riga['dettagli']['flusso'] ?? '');
                }
            }
            self::assertSame(['avviso_incarichi'], $flussi);
        } finally {
            \App\Core\Config::set('app.url', $primaUrl);
            \App\Core\Config::set('app.paths.logs', $primiLog);
            ini_set('error_log', $errorLogPrima === false ? '' : $errorLogPrima);
            foreach (glob($cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($cartella);
        }
    }

    #[Test]
    public function la_revoca_dalla_tabella_dice_a_chi_e_manda_l_email(): void
    {
        $st = $this->pdo->prepare("SELECT id FROM teacher_sections WHERE user_id = ? AND classe = '2A'");
        $st->execute([$this->prof]);
        $_POST = ['institute_id' => (string)$this->ist, 'assignment_id' => (string)$st->fetchColumn()];

        $q = $this->ritorno($this->controller()->revoke(new Request()));

        self::assertSame('Incarico di Zz Avvisato su 2A revocato. Email inviata a Zz Avvisato.', $q['ok'] ?? '');
        self::assertCount(1, $this->inviate);
        self::assertStringContainsString('- 2A: ', $this->inviate[0]['body']);

        $_POST = ['institute_id' => (string)$this->ist, 'assignment_id' => '999999999'];
        $q = $this->ritorno($this->controller()->revoke(new Request()));
        self::assertSame('Incarico inesistente.', $q['error'] ?? '');
        self::assertCount(1, $this->inviate, 'nessuna email per un incarico che non c\'era');
    }
}
