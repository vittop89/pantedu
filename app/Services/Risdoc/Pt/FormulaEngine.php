<?php

declare(strict_types=1);

namespace App\Services\Risdoc\Pt;

/**
 * Motore di formule per tabelle PT (ADR-031) — mirror PHP di
 * js/modules/risdoc/pt/formula-engine.js, per il render server-side / PDF.
 * Mantenere allineata la semantica con la versione JS.
 */
final class FormulaError extends \RuntimeException
{
    public string $errCode;
    public function __construct(string $errCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errCode);
        $this->errCode = $errCode;
    }
}

final class FormulaEngine
{
    private const FUNC_ALIAS = [
        'SOMMA' => 'SUM', 'MEDIA' => 'AVERAGE', 'AVG' => 'AVERAGE', 'CONTA' => 'COUNT',
        'ARROTONDA' => 'ROUND', 'SE' => 'IF', 'PRODOTTO' => 'PRODUCT',
        'MEDIANA' => 'MEDIAN', 'RADQ' => 'SQRT', 'POTENZA' => 'POWER', 'INTERO' => 'INT', 'RESTO' => 'MOD',
        'ARROTONDA.PER.DIF' => 'ROUNDDOWN', 'ARROTONDA.PER.ECC' => 'ROUNDUP',
        'SE.ERRORE' => 'IFERROR', 'CONTA.SE' => 'COUNTIF', 'SOMMA.SE' => 'SUMIF',
        'E' => 'AND', 'O' => 'OR', 'NON' => 'NOT',
    ];

    /** @return list<array{r:int,c:int}> */
    private static function rangeCoords(array $node): array
    {
        if (($node['t'] ?? '') === 'range') {
            $a = self::parseRef($node['v'][0]); $b = self::parseRef($node['v'][1]);
            if (!$a || !$b) throw new FormulaError('#REF!', 'range non valido');
            $r0 = \min($a['r'], $b['r']); $r1 = \max($a['r'], $b['r']);
            $c0 = \min($a['c'], $b['c']); $c1 = \max($a['c'], $b['c']);
            $out = [];
            for ($r = $r0; $r <= $r1; $r++) { for ($c = $c0; $c <= $c1; $c++) { $out[] = ['r' => $r, 'c' => $c]; } }
            return $out;
        }
        if (($node['t'] ?? '') === 'ref') {
            $a = self::parseRef($node['v']); if (!$a) throw new FormulaError('#REF!');
            return [$a];
        }
        throw new FormulaError('#VALUE!', 'atteso un intervallo');
    }

    /** @return callable(float):bool */
    private static function makeCriterion(array $node): callable
    {
        if (($node['t'] ?? '') === 'num') { $v = (float)$node['v']; return static fn(float $x) => $x === $v; }
        if (($node['t'] ?? '') === 'str') {
            \preg_match('/^\s*(>=|<=|<>|>|<|=)?\s*(.+?)\s*$/', (string)$node['v'], $m);
            $op = $m[1] ?? '=';
            $v = self::parseNumber($m[2] ?? '')['num'];
            return match ($op) {
                '>'  => static fn(float $x) => $x > $v,
                '<'  => static fn(float $x) => $x < $v,
                '>=' => static fn(float $x) => $x >= $v,
                '<=' => static fn(float $x) => $x <= $v,
                '<>' => static fn(float $x) => $x !== $v,
                default => static fn(float $x) => $x === $v,
            };
        }
        throw new FormulaError('#VALUE!', 'criterio non valido');
    }

    public static function isFormula(mixed $s): bool
    {
        return \is_string($s) && \strlen(\ltrim($s)) > 0 && \ltrim($s)[0] === '=';
    }

