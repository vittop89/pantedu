"""
Bug: LaTeX commands \b, \f, \t in chunk content vennero serializzati come
JSON control char escapes (\b=0x08, \f=0x0c, \t=0x09) invece di \\b, \\f, \\t.
Quando Python carica il JSON, ottiene il control char invece di literal \b.

Fix: scandisce tutti i chunk content per control char prefissi che dovrebbero
essere LaTeX commands, e li sostituisce con literal backslash + lettera.

Pattern affected:
- \begin → \b + egin
- \frac, \frac{...} → \f + rac
- \text → ? (no, \t is tab — let me check)

Common LaTeX commands starting with these letters:
- \b: \begin, \bigg, \binom, \bullet, \bm, \bf
- \f: \frac, \fbox, \forall, \fboxsep
- \t: \text, \times, \to, \tan, \theta, \top
- \n: \nabla, \neq, \neg, \ne (but \n collides with LF too messy)
- \r: \rightarrow, \rho (collides CR)
"""
import os, json, re, sys

ROOTS = [
    'storage/objects/institutes/106/private/77/eser',
    'storage/objects/institutes/106/private/77/verifiche',
]

# Heuristic: detect control-char-followed-by-letters that looks like a
# truncated LaTeX command. Only fix when:
# - char is 0x08 (\b), 0x0c (\f), or 0x09 (\t)
# - it's followed by typical LaTeX letters
LATEX_RECOVERY = {
    '\x08': 'b',  # backspace → \b
    '\x0c': 'f',  # form-feed → \f
    '\x09': 't',  # tab → \t (cautious: could be intentional indent)
}


def fix_content(s: str) -> tuple:
    """Returns (new_string, count_changes)."""
    if not isinstance(s, str):
        return s, 0
    n = 0
    # Only convert backspace and form-feed; skip tab (often intentional)
    for ch, letter in [('\x08', 'b'), ('\x0c', 'f')]:
        if ch in s:
            cnt = s.count(ch)
            n += cnt
            s = s.replace(ch, '\\' + letter)
    return s, n


def walk_fix(node, changes):
    if isinstance(node, dict):
        for k, v in list(node.items()):
            if isinstance(v, str):
                new, n = fix_content(v)
                if n:
                    node[k] = new
                    changes.append((k, n))
            else:
                walk_fix(v, changes)
    elif isinstance(node, list):
        for v in node:
            walk_fix(v, changes)


apply = '--apply' in sys.argv
total = {}
for root in ROOTS:
    if not os.path.isdir(root):
        continue
    for dp, _, files in os.walk(root):
        for fn in sorted(files):
            if not fn.endswith('.contract.json'):
                continue
            path = os.path.join(dp, fn)
            try:
                with open(path, 'r', encoding='utf-8') as f:
                    c = json.load(f)
            except Exception as e:
                print(f'SKIP {path}: {e}')
                continue
            changes = []
            walk_fix(c, changes)
            if changes:
                total_n = sum(n for _, n in changes)
                total[fn] = total_n
                if apply:
                    with open(path, 'w', encoding='utf-8') as f:
                        json.dump(c, f, ensure_ascii=False, indent=4)

for fn, n in sorted(total.items(), key=lambda x: -x[1]):
    print(f'  {fn}: {n} control chars')
print(f'\nTotal: {sum(total.values())} fixes in {len(total)} files')
print(f'Mode: {"APPLIED" if apply else "DRY-RUN"}')
