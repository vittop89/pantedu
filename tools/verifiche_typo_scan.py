import os, re

folder = 'storage/objects/institutes/106/private/77/verifiche'
patterns = [
    (rb'\bbalck\b', 'balck'),
    (rb'\bbaalck\b', 'baalck'),
    (rb'\bsisitema\b', 'sisitema'),
    (rb'\bdenomiantore\b', 'denomiantore'),
    (rb'\bcentiania\b', 'centiania'),
    (rb'\beta di [A-Z]', 'eta-di-X'),
    (rb'\bunit\xc3\xa0\(', 'unita('),
    (rb'centinaia\(', 'centinaia('),
    (rb'decine\(', 'decine('),
    (rb'pi\xc3\xb9\(', 'piu('),
    (rb'\bperche\b', 'perche-no-accent'),
    (rb'\bpercio\b', 'percio-no-accent'),
    (rb'\bpoiche\b', 'poiche-no-accent'),
    (rb'\bgia\b ', 'gia-no-accent'),
    (rb'\bpiu\b ', 'piu-no-accent'),
    (rb'\bsisitema\b', 'sisitema'),
    (rb'\bllibro\b', 'llibro'),
    (rb'\bmenoi\b', 'menoi'),
    (rb'\bquadno\b', 'quadno'),
    (rb'\bqaundo\b', 'qaundo'),
    (rb'\boggettoo\b', 'oggettoo'),
    (rb'\boggeto\b', 'oggeto-1g'),
    (rb'\bcasonn\b', 'casonn'),
    (rb'\bperch\xc3\xa8', 'perché-typo'),
    (rb'\binca\xe2\x80\x99', 'inca-curl'),
    (rb'\bso\xc3\xa8\b', 'soè'),
]
for root, _, files in os.walk(folder):
    for fn in sorted(files):
        if not fn.endswith('.contract.json'):
            continue
        path = os.path.join(root, fn)
        with open(path, 'rb') as f:
            data = f.read()
        hits = {}
        for pat, label in patterns:
            n = len(re.findall(pat, data))
            if n:
                hits[label] = n
        if hits:
            print(f'{fn}: {hits}')
