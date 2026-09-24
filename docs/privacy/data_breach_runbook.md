# Che cosa fare quando è successo qualcosa ai dati

Procedura operativa per le violazioni di dati personali (artt. 33-34 GDPR).

*Ultima revisione: 25 settembre 2026 (le modifiche sono in fondo, «Revisioni»).*

**La cosa da ricordare, se si ricorda una sola cosa.** Le settantadue ore
dell'art. 33 non decorrono dal guasto: decorrono da quando **il titolare ne
viene a conoscenza**. Il primo compito non è riparare — è fermare l'ora e
scriverla. Tutto il resto viene dopo, e può venire dopo.

> **Riscritto il 22 settembre 2026.** La versione precedente apriva con una
> «Fase 1 — Rilevamento» che cominciava con *«isolare il vettore»*: dava per
> scontato che la notizia fosse già arrivata, e non diceva mai da dove potesse
> arrivare. Non prevedeva inoltre il caso in cui il dato trapelato abbia per
> titolare **un'altra persona giuridica** — cioè il rischio R20, il più
> probabile di tutti qui. I comandi rapidi in coda non funzionavano: due
> cartelle che non esistono, e una variabile di shell che non è mai stata
> impostata.

---

## Fase 0 — Da dove arriva la notizia

Si viene a sapere di una violazione per una di queste sette strade. Nessun'altra
esiste: se una notizia arriva per una via che non è in questo elenco, l'elenco
va aggiornato.

| # | Strada | Come arriva | Chi la può far partire |
|---|---|---|---|
| 1 | **Diagnostica degli invarianti** | l'unità fallisce → `OnFailure` → `avvisa_guasto.php` → posta all'amministratore | automatica, due volte al giorno |
| 2 | **Controllo d'integrità dei file (AIDE)** | scrive `aide_differenze` nel registro delle anomalie; la diagnostica del mattino lo segnala | automatica, di notte |
| 3 | **Ingressi nei container (auditd)** | `audit_container_ingressi` nel registro delle anomalie | automatica, di notte |
| 4 | **Un docente o un genitore che se ne accorge** | modulo `/segnalazione-contenuti`, categoria `gdpr_art9` → posta ad `abuse@` | chiunque, senza account |
| 5 | **Chi vuole scrivere al titolare** | modulo `/dpo-contact`, oggetto «Segnalazione data breach» → posta al recapito dedicato | chiunque, senza account |
| 6 | **Chi trova una vulnerabilità** | `/.well-known/security.txt` → `security@` | ricercatori, terzi |
| 7 | **Una segnalazione che nessuno ha valutato** | l'invariante `segnalazioni` della diagnostica fallisce → `OnFailure` → posta | automatica, due volte al giorno |

**Le prime tre non distinguono una violazione da un guasto.** Dicono che
qualcosa è cambiato, e spetta a una persona guardare. È il loro limite, ed è
strutturale: un controllo che sapesse distinguerle da solo non esisterebbe.

**La settima strada è la rete delle altre due.** Una segnalazione arrivata dai
moduli pubblici e rimasta ferma più di sei ore senza un incidente aperto fa
fallire la diagnostica, quindi manda l'avviso per la stessa strada di tutti gli
altri guasti. Il timer gira alle 07:00 e alle 19:00 e conta le ore intere: una
segnalazione arrivata poco dopo le 13:00 non ha ancora sei ore al giro delle
19:00, e l'avviso parte al giro delle 07:00 del giorno dopo. Nel caso peggiore
arriva quindi dopo circa diciotto ore, e ne restano almeno cinquantaquattro
delle settantadue. Si spegne in un modo solo
— aprendo l'incidente dal pannello, oppure chiudendo la segnalazione con una
nota, cioè dichiarando che non era una violazione. Non esiste una terza via, ed
è voluto: la terza via sarebbe «me ne occupo dopo».

