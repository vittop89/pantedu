<?php

declare(strict_types=1);

namespace Tests\Integration\Gdpr;

use App\Controllers\SelfServiceController;
use App\Controllers\TrustPagesController;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Gdpr\ConsentService;
use App\Services\Gdpr\DeletionRequestService;
use App\Services\Gdpr\RichiestaDiCancellazione;
use App\Services\Mailer;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La cancellazione dell'account manda davvero l'email di conferma (24/9/2026).
 *
 * ── Il difetto ────────────────────────────────────────────────────────────
 *
 * POST /me/request-deletion creava la richiesta e rispondeva «Email di
 * conferma inviata. Controlla la casella…», ma nessuna email partiva: in
 * produzione il gettone non lo riceveva nessuno. La pagina «I tuoi dati»
 * aveva un collegamento (GET) a una rotta che accetta solo POST, e il
 * collegamento di conferma, aperto, confermava subito e rispondeva JSON.
 *
 * ── Le due direzioni ──────────────────────────────────────────────────────
 *
 *   - con un mittente finto che registra, la richiesta produce UNA email
 *     all'indirizzo dell'account, e il suo collegamento porta alla pagina il
 *     cui pulsante conferma davvero (`cooling_off`);
 *   - con il mittente che rifiuta, la risposta non dice «inviata», dice a chi
 *     scrivere, e il gettone dell'email tentata non conferma niente;
 *   - aprire il collegamento (GET, anche in JSON o HEAD) non conferma;
 *   - un account senza indirizzo non tocca la richiesta già confermata;
 *   - «I tuoi dati» ha il modulo POST con il gettone CSRF, non il
 *     collegamento, e con una richiesta in corso il modulo per annullarla.
 *
 * Dopo la revisione del 24/9/2026, tre difetti misurati e le loro prove:
 *
 *   - una cancellazione già confermata (`cooling_off`) spariva se l'email di
 *     una richiesta nuova non partiva: la richiesta nuova ritirava prima le
 *     aperte, poi ritirava sé stessa. Adesso non se ne crea un'altra, e la
 *     risposta dice quando sarà eseguita e come annullarla; col mittente che
 *     rifiuta, la confermata resta identica, riga per riga;
 *   - una richiesta in attesa di conferma si ritira solo dopo che l'email
 *     della nuova è partita: il mittente finto guarda lo stato della vecchia
 *     nel momento dell'invio, e con l'invio rifiutato il gettone vecchio
 *     conferma ancora;
 *   - una richiesta in attesa con il collegamento scaduto non è più «in
 *     corso»: «I tuoi dati» offre di nuovo la richiesta. La controprova: una
 *     non scaduta resta in corso.
 *
 * Il controllore riceve il servizio come terzo argomento posizionale: sul
 * codice di prima l'argomento in più si ignora, e le prove falliscono sul
 * comportamento (nessuna email, «inviata», GET che conferma), non su un nome
 * di parametro sconosciuto.
 *
 * Tutto dentro una transazione annullata alla fine (nessuno dei servizi ne
 * apre una sua); il registro delle anomalie in una cartella temporanea.
 * Indirizzi sul dominio riservato `.test` (RFC 2606).
 */
final class CancellazioneConEmailTest extends TestCase
{
    private const SITO = 'https://istanza.example.test';
    private const DPO = 'dpo@istanza.example.test';

    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $configPrima = [];
    private string $cartella = '';
    private string $utente = '';
    private int $idUtente = 0;
    private string $indirizzo = '';
    /** @var list<array{to: string, subject: string, body: string}> */
    private array $inviate = [];
    private bool $mittenteAccetta = true;
    /** @var (\Closure(): void)|null che cosa guardare nel momento dell'invio */
    private ?\Closure $allInvio = null;
    private string $registroPrima = '';

