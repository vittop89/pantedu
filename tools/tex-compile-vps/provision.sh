#!/usr/bin/env bash
# provision.sh — setup one-shot per VPS Debian 12/13.
#
# USO:
#   1. Copia questo script + cartelle app/ systemd/ nginx/ sul VPS:
#        scp -r tools/tex-compile-vps/ root@VPS_IP:/root/
#   2. SSH e lancia:
#        ssh root@VPS_IP
#        cd /root/tex-compile-vps
#        bash provision.sh tex.tuosito.it admin@tuosito.it
#
# IDEMPOTENTE: eseguibile più volte senza danno.
#
# ORDINE OPERAZIONI (importante):
#   1-7. Setup base + servizio FastAPI
#   8.   nginx HTTP-only temporaneo (per ACME challenge)
#   9.   certbot --nginx (genera cert + RIESCRIVE nginx config con HTTPS)
#   10.  Sostituisci nginx config con quella di produzione (rate limit,
#        security headers, ecc.) puntando ai cert appena generati.
#
set -euo pipefail

# ─── Args ──────────────────────────────────────────────────────────────
DOMAIN="${1:-}"
EMAIL="${2:-}"

if [[ -z "$DOMAIN" || -z "$EMAIL" ]]; then
    echo "Usage: $0 <subdomain.tuosito.it> <admin-email@example.com>"
    exit 1
fi

if [[ $EUID -ne 0 ]]; then
    echo "Esegui come root (o via sudo)."
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="/opt/tex-compile"
SERVICE_USER="texcompile"
NGINX_CONF="/etc/nginx/sites-available/tex-compile.conf"

echo "==> Provisioning tex-compile-vps su VPS Debian"
echo "    Dominio: $DOMAIN"
echo "    Email: $EMAIL"
echo "    Source: $SCRIPT_DIR"
echo "    Install dir: $APP_DIR"
echo ""

# ─── 1. System update ──────────────────────────────────────────────────
echo "==> [1/10] Aggiornamento sistema..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -yq

# ─── 2. Pacchetti base ─────────────────────────────────────────────────
echo "==> [2/10] Installazione pacchetti base + certbot..."
apt-get install -yq \
    curl \
    ca-certificates \
    ufw \
    fail2ban \
    nginx \
    python3 python3-venv python3-pip \
    git \
    certbot python3-certbot-nginx \
    bind9-host

# ─── 3. TeX Live ───────────────────────────────────────────────────────
# Set curato ~2GB; copre 99% dei casi scolastici.
# Per scheme-full (~5GB) sostituire con `texlive-full`.
echo "==> [3/10] Installazione TeX Live (set curato, ~2GB download)..."
apt-get install -yq \
    texlive-base \
    texlive-latex-base \
    texlive-latex-recommended \
    texlive-latex-extra \
    texlive-fonts-recommended \
    texlive-fonts-extra \
    texlive-lang-italian \
    texlive-pictures \
    texlive-science \
    texlive-xetex \
    texlive-luatex

# ─── 4. User dedicato ──────────────────────────────────────────────────
echo "==> [4/10] User di sistema $SERVICE_USER..."
if ! id "$SERVICE_USER" &>/dev/null; then
    useradd --system --no-create-home --shell /usr/sbin/nologin "$SERVICE_USER"
fi

# ─── 5. App layout ─────────────────────────────────────────────────────
# NB: NON creiamo /var/tmp/tex-compile perché systemd PrivateTmp=yes lo
# rende invisibile al servizio. Il WORKDIR del compile è /tmp/tex-compile
# (private al servizio, automaticamente scrivibile).
echo "==> [5/10] Layout applicazione..."
mkdir -p "$APP_DIR"
cp -r "$SCRIPT_DIR/app" "$APP_DIR/"
if [[ ! -f "$APP_DIR/.env" ]]; then
    cp "$SCRIPT_DIR/.env.example" "$APP_DIR/.env"
    SECRET="$(openssl rand -hex 32)"
    sed -i "s|^TEX_COMPILE_SECRET=.*$|TEX_COMPILE_SECRET=$SECRET|" "$APP_DIR/.env"
    echo ""
    echo "  ┌────────────────────────────────────────────────────────────────┐"
    echo "  │ SEGRETO HMAC GENERATO — copia in hosting legacy config!                 │"
    echo "  ├────────────────────────────────────────────────────────────────┤"
    echo "  │ $SECRET │"
    echo "  └────────────────────────────────────────────────────────────────┘"
    echo ""
fi

chown -R "$SERVICE_USER:$SERVICE_USER" "$APP_DIR"
chmod 600 "$APP_DIR/.env"

# ─── 6. Python venv + dipendenze ───────────────────────────────────────
echo "==> [6/10] Python venv + dipendenze..."
if [[ ! -d "$APP_DIR/venv" ]]; then
    python3 -m venv "$APP_DIR/venv"
fi
"$APP_DIR/venv/bin/pip" install --quiet --upgrade pip
"$APP_DIR/venv/bin/pip" install --quiet -r "$APP_DIR/app/requirements.txt"
chown -R "$SERVICE_USER:$SERVICE_USER" "$APP_DIR/venv"

