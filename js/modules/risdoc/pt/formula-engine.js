/**
 * Motore di formule per tabelle PT (ADR-031) — stile Excel, sicuro (nessun eval).
 *
 * Sintassi:
 *   - inizia con "=" (es. "=SOMMA(B2:B5)", "=B2/ B3*100", "=SE(A1>10; 1; 0)").
 *   - riferimenti A1: colonna lettere (A,B,…,AA) + numero riga 1-based (A1, B2).
 *   - range: A1:B3.
 *   - operatori: + - * / ^  (unari + -),  confronti = <> < > <= >= (ritornano 1/0).
 *   - separatore argomenti: ";" (stile Excel IT) oppure ",". Decimali nei letterali: ".".
 *   - funzioni (alias IT/EN): SUM/SOMMA, AVERAGE/MEDIA, MIN, MAX, COUNT/CONTA,
 *     ROUND/ARROTONDA, IF/SE, ABS, PRODUCT/PRODOTTO.
 *
 * I VALORI delle celle referenziate vengono letti come numeri (parsing IT: "7,5"
 * → 7.5; "1.234,56" → 1234.56). Celle vuote/non numeriche = 0 (COUNT conta solo
 * le numeriche).
 *
 * Errori (FormulaError.code): #DIV/0!, #REF!, #NAME?, #CIRC!, #VALUE!, #ERR!.
 *
 * Condiviso col mirror PHP (FormulaEngine.php) per il render server/PDF. Mantenere
 * le due implementazioni allineate (vedi ADR-030 per il pattern mirror).
 */

export class FormulaError extends Error {
    constructor(code, message) { super(message || code); this.code = code; this.name = "FormulaError"; }
}

/** Una stringa è una formula? (inizia con "=", trim). */
export function isFormula(s) {
    return typeof s === "string" && s.trim().charAt(0) === "=";
}

/** Parsing numerico tollerante (IT/EN). Ritorna {num, isNum}. Vuoto/non-numerico → {num:0, isNum:false}. */
export function parseNumber(v) {
    if (typeof v === "number") return { num: isFinite(v) ? v : 0, isNum: isFinite(v) };
    if (v == null) return { num: 0, isNum: false };
    let s = String(v).trim();
    if (s === "") return { num: 0, isNum: false };
    s = s.replace(/[%€$ \s]/g, "");
    if (/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(s)) s = s.replace(/\./g, "").replace(",", "."); // 1.234,56
    else if (/^-?\d+(,\d+)?$/.test(s)) s = s.replace(",", ".");                              // 7,5
    if (!/^-?\d+(\.\d+)?$/.test(s)) return { num: 0, isNum: false };
    const n = parseFloat(s);
    return isFinite(n) ? { num: n, isNum: true } : { num: 0, isNum: false };
}

// ── Riferimenti A1 ──────────────────────────────────────────────────────────
function colToIndex(letters) {
    let n = 0;
    const up = letters.toUpperCase();
    for (let i = 0; i < up.length; i++) n = n * 26 + (up.charCodeAt(i) - 64);
    return n - 1; // A→0
}
/** "B2" → {r:1, c:1} (0-based). Null se non valido. */
export function parseRef(ref) {
    const m = /^([A-Za-z]+)(\d+)$/.exec(ref);
    if (!m) return null;
    const c = colToIndex(m[1]);
    const r = parseInt(m[2], 10) - 1;
    if (r < 0 || c < 0) return null;
    return { r, c };
}
/** Indice colonna 0-based → lettere (0→A, 25→Z, 26→AA). Inverso di colToIndex. */
export function colName(n) {
    let s = "";
    let x = n;
    do { s = String.fromCharCode(65 + (x % 26)) + s; x = Math.floor(x / 26) - 1; } while (x >= 0);
    return s;
}

/**
 * Sposta i riferimenti RELATIVI di una formula di (dR righe, dC colonne) —
 * come il riempimento di Excel. Usa il tokenizer (NON tocca i riferimenti
 * dentro i letterali stringa né i nomi funzione). Se un riferimento esce dalla
 * griglia (riga/colonna < 0) resta invariato. Ricostruisce la formula in forma
 * canonica (spazi non significativi rimossi).
 */
