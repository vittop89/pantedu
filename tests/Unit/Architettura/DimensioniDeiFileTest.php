<?php

declare(strict_types=1);

namespace Tests\Unit\Architettura;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * I file già grandi non crescono senza che qualcuno lo veda (23/9/2026).
 *
 * ── Perché (revisione architetturale del 23/9/2026, A-11; ADR-049, R-8 passo 3) ──
 *
 * Nessun controllo misurava le dimensioni: `checkin-handlers.js` è arrivato a
 * 5.137 righe, e i 15 metodi PHP oltre le 200 righe (fino alle 447 di
 * `Sanitizer::stripHtml`) sono cresciuti senza un avviso. Questa prova non
 * corregge i file: fissa in `tools/ci/dimensioni.json` le righe di oggi per
 * ogni file PHP sotto `app/` oltre 600 e JS sotto `js/` oltre 1.000, e
 * ricalcola le righe (come `wc -l`, cioè i newline) a ogni corsa.
 *
 * Fallisce in tre casi, misurati nei due versi il 23/9/2026 con una
 * mutazione temporanea poi ripristinata (`git diff` pulito dopo ogni prova):
 * un file dell'elenco cresciuto oltre il 5% (`cresciutoOltreIlMargineFallisce`),
 * un file nuovo sopra soglia non censito (`unFileNuovoSopraSogliaFallisce`),
 * un file dell'elenco sceso sotto il numero registrato
 * (`unFileSceseSottoIlNumeroRegistratoFallisce`). Il margine del 5% in
 * crescita evita di dover toccare il censimento per ogni riga aggiunta a un
 * file già segnalato.
 */
final class DimensioniDeiFileTest extends TestCase
{
    private static function radice(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function censimento(): array
    {
        $json = file_get_contents(self::radice() . '/tools/ci/dimensioni.json');
        $dati = json_decode((string)$json, true);
        self::assertIsArray($dati, 'tools/ci/dimensioni.json si legge');
        return $dati;
    }

    /** Righe di un file, come `wc -l` (newline contati, non righe "logiche"). */
    private static function righe(string $percorsoAssoluto): int
    {
        $contenuto = file_get_contents($percorsoAssoluto);
        self::assertIsString($contenuto, "$percorsoAssoluto si legge");
        return substr_count($contenuto, "\n");
    }

    /** @return iterable<string> percorsi assoluti sotto $cartella con estensione $ext */
    private static function fileSotto(string $cartella, string $ext): iterable
    {
        if (!is_dir($cartella)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cartella, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->getExtension() === $ext) {
                yield $f->getPathname();
            }
        }
    }

    /** @return array<string,int> percorso relativo => righe, per i file oltre soglia oggi */
    private static function oltreSogliaOggi(array $soglie): array
    {
        $radice = self::radice();
        $trovati = [];
        foreach (self::fileSotto($radice . '/app', 'php') as $path) {
            $n = self::righe($path);
            if ($n > $soglie['php_sotto_app']) {
                $trovati[str_replace($radice . '/', '', $path)] = $n;
            }
        }
        foreach (self::fileSotto($radice . '/js', 'js') as $path) {
            $n = self::righe($path);
            if ($n > $soglie['js_sotto_js']) {
                $trovati[str_replace($radice . '/', '', $path)] = $n;
            }
        }
        return $trovati;
    }

    #[Test]
    public function nessunFileNuovoSopraSoglia(): void
    {
        $dati = self::censimento();
        $oggi = self::oltreSogliaOggi($dati['soglie']);
        $censiti = $dati['file'];

        $nuovi = array_values(array_diff(array_keys($oggi), array_keys($censiti)));
        self::assertSame(
            [],
            $nuovi,
            'File nuovi oltre soglia (600 righe PHP in app/, 1.000 JS in js/), non in'
            . ' tools/ci/dimensioni.json: ' . implode(', ', $nuovi)
        );
    }

    #[Test]
    public function nessunFileCensitoCresceOltreIlMargine(): void
    {
        $dati = self::censimento();
        $oggi = self::oltreSogliaOggi($dati['soglie']);
        $margine = $dati['margine_crescita'];

        $cresciuti = [];
        foreach ($dati['file'] as $percorso => $registrato) {
            $attuale = $oggi[$percorso] ?? self::righeSeEsiste($percorso);
            $limite = $registrato * (1 + $margine);
            if ($attuale > $limite) {
                $cresciuti[] = "$percorso: $registrato → $attuale (limite " . (int)$limite . ')';
            }
        }
        self::assertSame(
            [],
            $cresciuti,
            'File cresciuti oltre il ' . ($margine * 100) . '% rispetto a tools/ci/dimensioni.json: '
            . implode('; ', $cresciuti)
        );
    }

    #[Test]
    public function unFileCensitoCheScendeChiedeDiAbbassareIlNumero(): void
    {
        $dati = self::censimento();
        $oggi = self::oltreSogliaOggi($dati['soglie']);

        $sceso = [];
        foreach ($dati['file'] as $percorso => $registrato) {
            $attuale = $oggi[$percorso] ?? 0;
            if ($attuale === 0 && !file_exists(self::radice() . '/' . $percorso)) {
                $sceso[] = "$percorso: cancellato, togli la voce da tools/ci/dimensioni.json";
                continue;
            }
            $attuale = $attuale === 0 ? self::righe(self::radice() . '/' . $percorso) : $attuale;
            if ($attuale < $registrato) {
                $chiaveSoglia = str_starts_with($percorso, 'app/') ? 'php_sotto_app' : 'js_sotto_js';
                $sotto = $attuale <= $dati['soglie'][$chiaveSoglia];
                $sceso[] = $sotto
                    ? "$percorso: sceso a $attuale, sotto soglia — togli la voce da tools/ci/dimensioni.json"
                    : "$percorso: sceso da $registrato a $attuale, abbassa il numero in tools/ci/dimensioni.json";
            }
        }
        self::assertSame([], $sceso, implode('; ', $sceso));
    }

    private static function righeSeEsiste(string $percorsoRelativo): int
    {
        $assoluto = self::radice() . '/' . $percorsoRelativo;
        return is_file($assoluto) ? self::righe($assoluto) : 0;
    }

    /** Il conteggio righe usa i newline, come `wc -l`: controprova diretta. */
    #[Test]
    public function ilConteggioDelleRigheUsaINewlineComeWcMenoL(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'righe-');
        try {
            file_put_contents($tmp, "a\nb\nc\n");
            self::assertSame(3, self::righe($tmp));
            file_put_contents($tmp, "a\nb\nc");
            self::assertSame(2, self::righe($tmp), "senza newline finale l'ultima riga non si conta, come wc -l");
        } finally {
            @unlink($tmp);
        }
    }
}