    protected function setUp(): void
    {
        if (!Database::isAvailable()) {
            self::markTestSkipped('database non disponibile');
        }
        $this->pdo = Database::connection();
        $this->configPrima = (array)(new \ReflectionProperty(Config::class, 'items'))->getValue();

        $this->cartella = sys_get_temp_dir() . '/pantedu_cancellazione_' . bin2hex(random_bytes(4));
        mkdir($this->cartella, 0700, true);
        // Anche error_log: le righe del servizio non sporcano l'uscita della suite.
        $this->registroPrima = (string)ini_get('error_log');
        ini_set('error_log', $this->cartella . '/errori.log');
        Config::set('app.paths.logs', $this->cartella);
        Config::set('app.url', self::SITO);
        Config::set('mail.dpo_email', self::DPO);
        // Come in produzione: il gettone non torna nella risposta.
        Config::set('app.env', 'production');
        Config::set('security.expose_deletion_debug_token', false);
        // Come in produzione anche il segreto dei registri, preso dall'ambiente:
        // senza, ImprontaIp segnala una chiave generata sul posto (anomalia).
        Config::set('waf.hmac_secret_dall_ambiente', true);

        $this->pdo->beginTransaction();
        $this->utente = 'zz_cancellazione_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $this->indirizzo = $this->utente . '@example.test';
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Cancellazione", ?, "x", "approved", 1, NOW())'
        )->execute([$this->utente, $this->indirizzo]);
        $this->idUtente = (int)$this->pdo->lastInsertId();

