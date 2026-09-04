r"""Find all \color{black}* pattern variants in chunks."""
import json
import os
import re

ROOTS = ['storage/objects/institutes/106/private/77/eser',
         'storage/objects/institutes/106/private/77/verifiche']

patterns = {}
for r in ROOTS:
    for dp, _, fs in os.walk(r):
        for fn in fs:
            if not fn.endswith('.contract.json'):
                continue
            try:
                with open(os.path.join(dp, fn), 'r', encoding='utf-8') as f:
                    c = json.load(f)
            except Exception:
                continue

            def walk(node):
                if isinstance(node, dict):
                    t = node.get('type')
                    if t in ('text', 'latex') and isinstance(node.get('content'), str):
                        s = node['content']
                        for m in re.finditer(r'\\color\s*\{black\}(?:\s*\{[^{}]*\})?', s):
                            yield m.group()
                    for v in node.values():
                        if isinstance(v, (dict, list)):
                            yield from walk(v)
                elif isinstance(node, list):
                    for v in node:
                        if isinstance(v, (dict, list)):
                            yield from walk(v)

            for pat in walk(c):
                k = pat[:50]
                patterns[k] = patterns.get(k, 0) + 1

for pat, count in sorted(patterns.items(), key=lambda x: -x[1])[:20]:
    print(f'{count:4}: {pat!r}')
