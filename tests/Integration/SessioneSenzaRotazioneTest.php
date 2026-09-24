<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'id di sessione non ruota più; la sessione ha una durata massima (14/9/2026).
 *
 * Session::start ruotava l'id ogni cinque minuti con
 * session_regenerate_id(true): le richieste parallele partite con l'id vecchio
 * perdevano la sessione, e con la modalità stretta di produzione l'utente
 * veniva disconnesso (voce 101 del debito). Ora l'id cambia solo a login,
 * secondo fattore e cambio di ruolo, e una sessione autenticata finisce dopo
 * `session.absolute_lifetime` dal login.
 *
 * La prova usa Session::start vero, in processi PHP separati, come due
 * richieste della stessa sessione: la prima la prepara, la seconda la rilegge.
 * A file, in una cartella della prova (dal 14/9/2026 il modo si sceglie in
 * configurazione, ADR-039). Nei due versi: una sessione aperta da dieci
 * minuti tiene id e login (con il codice di prima l'id cambiava); una aperta da
 * più della durata massima si chiude.
 */
final class SessioneSenzaRotazioneTest extends TestCase
{
    private string $cartella = '';
    private string $figlio = '';

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-sessioni-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        $this->figlio = $this->cartella . '/richiesta.php';
        file_put_contents($this->figlio, <<<'PHP'
<?php
// Una richiesta: Session::start vero, poi prepara o rilegge la sessione.
[$script, $base, $id, $azione, $etaLogin, $cartella] = $argv;
require $base . '/vendor/autoload.php';
foreach (['.env', '.env.local'] as $f) {
    if (is_file("$base/$f")) {
        \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
    }
}
\App\Core\Config::load($base . '/app/Config');
ini_set('session.use_cookies', '0');
// La cartella la sceglie la configurazione (ADR-039), come in ogni richiesta vera.
\App\Core\Config::set('session.driver', 'file');
\App\Core\Config::set('session.save_path', $cartella);
// Con la modalità stretta un id nuovo non si accetta: la prima richiesta ne
// riceve uno dal server, la seconda riusa quello.
if ($id !== '-') {
    session_id($id);
}
\App\Core\Session::start();
if ($azione === 'prepara') {
    $_SESSION['autenticato'] = true;
    $_SESSION['username'] = 'prova-rotazione';
    $_SESSION['login_time'] = time() - (int)$etaLogin;
    $_SESSION['last_activity'] = time();
    // Il segno con cui il codice di prima decideva di ruotare: ultima rotazione mezz'ora fa.
    $_SESSION['last_regeneration'] = time() - 1800;
}
echo json_encode(['id' => session_id(), 'autenticato' => $_SESSION['autenticato'] ?? null]), "\n";
session_write_close();
PHP);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella);
    }

    /** @return array{id: string, autenticato: mixed} */
    private function richiesta(string $id, string $azione, int $etaLogin = 0): array
    {
        $cmd = [PHP_BINARY, $this->figlio, dirname(__DIR__, 2), $id, $azione, (string)$etaLogin, $this->cartella];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        $this->assertIsResource($proc);
        $uscita = (string)stream_get_contents($pipes[1]);
        $errori = (string)stream_get_contents($pipes[2]);
        proc_close($proc);
        $esito = json_decode(trim($uscita), true);
        $this->assertIsArray($esito, "la richiesta risponde: {$uscita}{$errori}");
        return ['id' => (string)$esito['id'], 'autenticato' => $esito['autenticato'] ?? null];
    }

    #[Test]
    public function una_sessione_aperta_da_dieci_minuti_tiene_id_e_login(): void
    {
        $id = $this->richiesta('-', 'prepara', 600)['id'];
        $this->assertNotSame('', $id, 'la prima richiesta apre una sessione');

        $dopo = $this->richiesta($id, 'leggi');

        $this->assertSame($id, $dopo['id'], "l'id non ruota: le richieste parallele non perdono la sessione");
        $this->assertTrue($dopo['autenticato'], 'e il login resta');
    }

    #[Test]
    public function oltre_la_durata_massima_la_sessione_si_chiude(): void
    {
        $id = $this->richiesta('-', 'prepara', 50000)['id'];

        $dopo = $this->richiesta($id, 'leggi');

        $this->assertNull($dopo['autenticato'], 'dopo dodici ore dal login bisogna rientrare');
    }

    #[Test]
    public function la_durata_si_conta_dal_login_e_solo_se_c_e(): void
    {
        $this->assertFalse(Session::oltreLaDurata(null, 43200, 100000), 'senza login nessuna durata');
        $this->assertFalse(Session::oltreLaDurata(1000, 0, 999999), 'senza tetto nessuna durata');
        $this->assertFalse(Session::oltreLaDurata(1000, 43200, 1000 + 43200), 'al limite resta aperta');
        $this->assertTrue(Session::oltreLaDurata(1000, 43200, 1000 + 43201), 'oltre si chiude');
        $this->assertTrue(Session::oltreLaDurata('1000', 43200, 50000), 'anche con il numero scritto come testo');
    }
}
