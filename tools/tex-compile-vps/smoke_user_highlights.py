#!/usr/bin/env python3
"""Riproduce ESATTAMENTE il caso utente per verificare se highlights=1;3
funziona o no nel macro originale."""
import base64, hashlib, hmac, json, os, sys, time, urllib.request

SECRET = os.environ.get("TEX_COMPILE_SECRET", "local-dev-test-secret-not-for-prod-32-bytes-min")
ENDPOINT = os.environ.get("ENDPOINT", "http://127.0.0.1:8001")

# Importo il PREAMBLE intero dal modulo JS via copia (full preamble del template)
# e provo l'esatta sequenza: 3 righe, riga 3 con highlight=1;3.
TIKZ = r"""% ==================================================
% .....PREAMBOLO E LIBRERIE.....
\usepackage{amssymb}
\usepackage{amsmath}
\usepackage{tikz}
\usetikzlibrary{calc}

\makeatletter

\newcount\schemaCountA \newcount\schemaCountB \newcount\schemaIndex
\newdimen\schemaDimA \newdimen\schemaDimB \newdimen\schemaDimPrev
\newdimen\schemaLastShift \newdimen\schemaLastWidth \newdimen\schemaLastRowDim \newdimen\schemaPrevRowDim
\def\schemaHighlightTarget{} \def\schemaTempHighlight{} \def\schemaHighlightListEnd{\relax}
\gdef\schemaLastCenterVal{0}

\newcommand{\schemaTrimSpaces}[2]{\edef#2{\zap@space#1 \@empty}}

\def\schemaIterateHighlights#1;#2\schemaHighlightListEnd{%
    \schemaTrimSpaces{#1}{\schemaTempHighlight}%
    \ifx\schemaTempHighlight\empty
    \else
        \ifnum\schemaTempHighlight=\schemaHighlightTarget\relax
            \global\def\schemaDoHighlight{1}%
        \fi
    \fi
    \ifx#2\schemaHighlightListEnd
    \else
        \schemaIterateHighlights#2\schemaHighlightListEnd
    \fi
}

\newcommand{\schemaModulareCore}[7]{%
    \begin{scope}[shift={(#1,0)}]
        \schemaLastRowDim=0pt \schemaPrevRowDim=0pt \schemaCountA=0
        \foreach \pos/\val in {#2} {\global\advance\schemaCountA by 1
            \expandafter\xdef\csname tempXPos\the\schemaCountA\endcsname{\pos}}
        \edef\tempLastPos{\csname tempXPos\the\schemaCountA\endcsname}
        \schemaDimA=\tempLastPos pt \advance\schemaDimA by 1pt
        \edef\tempLastCol{\strip@pt\schemaDimA}
        \schemaDimPrev=0pt \schemaIndex=1
        \foreach \pos/\val in {#2} {\schemaDimA=\pos pt \advance\schemaDimA by \schemaDimPrev
            \divide\schemaDimA by 2
            \expandafter\xdef\csname tempMidPos\the\schemaIndex\endcsname{\strip@pt\schemaDimA}
            \global\schemaDimPrev=\pos pt \global\advance\schemaIndex by 1}
        \schemaDimA=\tempLastCol pt \advance\schemaDimA by \schemaDimPrev \divide\schemaDimA by 2
        \expandafter\xdef\csname tempMidPos\the\schemaIndex\endcsname{\strip@pt\schemaDimA}
        \draw (0,0) -- (\tempLastCol,0);
        \foreach \ypos/\equazione/\rowSigns/\rowCircles/\rowHighlights [count=\rowNum] in {#3} {
            \global\schemaPrevRowDim=\schemaLastRowDim
            \schemaDimA=\ypos pt \global\schemaLastRowDim=\schemaDimA
            \node [left] at (0,-\ypos) {\equazione};
            \begingroup
                \edef\schemaTmpCircles{\detokenize{\rowCircles}}
                \if\relax\schemaTmpCircles\relax
                \else
                    \foreach \idx/\filltype in \rowCircles {
                        \schemaTrimSpaces{\idx}{\schemaCircleIdxDet}
                        \schemaTrimSpaces{\filltype}{\schemaCircleCmdDet}
                        \if\relax\schemaCircleIdxDet\relax\else
                            \edef\xVal{\csname tempXPos\schemaCircleIdxDet\endcsname}
                            \csname\schemaCircleCmdDet\endcsname[line width=0.4mm] (\xVal,-\ypos) circle (5pt);
                        \fi
                    }
                \fi
            \endgroup
            \foreach \sign [count=\schemaTmpIdx] in \rowSigns {
                \edef\schemaTempMid{\csname tempMidPos\schemaTmpIdx\endcsname}
                \gdef\schemaDoHighlight{0}
                \edef\schemaCheckHL{\detokenize{\rowHighlights}}
                \if\relax\schemaCheckHL\relax\else
                    \foreach \hCol in {\rowHighlights} {
                        \ifnum\hCol=\schemaTmpIdx
                            \global\def\schemaDoHighlight{1}
                        \fi
                    }
                \fi
                \ifnum\schemaDoHighlight=1
                    \filldraw[fill=red!70,draw=red!40!black,line width=0.4pt] (\schemaTempMid,-\ypos) circle (0.2cm);
                    \node[text=white] at (\schemaTempMid,-\ypos) {\Large \sign};
                \else
                    \node at (\schemaTempMid,-\ypos) {\Large \sign};
                \fi
            }
        }
        \edef\tempLastRow{\strip@pt\schemaLastRowDim}
        \schemaDimA=\schemaLastRowDim \advance\schemaDimA by 0.5pt
        \edef\tempVerticalDepth{\strip@pt\schemaDimA}
        \foreach \pos/\val in {#2} {
            \draw (\pos,0.2) -- (\pos,-\tempVerticalDepth);
            \node [above] at (\pos,0.2) {\val};
        }
    \end{scope}
}

\def\schemaModulare#1#2#3{\schemaModulareCore{#1}{#2}{#3}{}{}{}{}}

\makeatother

\begin{document}
\begin{tikzpicture}
    \schemaModulare{0}
        {1/{$2a$}, 2/{$0$}, 3/{$-\frac{a}{4}$}}
        {
            0.5/{$N(x)>0$}/{{$+$},{$+$},{$-$},{$+$}}/{2/draw, 3/draw}/{},
            1.5/{$D(x)>0$}/{{$+$},{$+$},{$+$},{$+$}}/{1/draw}/{},
            2.5/{$\frac{N(x)}{D(x)}$}/{{$+$},{$+$},{$-$},{$+$}}/{1/draw, 2/draw, 3/draw}/{1,3}
        }
\end{tikzpicture}
\end{document}
"""