# ─── 7. systemd service ────────────────────────────────────────────────
echo "==> [7/10] systemd unit..."
cp "$SCRIPT_DIR/systemd/tex-compile.service" /etc/systemd/system/tex-compile.service
systemctl daemon-reload
systemctl enable tex-compile.service
systemctl restart tex-compile.service
sleep 2
if ! systemctl is-active --quiet tex-compile; then
    echo "ERRORE: tex-compile non parte. Logs:"
    journalctl -u tex-compile -n 30 --no-pager -l
    exit 1
fi
echo "  ✓ tex-compile attivo su 127.0.0.1:8001"

# ─── 8. nginx HTTP-only temporaneo + firewall ──────────────────────────
echo "==> [8/10] nginx HTTP-only (per ACME challenge) + firewall..."

ufw allow OpenSSH
ufw allow http
ufw allow https
ufw --force enable

# Config minima HTTP-only per servire ACME challenge.
mkdir -p /var/www/certbot
cat > "$NGINX_CONF" <<NGINX_EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 200 "tex-compile temp HTTP — TLS in setup\n";
        add_header Content-Type text/plain;
    }
}
NGINX_EOF

rm -f /etc/nginx/sites-enabled/default
ln -sf "$NGINX_CONF" /etc/nginx/sites-enabled/tex-compile.conf
nginx -t
systemctl reload nginx
echo "  ✓ nginx HTTP temporaneo attivo"

# ─── 9. TLS via certbot (genera cert e ricarica nginx) ─────────────────
echo "==> [9/10] TLS Let's Encrypt via certbot..."

# Verifica DNS prima di chiamare LE (evita rate limit per dominio mal-configurato).
RESOLVED_IP="$(host -t A "$DOMAIN" 8.8.8.8 2>/dev/null | awk '/has address/ {print $4; exit}')"
EXPECTED_IP="$(curl -s ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')"

if [[ -z "$RESOLVED_IP" ]]; then
    echo "  ⚠️  DNS per $DOMAIN non risolve ancora. Salto certbot."
    echo "     Configura A record → $EXPECTED_IP, poi rilancia:"
    echo "       certbot --nginx -d $DOMAIN --email $EMAIL --agree-tos --non-interactive --redirect --hsts"
    CERT_OK=0
elif [[ "$RESOLVED_IP" != "$EXPECTED_IP" ]]; then
    echo "  ⚠️  DNS $DOMAIN → $RESOLVED_IP, ma questo VPS è $EXPECTED_IP."
    echo "     Aggiorna A record e rilancia certbot manualmente."
    CERT_OK=0
else
    echo "  ✓ DNS OK ($DOMAIN → $RESOLVED_IP). Lancio certbot..."
    if certbot --nginx \
        --non-interactive \
        --agree-tos \
        --email "$EMAIL" \
        --domain "$DOMAIN" \
        --redirect \
        --hsts; then
        CERT_OK=1
        echo "  ✓ Certificato TLS installato"
    else
        echo "  ⚠️  certbot fallito. Verifica logs e rilancia:"
        echo "       certbot --nginx -d $DOMAIN --email $EMAIL --agree-tos --non-interactive --redirect --hsts"
        CERT_OK=0
    fi
fi

# ─── 10. nginx config produzione (rate limit, headers, proxy) ──────────
echo "==> [10/10] nginx config produzione..."

if [[ "$CERT_OK" == "1" ]]; then
    # Sostituisci tex.tuosito.it nel template con dominio reale.
    sed "s|tex\.tuosito\.it|$DOMAIN|g" "$SCRIPT_DIR/nginx/tex-compile.conf" > "$NGINX_CONF"
    if nginx -t 2>&1; then
        systemctl reload nginx
        echo "  ✓ nginx PRODUZIONE attivo con TLS"
    else
        echo "  ⚠️  nginx config produzione invalida. Mantengo HTTP+cert."
    fi
else
    echo "  Lascio config HTTP-only minimale (cert non disponibile)."
    echo "  Dopo aver ottenuto il cert, esegui:"
    echo "    sed \"s|tex\\.tuosito\\.it|$DOMAIN|g\" $SCRIPT_DIR/nginx/tex-compile.conf > $NGINX_CONF"
    echo "    nginx -t && systemctl reload nginx"
fi

# ─── Done ──────────────────────────────────────────────────────────────
echo ""
echo "============================================================"
echo "  DONE"
echo "============================================================"
echo "  Servizio FastAPI: systemctl status tex-compile"
echo "  Logs:             journalctl -u tex-compile -f"
echo "  nginx:            systemctl status nginx"
echo ""
if [[ "$CERT_OK" == "1" ]]; then
    echo "  Test health (HTTPS):"
    echo "    curl https://$DOMAIN/health"
else
    echo "  Test health (HTTP temporaneo):"
    echo "    curl http://$DOMAIN/health  # (NB: il servizio risponde solo via /health proxy)"
fi
echo ""
echo "  Segreto HMAC (per integrazione hosting legacy):"
echo "    grep TEX_COMPILE_SECRET $APP_DIR/.env"
echo "============================================================"
