# LaTeX Rules — Formule e Notazione

Regole per la conversione e validazione delle formule LaTeX negli esercizi.

---

## Delimitatori

| Tipo | ❌ Sbagliato | ✅ Corretto |
|------|-------------|------------|
| Inline | `$x^2$` | `\(x^2\)` |
| Block | `$$...$$` | `\[...\]` |

---

## Simboli e operatori

| ❌ Sbagliato | ✅ Corretto | Note |
|-------------|------------|------|
| `±` | `\pm` | Plus-minus |
| `≤` | `\leq` | Minore uguale |
| `≥` | `\geq` | Maggiore uguale |
| `≠` | `\neq` | Diverso |
| `→` | `\to` | Freccia |
| `⇒` | `\Rightarrow` | Implicazione |
| `∞` | `\infty` | Infinito |
| `²` / `³` | `^2` / `^3` | Apici |
| `√` | `\sqrt{}` | Radice |

---

## Notazione scientifica

| ❌ Sbagliato | ✅ Corretto | Note |
|-------------|------------|------|
| `3.14` | `3{,}14` | Decimali EU (virgola) |
| `5m` | `5\text{ m}` | Unità di misura con spazio |
| `10^3 km` | `10^3\text{ km}` | Potenza + unità |
| `4 °C` / `4 &deg;C` | `4 \,^{\circ}\text{C}` | Gradi Celsius |
| `95%` (fuori da math) | `\(95\%\)` | Percentuali sempre in `\(...\)` |
| `100 °F` | `100 \,^{\circ}\text{F}` | Gradi Fahrenheit |
| `sin(x)` | `\sin(x)` | Funzioni trigonometriche |
| `log(x)` | `\log(x)` | Logaritmo |
| `lim` | `\lim` | Limite |
| `v` (vettore) | `\vec{v}` | Notazione vettoriale |
| `|v|` | `\|\vec{v}\|` | Modulo vettore |

---

## Frazioni e rapporti

| ❌ Sbagliato | ✅ Corretto |
|-------------|------------|
| `a/b` (inline, semplice) | `\frac{a}{b}` |
| `(a+b)/(c+d)` | `\frac{a+b}{c+d}` |
| `1/2` (discorsivo) | `\frac{1}{2}` |

---

## Ambienti LaTeX comuni

```latex
% Sistemi
\begin{cases} x + y = 5 \\ x - y = 1 \end{cases}

% Matrici
\begin{pmatrix} a & b \\ c & d \end{pmatrix}

% Allineamento (multi-step)
\begin{aligned} x &= 2y + 3 \\ &= 2(4) + 3 \\ &= 11 \end{aligned}
```

---

## Stile svolgimento

- Ogni step = formula LaTeX autonoma, preferibilmente in `\(...\)`
- Connettivi ammessi tra step: `\Longrightarrow`, `\quad`, `\text{C.E.}`, `\text{impossibile}`
- **Minimizzare** parole: NO "Risolviamo", "Calcoliamo", "Otteniamo", "Quindi"
- Se necessario testo: usare `\text{...}` all'interno della formula

### Unità di misura nei calcoli

> **REGOLA MANDATORIA:** Ogni passaggio algebrico DEVE riportare le unità di misura.

- Le unità vanno trattate come fattori algebrici: si moltiplicano, dividono, semplificano
- Usare `\cancel{...}` per le unità che si elidono tra numeratore e denominatore
- Le unità residue (non cancellate) restano nel risultato

| ❌ Sbagliato | ✅ Corretto |
|-------------|------------|
| `1{,}2 \times 10^{-5} \cdot 503 \cdot 20 = 0{,}12` | `1{,}2 \times 10^{-5}\,\cancel{^{\circ}\text{C}}^{-1} \cdot 503\text{ m} \cdot 20\,\cancel{^{\circ}\text{C}} = 0{,}12\text{ m}` |
| `50{,}0 \cdot 1{,}00576 = 50{,}29` | `50{,}0\text{ cm}^3 \cdot 1{,}00576 = 50{,}29\text{ cm}^3` |

