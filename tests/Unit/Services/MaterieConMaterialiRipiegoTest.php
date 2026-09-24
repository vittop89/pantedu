<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Core\Config;
use App\Services\Study\MaterieConMateriali;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il ripiego del selettore delle materie non è muto (20/9/2026).
 *
 * Se il calcolo delle materie con materiali non riesce — una query che
 * fallisce, una colonna cambiata da una migrazione, la sessione non
 * scrivibile — la pagina non deve cadere: resta il vocabolario intero, che è
 * quello che si vedeva prima del 19/9/2026. Ma quel ripiego **è** il difetto
 * che la correzione ha tolto: diciassette materie di cui quindici vuote, e i
 * pannelli che chiedono i contenuti una volta per materia. Se torna in
 * silenzio nessuno lo sa, e la prova d'integrazione non lo vede perché misura
 * il caso buono (CLAUDE.md: gli errori si correggono, non si silenziano).
 *
 * Quindi si scrive nel registro delle anomalie, che `tools/ops/diagnostica.php`
 * legge a timer. Provato nei due versi: scatta quando il calcolo fallisce, e
 * non scatta quando riesce.
 *
 * Prova di unità: nessun database: il calcolo si passa da fuori.
 */
final class MaterieConMaterialiRipiegoTest extends TestCase
{
    private string $cartella = '';
    private mixed $logsPrima = null;

    protected function setUp(): void
    {
        // Il registro va in una cartella tutta sua: qui finisce anche il file
        // di stato del contenimento del rumore, che altrimenti si porterebbe
        // dietro le scritture di un'altra prova.
        $this->logsPrima = Config::get('app.paths.logs');
        $this->cartella = sys_get_temp_dir() . '/pantedu-anomalie-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0775, true);
        Config::set('app.paths.logs', $this->cartella);
    }

    protected function tearDown(): void
    {
        Config::set('app.paths.logs', $this->logsPrima);
        foreach (glob($this->cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella);
    }

    /** Le righe del registro delle anomalie scritte da questa prova. */
    private function anomalie(): array
    {
        $file = $this->cartella . '/anomalie.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $righe = [];
        foreach (explode("\n", trim((string)file_get_contents($file))) as $riga) {
            if (trim($riga) !== '') {
                $righe[] = json_decode($riga, true);
            }
        }
        return $righe;
    }

    #[Test]
    public function se_il_calcolo_fallisce_resta_il_vocabolario_intero_e_il_registro_lo_dice(): void
    {
        $voci = [['code' => 'MAT'], ['code' => 'FIS'], ['code' => 'STO']];

        $esito = MaterieConMateriali::perLaBarra($voci, static function (): array {
            throw new \RuntimeException('SQLSTATE[42S22]: colonna sparita');
        });

        self::assertSame($voci, $esito['materie'], 'il vocabolario intero: la pagina non cade');
        self::assertFalse($esito['filtrate'], 'e la barra non dichiara di aver filtrato');

        $anomalie = $this->anomalie();
        self::assertCount(1, $anomalie, 'una riga nel registro delle anomalie');
        self::assertSame('materie_di_chi_studia_non_filtrate', $anomalie[0]['codice']);
        self::assertSame('RuntimeException', $anomalie[0]['dettagli']['errore'], 'con il tipo di guasto');
        self::assertStringContainsString('colonna sparita', (string)$anomalie[0]['dettagli']['messaggio'], 'e il messaggio');
        self::assertSame(3, $anomalie[0]['dettagli']['materie'], 'e quante materie sono tornate in circolo');
    }

    #[Test]
    public function nell_altro_verso_quando_il_calcolo_riesce_non_scrive_niente(): void
    {
        $voci = [['code' => 'MAT'], ['code' => 'FIS']];

        $esito = MaterieConMateriali::perLaBarra($voci, static fn(array $v): array => [$v[0]]);

        self::assertSame([['code' => 'MAT']], $esito['materie']);
        self::assertTrue($esito['filtrate']);
        self::assertSame([], $this->anomalie(), 'un registro che si riempie anche quando va bene non lo apre più nessuno');
    }
}
