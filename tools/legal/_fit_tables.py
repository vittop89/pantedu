#!/usr/bin/env python3
"""
Riproporziona le tabelle pipe di un markdown prima della conversione in PDF.

PERCHE' SERVE
  Pandoc ricava la larghezza delle colonne del PDF dal NUMERO DI TRATTINI nella
  riga separatrice del markdown, non dal contenuto. Una tabella scritta cosi':

      | Sub-processor | Servizio | Localizzazione | DPA |
      |---------------|----------|----------------|-----|

  assegna a "DPA" l'11% della larghezza — ed e' la colonna col testo piu' lungo.
  Nel registro art. 30 quella cella andava a capo dieci volte mentre
  "Localizzazione" restava mezza vuota.

  In HTML il problema non esiste (il browser dimensiona da se'), quindi il
  markdown non va toccato: si riscrivono i separatori solo su una copia
  temporanea, in fase di build.

COME
  Per ogni colonna si prende la lunghezza della cella piu' lunga, si comprime
  con una radice quadrata — cosi' una singola cella molto lunga non schiaccia
  le altre — e si normalizza. Ogni colonna ha comunque un minimo garantito.

Uso:  _fit_tables.py < input.md > output.md
"""
import sys, re, math

MIN_W, TOTAL = 6, 90


def cells(line):
    """Celle di una riga pipe, senza i delimitatori esterni."""
    return [c.strip() for c in line.strip().strip('|').split('|')]


def visible_len(s):
    """Lunghezza percepita: via la sintassi markdown che non si stampa."""
    s = re.sub(r'\[([^\]]*)\]\([^)]*\)', r'\1', s)   # link -> solo etichetta
    s = re.sub(r'[*`_]', '', s)                       # enfasi e codice
    return len(s)


SEP = re.compile(r'^\s*\|[\s:|-]*\|\s*$')


def is_sep(line):
    return bool(SEP.match(line)) and '-' in line


def main():
    lines = sys.stdin.read().split('\n')
    out, i = [], 0
    while i < len(lines):
        # una tabella = riga di intestazione + separatore + righe corpo
        if i + 1 < len(lines) and is_sep(lines[i + 1]) and lines[i].strip().startswith('|'):
            head = cells(lines[i])
            n = len(head)
            body, j = [], i + 2
            while j < len(lines) and lines[j].strip().startswith('|'):
                body.append(cells(lines[j]))
                j += 1

            widths = []
            for c in range(n):
                longest = visible_len(head[c])
                for row in body:
                    if c < len(row):
                        longest = max(longest, visible_len(row[c]))
                # radice quadrata: comprime gli estremi senza appiattire tutto
                widths.append(max(math.sqrt(longest), 1.0))

            tot = sum(widths)
            dashes = [max(MIN_W, round(w / tot * TOTAL)) for w in widths]

            # allineamenti originali (:--- , ---: , :---:) preservati
            orig = cells(lines[i + 1])
            new = []
            for c in range(n):
                a = orig[c] if c < len(orig) else '---'
                left, right = a.startswith(':'), a.endswith(':')
                d = '-' * dashes[c]
                new.append((':' if left else '') + d + (':' if right else ''))

            out.append(lines[i])
            out.append('|' + '|'.join(new) + '|')
            out.extend(lines[i + 2:j])
            i = j
            continue
        out.append(lines[i])
        i += 1
    sys.stdout.write('\n'.join(out))


if __name__ == '__main__':
    main()