export function offsetFormula(formula, dR, dC) {
    const s = String(formula);
    if (!isFormula(s) || (dR === 0 && dC === 0)) return s;
    const src = s.replace(/^=/, "");
    let toks;
    try { toks = tokenize(src); } catch { return s; }
    const shift = (ref) => {
        const p = parseRef(ref);
        if (!p) return ref;
        const r = p.r + dR, c = p.c + dC;
        if (r < 0 || c < 0) return ref; // fuori griglia → invariato
        return colName(c) + (r + 1);
    };
    const out = toks.map((t) => {
        switch (t.t) {
            case "num":   return String(t.v);
            case "str":   return '"' + t.v + '"';
            case "ref":   return shift(t.v);
            case "range": return shift(t.v[0]) + ":" + shift(t.v[1]);
            case "name":  return t.v;
            case "op":    return t.v;
            case "sep":   return ";";
            default:      return "";
        }
    });
    return "=" + out.join("");
}

// ── Tokenizer ───────────────────────────────────────────────────────────────
function tokenize(src) {
    const t = [];
    let i = 0;
    const s = src;
    const isAl = (ch) => /[A-Za-z]/.test(ch);
    const isDig = (ch) => /[0-9]/.test(ch);
    while (i < s.length) {
        const ch = s[i];
        if (/\s/.test(ch)) { i++; continue; }
        if (isDig(ch) || (ch === "." && isDig(s[i + 1] || ""))) {
            let j = i + 1;
            while (j < s.length && /[0-9.]/.test(s[j])) j++;
            t.push({ t: "num", v: parseFloat(s.slice(i, j)) });
            i = j; continue;
        }
        if (ch === '"' || ch === "'") {
            const q = ch; let j = i + 1; let buf = "";
            while (j < s.length && s[j] !== q) { buf += s[j]; j++; }
            if (s[j] !== q) throw new FormulaError("#ERR!", "stringa non chiusa");
            t.push({ t: "str", v: buf });
            i = j + 1; continue;
        }
        if (isAl(ch)) {
            let j = i + 1;
            while (j < s.length && isAl(s[j])) j++;
            // ref/range? lettere seguite da cifre (A1, B2:C5)
            if (isDig(s[j] || "")) {
                let k = j;
                while (k < s.length && isDig(s[k])) k++;
                const ref1 = s.slice(i, k);
                if (s[k] === ":") {
                    let m = k + 1;
                    while (m < s.length && isAl(s[m])) m++;
                    while (m < s.length && isDig(s[m])) m++;
                    t.push({ t: "range", v: [ref1, s.slice(k + 1, m)] });
                    i = m; continue;
                }
                t.push({ t: "ref", v: ref1 });
                i = k; continue;
            }
            // nome funzione, eventualmente PUNTATO (CONTA.SE, SE.ERRORE, ARROTONDA.PER.DIF)
            while (s[j] === "." && isAl(s[j + 1] || "")) { j++; while (j < s.length && isAl(s[j])) j++; }
            t.push({ t: "name", v: s.slice(i, j) });
            i = j; continue;
        }
        // operatori a 2 caratteri
        const two = s.slice(i, i + 2);
        if (two === "<=" || two === ">=" || two === "<>") { t.push({ t: "op", v: two }); i += 2; continue; }
        if ("+-*/^()=<>&".includes(ch)) { t.push({ t: "op", v: ch }); i++; continue; }
        if (ch === "," || ch === ";") { t.push({ t: "sep" }); i++; continue; }
        throw new FormulaError("#ERR!", `carattere non valido: '${ch}'`);
    }
    return t;
}

