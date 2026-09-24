<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\TikzRenderController;
use App\Core\Config;
use App\Core\Request;
use App\Core\Session;
use App\Support\ClassAccessGrant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Segnalazione dell'utente (21/9/2026): entrando con la credenziale di classe,
 * al posto delle figure di alcuni esercizi compariva
 * «[TikZ render error] unauthenticated».
 *
 * Le figure si prendono in due tempi: una GET dice se la figura è già in
 * cache, e quando manca una POST la fa compilare. La GET era raggiungibile da
 * chi studia; la POST no — stava nel gruppo che vuole un account. Così la
 * pagina gli arrivava, ma il pezzo che disegna le figure gli veniva negato.
 *
 * Qui si misura **la decisione di autorizzazione**, nei due versi: senza
 * credenziale la compilazione resta chiusa; con una credenziale valida non è
 * più il permesso a fermarla. In questo ambiente il servizio TeX non è
 * configurato con un indirizzo che non risponde: la risposta «aperta» è quindi
 * un errore di rete, non un 401. È la prova che il cancello è stato passato,
 * perché la decisione sul permesso viene prima di qualunque chiamata.
 */
final class FigureDiChiStudiaTest extends TestCase
{
    protected function setUp(): void
    {
        // Il servizio TeX va **configurato**, altrimenti il controller esce
        // subito con 503 e non arriva nemmeno a decidere il permesso: la prova
        // misurerebbe l'ambiente invece della regola. L'indirizzo è finto di
        // proposito — qui nessuno compila davvero, e la porta che si misura
        // viene prima di qualunque chiamata di rete.
        Config::set('tex_compile.endpoint', 'http://127.0.0.1:1');
        Config::set('tex_compile.secret', 'segreto-di-prova');
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = ['tikz' => '\draw (0,0) -- (1,1);', 'scope' => 'public'];
        $this->svuotaIlPortachiavi();
    }

    protected function tearDown(): void
    {
        $this->svuotaIlPortachiavi();
        $_POST = [];
        unset($_SERVER['CONTENT_TYPE']);
    }

    private function svuotaIlPortachiavi(): void
    {
        Session::put(ClassAccessGrant::SESSION_KEY, null);
        Session::put(ClassAccessGrant::LEGACY_KEY, null);
        (new \ReflectionClass(ClassAccessGrant::class))->setStaticPropertyValue('cache', null);
    }

    /** La risposta del controller a una richiesta di compilazione. */
    private function compila(): array
    {
        $res = (new TikzRenderController())->render(new Request());
        $corpo = json_decode((string)$res->body, true);
        return [$res->status, (string)($corpo['error'] ?? '')];
    }

    #[Test]
    public function senza_credenziale_la_compilazione_resta_chiusa(): void
    {
        [$stato, $errore] = $this->compila();

        self::assertSame(401, $stato);
        self::assertSame('auth_required', $errore);
    }

    #[Test]
    public function con_la_credenziale_di_classe_il_permesso_non_e_piu_l_ostacolo(): void
    {
        Session::put(ClassAccessGrant::SESSION_KEY, [[
            'teacher_id' => 77, 'institute_id' => 106, 'indirizzo' => 'SCI',
            'classe' => '3', 'label' => '3_SCI_FIS-GEO-MAT_PANTA',
            // Senza identificativo: qui si misura il permesso, non la
            // riverifica sul database della credenziale (che ha la sua prova,
            // ClassAccessGrantTest, e in questa suite il database non c'è).
            'credential_id' => null, 'source' => 'teacher_access_credentials',
            'granted_at' => time(),
        ]]);
        (new \ReflectionClass(ClassAccessGrant::class))->setStaticPropertyValue('cache', null);
        self::assertTrue(ClassAccessGrant::isActive(), 'il portachiavi della prova è attivo');

        [$stato, $errore] = $this->compila();

        self::assertNotSame(401, $stato, "chi ha la credenziale non prende più 401 ($errore)");
        self::assertNotSame('auth_required', $errore);
    }

    #[Test]
    public function la_cache_del_docente_resta_del_docente(): void
    {
        // Una credenziale di classe non apre la dispensa cifrata del docente:
        // quella si decifra solo con la sua chiave.
        Session::put(ClassAccessGrant::SESSION_KEY, [[
            'teacher_id' => 77, 'institute_id' => 106, 'indirizzo' => 'SCI',
            'classe' => '3', 'label' => '3_SCI_FIS-GEO-MAT_PANTA',
            // Senza identificativo: qui si misura il permesso, non la
            // riverifica sul database della credenziale (che ha la sua prova,
            // ClassAccessGrantTest, e in questa suite il database non c'è).
            'credential_id' => null, 'source' => 'teacher_access_credentials',
            'granted_at' => time(),
        ]]);
        (new \ReflectionClass(ClassAccessGrant::class))->setStaticPropertyValue('cache', null);
        $_POST['scope'] = 'teacher';

        [$stato, $errore] = $this->compila();

        self::assertSame(401, $stato);
        self::assertSame('auth_required', $errore);
    }
}
