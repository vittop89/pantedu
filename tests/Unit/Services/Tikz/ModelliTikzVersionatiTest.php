<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Tikz;

use App\Services\Tikz\ModelliTikzVersionati;
use App\Services\TikzElementsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * I modelli TikZ versionati e la biblioteca dell'istanza (ADR-050, 24/9/2026).
 *
 * Le quattro azioni, ognuna nei due versi: si crea quel che manca e non
 * quel che c'è; si sostituisce una versione dichiarata superata e **non**
 * una cambiata dal pannello; a secco non si scrive niente; un secondo giro
 * non trova più niente da fare. Più il manifesto vero del repository: file
 * presenti, impronte ben formate, nessuna voce doppia.
 */
final class ModelliTikzVersionatiTest extends TestCase
{
    private string $dati;
    private string $versionati;
    private TikzElementsService $biblioteca;

    private const VECCHIO = "\\begin{tikzpicture}\\draw (0,0) -- (2,0);\\end{tikzpicture}";
    private const NUOVO   = "\\begin{tikzpicture}\n\\draw[->] (0,0) -- (3,0);\n\\end{tikzpicture}\n";

    protected function setUp(): void
    {
        $radice = sys_get_temp_dir() . '/pantedu_modelli_versionati_' . uniqid();
        $this->dati = $radice . '/dati';
        $this->versionati = $radice . '/versionati';
        mkdir($this->dati . '/storage/data', 0755, true);
        mkdir($this->versionati . '/fisica', 0755, true);
        file_put_contents($this->versionati . '/fisica/cinematica.tex', self::NUOVO);
        file_put_contents($this->versionati . '/fisica/nuovo.tex', "\\draw (0,0) circle (1);\n");
        $this->manifesto([
            ['gruppo' => 'gruppo-FISICA', 'etichetta' => 'cinematica 1D', 'tipo' => 'tikz', 'file' => 'fisica/cinematica.tex',
             'sostituisce' => [ModelliTikzVersionati::impronta(self::VECCHIO)]],
            ['gruppo' => 'gruppo-FISICA', 'etichetta' => 'Cerchio', 'tipo' => 'tikz', 'file' => 'fisica/nuovo.tex'],
        ]);
        $this->biblioteca = new TikzElementsService($this->dati);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname($this->dati), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir(\dirname($this->dati));
    }

    /** @param list<array<string, mixed>> $modelli */
    private function manifesto(array $modelli): void
    {
        file_put_contents($this->versionati . '/modelli.json', json_encode(['modelli' => $modelli]));
    }

    /** @param array<string, list<array<string, string>>> $indice */
    private function istanza(array $indice): void
    {
        file_put_contents($this->dati . '/storage/data/modelli_tikz_elements.json', json_encode($indice));
    }

    private function modelli(): ModelliTikzVersionati
    {
        return new ModelliTikzVersionati($this->biblioteca, $this->versionati);
    }

    /** @return array<string, string> etichetta => azione */
    private static function azioni(array $righe): array
    {
        return array_column($righe, 'azione', 'etichetta');
    }

    private function contenuto(string $gruppo, string $etichetta): ?string
    {
        foreach ($this->biblioteca->readIndex()[$gruppo] ?? [] as $el) {
            if ($el['label'] === $etichetta) {
                return (string) $el['content'];
            }
        }
        return null;
    }

    #[Test]
    public function la_versione_superata_si_aggiorna_e_quella_che_manca_si_crea(): void
    {
        $this->istanza(['gruppo-FISICA' => [
            ['label' => 'cinematica 1D', 'content' => self::VECCHIO, 'type' => 'tikz'],
            ['label' => 'Dati problema', 'content' => 'v_0', 'type' => 'latex'],
        ]]);

        $fatto = $this->modelli()->applica();

        self::assertSame(['cinematica 1D' => 'aggiorna', 'Cerchio' => 'crea'], self::azioni($fatto));
        self::assertSame(self::NUOVO, $this->contenuto('gruppo-FISICA', 'cinematica 1D'));
        self::assertSame("\\draw (0,0) circle (1);\n", $this->contenuto('gruppo-FISICA', 'Cerchio'));
        self::assertSame('v_0', $this->contenuto('gruppo-FISICA', 'Dati problema'), 'un modello fuori dal manifesto non si tocca');
    }

    #[Test]
    public function un_modello_cambiato_dal_pannello_non_si_tocca(): void
    {
        $delPannello = "\\begin{tikzpicture}\\draw (0,0) -- (5,5);\\end{tikzpicture}";
        $this->istanza(['gruppo-FISICA' => [['label' => 'cinematica 1D', 'content' => $delPannello, 'type' => 'tikz']]]);

        $fatto = $this->modelli()->applica();

        self::assertSame('modificato', self::azioni($fatto)['cinematica 1D']);
        self::assertSame($delPannello, $this->contenuto('gruppo-FISICA', 'cinematica 1D'));
    }

