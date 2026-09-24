<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La baseline di PHPStan può solo scendere (23/9/2026).
 *
 * Con `reportUnmatchedIgnoredErrors: false` una voce della baseline che non
 * trova più il suo errore restava lì in silenzio, e avrebbe nascosto un errore
 * nuovo della stessa forma nello stesso file. Il 23/9 erano morte 66 voci su
 * 161, una su un file cancellato il 4/9, più i due pattern globali di
 * phpstan.neon; alcune coprivano il ritorno di difetti già corretti (revisione
 * architetturale del 23/9/2026, A-6). Che cosa succede con `true` l'ha
 * misurato PHPStan stesso, su una copia: una voce morta, un percorso che non
 * esiste, un `count` più alto del vero fanno fallire `composer stan`.
 *
 * Qui si tiene fermo l'interruttore. Chi rigenera la baseline e si trova
 * davanti le voci morte deve toglierle, non spegnere il controllo: e se lo
 * spegne, questa prova glielo dice, con il perché.
 *
 * L'interruttore però ferma solo le voci morte, non quelle nuove: un errore
 * nuovo messo in baseline insieme al codice che lo introduce passava (lo ha
 * misurato la verifica avversaria del 23/9). Per questo la somma dei `count`
 * della baseline ha un tetto, in tools/ci/phpstan-tetto.json, e la prova la
 * vuole uguale al tetto: se sale, la baseline è cresciuta; se scende, il tetto
 * va abbassato, perché uno più alto del vero lascerebbe spazio a errori nuovi.
 */
final class LaBaselineDiPhpstanPuoSoloScendereTest extends TestCase
{
    /**
     * Il valore di `reportUnmatchedIgnoredErrors` nel file, letto sulle righe
     * che non sono commenti; null se non c'è. Se compare più di una volta
     * restituisce tutti i valori separati da virgola, così la prova fallisce
     * invece di scegliere quello comodo.
     */
    private static function valoreDichiarato(string $neon): ?string
    {
        preg_match_all('/^[ \t]*reportUnmatchedIgnoredErrors:[ \t]*([^\s#]+)/m', $neon, $m);
        if ($m[1] === []) {
            return null;
        }
        return implode(',', $m[1]);
    }

    /** La somma dei `count:` della baseline (una voce senza count vale 1). */
    private static function erroriNellaBaseline(string $neon): int
    {
        $voci = preg_match_all('/^[ \t]*message:/m', $neon);
        preg_match_all('/^[ \t]*count:[ \t]*(\d+)/m', $neon, $m);
        return array_sum(array_map('intval', $m[1])) + max(0, (int)$voci - \count($m[1]));
    }

    #[Test]
    public function laBaselineNonCresceOltreIlTetto(): void
    {
        $radice = dirname(__DIR__, 2);
        $baseline = file_get_contents($radice . '/phpstan-baseline.neon');
        self::assertIsString($baseline, 'phpstan-baseline.neon si legge');
        $tetto = json_decode((string)file_get_contents($radice . '/tools/ci/phpstan-tetto.json'), true);
        self::assertIsArray($tetto, 'tools/ci/phpstan-tetto.json si legge');
        self::assertIsInt($tetto['errori'] ?? null, 'il tetto è un numero intero');

        $errori = self::erroriNellaBaseline($baseline);

        self::assertLessThanOrEqual(
            $tetto['errori'],
            $errori,
            "La baseline di PHPStan è cresciuta a $errori errori, oltre il tetto di {$tetto['errori']}:"
            . ' un errore nuovo si corregge, non si mette in baseline (tools/ci/phpstan-tetto.json).'
        );
        self::assertSame(
            $tetto['errori'],
            $errori,
            "La baseline è scesa a $errori errori: abbassa il tetto in tools/ci/phpstan-tetto.json"
            . " (oggi {$tetto['errori']}), perché uno più alto del vero lascerebbe spazio a errori nuovi."
        );
    }

    /** La controprova del conto: count espliciti, voci senza count, commenti. */
    #[Test]
    public function ilContoDegliErroriLeggeCountEVociSenzaCount(): void
    {
        $neon = "parameters:\n\tignoreErrors:\n"
            . "\t\t-\n\t\t\tmessage: '#a#'\n\t\t\tcount: 3\n\t\t\tpath: x.php\n"
            . "\t\t-\n\t\t\tmessage: '#b#'\n\t\t\tcount: 2\n\t\t\tpath: y.php\n";
        self::assertSame(5, self::erroriNellaBaseline($neon));
        self::assertSame(
            6,
            self::erroriNellaBaseline($neon . "\t\t-\n\t\t\tmessage: '#c#'\n\t\t\tpath: z.php\n"),
            'una voce senza count vale 1'
        );
        self::assertSame(0, self::erroriNellaBaseline("parameters:\n\tignoreErrors: []\n"));
    }

    #[Test]
    public function phpstanNeonFaFallireLAnalisiSuUnaVoceMorta(): void
    {
        $neon = file_get_contents(dirname(__DIR__, 2) . '/phpstan.neon');
        self::assertIsString($neon, 'phpstan.neon si legge');

        self::assertSame(
            'true',
            self::valoreDichiarato($neon),
            "phpstan.neon deve dichiarare reportUnmatchedIgnoredErrors: true, una volta sola:"
            . " con false la baseline cresce e nasconde errori nuovi (A-6)."
            . " Se composer stan segnala voci morte, si tolgono dalla baseline."
        );
    }

    /**
     * La controprova: la lettura distingue i casi, altrimenti la prova sopra
     * potrebbe essere verde per la ragione sbagliata.
     */
    #[Test]
    public function laLetturaRiconosceFalseAssenzaCommentiEDoppioni(): void
    {
        self::assertSame('true', self::valoreDichiarato("parameters:\n    reportUnmatchedIgnoredErrors: true\n"));
        self::assertSame('false', self::valoreDichiarato("parameters:\n    reportUnmatchedIgnoredErrors: false\n"));
        self::assertSame(
            'false',
            self::valoreDichiarato("parameters:\n    reportUnmatchedIgnoredErrors: false # true\n"),
            'un commento sulla stessa riga non conta'
        );
        self::assertNull(
            self::valoreDichiarato("parameters:\n    # reportUnmatchedIgnoredErrors: true\n    level: 6\n"),
            'una riga commentata non è una dichiarazione'
        );
        self::assertSame(
            'true,false',
            self::valoreDichiarato(
                "parameters:\n    reportUnmatchedIgnoredErrors: true\n    reportUnmatchedIgnoredErrors: false\n"
            ),
            'due dichiarazioni non si riducono a quella comoda'
        );
    }
}
