<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Controllers\FileController;
use App\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il vecchio editor TikZ salvava gli SVG su disco, e quelle rotte non ci sono
 * più.
 *
 * Il giro era: l'editor compilava il TikZ, serializzava l'SVG dal DOM e lo
 * spediva a `POST /files/save-image`, che lo scriveva sotto la radice
 * pubblica; alla riapertura lo rileggeva da lì. Partiva da
 * `svg[data-tikz-script-id]`, e quell'attributo lo scriveva solo il codice
 * legacy stesso: il disegnatore in servizio
 * (`js/modules/editor/tikz-render-client.js`) mette `data-tikz-hash`,
 * `data-tikz-source`, `data-tikz-srckey`, `data-tikz-tagopen`,
 * `data-tikz-body`, e la cache degli SVG vive in `/tikz/render`. Il giro si
 * chiudeva su se stesso.
 *
 * `POST /files/save-tex` se n'è andato con lui: scriveva un `.tex` in
 * `temp/`, e in tutto il codice non lo chiamava nessuno — nemmeno
 * `Endpoints.files.saveTex`, che era una voce senza lettori.
 *
 * Questa prova guarda la tabella delle rotte, che è dove la cosa è decisa, e
 * il controller. Misura **nei due versi**: le due rotte non devono esserci, e
 * le altre della stessa famiglia — quelle in servizio — devono esserci. Se
 * sbagliassi il modo di leggere le rotte, il secondo verso lo direbbe subito,
 * invece di lasciar passare un «non c'è» che vale per tutte.
 */
final class SalvataggiDelVecchioEditorTest extends TestCase
{
    /** Le rotte tolte: nessun chiamante, e in produzione nessuna richiesta. */
    private const TOLTE = [
        '/files/save-image',
        '/files/save-tex',
    ];

    /**
     * La stessa famiglia, ancora in servizio. Vale da controllo positivo: se
     * la lettura delle rotte non misurasse niente, anche queste mancherebbero.
     */
    private const IN_SERVIZIO = [
        '/files/save-latex',
        '/files/save-pdf',
        '/files/delete',
        '/files/delete-folder',
        '/files/clear-temp',
        '/files/list',
    ];

    /** @return list<string> i percorsi dichiarati in routes/web.php */
    private function percorsi(): array
    {
        $router = new Router();
        require \dirname(__DIR__, 3) . '/routes/web.php';

        $fuori = [];
        foreach ($router->routes() as $rotta) {
            $fuori[] = $rotta->pattern;
        }
        return array_values(array_unique($fuori));
    }

    #[Test]
    public function le_rotte_del_vecchio_editor_non_sono_piu_dichiarate(): void
    {
        $percorsi = $this->percorsi();

        $rimaste = array_values(array_intersect(self::TOLTE, $percorsi));
        $this->assertSame([], $rimaste, sprintf(
            "%d rotte del vecchio salvataggio su disco sono tornate in routes/web.php: %s.\n\n"
            . "Non le chiama nessuno: l'unico ramo che ci arrivava stava in "
            . "js/modules/editor/content-processor.js e partiva da "
            . "svg[data-tikz-script-id], un attributo che scriveva solo lui. "
            . "Il salvataggio degli SVG in servizio passa da /tikz/save-svg e "
            . "dalla cache di /tikz/render.",
            \count($rimaste),
            implode(', ', $rimaste)
        ));
    }

    #[Test]
    public function le_rotte_dei_file_in_servizio_ci_sono_ancora(): void
    {
        $percorsi = $this->percorsi();

        $mancanti = array_values(array_diff(self::IN_SERVIZIO, $percorsi));
        $this->assertSame([], $mancanti, sprintf(
            "%d rotte della famiglia /files/ non risultano dichiarate: %s.\n\n"
            . "O sono state tolte per sbaglio insieme al giro morto, o questa "
            . "prova non sta leggendo le rotte — e allora nemmeno l'altra metà "
            . "misura qualcosa.",
            \count($mancanti),
            implode(', ', $mancanti)
        ));
    }

    /**
     * I metodi pubblici del controller dei file.
     *
     * Via riflessione e non con `method_exists()`: con il nome della classe
     * scritto a mano PHPStan sa già la risposta e si ferma prima
     * («impossibleType»), che è un altro modo di non misurare niente.
     *
     * @return list<string>
     */
    private function metodiDelControllerDeiFile(): array
    {
        $metodi = [];
        foreach ((new \ReflectionClass(FileController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $metodo) {
            $metodi[] = $metodo->getName();
        }
        sort($metodi);
        return $metodi;
    }

    #[Test]
    public function il_controller_dei_file_non_ha_piu_quei_metodi(): void
    {
        $metodi = $this->metodiDelControllerDeiFile();

        $this->assertNotContains('saveImage', $metodi, 'FileController::saveImage è tornato: '
            . 'era il ramo del vecchio editor, scriveva sotto la radice pubblica del '
            . 'container (immutabile) e nessuna rotta serviva i file che produceva.');
        $this->assertNotContains('saveTex', $metodi, 'FileController::saveTex è tornato: '
            . 'nessun chiamante, e il salvataggio dei sorgenti TeX in servizio passa da '
            . '/api/verifica/save-tex e dagli endpoint tex-files/save.');

        // Controllo positivo, come sopra: i metodi che restano ci sono.
        foreach (['saveLatex', 'savePdf', 'deleteFile', 'deleteFolder', 'clearTemp', 'list'] as $metodo) {
            $this->assertContains(
                $metodo,
                $metodi,
                "FileController::$metodo non c'è più: se è voluto va tolta anche la sua rotta."
            );
        }
    }
}