    #[Test]
    public function a_capo_di_windows_e_spazi_finali_non_fanno_una_versione_diversa(): void
    {
        // Il pannello e i browser possono cambiare \r\n e l'ultimo a capo: la
        // versione superata va riconosciuta lo stesso, e quella nuova pure.
        $this->istanza(['gruppo-FISICA' => [
            ['label' => 'cinematica 1D', 'content' => str_replace("\n", "\r\n", self::NUOVO) . "\r\n\r\n", 'type' => 'tikz'],
        ]]);
        self::assertSame('allineato', self::azioni($this->modelli()->piano())['cinematica 1D']);

        $this->istanza(['gruppo-FISICA' => [['label' => 'cinematica 1D', 'content' => self::VECCHIO . "\n", 'type' => 'tikz']]]);
        self::assertSame('aggiorna', self::azioni($this->modelli()->piano())['cinematica 1D']);
    }

    #[Test]
    public function a_secco_non_scrive_e_il_secondo_giro_non_ha_niente_da_fare(): void
    {
        $this->istanza(['gruppo-FISICA' => [['label' => 'cinematica 1D', 'content' => self::VECCHIO, 'type' => 'tikz']]]);
        $file = $this->dati . '/storage/data/modelli_tikz_elements.json';
        $prima = (string) file_get_contents($file);

        self::assertSame(['cinematica 1D' => 'aggiorna', 'Cerchio' => 'crea'], self::azioni($this->modelli()->piano()));
        self::assertSame($prima, (string) file_get_contents($file), 'il piano non scrive');

        $this->modelli()->applica();
        self::assertSame(['cinematica 1D' => 'allineato', 'Cerchio' => 'allineato'], self::azioni($this->modelli()->applica()));
    }

    #[Test]
    public function un_istanza_nuova_senza_biblioteca_riceve_tutti_i_modelli(): void
    {
        self::assertSame(['cinematica 1D' => 'crea', 'Cerchio' => 'crea'], self::azioni($this->modelli()->applica()));
        self::assertSame(self::NUOVO, $this->contenuto('gruppo-FISICA', 'cinematica 1D'));
    }

    #[Test]
    public function un_manifesto_sbagliato_si_rifiuta_prima_di_scrivere(): void
    {
        $this->istanza(['gruppo-FISICA' => [['label' => 'cinematica 1D', 'content' => self::VECCHIO, 'type' => 'tikz']]]);
        $casi = [
            'file che non c\'è'   => [['gruppo' => 'gruppo-FISICA', 'etichetta' => 'X', 'tipo' => 'tikz', 'file' => 'fisica/manca.tex']],
            'fuori dalla cartella' => [['gruppo' => 'gruppo-FISICA', 'etichetta' => 'X', 'tipo' => 'tikz', 'file' => '../fuori.tex']],
            'impronta storta'      => [['gruppo' => 'gruppo-FISICA', 'etichetta' => 'X', 'tipo' => 'tikz', 'file' => 'fisica/nuovo.tex', 'sostituisce' => ['abc']]],
            'gruppo senza prefisso' => [['gruppo' => 'FISICA', 'etichetta' => 'X', 'tipo' => 'tikz', 'file' => 'fisica/nuovo.tex']],
            'voce doppia'          => [
                ['gruppo' => 'gruppo-FISICA', 'etichetta' => 'X', 'tipo' => 'tikz', 'file' => 'fisica/nuovo.tex'],
                ['gruppo' => 'gruppo-FISICA', 'etichetta' => 'x', 'tipo' => 'tikz', 'file' => 'fisica/cinematica.tex'],
            ],
        ];
        foreach ($casi as $caso => $modelli) {
            $this->manifesto($modelli);
            try {
                $this->modelli()->applica();
                self::fail("manifesto accettato: $caso");
            } catch (RuntimeException $e) {
                self::assertStringStartsWith('manifesto_', $e->getMessage(), $caso);
            }
            self::assertSame(self::VECCHIO, $this->contenuto('gruppo-FISICA', 'cinematica 1D'), "niente scritto: $caso");
        }
    }

    #[Test]
    public function il_manifesto_del_repository_si_legge_tutto(): void
    {
        // Quello vero, in storage/templates/tikz: se un file manca o
        // un'impronta è storta, il rilascio fallirebbe il passo 8-quater.
        $modelli = (new ModelliTikzVersionati($this->biblioteca, \dirname(__DIR__, 4) . '/' . ModelliTikzVersionati::CARTELLA_REL))->modelli();
        self::assertNotEmpty($modelli);
        foreach ($modelli as $m) {
            self::assertNotContains(
                ModelliTikzVersionati::impronta($m['contenuto']),
                $m['sostituisce'],
                "{$m['file']}: la versione attuale non può essere anche «superata»",
            );
            if ($m['tipo'] === 'tikz') {
                self::assertStringContainsString('\\begin{tikzpicture}', $m['contenuto'], $m['file']);
            }
        }
    }
}
