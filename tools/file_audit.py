"""
Audit single contract file: print all chunks per item con flags.
Usage: python file_audit.py <relative_path>
"""
import json, sys, re, os

if len(sys.argv) < 2:
    print('usage: file_audit.py <path>')
    sys.exit(1)

path = sys.argv[1]
with open(path, 'r', encoding='utf-8') as f:
    c = json.load(f)

DISPLAY = ['\\begin{array}', '\\begin{cases}', '\\begin{align', '\\begin{eqnarray}',
           '\\hdashline', '\\hline']


def flags(content: str, type_: str) -> list:
    fl = []
    if not isinstance(content, str):
        return fl
    if type_ == 'text':
        if re.search(r'(?<!\n)\n(?!\n)', content):
            fl.append('mid-\\n')
        if re.search(r'\.\S', content):
            fl.append('no-space-after-period')
        if re.search(r'[^\s][:;] ?\S', content) and '\\n' not in content:
            pass
        if content.strip() == '':
            fl.append('whitespace-only')
        if re.search(r'\b(sisitema|qaundo|conmme|chuiamato|chimato|disequa)', content):
            fl.append('typo-suspect')
    if type_ == 'latex':
        if 'balck' in content:
            fl.append('balck-typo')
        # Count display indicators
        d_count = sum(1 for h in DISPLAY if h in content)
        if d_count > 0:
            fl.append(f'display-{d_count}')
    return fl


for gi, g in enumerate(c.get('groups', [])):
    title = g.get('title', '')
    items = g.get('items', [])
    if not items:
        continue
    print(f'\n=== G{gi}: {title} ({len(items)} items)')
    for ii, it in enumerate(items):
        for field in ['question', 'solution', 'answer']:
            arr = it.get(field, [])
            if not arr:
                continue
            issues = []
            for ci, ch in enumerate(arr):
                if not isinstance(ch, dict):
                    continue
                t = ch.get('type', '')
                content = ch.get('content', '') if t in ('text', 'latex') else ch.get('script', '')
                fl = flags(content, t)
                if fl:
                    issues.append(f'    [{ci}] {t}: {fl}')
                    # print first 80 chars
                    p = content[:80].replace('\n', '\\n')
                    issues.append(f'        {p!r}')
            if issues:
                print(f'  G{gi}.it{ii}.{field} ({len(arr)} chunks):')
                for line in issues[:20]:
                    print(line)
                if len(issues) > 20:
                    print(f'    ... +{len(issues)-20} more')