### Risultato finale evidenziato

- Il risultato finale va racchiuso con `\fcolorbox{red}{yellow}{$\color{black}...VALORE...$}`
- L'HTML `<span class="solution">` va **dentro** il `$...$` del fcolorbox
- Formato completo: `\fcolorbox{red}{yellow}{$\color{black}<span class="solution">VALORE\text{ UNITÀ}</span>$}`
- **⚠️ Il `\fcolorbox` DEVE essere dentro i delimitatori `\(…\)` o `\[…\]`** — mai fuori

| ❌ Sbagliato | ✅ Corretto |
|-------------|------------|
| `\) \fcolorbox{red}{yellow}{...}` | `\fcolorbox{red}{yellow}{...} \)` |
| `</div>` poi `<div>\fcolorbox{...}` | `\fcolorbox` nella stessa espressione `\(…\)` |

Esempio completo di un passaggio finale:
```
\(\Delta L = 1{,}2 \times 10^{-5}\,\cancel{^{\circ}\text{C}}^{-1} \cdot 503\text{ m} \cdot 20\,\cancel{^{\circ}\text{C}} =
\fcolorbox{red}{yellow}{$\color{black}<span class="solution">0{,}12\text{ m}</span>$} \)
```

### Tabella DATI & INCOGNITE

> **OBBLIGATORIA** prima dei passaggi algebrici **solo per problemi fisico-matematici** `type_Collect` (con calcoli algebrici). NON per completamenti, conversion semplici, domande discorsive.

Usare `\begin{array}{|l|l|}` con `<br>` come separatore di riga:
```latex
\begin{array}{|l|l|}
\hline
DATI & INCOGNITE\\
\hline
L_0=503\text{ m} & \Delta L=?\\
\lambda=1{,}2 \times 10^{-5}\,^{\circ}\text{C}^{-1} & \\
\Delta T=20\,^{\circ}\text{C} & \\
\hline
\end{array}
```

### Svolgimento con `align*` e incognite colorate

> **REGOLA MANDATORIA per problemi fisico-matematici type_Collect**

#### Incognite colorate con `\enclose{circle}`

| Ruolo | LaTeX |
|-------|-------|
| Incognita **principale** (richiesta) | `\enclose{circle}[mathcolor=red]{X}` |
| 1ª ausiliaria | `\enclose{circle}[mathcolor=blue]{Y}` |
| 2ª ausiliaria | `\enclose{circle}[mathcolor=purple]{Z}` |
| 3ª ausiliaria | `\enclose{circle}[mathcolor=green]{W}` |

#### Formato `align*`

Lo svolgimento usa `\begin{align*}...\end{align*}` con ogni riga in un `<div>` separato.

> **ORDINE MANDATORIO — Sostituzione in avanti (substitution-forward)**
> - Lo svolgimento parte SEMPRE dall'incognita **principale** (rossa).
> - MAI calcolare un'ausiliaria prima: si parte dalla rossa, si sostituisce la formula dell'ausiliaria inline, e si calcola tutto in un'unica espressione numerica.
> - Le righe di definizione delle ausiliarie (`\lower{…}\colorbox{…}`) vanno come **ultime righe dentro `align*`** (non dopo).

##### Senza incognite ausiliarie (formula diretta)

1. **Prima riga**: `\enclose{circle}[mathcolor=red]{X} &= formula =\\`
2. **Righe successive**: sostituzione numerica con unità e `\cancel{}`
3. **Ultima riga**: risultato in `\fcolorbox`

```html
<div>\(\begin{align*}</div>
<div>\enclose{circle}[mathcolor=red]{\Delta L} &= \lambda \cdot L_0 \cdot \Delta T =\\</div>
<div> &= 1{,}2 \times 10^{-5}\,\cancel{^{\circ}\text{C}}^{-1} \cdot 503\text{ m} \cdot 20\,\cancel{^{\circ}\text{C}} =\fcolorbox{red}{yellow}{$\color{black}<span class="solution">0{,}12\text{ m}</span>$}</div>
<div>\end{align*} \)</div>
```

