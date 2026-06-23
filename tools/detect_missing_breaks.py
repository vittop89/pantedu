"""
Detect text/latex chunks where context suggests missing paragraph break.
Heuristics (conservative):
- text chunk ending with `:` or `.` followed by next chunk starting with capital
  AND no \n\n at end → suggests missing break
- text chunk containing section markers: "In conclusione", "Quindi", "Pertanto",
  "Dunque" not preceded by \n\n
- adjacent latex chunks where one contains display markers and no separator

Output: list of candidate fixes, NOT auto-applied.
"""
import os, json, re

folder = 'storage/objects/institutes/106/private/77/verifiche'
SECTION_WORDS = ['In conclusione', 'Quindi', 'Pertanto', 'Dunque', 'Allora',
                 'Inoltre', 'Riassumendo', 'Riporto', 'Riscrivo',
                 'Sostituendo', 'Applico', 'Procediamo']


def chunk_iter(c):
    for gi, g in enumerate(c.get('groups', [])):
        for ii, it in enumerate(g.get('items', [])):
            for field in ['question', 'solution', 'answer']:
                arr = it.get(field, [])
                if isinstance(arr, list):
                    for ci, ch in enumerate(arr):
                        if isinstance(ch, dict):
                            yield (gi, ii, field, ci, ch)


total_per_file = {}
for root, _, files in os.walk(folder):
    for fn in sorted(files):
        if not fn.endswith('.contract.json'):
            continue
        path = os.path.join(root, fn)
        try:
            with open(path, 'r', encoding='utf-8') as f:
                c = json.load(f)
        except:
            continue
        candidates = []
        prev = None
        for (gi, ii, field, ci, ch) in chunk_iter(c):
            t = ch.get('type')
            content = ch.get('content', '') if isinstance(ch.get('content'), str) else ''
            # Section words mid-content
            for w in SECTION_WORDS:
                # match " {Word}" (with leading space) at non-start, suggesting
                # paragraph word inline
                if f' {w} ' in content and content.find(f'{w}') > 5:
                    # check there's no \n\n before
                    idx = content.find(f' {w} ')
                    if idx > 0 and '\n\n' not in content[max(0, idx-10):idx]:
                        candidates.append((f'G{gi}.it{ii}.{field}[{ci}]', t, content[max(0, idx-20):idx+30]))
            prev = ch
        if candidates:
            total_per_file[fn] = candidates

for fn, cands in total_per_file.items():
    print(f'\n{fn}: {len(cands)} candidates')
    for loc, t, ctx in cands[:5]:
        print(f'  {loc} [{t}]: ...{ctx.strip()!r}...')
    if len(cands) > 5:
        print(f'  ... +{len(cands)-5}')

print(f'\nTotal: {sum(len(c) for c in total_per_file.values())} candidates in {len(total_per_file)} files')