// ── Parser ricorsivo (AST) ──────────────────────────────────────────────────
function parse(tokens) {
    let p = 0;
    const peek = () => tokens[p];
    const next = () => tokens[p++];
    const expect = (v) => { const tk = next(); if (!tk || tk.t !== "op" || tk.v !== v) throw new FormulaError("#ERR!", `atteso '${v}'`); };

    function parseExpr() { return parseCompare(); }
    function parseCompare() {
        let left = parseConcat();
        const tk = peek();
        if (tk && tk.t === "op" && ["=", "<", ">", "<=", ">=", "<>"].includes(tk.v)) {
            next(); const right = parseConcat();
            return { t: "cmp", op: tk.v, left, right };
        }
        return left;
    }
    // Concatenazione testo `&` (precedenza Excel: sotto +- e sopra i confronti).
    function parseConcat() {
        let left = parseAddSub();
        while (peek() && peek().t === "op" && peek().v === "&") {
            next(); const right = parseAddSub();
            left = { t: "concat", left, right };
        }
        return left;
    }
    function parseAddSub() {
        let left = parseMulDiv();
        while (peek() && peek().t === "op" && (peek().v === "+" || peek().v === "-")) {
            const op = next().v; const right = parseMulDiv();
            left = { t: "bin", op, left, right };
        }
        return left;
    }
    function parseMulDiv() {
        let left = parsePow();
        while (peek() && peek().t === "op" && (peek().v === "*" || peek().v === "/")) {
            const op = next().v; const right = parsePow();
            left = { t: "bin", op, left, right };
        }
        return left;
    }
    function parsePow() {
        const left = parseUnary();
        if (peek() && peek().t === "op" && peek().v === "^") {
            next(); const right = parsePow(); // destra-associativo
            return { t: "bin", op: "^", left, right };
        }
        return left;
    }
    function parseUnary() {
        const tk = peek();
        if (tk && tk.t === "op" && (tk.v === "-" || tk.v === "+")) { next(); return { t: "unary", op: tk.v, arg: parseUnary() }; }
        return parsePrimary();
    }
    function parsePrimary() {
        const tk = next();
        if (!tk) throw new FormulaError("#ERR!", "formula incompleta");
        if (tk.t === "num") return { t: "num", v: tk.v };
        if (tk.t === "str") return { t: "str", v: tk.v };
        if (tk.t === "ref") return { t: "ref", v: tk.v };
        if (tk.t === "range") return { t: "range", v: tk.v };
        if (tk.t === "op" && tk.v === "(") { const e = parseExpr(); expect(")"); return e; }
        if (tk.t === "name") {
            if (peek() && peek().t === "op" && peek().v === "(") {
                next(); const args = [];
                if (!(peek() && peek().t === "op" && peek().v === ")")) {
                    args.push(parseExpr());
                    while (peek() && peek().t === "sep") { next(); args.push(parseExpr()); }
                }
                expect(")");
                return { t: "func", name: tk.v.toUpperCase(), args };
            }
            throw new FormulaError("#NAME?", `nome sconosciuto: '${tk.v}'`);
        }
        throw new FormulaError("#ERR!", "token inatteso");
    }

    const ast = parseExpr();
    if (p !== tokens.length) throw new FormulaError("#ERR!", "token in eccesso");
    return ast;
}

// ── Valutatore ──────────────────────────────────────────────────────────────
const FUNC_ALIAS = {
    SOMMA: "SUM", MEDIA: "AVERAGE", AVG: "AVERAGE", CONTA: "COUNT",
    ARROTONDA: "ROUND", SE: "IF", PRODOTTO: "PRODUCT",
    MEDIANA: "MEDIAN", RADQ: "SQRT", POTENZA: "POWER", INTERO: "INT", RESTO: "MOD",
    "ARROTONDA.PER.DIF": "ROUNDDOWN", "ARROTONDA.PER.ECC": "ROUNDUP",
    "SE.ERRORE": "IFERROR", "CONTA.SE": "COUNTIF", "SOMMA.SE": "SUMIF",
    E: "AND", O: "OR", NON: "NOT",
};

/** Espande un nodo range/ref in lista di coordinate {r,c}. */
function rangeCoords(node) {
    if (node.t === "range") {
        const a = parseRef(node.v[0]); const b = parseRef(node.v[1]);
        if (!a || !b) throw new FormulaError("#REF!", "range non valido");
        const r0 = Math.min(a.r, b.r), r1 = Math.max(a.r, b.r);
        const c0 = Math.min(a.c, b.c), c1 = Math.max(a.c, b.c);
        const out = [];
        for (let r = r0; r <= r1; r++) for (let c = c0; c <= c1; c++) out.push({ r, c });
        return out;
    }
    if (node.t === "ref") { const a = parseRef(node.v); if (!a) throw new FormulaError("#REF!"); return [a]; }
    throw new FormulaError("#VALUE!", "atteso un intervallo");
}

/** Criterio CONTA.SE/SOMMA.SE: nodo num (match esatto) o str (">10","<=5"). */
function makeCriterion(node) {
    if (node.t === "num") { const v = node.v; return (x) => x === v; }
    if (node.t === "str") {
        const m = /^\s*(>=|<=|<>|>|<|=)?\s*(.+?)\s*$/.exec(node.v) || [];
        const op = m[1] || "=";
        const v = parseNumber(m[2] || "").num;
        switch (op) {
            case ">":  return (x) => x > v;
            case "<":  return (x) => x < v;
            case ">=": return (x) => x >= v;
            case "<=": return (x) => x <= v;
            case "<>": return (x) => x !== v;
            default:   return (x) => x === v;
        }
    }
    throw new FormulaError("#VALUE!", "criterio non valido");
}

