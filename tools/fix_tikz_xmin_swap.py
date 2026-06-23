r"""Fix xmin/xmax swap in Rette ver G5.it0 TikZ."""
import json
path = 'storage/objects/institutes/106/private/77/verifiche/MAT-Rette_fasci_di_rette_e_piani-ver.contract.json'
with open(path, 'r', encoding='utf-8') as f:
    c = json.load(f)

script = c['groups'][5]['items'][0]['solution'][0]['script']
old_xmin = '\\def\\xmin{1}'
new_xmin = '\\def\\xmin{-6}'
old_xmax = '\\def\\xmax{-6}'
new_xmax = '\\def\\xmax{1}'

if old_xmin in script and old_xmax in script:
    # Use placeholder to avoid double-replace collision
    s = script.replace(old_xmin, '__XMIN_NEW__')
    s = s.replace(old_xmax, new_xmax)
    s = s.replace('__XMIN_NEW__', new_xmin)
    c['groups'][5]['items'][0]['solution'][0]['script'] = s
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(c, f, ensure_ascii=False, indent=4)
    print('Fixed and saved')
else:
    print(f'Pattern not found:')
    print(f'  old_xmin in script: {old_xmin in script}')
    print(f'  old_xmax in script: {old_xmax in script}')
