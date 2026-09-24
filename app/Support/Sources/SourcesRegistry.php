<?php

declare(strict_types=1);

namespace App\Support\Sources;

/**
 * Le due conversioni fra il registro delle fonti di un docente e la forma
 * storica con cui l'editor le legge.
 *
 * Registro (canonico, `sources.registry.json`):
 *     [{ key, book, volume, authors }, …]
 * Forma storica (quella che l'API restituisce):
 *     { <code>: { code, title, volume, publisher, authors }, … }
 *
 * `volume` porta l'editore attaccato con un trattino — «Vol.2 Ed.3 - ZANICHELLI»
 * — e le due funzioni lo separano e lo ricompongono.
 *
 * 2026-09-07 — stavano come metodi privati di `ContentStudyController`. Quando
 * `StudySourcesController` è stato estratto (ADR-029) le chiamate sono partite
 * con lui e i metodi no: `self::registryArrayToLegacyDict()` non esisteva più
 * nella classe che la invoca. Due delle tre chiamate stanno dentro un
 * `catch (\Throwable)` vuoto, quindi l'errore veniva ingoiato e l'elenco delle
 * fonti tornava vuoto come se il registro non ci fosse; la terza, nel
 * salvataggio, sarebbe morta con un 500. Qui la regola sta in un posto solo e
 * la usano tutti e due.
 */
final class SourcesRegistry
{
    /**
     * Registro → forma storica.
     *
     * @param array<int|string, mixed> $list
     * @return array<string, array{code: string, title: string, volume: string, publisher: string, authors: string}>
     */
    public static function toLegacyDict(array $list): array
    {
        $out = [];
        foreach ($list as $r) {
            if (!is_array($r)) {
                continue;
            }
            $key = (string)($r['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $vol       = (string)($r['volume'] ?? '');
            $publisher = '';
            $shortVol  = $vol;
            if (preg_match('/^(.*?)\s*-\s*([A-Z][A-Z0-9\/ ]*)\s*$/u', $vol, $m)) {
                $shortVol  = trim($m[1]);
                $publisher = trim($m[2]);
            }
            $out[$key] = [
                'code'      => $key,
                'title'     => (string)($r['book']    ?? ''),
                'volume'    => $shortVol,
                'publisher' => $publisher,
                'authors'   => (string)($r['authors'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Forma storica → registro.
     *
     * @param array<int|string, mixed> $dict
     * @return list<array{key: string, book: string, volume: string, authors: string}>
     */
    public static function fromLegacyDict(array $dict): array
    {
        $out = [];
        foreach ($dict as $code => $src) {
            if (!is_array($src)) {
                continue;
            }
            $key = is_string($code) && $code !== ''
                ? $code
                : (string)($src['code'] ?? '');
            if ($key === '') {
                continue;
            }
            $shortVol  = (string)($src['volume']    ?? '');
            $publisher = (string)($src['publisher'] ?? '');
            $vol = $publisher !== '' && $shortVol !== ''
                ? "$shortVol - $publisher"
                : ($shortVol !== '' ? $shortVol : $publisher);
            $out[] = [
                'key'     => $key,
                'book'    => (string)($src['title']   ?? ''),
                'volume'  => $vol,
                'authors' => (string)($src['authors'] ?? ''),
            ];
        }
        return $out;
    }
}
