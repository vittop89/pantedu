#!/usr/bin/env bash
#
# Passaggio dell'installazione al rilascio a container. Si esegue una volta.
#
# Cosa cambia, in concreto:
#   - nginx dell'host smette di servire l'applicazione e la passa a un
#     container, scegliendolo da una riga sola in
#     /etc/nginx/conf.d/pantedu-upstream.conf;
#   - `pantedu-deploy.service` lancia `pantedu-deploy-container.sh` invece di
#     `pantedu-deploy.sh`.
#
# Cosa NON cambia: il database, i dati d'istanza, i certificati, il servizio
# TeX, Grafana, il webhook, i timer, gli avvisi. Il sorgente in
# /var/www/pantedu resta dov'è — gli strumenti a riga di comando e l'avviso di
# guasto devono funzionare anche quando il container è morto.
#
# Con `--prova` costruisce e avvia il container su una porta senza traffico, lo
# verifica e si ferma lì: nginx continua a servire come prima. È il modo di
# guardare prima di decidere.
#
# Per tornare indietro, in qualunque momento:
#     sudo cp /etc/nginx/sites-available/pantedu.eu.conf.prima-dei-container \
#             /etc/nginx/sites-available/pantedu.eu.conf
#     sudo sed -i 's|pantedu-deploy-container.sh|pantedu-deploy.sh|' \
#             /etc/systemd/system/pantedu-deploy.service
#     sudo systemctl daemon-reload && sudo nginx -t && sudo systemctl reload nginx
#     sudo docker rm -f pantedu-app-a pantedu-app-b

set -euo pipefail

REPO_DIR="/var/www/pantedu"
DATI="/var/lib/pantedu-data"
VHOST_ATTIVO="/etc/nginx/sites-available/pantedu.eu.conf"
VHOST_SALVATO="/etc/nginx/sites-available/pantedu.eu.conf.prima-dei-container"
UPSTREAM="/etc/nginx/conf.d/pantedu-upstream.conf"
UNITA="/etc/systemd/system/pantedu-deploy.service"
NOME="pantedu-app-a"
PORTA=8090

PROVA=0
[[ "${1:-}" == "--prova" ]] && PROVA=1