    /** @return array{num: float, isNum: bool} */
    public static function parseNumber(mixed $v): array
    {
        if (\is_int($v) || \is_float($v)) {
            return ['num' => \is_finite((float)$v) ? (float)$v : 0.0, 'isNum' => \is_finite((float)$v)];
        }
        if ($v === null) {
            return ['num' => 0.0, 'isNum' => false];
        }
        $s = \trim((string)$v);
        if ($s === '') {
            return ['num' => 0.0, 'isNum' => false];
        }
        $s = \preg_replace('/[%€$ \s]/u', '', $s) ?? $s;
        if (\preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
            $s = \str_replace(['.', ','], ['', '.'], $s);
        } elseif (\preg_match('/^-?\d+(,\d+)?$/', $s)) {
            $s = \str_replace(',', '.', $s);
        }
        if (!\preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return ['num' => 0.0, 'isNum' => false];
        }
        return ['num' => (float)$s, 'isNum' => true];
    }

    private static function colToIndex(string $letters): int
    {
        $n = 0;
        $up = \strtoupper($letters);
        for ($i = 0, $L = \strlen($up); $i < $L; $i++) {
            $n = $n * 26 + (\ord($up[$i]) - 64);
        }
        return $n - 1;
    }

    /** @return array{r:int,c:int}|null */
    public static function parseRef(string $ref): ?array
    {
        if (!\preg_match('/^([A-Za-z]+)(\d+)$/', $ref, $m)) {
            return null;
        }
        $c = self::colToIndex($m[1]);
        $r = (int)$m[2] - 1;
        if ($r < 0 || $c < 0) {
            return null;
        }
        return ['r' => $r, 'c' => $c];
    }

    // ── Tokenizer ─────────────────────────────────────────────────────────────
    /** @return list<array> */
    private static function tokenize(string $s): array
    {
        $t = [];
        $i = 0;
        $L = \strlen($s);
        $isAl = static fn($ch) => $ch !== '' && \preg_match('/[A-Za-z]/', $ch) === 1;
        $isDig = static fn($ch) => $ch !== '' && $ch >= '0' && $ch <= '9';
        while ($i < $L) {
            $ch = $s[$i];
            if (\preg_match('/\s/', $ch)) { $i++; continue; }
            if ($isDig($ch) || ($ch === '.' && $isDig($s[$i + 1] ?? ''))) {
                $j = $i + 1;
                while ($j < $L && \preg_match('/[0-9.]/', $s[$j])) { $j++; }
                $t[] = ['t' => 'num', 'v' => (float)\substr($s, $i, $j - $i)];
                $i = $j; continue;
            }
            if ($ch === '"' || $ch === "'") {
                $q = $ch; $j = $i + 1; $buf = '';
                while ($j < $L && $s[$j] !== $q) { $buf .= $s[$j]; $j++; }
                if (($s[$j] ?? '') !== $q) throw new FormulaError('#ERR!', 'stringa non chiusa');
                $t[] = ['t' => 'str', 'v' => $buf];
                $i = $j + 1; continue;
            }
            if ($isAl($ch)) {
                $j = $i + 1;
                while ($j < $L && $isAl($s[$j])) { $j++; }
                if ($isDig($s[$j] ?? '')) {
                    $k = $j;
                    while ($k < $L && $isDig($s[$k])) { $k++; }
                    $ref1 = \substr($s, $i, $k - $i);
                    if (($s[$k] ?? '') === ':') {
                        $m = $k + 1;
                        while ($m < $L && $isAl($s[$m])) { $m++; }
                        while ($m < $L && $isDig($s[$m])) { $m++; }
                        $t[] = ['t' => 'range', 'v' => [$ref1, \substr($s, $k + 1, $m - $k - 1)]];
                        $i = $m; continue;
                    }
                    $t[] = ['t' => 'ref', 'v' => $ref1];
                    $i = $k; continue;
                }
                // nome funzione PUNTATO (CONTA.SE, SE.ERRORE, ARROTONDA.PER.DIF)
                while (($s[$j] ?? '') === '.' && $isAl($s[$j + 1] ?? '')) {
                    $j++;
                    while ($j < $L && $isAl($s[$j])) { $j++; }
                }
                $t[] = ['t' => 'name', 'v' => \substr($s, $i, $j - $i)];
                $i = $j; continue;
            }
            $two = \substr($s, $i, 2);
            if ($two === '<=' || $two === '>=' || $two === '<>') { $t[] = ['t' => 'op', 'v' => $two]; $i += 2; continue; }
            if (\strpos('+-*/^()=<>&', $ch) !== false) { $t[] = ['t' => 'op', 'v' => $ch]; $i++; continue; }
            if ($ch === ',' || $ch === ';') { $t[] = ['t' => 'sep']; $i++; continue; }
            throw new FormulaError('#ERR!', "carattere non valido: '$ch'");
        }
        return $t;
    }

