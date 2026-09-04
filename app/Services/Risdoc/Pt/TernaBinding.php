<?php

declare(strict_types=1);

namespace App\Services\Risdoc\Pt;

/**
 * ADR-030 — Binding per-terna dei VALORI dei campi (mirror PHP di
 * js/modules/risdoc/pt/terna-binding.js) per il render server-side e la
 * migrazione.
 *
 * Un campo è 🔗 (per indirizzo/classe/materia) se ha `options_source.folder`
 * (auto) oppure `binding === "terna"` (esplicito). I valori per-terna vivono
 * in un blocco non-renderizzato `{_type:"ternaStore", store:{ternaKey:{key:val}}}`
 * dentro il body_pt (cifrato con esso).
 *
 * Chiavi: nodo top-level = `name`; cella tabella = `"{table.name}#{cell.cid}"`.
 */
final class TernaBinding
{
    private const STORE_TYPE = 'ternaStore';

    /** Tipi PT porta-valore (oltre alle celle tabella). */
    private const VALUE_TYPES = ['select', 'textField', 'formCheckbox', 'checkboxGroup', 'rawTex'];

    /** ADR-030 — in un doc terna_scoped (il binding gira SOLO per quei doc) OGNI
     *  campo-valore è per-classe di default; eccezione solo se marcato 📌 fisso. */
    private static function blockIsLinked(array $n): bool
    {
        if (!\in_array($n['_type'] ?? '', self::VALUE_TYPES, true)) {
            return false;
        }
        return ($n['binding'] ?? '') !== 'fixed';
    }

    /** ADR-030 scalabilità — value selezionati di un checkboxGroup (compatto). */
    private static function cbValues(array $n): array
    {
        $out = [];
        foreach ((\is_array($n['items'] ?? null) ? $n['items'] : []) as $it) {
            if (\is_array($it) && ((($it['state'] ?? '') === 'x') || !empty($it['checked']))) {
                $out[] = (string)($it['value'] ?? $it['label'] ?? '');
            }
        }
        return $out;
    }

    /** Espande i value salvati in item minimi (retro-compat con oggetti pieni). */
    private static function cbExpand(mixed $v): array
    {
        if (!\is_array($v)) {
            return [];
        }
        return \array_map(
            static fn($x) => \is_string($x) ? ['state' => 'x', 'value' => $x, 'label' => $x] : $x,
            $v
        );
    }

    private static function cellIsLinked(mixed $cell): bool
    {
        if (!\is_array($cell) || isset($cell['formula'])) {
            return false; // formula = struttura calcolata, mai per-classe
        }
        if (isset($cell['widget']) && \is_array($cell['widget'])) {
            return (($cell['widget']['binding'] ?? '') !== 'fixed')
                && (($cell['binding'] ?? '') !== 'fixed');
        }
        // Cella di solo testo: per-classe solo se marcata esplicitamente 🔗.
        return ($cell['binding'] ?? '') === 'terna';
    }

    /** Legge il valore di una cella 🔗 (widget.value o, per cella di testo, cell.text). */
    private static function readCell(array $cell): mixed
    {
        if (isset($cell['widget']) && \is_array($cell['widget'])) {
            return $cell['widget']['value'] ?? '';
        }
        return $cell['text'] ?? '';
    }

    /** Estrae lo store dei valori per-terna dal body_pt. @return array{0: list<array>, 1: array} [blocchi senza store, store] */
    public static function splitStore(array $blocks): array
    {
        $store = [];
        $clean = [];
        foreach ($blocks as $n) {
            if (\is_array($n) && (($n['_type'] ?? '') === self::STORE_TYPE)) {
                if (\is_array($n['store'] ?? null)) {
                    $store = $n['store'];
                }
                continue;
            }
            $clean[] = $n;
        }
        return [$clean, $store];
    }

    /**
     * RENDER server-side: applica i valori salvati della terna `$ternaKey` ai
     * campi 🔗 e RIMUOVE il blocco ternaStore. Ritorna i blocchi pronti per
     * PtToHtml. I campi senza valore per quella terna restano vuoti.
     */
    public static function applyAndStrip(array $blocks, string $ternaKey): array
    {
        [$clean, $store] = self::splitStore($blocks);
        $t = (\is_array($store[$ternaKey] ?? null)) ? $store[$ternaKey] : [];
        return self::walkApply($clean, $t);
    }

