---
tags:
  - documentazione/adr
date: 2026-09-08
tipo: adr
status: accettato
aliases: ["ADR-048", "rilascio a container", "blu/verde", "deploy-container", "immagine dell'applicazione", "che cosa il rilascio non installa"]
cssclasses: []
---

# ADR-048 — Si va in produzione con un'immagine: container nuovo a fianco, scambio in un istante, l'host tiene il resto

## Stato

**ACCETTATO**, e in servizio dall'8 settembre 2026. È un ADR **retroattivo**:
scritto il 23 settembre 2026, perché la revisione architetturale di quel
giorno ha trovato che il modo in cui si va in produzione non aveva una
decisione scritta — compariva solo come menzione in
[[decisions/ADR-039-dove-stanno-le-sessioni]] (DOC-28). Registra la decisione
com'era e le sue conseguenze misurate fino al 23/9; non ne prende una nuova.

## Contesto

Fino all'8 settembre 2026 il rilascio era `tools/webhook/deploy.sh`: un
`git reset --hard` sulla cartella servita, poi composer, l'istantanea del
database e le migrazioni.

- **Il codice nuovo rispondeva prima delle migrazioni.** Fra il reset e la
  fine delle migrazioni passavano decine di secondi in cui il codice nuovo
  serviva richieste vere su uno schema vecchio, e un errore a metà lasciava
  l'albero aggiornato a metà.
- **La cura scritta non ha retto alla prova.** La mattina dell'8/9 due
  tentativi con una cartella di release e un collegamento simbolico hanno
  dato tre minuti di sito in 404 e dieci minuti di sito in servizio senza
  configurazione.
- **Una macchina sola, un manutentore solo.** Un ambiente di staging costa e
  diverge da quello vero; la domanda che serve — il commit unito funziona? — la
  risponde la suite end-to-end dopo l'unione.

## Decisione

**L'applicazione è un'immagine**: nginx, php-fpm, il codice e gli asset
costruiti, un'immagine sola, costruita da `immagine.yml` e pubblicata nel
registro. Il rilascio (`tools/webhook/deploy-container.sh`, lanciato da
`pantedu-deploy.path` sul segnale del webhook) la porta in servizio così:

1. aspetta l'immagine del commit; se non arriva, la costruisce sul VPS e lo
   dice;
2. istantanea del database, migrazioni;
3. avvia il container nuovo sulla porta libera fra le due (8090 e 8091),
   **senza traffico**, e aspetta che si dichiari sano;
4. scambia una riga nell'upstream di nginx, ricarica, verifica **attraverso
   nginx**, e solo allora ferma il vecchio.

Fino allo scambio la produzione non si accorge di niente, e ogni fallimento
prima dello scambio lascia in servizio il container di prima. Anche subito
dopo lo scambio si torna indietro da soli: se nginx non accetta la
configurazione nuova, se il `reload` fallisce o se la verifica attraverso
nginx non passa (una pagina che non risponde 2xx o 3xx, `/health` senza il
database), il rilascio rimette l'upstream com'era, ricarica nginx e ferma il
container nuovo (`rimetti_upstream`, passi 7 e 8 di `deploy-container.sh`).
È la proprietà per cui questo assetto esiste. Misurato l'8/9: 43 secondi da
capo a fondo.

**Sull'host resta quello che non deve cambiare con l'applicazione, o che deve
funzionare quando l'applicazione è rotta:**

