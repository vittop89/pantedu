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
# 19/9/2026 — DOVE ASCOLTA. Dal 24/5/2026 il servizio sta sulla stessa
# macchina dell'applicazione, e dall'8/9/2026 l'applicazione gira in un
# container Docker: il servizio ascolta sul gateway del bridge Docker (docker0),
# non su 127.0.0.1, che dal container è il container stesso. Quindi Docker va
# installato PRIMA di questo script (il passo 7 si ferma, se manca). I passi
# 8-10 (nginx e TLS per un dominio suo) sono dell'assetto a due macchine: sulla
# macchina unica il servizio non ha un dominio e non passa da nginx. Il perché,
# i passi a mano e il ritorno indietro: docs/ops/tex-dal-container.md.
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
# L'elenco sta in `pacchetti-debian.txt`, lo stesso che usa il Dockerfile della
# prova in locale. Fino al 14/9/2026 stava scritto qui, e non era più quello
# del server: mancavano `texlive-extra-utils` (latexindent) e `librsvg2-bin`
# (rsvg-convert), installati a mano sul VPS. Il perché è nel file.
echo "==> [3/10] Installazione TeX Live (pacchetti-debian.txt, qualche GB)..."
mapfile -t PACCHETTI_TEX < <(grep -vE '^[[:space:]]*(#|$)' "$SCRIPT_DIR/pacchetti-debian.txt")
[[ ${#PACCHETTI_TEX[@]} -gt 0 ]] || { echo "ERRORE: pacchetti-debian.txt vuoto o assente"; exit 1; }
apt-get install -yq "${PACCHETTI_TEX[@]}"

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
# Il servizio ascolta sul gateway del bridge Docker: l'unico indirizzo che vale
# sia sull'host (lavori a orario) sia nel container dell'applicazione. Lo si
# chiede a Docker invece di scriverlo, così segue la sua configurazione.
echo "==> [7/10] systemd unit (in ascolto sul gateway del bridge Docker)..."
if ! command -v docker >/dev/null 2>&1; then
    echo "ERRORE: Docker non c'è. Il servizio ascolta sul gateway del bridge Docker,"
    echo "        che esiste solo con Docker installato e avviato: installalo prima."
    exit 1
fi
GATEWAY="$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || true)"
RETE_BRIDGE="$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Subnet}}' 2>/dev/null || true)"
if [[ -z "$GATEWAY" || -z "$RETE_BRIDGE" ]]; then
    echo "ERRORE: non riesco a chiedere a Docker gateway e rete del bridge (docker network inspect bridge)."
    exit 1
fi
if grep -q '^TEX_COMPILE_HOST=' "$APP_DIR/.env"; then
    sed -i "s|^TEX_COMPILE_HOST=.*$|TEX_COMPILE_HOST=$GATEWAY|" "$APP_DIR/.env"
else
    echo "TEX_COMPILE_HOST=$GATEWAY" >> "$APP_DIR/.env"
fi
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
echo "  ✓ tex-compile attivo su $GATEWAY:8001 (gateway del bridge Docker)"

# ─── 8. nginx HTTP-only temporaneo + firewall ──────────────────────────
echo "==> [8/10] nginx HTTP-only (per ACME challenge) + firewall..."

ufw allow OpenSSH
ufw allow http
ufw allow https
# I container raggiungono il servizio, e solo loro: in entrata da docker0, dalla
# rete del bridge, verso il gateway e la porta del servizio. Un `ufw allow 8001`
# generico lascerebbe come unica barriera il firewall del fornitore.
ufw allow in on docker0 from "$RETE_BRIDGE" to "$GATEWAY" port 8001 proto tcp comment 'TeX per i container'
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