**Le strade 4 e 5 sono le uniche che possono rilevare il rischio R20** — un
docente che scrive in un campo a testo libero un dato che riguarda uno
studente. Nessun controllo automatico lo vede né lo può vedere: per distinguere
un nome da una parola qualsiasi bisognerebbe capire il senso della frase. Le
difese sono a monte (divieto nei Termini, avviso al momento della scrittura,
svuotamento dei campi nominati su una persona), e a valle c'è solo una persona
che se ne accorge.

### Che cosa fare nei primi dieci minuti

1. **Scrivere l'ora.** Non a mente: aprire `/admin/data-breach`, creare
   l'incidente e mettere `detected_at` all'istante in cui hai saputo. Quel
   campo non si può più correggere (trigger della migrazione 136), ed è
   voluto: è l'unica data che, spostata, falsificherebbe il rispetto del
   termine.
2. **Non cancellare niente.** Nemmeno ciò che sembra spazzatura: è la prova.
3. **Non rispondere ancora a nessuno.** Una prima risposta sbagliata è
   difficile da ritirare, e non c'è nessuna fretta nelle prime ore.

---

## Fase 1 — Di chi sono i dati (T+30 min)

**Questa domanda viene prima di tutte le altre**, perché decide chi deve
notificare che cosa a chi. È la fase che nella versione precedente mancava.

### Caso A — i dati sono nostri

Account dei docenti, registri tecnici, contenuti didattici. Titolare è
l'operatore della piattaforma. Si prosegue dalla Fase 2.

### Caso B — un docente ha scritto il dato di uno studente (il caso R20)

Un docente ha scritto in un campo a testo libero qualcosa che riguarda uno
studente: un nome, una difficoltà, una condizione. I Termini lo vietano (§2.4)
e la piattaforma non avrebbe dovuto riceverlo. Ma il dato sta sui nostri
sistemi, e **della sua conservazione qui risponde il titolare della
piattaforma**, come per ogni contenuto (DPIA §3.2; procedura di rimozione
§6.3). L'Istituto del docente non ha ruoli in questo trattamento: non si
avvisa, e non gli si chiede di fare qualcosa. Quindi:

1. **Rimuovere il contenuto**, con le regole di
   `docs/legal/takedown_procedure.md` (§6.3).
2. **Registrarlo**: `/admin/data-breach`, e da lì la valutazione come per
   ogni incidente, dalla Fase 2: che cosa è stato esposto, a chi, per quanto
   tempo; e che nelle copie di sicurezza il dato resta fino alla loro
   scadenza.
3. **Dirlo al docente** che l'ha scritto: che cosa è stato rimosso e perché
   (Termini §2.4). È la comunicazione della Fase 4 della procedura di
   rimozione.
4. **Se la valutazione conclude per un rischio elevato** per lo studente, la
   comunicazione all'interessato (art. 34) è del titolare. La piattaforma non
   ha i contatti degli studenti né il diritto di averli: come raggiungerlo si
   decide sul caso, e la scelta si scrive nel registro dell'incidente.

Il campo «Titolare dei dati, se non siamo noi» del registro serve a un'istanza
dello Scenario 3, dove la piattaforma è adottata da un Istituto che ne è
titolare e vale il suo accordo (DPA). Su pantedu.eu resta vuoto.

### Caso C — non si capisce

Si procede come nel caso B: si rimuove, si registra e si valuta. Nel dubbio
il caso si tratta come una violazione, e la valutazione dice perché: trattare
da violazione ciò che poi non lo era è un errore piccolo; il contrario no.

---

## Fase 2 — Che cosa è uscito (T+1h)

I livelli sono quelli del campo «Severity» del pannello.

| Livello (nel pannello) | Dati | Che cosa comporta |
|---|---|---|
| Basso (`low`) | registri tecnici aggregati, senza identificativi | solo annotazione nel registro degli incidenti |
| Medio (`medium`) | indirizzi di posta, nomi utente, dati di connessione | Garante entro 72 h |
| Alto (`high`) | impronte delle password, chiavi, contenuti dei docenti | Garante entro 72 h **+** interessati senza ritardo **+** rotazione delle credenziali |
| Alto (`high`) | **dati che riguardano studenti** | come sopra, **e** Fase 1 caso B: il titolare è la scuola |