        $_SESSION = [
            'autenticato' => true, 'username' => $this->utente, 'user_id' => $this->idUtente,
            'user_role' => 'teacher', 'is_super_admin' => false,
        ];
        $_SERVER['REMOTE_ADDR'] = '192.0.2.44';
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->configPrima !== []) {
            (new \ReflectionProperty(Config::class, 'items'))->setValue(null, $this->configPrima);
        }
        ini_set('error_log', $this->registroPrima);
        if ($this->cartella !== '') {
            foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($this->cartella);
        }
        $_SESSION = [];
    }

    // ── Il verso che deve funzionare ────────────────────────────────────────

    #[Test]
    public function la_richiesta_manda_una_email_all_account_e_il_suo_collegamento_conferma(): void
    {
        $risposta = $this->chiedi();
        $json = $this->json($risposta);

        self::assertCount(1, $this->inviate, 'parte una email, una sola');
        $email = $this->inviate[0];
        self::assertSame($this->indirizzo, $email['to'], "all'indirizzo dell'account");
        self::assertSame(200, $risposta->status);
        self::assertTrue($json['ok'] ?? null);
        self::assertTrue($json['email_sent'] ?? null, 'la risposta dice che è partita, ed è vero');
        self::assertStringContainsString($this->indirizzo, (string)($json['message'] ?? ''), 'e dice dove');
        self::assertNull($json['debug_token'] ?? null, 'in produzione il gettone non torna nella risposta');
        self::assertSame(30, $json['cooling_off_days'] ?? null, 'la forma JSON di prima resta');
        self::assertSame(7, $json['token_expiry_days'] ?? null);

        // Il testo: che cosa succede, quanto vale, quando si esegue, come si
        // annulla, e che cosa fare se non l'ha chiesta lui.
        $giorniCollegamento = DeletionRequestService::TOKEN_EXPIRY_DAYS;
        $giorniRipensamento = DeletionRequestService::COOLING_OFF_DAYS;
        self::assertStringContainsString("entro $giorniCollegamento giorni", $email['body']);
        self::assertStringContainsString("fra $giorniRipensamento giorni", $email['body']);
        self::assertStringContainsString('«I tuoi dati»', $email['body']);
        self::assertStringContainsString(self::SITO . '/privacy/your-data', $email['body']);
        self::assertStringContainsString('Se non sei stato tu, basta ignorare questo messaggio', $email['body']);
        self::assertStringContainsString('pulsante', $email['body']);

        $gettone = $this->gettoneDellEmail($email['body']);
        self::assertSame('pending_confirm', $this->stato(), 'prima della conferma la richiesta aspetta');

        // Il collegamento, aperto in un browser: la pagina con il pulsante.
        $pagina = $this->apri($gettone, 'text/html,application/xhtml+xml');
        self::assertSame(200, $pagina->status);
        self::assertStringContainsString('action="/me/confirm-deletion"', $pagina->body);
        self::assertStringContainsString('name="token" value="' . $gettone . '"', $pagina->body);
        self::assertStringContainsString('name="_csrf" value="' . Csrf::token() . '"', $pagina->body);
        self::assertStringContainsString('href="/privacy/your-data"', $pagina->body);
        self::assertSame('pending_confirm', $this->stato(), 'aprire il collegamento non conferma');

        // Il pulsante.
        $confermata = $this->premi($gettone, 'text/html');
        self::assertSame(200, $confermata->status);
        self::assertStringContainsString('Cancellazione confermata', $confermata->body);
        self::assertStringContainsString('href="/privacy/your-data"', $confermata->body);
        self::assertSame('cooling_off', $this->stato(), 'il pulsante conferma davvero');

        self::assertSame('', $this->anomalie(), 'una email partita non è un\'anomalia');
    }

    #[Test]
    public function la_conferma_in_json_ha_la_forma_di_prima(): void
    {
        $this->chiedi();
        $gettone = $this->gettoneDellEmail($this->inviate[0]['body'] ?? '');

        $json = $this->json($this->premi($gettone));
        self::assertTrue($json['ok'] ?? null);
        self::assertSame('cooling_off', $json['status'] ?? null);

        $ancora = $this->premi($gettone);
        self::assertSame(400, $ancora->status, 'il gettone vale una volta sola');
        self::assertSame('token_invalid_or_expired', $this->json($ancora)['error'] ?? null);
    }

    // ── Il verso che non deve succedere ─────────────────────────────────────

    #[Test]
    public function se_l_email_non_parte_la_risposta_lo_dice_e_il_gettone_non_conferma(): void
    {
        $this->mittenteAccetta = false;
        $risposta = $this->chiedi();
        $json = $this->json($risposta);

        $messaggio = (string)($json['message'] ?? '');
        self::assertStringNotContainsStringIgnoringCase('inviata', $messaggio, 'mai «inviata» se non è partita');
        self::assertNotSame(200, $risposta->status, 'e non è un successo');
        self::assertFalse($json['ok'] ?? null);
        self::assertSame('email_non_inviata', $json['error'] ?? null);
        self::assertStringContainsString('non è stata registrata', $messaggio);
        self::assertStringContainsString(self::DPO, $messaggio, 'dice a chi scrivere');
        self::assertStringContainsString('/dpo-contact', $messaggio);
        self::assertNull($json['debug_token'] ?? null);

        // Il mittente il messaggio l'ha avuto in mano, e l'ha rifiutato: il
        // gettone che conteneva non deve confermare niente.
        self::assertCount(1, $this->inviate, 'un tentativo, non di più');
        $gettone = $this->gettoneDellEmail($this->inviate[0]['body']);
        $svc = new DeletionRequestService();
        self::assertNull($svc->activeRequest($this->idUtente), 'la richiesta non resta aperta');
        self::assertSame(400, $this->premi($gettone)->status, 'il gettone non conferma');
        self::assertNull($svc->activeRequest($this->idUtente), 'e niente è in ripensamento');

        self::assertStringContainsString(
            RichiestaDiCancellazione::ANOMALIA,
            $this->anomalie(),
            'e l\'amministratore lo viene a sapere'
        );
    }

    #[Test]
    public function se_l_email_non_parte_la_pagina_lo_dice_con_i_collegamenti_al_dpo(): void
    {
        $this->mittenteAccetta = false;
        $pagina = $this->chiedi('text/html,application/xhtml+xml');

        self::assertSame(503, $pagina->status);
        self::assertStringNotContainsStringIgnoringCase('inviata', $pagina->body);
        self::assertStringContainsString('non è stata registrata', $pagina->body);
        self::assertStringContainsString('href="mailto:' . self::DPO, $pagina->body);
        self::assertStringContainsString('href="/dpo-contact"', $pagina->body);
        self::assertStringContainsString('href="/privacy/your-data"', $pagina->body);
    }

    #[Test]
    public function senza_mittente_la_richiesta_non_si_crea(): void
    {
        $risposta = $this->chiedi('', senzaMittente: true);

        self::assertSame(503, $risposta->status);
        self::assertStringNotContainsStringIgnoringCase('inviata', (string)($this->json($risposta)['message'] ?? ''));
        self::assertSame(0, $this->quanteRichieste(), 'nessuna riga: la richiesta non si crea');
    }

    /**
     * Fino al 24/9/2026 questa prova partiva da una cancellazione confermata;
     * adesso quella la protegge il controllo che viene prima di tutti (vedi
     * sotto), e qui si guarda il caso che resta: una richiesta in attesa.
     */
    #[Test]
    public function un_account_senza_indirizzo_non_sostituisce_la_richiesta_in_attesa(): void
    {
        (new DeletionRequestService())->request($this->idUtente);
        $prima = $this->righe();
        $this->pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute(['', $this->idUtente]);

        $risposta = $this->chiedi();

        self::assertSame(409, $risposta->status);
        self::assertSame('senza_indirizzo', $this->json($risposta)['reason'] ?? null);
        self::assertCount(0, $this->inviate, 'non si tenta nessun invio');
        self::assertSame($prima, $this->righe(), 'la richiesta di prima resta com\'era, e non se ne crea un\'altra');
    }

    /**
     * Fuori dalla produzione il gettone torna nella risposta (è il canale
     * della suite end-to-end, che non ha una casella di posta): lì la
     * richiesta resta valida anche senza email. La risposta però dice che
     * l'email non è partita.
     */
    #[Test]
    public function in_prova_senza_email_il_gettone_torna_nella_risposta_e_non_si_dice_inviata(): void
    {
        Config::set('app.env', 'testing');
        $this->mittenteAccetta = false;
        $json = $this->json($this->chiedi());

        self::assertTrue($json['ok'] ?? null);
        self::assertFalse($json['email_sent'] ?? null);
        self::assertStringNotContainsStringIgnoringCase('inviata', (string)($json['message'] ?? ''));
        self::assertStringContainsString('non è partita', (string)($json['message'] ?? ''));
        $gettone = (string)($json['debug_token'] ?? '');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $gettone);
        self::assertTrue($this->json($this->premi($gettone))['ok'] ?? null, 'e conferma, come prima');
        self::assertSame('', $this->anomalie(), 'l\'ambiente di prova senza posta non è un\'anomalia');
    }

    // ── Una cancellazione già confermata non si tocca ──────────────────────

    #[Test]
    public function con_una_cancellazione_gia_confermata_non_se_ne_crea_un_altra_e_si_dice_quando_e_come_annullarla(): void
    {
        $svc = new DeletionRequestService();
        self::assertTrue($svc->confirm($svc->request($this->idUtente)), 'precondizione: una cancellazione confermata');
        $prima = $this->righe();
        $quando = date('d/m/Y', (int)strtotime((string)$prima[0]['execute_after']));

        $risposta = $this->chiedi();
        $json = $this->json($risposta);

        self::assertSame(409, $risposta->status);
        self::assertFalse($json['ok'] ?? null);
        self::assertSame('cancellazione_gia_confermata', $json['error'] ?? null);
        self::assertSame($prima[0]['execute_after'], $json['execute_after'] ?? null, 'con la data di esecuzione');
        $messaggio = (string)($json['message'] ?? '');
        self::assertStringContainsString("sarà eseguita il $quando", $messaggio);
        self::assertStringContainsString('«I tuoi dati»', $messaggio, 'e come annullarla');
        self::assertStringContainsString('Annulla la richiesta di cancellazione', $messaggio);
        self::assertSame('/me/cancel-deletion', $json['cancel'] ?? null);
        self::assertCount(0, $this->inviate, 'nessuna email');
        self::assertSame($prima, $this->righe(), 'nessuna riga nuova, e la confermata intatta');

        $pagina = $this->chiedi('text/html,application/xhtml+xml');
        self::assertSame(409, $pagina->status);
        self::assertStringContainsString('Cancellazione già confermata', $pagina->body);
        self::assertStringContainsString("sarà eseguita il $quando", $pagina->body);
        self::assertStringContainsString('href="/privacy/your-data"', $pagina->body);
        self::assertSame($prima, $this->righe(), 'neanche dalla pagina');
    }

    #[Test]
    public function con_il_mittente_che_rifiuta_la_cancellazione_confermata_resta_intatta(): void
    {
        $svc = new DeletionRequestService();
        self::assertTrue($svc->confirm($svc->request($this->idUtente)), 'precondizione: una cancellazione confermata');
        $prima = $this->righe();
        $this->mittenteAccetta = false;

        $this->chiedi();

        self::assertSame($prima, $this->righe(), 'la confermata resta com\'era, e non se ne aggiunge un\'altra');
        self::assertSame('cooling_off', $svc->activeRequest($this->idUtente)['status'] ?? null);
    }

    // ── Una richiesta in attesa si sostituisce solo a email partita ────────

    #[Test]
    public function la_richiesta_di_prima_si_ritira_solo_dopo_che_l_email_della_nuova_e_partita(): void
    {
        $vecchio = (new DeletionRequestService())->request($this->idUtente);
        $idVecchia = (int)$this->righe()[0]['id'];
        $allInvio = null;
        $this->allInvio = function () use (&$allInvio, $idVecchia): void {
            $allInvio = $this->statoDi($idVecchia);
        };

        $this->chiedi();

        self::assertSame('pending_confirm', $allInvio, 'mentre l\'email parte, la richiesta di prima vale ancora');
        self::assertSame('cancelled', $this->statoDi($idVecchia), 'a email partita, la nuova la sostituisce');
        self::assertSame(400, $this->premi($vecchio)->status, 'e il gettone di prima non conferma più');
        $nuovo = $this->gettoneDellEmail($this->inviate[0]['body'] ?? '');
        self::assertTrue($this->json($this->premi($nuovo))['ok'] ?? null, 'quello nuovo sì');
    }

    #[Test]
    public function se_l_email_della_nuova_non_parte_la_richiesta_di_prima_resta_valida(): void
    {
        $vecchio = (new DeletionRequestService())->request($this->idUtente);
        $idVecchia = (int)$this->righe()[0]['id'];
        $this->mittenteAccetta = false;

        $json = $this->json($this->chiedi());

        self::assertFalse($json['ok'] ?? null);
        self::assertStringContainsString('La richiesta che avevi fatto prima resta valida', (string)($json['message'] ?? ''));
        self::assertSame('pending_confirm', $this->statoDi($idVecchia), 'la richiesta di prima non si tocca');
        $nuovo = $this->gettoneDellEmail($this->inviate[0]['body'] ?? '');
        self::assertSame(400, $this->premi($nuovo)->status, 'il gettone dell\'email non partita non conferma');
        self::assertTrue($this->json($this->premi($vecchio))['ok'] ?? null, 'quello di prima sì');
    }

    // ── Una richiesta scaduta non è in corso ───────────────────────────────

    #[Test]
    public function una_richiesta_con_il_collegamento_scaduto_non_e_in_corso_e_se_ne_puo_chiedere_un_altra(): void
    {
        $svc = new DeletionRequestService();
        $svc->request($this->idUtente);
        $this->scadeFra(-60);

        self::assertNull($svc->activeRequest($this->idUtente), 'scaduta, non è in corso');
        self::assertFalse($this->json($this->statoInJson())['pending'] ?? null, '/me/deletion-status lo stesso');
        $pagina = $this->iTuoiDati();
        self::assertStringContainsString('action="/me/request-deletion"', $pagina, '«I tuoi dati» offre la richiesta');
        self::assertStringNotContainsString('action="/me/cancel-deletion"', $pagina);
    }

    #[Test]
    public function una_richiesta_non_ancora_scaduta_resta_in_corso(): void
    {
        $svc = new DeletionRequestService();
        $svc->request($this->idUtente);
        $this->scadeFra(60);

        self::assertSame('pending_confirm', $svc->activeRequest($this->idUtente)['status'] ?? null);
        self::assertTrue($this->json($this->statoInJson())['pending'] ?? null);
        $pagina = $this->iTuoiDati();
        self::assertStringContainsString('action="/me/cancel-deletion"', $pagina, 'con il modulo per annullarla');
        self::assertStringNotContainsString('action="/me/request-deletion"', $pagina);
    }

    // ── Il collegamento non conferma da solo ───────────────────────────────

    #[Test]
    public function aprire_il_collegamento_non_conferma_in_nessun_modo(): void
    {
        $gettone = (new DeletionRequestService())->request($this->idUtente);

        // Come un programma di posta che controlla i collegamenti: JSON,
        // Accept generico, HEAD.
        $json = $this->apri($gettone, 'application/json');
        self::assertSame(200, $json->status, 'il collegamento vale');
        self::assertSame('pending_confirm', $this->json($json)['status'] ?? null);
        $this->apri($gettone, '*/*');
        $this->apri($gettone, '', 'HEAD');

        self::assertSame('pending_confirm', $this->stato(), 'e nessuna di queste aperture ha confermato');
        self::assertTrue($this->json($this->premi($gettone))['ok'] ?? null, 'il pulsante sì');
    }

    #[Test]
    public function un_collegamento_non_valido_e_una_pagina_leggibile_o_il_json_di_prima(): void
    {
        $pagina = $this->apri('gettone_inventato_xxxxxxx', 'text/html');
        self::assertSame(400, $pagina->status);
        self::assertStringContainsString('Collegamento non valido', $pagina->body);
        self::assertStringNotContainsString('action="/me/confirm-deletion"', $pagina->body, 'senza pulsante');
        self::assertStringContainsString('href="/privacy/your-data"', $pagina->body);

        $json = $this->apri('gettone_inventato_xxxxxxx', '');
        self::assertSame(400, $json->status);
        self::assertSame('token_invalid_or_expired', $this->json($json)['error'] ?? null);
    }

    #[Test]
    public function l_annullamento_da_un_modulo_e_una_pagina_e_in_json_resta_com_era(): void
    {
        $svc = new DeletionRequestService();
        $svc->confirm($svc->request($this->idUtente));

        $pagina = $this->annulla('text/html');
        self::assertSame(200, $pagina->status);
        self::assertStringContainsString('Cancellazione annullata', $pagina->body);
        self::assertStringContainsString('href="/privacy/your-data"', $pagina->body);
        self::assertNull($svc->activeRequest($this->idUtente));

        $json = $this->json($this->annulla(''));
        self::assertSame(['ok' => false, 'message' => 'Nessuna cancellazione attiva da annullare.'], $json);
    }

    // ── «I tuoi dati» ───────────────────────────────────────────────────────

    #[Test]
    public function i_tuoi_dati_ha_il_modulo_post_con_il_gettone_e_non_il_collegamento(): void
    {
        $pagina = $this->iTuoiDati();

        self::assertStringNotContainsString(
            'href="/me/request-deletion"',
            $pagina,
            'niente GET verso una rotta solo POST'
        );
        self::assertMatchesRegularExpression(
            '~<form method="post" action="/me/request-deletion">\s*<input type="hidden" name="_csrf" value="'
            . preg_quote(Csrf::token(), '~') . '">~',
            $pagina,
            'il modulo POST con il gettone CSRF della sessione'
        );
        self::assertStringContainsString('Chiedi la cancellazione dell\'account', $pagina);
        self::assertStringNotContainsString('action="/me/cancel-deletion"', $pagina, 'niente da annullare');
    }

    #[Test]
    public function i_tuoi_dati_con_una_richiesta_in_corso_mostra_lo_stato_e_il_modulo_per_annullarla(): void
    {
        $svc = new DeletionRequestService();
        $svc->confirm($svc->request($this->idUtente));
        $quando = date('d/m/Y', time() + DeletionRequestService::COOLING_OFF_DAYS * 86400);

        $pagina = $this->iTuoiDati();

        self::assertStringContainsString('action="/me/cancel-deletion"', $pagina);
        self::assertStringContainsString($quando, $pagina, 'con la data in cui sarebbe eseguita');
        self::assertStringNotContainsString('action="/me/request-deletion"', $pagina);
    }

    #[Test]
    public function i_tuoi_dati_senza_accesso_non_ha_moduli(): void
    {
        $_SESSION = [];
        $pagina = $this->iTuoiDati();

        self::assertStringNotContainsString('action="/me/request-deletion"', $pagina);
        self::assertStringNotContainsString('action="/me/cancel-deletion"', $pagina);
        self::assertStringContainsString('href="/login"', $pagina);
    }

    // ── Appoggi ─────────────────────────────────────────────────────────────

    private function controllore(bool $senzaMittente = false): SelfServiceController
    {
        $mailer = new Mailer('noreply@istanza.example.test', 'Pantedu', function (
            string $to,
            string $subject,
            string $body,
            string $headers
        ): bool {
            $this->inviate[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
            if ($this->allInvio !== null) {
                ($this->allInvio)();
            }
            return $this->mittenteAccetta;
        });
        $posta = static fn(): ?Mailer => $senzaMittente ? null : $mailer;
        // Posizionale, non con il nome: vedi il commento della classe.
        return new SelfServiceController(
            new ConsentService(),
            new DeletionRequestService(),
            new RichiestaDiCancellazione(null, $posta),
        );
    }

    private function chiedi(string $accept = '', bool $senzaMittente = false): Response
    {
        $this->richiesta('POST', '/me/request-deletion', $accept);
        $_POST = ['reason' => 'prova della suite'];
        return $this->controllore($senzaMittente)->requestDeletion(new Request(''));
    }

    private function apri(string $gettone, string $accept, string $metodo = 'GET'): Response
    {
        $this->richiesta($metodo, '/me/confirm-deletion', $accept);
        $_GET = ['token' => $gettone];
        return $this->controllore()->confirmDeletion(new Request(''));
    }

    private function premi(string $gettone, string $accept = ''): Response
    {
        $this->richiesta('POST', '/me/confirm-deletion', $accept);
        $_POST = ['token' => $gettone, '_csrf' => Csrf::token()];
        return $this->controllore()->confirmDeletionSubmit(new Request(''));
    }

    private function annulla(string $accept): Response
    {
        $this->richiesta('POST', '/me/cancel-deletion', $accept);
        return $this->controllore()->cancelDeletion(new Request(''));
    }

    private function iTuoiDati(): string
    {
        $this->richiesta('GET', '/privacy/your-data', 'text/html');
        return (new TrustPagesController())->yourData(new Request(''))->body;
    }

    private function richiesta(string $metodo, string $percorso, string $accept): void
    {
        $_SERVER['REQUEST_METHOD'] = $metodo;
        $_SERVER['REQUEST_URI'] = $percorso;
        if ($accept === '') {
            unset($_SERVER['HTTP_ACCEPT']);
        } else {
            $_SERVER['HTTP_ACCEPT'] = $accept;
        }
        $_GET = [];
        $_POST = [];
    }

    /** @return array<string, mixed> */
    private function json(Response $risposta): array
    {
        $dati = json_decode($risposta->body, true);
        self::assertIsArray($dati, 'la risposta è JSON: ' . substr($risposta->body, 0, 200));
        return $dati;
    }

    private function gettoneDellEmail(string $corpo): string
    {
        $trovato = preg_match(
            '~^' . preg_quote(self::SITO, '~') . '/me/confirm-deletion\?token=([0-9a-f]{64})$~m',
            $corpo,
            $m
        );
        self::assertSame(1, $trovato, "l'email ha il collegamento al sito dell'istanza con il gettone");
        return $m[1];
    }

    private function stato(): ?string
    {
        $st = $this->pdo->prepare(
            'SELECT status FROM deletion_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$this->idUtente]);
        $stato = $st->fetchColumn();
        return $stato === false ? null : (string)$stato;
    }

    /** @return list<array<string, mixed>> le richieste dell'utente, com'erano scritte */
    private function righe(): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, status, confirm_token, requested_at, expires_at, confirmed_at, execute_after, cancelled_at
               FROM deletion_requests WHERE user_id = ? ORDER BY id'
        );
        $st->execute([$this->idUtente]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function statoDi(int $id): ?string
    {
        $st = $this->pdo->prepare('SELECT status FROM deletion_requests WHERE id = ?');
        $st->execute([$id]);
        $stato = $st->fetchColumn();
        return $stato === false ? null : (string)$stato;
    }

    /** Sposta la scadenza del collegamento: `$secondi` da adesso, nel passato se negativo. */
    private function scadeFra(int $secondi): void
    {
        $this->pdo->prepare('UPDATE deletion_requests SET expires_at = ? WHERE user_id = ?')
            ->execute([date('Y-m-d H:i:s', time() + $secondi), $this->idUtente]);
    }

    private function statoInJson(): Response
    {
        $this->richiesta('GET', '/me/deletion-status', '');
        return $this->controllore()->deletionStatus(new Request(''));
    }

    private function quanteRichieste(): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM deletion_requests WHERE user_id = ?');
        $st->execute([$this->idUtente]);
        return (int)$st->fetchColumn();
    }

    private function anomalie(): string
    {
        $file = $this->cartella . '/anomalie.jsonl';
        return is_file($file) ? (string)file_get_contents($file) : '';
    }
}