| che cosa | perché resta fuori |
|---|---|
| il webhook e il sorgente (`git reset --hard` sull'host) | se il container è rotto, da lì si rilascia la correzione; il sorgente lo usano migrazioni, lavori a orario e avvisi di guasto |
| MariaDB, raggiunta dal socket montato | non cambia con il codice, e metterla dentro vorrebbe dire migrare dati veri; il socket non espone niente sulla rete |
| i dati d'istanza, montati | l'immagine è immutabile: tutto ciò che si scrive sta sotto `PANTEDU_DATA_PATH` |
| il servizio TeX, raggiunto sul gateway del bridge | cambia di rado (8 volte in sei mesi contro 863 dell'applicazione) e ha il suo utente e i suoi limiti |
| TLS e i limiti di frequenza di nginx | le zone `limit_req` stanno in memoria condivisa: dentro il container, ogni scambio azzererebbe i contatori |

Superata la verifica attraverso nginx, lo scambio non si annulla più: il
passo 9 ferma il container vecchio, e da lì tornare indietro è una decisione
di una persona. Le migrazioni non tornano indietro in nessun caso, nemmeno
quando lo scambio si annulla: l'istantanea del database fatta prima di loro
serve a chi decide di ripristinare. Quello che è automatico, dopo, è l'avviso
(`OnFailure=` sulle unità, la diagnostica).

## Conseguenze

- **L'immagine è la versione.** `storage/version.txt` lo scrive il build, e
  `/version` dice il commit che serve. Quello che si scrive dentro il
  container, fuori dai dati, sparisce al rilascio dopo: la regola e la guardia
  sono in [ci-cd](../../docs/dev/ci-cd.md#che-cosa-si-scrive-e-dove).
- **`.dockerignore` decide che cosa entra.** Un file letto a runtime fuori da
  `app/`, `views/`, `public/` va riammesso a mano ([[dev-workflow]], punto 7);
  gli strumenti d'accesso al server restano fuori, come nella copia pubblica
  (dal 23/9, A-85).
- **Le sessioni stanno nei dati**, non nella `/tmp` del container, che ogni
  scambio butta via: [[decisions/ADR-039-dove-stanno-le-sessioni]].
- **Il container non vede la loopback dell'host.** Ha colpito due volte: il TeX
  irraggiungibile dall'8 al 19 settembre (`docs/ops/tex-dal-container.md`), e
  l'URL predefinito della LAPI di CrowdSec, che nel container era il nginx
  dell'applicazione (A-15, corretto il 23/9: nessun predefinito, e la
  diagnostica riconosce la LAPI). Ogni servizio dell'host va raggiunto da un
  indirizzo che il container vede.
- **La suite end-to-end prova l'immagine del rilascio** dal 14/9, non `php -S`
  ([ci-cd](../../docs/dev/ci-cd.md#la-suite-contro-limmagine-del-rilascio)).
- **Una modifica a `deploy-container.sh` vale dal rilascio dopo**: il
  rilascio gira dalla copia installata, e la sostituisce mentre è in corso.

### Che cosa il rilascio non installa

Il passaggio ha perso, senza che niente lo dicesse, tre passi di `deploy.sh`
(A-14 della revisione del 23/9). Lo stato al 23/9/2026:

| passo di `deploy.sh` | nel rilascio a container |
|---|---|
| `composer install` del `vendor/` dell'host | **tornato** il 23/9 (passo 2-bis), solo se cambiano `composer.*` |
| `sync_versions.php --apply` delle versioni legali | **tornato** il 23/9 (passo 8-ter) |
| le unità di `tools/systemd` e gli script dell'infrastruttura del rilascio | **a mano**. Dal 23/9 lo scarto si vede: il controllo `unita` della diagnostica dice, due volte al giorno, quali unità sono diverse, non installate, spente o orfane, e manda la mail |

Restano a mano anche: il vhost di nginx dell'assetto a container
(`deploy.sh` sincronizzava quello dell'assetto di prima; questo non l'ha mai
sincronizzato nessun rilascio, `docs/ops/vps-info.md`); del servizio TeX
l'unità, i pacchetti Debian e il suo `.env` (il codice lo porta il rilascio
dal 19/9); le regole di AIDE e di auditd. I comandi stanno nelle guide di
ciascuno ([ci-cd](../../docs/dev/ci-cd.md#che-cosa-il-rilascio-non-installa),
[diagnostica](../../docs/ops/diagnostica.md)).

## Rimandi

- [ci-cd](../../docs/dev/ci-cd.md): dal commit alla produzione, che cosa resta
  fuori dal container, il `vendor/` dell'host e le versioni legali, che cosa il
  rilascio non installa. È la guida operativa; questo ADR ne dice il perché.
- [[decisions/ADR-039-dove-stanno-le-sessioni]]: le sessioni e lo scambio
  blu/verde.
- `tools/webhook/deploy-container.sh`: le tappe e che cosa succede se una
  fallisce, in testa al file.
- Il racconto del giorno, con gli otto difetti trovati provandolo: changelog
  della wiki, 2026-09-08.
