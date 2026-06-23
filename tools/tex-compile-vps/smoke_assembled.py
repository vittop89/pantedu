#!/usr/bin/env python3
"""Smoke che prende l'output ESATTO dell'assembler JS (da
/tmp/assembled_test.tex) e lo manda al VPS locale per verifica."""
import base64, hashlib, hmac, json, os, sys, time, urllib.request

SECRET = os.environ.get("TEX_COMPILE_SECRET", "local-dev-test-secret-not-for-prod-32-bytes-min")
ENDPOINT = os.environ.get("ENDPOINT", "http://127.0.0.1:8001")

import tempfile
TEX_PATH = os.environ.get("TEX_PATH") or os.path.join(tempfile.gettempdir(), "assembled_test.tex")
with open(TEX_PATH, "r", encoding="utf-8") as f:
    TIKZ = f.read()

payload = json.dumps({
    "tikz_b64": base64.b64encode(TIKZ.encode()).decode(),
    "libraries": ["calc"],
    "doc_id": "assembled-test",
}).encode()
ts = str(int(time.time()))
sig = hmac.new(SECRET.encode(), ts.encode() + b"." + payload, hashlib.sha256).hexdigest()
req = urllib.request.Request(f"{ENDPOINT}/render-tikz", data=payload, method="POST",
    headers={"Content-Type":"application/json","X-Timestamp":ts,"X-Signature":sig})
print(f"INPUT TEX (riga critica + dim): {len(TIKZ)} bytes")
import re
m = re.search(r"frac\{N\(x\)\}\{D\(x\)\}.*", TIKZ)
print(f"  riga 3: {m.group(0)[:120] if m else 'NOT FOUND'}")
try:
    # Salva localmente la sorgente per ispezione
    with open("test_input.tex", "w", encoding="utf-8") as f:
        f.write(TIKZ)
    with urllib.request.urlopen(req, timeout=60) as r:
        body = r.read()
        print(f"HTTP {r.status} | {len(body)} bytes")
        with open("assembled_out.svg", "wb") as f: f.write(body)
        s = body.decode("utf-8", errors="replace")
        import re
        from collections import Counter
        matches = re.findall(r'fill=["\']#?([0-9a-fA-F]{6}|[0-9a-fA-F]{3}|red[^"\']*)["\']', s)
        counter = Counter(matches)
        print(f"Fill colors: {dict(counter)}")
        red = sum(1 for c in matches if "ff4d4d" in c.lower())
        print(f"Red cells (ff4d4d) = {red}")
        # Atteso: 2 highlights × 2 paths (fill+stroke) = 4
        # Se vediamo 8 (4 cells) → bug "tutto colorato"
        if red == 4:
            print("OK: 2 celle evidenziate (1 e 3)")
        elif red >= 8:
            print(f"BUG: {red//2} celle colorate (atteso 2)")
        else:
            print(f"UNEXPECTED: {red}")
except urllib.error.HTTPError as e:
    print(f"HTTP {e.code}: {e.reason}")
    try: j = json.loads(e.read()); print(j.get("log","")[-1500:])
    except: pass
    sys.exit(1)
