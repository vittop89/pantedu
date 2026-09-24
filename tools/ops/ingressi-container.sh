#!/bin/bash
#
# Gli ingressi nei container, da quello che auditd ha registrato (15/9/2026).
#
# Si installa accanto al controllo notturno, in
# `/usr/local/sbin/ingressi-container.sh`: `aide-check.sh` lo cerca nella
# propria cartella, come `aide-spiega.sh`.
#
# ── Il punto cieco che chiude ─────────────────────────────────────────────
#
# La regola sulle letture di `.env.local` registra solo chi ha una sessione di
# login (`auid!=unset`), per non registrare php-fpm, che lo legge a ogni
# processo. Ma un processo entrato con `docker exec` non ha login: lo avvia il
# runtime dei container. Misurato il 15/9/2026 sul VPS: la sessione ssh ha
# `loginuid` 0, il processo di `docker exec` 4294967295, cioè «nessuno». Quindi
# `docker exec pantedu-app-b cat /var/www/pantedu/.env.local` non lasciava
# traccia.
#
# La lettura dentro il container non si registra in modo pulito. Si registra
# l'**ingresso**: chi esegue `docker`, `nsenter`, `runc` o `ctr` da una sessione
# di login (`tools/ops/audit-pantedu.rules`, chiave `pantedu_container_ingressi`).
# Il rilascio usa docker da systemd, senza login, e non compare.
#
# ── Cosa fa ───────────────────────────────────────────────────────────────
#
# Legge da stdin l'uscita di `ausearch -i -k pantedu_container_ingressi` e
# scrive:
#   INGRESSI=<n>   i comandi che entrano in un container o ne portano fuori
#                  file: docker exec, cp, run, attach, export, commit (anche
#                  come `docker container …` e `docker compose …`), e ogni
#                  nsenter, runc, ctr;
#   ALTRI=<n>      gli altri comandi docker di una persona: ps, logs, inspect…
# e poi una riga per ingresso: «<quando> auid=<chi> <comando>».
#
# Uso: ausearch --input-logs -i -k pantedu_container_ingressi -ts … </dev/null | ingressi-container.sh
set -uo pipefail

awk '
function azzera() { quando = ""; chi = ""; delete arg; n = 0 }
function base(p,   parti, k) { k = split(p, parti, "/"); return parti[k] }
# Le opzioni globali di docker che prendono un valore: il valore non è il sottocomando.
function con_valore(o) {
    return o == "-H" || o == "--host" || o == "-c" || o == "--context" || \
           o == "--config" || o == "-l" || o == "--log-level" || \
           o == "-f" || o == "--file" || o == "-p" || o == "--project-name" || \
           o == "--project-directory" || o == "--env-file" || o == "--profile"
}
# Il primo argomento che non è un opzione, a partire da i; -1 se non c è.
function sottocomando(i) {
    while (i < n) {
        if (arg[i] ~ /^-/) {
            if (con_valore(arg[i]) && arg[i] !~ /=/) i++
            i++
            continue
        }
        return i
    }
    return -1
}
function classifica(   eseguibile, i, sub_, riga, k) {
    if (n == 0) return
    eseguibile = base(arg[0])
    ingresso = 0
    if (eseguibile == "nsenter" || eseguibile == "runc" || eseguibile == "ctr") {
        ingresso = 1
    } else if (eseguibile == "docker") {
        i = sottocomando(1)
        sub_ = i >= 0 ? arg[i] : ""
        if (sub_ == "container" || sub_ == "compose") {
            i = sottocomando(i + 1)
            sub_ = i >= 0 ? arg[i] : ""
        }
        if (sub_ ~ /^(exec|cp|run|attach|export|commit)$/) ingresso = 1
    } else {
        return
    }
    if (ingresso) {
        riga = quando " auid=" chi
        for (k = 0; k < n; k++) riga = riga " " arg[k]
        righe[++ingressi] = riga
    } else {
        altri++
    }
}
BEGIN { ingressi = 0; altri = 0; azzera() }
/^----/ { classifica(); azzera(); next }
/^type=EXECVE / {
    if (match($0, /msg=audit\([^)]*\)/)) {
        quando = substr($0, RSTART + 10, RLENGTH - 11)
        sub(/\.[0-9]+:[0-9]+$/, "", quando)
    }
    for (f = 1; f <= NF; f++) {
        if ($f ~ /^a[0-9]+=/) {
            indice = substr($f, 2, index($f, "=") - 2) + 0
            arg[indice] = substr($f, index($f, "=") + 1)
            if (indice + 1 > n) n = indice + 1
        }
    }
    next
}
/^type=SYSCALL / {
    for (f = 1; f <= NF; f++) if ($f ~ /^auid=/) chi = substr($f, 6)
    next
}
END {
    classifica()
    print "INGRESSI=" ingressi
    print "ALTRI=" altri
    for (k = 1; k <= ingressi; k++) print righe[k]
}
'
