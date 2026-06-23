"""Find chunks with unbalanced \\begin{cases} / \\end{cases}."""
import json, os

def walk(node, p=''):
    if isinstance(node, dict):
        for k, v in node.items():
            yield from walk(v, f'{p}.{k}')
    elif isinstance(node, list):
        for i, v in enumerate(node):
            yield from walk(v, f'{p}[{i}]')
    elif isinstance(node, str):
        for env in ['cases', 'array', 'align', 'align*', 'eqnarray', 'matrix',
                    'vmatrix', 'bmatrix', 'pmatrix', 'Bmatrix', 'split']:
            n_begin = node.count('\\begin{' + env + '}')
            n_end = node.count('\\end{' + env + '}')
            if n_begin != n_end:
                yield (p, env, n_begin, n_end, node)


ROOTS = [
    'storage/objects/institutes/106/private/77/eser',
    'storage/objects/institutes/106/private/77/verifiche',
]
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
            except:
                continue
            results = list(walk(c))
            if results:
                rel = os.path.relpath(path).replace('\\', '/')
                print(f'\n{rel}:')
                for loc, env, b, e, full in results:
                    print(f'  {loc} env={env} begin={b} end={e}')
                    print(f'    excerpt: {full[:250]!r}')
