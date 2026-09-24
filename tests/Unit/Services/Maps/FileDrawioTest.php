<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Maps;

use App\Services\Maps\FileDrawio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il controllo del contenuto di una mappa caricata (23/9/2026, revisione
 * architetturale A-3, R-3 passo 2), nei due versi.
 *
 * Scatta: una pagina HTML (anche con `<mxfile` in un commento in testa, che il
 * vecchio controllo sui primi 256 byte lasciava passare come .drawio), un PDF,
 * un PNG, un XML con DOCTYPE o entità, un XML rotto, un altro XML. Ognuno con
 * il suo motivo, perché il rifiuto venga dalla regola giusta e non da una
 * qualsiasi.
 *
 * Non scatta: i file che drawio scrive davvero (il file intero, il solo
 * modello, la pagina compressa, la dichiarazione XML con il BOM, etichette
 * HTML codificate con lettere accentate, un'immagine incorporata di 11 MB in
 * un attributo). Le 192 mappe della copia locale, lette il 23/9/2026, passano
 * tutte (nessuna ha una dichiarazione XML).
 *
 * E nessun DTD o entità esterna si carica mai, in nessuna codifica: lo guarda
 * un caricatore di entità di prova (libxml_set_external_entity_loader), che
 * annota ogni tentativo e non carica niente.
 */
final class FileDrawioTest extends TestCase
{
    /** Il drawio delle spec end-to-end (creazione-e-modifica-per-sidepage.spec.js). */
    private const DRAWIO = '<mxfile host="e2e"><diagram id="d" name="Pagina"><mxGraphModel><root>'
        . '<mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel></diagram></mxfile>';

    /** @return iterable<string, array{string}> */
    public static function fileDiDrawio(): iterable
    {
        yield 'il file intero' => [self::DRAWIO];
        yield 'il solo modello' => ['<mxGraphModel dx="1" dy="1"><root><mxCell id="0"/></root></mxGraphModel>'];
        yield 'con la dichiarazione XML e il BOM' => [
            "\xEF\xBB\xBF" . '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . self::DRAWIO,
        ];
        yield 'una pagina compressa' => [
            '<mxfile host="app.diagrams.net" modified="2026-09-23T08:00:00.000Z" version="24.7.8">'
            . '<diagram id="d" name="Pagina-1">' . base64_encode((string)gzdeflate('<mxGraphModel/>'))
            . '</diagram></mxfile>',
        ];
        yield 'la dichiarazione con apici semplici e utf-8 minuscolo' => [
            "<?xml version='1.0' encoding='utf-8' standalone='yes'?>" . self::DRAWIO,
        ];
        yield 'la dichiarazione senza codifica' => ['<?xml version="1.0"?>' . "\n" . self::DRAWIO];
        // Un'immagine incorporata nello style della cella: senza
        // LIBXML_PARSEHUGE libxml rifiuta un attributo sopra i 10 MB.
        yield "un'immagine di 11 MB in un attributo" => [
            '<mxfile host="e2e"><diagram id="d" name="Pagina"><mxGraphModel><root><mxCell id="0"/>'
            . '<mxCell id="2" style="shape=image;image=data:image/png,' . str_repeat('A', 11 * 1024 * 1024)
            . ';" vertex="1" parent="0"/></root></mxGraphModel></diagram></mxfile>',
        ];
        yield 'etichette HTML codificate, con accenti' => [
            '<mxfile host="e2e"><diagram id="d" name="Cinematica"><mxGraphModel><root><mxCell id="0"/>'
            . '<mxCell id="2" value="&lt;b&gt;Velocità&lt;/b&gt; &amp; «moto»" style="html=1;" vertex="1" parent="0"/>'
            . '</root></mxGraphModel></diagram></mxfile>',
        ];
    }

    #[Test]
    #[DataProvider('fileDiDrawio')]
    public function un_file_di_drawio_passa(string $contenuto): void
    {
        self::assertNull(FileDrawio::motivoDelRifiuto($contenuto));
        self::assertTrue(FileDrawio::valido($contenuto));
    }

