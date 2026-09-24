<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Repositories\Contract\ContractRepository;
use App\Repositories\TeacherContentRepository;
use App\Services\Contract\ContractRenderedTextException;
use App\Services\Contract\TestoDiPaginaResa;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La rete che impedisce a un pezzo di pagina resa di entrare nel contratto.
 *
 * Il guasto del 18 settembre 2026 (verifica 75): il serializzatore dell'editor
 * ha scritto nel contratto il riquadro d'errore TikZ e uno
 * `<script type="text/tikz">`, e la sorgente delle due figure è persa. Il
 * difetto del serializzatore è corretto nel JavaScript; qui si prova la
 * seconda rete, quella che vale anche per la prossima volta.
 *
 * Provata nei due versi, che è il punto: un controllo provato solo nel verso
 * «deve scattare» può essere una funzione che risponde sempre «no».
 */
final class TestoDiPaginaResaTest extends TestCase
{
    private InMemoryStorageProvider $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorageProvider();
    }

    /** Un contratto sano: testo, formula, e una figura con la sua sorgente. */
    private static function contrattoSano(): array
    {
        return [
            'version' => 1,
            '$schema' => ContractRepository::SCHEMA_ID,
            'groups' => [[
                'id' => 'g1',
                'items' => [[
                    'id' => '101',
                    'question' => [
                        ['type' => 'text', 'content' => 'Un sasso è lanciato orizzontalmente.'],
                        ['type' => 'latex', 'content' => '\\(v_y = g\\,t\\)'],
                    ],
                    'solution' => [
                        [
                            'type' => 'tikz',
                            'script' => "\\begin{tikzpicture}\n  \\draw[->] (0,0) -- (4,0);\n\\end{tikzpicture}",
                            'tex_packages' => '{"amsmath":""}',
                            'tikz_libs' => 'arrows.meta,calc',
                        ],
                        ['type' => 'text', 'content' => 'È un arco di parabola.'],
                    ],
                ]],
            ]],
        ];
    }

    /** I quattro modi in cui la pagina resa è tornata indietro davvero. */
    public static function pezziDiPaginaResa(): array
    {
        return [
            "il riquadro d'errore (verifica 75, esercizio 16)" => [
                "[TikZ render error]\nErrore di rete: [7] Failed to connect to 127.0.0.1 port 8001",
            ],
            'la classe del riquadro (verifica 962)' => [
                '<div class="fm-tikz-error-messages-block">niente</div>',
            ],
            'lo script serializzato (verifica 75, esercizio 14)' => [
                '<script nonce="" type="text/tikz" data-show-console="true">\\usepackage{tikz}</script>',
            ],
            'lo stesso script senza nonce' => [
                '<script type="text/tikz">\\begin{tikzpicture}\\end{tikzpicture}</script>',
            ],
            // Com'è davvero l'SVG che il client mette al posto dello <script>:
            // tikz-render-client.js righe 451-455.
            "l'SVG reso, con gli attributi del client" => [
                '<svg xmlns="http://www.w3.org/2000/svg" data-tikz-hash="386c2f" '
                . 'data-tikz-tagopen="%3Cscript%20type%3D%22text%2Ftikz%22%3E" '
                . 'data-tikz-body="%5Cdraw%20(0%2C0)"><path d="M0 0"/></svg>',
            ],
            // Lo stesso SVG a cui gli attributi si sono persi per strada: il
            // prefisso che renameSvgIds mette agli id interni resta.
            "l'SVG reso senza più gli attributi, con gli id di renameSvgIds" => [
                "<svg version='1.1' xmlns='http://www.w3.org/2000/svg' width='350.879pt'>"
                . "<path id='tk386c2f_0_g7-1' d='M2.9-6.3'/></svg>",
            ],
            'gli attributi del client' => [
                '<div data-tikz-body="%5Cdraw" data-tikz-hash="abc">figura</div>',
            ],
        ];
    }

    /**
     * IL VERSO CHE CONTA, per il marcatore dell'SVG.
     *
     * Il contenuto dei blocchi NON passa da `HtmlSanitizer` prima di essere
     * salvato — lo chiama `ContractRenderer` in fase di resa, non
     * `ContractRepository::save()` — quindi quello che il docente scrive
     * arriva alla guardia com'è. Se bastasse il tag `<svg`, un quesito di
     * Informatica che ne parla si vedrebbe rifiutare il salvataggio con un
     * messaggio che parla di figure TikZ, e ricaricare la pagina non
     * cambierebbe niente: il docente resterebbe fermo. Un controllo che
     * blocca il lavoro vero è peggio del guasto che evita.
     *
     * @return array<string, array{string}>
     */
    public static function svgScrittiDalDocente(): array
    {
        return [
            'un quesito che parla di <svg>' => [
                'Osserva questo codice HTML: <svg width="10" height="10"><circle r="4"/></svg> '
                . 'e disegna il rettangolo che lo contiene.',
            ],
            'un SVG legittimo scritto dal docente' => [
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
                . '<circle cx="50" cy="50" r="40" fill="none" stroke="black"/></svg>',
            ],
            'il tag nominato in mezzo al testo' => [
                'Il tag &lt;svg&gt; apre un disegno vettoriale; <svg> lo apre davvero.',
            ],
            'un SVG con gli id, ma non quelli del client' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><path id="contorno" d="M0 0"/></svg>',
            ],
        ];
    }

    #[Test]
    #[DataProvider('svgScrittiDalDocente')]
    public function unSvgScrittoDalDocenteNonLoFaScattare(string $testo): void
    {
        $contratto = self::contrattoSano();
        $contratto['groups'][0]['items'][0]['question'] = [['type' => 'text', 'content' => $testo]];

        self::assertSame([], TestoDiPaginaResa::trova($contratto),
            'la guardia ha rifiutato testo scritto dal docente: ' . substr($testo, 0, 60));
    }

    /**
     * E lo stesso al salvataggio, che è dove il docente lo sente: il quesito
     * che parla di `<svg>` si salva, e finisce sul disco com'è stato scritto.
     */
    #[Test]
    public function ilSalvataggioAccettaUnQuesitoCheParlaDiSvg(): void
    {
        $testo = 'Osserva questo codice HTML: <svg width="10" height="10"><circle r="4"/></svg> '
            . 'e disegna il rettangolo che lo contiene.';
        $repo = $this->repo(7, 77, self::contrattoSano());

        $agg = $repo->patchItem(7, 77, '101', ['question' => [['type' => 'text', 'content' => $testo]]]);

        self::assertSame(2, $agg->version());
        $scritto = json_decode((string)$this->storage->raw('c/test.json'), true);
        self::assertSame($testo, $scritto['groups'][0]['items'][0]['question'][0]['content']);
    }

    #[Test]
    #[DataProvider('pezziDiPaginaResa')]
    public function loRiconosce(string $pezzo): void
    {
        $contratto = self::contrattoSano();
        $contratto['groups'][0]['items'][0]['solution'] = [['type' => 'text', 'content' => $pezzo]];

        $trovati = TestoDiPaginaResa::trova($contratto);
        self::assertNotSame([], $trovati, 'nessun marcatore su: ' . substr($pezzo, 0, 60));
        self::assertStringContainsString('groups.0.items.0.solution.0.content', $trovati[0]['percorso']);
    }

    /**
     * IL VERSO CHE CONTA DI PIÙ. Un contratto sano deve passare: se qui
     * scattasse, il controllo sarebbe una funzione che dice sempre «no», e
     * dire sempre «no» non è un controllo.
     */
    #[Test]
    public function unContrattoSanoNonLoFaScattare(): void
    {
        self::assertSame([], TestoDiPaginaResa::trova(self::contrattoSano()));
    }

    /**
     * Un blocco `geogebra` porta il suo SVG per mestiere, insieme allo stato
     * `.ggb` che lo rigenera: quello non è pagina resa che sostituisce una
     * sorgente, è una sorgente con la sua anteprima accanto.
     */
    #[Test]
    public function lSvgDiUnBloccoGeogebraNonLoFaScattare(): void
    {
        $contratto = self::contrattoSano();
        $contratto['groups'][0]['items'][0]['solution'][] = [
            'type' => 'geogebra',
            'ggb_b64' => 'UEsDBBQ',
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><circle r="3"/></svg>',
            'label' => 'grafico',
        ];
        self::assertSame([], TestoDiPaginaResa::trova($contratto));
    }

    /** Un sorgente TeX resta un sorgente TeX anche se parla di `svg`. */
    #[Test]
    public function ilSorgenteDiUnBloccoTikzNonLoFaScattare(): void
    {
        $contratto = self::contrattoSano();
        $contratto['groups'][0]['items'][0]['solution'][0]['script'] =
            "\\begin{tikzpicture}\n  \\node {<svg> non è un tag qui};\n\\end{tikzpicture}";
        self::assertSame([], TestoDiPaginaResa::trova($contratto));
    }

    // ── la rete al salvataggio ───────────────────────────────────────────

    private function repo(int $contentId, int $teacherId, array $contratto, string $chiave = 'c/test.json'): ContractRepository
    {
        $this->storage->put($chiave, (string)json_encode($contratto));
        $stub = new class($contentId, $teacherId, $chiave) extends TeacherContentRepository {
            public array $metadata;
            public function __construct(
                private int $stubId,
                private int $stubTeacher,
                private string $stubKey,
            ) {
                $this->metadata = ['contract_key' => $stubKey];
            }
            public function find(int $id): ?array
            {
                if ($id !== $this->stubId) {
                    return null;
                }
                return [
                    'id' => $this->stubId,
                    'teacher_id' => $this->stubTeacher,
                    'metadata_json' => (string)json_encode($this->metadata),
                ];
            }
            public function update(int $id, int $teacherId, array $data): bool
            {
                if (isset($data['metadata'])) {
                    $this->metadata = $data['metadata'];
                }
                return true;
            }
        };
        return new ContractRepository($stub, $this->storage);
    }

    #[Test]
    public function ilSalvataggioRifiutaIlRiquadroDErrore(): void
    {
        $repo = $this->repo(7, 77, self::contrattoSano());
        $prima = $this->storage->raw('c/test.json');

        try {
            $repo->patchItem(7, 77, '101', ['solution' => [
                ['type' => 'text', 'content' => "[TikZ render error]\nErrore di rete: [7] Failed to connect"],
            ]]);
            self::fail('il salvataggio doveva essere rifiutato');
        } catch (ContractRenderedTextException $e) {
            self::assertStringContainsString('pezzi della pagina', $e->getMessage());
            self::assertStringContainsString("riquadro d'errore TikZ", $e->getMessage());
        }

        // E soprattutto: il contratto sul disco non è cambiato.
        self::assertSame($prima, $this->storage->raw('c/test.json'));
    }

    #[Test]
    public function ilSalvataggioRifiutaLoScriptConIlNonce(): void
    {
        $repo = $this->repo(7, 77, self::contrattoSano());
        $this->expectException(ContractRenderedTextException::class);
        $repo->patchItem(7, 77, '101', ['solution' => [
            ['type' => 'text', 'content' => '<script nonce="" type="text/tikz">\\usepackage{tikz}</script>'],
        ]]);
    }

    /** L'ALTRO VERSO: un salvataggio sano passa e finisce sul disco. */
    #[Test]
    public function ilSalvataggioSanoPassa(): void
    {
        $repo = $this->repo(7, 77, self::contrattoSano());
        $agg = $repo->patchItem(7, 77, '101', ['solution' => [
            [
                'type' => 'tikz',
                'script' => "\\begin{tikzpicture}\n  \\draw (0,0) circle (1);\n\\end{tikzpicture}",
                'tex_packages' => '{"amsmath":""}',
            ],
            ['type' => 'text', 'content' => 'La circonferenza ha raggio 1.'],
            ['type' => 'latex', 'content' => '\\(r = 1\\)'],
        ]]);

        self::assertSame(2, $agg->version());
        $scritto = json_decode($this->storage->raw('c/test.json'), true);
        $soluzione = $scritto['groups'][0]['items'][0]['solution'];
        self::assertSame('tikz', $soluzione[0]['type']);
        self::assertSame('{"amsmath":""}', $soluzione[0]['tex_packages']);
        self::assertSame('\\(r = 1\\)', $soluzione[2]['content']);
    }

    /**
     * Un contratto già rovinato resta modificabile: la rete guarda quello che
     * COMPARE adesso. Altrimenti i due contratti rovinati in produzione (75 e
     * 962) non si potrebbero più nemmeno riparare dall'editor.
     */
    #[Test]
    public function unContrattoGiaRovinatoRestaModificabile(): void
    {
        $rovinato = self::contrattoSano();
        $rovinato['groups'][0]['items'][0]['solution'] = [
            ['type' => 'text', 'content' => "[TikZ render error]\nErrore di rete"],
        ];
        $rovinato['groups'][0]['items'][] = ['id' => '102', 'question' => [
            ['type' => 'text', 'content' => 'Un quesito sano accanto.'],
        ]];
        $repo = $this->repo(7, 77, $rovinato);

        $agg = $repo->patchItem(7, 77, '102', ['question' => [
            ['type' => 'text', 'content' => 'Testo nuovo, sano.'],
        ]]);

        self::assertSame(2, $agg->version());
        $scritto = json_decode($this->storage->raw('c/test.json'), true);
        self::assertSame('Testo nuovo, sano.', $scritto['groups'][0]['items'][1]['question'][0]['content']);
    }

    // ── «Duplica in…», che non passa da save() ───────────────────────────

    /**
     * Due righe: il contenuto sorgente con il suo contratto, e la riga della
     * copia che il contratto non ce l'ha ancora.
     */
    private function repoPerLaCopia(array $contratto, string $titoloDellaCopia = 'La copia'): ContractRepository
    {
        $this->storage->put('c/sorgente.json', (string)json_encode($contratto));
        $stub = new class($titoloDellaCopia) extends TeacherContentRepository {
            /** @var array<int, array<string,mixed>> */
            public array $righe;
            public function __construct(string $titoloDellaCopia)
            {
                $this->righe = [
                    7 => ['id' => 7, 'teacher_id' => 77, 'content_type' => 'esercizio',
                          'subject_code' => 'MAT', 'title' => "L'originale",
                          'metadata_json' => '{"contract_key":"c/sorgente.json"}',
                          'metadata' => ['contract_key' => 'c/sorgente.json']],
                    8 => ['id' => 8, 'teacher_id' => 77, 'content_type' => 'esercizio',
                          'subject_code' => 'MAT', 'title' => $titoloDellaCopia,
                          'metadata_json' => '{}', 'metadata' => []],
                ];
            }
            public function find(int $id): ?array
            {
                return $this->righe[$id] ?? null;
            }
            public function update(int $id, int $teacherId, array $data): bool
            {
                if (isset($data['metadata']) && isset($this->righe[$id])) {
                    $this->righe[$id]['metadata'] = $data['metadata'];
                    $this->righe[$id]['metadata_json'] = (string)json_encode($data['metadata']);
                }
                return true;
            }
        };
        return new ContractRepository($stub, $this->storage);
    }

    /** La chiave che copiaPerNuovoContenuto() costruisce per la copia #8. */
    private const CHIAVE_COPIA = 'institutes/106/private/77/esercizi/MAT/copia-8.contract.json';

    /**
     * IL VERSO CHE CONTA: duplicare un contenuto il cui contratto è GIÀ
     * rovinato resta possibile.
     *
     * La copia non introduce niente — ripete quello che sta già sul disco — e
     * rifiutarla non salverebbe nessuna sorgente: quella è persa da prima.
     * Toglierebbe solo «Duplica in…» al docente, con un messaggio che gli dice
     * di ricaricare la pagina, cosa che non cambia niente.
     */
    #[Test]
    public function laCopiaDiUnContrattoGiaRovinatoSiFaLoStesso(): void
    {
        $rovinato = self::contrattoSano();
        $rovinato['groups'][0]['items'][0]['solution'] = [
            ['type' => 'text', 'content' => "[TikZ render error]\nErrore di rete"],
        ];
        $repo = $this->repoPerLaCopia($rovinato);

        $chiave = $repo->copiaPerNuovoContenuto(7, 8, 106);

        self::assertSame(self::CHIAVE_COPIA, $chiave);
        $scritto = json_decode((string)$this->storage->raw((string)$chiave), true);
        self::assertSame(0, $scritto['version']);
        self::assertSame(7, $scritto['_copiato_da']['contenuto']);
    }

    /**
     * L'ALTRO VERSO: quello che la copia AGGIUNGE al sorgente passa dalla
     * guardia come ogni altra scrittura. Qui il titolo della riga nuova porta
     * il riquadro d'errore: la copia si rifiuta e non tocca il disco.
     *
     * Prima della correzione questa scrittura andava dritta a
     * `storage->put()`, senza guardia e senza registro.
     */
    #[Test]
    public function laCopiaRifiutaQuelloCheAggiungeLei(): void
    {
        $repo = $this->repoPerLaCopia(self::contrattoSano(), "[TikZ render error]\nErrore di rete");

        try {
            $repo->copiaPerNuovoContenuto(7, 8, 106);
            self::fail('la copia doveva essere rifiutata');
        } catch (ContractRenderedTextException $e) {
            self::assertStringContainsString("riquadro d'errore TikZ", $e->getMessage());
        }

        self::assertNull($this->storage->raw(self::CHIAVE_COPIA), 'la copia non deve essere sul disco');
    }
}
