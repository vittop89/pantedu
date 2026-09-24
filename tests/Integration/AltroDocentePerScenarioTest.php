<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\AuthController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Services\Study\MaterieConMateriali;
use App\Support\ClassAccessGrant;
use App\Support\DeploymentScenario;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * «La credenziale di un altro docente» si propone solo dove altri docenti ci
 * sono (19/9/2026).
 *
 * Nello scenario 1 c'è solo l'autore (ADR-032; informativa dello scenario 1,
 * §2), eppure la barra di chi entrava con la credenziale offriva «➕ Aggiungi»
 * con il titolo «Inserisci la credenziale di un altro docente», e la pagina
 * /accesso-classe, con il portachiavi già aperto, «Aggiungi la credenziale di
 * un altro docente». Adesso lo decide DeploymentScenario::consentePiuDocenti():
 *
 *   - la scritta «Credenziale del docente, nessun account» resta in ogni
 *     scenario: è l'unico segno che si è entrati senza account;
 *   - il link della barra e il titolo «un altro docente» ci sono solo negli
 *     scenari 2 e 3.
 *
 * Il modulo, invece, c'è sempre (20/9/2026). Lo scenario dice quanti docenti
 * ci sono, non quante credenziali: il portachiavi ne tiene più d'una anche
 * dello stesso docente — ClassAccessGrant::add() deduplica per
 * `credential_id` — e sono legittime (la classe e il gruppo di recupero, o
 * quella rifatta dopo una fuga mentre la vecchia è ancora valida). Nasconderlo
 * lasciava, nello scenario 1 col portachiavi aperto, una pagina senza nessun
 * campo dove digitare e nessuna indicazione di premere prima «Esci»: nello
 * scenario 1 cambia solo il titolo.
 *
 * Nessuna prova end-to-end nell'altro verso: lo scenario vale per tutta
 * l'istanza su cui gira la suite, che è lo scenario 1 (ADR-040). Qui lo
 * scenario si sceglie per prova, dalla configurazione.
 *
 * Fixture isolata in transazione (rollback in tearDown).
 */
final class AltroDocentePerScenarioTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private int $scuola = 0;
    private int $docente = 0;
    private int $credenziale = 0;
    private mixed $scenarioPrima = null;

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM teacher_access_credentials_data LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->scenarioPrima = Config::get('app.deployment_scenario');
        $this->pdo->beginTransaction();
        $this->inTx = true;
        $_SESSION = [];
        $_GET = [];
        ClassAccessGrant::resetCache();
        MaterieConMateriali::dimentica();

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZADS001', 'SCUOLA ALTRO DOCENTE', 'Comune Esempio']);
        $this->scuola = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES ("zzads_doc", "teacher", "Zz", "Autore", "zzads_doc@example.invalid", "x", "approved", 1, NOW())'
        )->execute();
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$this->docente, $this->scuola]);
        $this->pdo->prepare(
            'INSERT INTO teacher_access_credentials_data (teacher_id, label, access_username, password_hash, institute_id, active)
             VALUES (?, "Terza B", ?, "x", ?, 1)'
        )->execute([$this->docente, 'zzads_' . uniqid(), $this->scuola]);
        $this->credenziale = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->impostaScenario($this->scenarioPrima);
        $_SESSION = [];
        $_GET = [];
        unset($_SERVER['HTTP_X_PARTIAL']);
        ClassAccessGrant::resetCache();
        MaterieConMateriali::dimentica();
    }

    private function impostaScenario(mixed $valore): void
    {
        $ref = new \ReflectionProperty(Config::class, 'items');
        $items = $ref->getValue();
        $items['app']['deployment_scenario'] = $valore;
        $ref->setValue(null, $items);
        DeploymentScenario::resetCache();
    }

    private function nelloScenario(string $scenario): void
    {
        $this->impostaScenario($scenario);
        // Un file di scenario scritto dal pannello vincerebbe sulla
        // configurazione: la prova misurerebbe lo scenario sbagliato.
        $this->assertSame('env', DeploymentScenario::snapshot()['source'], 'lo scenario viene dalla configurazione della prova');
        $this->assertSame($scenario, DeploymentScenario::current());
    }

    private function conCredenziale(): void
    {
        $_SESSION = [];
        ClassAccessGrant::resetCache();
        ClassAccessGrant::add([
            'teacher_id' => $this->docente, 'institute_id' => $this->scuola, 'indirizzo' => 'ZAD', 'classe' => '3',
            'label' => 'Terza B', 'credential_id' => $this->credenziale, 'granted_at' => time(),
        ]);
        ClassAccessGrant::resetCache();
    }

    /** Il riquadro dei collegamenti sotto il portachiavi, nella barra. */
    private function collegamentiDelPortachiavi(): string
    {
        $_GET = [];
        $_SERVER['HTTP_X_PARTIAL'] = '';
        $pageTitle = 'Prova';
        $pageContent = '<div id="prova">x</div>';
        $pageScripts = '';
        ob_start();
        include dirname(__DIR__, 2) . '/views/layout/app.php';
        $html = (string)ob_get_clean();
        $this->assertSame(1, preg_match('~<ul class="fm-session-keychain.*?</ul>\s*<div class="fm-session-links">(.*?)</div>~s', $html, $m),
            'il banner della credenziale è nella barra');
        return $m[1];
    }

    private function paginaDellAccesso(): string
    {
        ClassAccessGrant::resetCache();
        $risposta = (new AuthController())->showClassAccess(new Request());
        $this->assertSame(200, $risposta->status);
        return $risposta->body;
    }

    #[Test]
    public function nello_scenario_1_la_barra_non_propone_un_altro_docente(): void
    {
        $this->nelloScenario(DeploymentScenario::PERSONAL);
        $this->conCredenziale();
        $riquadro = $this->collegamentiDelPortachiavi();
        $this->assertStringContainsString('Credenziale del docente, nessun account', $riquadro, 'la scritta resta');
        $this->assertStringNotContainsString('href="/accesso-classe"', $riquadro, 'il link per un altro docente no');
    }

    #[Test]
    public function nello_scenario_2_la_barra_lo_propone(): void
    {
        $this->nelloScenario(DeploymentScenario::COLLEAGUES);
        $this->conCredenziale();
        $riquadro = $this->collegamentiDelPortachiavi();
        $this->assertStringContainsString('Credenziale del docente, nessun account', $riquadro);
        $this->assertStringContainsString('href="/accesso-classe"', $riquadro, 'fra colleghi il link c\'è');
        $this->assertStringContainsString('➕ Aggiungi', $riquadro);
    }

    #[Test]
    public function nello_scenario_1_la_pagina_con_il_portachiavi_non_parla_di_un_altro_docente_ma_il_modulo_resta(): void
    {
        $this->nelloScenario(DeploymentScenario::PERSONAL);
        $this->conCredenziale();
        $pagina = $this->paginaDellAccesso();
        $this->assertStringContainsString('Terza B', $pagina, 'il portachiavi è in pagina');
        $this->assertStringContainsString('Vai ai materiali', $pagina, 'con «Vai ai materiali»');
        $this->assertStringContainsString('action="/accesso-classe/esci"', $pagina, 'e con «Esci»');
        $this->assertStringNotContainsString('un altro docente', $pagina, 'niente «un altro docente»');
        // Una seconda credenziale dello stesso docente è legittima (classe e
        // gruppo, o quella rifatta): senza il modulo la pagina era un vicolo
        // cieco, e l'unica strada restava il QR del pacchetto.
        $this->assertStringContainsString('Aggiungi un\'altra credenziale', $pagina, 'ma si può aggiungerne un\'altra');
        $this->assertStringContainsString('id="fm-class-access-form"', $pagina, 'con il modulo, che c\'è');
        $this->assertStringContainsString('>Aggiungi</button>', $pagina, 'e il pulsante dice «Aggiungi»');
    }

    #[Test]
    public function nello_scenario_2_la_pagina_con_il_portachiavi_offre_di_aggiungere(): void
    {
        $this->nelloScenario(DeploymentScenario::COLLEAGUES);
        $this->conCredenziale();
        $pagina = $this->paginaDellAccesso();
        $this->assertStringContainsString('Aggiungi la credenziale di un altro docente', $pagina);
        $this->assertStringContainsString('id="fm-class-access-form"', $pagina);
    }

    #[Test]
    public function senza_credenziali_il_modulo_c_e_in_ogni_scenario_e_i_piu_docenti_solo_dove_ci_sono(): void
    {
        $this->nelloScenario(DeploymentScenario::PERSONAL);
        $pagina = $this->paginaDellAccesso();
        $this->assertStringContainsString('id="fm-class-access-form"', $pagina, 'per entrare il modulo serve sempre');
        $this->assertStringNotContainsString('più docenti sul sito', $pagina, 'nello scenario 1 non si parla di più docenti');

        $this->nelloScenario(DeploymentScenario::COLLEAGUES);
        $this->assertStringContainsString('più docenti sul sito', $this->paginaDellAccesso());
    }
}