nota()   { printf '[passaggio] %s\n' "$*"; }
errore() { printf '[passaggio] ERRORE: %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || errore "va eseguito come root"
command -v docker >/dev/null || errore "docker non è installato"

# ── Controlli preliminari ─────────────────────────────────────────────────
[ -d "$DATI/storage" ] || errore "$DATI/storage non c'è: i dati d'istanza non sono dove credo."
[ -r "$REPO_DIR/.env" ] || errore "$REPO_DIR/.env non è leggibile."
[ -f "$REPO_DIR/docker/Dockerfile" ] || errore "il sorgente non ha docker/Dockerfile: fai prima un rilascio."

# `www-data` dentro il container ha uid 33, come sull'host. Se i dati non sono
# scrivibili da lui, il container si rifiuterà di partire — meglio saperlo
# adesso che a metà scambio. È la lezione dei due disservizi dell'8 settembre:
# verificare come l'utente che serve le pagine, non come root.
sudo -u www-data test -w "$DATI/storage" \
    || errore "www-data non scrive in $DATI/storage: sistema proprietario e permessi prima."

COMMIT=$(sudo -u pantedu git -C "$REPO_DIR" rev-parse HEAD)
ETICHETTA="ghcr.io/vittop89/pantedu:$COMMIT"
nota "commit in produzione: ${COMMIT:0:8}"

# ── 1. Gli script del rilascio ────────────────────────────────────────────
nota "installo gli script"
install -m 755 -o root -g root "$REPO_DIR/tools/webhook/deploy-container.sh" \
    /usr/local/bin/pantedu-deploy-container.sh

# ── 2. L'immagine ─────────────────────────────────────────────────────────
if docker image inspect "$ETICHETTA" >/dev/null 2>&1; then
    nota "immagine già presente"
else
    nota "costruisco l'immagine (qualche minuto su due CPU)"
    docker build -f "$REPO_DIR/docker/Dockerfile" -t "$ETICHETTA" \
        --build-arg "COMMIT=$COMMIT" "$REPO_DIR" \
        || errore "il build è fallito: non ho toccato niente."
fi

# ── 3. Il container, su una porta senza traffico ──────────────────────────
nota "avvio $NOME sulla porta $PORTA (nessun traffico ancora)"
docker rm -f "$NOME" >/dev/null 2>&1 || true

# `-p 127.0.0.1:` e non `-p` nudo: Docker scrive regole iptables proprie e
# scavalca ufw. Una porta pubblicata senza indirizzo sarebbe raggiungibile da
# Internet anche se ufw la nega. Così la vede solo nginx.
docker run -d \
    --name "$NOME" \
    --restart unless-stopped \
    -p "127.0.0.1:$PORTA:8080" \
    --add-host host.docker.internal:host-gateway \
    -v "$DATI:/var/lib/pantedu-data" \
    -v "$REPO_DIR/.env:/var/www/pantedu/.env:ro" \
    -v "$REPO_DIR/.env.local:/var/www/pantedu/.env.local:ro" \
    -v /var/lib/pantedu-deploy:/var/lib/pantedu-deploy \
    --mount type=bind,src=/run/mysqld/mysqld.sock,dst=/run/mysqld/mysqld.sock \
    -e PANTEDU_DATA_PATH=/var/lib/pantedu-data \
    -e DB_SOCKET=/run/mysqld/mysqld.sock \
    --memory 1g \
    --health-start-period 90s \
    "$ETICHETTA" >/dev/null \
    || errore "il container non è partito."

nota "aspetto che si dichiari sano"
SANO=0
for tentativo in $(seq 1 45); do
    STATO=$(docker inspect -f '{{.State.Health.Status}}' "$NOME" 2>/dev/null || echo assente)
    case "$STATO" in
        healthy) SANO=1; nota "sano dopo $((tentativo * 3)) secondi"; break ;;
        unhealthy|assente) break ;;
    esac
    sleep 3
done

if [ "$SANO" -ne 1 ]; then
    docker logs --tail 40 "$NOME" 2>&1 | sed 's/^/    /' >&2 || true
    docker rm -f "$NOME" >/dev/null 2>&1 || true
    errore "il container non è mai stato sano: nginx serve ancora come prima."
fi

# ── 4. Le rotte, direttamente sul container ───────────────────────────────
nota "controllo le rotte sul container"
GUASTE=""
for P in /health /version /login /register /legal/tos /privacy/informativa /accessibility /css/main.bundle.css; do
    C=$(curl -s -o /dev/null -w '%{http_code}' -H 'X-Forwarded-For: 127.0.0.1' \
        --max-time 10 "http://127.0.0.1:$PORTA$P" 2>/dev/null || echo 000)
    printf '    %s  %s\n' "$C" "$P"
    case "$C" in 2*|3*) ;; *) GUASTE="$GUASTE $P($C)" ;; esac
done
[ -z "$GUASTE" ] || { docker rm -f "$NOME" >/dev/null 2>&1 || true; errore "sul container non rispondono:$GUASTE"; }

SALUTE=$(curl -s -H 'X-Forwarded-For: 127.0.0.1' --max-time 10 "http://127.0.0.1:$PORTA/health" || echo "")
echo "$SALUTE" | grep -q '"db":true' \
    || { docker rm -f "$NOME" >/dev/null 2>&1 || true; errore "/health non dichiara il database: $SALUTE"; }
nota "il container serve tutto quello che deve"

