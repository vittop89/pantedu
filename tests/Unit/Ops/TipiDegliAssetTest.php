<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ogni file che il sito serve deve arrivare col suo tipo.
 *
 * Segnalazione dell'utente (21/9/2026): il pulsante TEX/PDF si inchiodava, e
 * al secondo tentativo diceva «Setting up fake worker failed: Failed to fetch
 * dynamically imported module … pdf.worker.min.*.mjs». Il file c'era e
 * rispondeva 200: era il **tipo** a non andare bene. `.mjs` non sta nella
 * tabella dei tipi dell'immagine, finiva nel predefinito
 * (`application/octet-stream`), e un `import()` dinamico lo rifiuta per regola
 * del browser. È il modo peggiore di rompersi: sembra un problema di rete, e
 * il file è lì.
 *
 * Questa prova guarda la configurazione, non una risposta vera: il tipo lo
 * assegna nginx dentro l'immagine, e qui non c'è né l'uno né l'altra. Guarda
 * però la cosa giusta — **le estensioni che il build produce davvero** — così
 * il giorno che ne comparirà una nuova che nginx non conosce, lo dice prima
 * che a dirlo sia una persona.
 */
final class TipiDegliAssetTest extends TestCase
{
    /**
     * Le estensioni che la tabella dei tipi di nginx conosce già, e che quindi
     * non hanno bisogno di una riga nostra. Sono quelle della `mime.types` di
     * Debian: se una sparisse di lì, il sintomo sarebbe lo stesso e la riga
     * andrebbe aggiunta come per `.mjs`.
     */
    private const GIA_NOTE = [
        'js', 'css', 'json', 'map', 'html', 'svg', 'png', 'jpg', 'jpeg', 'gif',
        'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'otf', 'txt', 'xml', 'pdf',
        'wasm', 'mp3', 'mp4', 'webm', 'zip',
    ];

    private function configurazione(): string
    {
        $p = \dirname(__DIR__, 3) . '/docker/nginx.conf';
        self::assertFileExists($p, 'la configurazione di nginx dell\'immagine');
        return (string)file_get_contents($p);
    }

    /** Le estensioni dichiarate in un blocco `types { … }` della nostra configurazione. */
    private function estensioniDichiarate(): array
    {
        $conf = $this->configurazione();
        $trovate = [];
        if (preg_match_all('/types\s*\{(.*?)\}/s', $conf, $blocchi)) {
            foreach ($blocchi[1] as $blocco) {
                foreach (preg_split('/;/', $blocco) ?: [] as $riga) {
                    $pezzi = preg_split('/\s+/', trim($riga)) ?: [];
                    $tipo = array_shift($pezzi);
                    foreach ($pezzi as $ext) {
                        if ($ext !== '') {
                            $trovate[strtolower($ext)] = (string)$tipo;
                        }
                    }
                }
            }
        }
        return $trovate;
    }

    #[Test]
    public function il_modulo_del_lettore_pdf_ha_un_tipo_javascript(): void
    {
        $tipi = $this->estensioniDichiarate();

        self::assertArrayHasKey('mjs', $tipi, 'senza questa riga `.mjs` prende il tipo predefinito');
        self::assertMatchesRegularExpression(
            '~^(text|application)/javascript$~',
            $tipi['mjs'],
            'un import() dinamico vuole un tipo JavaScript',
        );
    }

    #[Test]
    public function il_tipo_predefinito_e_ancora_quello_che_rende_necessaria_la_riga(): void
    {
        // Se un domani il predefinito diventasse un tipo JavaScript, la riga
        // qui sopra smetterebbe di essere necessaria — e questa prova lo
        // direbbe, invece di lasciarla lì per sempre senza sapere perché.
        self::assertStringContainsString(
            'default_type  application/octet-stream;',
            $this->configurazione(),
        );
    }

    #[Test]
    public function ogni_estensione_che_il_build_produce_ha_un_tipo(): void
    {
        // Niente `markTestSkipped`: qui un salto vale come un fallimento (lo
        // dice il lavoro «PHPUnit, unit e integrazione, ogni salto è un
        // fallimento»), e ha ragione — una prova che tace non si distingue da
        // una che guarda. Dove il build c'è — la macchina di chi sviluppa e il
        // lavoro del front-end — si guardano le estensioni vere; dove non c'è,
        // resta il caso qui sopra, che misura la regola.
        $cartella = \dirname(__DIR__, 3) . '/public/build/assets';
        $dichiarate = $this->estensioniDichiarate();
        $senzaTipo = [];
        $guardate = 0;
        foreach ((array)@scandir($cartella) ?: [] as $nome) {
            $ext = strtolower(pathinfo((string)$nome, PATHINFO_EXTENSION));
            if ($ext === '') {
                continue;
            }
            $guardate++;
            if (!\in_array($ext, self::GIA_NOTE, true) && !isset($dichiarate[$ext])) {
                $senzaTipo[$ext] = true;
            }
        }

        self::assertSame([], array_keys($senzaTipo),
            "estensioni servite senza un tipo (guardati $guardate file): finirebbero in application/octet-stream");
    }
}