    /** @return iterable<string, array{string, string}> */
    public static function fileCheNonSonoDrawio(): iterable
    {
        yield 'vuoto' => ['', FileDrawio::VUOTO];
        yield 'solo spazi' => ["  \n\t", FileDrawio::VUOTO];
        yield 'un PDF' => ["%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj", FileDrawio::NON_UTF8];
        yield 'un PNG' => ["\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR", FileDrawio::NON_UTF8];
        // In UTF-16 ogni carattere ASCII ha un byte nullo accanto: i byte sono
        // anche UTF-8 valido, e libxml, con la dichiarazione, lo legge come un
        // drawio. Nella pagina arriverebbe un testo pieno di byte nulli, e un
        // DOCTYPE non si vedrebbe nei byte.
        yield 'un drawio in UTF-16' => [
            (string)mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>' . self::DRAWIO, 'UTF-16LE', 'UTF-8'),
            FileDrawio::NON_UTF8,
        ];
        yield 'XXE' => [
            '<?xml version="1.0"?><!DOCTYPE mxfile [<!ENTITY x SYSTEM "file:///etc/passwd">]><mxfile>&x;</mxfile>',
            FileDrawio::CON_DTD,
        ];
        // Dieci alla nona: il DOCTYPE si ferma prima di leggerlo. Senza quel
        // controllo, con LIBXML_PARSEHUGE, il libxml di questa macchina la ferma
        // con un altro motivo («amplification factor exceeded»), quello
        // dell'immagine del rilascio no (FileDrawio): il caso prova che il
        // controllo sui byte c'è.
        $entita = '<!ENTITY l0 "ha">';
        for ($i = 1; $i <= 9; $i++) {
            $entita .= '<!ENTITY l' . $i . ' "' . str_repeat('&l' . ($i - 1) . ';', 10) . '">';
        }
        yield 'moltiplicazione delle entità' => [
            '<!DOCTYPE mxfile [' . $entita . ']><mxfile>&l9;</mxfile>', FileDrawio::CON_DTD,
        ];
        yield 'DOCTYPE scritto in minuscolo' => ['<!doctype mxfile><mxfile/>', FileDrawio::CON_DTD];
        yield 'un drawio troncato' => ['<mxfile host="x"><diagram id="d">', FileDrawio::XML_NON_VALIDO];
        yield 'HTML che non è XML' => ['<html><body><br></body></html>', FileDrawio::XML_NON_VALIDO];
        yield 'una pagina HTML con uno script' => [
            '<html><body><script>alert(1)</script></body></html>', FileDrawio::RADICE_SBAGLIATA,
        ];
        // Il caso che passava come .drawio: `<mxfile` nei primi 256 byte.
        yield 'HTML con <mxfile in un commento in testa' => [
            '<!-- <mxfile --><html><body><script>alert(1)</script></body></html>', FileDrawio::RADICE_SBAGLIATA,
        ];
        yield 'un SVG' => [
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', FileDrawio::RADICE_SBAGLIATA,
        ];
        yield 'mxfile solo in un attributo' => ['<html lang="mxfile"><body/></html>', FileDrawio::RADICE_SBAGLIATA];
    }

    #[Test]
    #[DataProvider('fileCheNonSonoDrawio')]
    public function cio_che_non_e_un_drawio_si_rifiuta_con_il_suo_motivo(string $contenuto, string $motivo): void
    {
        self::assertSame($motivo, FileDrawio::motivoDelRifiuto($contenuto));
        self::assertFalse(FileDrawio::valido($contenuto));
    }

