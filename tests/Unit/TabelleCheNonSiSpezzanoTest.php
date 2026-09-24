<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Nei documenti che consegniamo, nessuna tabella si spezza a metà.
 *
 * ── Il difetto, misurato il 22/9/2026 ─────────────────────────────────────
 *
 * Nella DPIA una voce della storia delle revisioni era lunga otto paragrafi, e
 * quei paragrafi stavano su righe che **non cominciano con `|`**. In Markdown
 * una tabella finisce alla prima riga che non è una riga di tabella: da lì in
 * poi quegli otto paragrafi escono come testo sciolto, e le voci successive
 * ricominciano come una **seconda tabella senza intestazione**.
 *
 * Il file sorgente sembra a posto — le voci ci sono tutte, nell'ordine — e il
 * PDF che finisce in mano a un DPO no. È un guasto che si vede solo dall'altra
 * parte, ed è esattamente il motivo per cui questa prova legge la forma e non
 * il contenuto.
 *
 * Sintomo riportato dall'utente: «la parte finale sembra un accumulo di testo
 * non organizzato». Non era un'impressione: era una tabella rotta.
 *
 * ── Perché una prova e non solo una correzione ────────────────────────────
 *
 * Perché ricapiterà. La tentazione di scrivere una voce lunga dentro una cella
 * arriva ogni volta che una revisione è complicata, e il file continua a
 * sembrare giusto. Qui si chiede al documento di dimostrare che le sue tabelle
 * stanno in piedi.
 */
final class TabelleCheNonSiSpezzanoTest extends TestCase
{
    /**
     * I documenti che escono da qui verso qualcuno: quelli che il sito rende,
     * quelli che il pacchetto consegna, e quelli che si allegano a mano.
     *
     * @return list<string>
     */
    private static function documenti(): array
    {
        $radice = \dirname(__DIR__, 2);
        $fuori = [];

        foreach (['docs/privacy', 'docs/legal', 'docs/dpo/pacchetto-scuola'] as $cartella) {
            foreach (glob($radice . '/' . $cartella . '/*.md') ?: [] as $f) {
                $fuori[] = $cartella . '/' . basename($f);
            }
        }

        sort($fuori);

        return $fuori;
    }

