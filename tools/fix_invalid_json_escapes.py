r"""
Fix invalid JSON escapes in contract files.

Valid JSON escapes: \" \\ \/ \b \f \n \r \t \uXXXX
Invalid (must be doubled): any other letter, e.g. \d \s \l \L \D

Scans .contract.json files, finds these patterns in string positions, and
replaces with \\X (literal backslash + letter).
"""
import os, re, sys, json

ROOTS = [
    'storage/objects/institutes/106/private/77/eser',
    'storage/objects/institutes/106/private/77/verifiche',
]

VALID_AFTER_BACKSLASH = set('"\\/bfnrtu')


def fix_bytes(data: bytes) -> tuple[bytes, int]:
    """Find \\X patterns where X is not a valid JSON escape.
    Replace with \\\\X (double the backslash). Returns (new_data, count).
    Only operates on chars in JSON string context (heuristic: any
    position not inside JSON delimiter chars)."""
    # Decode as utf-8, then find single backslash followed by invalid escape char.
    # We need to be careful: within a JSON string, "\\d" appears as bytes [\, \, d]
    # which is valid (escaped backslash + literal d). Only single \X invalid.
    # Single-backslash detection: scan for \ that is not part of \\...
    # Approach: replace runs of N backslashes followed by char:
    # - N even followed by anything: keep
    # - N odd followed by valid escape: keep
    # - N odd followed by invalid char: need to make even (insert one more \)

    out = bytearray()
    i = 0
    L = len(data)
    count = 0
    while i < L:
        if data[i:i+1] == b'\\':
            # Count consecutive backslashes
            j = i
            while j < L and data[j:j+1] == b'\\':
                j += 1
            n_back = j - i
            next_byte = data[j:j+1] if j < L else b''
            if n_back % 2 == 1:
                # Odd backslashes → last \ pairs with next char
                if next_byte and chr(next_byte[0]) in VALID_AFTER_BACKSLASH:
                    # Valid escape, keep as is
                    out.extend(data[i:j+1])
                    i = j + 1
                else:
                    # Invalid escape → add one more backslash
                    out.extend(b'\\' * n_back)
                    out.extend(b'\\')  # extra to make it escaped
                    count += 1
                    i = j
            else:
                # Even backslashes → all are escaped, keep
                out.extend(data[i:j])
                i = j
        else:
            out.extend(data[i:i+1])
            i += 1
    return bytes(out), count


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
            with open(path, 'rb') as f:
                data = f.read()
            new_data, n = fix_bytes(data)
            if n > 0:
                # Verify the new data is valid JSON
                try:
                    json.loads(new_data.decode('utf-8'))
                    if apply:
                        with open(path, 'wb') as f:
                            f.write(new_data)
                    total[fn] = (n, 'OK')
                except Exception as e:
                    total[fn] = (n, f'INVALID JSON after fix: {e}')

for fn, (n, status) in sorted(total.items(), key=lambda x: -x[1][0]):
    print(f'  {fn}: {n} fixes [{status}]')
print(f'\nTotal: {sum(v[0] for v in total.values())} escapes in {len(total)} files')
print(f'Mode: {"APPLIED" if apply else "DRY-RUN"}')
