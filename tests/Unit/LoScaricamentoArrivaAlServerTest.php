<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il filo che va dal pulsante «scarica» alla data sul server (ADR-046).
 *
 * ── Perché una prova che legge il codice ──────────────────────────────────
 *
 * Questo filo attraversa tre file e non ha un punto in cui si possa
 * interrogare. Lo scaricamento del PDF avviene tutto nel browser, e la
 * chiamata che ne informa il server è **volutamente silenziosa**: se fallisce
 * l'utente non vede niente, perché non ha sbagliato lui e non può farci
 * niente. Vuol dire che se un giorno qualcuno la toglie, o la sposta prima
 * della guardia, o cambia il nome della proprietà che porta l'identificativo,
 * non se ne accorge nessuno: il PDF continua a scendere, la bozza non scade
 * più, e la conservazione dichiarata nell'informativa smette di essere vera.
 *
 * Una prova end-to-end misura la rotta (`tests/e2e/risdoc/bozze-a-scadenza.spec.js`),
 * ma non può misurare il clic: quello vuole il servizio TeX, un documento già
 * compilato e i byte in cache. Questa prova copre il tratto che resta.
 *
 * ── Le quattro cose che devono restare vere ───────────────────────────────
 *
 * 1. L'identificativo della compilazione viene **tenuto**, non buttato: è
 *    l'unica cosa che identifichi la riga aperta.
 * 2. Viaggia sull'oggetto `doc`, non in `opts`: `openPreview` legge di `opts`
 *    cinque chiavi nominate e butta il resto **senza dire niente**.
 * 3. La segnalazione sta **dopo** la guardia che esce quando il PDF non c'è:
 *    lì non è successo nessuno scaricamento.
 * 4. La guardia è l'identificativo, non il modo del modal: `risdoc-template`
 *    è vero anche per il super-admin che modifica il modello originale, dove
 *    non c'è nessuna compilazione di nessun docente.
 */
final class LoScaricamentoArrivaAlServerTest extends TestCase
{
    private static function legge(string $relativo): string
    {
        return (string)file_get_contents(\dirname(__DIR__, 2) . '/' . $relativo);
    }

    private const MODAL   = 'js/entries/verifica-preview-editor.js';
    private const DOC     = 'js/components/pt-document/fm-pt-document.js';
    private const ADAPTER = 'js/components/pt-document/adapters/risdoc-template-adapter.js';
    private const CHIAMA  = 'js/modules/risdoc/segna-scaricata.js';

    // ─────────────────────────────────────────────────────────────────────

    /** Controllo positivo: sono i file giusti. */
    #[Test]
    public function i_file_del_filo_esistono_e_sono_quelli(): void
    {
        self::assertStringContainsString('data-act="export-pdf"', self::legge(self::MODAL));
        self::assertStringContainsString('_exportTex()', self::legge(self::DOC));
        self::assertStringContainsString('class RisdocTemplateAdapter', self::legge(self::ADAPTER));
        self::assertStringContainsString('export async function segnaScaricata', self::legge(self::CHIAMA));
    }

    /**
     * 1. L'adattatore tiene l'identificativo, nei due punti in cui passa: il
     *    caricamento di una compilazione esistente e il salvataggio.
     */
    #[Test]
    public function l_adattatore_tiene_l_identificativo_invece_di_buttarlo(): void
    {
        $s = self::legge(self::ADAPTER);

        self::assertStringContainsString(
            'this.compilationId = null;',
            $s,
            'senza un posto dove tenerlo, l\'identificativo muore come prima'
        );
        self::assertStringContainsString(
            'this.compilationId = Number(m.id) || null;',
            $s,
            'al caricamento di una compilazione salvata'
        );
        self::assertStringContainsString(
            'if (j.id) this.compilationId = Number(j.id) || null;',
            $s,
            'e al salvataggio, dove il server lo restituisce'
        );
    }

    /**
     * ...e lo azzera dove una riga sul server non c'è: la bozza tenuta nel
     * browser per scelta dell'Istituto non ha niente da segnare.
     */
    #[Test]
    public function dove_la_bozza_resta_nel_browser_l_identificativo_si_azzera(): void
    {
        $s = self::legge(self::ADAPTER);
        $dopoIl403 = strstr($s, "j.error === \"compilation_storage_disabled\"");

        self::assertIsString($dopoIl403, 'controllo positivo: il ramo del 403 esiste ancora');
        self::assertStringContainsString(
            'this.compilationId = null;',
            substr($dopoIl403, 0, 1200),
            'senza questo, la bozza locale segnerebbe la riga di un\'altra compilazione'
        );
    }

