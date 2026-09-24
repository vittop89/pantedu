#!/usr/bin/env bash
#
# I pacchetti di sistema per sviluppare in WSL. Chiede sudo, quindi si lancia
# a mano, una volta per macchina:
#
#   sudo bash tools/dev/wsl/pacchetti.sh
#
# Due parti:
#
#   1. la catena della CI — PHP 8.3 con le estensioni dichiarate, il Node di .nvmrc,
#      Composer, il client MariaDB, shellcheck, le librerie del browser di
#      Playwright. La installa tools/ci/prepara-runner.sh, che qui si riusa:
#      l'elenco dei pacchetti sta in un posto solo;
#
#   2. quello che la CI non ha perché le prove TeX le salta: TeX Live con lo
#      stesso insieme del servizio di produzione (tools/tex-compile-vps/provision.sh),
#      rsvg-convert, pdftoppm e latexmk. Su Windows c'erano MiKTeX e svglib al
#      posto di rsvg-convert; qui c'è quello che gira sul VPS.
#
# TeX Live pesa qualche gigabyte: il primo giro dura parecchi minuti.
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "va lanciato con sudo: sudo bash $0"; exit 1; }
RADICE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"

echo "== 1. la catena della CI =="
bash "$RADICE/tools/ci/prepara-runner.sh" --solo-pacchetti

echo
echo "== 2. TeX e dintorni =="
export DEBIAN_FRONTEND=noninteractive
apt-get install -y -qq \
    texlive-base texlive-latex-base texlive-latex-recommended texlive-latex-extra \
    texlive-fonts-recommended texlive-fonts-extra texlive-lang-italian \
    texlive-pictures texlive-science texlive-xetex texlive-luatex \
    latexmk librsvg2-bin poppler-utils \
    python3-venv python3-pip

echo
echo "== controllo =="
for c in php node composer mariadb pdflatex latexmk rsvg-convert pdftoppm dvisvgm python3; do
    printf '  %-14s %s\n' "$c" "$(command -v "$c" >/dev/null 2>&1 && echo presente || echo MANCA)"
done