    /**
     * Le righe che spezzano una tabella, in un documento.
     *
     * Una tabella comincia alla riga di separazione (`|---|---|`) e finisce
     * alla prima riga vuota o alla prima intestazione. Qualunque riga in mezzo
     * che non cominci con `|` la interrompe.
     *
     * @return list<int> numeri di riga, da 1
     */
    private static function righeCheSpezzano(string $relativo): array
    {
        $righe = file(\dirname(__DIR__, 2) . '/' . $relativo, FILE_IGNORE_NEW_LINES) ?: [];
        $dentro = false;
        $rotte  = [];

        foreach ($righe as $i => $riga) {
            $senzaSpazi = str_replace([' ', '|'], '', $riga);
            $eSeparatore = str_starts_with($riga, '|')
                && substr_count($riga, '|') >= 2
                && $senzaSpazi !== ''
                && trim($senzaSpazi, '-:') === '';

            if ($eSeparatore) {
                $dentro = true;
                continue;
            }
            if (!$dentro) {
                continue;
            }
            if (trim($riga) === '' || str_starts_with($riga, '#')) {
                $dentro = false;
                continue;
            }
            if (!str_starts_with($riga, '|')) {
                $rotte[] = $i + 1;
            }
        }

        return $rotte;
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Controllo positivo sulla scoperta: se la lettura non trovasse documenti,
     * la prova qui sotto passerebbe senza aver guardato niente.
     */
    #[Test]
    public function i_documenti_si_trovano(): void
    {
        $documenti = self::documenti();

        self::assertGreaterThanOrEqual(10, \count($documenti), 'i documenti legali sono molti di più');
        self::assertContains('docs/privacy/dpia.md', $documenti);
        self::assertContains('docs/legal/tos_docente.md', $documenti);
    }

    /**
     * Controllo positivo sul metodo: su un testo rotto costruito apposta, la
     * funzione deve trovare la riga. Senza questa prova, una funzione che
     * torna sempre l'elenco vuoto passerebbe tutto.
     */
    #[Test]
    public function il_controllo_riconosce_una_tabella_spezzata(): void
    {
        $esca = \sys_get_temp_dir() . '/pantedu_tabella_rotta_' . bin2hex(random_bytes(4)) . '.md';
        file_put_contents($esca, implode("\n", [
            '| Versione | Che cosa |',
            '|---|---|',
            '| 1.0 | prima riga, sana |',
            '| 1.1 | una voce lunga che continua...',
            'e qui continua fuori dalla tabella, che così si spezza |',
            '| 1.2 | riga dopo la rottura |',
            '',
        ]));

        try {
            $righe = file($esca, FILE_IGNORE_NEW_LINES) ?: [];
            $dentro = false;
            $rotte = [];
            foreach ($righe as $i => $riga) {
                $senzaSpazi = str_replace([' ', '|'], '', $riga);
                if (str_starts_with($riga, '|') && substr_count($riga, '|') >= 2
                    && $senzaSpazi !== '' && trim($senzaSpazi, '-:') === '') {
                    $dentro = true;
                    continue;
                }
                if (!$dentro) {
                    continue;
                }
                if (trim($riga) === '') {
                    $dentro = false;
                    continue;
                }
                if (!str_starts_with($riga, '|')) {
                    $rotte[] = $i + 1;
                }
            }
            self::assertSame([5], $rotte, 'la riga 5 spezza la tabella e il controllo deve vederla');
        } finally {
            @unlink($esca);
        }
    }

    /**
     * Le righe che hanno un numero di celle diverso dall'intestazione.
     *
     * ── Il secondo modo di rompere una tabella (22/9/2026) ────────────────
     *
     * La prima versione di questa classe guardava solo le righe che non
     * cominciano con «|». Poche ore dopo averla scritta, la voce che
     * raccontava quella correzione ne ha introdotta un'altra: conteneva un «|»
     * dentro il testo, non protetto, che apre una cella in piu'. In un lettore
     * Markdown le celle oltre l'intestazione si scartano — quindi la riga
     * perdeva proprio il racconto della riparazione.
     *
     * Nella stessa tabella cinque righe ne avevano quattro contro le tre
     * dell'intestazione, per una colonna «Operatore» che nessuno aveva
     * dichiarato.
     *
     * Una prova che guarda un modo solo di rompere una cosa e' una prova che
     * lascia aperti gli altri.
     *
     * @return list<string> descrizioni, vuote se tutto combacia
     */
    private static function righeConCelleSbagliate(string $relativo): array
    {
        $righe = file(\dirname(__DIR__, 2) . '/' . $relativo, FILE_IGNORE_NEW_LINES) ?: [];
        $fuori = [];
        $attese = null;

        foreach ($righe as $i => $riga) {
            $senzaSpazi = str_replace([' ', '|'], '', $riga);
            $eSeparatore = str_starts_with($riga, '|')
                && substr_count($riga, '|') >= 2
                && $senzaSpazi !== ''
                && trim($senzaSpazi, '-:') === '';

            if ($eSeparatore) {
                // L'intestazione e' la riga sopra il separatore.
                $attese = self::celle($righe[$i - 1] ?? '');
                continue;
            }
            if ($attese === null) {
                continue;
            }
            if (trim($riga) === '' || str_starts_with($riga, '#') || !str_starts_with($riga, '|')) {
                $attese = null;
                continue;
            }
            $quante = self::celle($riga);
            if ($quante !== $attese) {
                $fuori[] = sprintf('%s:%d — %d celle invece di %d', $relativo, $i + 1, $quante, $attese);
            }
        }

        return $fuori;
    }

    /** Le celle di una riga: i «|» non protetti da una barra rovescia. */
    private static function celle(string $riga): int
    {
        return substr_count(str_replace('\|', " ", $riga), '|') - 1;
    }

    /**
     * La seconda direzione: una tabella con le celle in disordine perde
     * silenziosamente il contenuto in eccesso.
     */
    #[Test]
    public function nessuna_riga_ha_piu_celle_dell_intestazione(): void
    {
        $riscontri = [];
        foreach (self::documenti() as $documento) {
            foreach (self::righeConCelleSbagliate($documento) as $r) {
                $riscontri[] = $r;
            }
        }

        self::assertSame(
            [],
            $riscontri,
            "Queste righe non hanno lo stesso numero di celle della loro intestazione.
"
            . "Le celle in piu' vengono SCARTATE dal lettore Markdown, quindi la riga perde
"
            . "il suo contenuto finale; quelle in meno lasciano colonne vuote.
"
            . "Se il testo contiene una barra verticale, va protetta con \|.
  "
            . implode("
  ", $riscontri) . "
"
        );
    }

    /** La direzione che conta: nessun documento vero deve averne. */
    #[Test]
    public function nessun_documento_ha_una_tabella_spezzata(): void
    {
        $riscontri = [];
        foreach (self::documenti() as $documento) {
            foreach (self::righeCheSpezzano($documento) as $riga) {
                $riscontri[] = "{$documento}:{$riga}";
            }
        }

        self::assertSame(
            [],
            $riscontri,
            "Queste righe non cominciano con «|» e stanno dentro una tabella: in Markdown\n"
            . "la chiudono, e nel PDF consegnato il resto della tabella esce come testo\n"
            . "sciolto o come una seconda tabella senza intestazione.\n"
            . "Una voce lunga va riassunta nella cella, o spostata in una sezione sua.\n  "
            . implode("\n  ", $riscontri) . "\n"
        );
    }
}
