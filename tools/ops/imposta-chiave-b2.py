#!/usr/bin/env python3
"""Cambia la chiave di un remote rclone senza farla passare da nessuna parte.

Perché esiste, invece di `rclone config update`:

  - su questo VPS rclone è v1.60, e lì `config update` scrive il valore su una
    riga a sé, **rompendo il file di configurazione**. È successo il 9 settembre
    2026: dopo quel comando rclone non riusciva più a leggere nemmeno il remote
    che funzionava, e il salvataggio notturno delle 02:35 sarebbe fallito;
  - passare la chiave sulla riga di comando la mette nella cronologia della
    shell e, per un istante, nella lista dei processi visibile a chiunque;
  - `rclone config update` **ristampa la sezione a video**, chiave compresa,
    perché per i remote `b2` non è un campo che considera segreto.

Qui i valori si digitano a un prompt, l'applicationKey non viene riecheggiata,
e non compare mai in nessun output.

Uso (serve un terminale, quindi `ssh -t`):
    ssh -t pantedu-tunnel "python3 /usr/local/sbin/imposta-chiave-b2.py"
"""

import configparser
import getpass
import os
import re
import shutil
import subprocess
import sys

CONFIG = "/root/.config/rclone/rclone.conf"

# Sequenze di escape che un terminale può infilare nell'input da solo.
#
# Il caso vero, il 9 settembre 2026: cliccando fuori e poi dentro la finestra
# del terminale durante il prompt, xterm ha scritto `CSI O` e `CSI I` — i suoi
# codici di «ho perso il fuoco» e «ho ripreso il fuoco» — dentro
# l'applicationKey. `getpass` non mostra niente, quindi erano invisibili, e
# l'unico segnale è arrivato venti secondi dopo come `401 bad_auth_token`.
#
# Ci sono voluti tre tentativi per capirlo. Nessuna credenziale contiene un
# ESC: togliere questa roba non può far perdere niente di buono.
ESCAPE = re.compile(r"\x1b\[[0-9;?]*[a-zA-Z]|\x1b.|[\x00-\x08\x0b-\x1f\x7f]")


def ripulisci(grezzo: str, dove: str) -> str:
    """Toglie le sequenze del terminale e dice se ne ha trovate."""
    pulito = ESCAPE.sub("", grezzo).strip()
    if pulito != grezzo.strip():
        quanti = len(grezzo.strip()) - len(pulito)
        print(f"  nota: tolti {quanti} caratteri di controllo da {dove} "
              "(il terminale li scrive da solo quando la finestra perde o "
              "riprende il fuoco: non fare clic fuori mentre digiti)")
    return pulito