    // ── Parser ────────────────────────────────────────────────────────────────
    private static function parse(array $tokens): array
    {
        $p = 0;
        $peek = static function () use (&$p, $tokens) { return $tokens[$p] ?? null; };
        $next = static function () use (&$p, $tokens) { return $tokens[$p++] ?? null; };
        $expect = static function (string $v) use ($next) {
            $tk = $next();
            if (!$tk || ($tk['t'] ?? '') !== 'op' || ($tk['v'] ?? '') !== $v) {
                throw new FormulaError('#ERR!', "atteso '$v'");
            }
        };

        $parseExpr = null; $parseCompare = null; $parseConcat = null; $parseAddSub = null; $parseMulDiv = null;
        $parsePow = null; $parseUnary = null; $parsePrimary = null;

        $parseCompare = function () use (&$parseConcat, $peek, $next) {
            $left = $parseConcat();
            $tk = $peek();
            if ($tk && ($tk['t'] ?? '') === 'op' && \in_array($tk['v'], ['=', '<', '>', '<=', '>=', '<>'], true)) {
                $next(); $right = $parseConcat();
                return ['t' => 'cmp', 'op' => $tk['v'], 'left' => $left, 'right' => $right];
            }
            return $left;
        };
        // Concatenazione testo `&` (sotto +- e sopra i confronti, come Excel).
        $parseConcat = function () use (&$parseAddSub, $peek, $next) {
            $left = $parseAddSub();
            while (($tk = $peek()) && ($tk['t'] ?? '') === 'op' && $tk['v'] === '&') {
                $next(); $right = $parseAddSub();
                $left = ['t' => 'concat', 'left' => $left, 'right' => $right];
            }
            return $left;
        };
        $parseExpr = function () use (&$parseCompare) { return $parseCompare(); };
        $parseAddSub = function () use (&$parseMulDiv, $peek, $next) {
            $left = $parseMulDiv();
            while (($tk = $peek()) && ($tk['t'] ?? '') === 'op' && ($tk['v'] === '+' || $tk['v'] === '-')) {
                $op = $next()['v']; $right = $parseMulDiv();
                $left = ['t' => 'bin', 'op' => $op, 'left' => $left, 'right' => $right];
            }
            return $left;
        };
        $parseMulDiv = function () use (&$parsePow, $peek, $next) {
            $left = $parsePow();
            while (($tk = $peek()) && ($tk['t'] ?? '') === 'op' && ($tk['v'] === '*' || $tk['v'] === '/')) {
                $op = $next()['v']; $right = $parsePow();
                $left = ['t' => 'bin', 'op' => $op, 'left' => $left, 'right' => $right];
            }
            return $left;
        };
        $parsePow = function () use (&$parseUnary, &$parsePow, $peek, $next) {
            $left = $parseUnary();
            $tk = $peek();
            if ($tk && ($tk['t'] ?? '') === 'op' && $tk['v'] === '^') {
                $next(); $right = $parsePow();
                return ['t' => 'bin', 'op' => '^', 'left' => $left, 'right' => $right];
            }
            return $left;
        };
        $parseUnary = function () use (&$parseUnary, &$parsePrimary, $peek, $next) {
            $tk = $peek();
            if ($tk && ($tk['t'] ?? '') === 'op' && ($tk['v'] === '-' || $tk['v'] === '+')) {
                $next(); return ['t' => 'unary', 'op' => $tk['v'], 'arg' => $parseUnary()];
            }
            return $parsePrimary();
        };
        $parsePrimary = function () use (&$parseExpr, $peek, $next, $expect) {
            $tk = $next();
            if (!$tk) throw new FormulaError('#ERR!', 'formula incompleta');
            $tt = $tk['t'] ?? '';
            if ($tt === 'num') return ['t' => 'num', 'v' => $tk['v']];
            if ($tt === 'str') return ['t' => 'str', 'v' => $tk['v']];
            if ($tt === 'ref') return ['t' => 'ref', 'v' => $tk['v']];
            if ($tt === 'range') return ['t' => 'range', 'v' => $tk['v']];
            if ($tt === 'op' && $tk['v'] === '(') { $e = $parseExpr(); $expect(')'); return $e; }
            if ($tt === 'name') {
                $pk = $peek();
                if ($pk && ($pk['t'] ?? '') === 'op' && $pk['v'] === '(') {
                    $next(); $args = [];
                    $pk2 = $peek();
                    if (!($pk2 && ($pk2['t'] ?? '') === 'op' && $pk2['v'] === ')')) {
                        $args[] = $parseExpr();
                        while (($s = $peek()) && ($s['t'] ?? '') === 'sep') { $next(); $args[] = $parseExpr(); }
                    }
                    $expect(')');
                    return ['t' => 'func', 'name' => \strtoupper($tk['v']), 'args' => $args];
                }
                throw new FormulaError('#NAME?', "nome sconosciuto: '{$tk['v']}'");
            }
            throw new FormulaError('#ERR!', 'token inatteso');
        };

        $ast = $parseExpr();
        if ($p !== \count($tokens)) {
            throw new FormulaError('#ERR!', 'token in eccesso');
        }
        return $ast;
    }