Il pannello ha un quarto livello, `critical`: si usa per la copia dell'intero
database, per la chiave master letta da terzi o per il server compromesso
(`docs/security/operations/incident-response.md`, §2).

Nel dubbio si sale di livello: un livello più alto del necessario si spiega
nell'analisi della Fase 5, un termine mancato non si recupera. Il livello si
sceglie quando si apre l'incidente e dal pannello **non si cambia più**; un
incidente aperto da una segnalazione nasce `high`.

---

## Fase 3 — Contenimento (T+4h)

- Sospendere gli account coinvolti.
- Se la chiave master può essere stata letta: cambiarla con la procedura di
  `docs/security/operations/kms-recovery.md` («Cambiare la chiave master» ed
  «Eventuale»), che dice anche che cosa il cambio non copre.
- Cambiare gli altri segreti che possono essere usciti: `STORAGE_SIGNING_SECRET`;
  `WAF_HMAC_SECRET`, da cui derivano anche le chiavi delle impronte (dopo il
  cambio le impronte nuove non si confrontano con le vecchie); le credenziali
  del database (applicazione, migrazioni, manutenzione); la chiave di Resend;
  il segreto del client Google; le chiavi di Backblaze; il segreto del
  servizio TeX.
- Revoca delle sessioni attive (sono a file: si svuota la cartella delle
  sessioni nei dati d'istanza).
- Reimpostazione forzata della password per gli account coinvolti.
- Blocco degli indirizzi sospetti dal pannello del filtro di sicurezza.

---

## Fase 4 — Notifiche (entro T+72h)

| A chi | Quando | Con che cosa |
|---|---|---|
| **Garante** | entro 72 h dalla conoscenza | modulo su `garanteprivacy.it`; poi l'azione «Marca notificato al Garante» nel registro |
| **Interessati** | senza ritardo, se il rischio è elevato | `docs/privacy/breach_notification_template.md`, già scritto e da compilare; poi l'azione «Marca notificato agli utenti», metodo «Email diretta» |
| **Titolare esterno** (caso B) | senza ingiustificato ritardo | non c'è un modello: si scrive, e si registra l'avviso |

Se le 72 ore scadono senza aver completato le verifiche, **si notifica lo
stesso**: l'art. 33 §4 ammette la notifica per fasi. Il ritardo va motivato; il
silenzio no.

> **La comunicazione agli interessati si fa per posta.** Non c'è un avviso in
> pagina da usare qui, e non si può improvvisarlo con le 72 ore che corrono.
>
> L'unico banner del progetto serve gli aggiornamenti di Termini e AUP, dove è
> un impegno contrattuale, e mostra solo quelli: non sa rivolgersi a un
> sottoinsieme di utenti, che è invece esattamente quello che una
> comunicazione dell'art. 34 richiede.
>
> *Il modello di comunicazione prometteva quell'avviso e aveva perfino una
> casella da spuntare. Tolti il 22 settembre 2026: una lista di controllo che
> non si può completare onestamente è una lista che si smette di aprire.*

---

## Fase 5 — Dopo (T+7 giorni)

- Analisi della causa, scritta.
- Correzioni tecniche, con la loro prova.
- Revisione della valutazione d'impatto.
- **Chiudere l'incidente nel registro.** Le righe non si cancellano mai: la
  conservazione è permanente, ed è la dimostrazione richiesta dall'art. 5 §2.

Va documentata anche la valutazione che si conclude con «non era una
violazione»: lo chiede l'art. 33 §5, che parla di documentare le valutazioni e
non solo le violazioni.

---

## Comandi che funzionano

I comandi della versione precedente non funzionavano: scrivevano in
`storage/backups/db` e `storage/backups/files`, che non esistono, e usavano
`$DB_NAME` come se fosse una variabile di shell — è una chiave del file
d'ambiente, che la shell non legge.

```bash
# Una copia cifrata adesso, con la procedura vera
sudo systemctl start pantedu-backup-encrypted.service

# Che cosa c'è di recente (la stessa cartella che controlla la diagnostica)
ls -lt /var/backups/pantedu/pantedu-backup-*.tar.gpg | head -3

# Le anomalie non ancora guardate
sudo -u pantedu env PANTEDU_DATA_PATH=<cartella dei dati> php tools/ops/visto.php

# Lo stato degli invarianti, adesso
sudo systemctl start pantedu-diagnostica.service; journalctl -u pantedu-diagnostica -n 30 --no-pager

# La prova del piano, fuori cadenza
sudo systemctl start pantedu-breach-drill.service
```

Per estrarre i registri non si usa `mysql -e`: le credenziali non stanno nella
shell, e su alcune configurazioni l'uscita deforma i caratteri non ASCII. Si
passa da PHP, che legge il file d'ambiente da sé.

---

## Che cosa questo piano NON ha

Un piano onesto dice anche dove non arriva.

- **Nessun rilevamento automatico del rischio R20.** Non è una mancanza da
  colmare: non è colmabile. Le strade 4 e 5 della Fase 0 sono l'unica via.
- **Nessun recapito degli studenti.** Se occorre comunicare con uno studente
  (caso B, punto 4), la piattaforma non ha il suo indirizzo: il modo si decide
  sul caso.
- **Nessun invio di massa agli interessati.** Vedi la nota della Fase 4.
- ~~Nessun collegamento fra una segnalazione arrivata dai moduli pubblici e il
  registro degli incidenti~~ — **chiuso il 22 settembre 2026.** L'incidente si
  apre ancora a mano, e resta così di proposito: quei moduli sono pubblici e
  senza autenticazione, e una riga del registro non si cancella mai, quindi
  farli scrivere direttamente vorrebbe dire lasciare che chiunque lo riempia
  per sempre. Adesso però **il sistema si accorge se nessuno lo apre**: vedi la
  strada 7 della Fase 0.

## Dove stanno le cose

| Che cosa | Dove |
|---|---|
| Registro degli incidenti | `/admin/data-breach` — e in cima alla pagina i primi passi, in chiaro |
| Questo piano, da scaricare | `/admin/data-breach/piano` |
| Segnalazioni arrivate dai moduli | `/admin/data-requests`, `/admin/takedown` |
| Registro delle anomalie | `tools/ops/visto.php`, `tools/ops/diagnostica.php` |
| Prova periodica del piano | `tools/gdpr/breach_drill.php`, unità `pantedu-breach-drill.timer` |
| Modello di comunicazione (art. 34) | `docs/privacy/breach_notification_template.md` |
| Procedura di rimozione contenuti | `docs/legal/takedown_procedure.md` |
| Valutazione d'impatto, rischio R20 | `docs/privacy/dpia.md` |

## Revisioni

| Data | Modifica |
|---|---|
| 22 settembre 2026 | Riscritto: la Fase 0 dice da dove arriva la notizia, la Fase 1 di chi sono i dati; comandi che funzionano (nota in testa). |
| 25 settembre 2026 | Fase 0: le strade sono sette, non sei; l'avviso di una segnalazione ferma arriva nel caso peggiore dopo circa diciotto ore, non dodici. Fase 2: i livelli sono quelli del pannello, e il livello scelto all'apertura non si cambia dal pannello (qui si leggeva che la classificazione si poteva correggere). Fase 3: i segreti veri; `APP_KEY` non esiste, e mancavano la chiave master e gli altri. Fase 4: il metodo da registrare per la comunicazione agli interessati. |
| 25 settembre 2026, sera | Fase 1, caso B: il dato che un docente scrive su uno studente sta sui nostri sistemi, e della sua conservazione risponde il titolare della piattaforma; l'Istituto del docente non si avvisa, come nella DPIA §3.2 e nella procedura di rimozione §6.3. Il caso B diceva ancora che titolare era l'Istituto e che andava avvisato. Caso C e «Che cosa questo piano NON ha» di conseguenza. |