/** Espande un nodo in lista di numeri (per le funzioni); le coord servono a COUNT. */
function collectNumbers(node, ctx, forCount) {
    if (node.t === "range") {
        const a = parseRef(node.v[0]); const b = parseRef(node.v[1]);
        if (!a || !b) throw new FormulaError("#REF!", "range non valido");
        const r0 = Math.min(a.r, b.r), r1 = Math.max(a.r, b.r);
        const c0 = Math.min(a.c, b.c), c1 = Math.max(a.c, b.c);
        const out = [];
        for (let r = r0; r <= r1; r++) for (let c = c0; c <= c1; c++) {
            const cell = ctx.cell(r, c); // {num, isNum}
            if (forCount) { if (cell.isNum) out.push(1); }
            else out.push(cell.num);
        }
        return out;
    }
    // scalare
    const v = toNum(evalNode(node, ctx));
    return forCount ? [1] : [v];
}

// ── Valori = numero | stringa. Helper di conversione ─────────────────────────
/** Numero → stringa IT (virgola decimale, niente .0 inutile). Stringa → invariata. */
function numToStr(n) {
    if (typeof n === "string") return n;
    if (!isFinite(n)) return "";
    return (Math.round(n * 1e10) / 1e10).toString().replace(".", ",");
}
/** Qualsiasi valore → stringa (per la concatenazione `&`). */
function valToStr(v) { return typeof v === "string" ? v : numToStr(v); }
/** Qualsiasi valore → numero (per aritmetica/confronti). Stringa non numerica → #VALUE!. */
function toNum(v) {
    if (typeof v === "number") return v;
    const pn = parseNumber(v);
    if (!pn.isNum) throw new FormulaError("#VALUE!", "atteso un numero");
    return pn.num;
}

function evalNode(node, ctx) {
    switch (node.t) {
        case "num": return node.v;
        case "str": return node.v; // testo: valido come valore (concat, TESTO, criteri)
        case "ref": {
            const ref = parseRef(node.v);
            if (!ref) throw new FormulaError("#REF!", node.v);
            const cv = ctx.cell(ref.r, ref.c);
            return cv.val !== undefined ? cv.val : cv.num; // val = numero|stringa; fallback num
        }
        case "range":
            throw new FormulaError("#VALUE!", "range non ammesso qui");
        case "concat": // `&` → concatena come testo
            return valToStr(evalNode(node.left, ctx)) + valToStr(evalNode(node.right, ctx));
        case "unary": {
            const a = toNum(evalNode(node.arg, ctx));
            return node.op === "-" ? -a : a;
        }
        case "bin": {
            const a = toNum(evalNode(node.left, ctx)), b = toNum(evalNode(node.right, ctx));
            switch (node.op) {
                case "+": return a + b;
                case "-": return a - b;
                case "*": return a * b;
                case "/": if (b === 0) throw new FormulaError("#DIV/0!"); return a / b;
                case "^": return Math.pow(a, b);
                default: throw new FormulaError("#ERR!");
            }
        }
        case "cmp": {
            const a = toNum(evalNode(node.left, ctx)), b = toNum(evalNode(node.right, ctx));
            let res;
            switch (node.op) {
                case "=": res = a === b; break;
                case "<>": res = a !== b; break;
                case "<": res = a < b; break;
                case ">": res = a > b; break;
                case "<=": res = a <= b; break;
                case ">=": res = a >= b; break;
                default: throw new FormulaError("#ERR!");
            }
            return res ? 1 : 0;
        }
        case "func": {
            const name = FUNC_ALIAS[node.name] || node.name;
            return applyFunc(name, node.args, ctx);
        }
        default: throw new FormulaError("#ERR!");
    }
}

