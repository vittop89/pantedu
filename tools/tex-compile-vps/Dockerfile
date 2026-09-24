# Il servizio TeX in un container, per provare in locale come sul VPS.
#
# In produzione NON gira così: lo installa `provision.sh` direttamente
# sull'host, come unità systemd (`systemd/tex-compile.service`). Questo file
# serve a `tools/dev/wsl/vps-locale.sh` e ne riproduce le parti che contano
# per l'applicazione, misurate sul server il 14 settembre 2026:
#
#   - la stessa Debian (13, trixie) e quindi lo stesso Python (3.13);
#   - gli stessi pacchetti, letti da `pacchetti-debian.txt`, lo stesso elenco
#     che usa `provision.sh`;
#   - le stesse dipendenze Python (`app/requirements.txt`);
#   - lo stesso comando di avvio, con due lavoratori, e un utente senza
#     privilegi come `texcompile` sul server.
#
# Cosa cambia, di necessità: ascolta su tutte le interfacce del container e
# non su 127.0.0.1, perché l'applicazione lo raggiunge dalla rete di Docker.
#
# Pesa: TeX Live con i font extra sono qualche gigabyte, e la prima
# costruzione dura parecchi minuti. Le successive riusano la cache.
FROM debian:trixie-slim

ENV DEBIAN_FRONTEND=noninteractive

# Le raccomandazioni restano: `provision.sh` usa `apt-get install` senza
# `--no-install-recommends`, e un pacchetto raccomandato che in produzione c'è
# e qui no sarebbe una differenza nascosta.
COPY pacchetti-debian.txt /tmp/pacchetti-debian.txt
RUN set -eux; \
    apt-get update; \
    apt-get install -y python3 python3-venv ca-certificates \
        $(grep -vE '^[[:space:]]*(#|$)' /tmp/pacchetti-debian.txt); \
    rm -rf /var/lib/apt/lists/* /tmp/pacchetti-debian.txt

RUN useradd --system --no-create-home --shell /usr/sbin/nologin texcompile

WORKDIR /opt/tex-compile
COPY app/requirements.txt app/requirements.txt
RUN python3 -m venv venv \
    && venv/bin/pip install --quiet --upgrade pip \
    && venv/bin/pip install --quiet -r app/requirements.txt
COPY app app

# Come sul server: la cartella di lavoro sta in /tmp, che lì è privata al
# servizio (PrivateTmp=yes) e qui è del container.
ENV TEX_COMPILE_WORKDIR=/tmp/tex-compile

USER texcompile
EXPOSE 8001

HEALTHCHECK --interval=10s --timeout=5s --start-period=20s --retries=3 \
    CMD ["/opt/tex-compile/venv/bin/python", "-c", "import sys, urllib.request; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8001/health', timeout=4).status == 200 else 1)"]

CMD ["/opt/tex-compile/venv/bin/uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001", "--workers", "2", "--no-access-log"]