    /** @param array<string,mixed> $t valori della terna (key → value) */
    private static function walkApply(array $blocks, array $t): array
    {
        foreach ($blocks as &$n) {
            if (!\is_array($n)) {
                continue;
            }
            $type = $n['_type'] ?? '';
            if ($type === 'table' && \is_array($n['rows'] ?? null)) {
                $tableName = $n['name'] ?? '';
                foreach ($n['rows'] as &$row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    foreach ($row as &$cell) {
                        if (!self::cellIsLinked($cell)) {
                            continue;
                        }
                        $cid = $cell['cid'] ?? '';
                        if ($tableName === '' || $cid === '') {
                            continue;
                        }
                        $v = $t["{$tableName}#{$cid}"] ?? '';
                        if (isset($cell['widget']) && \is_array($cell['widget'])) {
                            $cell['widget']['value'] = $v;
                        } else {
                            $cell['text'] = \is_string($v) ? $v : '';
                        }
                    }
                    unset($cell);
                }
                unset($row);
                continue;
            }
            if ($type === 'accordion' && \is_array($n['items'] ?? null)) {
                foreach ($n['items'] as &$it) {
                    if (\is_array($it['body_pt'] ?? null)) {
                        $it['body_pt'] = self::walkApply($it['body_pt'], $t);
                    }
                }
                unset($it);
                continue;
            }
            if (self::blockIsLinked($n)) {
                $name = $n['name'] ?? '';
                $v = $name !== '' ? ($t[$name] ?? null) : null;
                switch ($type) {
                    case 'checkboxGroup': $n['items']   = self::cbExpand($v); break;
                    case 'select':        $n['value']   = \is_string($v) ? $v : ''; break;
                    case 'textField':     $n['value']   = \is_string($v) ? $v : ''; break;
                    case 'rawTex':        $n['content'] = \is_string($v) ? $v : ''; break;
                    case 'formCheckbox':  $n['checked'] = (bool)$v; break;
                }
            }
        }
        unset($n);
        return $blocks;
    }

    /**
     * MIGRAZIONE: estrae i valori 🔗 CORRENTI (inline) di `$blocks` in
     * `$store[$ternaKey]` e li azzera nella struttura. Ritorna
     * [blocchiStruttura, store] (lo store include la chiave aggiornata).
     *
     * @param array<string,mixed> $store store esistente (per accumulo multi-terna)
     * @return array{0: list<array>, 1: array}
     */
    public static function extract(array $blocks, string $ternaKey, array $store = []): array
    {
        [$clean, $existing] = self::splitStore($blocks);
        $store = $store ?: $existing;
        $delta = [];
        $clean = self::walkExtract($clean, $delta);
        $store[$ternaKey] = $delta;
        return [$clean, $store];
    }

    /** @param array<string,mixed> $delta accumulatore (per riferimento) */
    private static function walkExtract(array $blocks, array &$delta): array
    {
        foreach ($blocks as &$n) {
            if (!\is_array($n)) {
                continue;
            }
            $type = $n['_type'] ?? '';
            if ($type === 'table' && \is_array($n['rows'] ?? null)) {
                $tableName = $n['name'] ?? '';
                foreach ($n['rows'] as &$row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    foreach ($row as &$cell) {
                        if (!self::cellIsLinked($cell) || $tableName === '' || ($cell['cid'] ?? '') === '') {
                            continue;
                        }
                        $key = "{$tableName}#{$cell['cid']}";
                        $delta[$key] = self::readCell($cell);
                        if (isset($cell['widget']) && \is_array($cell['widget'])) {
                            $cell['widget']['value'] = \is_array($cell['widget']['value'] ?? null) ? [] : '';
                        } else {
                            $cell['text'] = '';
                        }
                    }
                    unset($cell);
                }
                unset($row);
                continue;
            }
            if ($type === 'accordion' && \is_array($n['items'] ?? null)) {
                foreach ($n['items'] as &$it) {
                    if (\is_array($it['body_pt'] ?? null)) {
                        $it['body_pt'] = self::walkExtract($it['body_pt'], $delta);
                    }
                }
                unset($it);
                continue;
            }
            if (self::blockIsLinked($n)) {
                $name = $n['name'] ?? '';
                if ($name === '') {
                    continue;
                }
                switch ($type) {
                    case 'checkboxGroup': $delta[$name] = self::cbValues($n); $n['items'] = []; break;
                    case 'select':        $delta[$name] = $n['value'] ?? '';   $n['value'] = ''; break;
                    case 'textField':     $delta[$name] = $n['value'] ?? '';   $n['value'] = ''; break;
                    case 'rawTex':        $delta[$name] = $n['content'] ?? ''; $n['content'] = ''; break;
                    case 'formCheckbox':  $delta[$name] = (bool)($n['checked'] ?? false); $n['checked'] = false; break;
                }
            }
        }
        unset($n);
        return $blocks;
    }