    /**
     * 2. L'identificativo viaggia sull'oggetto `doc`. Se qualcuno lo spostasse
     *    in `opts` sparirebbe in silenzio: `openPreview` legge cinque chiavi
     *    nominate e ignora il resto senza errore.
     */
    #[Test]
    public function l_identificativo_viaggia_sull_oggetto_del_documento(): void
    {
        $doc = self::legge(self::DOC);

        self::assertMatchesRegularExpression(
            '/compilationId:\s*this\._adapter\?\.compilationId\s*\?\?\s*null,/',
            $doc,
            'il modal riceve l\'identificativo insieme al documento'
        );

        // ...e nel ramo giusto: quello risdoc, non quello dei documenti
        // personalizzati, che sono lavoro del docente e non scadono.
        $ramoRisdoc = strstr($doc, 'mode: "risdoc-template"', true);
        self::assertIsString($ramoRisdoc, 'controllo positivo: il ramo risdoc esiste');
        self::assertStringContainsString(
            'compilationId:',
            substr($ramoRisdoc, -900),
            'l\'identificativo deve stare nel documento aperto in modo risdoc-template'
        );

        $modal = self::legge(self::MODAL);
        self::assertStringContainsString(
            'State.mode = opts.mode || "verifica";',
            $modal,
            'controllo positivo: opts si legge ancora per chiavi nominate'
        );
        self::assertStringNotContainsString(
            'opts.compilationId',
            $modal,
            'passarlo in opts vorrebbe dire perderlo senza un errore'
        );
    }

    /**
     * 3. e 4. La segnalazione sta dopo la guardia dei byte, e la sua condizione
     * è l'identificativo.
     */
    #[Test]
    public function la_segnalazione_parte_solo_dopo_uno_scaricamento_vero(): void
    {
        $s = self::legge(self::MODAL);

        $gestore = strstr($s, 'if (act === "export-pdf") {');
        self::assertIsString($gestore, 'controllo positivo: il gestore del pulsante esiste');
        $gestore = substr($gestore, 0, 1800);

        $guardia = strpos($gestore, 'if (!cached?.pdfBytes) return;');
        $clic    = strpos($gestore, 'a2.click();');
        $segnala = strpos($gestore, 'segna-scaricata.js');

        self::assertIsInt($guardia, 'la guardia che esce quando il PDF non c\'è');
        self::assertIsInt($clic, 'lo scaricamento vero');
        self::assertIsInt($segnala, 'la segnalazione al server');

        self::assertLessThan($segnala, $guardia, 'senza byte non c\'è stato nessuno scaricamento da segnalare');
        self::assertLessThan($segnala, $clic, 'si segnala dopo aver dato il file, non prima');

        self::assertStringContainsString(
            'if (doc.compilationId) {',
            $gestore,
            'la guardia è l\'identificativo: il modo «risdoc-template» è vero anche per il super-admin che modifica il modello'
        );
    }

    /**
     * Anche lo ZIP e il pacchetto per VSCode portano via lo stesso documento.
     * Se segnasse solo il PDF, la bozza di chi lavora con lo ZIP resterebbe
     * fino alla rete di fine anno.
     */
    #[Test]
    public function anche_lo_zip_e_il_pacchetto_vscode_segnano_lo_scaricamento(): void
    {
        $s = self::legge(self::DOC);

        self::assertSame(
            2,
            substr_count($s, 'await this._segnaScaricata();'),
            'due scaricamenti fuori dal modal: lo ZIP e il pacchetto per VSCode'
        );
        self::assertStringContainsString('async _segnaScaricata() {', $s);
        self::assertStringContainsString(
            'const id = this._isRisdoc ? (this._adapter?.compilationId ?? null) : null;',
            $s,
            'su un documento personalizzato non deve succedere niente'
        );
    }

    /**
     * Il gettone vero, e la fetch che sopravvive alla sfida del filtro di
     * sicurezza. Una fetch grezza si prende un 403 e la data non si scrive,
     * in silenzio; e `fetchCsrf` torna stringa vuota invece di sollevare.
     */
    #[Test]
    public function la_chiamata_usa_il_gettone_vero_e_la_fetch_che_ritenta(): void
    {
        $s = self::legge(self::CHIAMA);

        self::assertStringContainsString('import { fetchCsrf, wafFetch }', $s);
        self::assertStringContainsString('if (!csrf) return false;', $s, 'un gettone vuoto non si spedisce');
        self::assertStringContainsString('await wafFetch(', $s, 'non una fetch grezza');
        self::assertStringContainsString('if (!r.ok) return false;', $s, 'e l\'esito si guarda');
        self::assertStringNotContainsString(
            'sendBeacon',
            $s,
            'il beacon non restituisce un esito: sarebbe un successo che non ha misurato niente'
        );
    }

    /** La rotta che la chiamata usa esiste, ed è quella protetta. */
    #[Test]
    public function la_rotta_esiste_ed_e_protetta(): void
    {
        $rotte = self::legge('routes/web.php');

        self::assertStringContainsString("'/api/risdoc/compilations/{id}/scaricata'", $rotte);
        self::assertStringContainsString("'scaricata'", $rotte);
        self::assertStringContainsString("->middleware('rate:scaricata,60')", $rotte, 'secchio dedicato');

        // Deve stare dentro il gruppo csrf+rate, che si apre prima e si chiude
        // dopo: se finisse fuori, scriverebbe una data senza gettone.
        $gruppo = strpos($rotte, "\$r->group(['middleware' => ['csrf', 'rate']");
        $rotta  = strpos($rotte, "'/api/risdoc/compilations/{id}/scaricata'");
        self::assertIsInt($gruppo);
        self::assertIsInt($rotta);
        self::assertLessThan($rotta, $gruppo, 'la rotta sta dentro il gruppo che impone il gettone');
    }
}