    // ── Valori = float|string. Helper di conversione ────────────────────────────
    /** Numero → stringa IT (virgola, niente .0 inutile). Stringa → invariata. */
    private static function numToStr(float|string $n): string
    {
        if (\is_string($n)) return $n;
        if (!\is_finite($n)) return '';
        $s = \rtrim(\rtrim(\sprintf('%.10F', \round($n * 1e10) / 1e10), '0'), '.');
        return \str_replace('.', ',', $s);
    }
    /** Qualsiasi valore → stringa (concatenazione `&`). */
    private static function valToStr(float|string $v): string
    {
        return \is_string($v) ? $v : self::numToStr($v);
    }
    /** Qualsiasi valore → numero (aritmetica/confronti). Stringa non numerica → #VALUE!. */
    private static function toNum(float|string $v): float
    {
        if (!\is_string($v)) return $v;
        $pn = self::parseNumber($v);
        if (!$pn['isNum']) throw new FormulaError('#VALUE!', 'atteso un numero');
        return $pn['num'];
    }

    // ── Valutatore ──────────────────────────────────────────────────────────────
    /** @param callable $cell fn(int $r,int $c): array{num:float,isNum:bool,val?:float|string} */
    private static function evalNode(array $node, callable $cell): float|string
    {
        switch ($node['t']) {
            case 'num': return (float)$node['v'];
            case 'str': return (string)$node['v']; // testo: valido come valore
            case 'ref': {
                $ref = self::parseRef($node['v']);
                if (!$ref) throw new FormulaError('#REF!', $node['v']);
                $cv = $cell($ref['r'], $ref['c']);
                return $cv['val'] ?? $cv['num']; // val = float|string; fallback num
            }
            case 'range': throw new FormulaError('#VALUE!', 'range non ammesso qui');
            case 'concat': // `&` → concatena come testo
                return self::valToStr(self::evalNode($node['left'], $cell)) . self::valToStr(self::evalNode($node['right'], $cell));
            case 'unary': {
                $a = self::toNum(self::evalNode($node['arg'], $cell));
                return $node['op'] === '-' ? -$a : $a;
            }
            case 'bin': {
                $a = self::toNum(self::evalNode($node['left'], $cell)); $b = self::toNum(self::evalNode($node['right'], $cell));
                switch ($node['op']) {
                    case '+': return $a + $b;
                    case '-': return $a - $b;
                    case '*': return $a * $b;
                    case '/': if ($b == 0.0) throw new FormulaError('#DIV/0!'); return $a / $b;
                    case '^': return $a ** $b;
                }
                throw new FormulaError('#ERR!');
            }
            case 'cmp': {
                $a = self::toNum(self::evalNode($node['left'], $cell)); $b = self::toNum(self::evalNode($node['right'], $cell));
                switch ($node['op']) {
                    case '=':  $r = $a === $b; break;
                    case '<>': $r = $a !== $b; break;
                    case '<':  $r = $a < $b; break;
                    case '>':  $r = $a > $b; break;
                    case '<=': $r = $a <= $b; break;
                    case '>=': $r = $a >= $b; break;
                    default: throw new FormulaError('#ERR!');
                }
                return $r ? 1.0 : 0.0;
            }
            case 'func': {
                $name = self::FUNC_ALIAS[$node['name']] ?? $node['name'];
                return self::applyFunc($name, $node['args'], $cell);
            }
        }
        throw new FormulaError('#ERR!');
    }