if [ $PROVA -eq 1 ]; then
    nota "=== PROVA finita ==="
    nota "Il container gira su 127.0.0.1:$PORTA e nginx NON è stato toccato."
    nota "Per guardarlo:  curl -s http://127.0.0.1:$PORTA/health"
    nota "Per fare sul serio:  bash $0"
    nota "Per toglierlo:  docker rm -f $NOME"
    exit 0
fi

# ── 5. Lo scambio ─────────────────────────────────────────────────────────
nota "scambio nginx"
cp -a "$VHOST_ATTIVO" "$VHOST_SALVATO"
cat > "$UPSTREAM" <<EOF
# Scritto da passa-ai-container.sh il $(date '+%Y-%m-%d %H:%M:%S').
# Commit in servizio: $COMMIT
upstream pantedu_app { server 127.0.0.1:$PORTA; }
EOF
cp -a "$REPO_DIR/infra/nginx/pantedu.eu.container.conf" "$VHOST_ATTIVO"

rimetti() {
    printf '[passaggio] rimetto nginx com'"'"'era\n' >&2
    cp -a "$VHOST_SALVATO" "$VHOST_ATTIVO"
    rm -f "$UPSTREAM"
    nginx -t >/dev/null 2>&1 && systemctl reload nginx || true
}

nginx -t >/dev/null 2>&1 || { rimetti; docker rm -f "$NOME" >/dev/null 2>&1 || true; errore "la configurazione di nginx non regge."; }
systemctl reload nginx || { rimetti; docker rm -f "$NOME" >/dev/null 2>&1 || true; errore "reload di nginx fallito."; }

# ── 6. La verifica passa da nginx ─────────────────────────────────────────
# È la lezione dello scambio di stamattina: il container rispondeva, nginx no.
sleep 2
GUASTE=""
for P in /login /health /accessibility /legal/tos; do
    C=$(curl -sk -o /dev/null -w '%{http_code}' -H 'Host: pantedu.eu' -H 'X-Forwarded-For: 127.0.0.1' \
        --max-time 10 "https://127.0.0.1$P" 2>/dev/null || echo 000)
    printf '    %s  %s\n' "$C" "$P"
    case "$C" in 2*|3*) ;; *) GUASTE="$GUASTE $P($C)" ;; esac
done

if [ -n "$GUASTE" ]; then
    rimetti
    docker rm -f "$NOME" >/dev/null 2>&1 || true
    errore "attraverso nginx non rispondono:$GUASTE — rimesso tutto com'era."
fi

SALUTE=$(curl -sk -H 'Host: pantedu.eu' -H 'X-Forwarded-For: 127.0.0.1' --max-time 10 https://127.0.0.1/health || echo "")
if ! echo "$SALUTE" | grep -q '"db":true'; then
    rimetti
    docker rm -f "$NOME" >/dev/null 2>&1 || true
    errore "/health attraverso nginx non dichiara il database: $SALUTE"
fi

# ── 7. Il rilascio d'ora in poi ───────────────────────────────────────────
nota "cambio l'unità del rilascio"
sed -i 's|^ExecStart=/usr/local/bin/pantedu-deploy\.sh$|ExecStart=/usr/local/bin/pantedu-deploy-container.sh|' "$UNITA"
grep -q 'pantedu-deploy-container.sh' "$UNITA" || errore "non sono riuscito a cambiare $UNITA"
systemctl daemon-reload

# php-fpm sull'host non serve più all'applicazione, ma NON lo si ferma qui: lo
# usano ancora gli strumenti a riga di comando e, soprattutto, se domani si
# torna indietro dev'essere in piedi. Spegnerlo è una decisione separata, da
# prendere quando il container avrà qualche settimana alle spalle.

nota "=== FATTO ==="
nota "In servizio: $NOME su 127.0.0.1:$PORTA, commit ${COMMIT:0:8}"
nota "Il vhost di prima è salvato in $VHOST_SALVATO"
nota "Per tornare indietro, le istruzioni sono in testa a questo script."
