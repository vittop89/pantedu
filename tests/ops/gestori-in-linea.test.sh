#!/bin/bash
#
# Prove di `tools/ci/no-inline-handlers.mjs`, nei due versi (23/9/2026).
#
# La guardia guardava solo views/, e fuori erano rimasti sei gestori in linea
# (`onclick=`), muti in produzione dove la CSP è rigorosa: uno scritto da una
# classe PHP (app/), cinque da moduli JS che scrivono HTML con innerHTML
# (revisione architetturale del 23/9/2026, A-19). Adesso guarda anche app/ e
# js/, e la forma `setAttribute("onclick", …)`. Qui si prova che:
#   - un albero pulito passa, e una riga di commento con `onclick="…"` non conta;
#   - un `onclick=` in una vista, in una classe PHP (anche scappato in una
#     stringa fra doppi apici) e in un modulo JS la fa fallire, con il file e
#     la riga;
#   - `setAttribute("onclick", …)` la fa fallire, in PHP e in JS;
#   - un .html sotto views/ oltre il tetto la fa fallire, sotto no (il tetto
#     è zero dal 23/9/2026, quando è stato tolto views/admin/delete_temp.html,
#     l'ultimo .html con un gestore in linea: revisione A-45);
#   - un'eccezione che non trova più la sua riga la fa fallire;
#   - sul repository vero passa.
#
# Alberi usa e getta in una cartella temporanea, con le righe che la guardia
# accetta come eccezioni (se no fallirebbe per le eccezioni morte).
#
# Uso: bash tests/ops/gestori-in-linea.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
RADICE_REPO=$(cd "$QUI/../.." && pwd)
GUARDIA="$RADICE_REPO/tools/ci/no-inline-handlers.mjs"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# Un albero pulito: le tre righe delle eccezioni, un .html senza gestori (al
# tetto, che è zero), un commento con un esempio di gestore, e codice che usa
# addEventListener.
albero() {
    local r="$T/$1"
    mkdir -p "$r/views/admin" "$r/app/Services" "$r/js/modules/risdoc/pt" "$r/js/modules/ui"
    printf '<div>\n<button data-fm-action="x">Ok</button>\n</div>\n' > "$r/views/pagina.php"
    printf '<button type="button">x</button>\n' > "$r/views/admin/pagina.html"
    cat > "$r/app/Services/Pulito.php" <<'PHP'
<?php
/**
 * Toglie `<span onclick="...">` dal contenuto.
 */
// o `<span onclick="">` direttamente nei blocchi
$h = '<button type="button" data-fm-chiudi-scheda="/admin">x</button>';
PHP
    printf "    '<p onclick=\"alert(1)\">x</p>',\n" > "$r/js/modules/risdoc/pt/html-sanitizer.js"
    cat > "$r/js/modules/ui/ui-comp.js" <<'JS'
        label.setAttribute("onclick", "event.stopPropagation();");
      checkbox.setAttribute("onclick", "event.stopPropagation();");
JS
    printf 'el.addEventListener("click", () => el.select());\n' > "$r/js/modules/pulito.js"
    echo "$r"
}

guardia() { node "$GUARDIA" "$1" > "$T/uscita" 2>&1; }

# 1. L'albero pulito passa.
r=$(albero pulito)
if guardia "$r"; then ok; else ko "l'albero pulito non passa: $(cat "$T/uscita")"; fi

# 2. Un onclick in una vista .php.
r=$(albero vista)
printf '<button onclick="fai()">x</button>\n' >> "$r/views/pagina.php"
if ! guardia "$r" && grep -q 'views/pagina.php:4' "$T/uscita"; then ok; else ko "onclick in una vista: non scatta o non dice la riga"; fi

# 3. Un onclick scritto da una classe PHP, come il «✕ Esci» di TemplateViewController.
r=$(albero classe)
cat >> "$r/app/Services/Pulito.php" <<'PHP'
$b = '<button type="button"'
    . ' onclick="try{window.close();}catch(_){}"'
    . '>x</button>';
PHP
if ! guardia "$r" && grep -q 'app/Services/Pulito.php' "$T/uscita"; then ok; else ko "onclick in una classe PHP: non scatta"; fi

# 4. Lo stesso fra doppi apici, con le virgolette scappate.
r=$(albero classe-doppi-apici)
printf '%s\n' '$c = "<a onclick=\"x()\">y</a>";' >> "$r/app/Services/Pulito.php"
if ! guardia "$r"; then ok; else ko "onclick fra doppi apici in PHP: non scatta"; fi

# 5. Un onclick in un modulo JS che scrive HTML, come la chiave di recupero del cruscotto.
r=$(albero js)
printf 'm.innerHTML = `<textarea readonly onclick="this.select()"></textarea>`;\n' >> "$r/js/modules/pulito.js"
if ! guardia "$r" && grep -q 'js/modules/pulito.js:2' "$T/uscita"; then ok; else ko "onclick in un modulo JS: non scatta"; fi

# 6. setAttribute("onclick", …) in JS e in PHP.
r=$(albero set-attribute-js)
printf 'cb.setAttribute("onclick", "event.stopPropagation();");\n' >> "$r/js/modules/pulito.js"
if ! guardia "$r"; then ok; else ko "setAttribute(\"onclick\") in JS: non scatta"; fi
r=$(albero set-attribute-php)
printf '%s\n' "\$cb->setAttribute('onclick', 'event.stopPropagation();');" >> "$r/app/Services/Pulito.php"
if ! guardia "$r"; then ok; else ko "setAttribute('onclick') in PHP: non scatta"; fi

# 7. Il tetto dei .html: uno in più fa fallire.
r=$(albero html)
printf '<a onclick="x()">y</a>\n' > "$r/views/admin/altro.html"
if ! guardia "$r" && grep -q 'baseline 0' "$T/uscita"; then ok; else ko "un .html con un gestore non fa scattare il tetto"; fi

# 8. Un'eccezione morta: la riga di ui-comp.js non c'è più.
r=$(albero eccezione-morta)
printf '        label.setAttribute("for", id);\n      checkbox.setAttribute("onclick", "event.stopPropagation();");\n' > "$r/js/modules/ui/ui-comp.js"
if ! guardia "$r" && grep -q 'non trovano più la loro riga' "$T/uscita"; then ok; else ko "un'eccezione morta non fa fallire la guardia"; fi

# 9. Sul repository vero passa (è lo stesso comando della CI).
if node "$GUARDIA" > "$T/uscita" 2>&1; then ok; else ko "sul repository vero: $(cat "$T/uscita")"; fi

echo "gestori-in-linea: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