    /** @return list<float> */
    private static function collectNumbers(array $node, callable $cell, bool $forCount): array
    {
        if (($node['t'] ?? '') === 'range') {
            $a = self::parseRef($node['v'][0]); $b = self::parseRef($node['v'][1]);
            if (!$a || !$b) throw new FormulaError('#REF!', 'range non valido');
            $r0 = \min($a['r'], $b['r']); $r1 = \max($a['r'], $b['r']);
            $c0 = \min($a['c'], $b['c']); $c1 = \max($a['c'], $b['c']);
            $out = [];
            for ($r = $r0; $r <= $r1; $r++) {
                for ($c = $c0; $c <= $c1; $c++) {
                    $cv = $cell($r, $c);
                    if ($forCount) { if ($cv['isNum']) $out[] = 1.0; }
                    else $out[] = $cv['num'];
                }
            }
            return $out;
        }
        $v = self::toNum(self::evalNode($node, $cell));
        return $forCount ? [1.0] : [$v];
    }

    private static function applyFunc(string $name, array $args, callable $cell): float|string
    {
        $nums = static function () use ($args, $cell) {
            $out = [];
            foreach ($args as $a) { foreach (self::collectNumbers($a, $cell, false) as $x) { $out[] = $x; } }
            return $out;
        };
        $en = static fn(int $i): float => self::toNum(self::evalNode($args[$i], $cell)); // arg i come numero
        switch ($name) {
            case 'SUM':     return \array_sum($nums());
            case 'PRODUCT': { $p = 1.0; foreach ($nums() as $x) { $p *= $x; } return $p; }
            case 'MIN':     { $a = $nums(); return $a ? \min($a) : 0.0; }
            case 'MAX':     { $a = $nums(); return $a ? \max($a) : 0.0; }
            case 'AVERAGE': {
                $a = $nums(); $cnt = 0;
                foreach ($args as $x) { $cnt += \count(self::collectNumbers($x, $cell, true)); }
                if ($cnt === 0) throw new FormulaError('#DIV/0!');
                return \array_sum($a) / $cnt;
            }
            case 'COUNT': { $cnt = 0; foreach ($args as $a) { $cnt += \count(self::collectNumbers($a, $cell, true)); } return (float)$cnt; }
            case 'ABS':   return \abs($en(0));
            case 'ROUND': {
                $x = $en(0);
                $d = \count($args) > 1 ? (int)$en(1) : 0;
                $f = 10 ** $d;
                return \round($x * $f) / $f;
            }
            case 'TESTO': case 'TEXT': {
                // TESTO(valore; [decimali]) → stringa IT.
                $x = $en(0);
                if (\count($args) > 1) { $d = \max(0, (int)$en(1)); return \str_replace('.', ',', \number_format($x, $d, '.', '')); }
                return self::numToStr($x);
            }
            case 'PERCENTUALE': case 'PERCENT': {
                // PERCENTUALE(numero; [decimali]) → "…%". NON moltiplica per 100.
                // - con decimali: SEMPRE quel numero di cifre (;2 → "10,00%")
                // - senza: max 2 decimali, zeri finali tolti (10 → "10%")
                $x = $en(0);
                if (\count($args) > 1) {
                    $d = \max(0, (int)$en(1));
                    return \str_replace('.', ',', \number_format($x, $d, '.', '')) . '%';
                }
                return self::numToStr(\round($x * 100) / 100) . '%';
            }
            case 'IF': {
                if (\count($args) < 2) throw new FormulaError('#ERR!', 'SE richiede almeno 2 argomenti');
                $cond = $en(0); // condizione come numero; rami preservano il tipo
                return $cond != 0.0 ? self::evalNode($args[1], $cell) : (\count($args) > 2 ? self::evalNode($args[2], $cell) : 0.0);
            }
            case 'MEDIAN': {
                $a = $nums(); \sort($a);
                $n = \count($a); if ($n === 0) throw new FormulaError('#DIV/0!');
                $m = \intdiv($n, 2);
                return $n % 2 ? $a[$m] : ($a[$m - 1] + $a[$m]) / 2;
            }
            case 'INT': return \floor($en(0));
            case 'MOD': {
                $a = $en(0); $b = $en(1);
                if ($b == 0.0) throw new FormulaError('#DIV/0!');
                return $a - $b * \floor($a / $b);
            }
            case 'SQRT': { $x = $en(0); if ($x < 0) throw new FormulaError('#VALUE!'); return \sqrt($x); }
            case 'POWER': return $en(0) ** $en(1);
            case 'ROUNDDOWN': {
                $x = $en(0);
                $d = \count($args) > 1 ? (int)$en(1) : 0;
                $f = 10 ** $d; $v = $x * $f;
                return ($v < 0 ? \ceil($v) : \floor($v)) / $f; // verso lo zero
            }
            case 'ROUNDUP': {
                $x = $en(0);
                $d = \count($args) > 1 ? (int)$en(1) : 0;
                $f = 10 ** $d; $v = $x * $f;
                return ($v < 0 ? \floor($v) : \ceil($v)) / $f; // lontano dallo zero
            }
            case 'IFERROR': {
                try { return self::evalNode($args[0], $cell); } // preserva il tipo
                catch (FormulaError $e) { return self::evalNode($args[1], $cell); }
            }
            case 'AND': { foreach ($args as $a) { if (self::toNum(self::evalNode($a, $cell)) == 0.0) return 0.0; } return 1.0; }
            case 'OR':  { foreach ($args as $a) { if (self::toNum(self::evalNode($a, $cell)) != 0.0) return 1.0; } return 0.0; }
            case 'NOT': return self::toNum(self::evalNode($args[0], $cell)) != 0.0 ? 0.0 : 1.0;
            case 'COUNTIF': {
                $coords = self::rangeCoords($args[0]); $pred = self::makeCriterion($args[1]);
                $n = 0;
                foreach ($coords as $co) { $cv = $cell($co['r'], $co['c']); if ($cv['isNum'] && $pred($cv['num'])) $n++; }
                return (float)$n;
            }
            case 'SUMIF': {
                $coords = self::rangeCoords($args[0]); $pred = self::makeCriterion($args[1]);
                $sumCoords = \count($args) > 2 ? self::rangeCoords($args[2]) : $coords;
                $s = 0.0;
                foreach ($coords as $k => $co) {
                    $cc = $cell($co['r'], $co['c']);
                    if ($pred($cc['num'])) { $sc = isset($sumCoords[$k]) ? $cell($sumCoords[$k]['r'], $sumCoords[$k]['c']) : $cc; $s += $sc['num']; }
                }
                return $s;
            }
        }
        throw new FormulaError('#NAME?', $name);
    }