function applyFunc(name, args, ctx) {
    const nums = () => args.flatMap((a) => collectNumbers(a, ctx, false));
    const en = (i) => toNum(evalNode(args[i], ctx)); // arg i come NUMERO
    switch (name) {
        case "SUM":     return nums().reduce((s, x) => s + x, 0);
        case "PRODUCT": return nums().reduce((s, x) => s * x, 1);
        case "MIN":     { const a = nums(); return a.length ? Math.min(...a) : 0; }
        case "MAX":     { const a = nums(); return a.length ? Math.max(...a) : 0; }
        case "AVERAGE": {
            const a = args.flatMap((x) => collectNumbers(x, ctx, false));
            const cnt = args.flatMap((x) => collectNumbers(x, ctx, true)).length;
            if (cnt === 0) throw new FormulaError("#DIV/0!");
            return a.reduce((s, x) => s + x, 0) / cnt;
        }
        case "COUNT":   return args.flatMap((a) => collectNumbers(a, ctx, true)).length;
        case "ABS":     return Math.abs(en(0));
        case "ROUND": {
            const x = en(0);
            const d = args.length > 1 ? Math.trunc(en(1)) : 0;
            const f = Math.pow(10, d);
            return Math.round(x * f) / f;
        }
        case "TESTO": case "TEXT": {
            // TESTO(valore; [decimali]) → stringa IT. Senza decimali: numero "pulito".
            const x = en(0);
            if (args.length > 1) { const d = Math.max(0, Math.trunc(en(1))); return x.toFixed(d).replace(".", ","); }
            return numToStr(x);
        }
        case "PERCENTUALE": case "PERCENT": {
            // PERCENTUALE(numero; [decimali]) → "…%". NON moltiplica per 100
            // (il numero è già la percentuale: es. C1/SOMMA(C1:C3)*100).
            // - con decimali: SEMPRE quel numero di cifre (es. ;2 → "10,00%")
            // - senza: max 2 decimali, zeri finali tolti (10 → "10%", 14,2857 → "14,29%")
            const x = en(0);
            if (args.length > 1) {
                const d = Math.max(0, Math.trunc(en(1)));
                return x.toFixed(d).replace(".", ",") + "%";
            }
            return numToStr(Math.round(x * 100) / 100) + "%";
        }
        case "IF": {
            if (args.length < 2) throw new FormulaError("#ERR!", "SE richiede almeno 2 argomenti");
            const cond = en(0); // condizione come numero; rami preservano il tipo (anche testo)
            return cond !== 0 ? evalNode(args[1], ctx) : (args.length > 2 ? evalNode(args[2], ctx) : 0);
        }
        case "MEDIAN": {
            const a = nums().slice().sort((x, y) => x - y);
            if (!a.length) throw new FormulaError("#DIV/0!");
            const m = Math.floor(a.length / 2);
            return a.length % 2 ? a[m] : (a[m - 1] + a[m]) / 2;
        }
        case "INT":   return Math.floor(en(0));
        case "MOD": {
            const a = en(0), b = en(1);
            if (b === 0) throw new FormulaError("#DIV/0!");
            return a - b * Math.floor(a / b);
        }
        case "SQRT": { const x = en(0); if (x < 0) throw new FormulaError("#VALUE!"); return Math.sqrt(x); }
        case "POWER": return Math.pow(en(0), en(1));
        case "ROUNDDOWN": {
            const x = en(0);
            const d = args.length > 1 ? Math.trunc(en(1)) : 0;
            const f = Math.pow(10, d); return Math.trunc(x * f) / f;
        }
        case "ROUNDUP": {
            const x = en(0);
            const d = args.length > 1 ? Math.trunc(en(1)) : 0;
            const f = Math.pow(10, d); return (x < 0 ? Math.floor(x * f) : Math.ceil(x * f)) / f;
        }
        case "IFERROR": {
            try { return evalNode(args[0], ctx); } // preserva il tipo (anche testo)
            catch (e) { if (e instanceof FormulaError) return evalNode(args[1], ctx); throw e; }
        }
        case "AND": return args.every((a) => toNum(evalNode(a, ctx)) !== 0) ? 1 : 0;
        case "OR":  return args.some((a) => toNum(evalNode(a, ctx)) !== 0) ? 1 : 0;
        case "NOT": return toNum(evalNode(args[0], ctx)) !== 0 ? 0 : 1;
        case "COUNTIF": {
            const coords = rangeCoords(args[0]); const pred = makeCriterion(args[1]);
            let n = 0;
            for (const { r, c } of coords) { const cell = ctx.cell(r, c); if (cell.isNum && pred(cell.num)) n++; }
            return n;
        }
        case "SUMIF": {
            const coords = rangeCoords(args[0]); const pred = makeCriterion(args[1]);
            const sumCoords = args.length > 2 ? rangeCoords(args[2]) : coords;
            let s = 0;
            for (let k = 0; k < coords.length; k++) {
                const cc = ctx.cell(coords[k].r, coords[k].c);
                if (pred(cc.num)) { const sc = sumCoords[k] ? ctx.cell(sumCoords[k].r, sumCoords[k].c) : cc; s += sc.num; }
            }
            return s;
        }
        default: throw new FormulaError("#NAME?", name);
    }
}