    /**
     * Una dichiarazione XML che nomina un'altra codifica. Con UTF-7 libxml
     * legge `+ADw-!DOCTYPE` come `<!DOCTYPE`, e il controllo sui byte non lo
     * vede: con LIBXML_PARSEHUGE, nell'immagine del rilascio, un DTD così
     * moltiplica le entità senza freno (FileDrawio). Tutte le forme che libxml
     * segue (misurate il 23/9/2026), più l'ISO-8859-1, si fermano sui byte.
     *
     * @return iterable<string, array{string}>
     */
    public static function dichiarazioniDiUnAltraCodifica(): iterable
    {
        $corpo = (string)mb_convert_encoding('<!DOCTYPE mxfile [<!ENTITY x "y">]><mxfile a="&x;"/>', 'UTF-7', 'UTF-8');
        yield 'UTF-7' => ['<?xml version="1.0" encoding="UTF-7"?>' . $corpo];
        yield 'UTF-7 fra apici semplici' => ["<?xml version='1.0' encoding='UTF-7'?>" . $corpo];
        yield 'utf-7 con gli spazi' => ['<?xml version = "1.0" encoding = "utf-7" ?>' . $corpo];
        yield 'UTF-7 dopo il BOM' => ["\xEF\xBB\xBF" . '<?xml version="1.0" encoding="UTF-7"?>' . $corpo];
        yield 'UTF-7 con tabulazioni e a capo' => ["<?xml\tversion=\"1.0\"\nencoding=\"UTF-7\"\r\n?>" . $corpo];
        yield 'UTF-7 senza versione' => ['<?xml encoding="UTF-7"?>' . $corpo];
        yield 'un altro nome di UTF-7' => ['<?xml version="1.0" encoding="UNICODE-1-1-UTF-7"?>' . $corpo];
        yield 'ISO-8859-1' => ['<?xml version="1.0" encoding="ISO-8859-1"?>' . self::DRAWIO];
        yield 'UTF-8 e poi UTF-7' => ['<?xml version="1.0" encoding="UTF-8" encoding="UTF-7"?>' . $corpo];
    }

    #[Test]
    #[DataProvider('dichiarazioniDiUnAltraCodifica')]
    public function una_dichiarazione_di_un_altra_codifica_si_ferma_sui_byte(string $contenuto): void
    {
        self::assertStringNotContainsStringIgnoringCase('<!DOCTYPE', $contenuto);
        self::assertSame(FileDrawio::NON_UTF8, FileDrawio::motivoDelRifiuto($contenuto));
    }

    /**
     * Un DTD esterno, o un'entità esterna, in tutte le codifiche da cui può
     * arrivare: nei byte (UTF-8), nascosto in UTF-7, in UTF-16. Si rifiuta, e
     * non si carica niente: né il file locale né l'indirizzo, che non risponde
     * (la porta 9 di 127.0.0.1).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function dtdEsterni(): iterable
    {
        $dtd = self::fileLocale();
        $sistema = '<!DOCTYPE mxfile SYSTEM "' . $dtd . '"><mxfile/>';
        $rete = '<!DOCTYPE mxfile SYSTEM "http://127.0.0.1:9/x.dtd"><mxfile/>';
        $entita = '<!DOCTYPE mxfile [<!ENTITY x SYSTEM "' . $dtd . '">]><mxfile>&x;</mxfile>';
        $utf7 = static fn(string $s): string => '<?xml version="1.0" encoding="UTF-7"?>'
            . mb_convert_encoding($s, 'UTF-7', 'UTF-8');
        $utf16 = static fn(string $s): string => (string)mb_convert_encoding(
            '<?xml version="1.0" encoding="UTF-16"?>' . $s,
            'UTF-16LE',
            'UTF-8'
        );
        yield 'DTD locale, UTF-8' => [$sistema, FileDrawio::CON_DTD];
        yield 'DTD in rete, UTF-8' => [$rete, FileDrawio::CON_DTD];
        yield 'entità esterna, UTF-8' => [$entita, FileDrawio::CON_DTD];
        yield 'DTD locale, UTF-7' => [$utf7($sistema), FileDrawio::NON_UTF8];
        yield 'DTD in rete, UTF-7' => [$utf7($rete), FileDrawio::NON_UTF8];
        yield 'entità esterna, UTF-7' => [$utf7($entita), FileDrawio::NON_UTF8];
        yield 'DTD locale, UTF-16' => [$utf16($sistema), FileDrawio::NON_UTF8];
        yield 'DTD in rete, UTF-16' => [$utf16($rete), FileDrawio::NON_UTF8];
    }

    #[Test]
    #[DataProvider('dtdEsterni')]
    public function un_dtd_esterno_si_rifiuta_senza_caricarlo(string $contenuto, string $motivo): void
    {
        $caricati = self::caricatiDurante(static function () use ($contenuto, $motivo): void {
            self::assertSame($motivo, FileDrawio::motivoDelRifiuto($contenuto));
        });

        self::assertSame([], $caricati, 'nessun DTD né entità esterna caricati');
    }

    /**
     * Lo stesso, un passo più dentro: il documento come lo legge libxml, con
     * il DOCTYPE che i controlli sui byte fermano prima. È il solo modo di
     * guardare le opzioni di libxml: con LIBXML_DTDLOAD, LIBXML_NOENT,
     * LIBXML_DTDATTR o LIBXML_DTDVALID il caricatore riceve una richiesta (e
     * senza LIBXML_NONET una richiesta http partirebbe davvero dal server).
     *
     * @return iterable<string, array{string}>
     */
    public static function documentiConDtdEsterno(): iterable
    {
        $locale = self::fileLocale();
        yield 'DTD locale' => ['<!DOCTYPE mxfile SYSTEM "' . $locale . '"><mxfile/>'];
        yield 'DTD in rete' => ['<!DOCTYPE mxfile SYSTEM "http://127.0.0.1:9/x.dtd"><mxfile/>'];
        yield 'entità esterna nel testo' => [
            '<!DOCTYPE mxfile [<!ENTITY x SYSTEM "' . $locale . '">]><mxfile>&x;</mxfile>',
        ];
        yield 'entità parametro esterna' => [
            '<!DOCTYPE mxfile [<!ENTITY % p SYSTEM "' . $locale . '"> %p;]><mxfile/>',
        ];
    }