    /** Formula vuota/incompleta (solo "=" o spazi)? */
    public static function isEmptyFormula(mixed $formula): bool
    {
        return \trim(\preg_replace('/^=/', '', \trim((string)($formula ?? ''))) ?? '') === '';
    }

    /** Valuta una formula. $resolveCell = fn(r,c) → {num,isNum,val?}. Ritorna numero o testo. */
    public static function evaluateFormula(string $formula, callable $resolveCell): float|string
    {
        $src = \preg_replace('/^=/', '', \trim($formula)) ?? '';
        if (\trim($src) === '') {
            return 0.0; // formula vuota → 0
        }
        $ast = self::parse(self::tokenize($src));
        return self::evalNode($ast, $resolveCell);
    }

    /**
     * Calcola tutte le celle formula della griglia (dipendenze + cicli).
     * @param array<int, array<int, array{formula?:string, raw?:mixed}>> $grid
     * @return array<int, array<int, array{display:string, value:?float, error:?string, isFormula:bool}>>
     */
    public static function computeTableValues(array $grid, array $opts = []): array
    {
        $memo = [];
        $computing = [];
        $decimals = isset($opts['decimals']) && \is_int($opts['decimals']) ? $opts['decimals'] : null;

        $cellAt = static function (int $r, int $c) use ($grid) {
            return $grid[$r][$c] ?? null;
        };

        $computeCell = null;
        $computeCell = function (int $r, int $c) use (&$computeCell, &$memo, &$computing, $cellAt): array {
            $key = "$r,$c";
            if (isset($memo[$key])) return $memo[$key];
            $cell = $cellAt($r, $c);
            if (!$cell) return ['num' => 0.0, 'isNum' => false];
            if (isset($cell['formula']) && self::isFormula($cell['formula'])) {
                if (isset($computing[$key])) throw new FormulaError('#CIRC!', 'riferimento circolare');
                $computing[$key] = true;
                try {
                    $v = self::evaluateFormula($cell['formula'], static fn($rr, $cc) => $computeCell($rr, $cc));
                    // $v può essere numero o stringa (concat/TESTO/PERCENTUALE). Per i
                    // riferimenti numerici da altre formule, num = parse della stringa.
                    $isNumV = !\is_string($v) && \is_finite((float)$v);
                    $pn = $isNumV ? null : self::parseNumber((string)$v);
                    $res = ['num' => $isNumV ? (float)$v : $pn['num'], 'isNum' => $isNumV ? true : $pn['isNum'], 'val' => $v];
                    $memo[$key] = $res;
                    return $res;
                } finally { unset($computing[$key]); }
            }
            $pn = self::parseNumber($cell['raw'] ?? '');
            $res = ['num' => $pn['num'], 'isNum' => $pn['isNum'], 'val' => $pn['isNum'] ? $pn['num'] : (string)($cell['raw'] ?? '')];
            $memo[$key] = $res;
            return $res;
        };

        $fmt = static function (float $n) use ($decimals): string {
            if (!\is_finite($n)) return '';
            $x = $n;
            if ($decimals !== null) { $f = 10 ** $decimals; $x = \round($n * $f) / $f; }
            $x = \round($x * 1e10) / 1e10;
            $s = \rtrim(\rtrim(\sprintf('%.10F', $x), '0'), '.');
            return \str_replace('.', ',', $s);
        };

        $out = [];
        foreach ($grid as $r => $row) {
            $out[$r] = [];
            foreach ($row as $c => $cell) {
                if (\is_array($cell) && isset($cell['formula']) && self::isFormula($cell['formula'])) {
                    if (self::isEmptyFormula($cell['formula'])) {
                        $out[$r][$c] = ['display' => '', 'value' => null, 'error' => null, 'isFormula' => true];
                        continue;
                    }
                    try {
                        $cv = $computeCell($r, $c);
                        $v = $cv['val'] ?? $cv['num'];
                        if (\is_string($v)) {
                            $out[$r][$c] = ['display' => $v, 'value' => $cv['isNum'] ? $cv['num'] : null, 'error' => null, 'isFormula' => true];
                        } else {
                            $out[$r][$c] = ['display' => $fmt($v), 'value' => $v, 'error' => null, 'isFormula' => true];
                        }
                    } catch (FormulaError $e) {
                        $out[$r][$c] = ['display' => $e->errCode, 'value' => null, 'error' => $e->errCode, 'isFormula' => true];
                    } catch (\Throwable $e) {
                        $out[$r][$c] = ['display' => '#ERR!', 'value' => null, 'error' => '#ERR!', 'isFormula' => true];
                    }
                } else {
                    $raw = \is_array($cell) ? ($cell['raw'] ?? '') : '';
                    $out[$r][$c] = ['display' => (string)$raw, 'value' => self::parseNumber($raw)['num'], 'error' => null, 'isFormula' => false];
                }
            }
        }
        return $out;
    }
}