/** True se la formula è vuota/incompleta (solo "=" o spazi) → risultato vuoto, non errore. */
export function isEmptyFormula(formula) {
    return String(formula ?? "").trim().replace(/^=/, "").trim() === "";
}

/** Valuta UNA formula. `resolveCell(r,c)` → {num, isNum}. Lancia FormulaError. */
export function evaluateFormula(formula, resolveCell) {
    const src = String(formula).trim().replace(/^=/, "");
    if (src.trim() === "") return 0; // formula vuota → 0 (display gestito a parte)
    const ast = parse(tokenize(src));
    return evalNode(ast, { cell: resolveCell });
}

/**
 * Calcola tutte le celle formula di una griglia, con dipendenze + cicli.
 * @param {Array<Array<{formula?:string, raw?:string|number}>>} grid
 * @returns {Array<Array<{display:string, value:(number|null), error:(string|null), isFormula:boolean}>>}
 */
export function computeTableValues(grid, opts = {}) {
    const rows = Array.isArray(grid) ? grid : [];
    const memo = new Map();      // "r,c" → {num, isNum}
    const computing = new Set();
    const decimals = Number.isInteger(opts.decimals) ? opts.decimals : null;

    const cellAt = (r, c) => (rows[r] && rows[r][c]) ? rows[r][c] : null;

    function computeCell(r, c) {
        const key = `${r},${c}`;
        if (memo.has(key)) return memo.get(key);
        const cell = cellAt(r, c);
        if (!cell) return { num: 0, isNum: false };
        if (cell.formula && isFormula(cell.formula)) {
            if (computing.has(key)) throw new FormulaError("#CIRC!", "riferimento circolare");
            computing.add(key);
            try {
                const v = evaluateFormula(cell.formula, (rr, cc) => computeCell(rr, cc));
                // v può essere numero o stringa (concat/TESTO/PERCENTUALE). Per i
                // riferimenti numerici da altre formule, num = parse della stringa.
                const isNumV = typeof v === "number" && isFinite(v);
                const pn = isNumV ? null : parseNumber(v);
                const res = { num: isNumV ? v : pn.num, isNum: isNumV ? true : pn.isNum, val: v };
                memo.set(key, res);
                return res;
            } finally { computing.delete(key); }
        }
        const pn = parseNumber(cell.raw);
        const res = { num: pn.num, isNum: pn.isNum, val: pn.isNum ? pn.num : String(cell.raw ?? "") };
        memo.set(key, res);
        return res;
    }

    const fmt = (n) => {
        if (!isFinite(n)) return "";
        let x = n;
        if (decimals != null) { const f = Math.pow(10, decimals); x = Math.round(n * f) / f; }
        // numero "pulito": niente .0 inutile, virgola decimale IT
        let s = (Math.round(x * 1e10) / 1e10).toString();
        return s.replace(".", ",");
    };

    return rows.map((row, r) => (row || []).map((cell, c) => {
        if (cell && cell.formula && isFormula(cell.formula)) {
            // Formula vuota/incompleta ("=") → cella vuota, nessun errore.
            if (isEmptyFormula(cell.formula)) {
                return { display: "", value: null, error: null, isFormula: true };
            }
            try {
                const cv = computeCell(r, c);
                const v = cv.val;
                if (typeof v === "string") {
                    return { display: v, value: cv.isNum ? cv.num : null, error: null, isFormula: true };
                }
                return { display: fmt(v), value: v, error: null, isFormula: true };
            } catch (e) {
                const code = (e instanceof FormulaError) ? e.code : "#ERR!";
                return { display: code, value: null, error: code, isFormula: true };
            }
        }
        const raw = cell ? (cell.raw ?? "") : "";
        return { display: String(raw), value: parseNumber(raw).num, error: null, isFormula: false };
    }));
}
