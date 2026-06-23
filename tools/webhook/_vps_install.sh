#!/usr/bin/env bash
# One-time install on VPS. Run as root: bash _vps_install.sh
set -euo pipefail

REPO=/var/www/pantedu

echo "=== 1. secret HMAC ==="
mkdir -p /etc/pantedu
SECRET=$(openssl rand -hex 32)
echo "GITHUB_WEBHOOK_SECRET=${SECRET}" > /etc/pantedu/webhook.env
chmod 640 /etc/pantedu/webhook.env
chown root:www-data /etc/pantedu/webhook.env

echo "=== 2. deploy script ==="
cp "$REPO/tools/webhook/deploy.sh" /usr/local/bin/pantedu-deploy.sh
sed -i 's/\r$//' /usr/local/bin/pantedu-deploy.sh
chmod 755 /usr/local/bin/pantedu-deploy.sh
chown root:root /usr/local/bin/pantedu-deploy.sh

echo "=== 3. log file ==="
touch /var/log/pantedu-deploy.log
chown www-data:www-data /var/log/pantedu-deploy.log
chmod 660 /var/log/pantedu-deploy.log

echo "=== 4. sudoers ==="
echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/pantedu-deploy.sh" > /etc/sudoers.d/pantedu-deploy
chmod 440 /etc/sudoers.d/pantedu-deploy
visudo -c -f /etc/sudoers.d/pantedu-deploy

echo "=== 5. tex-compile system deps (one-time) ==="
# Pacchetti binari richiesti dal microservizio Python tex-compile-vps:
#   - texlive-latex-base/extra (pdflatex)
#   - latexindent (G22.S15 /format-tex)
#   - dvisvgm (G22.S15 /render-tikz, DVI/PDF → SVG)
#   - mupdf-tools (fornisce mutool: dvisvgm 3.4.4 richiede mutool quando
#     Ghostscript >= 10.01 — altrimenti fallisce su Debian 13 trixie)
#   - rsync (usato dal nuovo deploy.sh per sync /opt/tex-compile/app/)
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    latexindent dvisvgm mupdf-tools rsync curl

echo
echo "============================================="
echo "GITHUB WEBHOOK SECRET (incolla nella UI GitHub)"
echo "============================================="
echo "${SECRET}"
echo "============================================="
echo
echo "OK setup done. Configura nginx + GitHub webhook ora."
