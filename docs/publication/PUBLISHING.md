# Pubblicazione del clone pulito (Developers Italia / open source)

> Procedura per produrre una copia di pantedu **priva di dati personali e
> scolastici reali** da pubblicare su un repository pubblico (es. Developers
> Italia). Il repository di **sviluppo** continua a usare l'istituto reale come
> seed/test: non si modifica a mano, si sanifica un **clone**.

## Principio

Non si editano i 100+ file che contengono riferimenti reali. Si esegue un
programma di sanitizzazione su un **clone separato** destinato alla
pubblicazione. Lo strumento:

- **sostituisce** i token PII con segnaposto neutri (nome istituto, città,
  provincia, codici meccanografici MIUR, indirizzo, telefono/fax, C.F.,
  email e domini reali, IP del server, nome del Dirigente e del consulente DPO,
  username dell'account super-admin reale);
- **cancella** i file/cartelle interni non destinati al pubblico (handoff di
  sessione con credenziali, note di lavoro `docs/todo/`, analisi legacy
  `docs/analysis/`, pentest grezzi `docs/security/pentest/`, pacchetto DPO
  compilato con dati reali, doc infrastrutturali con IP/host, lo strumento
  stesso);
- **ri-scansiona** e segnala qualsiasi residuo (incl. binari come PDF/immagini),
  uscendo con codice ≠ 0 se restano residui dopo `--apply`.

Cosa **resta** (attribuzioni legittime): l'autore/copyright «Vittorio Pantaleo»
in LICENSE/NOTICE/publiccode/composer/CONTRIBUTING e l'URL del repo
`github.com/.../pantedu`. Le email sul dominio del deployment NON restano: il contatto
maintainer diventa `vittop89@users.noreply.github.com` (GitHub noreply), le
email funzionali (info/security/dpo/abuse…) diventano `{{OPERATORE_EMAIL}}`
nei doc template e `operatore@example.net` come default nel codice; il canale
security primario è **GitHub Security Advisories**. Il nome come *operatore*
nei doc legali diventa `{{OPERATORE_NOME}}` (l'adottante compila il proprio).

## Modo rapido (consigliato): un comando con semaforo di sicurezza

`tools/publish/release.sh` incapsula TUTTA la procedura manuale sotto, con un
**gate**: se resta anche una sola PII, si ferma e NON pubblica. Gestisce anche
il gotcha Windows (la cartella `tools/publish/` non si auto-cancella se lo
strumento è "in uso" → contiene i pattern PII reali e va rimossa a mano).

```bash
# Dry-run: clona, sanifica, VERIFICA i residui, ricrea la storia git,
#          ma NON pubblica. Usalo sempre prima per leggere l'esito.
bash tools/publish/release.sh

# Pubblica davvero (push --force sul repo pubblico) solo dopo un dry-run pulito:
bash tools/publish/release.sh --publish
```

Cosa fa in sequenza: clona in una cartella temporanea → `sanitize --apply`
(exit ≠ 0 se restano residui → abort) → rimuove e verifica `tools/publish/` →
**ri-verifica** l'intero albero riusando i pattern del sanitizer → re-inizializza
`.git` (single commit, niente PII nella history) → tagga il commit
`v<softwareVersion>` letta da `publiccode.yml` → push forzato di `main` e del
tag solo con `--publish`. Il tag segue lo snapshot: ripubblicare la stessa
versione lo sposta, non lascia in vita il commit precedente. Prima di
pubblicare una versione nuova si alza `softwareVersion` (e `releaseDate`) in
`publiccode.yml`, `package.json` e si scrive la voce nel `CHANGELOG.md`.
Env: `PANTEDU_PUBLIC_URL` (URL repo pubblico), `PHP` (binario php).

I passi manuali sotto restano come riferimento / fallback.

## Procedura (manuale, di riferimento)

```bash
# 1. Clona il repo di sviluppo in una cartella SEPARATA per la pubblicazione
git clone . /tmp/pantedu-public
cd /tmp/pantedu-public

# 2. ANTEPRIMA (default: non scrive nulla). Leggi il report:
#    - sostituzioni per regola
#    - file modificati / cancellati
#    - SCAN RESIDUI deve dire "Nessun residuo rilevato"
php tools/publish/sanitize-for-publication.php

# 3. APPLICA
php tools/publish/sanitize-for-publication.php --apply

# 4. Verifica manuale finale (rete di sicurezza). Lo SCAN RESIDUI dello
#    strumento (passi 2-3) ha già verificato; per un controllo extra cerca
#    i TUOI dati reali (nome istituto, città, codice meccanografico, cognomi
#    di Dirigente/DPO, email personali). NON elencare qui i token reali:
#    finirebbero pubblicati in questo file. Esempio con segnaposto:
git grep -inE "<nome-istituto>|<citta>|<cod-mecc>|<cognome-ds>|<email-personale>" || echo "PULITO"

# 5. Ricrea il super-admin parametrico (vedi docs/SUPERADMIN.md) — il clone
#    pubblico NON deve contenere credenziali reali.

# 6. Re-inizializza la storia git (il clone eredita la history, che può
#    contenere PII nei commit passati):
rm -rf .git
git init && git add -A
git commit -m "Initial public release (sanitized)"

# 7. Push verso il repository pubblico
git remote add origin <URL-repo-pubblico>
git push -u origin main
```

> **Importante (storia git):** la sanitizzazione opera sull'albero dei file, non
> sulla history. Per pubblicare in modo sicuro, ri-inizializza `.git` (passo 6)
> oppure usa `git filter-repo` per riscrivere i commit. Pubblicare la history
> originale ri-esporrebbe i dati reali nei commit passati.

## Opzioni dello strumento

| Opzione | Effetto |
|---|---|
| (nessuna)     | dry-run: mostra il report, non scrive |
| `--apply`     | applica sostituzioni e cancellazioni |
| `--root=PATH` | opera su `PATH` invece della cwd |
| `--no-delete` | solo sostituzioni di token, nessuna cancellazione |

## Sicurezza integrata

- Lo strumento **si rifiuta** di girare con `--apply` se trova il file marcatore
  `.pantedu-dev-repo` nella radice (evita di corrompere il repo di sviluppo).
- I pattern PII reali vivono **solo** dentro lo strumento, che si **auto-cancella**
  nel clone pubblico (così i token reali non vengono pubblicati nemmeno come
  pattern).

## Manutenzione dei pattern

Se aggiungi nuovi dati reali al repo (un nuovo istituto, una nuova persona),
aggiorna le regole in `tools/publish/sanitize-for-publication.php`
(array `$rules`) e il pattern dello `SCAN RESIDUI`. Poi rilancia il dry-run:
il report deve tornare a «Nessun residuo rilevato».
