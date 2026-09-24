<?php

namespace Tests\Unit\Rendering;

use App\Services\Rendering\RmColumnTypes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * I tipi di cella delle tabelle a risposta multipla sono scritti due volte: in
 * PHP (`RmColumnTypes`, che rende l'HTML del contratto e i simboli del TeX) e
 * in JavaScript (`COL_TYPES` in `rm-table-view.js`, che rende la stessa tabella
 * nell'editor). Se le due liste divergono, l'anteprima e la stampa mostrano
 * cose diverse e nessuno se ne accorge finché non lo vede un docente.
 *
 * Qui il confronto si fa davvero: il modulo JavaScript viene letto dal disco e
 * la sua lista confrontata voce per voce con quella PHP. Un test end-to-end non
 * potrebbe: la lista PHP non è esposta da nessuna rotta.
 *
 * Voce 58 del registro del debito. Il caso storico che prometteva questo
 * confronto — «RmColumnTypes JS export coerente con PHP» — leggeva due
 * variabili globali in un oggetto che poi non guardava e chiudeva verificando
 * che nella pagina ci fosse un `.fm-contract-wrap`: vero per qualunque pagina.
 */
final class RmColumnTypesParitaJsTest extends TestCase
{
    private const MODULO_JS = __DIR__ . '/../../../js/modules/render/rm-table-view.js';

    /**
     * Legge `COL_TYPES` dal modulo JavaScript.
     *
     * @return array<string, array{html: string, tex: string, desc: string}>
     */
    private function tipiDelJavaScript(): array
    {
        $percorso = realpath(self::MODULO_JS);
        self::assertNotFalse($percorso, 'il modulo rm-table-view.js non si trova: ' . self::MODULO_JS);

        $sorgente = file_get_contents($percorso);
        self::assertIsString($sorgente, 'il modulo rm-table-view.js non si legge');

        $blocco = null;
        if (preg_match('/export\s+const\s+COL_TYPES\s*=\s*Object\.freeze\(\{(.*?)\}\);/s', $sorgente, $m)) {
            $blocco = $m[1];
        }
        self::assertNotNull(
            $blocco,
            "in rm-table-view.js non si trova più l'export `COL_TYPES = Object.freeze({...})`: "
            . 'se il modulo è stato riscritto va aggiornato questo test, non cancellato'
        );

        $trovati = preg_match_all(
            "/^\s*([A-Z]):\s*\{\s*html:\s*'([^']*)',\s*tex:\s*'((?:[^'\\\\]|\\\\.)*)',\s*desc:\s*'([^']*)'\s*\},?\s*$/m",
            $blocco,
            $righe,
            PREG_SET_ORDER
        );
        self::assertNotFalse($trovati);
        self::assertGreaterThan(
            0,
            $trovati,
            'nessuna voce riconosciuta dentro COL_TYPES: la forma del modulo è cambiata'
        );

        $tipi = [];
        foreach ($righe as $riga) {
            // Il valore è una stringa JavaScript fra apici singoli: `\\square`
            // nel sorgente vale `\square`.
            $tex = str_replace(['\\\\', "\\'"], ['\\', "'"], $riga[3]);
            $tipi[$riga[1]] = ['html' => $riga[2], 'tex' => $tex, 'desc' => $riga[4]];
        }

        return $tipi;
    }

    #[Test]
    public function iDueElenchiHannoGliStessiTipi(): void
    {
        $js = array_keys($this->tipiDelJavaScript());
        sort($js);
        $php = RmColumnTypes::TYPES;
        sort($php);

        self::assertSame(
            $php,
            $js,
            'i tipi di cella del JavaScript e quelli del PHP non coincidono: '
            . 'chi ne aggiunge uno deve toccare rm-table-view.js e RmColumnTypes.php insieme'
        );
    }

    #[Test]
    public function ogniTipoHaLoStessoInputHtmlELoStessoSimboloTex(): void
    {
        $js = $this->tipiDelJavaScript();
        $php = [];
        foreach (RmColumnTypes::all() as $voce) {
            $php[$voce['key']] = $voce;
        }

        foreach ($js as $tipo => $definizione) {
            self::assertArrayHasKey($tipo, $php, "il tipo «{$tipo}» esiste solo nel JavaScript");

            self::assertSame(
                $php[$tipo]['html_input'],
                $definizione['html'],
                "tipo «{$tipo}»: l'input HTML del JavaScript non è quello del PHP"
            );
            self::assertSame(
                $php[$tipo]['tex'],
                $definizione['tex'],
                "tipo «{$tipo}»: il simbolo TeX del JavaScript non è quello del PHP"
            );
            self::assertSame(
                $php[$tipo]['desc'],
                $definizione['desc'],
                "tipo «{$tipo}»: la descrizione del JavaScript non è quella del PHP"
            );
        }
    }

    #[Test]
    public function ilTipoSconosciutoDiventaCasellaInEntrambi(): void
    {
        // La regola del ripiego è la stessa nei due linguaggi: `normalizeColType`
        // in JavaScript e `normalize` in PHP tornano 'X'.
        self::assertSame('X', RmColumnTypes::normalize('Z'));
        self::assertSame('X', RmColumnTypes::normalize(null));

        $sorgente = (string)file_get_contents((string)realpath(self::MODULO_JS));
        self::assertMatchesRegularExpression(
            "/return\s+COL_TYPES\[t\]\s*\?\s*t\s*:\s*'X';/",
            $sorgente,
            'in rm-table-view.js il ripiego di normalizeColType non è più «X»'
        );
    }
}