    #[Test]
    #[DataProvider('documentiConDtdEsterno')]
    public function libxml_non_carica_dtd_ne_entita_esterne(string $documento): void
    {
        // La sonda vede davvero un caricamento: con LIBXML_DTDLOAD lo annota.
        $vistiDallaSonda = self::caricatiDurante(static function () use ($documento): void {
            $prima = libxml_use_internal_errors(true);
            (new \DOMDocument())->loadXML($documento, LIBXML_DTDLOAD | LIBXML_NOENT);
            libxml_clear_errors();
            libxml_use_internal_errors($prima);
        });
        self::assertNotSame([], $vistiDallaSonda, 'la sonda annota un caricamento quando c\'è');

        $leggi = new \ReflectionMethod(FileDrawio::class, 'motivoNelDocumentoLetto');
        $caricati = self::caricatiDurante(static function () use ($leggi, $documento): void {
            self::assertSame(FileDrawio::CON_DTD, $leggi->invoke(null, $documento), 'il DOCTYPE nel documento letto');
        });

        self::assertSame([], $caricati, 'FileDrawio non carica niente');
    }

    /**
     * Un file che esiste davvero, perché un caricamento riuscirebbe: la sonda
     * non lo apre mai, restituisce null.
     */
    private static function fileLocale(): string
    {
        return 'file://' . __FILE__;
    }

    /**
     * Esegue $azione con un caricatore di entità che annota ogni richiesta e
     * non carica niente; poi rimette quello di PHP.
     *
     * @return list<string> gli indirizzi chiesti
     */
    private static function caricatiDurante(\Closure $azione): array
    {
        $caricati = [];
        libxml_set_external_entity_loader(
            static function (?string $pubblico, ?string $sistema) use (&$caricati) {
                $caricati[] = (string)$sistema;
                return null;
            }
        );
        try {
            $azione();
        } finally {
            libxml_set_external_entity_loader(null);
        }
        return $caricati;
    }

    /**
     * Un drawio annidato `$livelli` volte sotto la radice, con gli elementi
     * chiusi: ben formato, e con la radice giusta.
     */
    private static function annidato(int $livelli): string
    {
        return '<mxfile host="e2e">' . str_repeat('<a>', $livelli) . str_repeat('</a>', $livelli) . '</mxfile>';
    }