def main() -> int:
    if not os.path.isfile(CONFIG):
        print(f"non trovo {CONFIG}", file=sys.stderr)
        return 2

    conf = configparser.ConfigParser()
    conf.read(CONFIG)
    if not conf.sections():
        print("configurazione vuota o illeggibile", file=sys.stderr)
        return 2

    print("Remote configurati:", ", ".join(conf.sections()))
    nome = input("Quale vuoi aggiornare? [b2-pantedu] ").strip() or "b2-pantedu"
    if nome not in conf.sections():
        print(f"«{nome}» non esiste", file=sys.stderr)
        return 2

    # Che cosa si controlla, e cosa no.
    #
    # La prima versione pretendeva `keyID` di 25 caratteri con prefisso `003` e
    # `applicationKey` che cominciasse con `K003`. Ha rifiutato una chiave
    # perfettamente valida al primo uso vero.
    #
    # Era una supposizione travestita da controllo: quel `003` è il numero del
    # cluster Backblaze, non un formato garantito, e nessuno ci ha promesso che
    # resti quello. Un controllo di forma su un identificatore opaco di
    # qualcun altro non verifica niente — indovina, e prima o poi indovina
    # male, bloccando chi ha ragione.
    #
    # Restano i due controlli che dipendono solo da noi: non vuoto e senza
    # spazi (un incollaggio storto è l'errore vero e frequente). Il resto lo
    # dice la prova funzionale in fondo, che è l'unica che sappia davvero se la
    # chiave vale: se rclone non riesce, si torna indietro da soli.

    # Si spegne il tracciamento del fuoco (`CSI ?1004 l`) per la durata dei
    # prompt: così il terminale non ha proprio modo di scrivere quei codici,
    # invece di scriverli e farceli togliere dopo. La ripulitura resta come
    # rete, per i terminali che non ascoltano.
    if sys.stdin.isatty():
        sys.stdout.write("\033[?1004l")
        sys.stdout.flush()

    key_id = ripulisci(input("keyID: "), "il keyID")
    if not key_id or any(c.isspace() for c in key_id):
        print("keyID vuoto o con spazi dentro: mi fermo senza toccare niente",
              file=sys.stderr)
        return 1

    try:
        app_key = ripulisci(
            getpass.getpass("applicationKey (non verrà mostrata): "),
            "l'applicationKey",
        )
    except EOFError:
        # Senza terminale `getpass` non può nascondere l'input e solleva. Una
        # traccia di Python qui non aiuta nessuno: il rimedio è una lettera.
        print("\nserve un terminale: rilancia con  ssh -t  invece di  ssh",
              file=sys.stderr)
        return 2

    if not app_key or any(c.isspace() for c in app_key):
        print("applicationKey vuota o con spazi dentro: mi fermo senza toccare niente",
              file=sys.stderr)
        return 1

    if key_id == app_key:
        print("hai inserito due volte lo stesso valore: mi fermo", file=sys.stderr)
        return 1

    # Quanti caratteri sono arrivati davvero.
    #
    # `getpass` non mostra niente, ed è giusto così — ma vuol dire che un
    # incollaggio parziale è **invisibile**: il terminale ne lascia cadere un
    # pezzo, tu premi invio convinto, e l'unico segnale è un
    # «401 bad_auth_token» venti secondi dopo, che non dice quale metà è
    # sbagliata né perché.
    #
    # La lunghezza non è un segreto e non è una regola: è quello che è
    # arrivato. Confrontarla con quella della chiave nella console chiude la
    # domanda in un secondo, e non pretende di sapere che forma debba avere.
    print(f"\n  ricevuti: keyID {len(key_id)} caratteri, "
          f"applicationKey {len(app_key)} caratteri")
    print("  (per confronto: la chiave B2 che funzionava qui era di 31)")

    # Un indizio, non un cancello.
    #
    # Su B2 l'`applicationKey` comincia di solito con `K` seguito dalle stesse
    # cifre iniziali del suo `keyID` (il numero del cluster). Non è
    # documentato, quindi **non** ci si può rifiutare di procedere: una
    # versione precedente di questo script lo trattava come regola e ha
    # bloccato una chiave valida al primo uso vero.
    #
    # Come avviso invece serve, perché prende l'errore più probabile quando si
    # gestiscono due chiavi: prendere il `keyID` dalla riga di una e
    # l'`applicationKey` salvata per l'altra. Dà un `401 bad_auth_token`, che
    # da solo non dice quale metà è sbagliata.
    if app_key[:1] == "K" and key_id[:3].isdigit() and not app_key[1:4] == key_id[:3]:
        print(f"\n  avviso: il keyID comincia con {key_id[:4]!r} e l'applicationKey "
              f"con {app_key[:4]!r}.")
        print("  Di solito le cifre coincidono: potrebbero essere di due chiavi")
        print("  diverse. Provo lo stesso — decide la verifica in fondo.\n")

    copia = CONFIG + ".prima-della-chiave-nuova"
    shutil.copy2(CONFIG, copia)

    conf[nome]["account"] = key_id
    conf[nome]["key"] = app_key
    with open(CONFIG, "w", encoding="utf-8") as f:
        conf.write(f)
    os.chmod(CONFIG, 0o600)

    print(f"\nscritto. copia di sicurezza: {copia}")
    print("verifica — i secchi che questa chiave vede:\n")
    esito = subprocess.run(["rclone", "lsd", f"{nome}:"], check=False)
    if esito.returncode != 0:
        # Si torna indietro da soli, non si lascia il comando all'utente.
        #
        # Qui dentro c'è il remote che fa il salvataggio notturno: lasciare la
        # configurazione con una chiave che non funziona, e affidarsi a
        # qualcuno che copi la riga giusta alle undici di sera, vuol dire
        # scegliere che alle 02:35 il backup fallisca.
        shutil.copy2(copia, CONFIG)
        os.chmod(CONFIG, 0o600)
        print(f"\nNon ci riesce con il keyID {key_id!r} "
              f"({len(key_id)} caratteri) e un'applicationKey di "
              f"{len(app_key)} caratteri.", file=sys.stderr)
        print("Il keyID non è un segreto — si legge nella console — quindi "
              "confrontalo con la riga della chiave che volevi usare.",
              file=sys.stderr)
        print("\n  401 bad_auth_token  -> le due metà non stanno insieme: "
              "keyID e applicationKey di chiavi diverse, o un incollaggio monco",
              file=sys.stderr)
        print("  401 unauthorized    -> quella chiave non esiste più "
              "(cancellata o scaduta)", file=sys.stderr)
        print("\nHo rimesso la configurazione com'era: il remote che c'era "
              "prima continua a funzionare.", file=sys.stderr)
        return 1

    print("\nSe sopra c'è un solo secchio ed è quello giusto, è a posto.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