##### Con incognite ausiliarie (substitution-forward)

1. **Prima riga**: formula simbolica della rossa, contenente la blu. Dove la blu va espansa, mettere `\underset{\colorbox{yellow}{$ \,\text{ I }\,$}}{=}` seguito dalla formula espansa **+ il resto** della formula principale, poi `=\\`
2. **Righe successive**: sostituzione numerica **unica** (tutti i valori nella stessa espressione, inclusi quelli dell'ausiliaria)
3. **Riga risultato**: `\fcolorbox`
4. **Ultime righe dentro `align*`**: definizioni simboliche delle ausiliarie con `\lower{-1pt}\colorbox{yellow}{…}`, SOLO formula simbolica, **NESSUN calcolo numerico**

Numerazione etichette: `I`, `II`, `III`, `IV`…

```html
<div>\(\begin{align*}</div>
<div>\enclose{circle}[mathcolor=red]{Q} &= \enclose{circle}[mathcolor=blue]{\Phi} \cdot \Delta t\underset{\colorbox{yellow}{$ \,\text{ I }\,$}}{=}\frac{k \cdot A \cdot \Delta T}{L}\cdot \Delta t=\\</div>
<div> &= \frac{0{,}840\,\frac{\text{W}}{\cancel{\text{m}} \cdot \cancel{\text{K}}} \cdot 1{,}50\,\cancel{\text{m}}^2 \cdot 25{,}0\,\cancel{\text{K}}}{5{,}00 \times 10^{-3}\,\cancel{\text{m}}}  \cdot 3600\text{ s} = 2{,}27 \times 10^7\text{ J} =\fcolorbox{red}{yellow}{$\color{black}<span class="solution">22{,}7 \times 10^{3}\text{ kJ}</span>$}\\</div>
<div>\lower{-1pt}\colorbox{yellow}{$ \text{ I }$}\quad  \enclose{circle}[mathcolor=blue]{\Phi} &=\frac{k \cdot A \cdot \Delta T}{L}</div>
<div>\end{align*} \)</div>
```

> **⚠️ Cosa NON fare:** MAI scrivere prima il calcolo di Φ (blu) e poi usare il risultato numerico in Q (rossa). Il calcolo numerico avviene **una sola volta**, sostituendo direttamente la formula dell'ausiliaria nell'espressione della principale.

---

## Simboli vietati

> **REGOLA MANDATORIA:** I simboli sotto sono vietati in QUALSIASI tipo di esercizio (sol, giustsol, traccia).

### Comandi LaTeX vietati

| Vietato | Motivo | Alternativa |
|---------|--------|-------------|
| `\checkmark` | Conflitto con rendering MathJax | `\text{V}` o nessun simbolo |
| `\xmark`, `\crossmark` | Non supportato | `\text{F}` o nessun simbolo |
| `\tick`, `\cross` | Non standard | Rimuovere |
| `\square`, `\blacksquare` | Conflitto con checkbox HTML | Rimuovere |
| `\bigcirc` (come selezione) | Ambiguo con pallini difficoltà | Rimuovere |

### Simboli Unicode vietati (in qualsiasi contesto)

`✓` `✗` `☑` `☐` `✅` `❌` `✔` `✘` `☒` `▪` `▫`

> Per indicare risposte corrette/errate: usare le classi CSS (`solchecked`, `V`, `F`) — MAI simboli nel contenuto.

---

## Validazione

- Tutti i `\(` devono avere un corrispondente `\)`
- Tutti i `\[` devono avere un corrispondente `\]`
- No `$` o `$$` residui
- No simboli Unicode che hanno equivalente LaTeX (±, ≤, ≥, →, ∞, ², ³)
- No simboli vietati (§ Simboli vietati)
- Decimali con `{,}` non con `.` (convenzione EU)