payload = json.dumps({
    "tikz_b64": base64.b64encode(TIKZ.encode()).decode(),
    "libraries": ["calc"],
    "doc_id": "user-hl-13",
}).encode()
ts = str(int(time.time()))
sig = hmac.new(SECRET.encode(), ts.encode() + b"." + payload, hashlib.sha256).hexdigest()
req = urllib.request.Request(f"{ENDPOINT}/render-tikz", data=payload, method="POST",
    headers={"Content-Type":"application/json","X-Timestamp":ts,"X-Signature":sig})
try:
    with urllib.request.urlopen(req, timeout=60) as r:
        body = r.read()
        print(f"HTTP {r.status} | {len(body)} bytes")
        with open("user_hl_13.svg", "wb") as f: f.write(body)
        # Conta i cerchi rossi (filldraw fill="#b30000" o simili)
        s = body.decode("utf-8", errors="replace")
        # dvisvgm rappresenta filldraw con path + fill attribute
        red_circles = s.count('fill="#")') + s.count("fill='red'") + s.count('fill:red')
        # Cerca pattern di colore che dvisvgm produce per red!70
        import re
        matches = re.findall(r'fill=["\']#?([0-9a-fA-F]{6}|[0-9a-fA-F]{3}|red[^"\']*)["\']', s)
        from collections import Counter
        counter = Counter(matches)
        print(f"Fill colors trovati nell'SVG: {dict(counter)}")
        # Filldraw produce 2 elementi: stroke (border) + fill (interno).
        # Cerca colori vicini a red!70 (es. #b30000, b3 = 70% di FF)
        red_like = [c for c in matches if "b3" in c.lower() or "ff" in c.lower() or "red" in c.lower()]
        print(f"Red-like fills count: {len(red_like)}")
except urllib.error.HTTPError as e:
    print(f"HTTP {e.code}: {e.reason}")
    err = e.read().decode("utf-8", errors="replace")
    try: j = json.loads(err); print(j.get("log","")[-1500:])
    except: print(err[:2000])
    sys.exit(1)
