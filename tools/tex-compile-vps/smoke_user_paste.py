#!/usr/bin/env python3
"""Compila il TikZ ESATTO che l'utente ha incollato e analizza il SVG."""
import base64, hashlib, hmac, json, os, sys, time, urllib.request

SECRET = os.environ.get("TEX_COMPILE_SECRET", "local-dev-test-secret-not-for-prod-32-bytes-min")
ENDPOINT = os.environ.get("ENDPOINT", "http://127.0.0.1:8001")

# TikZ esatto incollato dall'utente
TIKZ = open(os.path.join(os.path.dirname(__file__), "user_paste.tex"), encoding="utf-8").read()

payload = json.dumps({
    "tikz_b64": base64.b64encode(TIKZ.encode()).decode(),
    "libraries": ["calc"],
    "doc_id": "user-paste",
}).encode()
ts = str(int(time.time()))
sig = hmac.new(SECRET.encode(), ts.encode() + b"." + payload, hashlib.sha256).hexdigest()
req = urllib.request.Request(f"{ENDPOINT}/render-tikz", data=payload, method="POST",
    headers={"Content-Type":"application/json","X-Timestamp":ts,"X-Signature":sig})
try:
    with urllib.request.urlopen(req, timeout=60) as r:
        body = r.read()
        print(f"HTTP {r.status} | {len(body)} bytes | {r.headers.get('X-Compile-Duration-Ms')}ms")
        with open("user_paste_out.svg", "wb") as f: f.write(body)
        s = body.decode("utf-8", errors="replace")
        # Conta filldraw=red!70 nel SVG. dvisvgm produce ogni filldraw come
        # 1 path con fill=color1 + 1 path con stroke=color2.
        # Per "red!70" dvisvgm tipicamente outputta #ff4d4d come fill.
        red_fills = s.count("ff4d4d") + s.count("ff4D4d") + s.count("FF4D4D")
        # Conta anche le occorrenze di "white" (testo bianco sopra il cerchio rosso)
        white_text = s.count('fill="#fff"') + s.count("fill='#fff'") + s.count('fill="#ffffff"')
        # Cerca pattern circle stroked (path approssimato)
        # Conta path con stroke ff4d4d (border red)
        red_strokes = s.count("stroke='#ff4d4d'") + s.count('stroke="#ff4d4d"') + \
                      s.count("stroke='#660000'") + s.count('stroke="#660000"') + \
                      s.count("stroke='#990000'") + s.count('stroke="#990000"') + \
                      s.count("stroke='#400000'") + s.count('stroke="#400000"')
        print(f"red fills (#ff4d4d): {red_fills}")
        print(f"white text fills:    {white_text}")
        print(f"red-ish strokes:     {red_strokes}")
        print()
        print("Atteso (highlight=3 su riga 3 di 4 colonne):")
        print("  - 1 cella rossa (col 3) con dvisvgm = 1 fill ff4d4d (interno) + 1 stroke (border)")
        print("  - + 1 testo bianco sopra")
        if red_fills <= 2:
            print(f"OK: ~1 cella evidenziata")
        else:
            print(f"BUG: ~{red_fills//2}+ celle evidenziate (atteso 1)")
except urllib.error.HTTPError as e:
    print(f"HTTP {e.code}: {e.reason}")
    try: j = json.loads(e.read()); print(j.get("log","")[-1500:])
    except: pass
