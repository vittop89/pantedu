#!/usr/bin/env python3
"""
Fix duplicate class="X" class="Y" → class="X Y" nelle view HTML/PHP.

Bug introdotto durante Phase 4 inline-styles refactor: dove esisteva già
un class="..." e ho sostituito uno style="..." con un secondo class="...",
il browser ignora il secondo silenziosamente.

Approach: regex match conservativo, gestisce attrs intermedi e single/double quotes.
"""

import re
import sys
from pathlib import Path

# Match: class="X" any-attrs class="Y" sulla stessa apertura tag <...>
# Conservative: matcha solo dentro <tagname ... > (no overflow su righe multiple).
TAG_RE = re.compile(r'<(\w+)([^>]*?)class=(["\'])([^"\']*)\3([^>]*?)class=(["\'])([^"\']*)\6([^>]*?)>')

def fix_in_text(text):
    """Restituisce (new_text, count_fixed)."""
    count = 0
    def repl(m):
        nonlocal count
        count += 1
        tag = m.group(1)
        before = m.group(2)
        quote1 = m.group(3)
        class1 = m.group(4)
        middle = m.group(5)
        # quote2 = m.group(6)
        class2 = m.group(7)
        after = m.group(8)
        merged = (class1 + ' ' + class2).strip()
        return f'<{tag}{before}class={quote1}{merged}{quote1}{middle}{after}>'
    new_text = TAG_RE.sub(repl, text)
    return new_text, count

def main(root='views'):
    total_fixed = 0
    files_fixed = []
    for p in list(Path(root).rglob('*.php')) + list(Path(root).rglob('*.html')):
        try:
            text = p.read_text(encoding='utf-8')
        except UnicodeDecodeError:
            continue
        new_text, n = fix_in_text(text)
        # Iterate: alcune tag possono avere 3+ class= duplicati
        while n > 0:
            text = new_text
            new_text, more = fix_in_text(text)
            n += more
            if more == 0:
                break
        if text != p.read_text(encoding='utf-8'):
            p.write_text(text, encoding='utf-8')
            files_fixed.append((str(p), n))
            total_fixed += n
    print(f"Files fixed: {len(files_fixed)}")
    print(f"Total duplicates merged: {total_fixed}")
    for fname, n in sorted(files_fixed, key=lambda x: -x[1])[:20]:
        print(f"  {n:3d}  {fname}")

if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else 'views')