    /**
     * I tetti della struttura (23/9/2026, verifica avversaria di A-3). Con
     * LIBXML_PARSEHUGE libxml non ha più il suo limite di profondità (256):
     * nell'immagine del rilascio un file di 49 MB di `<a>` annidati ha portato
     * un solo processo PHP a 3 GB, e memory_limit non conta la memoria di
     * libxml. Le 192 mappe della copia locale arrivano a profondità 5 e a 2.602
     * elementi: i tetti (64 e 200.000) stanno molto sopra.
     */
    #[Test]
    public function la_profondita_si_ferma_al_tetto(): void
    {
        // La radice è a profondità 0: 64 livelli sotto di lei passano, 65 no.
        self::assertNull(FileDrawio::motivoDelRifiuto(self::annidato(FileDrawio::PROFONDITA_MASSIMA)));
        self::assertSame(
            FileDrawio::TROPPO_COMPLESSO,
            FileDrawio::motivoDelRifiuto(self::annidato(FileDrawio::PROFONDITA_MASSIMA + 1))
        );
    }

    #[Test]
    public function il_numero_di_elementi_si_ferma_al_tetto(): void
    {
        // La radice conta: con ELEMENTI_MASSIMI - 1 figli si arriva al tetto
        // esatto, con uno in più lo si supera.
        $figli = static fn(int $n): string => '<mxfile host="e2e">' . str_repeat('<a/>', $n) . '</mxfile>';
        self::assertNull(FileDrawio::motivoDelRifiuto($figli(FileDrawio::ELEMENTI_MASSIMI - 1)));
        self::assertSame(
            FileDrawio::TROPPO_COMPLESSO,
            FileDrawio::motivoDelRifiuto($figli(FileDrawio::ELEMENTI_MASSIMI))
        );
    }

    /**
     * Il caso misurato, in piccolo: tre milioni di `<a>` aperti e mai chiusi.
     * Il documento non è ben formato, ma senza il tetto libxml lo costruisce
     * tutto prima di accorgersene; con il tetto la lettura si ferma al
     * sessantacinquesimo livello, e il motivo lo dice.
     */
    #[Test]
    public function un_annidamento_enorme_si_ferma_subito(): void
    {
        $contenuto = '<mxfile host="e2e">' . str_repeat('<a>', 3_000_000);
        $inizio = hrtime(true);

        $motivo = FileDrawio::motivoDelRifiuto($contenuto);

        self::assertSame(FileDrawio::TROPPO_COMPLESSO, $motivo);
        self::assertLessThan(1.0, (hrtime(true) - $inizio) / 1e9, 'si ferma senza leggere tutto');
    }

    /**
     * Il conto si fa sui byte: commenti e CDATA si saltano (dentro, un `<a>`
     * non apre niente), e un `>` o un `/>` dentro un valore fra apici non
     * chiude il tag.
     */
    #[Test]
    public function commenti_cdata_e_valori_non_ingannano_il_conto(): void
    {
        $molti = str_repeat('<a>', FileDrawio::PROFONDITA_MASSIMA + 10);
        self::assertNull(
            FileDrawio::motivoDelRifiuto('<mxfile host="e2e"><!-- ' . $molti . ' --><diagram name="p"/></mxfile>'),
            'in un commento'
        );
        self::assertNull(
            FileDrawio::motivoDelRifiuto('<mxfile host="e2e"><diagram name="p"><![CDATA[' . $molti . ']]></diagram></mxfile>'),
            'in un CDATA'
        );
        $livelli = FileDrawio::PROFONDITA_MASSIMA + 1;
        self::assertSame(
            FileDrawio::TROPPO_COMPLESSO,
            FileDrawio::motivoDelRifiuto(
                '<mxfile host="e2e">' . str_repeat('<a v="x/>" w=\'>\'>', $livelli) . str_repeat('</a>', $livelli) . '</mxfile>'
            ),
            'un `/>` in un valore non rende il tag chiuso da sé'
        );
    }

    #[Test]
    public function lo_stato_di_libxml_torna_com_era(): void
    {
        // Il controllo gira dentro una richiesta che usa libxml anche altrove
        // (l'alternativa testuale della mappa, il sanificatore): non lascia
        // errori accumulati né cambia la modalità degli errori.
        $prima = libxml_use_internal_errors(false);
        try {
            FileDrawio::motivoDelRifiuto('<mxfile><diagram>');
            self::assertSame([], libxml_get_errors(), 'gli errori di libxml si puliscono');
            self::assertFalse(libxml_use_internal_errors(false), 'e lo stato di prima torna com\'era');
        } finally {
            libxml_use_internal_errors($prima);
        }
    }
}
