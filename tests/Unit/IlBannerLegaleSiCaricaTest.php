<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il modulo che rende visibile il banner legale viene davvero caricato.
 *
 * ── Il difetto, misurato il 22/9/2026 ─────────────────────────────────────
 *
 * Il banner che annuncia una versione nuova dei Termini nasce con `hidden` nel
 * markup. L'unico punto di tutto il progetto che lo toglie è una riga dentro
 * `js/modules/core/legal-notice.js` — e quel file **non era importato da
 * nessuno**. Verificato anche sul pacchetto costruito: `fm-legal-notice` non
 * compariva fra gli asset, mentre `fm-bb-menu` sì.
 *
 * Risultato: il banner promesso dai Termini §14 e dall'AUP §11 — l'avviso in
 * applicazione trenta giorni prima di una modifica sostanziale — **non si è
 * mai visto da nessuno**, e nessuna prova se ne sarebbe accorta, perché tutti
 * i pezzi esistevano e sembravano collegati.
 *
 * ── Perché una prova sul sorgente e non un browser ───────────────────────
 *
 * Perché il difetto non era nel comportamento ma nel **collegamento**: un
 * import mancante. Una prova end-to-end lo vedrebbe soltanto costruendo prima
 * la condizione rara che fa comparire il banner (una versione con data di
 * efficacia nel futuro, che non esiste mai nei dati veri). Quella condizione
 * la prova {@see \Tests\Integration\AvvisoLegaleInPaginaTest} dal lato
 * server; qui si guarda il filo che era spezzato.
 */
final class IlBannerLegaleSiCaricaTest extends TestCase
{
    private const MODULO    = 'js/modules/core/legal-notice.js';
    private const BOOTSTRAP = 'js/modules/bootstrap.js';
    private const PARTIAL   = 'views/partials/_legal_notice_banner.php';

    private static function radice(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function legge(string $relativo): string
    {
        return (string)file_get_contents(self::radice() . '/' . $relativo);
    }

    // ─────────────────────────────────────────────────────────────────────

    /** Controllo positivo: i tre pezzi esistono e sono quelli. */
    #[Test]
    public function i_pezzi_del_banner_esistono(): void
    {
        self::assertStringContainsString('el.hidden = false;', self::legge(self::MODULO));
        self::assertStringContainsString('data-fm-legal-notice', self::legge(self::PARTIAL));
        self::assertStringContainsString('hidden>', self::legge(self::PARTIAL), 'il markup nasce nascosto');
    }

    /**
     * Il filo che era spezzato: il fascio caricato su ogni pagina deve
     * importare il modulo.
     */
    #[Test]
    public function il_fascio_principale_importa_il_modulo(): void
    {
        self::assertStringContainsString(
            'import "./core/legal-notice.js";',
            self::legge(self::BOOTSTRAP),
            'senza questa riga il banner resta `hidden` per sempre, e i Termini §14 promettono '
            . 'una cosa che non succede'
        );
    }

    /**
     * Il pacchetto costruito lo guarda un controllo a parte.
     *
     * Qui non si puo': il pacchetto e' un artefatto del front-end, e nella
     * catena di integrazione esiste solo nel lavoro che lo costruisce. Una
     * prova che lo cercasse dovrebbe saltare dove non c'e' — e in quel lavoro
     * «ogni salto e' un fallimento», giustamente, perche' un controllo che si
     * salta e' un controllo che non misura. Provato il 22/9/2026: la versione
     * precedente di questa classe faceva fallire la catena proprio cosi'.
     *
     * Il controllo sta in `tools/ci/check-moduli-nel-fascio.mjs`, dentro
     * `npm run ci`, subito dopo il build. Qui si verifica che ci sia e che
     * nomini questo modulo: un controllo tolto dal `ci` sparirebbe in
     * silenzio.
     */
    #[Test]
    public function il_pacchetto_costruito_lo_guarda_il_controllo_del_front_end(): void
    {
        $controllo = self::legge('tools/ci/check-moduli-nel-fascio.mjs');

        self::assertStringContainsString('fm-legal-notice', $controllo, 'deve cercare proprio questo modulo');
        self::assertStringContainsString('fm-bb-menu', $controllo, 'con un controllo positivo sul fascio');

        $package = self::legge('package.json');
        self::assertStringContainsString(
            'moduli:fascio',
            $package,
            'il controllo esiste ma nessuno lo lancia: è il difetto di prima, con un altro nome'
        );
        self::assertMatchesRegularExpression(
            '/npm run build && npm run moduli:fascio/',
            $package,
            'va lanciato DOPO il build, altrimenti guarda il pacchetto della volta prima'
        );
    }

    /**
     * Chi riceve il preavviso per posta: il filtro dei ruoli deve nominare i
     * ruoli veri.
     *
     * Fino al 22/9/2026 diceva `role IN ('teacher','admin')`, e `admin` non è
     * un ruolo: l'amministratore è `administrator` (ADR-040, l'alias è stato
     * tolto). Lo strumento di anteprima rispondeva quindi «nessuno verrebbe
     * bloccato» quando invece qualcuno lo sarebbe, e il preavviso non lo
     * raggiungeva. Silenzioso nei due versi.
     */
    #[Test]
    public function il_preavviso_per_posta_nomina_i_ruoli_veri(): void
    {
        foreach (['tools/legal/notify_policy_update.php', 'tools/legal/tos_gate_preview.php'] as $strumento) {
            $s = self::legge($strumento);

            self::assertStringContainsString(
                "u.role IN ('teacher','administrator','institute_admin')",
                $s,
                "{$strumento}: il filtro deve nominare i ruoli che esistono davvero"
            );
            self::assertStringNotContainsString(
                "u.role IN ('teacher','admin')",
                $s,
                "{$strumento}: «admin» non è un ruolo, e chi lo è resterebbe fuori"
            );
        }
    }
}