    /** Ricompone il body_pt con il blocco ternaStore in coda (no duplicati). */
    public static function attachStore(array $blocks, array $store): array
    {
        [$clean] = self::splitStore($blocks);
        $clean[] = ['_type' => self::STORE_TYPE, 'store' => $store];
        return $clean;
    }

    /** Il documento usa il modello per-terna? (metadata.terna_scoped). */
    public static function isTernaScoped(array $meta): bool
    {
        return ($meta['terna_scoped'] ?? null) === true;
    }

    /** Il body_pt ha almeno un campo 🔗? */
    public static function hasLinkedFields(array $blocks): bool
    {
        return \count(self::orderedLinked($blocks)) > 0;
    }

    private static function genId(string $prefix): string
    {
        return $prefix . '_' . substr(bin2hex(random_bytes(5)), 0, 8);
    }

    /** Assegna name/cid stabili ai campi 🔗 dove mancano (per la STRUTTURA
     *  canonica nel consolidamento). Muta $blocks. */
    public static function ensureIds(array &$blocks): void
    {
        foreach ($blocks as &$n) {
            if (!\is_array($n)) {
                continue;
            }
            $type = $n['_type'] ?? '';
            if ($type === 'table' && \is_array($n['rows'] ?? null)) {
                foreach ($n['rows'] as &$row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    foreach ($row as &$cell) {
                        if (self::cellIsLinked($cell)) {
                            if (($n['name'] ?? '') === '') {
                                $n['name'] = self::genId('tbl');
                            }
                            if (($cell['cid'] ?? '') === '') {
                                $cell['cid'] = self::genId('c');
                            }
                        }
                    }
                    unset($cell);
                }
                unset($row);
                continue;
            }
            if ($type === 'accordion' && \is_array($n['items'] ?? null)) {
                foreach ($n['items'] as &$it) {
                    if (\is_array($it['body_pt'] ?? null)) {
                        self::ensureIds($it['body_pt']);
                    }
                }
                unset($it);
                continue;
            }
            if (self::blockIsLinked($n) && ($n['name'] ?? '') === '') {
                $n['name'] = self::genId('fld');
            }
        }
        unset($n);
    }

    /**
     * Lista ORDINATA dei campi 🔗 (stesso ordine di traversata fra strutture
     * identiche): `[['key'=>?string, 'value'=>mixed], …]`. Per la canonica le
     * key sono valorizzate (dopo ensureIds); per un fork sorgente conta il VALUE
     * (le key generate indipendentemente si ignorano → zip per posizione).
     */
    public static function orderedLinked(array $blocks): array
    {
        $out = [];
        self::walkOrdered($blocks, $out);
        return $out;
    }

    private static function walkOrdered(array $blocks, array &$out): void
    {
        foreach ($blocks as $n) {
            if (!\is_array($n)) {
                continue;
            }
            $type = $n['_type'] ?? '';
            if ($type === 'table' && \is_array($n['rows'] ?? null)) {
                $tn = $n['name'] ?? '';
                foreach ($n['rows'] as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    foreach ($row as $cell) {
                        if (!self::cellIsLinked($cell)) {
                            continue;
                        }
                        $key = ($tn !== '' && ($cell['cid'] ?? '') !== '') ? "{$tn}#{$cell['cid']}" : null;
                        $out[] = ['key' => $key, 'value' => self::readCell($cell)];
                    }
                }
                continue;
            }
            if ($type === 'accordion' && \is_array($n['items'] ?? null)) {
                foreach ($n['items'] as $it) {
                    if (\is_array($it['body_pt'] ?? null)) {
                        self::walkOrdered($it['body_pt'], $out);
                    }
                }
                continue;
            }
            if (self::blockIsLinked($n)) {
                $key = ($n['name'] ?? '') !== '' ? $n['name'] : null;
                $val = match ($type) {
                    'checkboxGroup' => self::cbValues($n),
                    'select', 'textField' => $n['value'] ?? '',
                    'rawTex' => $n['content'] ?? '',
                    'formCheckbox' => (bool)($n['checked'] ?? false),
                    default => '',
                };
                $out[] = ['key' => $key, 'value' => $val];
            }
        }
    }
}
