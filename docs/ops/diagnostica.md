# Diagnostica: rendere rumorosi i guasti silenziosi

*9 settembre 2026*

## Il problema che risolve

L'8 e il 9 settembre 2026 sono venuti fuori, uno dopo l'altro, sei guasti che
sembravano scollegati:

| Cosa non funzionava | Come appariva |
|---|---|
| Il controllo semgrep non scansionava niente da mesi | Un segno di spunta verde |
| Quattro funzioni rispondevano 403 (gettone CSRF vuoto) | Un rifiuto di sicurezza riuscito |
| Il blocco geografico era spento (file GeoIP inesistenti) | Nessun blocco geografico, come quando non c'è niente da bloccare |
| `/version` dichiarava un commit e ne serviva un altro | Una risposta 200 con un valore plausibile |
| Il `trap` del rilascio leggeva il codice d'uscita di `date` | «FINE, tutto bene» |
| `git reset --hard` toglieva a `www-data` i permessi su `.env` | «Database disabilitato da configurazione» |

Hanno tutti la stessa forma: **il fallimento assomiglia al normale**. Non c'è
una schermata di errore da leggere, non c'è un'eccezione nei registri, non c'è
un allarme da tacitare. C'è un sistema che sembra funzionare.

Contro questa categoria di guasti non serve «più registri»: ne avevamo già
quattro. Serve chiedere a ogni pezzo di **dimostrare** di essere nello stato
che dichiara, e serve un posto dove finisce chi non ci riesce.

## I tre pezzi

### 1. Il registro delle anomalie

`app/Support/Anomalia.php` → `{logs}/anomalie.jsonl`

Non è un altro registro applicativo. Raccoglie una categoria sola: **le
incoerenze interne**, cioè quando un pezzo del sistema si accorge che un altro
pezzo si comporta in un modo che, se il codice fosse giusto, non potrebbe
verificarsi.

L'esempio che l'ha fatto nascere è `csrf_gettone_vuoto`. Un 403 per gettone
mancante è indistinguibile da un blocco riuscito — ed è esattamente per questo
che quattro funzioni sono state rotte per mesi senza che nessuno lo sapesse.
Ma un gettone **vuoto** non lo manda nessun attaccante: chi attacca manda
qualcosa di plausibile. Una stringa vuota è sempre codice nostro che non trova
il gettone. Dirlo con quel nome trasforma un rumore di fondo in un'indicazione.

Due regole di condotta, scritte nel file:

- **Non può far fallire chi la chiama.** Se scrivere non riesce, si rinuncia,
  e lo si dice in `error_log` (nel container: `docker logs`). Un registro che
  rompe la richiesta che stava osservando è peggio del problema che segnala; ma
  uno che perde righe senza dirlo è il guasto che esiste per combattere. Fino
  al 19 settembre 2026 qui c'era «in silenzio», e dal container non arrivava
  niente: vedi [chi scrive il registro](#chi-scrive-il-registro).
- **Non allaga.** Lo stesso codice si scrive al massimo una volta ogni cinque
  minuti per chiave, e le occorrenze saltate finiscono contate nella riga
  successiva (`saltate`). Contare invece di buttare è la differenza fra
  limitare il rumore e nascondere il segnale.

Per leggerlo:

```bash
tail -20 /var/lib/pantedu-data/storage/logs/anomalie.jsonl | jq .
```

#### Chi scrive il registro

Quattro scrittori, con utenti diversi: l'applicazione nel container
(`www-data`), la diagnostica e i lavori a orario sull'host (`pantedu`), gli
strumenti notturni (`aide-check.sh`, `versioni-indietro.sh`, come root).
`www-data` e `pantedu` si incontrano nel gruppo `www-data`, che è il gruppo
della cartella dei registri (`pantedu:www-data` 0770, senza setgid, misurato il
19/9/2026). Quindi **`anomalie.jsonl` e `anomalie.jsonl.stato` devono essere
del gruppo `www-data` e 0660**.

Il 19 settembre 2026 non lo erano: `anomalie.jsonl` era 0640, e il PHP del
container lo leggeva ma non ci scriveva; `anomalie.jsonl.stato` era
`www-data:www-data` 0644, e l'host non ne aggiornava più il conto. In più
`registra()` aggiornava lo stato **prima** di scrivere la riga: dal container
lo stato avanzava e la riga si perdeva, in silenzio. Nel registro c'erano solo
righe degli strumenti dell'host; nessuna, mai, dell'applicazione.

Adesso:

- `Anomalia::registra()` scrive prima la riga e dice «scritta» nello stato solo
  se ci è riuscita; se no, `error_log` — **una riga ogni cinque minuti per
  codice**, non una per occorrenza, e le occorrenze rimaste senza riga restano
  contate: se le porterà dietro (`saltate`) la prima riga che riesce a
  scriversi. Senza quel limite il rimedio contro il rumore diventava la
  sorgente del rumore proprio quando il registro è rotto (misurato il 20/9/2026:
  cinquanta occorrenze, cinquanta righe in `error_log`). Prova:
  `tests/Unit/Support/AnomaliaScritturaTest.php`;
- i due file, quando li crea l'applicazione, nascono 0660 e con il gruppo della
  cartella; gli strumenti di root fanno `chown pantedu:www-data` e `chmod 0660`
  — che sia vero per tutti lo controlla `tools/ci/check-deploy-units.mjs`, dopo
  che il 20/9/2026 si è trovato `versioni-indietro.sh` che faceva solo il primo;
  `pantedu-diagnostica.service` e `pantedu-versioni.service` hanno `UMask=0007`;
- il controllo `registro` della diagnostica dice se il registro è scrivibile
  **da chi sta girando**: dopo il rilascio come `www-data` nel container, dal
  timer come `pantedu` sull'host.

Una volta sola, sul server, dopo l'unione della PR del 19/9/2026: i due file
che esistono già non li tocca nessuno di questi rimedi, e si sistemano a mano,
un comando per volta. Il cambio di permessi può produrre una segnalazione di
AIDE, da segnare come vista con il motivo.

```bash
sudo chgrp www-data /var/lib/pantedu-data/storage/logs/anomalie.jsonl /var/lib/pantedu-data/storage/logs/anomalie.jsonl.stato
```

```bash
sudo chmod 660 /var/lib/pantedu-data/storage/logs/anomalie.jsonl /var/lib/pantedu-data/storage/logs/anomalie.jsonl.stato
```

### 2. La diagnostica

`tools/ops/diagnostica.php`

**Diciassette** invarianti, ognuno con la sua prova. *(Il 22/9/2026 questa riga diceva «dodici» e la tabella ne elencava tredici: `segnalazioni`, aggiunto lo stesso giorno, non era mai stato scritto qui. Corretto insieme a `bozze`. Il 23/9/2026 si sono aggiunti `unita` e `crowdsec`.)* Non «geoip ok», ma «geoip: il file
configurato esiste, 6.4 MB, aggiornato 12 giorni fa». Una diagnosi senza prova
è la stessa cosa di un verde che non misura niente.

| Controllo | Cosa dimostra | Da quale guasto nasce |
|---|---|---|
| `versione` | Il commit dichiarato è quello servito | `/version` che mentiva |
| `geoip` | I file configurati esistono, sono leggibili e non sono scaduti | Blocco geografico spento in silenzio |
| `permessi` | `.env` è leggibile, `storage` e `logs` sono scrivibili | `git reset --hard` che toglieva i permessi |
| `database` | Risponde, e le migrazioni sul disco sono tutte applicate | Rilascio a metà |
| `pubblicazioni` | Ogni principale di contenuto ha lo stato della sua riga, nessuna pubblicazione viene dai bersagli, ognuna sta nella scuola delle sue voci (`pub_verifica_allineamento`); nessun trigger delle etichette è rimasto senza la sua procedura | Studenti che vedono un contenuto in un posto diverso da quello scelto, senza nessun errore (ADR-037) |
| `contratti` | Nessun contratto porta pezzi della pagina resa al posto della sorgente | Le figure TikZ perdute della verifica 75, il 18 settembre 2026 |
| `sezioni` | Ogni contenuto è raggiungibile da almeno una delle domande che la barra sa fare | Nove contenuti invisibili in ogni pannello da aprile, fra cui la verifica che l'utente cercava il 20 settembre 2026 ([`sezioni`](#sezioni-un-contenuto-che-nessuno-può-chiedere)) |
| `conservazione` | Un Istituto che dichiara «le compilazioni non restano sul server» non ne ha di conservate | L'interruttore vale da quando lo si gira e non tocca il pregresso ([`conservazione`](#conservazione-una-misura-dichiarata-e-non-applicata)) |
| `bozze` | Non restano compilazioni scadute che avrebbero dovuto essere cancellate. Guarda il **risultato**, non se il lavoro notturno è partito: uno che gira e non cancella niente risulterebbe a posto | La conservazione dichiarata nell'informativa (15 giorni dallo scaricamento, ADR-046) smette di essere applicata senza fare rumore: timer non installato, migrazione non applicata, DELETE negato |
| `segnalazioni` | Nessuna segnalazione arrivata dai moduli pubblici resta oltre sei ore senza un incidente aperto o senza una valutazione che la chiuda | L'incidente dell'art. 33 si apre a mano, quindi è un passaggio che si può dimenticare — e quelle sono le sole vie che rilevino R20 |
| `csrf` | Le rotte che scrivono verificano il gettone | Le sette rotte scoperte |
| `crowdsec` | Il bouncer CrowdSec parla con la LAPI: risponde come una LAPI e accetta la chiave. Spento (né URL né chiave) non è un guasto; configurato a metà sì. Sull'host di un'installazione a container, un URL sulla loopback è un guasto qualunque cosa risponda | Il predefinito `127.0.0.1:8080`, che nel container è il nginx dell'applicazione, e il pannello che dava «raggiungibile» ogni codice fra 200 e 499 ([`crowdsec`](#crowdsec-il-bouncer-parla-con-la-lapi)) |
| `tex` | Il servizio TeX risponde a chi lo usa: nel container direttamente; sull'host direttamente (per i lavori a orario) **e**, attraverso nginx, a `/health/tex` dell'applicazione in servizio. Non configurato non è un guasto | Undici giorni senza compilazioni dal sito, 8-19/9/2026 ([tex-dal-container](tex-dal-container.md)) |
| `lavori` | I lavori periodici hanno **prodotto** qualcosa di fresco | Il salvataggio delle chiavi fermo tre settimane |
| `unita` | Le unità di `tools/systemd` installate sull'host sono quelle del repository, e i timer sono accesi. «Non ho potuto confrontare» è un guasto | Dall'8/9/2026 il rilascio a container non installa più le unità, e niente lo diceva ([`unita`](#unita-le-unità-installate-sono-quelle-del-repository)) |
| `registro` | Il registro delle anomalie e il suo stato sono scrivibili da chi sta girando | Le righe del container perse in silenzio ([chi scrive il registro](#chi-scrive-il-registro)) |
| `anomalie` | Non c'è niente nel registro delle ultime 24 ore | Tutti quanti |

#### `unita`: le unità installate sono quelle del repository?

Fino all'8 settembre 2026 le installava il rilascio: `deploy.sh`, al passo 7,
confrontava ogni `.service`, `.timer` e `.path` di `tools/systemd` con quello in
`/etc/systemd/system`, copiava i diversi e accendeva i timer. Il rilascio a
container non lo fa, e fino al 23/9 niente confrontava il server con il
repository: una correzione a un timer, unita e verde, restava dov'era finché
qualcuno non la copiava a mano. Il rilascio continua a non installarle
([ADR-048](../../wiki/decisions/ADR-048-rilascio-a-container.md)); questo
controllo dice che cosa manca.

Gira sull'host, dal timer, come `pantedu`. Nel container e su una macchina di
sviluppo è `non_applicabile`: lì non c'è niente di installato da confrontare.
Per ogni unità, e per ogni drop-in di una cartella `*.service.d`:

| risposta | che cosa vuol dire |
|---|---|
| uguale | stesso contenuto, byte per byte |
| diversa | installata con un altro contenuto: una correzione non è arrivata, o qualcuno l'ha ritoccata sul server |
| non installata | nel repository sì, sul server no |
| spenta | un timer o un path installato senza il collegamento in `<bersaglio>.wants/` che crea `systemctl enable`: non parte mai |
| orfana | un'unità `pantedu-*` installata che il repository non ha più, come il timer di una fonte tolta |
| non ho potuto confrontare | il file o la cartella non si legge, o la cartella non c'è. **È un guasto**, non un «uguale» |

Gli script `*.sh` di `tools/systemd` non si confrontano: vanno in
`/usr/local/bin` e `/usr/local/sbin` con nomi propri, e li reinstalla
`tools/webhook/install_auto_deploy.sh`. Il confronto sta in
`App\Services\Ops\UnitaInstallate`; le prove, nei due versi e con cartelle
finte, in `tests/Unit/Ops/UnitaInstallateTest.php` e
`tests/Unit/Ops/DiagnosticaUnitaTest.php`.

Quando scatta, si guarda ogni riga: un'unità diversa o non installata si
installa (i comandi in
[ci-cd](../dev/ci-cd.md#che-cosa-il-rilascio-non-installa)); una spenta si
accende, o si toglie dal repository se non serve più; un'orfana si spegne e si
toglie dal server (`systemctl disable --now`, poi il file e `daemon-reload`).
Segnarla come vista non serve: il confronto si rifà a ogni giro.

Per provarla a mano su un'altra cartella:
`php tools/ops/diagnostica.php --solo=unita --unita-installate=<cartella>`.

#### `crowdsec`: il bouncer parla con la LAPI?

Il bouncer CrowdSec del WAF chiede alla LAPI, per ogni indirizzo, se c'è una
decisione. Fino al 23/9/2026 il suo URL aveva un valore predefinito,
`http://127.0.0.1:8080`: la porta della LAPI sull'host. Ma il bouncer gira dove
gira l'applicazione, nel container, e lì quell'indirizzo è il nginx
dell'applicazione (`docker/nginx.conf`), che a `GET /v1/decisions` risponde
404 con una pagina HTML. Con la sola chiave impostata il bouncer avrebbe
interrogato il sito e, fail-open, non bloccato niente, e il pannello del WAF lo
avrebbe dato «raggiungibile»: contava come tale ogni codice fra 200 e 499.

In produzione, misurato il 23/9/2026 in sola lettura, né URL né chiave sono
impostati: il bouncer è spento, e il guasto era latente. Adesso l'URL non ha
predefinito, e il controllo risponde così:

| stato | esito |
|---|---|
| né `CROWDSEC_LAPI_URL` né `CROWDSEC_LAPI_KEY` | `non_applicabile`: il livello CrowdSec non c'è, di proposito |
| una sola delle due | guasto: configurato a metà, quindi spento senza che lo sappia chi l'ha configurato |
| risponde la LAPI (200 JSON, `null` o un elenco di decisioni) | regge |
| risponde la LAPI e rifiuta la chiave (403 JSON «access forbidden») | guasto |
| risponde qualcos'altro, o non risponde | guasto, con il codice e il tipo della risposta |
| sull'host di un'installazione a container, URL sulla loopback | guasto qualunque cosa risponda: da lì risponde la LAPI dell'host, dal container il sito |

L'ultima riga è la lezione del TeX ([tex-dal-container](tex-dal-container.md)):
una prova fatta dall'host sulla loopback passerebbe con il container rotto.
Che l'applicazione la serva un container lo dice, sull'host, il file
dell'upstream che scrive `deploy-container.sh`; nell'assetto di ripiego non
c'è, e la loopback torna giusta. Con un URL che non è la loopback la domanda
si fa anche dall'host, ma un firewall fra il bridge e la LAPI lo vede solo il
giro dentro il container, al passo 8-bis del rilascio.

**Quale URL, quindi.** Uno che valga **sull'host e nel container**, perché
`.env.local` è uno solo: il bouncer lo usa nel container, e questo controllo,
due volte al giorno, sull'host. È lo stesso problema del servizio TeX, e la
stessa soluzione: il **gateway del bridge Docker**
([tex-dal-container](tex-dal-container.md#il-gateway-come-si-ricava)), cioè
`CROWDSEC_LAPI_URL=http://<gateway>:8080`. Come per il TeX, tre pezzi devono
essere d'accordo: dove ascolta la LAPI (in CrowdSec lo decide `listen_uri` in
`/etc/crowdsec/config.yaml`; se si sposta dalla loopback al gateway, va
spostato anche l'indirizzo con cui la chiamano l'agente e `cscli` dell'host,
in `/etc/crowdsec/local_api_credentials.yaml`: dalla documentazione di
CrowdSec, non misurato qui), il firewall, che deve lasciar passare da
`docker0` verso gateway e porta, e l'URL in `.env.local`.
**Non `host.docker.internal`**: il container lo risolve (il rilascio lo avvia
con `--add-host host.docker.internal:host-gateway`), l'host no. Il bouncer
funzionerebbe, e questo controllo sull'host non risolverebbe il nome: un
guasto due volte al giorno per una configurazione giusta a metà. Configurare
il bouncer è una scelta da fare quando serve; al 23/9/2026 non è acceso.

La forma della risposta della LAPI è presa dal sorgente di CrowdSec, non
misurata su una LAPI vera da questo repository: se una versione nuova la
cambiasse, il controllo direbbe «non è la LAPI», cioè un guasto e non un verde.
Il riconoscimento sta in `WafCrowdSecBouncerService::riconosci()`, le risposte
in `App\Services\Waf\ControlloCrowdSec`. Le prove, con servizi finti veri:
`tests/Unit/Services/Waf/CrowdSecLapiTest.php`,
`tests/Unit/Services/Waf/ControlloCrowdSecTest.php`,
`tests/Unit/Ops/DiagnosticaCrowdSecTest.php`.

#### `sezioni`: un contenuto che nessuno può chiedere

La barra non ha una domanda sola. Una sezione che accetta più di un tipo
chiede i contenuti **per sezione** (`?section=verif`), e in quella domanda il
filtro sul tipo non c'è; una sezione mono-tipo li chiede **per tipo**
(`?type=verifica`), e lì non c'è la sezione (ADR-027).

Una riga con `section_id` vuoto non risponde alla prima domanda. Se nessuna
sezione mono-tipo copre il suo tipo — e il 20/9/2026 in produzione tutte e sei
ne accettavano quattro — quella riga non compare in nessun pannello, di nessuna
classe, per nessuno.

Non si vede provando l'applicazione: ogni pezzo fa il suo mestiere, e l'API
risponde 200 con l'elenco giusto **della domanda che le è stata fatta**. Si
vede solo confrontando le righe del database con quello che le sei domande
possono restituire, ed è quello che fa
`App\Services\Ops\ContenutiRaggiungibili`.

Gli archiviati non contano: stanno nel cestino logico apposta, e si recuperano
dalla dashboard.

Quando scatta, l'elenco è di identificativi: si aggancia la riga a una sezione
(dalla finestra di modifica, «Sposta in…») oppure la si cancella. Marcarlo come
visto non serve a niente — il contenuto resta invisibile a chi l'ha scritto.

#### `conservazione`: una misura dichiarata e non applicata

Il Titolare può configurare, per un Istituto, che le compilazioni dei
modelli istituzionali **non restino sul server**: `compilation_storage = 0`, dal
pannello degli Istituti. Da quel momento il salvataggio viene rifiutato con un
403 e la bozza resta nel browser del docente.

L'interruttore però vale **da quando lo si gira**. `InstituteRepository` esegue
una sola istruzione — `UPDATE institutes SET compilation_storage = ?` — e niente
tocca le righe già scritte. Un Istituto può quindi restare marcato «non si
conserva» con le conservazioni di prima ancora nel database.

Non si vede provando l'applicazione, perché ogni pezzo fa il suo mestiere: il
salvataggio viene rifiutato come promesso, e intanto il pregresso sta dov'era.
Si vede solo confrontando quello che la configurazione **dichiara** con quello
che nel database **c'è**, ed è quello che fa
`App\Services\Ops\ConservazioneDichiarata`.

Misura per docente, non per Istituto, con lo stesso criterio della politica che
nega il salvataggio (`CompilationStoragePolicy::storageDisabledForTeacher`): a
un docente si nega se **uno** dei suoi Istituti attivi ha l'interruttore spento,
perché un docente può appartenere a più Istituti. Misurare per Istituto darebbe
un elenco diverso da quello che il server rifiuta, cioè un allarme che non si sa
come chiudere. **Se quella politica cambia, questa guardia cambia con lei.**

Dove la colonna non c'è (migration 103 non applicata) l'esito è
`non_applicabile`, non «regge»: nessun Istituto può dichiarare niente, e
l'invariante non ha oggetto. Un «regge» su una colonna assente sarebbe
esattamente il verde che non misura.

Quando scatta, l'elenco dice quale docente e quante righe. **Non si chiude da
qui, e non c'è un bottone che cancelli**: sarebbe l'unico punto della
piattaforma in cui un amministratore distrugge in blocco contenuti che non può
nemmeno leggere — sono cifrati con la chiave di ciascun docente — e
contraddirebbe l'impianto, dove ogni accesso amministrativo ai contenuti di un
docente gli viene notificato. La chiude il docente: esporta il PDF, lo deposita
nei sistemi della scuola e cancella la sua compilazione. Se servisse una
rimozione in blocco, è uno strumento lanciato con intenzione esplicita e
motivazione a registro.

Marcarlo come visto non serve: verso il DPO che ha chiesto quella
configurazione, la differenza fra una misura applicata e una misura annunciata
resta finché le righe ci sono.

#### `contratti`: la sorgente non si sostituisce con la sua fotografia

Il contratto di un contenuto porta la **sorgente**: il testo, le formule, il
sorgente TikZ delle figure. Quello che il browser ne fabbrica per mostrarlo —
l'SVG compilato, il riquadro rosso quando la compilazione non riesce, lo
`<script type="text/tikz">` prima che il client lo sostituisca — non deve
tornarci dentro. Se ci torna, la sorgente è persa e non si ricostruisce: un SVG
non ridiventa il TikZ che l'ha disegnato, e il riquadro d'errore non porta
niente.

È successo il 18 settembre 2026. Il docente ha aperto due esercizi della
verifica 75 dal pannello delle verifiche correlate e li ha richiusi senza
toccarli; il servizio TeX in quel momento non rispondeva. Nel contratto, al
posto di due figure, sono finiti «`[TikZ render error]` Errore di rete…» e uno
`<script nonce="" type="text/tikz">` scritto come testo. Il difetto era nel
serializzatore dell'editor ed è corretto, e il salvataggio ora **rifiuta** una
scrittura che introduca uno di questi marcatori (`TestoDiPaginaResa`, 422 con
messaggio in italiano). Questo controllo guarda il risultato, non il codice: se
un contratto ne porta lo stesso, qualcuno ce l'ha messo.

Cosa cerca, e dove **non** cerca:

| Cerca | Non guarda |
|---|---|
| `[TikZ render error]`, `fm-tikz-error` | `script` di un blocco `tikz`: è sorgente TeX |
| `<script … text/tikz …>` dentro un testo | `svg` di un blocco `geogebra`: lì l'SVG ci va, con lo stato `.ggb` che lo rigenera |
| un `<svg …>` **con addosso i segni del client**: un attributo `data-tikz-*`, oppure gli `id` interni riscritti da `renameSvgIds` (`id="tk<6 esadecimali>_<n>_…"`) | `ggb_b64`, `data_template_data`: dati codificati |
| `data-tikz-body`, `-tagopen`, `-hash`, `-srckey` | un `<svg>` qualunque scritto dal docente |

L'ultima riga della colonna di destra è la più importante e la si è imparata
il 20 settembre 2026, in revisione: il tag `<svg` da solo **non** basta. Il
contenuto dei blocchi non passa da `HtmlSanitizer` prima del salvataggio — lo
chiama `ContractRenderer` in fase di resa, non `ContractRepository::save()` —
quindi un quesito di Informatica che dice «osserva questo codice: `<svg …>`»
arriva alla guardia così com'è. Con la regola vecchia quel salvataggio veniva
rifiutato, con un messaggio che parlava di figure TikZ e diceva di ricaricare
la pagina: il docente restava fermo e ricaricare non cambiava niente. Un
controllo che blocca il lavoro vero è peggio del guasto che evita.

Quando dice `ROTTO` elenca gli `id` delle righe `teacher_content_data`. La
sorgente di quelle figure si recupera da `content_versions`, che archivia lo
stato **precedente** a ogni salvataggio: la versione immediatamente prima di
quella che ha rovinato il contratto porta ancora il blocco `tikz` con il suo
`script`. Il recupero è un lavoro a mano, una voce per volta, e non lo fa
questo strumento (vedi «Cosa questo sistema non fa»).

Il controllo `lavori` merita una riga a parte, perché è quello che ha trovato
il guasto peggiore. Il 9 settembre 2026 **tutte** le unità systemd risultavano
`success` con uscita zero. Ma il salvataggio delle chiavi di cifratura dei
docenti non è un'unità systemd: è una riga di `crontab`, e falliva ogni notte
dal 18 agosto con «Permission denied». Un cron che fallisce non lascia
*nessuna* traccia di stato — non va in `failed`, non ha `OnFailure=`, non
compare in `systemctl` — e il suo messaggio finiva in un file di registro senza
nemmeno le date.

Per questo `lavori` guarda **cosa è stato prodotto**, non con che codice è
uscito qualcosa. È più severo, non meno: coglie anche il programma che esce
zero senza aver fatto niente.

```bash
php tools/ops/diagnostica.php              # tutti
php tools/ops/diagnostica.php --json       # per un'altra macchina
php tools/ops/diagnostica.php --solo=csrf,geoip
```

Esce `0` se tutto regge, `1` se qualcosa no. I guasti trovati vengono anche
scritti nel registro delle anomalie: **un guasto trovato di notte è ancora lì
la mattina dopo.**

Un nome passato a `--solo` che nessun controllo porta è un guasto (dal 23/9/2026).
Prima la diagnostica non faceva niente e stampava «0 controlli, tutti
reggono», con esito 0: un nome scritto male, o una copia dello strumento più
vecchia del controllo chiesto, sembravano un verde.

Gira in due momenti:

- **dopo ogni rilascio**, e — questo conta — **come `www-data`**. È la lezione
  dei due disservizi dell'8 settembre: un controllo dei permessi fatto da un
  utente che quei permessi ce li ha comunque non controlla niente. In
  `deploy-container.sh` gira **dentro il container**, che è dove vive
  l'applicazione; in `deploy.sh` sull'host, per l'assetto di ripiego. Non fa
  fallire il rilascio — il codice è già in produzione, e tornare indietro per
  un database GeoIP scaduto sarebbe sproporzionato: lascia un avviso e una riga
  nel registro.

  Attenzione a quale script si tocca: da quando il rilascio è a container il
  webhook lancia `deploy-container.sh`, **non** `deploy.sh`. Agganciare un
  controllo al secondo e crederlo attivo è un altro modo di avere una rete che
  non c'è; è successo il 9 settembre, corretto lo stesso giorno.
- **due volte al giorno**, alle 07:00 e alle 19:00, con
  `pantedu-diagnostica.timer`. Se fallisce, `OnFailure=pantedu-avviso@%n.service`
  manda una mail. Dodici ore è il tempo massimo che un guasto silenzioso può
  restare tale.

Installazione (una volta sola, come root sul VPS):

```bash
install -m 644 tools/systemd/pantedu-diagnostica.{service,timer} /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now pantedu-diagnostica.timer
```

### 3. Le guardie che non lasciano tornare il problema

La diagnostica trova; le guardie impediscono che rientri.

**`tools/ci/check-meta-letti.mjs`** — nessun JavaScript legge un `<meta>` che
nessuna vista emette. È la guardia che avrebbe colto il difetto CSRF a costo
zero: nove punti leggevano `meta[name="csrf-token"]`, mai emesso da nessuno, e
il ripiego `|| ""` faceva partire una stringa vuota. I due lati stavano in
cartelle diverse — chi legge in `js/`, chi emetterebbe in `views/` — quindi
nessuna lettura di un file solo poteva svelarlo: serviva **confrontare le due
liste**, che è tutto quello che fa lo script.

**`tests/Unit/Core/CoperturaCsrfTest.php`** — ogni rotta che cambia stato
verifica il gettone, o è esentata per iscritto. Fallisce in due direzioni: se
una rotta nuova scrive senza gettone, e se un'esenzione non corrisponde più a
nessuna rotta. Un elenco di eccezioni che nessuno ripulisce smette di essere un
elenco di eccezioni. Dal 23/9/2026 fallisce anche se una lettura (GET o HEAD,
che il middleware non controlla) arriva a un gestore protetto dal gettone: una
rotta del gruppo `csrf` che accetta GET, o una GET con lo stesso gestore di una
scrittura col gettone, se non è esentata con il motivo. `/files/clear-temp`,
dichiarata con `any()`, svuotava i temporanei con una GET senza gettone.

**`tools/ci/check-script-eseguibili.mjs`** — ogni file che comincia con `#!` è
eseguibile anche in git. Git teneva `backup_teacher_keys.sh` come `100644`: il
primo `git reset --hard` del rilascio lo ricreava inerte, e il cron delle 03:00
moriva. Uno shebang è una promessa; se git non la conosce, ogni copia fresca la
tradisce. `core.fileMode=false` su Windows nasconde il problema in locale.

**`tools/ci/cancello-semgrep.mjs`** — il cancello che sostituisce `--error`.
Fallisce se semgrep ha rifiutato delle regole, se ha guardato troppi pochi
file, se una classe di riscontri cresce, se ne compare una nuova non censita,
o se una classe censita scende a zero. Una classe che cala **si stampa e non fa
fallire** (dal 9/9/2026, dopo tre giri: i pacchetti di regole si scaricano
dalla rete e cambiano da soli); se il calo viene da una correzione, il tetto si
abbassa nello stesso commit. Per i file che semgrep legge solo in parte, dove
le regole non girano, il cancello stampa sempre l'elenco intero e fallisce se
supera il tetto o se un file non è in `file_non_letti.elenco` del censimento
(dal 23/9/2026: prima contava solo il numero, e un file sistemato e uno nuovo
letto a metà si compensavano). Prova: `tests/js-unit/cancello-semgrep-elenco.test.js`.

**`tests/semgrep/`** — il banco di prova delle regole di `.semgrep.yml`, un
file per regola con il nome della regola: le righe `ruleid:` dicono «qui deve
scattare», quelle `ok:` «qui no», e il passo «Le regole del progetto, provate
nei due versi» del lavoro di sicurezza le fa girare con `semgrep --test`. Il
cancello qui sopra conta i riscontri, ma una regola che non trova niente e una
regola rispettata danno lo stesso zero: il banco è il verso che lo distingue.
La scansione normale non guarda `tests/` (l'elenco predefinito di semgrep la
salta), quindi i riscontri voluti del banco non entrano nel censimento. Dal 19
settembre 2026 ci sta `php-mb-convert-encoding-indovinata`, la regola contro la
conversione che ha tolto gli accenti alle mappe (wiki/domains/mappe/
mappe-overview.md, «Caratteri persi»). Per provarla in locale, con l'immagine
della CI (l'impronta è in `ci.yml`):

```bash
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/src" -w /src semgrep/semgrep@sha256:… \
    semgrep --test --config .semgrep.yml tests/semgrep/php-mb-convert-encoding-indovinata.php
```

## Il lato GitHub

I giri programmati — accessibilità, suite E2E, Lighthouse, conformità — girano
la domenica notte, quando non c'è nessuno a guardare. Se falliscono, GitHub
manda una mail a chi ha toccato per ultimo il file del workflow, e quella mail
finisce insieme a tutte le altre: la suite E2E della notte dell'8 settembre
2026 era caduta con **sei test rossi** e non se n'era accorto nessuno per un
giorno intero.

`.github/workflows/avvisa-guasto.yml` apre una segnalazione quando un giro
programmato fallisce, e dal 23/9/2026 anche quando fallisce la suite
end-to-end dopo un'unione su `main`: il codice è già in produzione e chi ha
unito non sta più guardando. Una segnalazione e non un'altra mail, per tre ragioni:
resta finché qualcuno non la chiude, si vede aprendo il repository, e si
**aggiorna invece di duplicarsi** — se il giro cade cinque domeniche di fila
resta una segnalazione con cinque commenti. E si chiude da sola quando il giro
torna verde, così l'elenco dice cosa è rotto *adesso*.

Una cosa da sapere quando si guarda un rosso: **un giro può cadere per la
macchina e non per il codice**. Un runner spento a metà lavoro dà
`The runner has received a shutdown signal`, che sotto il nome del job sembra
in tutto una suite che fallisce. Vale la pena scriverlo nella segnalazione
prima di chiuderla.

## Cosa ha trovato il primo giro

Il 9 settembre 2026, applicando questi strumenti ai processi notturni:

| Trovato | Da quanto | Come appariva |
|---|---|---|
| Salvataggio delle chiavi di cifratura dei docenti fermo | 3 settimane | Un cron senza stato: né `failed`, né mail, né riga in `systemctl` |
| Filtro geografico su dati vecchi di 110 giorni | 3 mesi e mezzo | Il lavoro **riusciva**: scaricava, usciva zero, scriveva nella cartella del nome vecchio del progetto |
| Allarmi AIDE mandati nel nulla | Da sempre | «cannot send mail» nel giornale, unità `success` |
| Controllo d'integrità che non ha mai esaminato un file | 111 giorni | «AIDE check completato» ogni notte, e sotto `ERROR: missing configuration` |
| Un allarme scritto nel registro delle anomalie e mai letto | 1 giorno | Riga JSON spezzata da un conteggio sbagliato; il lettore la scartava in silenzio |
| Suite E2E notturna caduta con sei test rossi | 1 giorno | Una mail fra tante |
| Crontab orfano: coda di compilazione e sincronizzazione Drive spente | Dal rinominamento | `ORPHAN (no passwd entry)` a ogni riavvio di cron |

Nessuno di questi era in stato di errore. Le unità systemd erano **tutte**
`success` con uscita zero. È il motivo per cui il controllo `lavori` guarda
cosa viene prodotto: l'unica prova che un lavoro periodico ha davvero
lavorato è la cosa che ha lasciato.

Il 14 settembre 2026 ne è saltato fuori un altro, proprio dall'ultima riga: la
sincronizzazione con Drive, rimessa in piedi il 9 come unità, girava ma non
lavorava. A notti alterne tutte le mappe del docente collegato finivano in
errore (`error=130` nel giornale: in produzione mancano le credenziali OAuth,
almeno dal 20 maggio) e lo script usciva con zero. Ora esce con 1 e scrive il
motivo (`App\Services\Drive\SincronizzazioneNotturna`). Dallo stesso giorno
Drive ha uno stato (ADR-038): dove `DRIVE_ENABLED` non è vero, come in
produzione, Drive è spento e il giro esce con zero dicendolo; acceso senza
credenziali è un guasto, e l'avviso parte. Un docente che deve ricollegare
non fa partire avvisi: lo vede nel suo cruscotto.

## Integrità dei file: AIDE e auditd

Il caso più istruttivo della giornata, e quello che ha fatto nascere la regola
del battito.

### Centoundici notti

Dal 20 maggio al 9 settembre 2026 `/etc/cron.daily/aide-check` ha scritto ogni
notte nel suo registro:

```
[2026-05-20T06:25:01+00:00] AIDE check completato
  ERROR: missing configuration (use '--config' '--before' or '--after')
```

Centoundici righe «completato». Zero file esaminati. Il monitoraggio
d'integrità del server non ha mai guardato niente e non l'ha mai detto, per
tre difetti che si sommavano:

1. `aide --check` **senza `-c`** non parte: questa build non ha un percorso di
   configurazione compilato dentro, ed esce 17.
2. `DIFF=$(aide --check 2>&1 | tail -200)` butta via l'uscita di `aide` e
   tiene quella di `tail`, che è sempre zero.
3. Il ramo dell'allarme scattava solo trovando la riga «Total number of
   differences», che con exit 17 non c'è.

Ognuno da solo sarebbe stato visibile. Insieme facevano un verde perfetto.

E in più le regole stesse — `/etc/aide/aide.conf.d/99-progetto-precedente` — puntavano a
`/var/www/progetto-precedente`, il nome vecchio del progetto: una cartella che non
esiste. Usavano anche gruppi di attributi (`PERMS`, `Binlib`) non definiti in
questa installazione, quindi `aide --config-check` le rifiutava in blocco.
Anche avendo passato `-c`, non sarebbe stato sorvegliato niente.

### La regola che ne discende: il battito

Un controllo silenzioso e un controllo morto si assomigliano troppo. Perciò
`tools/ops/aide-check.sh` scrive **a ogni giro**, anche quando va tutto bene,
`storage/logs/aide-ultimo-giro.json`; e la diagnostica lo tratta come tratta
gli altri lavori periodici — se invecchia più di due giorni, è un guasto.

Non è il registro dell'esito: è la prova che il giro è avvenuto. È l'unica
cosa che avrebbe fatto suonare l'allarme la notte del 21 maggio invece che
centoundici notti dopo.

### auditd: risponde a «chi», non a «cosa»

AIDE confronta due fotografie a ventiquattr'ore di distanza. Dice **che** un
file è cambiato — non chi, non quando, non con quale comando. E un file
toccato e rimesso a posto nella stessa giornata, per AIDE, non è mai
cambiato.

auditd registra i fatti mentre accadono. Acceso il 9 settembre 2026 con nove
regole (`tools/ops/audit-pantedu.rules`), scelte per stare dentro il tetto di
80 MB con mesi di margine: misurate a regime, circa **2 MB al giorno**.

Due cose imparate accendendolo, e nessuna delle due si sarebbe vista senza
provarle:

- **`-F auid>=1000` è la forma che si trova scritta ovunque, e qui non
  funziona.** Serve a escludere gli account di servizio, che stanno sotto
  1000. Ma su questa macchina ci si collega **come root**, che ha `auid=0`:
  quella soglia escludeva esattamente le persone da sorvegliare. La regola
  giusta è `-F auid!=unset`, che distingue «sessione di login» da «demone» —
  verificato in tutti e due i versi: una lettura da terminale lascia
  l'evento, cinque richieste all'applicazione non ne lasciano nessuno.
- **`ausearch` senza `--input-logs` e senza un terminale resta bloccato in
  attesa su stdin.** Esito 124 dopo quindici secondi di timeout, zero righe.
  Dentro un cron sarebbe un processo appeso tutti i giorni. Nel dubbio,
  sempre `--input-logs` e `</dev/null`.

E una terza, trovata il 13 settembre 2026, quattro giorni dopo:

- **un lavoro in `/etc/cron.d/` non ha `/usr/sbin` nel PATH.** Non eredita il
  PATH di `/etc/crontab`, parte con `/usr/bin:/bin`, e `ausearch` sta in
  `/usr/sbin`. Il controllo delle 04:00 saltava in silenzio la ricerca delle
  letture di `.env.local`, mentre i giri lanciati a mano la facevano: delle
  cinque segnalazioni di letture nel registro, nessuna veniva da cron. Adesso
  `aide-check.sh` fissa il PATH da sé, e se `ausearch` manca lo dice con
  un'anomalia. Uno script che gira da cron **si prova con il PATH di cron**
  (`systemd-run --setenv=PATH=/usr/bin:/bin …`), non da una shell di root.

Le letture si contano dall'ultimo giro, non dalle ultime ventiquattr'ore: così
una lettura già segnalata da un giro fatto a mano non torna in quello della
notte.

### Il punto cieco dei container, e come si è chiuso

Segnalato il 14 settembre 2026, misurato il 15: una lettura di `.env.local`
**dall'interno del container** non lasciava traccia. Il container monta lo
stesso file dell'host, ma un processo entrato con `docker exec` non ha una
sessione di login: lo avvia il runtime dei container. Sul VPS la sessione ssh
ha `loginuid` 0, il processo di `docker exec` 4294967295. La regola
`auid!=unset`, che serve a non registrare php-fpm, escludeva anche lui.

La lettura dentro il container non si registra in modo pulito: senza filtro sul
login sono migliaia di letture di php-fpm, e un filtro per eseguibile non
regge, perché i binari del container cambiano percorso a ogni rilascio. Si
registra quindi l'**ingresso**: `docker`, `nsenter`, `runc` e `ctr` eseguiti da
una sessione di login (chiave `pantedu_container_ingressi`). Il controllo
notturno li separa con `tools/ops/ingressi-container.sh`:

- **ingressi**: `docker exec`, `cp`, `run`, `attach`, `export`, `commit` (anche
  come `docker container …` e `docker compose …`), e ogni `nsenter`, `runc`,
  `ctr`. Si segnalano come le letture: logger, anomalia
  `audit_container_ingressi`, e nel registro del giorno chi, quando e con quale
  comando;
- **altri comandi docker** (`ps`, `logs`, `inspect`…): contati e basta. Ogni
  notte ne compare qualcuno di atteso: `aide-spiega.sh` usa `docker ps` e gira
  dal cron, che ha una sessione di login;
- il rilascio e le unità systemd usano docker senza login e non compaiono.

Se manca `ausearch` o il classificatore il battito dice `ingressi_container: -1`
e c'è un'anomalia `audit_ingressi_non_controllati`: un controllo che non è
avvenuto non vale zero.

**Cosa resta fuori, e si sa**: chi parla con l'API di Docker direttamente sul
socket (`/run/docker.sock`, di root e del gruppo `docker`, che è vuoto), e un
binario copiato altrove.

**Installarlo richiede un riavvio**, perché le regole sono immutabili (`-e 2`):

```bash
install -m 0640 /var/www/pantedu/tools/ops/audit-pantedu.rules /etc/audit/rules.d/pantedu.rules
install -m 0755 /var/www/pantedu/tools/ops/aide-check.sh /usr/local/sbin/aide-check-pantedu.sh
install -m 0755 /var/www/pantedu/tools/ops/aide-spiega.sh /usr/local/sbin/aide-spiega.sh
install -m 0755 /var/www/pantedu/tools/ops/ingressi-container.sh /usr/local/sbin/ingressi-container.sh
augenrules --check
systemctl reboot
```

Dopo il riavvio, da una sessione ssh, la prova nei due versi:
`bash /var/www/pantedu/tools/ops/prova-ingressi-container.sh`. Controlla che le
quattro regole siano caricate, che un `docker exec` e un `docker ps` della
sessione compaiano (il primo come ingresso) e che lo stesso `docker ps` lanciato
da systemd non compaia.

**Prima di un riavvio**, controllare che Docker parta dopo MariaDB:
`systemctl show docker -p After` deve contenere `mariadb.service`
(`tools/systemd/docker.service.d/pantedu-dopo-mariadb.conf`). Qui c'era scritto
«il container del sito riparte da solo (`--restart unless-stopped`)»: era vero
del container, non del servizio. Al riavvio del 15 settembre 2026 Docker è
partito insieme a MariaDB e la cartella creata al posto del socket l'ha fermata:
sito e database giù per cinque minuti. Racconto e rimedio in
`docs/ops/runbook-sito-irraggiungibile.md`, § 4.6 e § 7.

### Il crontab di root è vuoto, e non per ordine

Accendere auditd ha reso visibile una cosa che non c'entrava con la sicurezza.

La regola sulle letture di `.env.local` distingue una sessione di login da un
demone guardando `auid`. Ma **un cron ha una sessione di login** — PAM gliene
assegna una — mentre un servizio systemd no. E PHP legge `.env.local` a ogni
avvio. Misurato:

```
prima: 30
php tools/cron/process_pdf_import_jobs.php --purge-only
dopo:  31   differenza: 1
```

Un evento per esecuzione, ogni notte, per un lavoro nostro. In una settimana
quell'allarme sarebbe morto di rumore.

Le tre righe rimaste nel crontab di root sono quindi diventate unità systemd —
il salvataggio delle chiavi dei docenti, la purga dei registri di audit, la
pulizia delle importazioni PDF. Il guadagno non è solo il silenzio:

- adesso hanno `OnFailure=pantedu-avviso@%n.service`, che un cron non può
  avere. Il salvataggio delle chiavi è **quello rimasto fermo tre settimane**
  senza che nessuno lo sapesse;
- girano come `pantedu` e non come root, quindi i file che producono sono
  leggibili dall'applicazione;
- non si possono orfanare. Un crontab di un utente che smette di esistere
  viene saltato in blocco, e cron lo annuncia come `ORPHAN (no passwd entry)` a
  ogni riavvio — un messaggio che non legge nessuno. È già successo qui, al
  rinominamento del progetto.

In `/etc/cron.d` restano solo lavori che non leggono i segreti (verificato) e
il controllo d'integrità, che come root ci deve girare per forza: per quello il
filtro è per nome, nello script che conta.

### La divisione dei compiti

**AIDE è l'allarme, auditd è il verbale.**
Quando AIDE dice che `/etc/sudoers` è cambiato, si va a leggere auditd per
sapere chi. L'unica eccezione è la lettura di `.env.local`, che AIDE non può
vedere per definizione — non cambia niente — e che il controllo giornaliero
segnala da sé passando per il registro delle anomalie.

## Dire «visto»

`PANTEDU_DATA_PATH=<cartella dei dati> php tools/ops/visto.php`

Il controllo `anomalie` guarda le ultime ventiquattr'ore. Quindi dopo
**qualunque** anomalia vera la diagnostica resta rossa per un giorno intero e
manda una mail a ogni giro — 07:00 e 19:00 — anche quando il problema è stato
guardato e risolto mezz'ora dopo.

Non è un fastidio. È la stessa forma di guasto contro cui è costruito tutto il
resto di questo documento: un allarme che continua a suonare dopo la
riparazione insegna a spegnere l'allarme.

Il caso concreto: `unattended-upgrades` è attivo, ogni pacchetto aggiornato
cambia file sotto `/usr` — che AIDE sorveglia — e quindi **circa una volta a
settimana** arriva un `aide_differenze` vero e atteso.

```bash
PANTEDU_DATA_PATH=<cartella dei dati> php tools/ops/visto.php            # cosa c'è di nuovo
PANTEDU_DATA_PATH=<cartella dei dati> php tools/ops/visto.php --stato    # i livelli d'acqua, con le motivazioni

PANTEDU_DATA_PATH=<cartella dei dati> php tools/ops/visto.php aide_differenze     --perche="le otto differenze sono i pacchetti aggiornati il 21/9, guardate una per una"
```

Sul VPS si lancia come `pantedu`, dalla copia sull'host:
`sudo -u pantedu env PANTEDU_DATA_PATH=<cartella dei dati> php tools/ops/visto.php --tutto --perche="..."`.
Il comando esatto, con la cartella, lo scrive la diagnostica nel suo messaggio.

### `--perche` è obbligatorio (dal 22 settembre 2026)

Senza motivazione non si segna niente e l'anomalia continua a suonare. Almeno
quindici caratteri.

Il motivo sta nelle regole del progetto: marcare un'anomalia come vista è
legittimo **solo dopo** aver guardato ogni differenza una per una, e va scritto
perché. Fino a quel giorno restavano registrati il codice, l'istante e il nome
di chi segnava: si sapeva *che* qualcuno aveva guardato, non *che cosa aveva
visto*. Davanti a un livello d'acqua di sei mesi prima non c'era modo di sapere
se sotto ci fosse un aggiornamento di pacchetti o un ingresso che nessuno sa
spiegare — cioè proprio la distinzione per cui il registro esiste.

L'attrito è voluto: scrivere una frase costa molto meno che guardare, e chi ha
davvero guardato quella frase ce l'ha già in testa.

Le motivazioni precedenti non si perdono: restano in `storico` dentro il file
dei livelli d'acqua, e `--stato` le mostra. Un codice chiuso ogni settimana con
la stessa frase è a sua volta un'informazione.

Una voce segnata **prima** di quella data non ha la motivazione: `--stato` lo
scrive per esteso — «non registrata» e «scritta male» non sono la stessa cosa
per chi rilegge — e il livello d'acqua continua a valere, perché il passato non
si riapre.

**Perché la cartella dei dati si passa a mano (dal 14 settembre 2026).**
`visto.php` non legge nessun file d'ambiente. Prima partiva dal bootstrap
dell'applicazione, che carica `.env.local`; auditd annota ogni lettura di quel
file da una sessione interattiva, e il giro di AIDE della notte dopo la
segnala come `audit_segreti_letti`. Segnare un'anomalia come vista ne
produceva quindi un'altra, e il resoconto non tornava mai pulito: misurato
quel giorno, `php tools/ops/visto.php audit_segreti_letti` compariva nel
registro di auditd fra le letture da segnalare. Al registro delle anomalie
serve solo la cartella dei dati, e quella non è un segreto. Se la variabile
manca e accanto c'è un `.env.local`, lo script si ferma e dice come lanciarlo:
in produzione i dati non stanno nel repository, e un registro sbagliato
risponderebbe «niente di nuovo». La prova è
`tests/Unit/Support/VistoSenzaSegretiTest.php`: lancia lo script con
`open_basedir` che esclude la radice del repository, quindi ogni tentativo di
aprire un file d'ambiente la fa cadere; con il `visto.php` di prima cade.

Si segna un **livello d'acqua** per codice: l'istante dell'ultima occorrenza
guardata. Una nuova occorrenza dello stesso codice, successiva a quell'istante,
torna a suonare. Niente viene cancellato, e la diagnostica scrive nel resoconto
quali ha contato come nuove e quali come già viste, con la data dell'ultima:

```
ROTTO  anomalie  nuove: aide_differenze ×1 (ultima 13/9 06:06). Dettagli in …
                 Già segnate come viste: aide_differenze ×1 (ultima 12/9 16:06),
                 audit_segreti_letti ×2 (ultima 12/9 16:22)
```

La data c'è dal 13 settembre 2026. Prima le due liste scrivevano soltanto
«aide_differenze ×1», uguale fra le nuove e fra le già viste, e quel giorno la
riga è stata letta al contrario: 171 differenze riferite come già guardate, con
la diagnostica rossa proprio per loro.

Zittire vuol dire non vedere più. Questo vuol dire aver visto.

Tre dettagli che sembrano pedanteria e non lo sono, tutti e tre trovati
provando:

- il livello d'acqua è la data dell'**ultima occorrenza guardata**, non
  `adesso`: `adesso` inghiottirebbe una riga scritta nel frattempo, cioè
  proprio quella che nessuno ha ancora visto;
- un file dei livelli illeggibile fa tornare a suonare **tutto**: si sbaglia
  dalla parte del rumore, mai da quella del silenzio;
- il file dei livelli deve essere leggibile da `www-data`, perché `visto.php`
  si lancia dall'host come `pantedu` ma la diagnostica gira dentro il
  container. La prima versione usciva `pantedu:pantedu 0660` e la funzione non
  faceva niente, senza un errore da nessuna parte. Ora gruppo e permessi si
  copiano dal registro accanto.

## Quali differenze meritano i primi cinque minuti

`tools/ops/aide-spiega.sh`

Un rapporto AIDE con trecento righe vere non si legge. Ma togliere `/usr` dal
perimetro sarebbe rinunciare proprio alla cosa che AIDE serve a vedere.

Lo script separa **verificando**, non correlando. La correlazione temporale —
«è cambiato lo stesso giorno di un aggiornamento, quindi va bene» — si inganna
aspettando il martedì. Una differenza è attesa solo se passa una di queste
prove:

| prova | attesa quando |
|---|---|
| stesso contenuto | per AIDE sono cambiate solo le date (per un file anche l'inode): impronta, dimensione, permessi e proprietario sono quelli di prima |
| file installato dal repository | è identico, byte per byte, al file del commit in servizio, ed è di root e non scrivibile da altri |
| upstream di nginx | punta alla porta di un container sano dell'applicazione |
| `audit.rules` | `augenrules --check` risponde che non c'è niente da cambiare |
| file di un pacchetto | `debsums` lo dà **OK**: un pacchetto senza impronte non basta |

Tutto il resto è da guardare, e `/etc`, `/usr/local`, `/opt`, i crontab e i
`.env` non passano mai dalla prova dei pacchetti. L'elenco dei file installati
dal repository, le colonne del rapporto che lo script legge e il perché di ogni
prova stanno nello script. Le prove, nei due versi, in
`tests/ops/aide-spiega.test.sh`: girano nel cancello su `main`.

Se tutte le differenze sono spiegate, non parte nessun allarme: resta tutto nel
registro del giorno e nel battito.

**13 settembre 2026.** Il controllo di quella notte aveva 171 differenze,
nessuna spiegata e nessuna da guardare, e la diagnostica è rimasta rossa. Le
cause erano due, corrette insieme:

- **il perimetro guardava cose che cambiano da sé**: `/run` (108 differenze: un
  riavvio, una sessione di login, i container), gli indici di apt, il lease del
  DHCP, i file di cloud-init con l'ora dell'avvio, i file del repository che git
  riscrive a ogni rilascio; e, trovate rifacendo la base, le regole di suricata
  che si riscaricano ogni domenica. Il perché di ogni esclusione sta in
  `tools/ops/aide-99_pantedu.conf`;
- **il filtro riconosceva solo i pacchetti, e al contrario**: era atteso tutto
  quello che `debsums -c` non segnalava, quindi anche con `debsums` assente o
  con un pacchetto senza impronte.

Con il perimetro e il filtro nuovi, di quelle 171 ne restano 43, tutte
verificate come attese.

**Quello che resta da guardare anche quando va tutto bene**: dopo ogni
aggiornamento automatico dei pacchetti, alcune righe non passano da nessuna
prova, perché non c'è niente sulla macchina con cui confrontarle (apt cancella
i `.deb` dopo averli installati). Il 19 settembre 2026, dopo nginx e bind9,
erano 13, di tre tipi:

- i file che dpkg tiene per ogni pacchetto in `/var/lib/dpkg/info/`: le
  impronte (`.md5sums`), ma anche l'elenco dei file (`.list`) e, per le
  librerie, `.shlibs`;
- i file della **versione vecchia**, che l'aggiornamento toglie: una libreria
  con la versione nel nome (`libdns-9.20.26-….so`) risulta rimossa, e nessun
  pacchetto la possiede più;
- `/etc/ld.so.cache`, che si rigenera quando cambia una libreria, e sta sotto
  `/etc`, dove niente è atteso per principio.

Il giorno dopo arriva quindi un `aide_differenze`. Si guarda che i pacchetti
siano stati davvero aggiornati (`/var/log/apt/history.log`) e che i loro file
combacino (`dpkg -V <pacchetti>`: non deve stampare niente, salvo i file di
configurazione modificati da noi, segnati `c`). Poi **si rifà la base**
(qui sotto). Segnare l'anomalia come vista, e basta, non serve: la base
resta quella di prima, e la notte dopo le stesse righe tornano.

Il limite è scritto nello script: `debsums` usa gli MD5 che il pacchetto porta
con sé, e il commit in servizio sta in un repository sulla stessa macchina. Chi
può riscrivere un file di sistema può in linea di principio riscrivere anche
quelli. Per questo non sostituisce AIDE, che tiene le sue impronte altrove —
gli dice quali righe leggere per prime.

### Le regole del perimetro si installano a mano

Il perimetro di AIDE sta in `tools/ops/aide-99_pantedu.conf`, con il perché di
ogni esclusione, ma il rilascio non lo installa. Dopo ogni modifica si copia a
mano, come root sul VPS, dalla cartella del sorgente, e si controlla che AIDE
lo accetti:

```bash
cp tools/ops/aide-99_pantedu.conf /etc/aide/aide.conf.d/99_pantedu
aide --config /etc/aide/aide.conf --config-check
```

`cp` su un file che esiste già ne tiene proprietario e permessi. Il file
installato cambia, e la notte dopo compare fra le differenze: `aide-spiega.sh`
lo dà per atteso se è identico a quello del commit in servizio. Poi si rifà la
base (qui sotto).

**23 settembre 2026.** L'ultima modifica esclude `/var/lib/pantedu-rilascio`,
la cartella di root dove il rilascio lascia il segno «il `vendor/` dell'host va
aggiornato» ([ci-cd](../dev/ci-cd.md#il-vendor-dellhost-e-le-versioni-legali)).
Va installata prima del primo rilascio che aggiorna il `vendor/` dell'host, che
è quello che crea la cartella. Altrimenti la cartella compare come «aggiunta» e
resta nel rapporto tutte le notti, finché non si rifà la base.

### Rifare la base di riferimento

AIDE confronta ogni notte con una base che **non si aggiorna da sola**: una
differenza vera resta nel rapporto tutte le notti, finché la base non si rifà.
Non esiste un comando per «accettarla», e non deve esistere: si rifà a mano,
**dopo** aver guardato le differenze una per una.

```bash
aide --config /etc/aide/aide.conf --update     # circa 6 minuti, scrive aide.db.new
```

`--update` esce da 1 a 7 quando trova differenze: non è un errore, sopra 7 sì.
Poi si conserva la base vecchia con la data accanto, e si mette la nuova al suo
posto:

```bash
mv /var/lib/aide/aide.db /var/lib/aide/aide.db.AAAA-MM-GG
mv /var/lib/aide/aide.db.new /var/lib/aide/aide.db
```

Poi la **controprova**, che non è facoltativa: un giro vero
(`/usr/local/sbin/aide-check-pantedu.sh`) deve dire «AIDE found NO
differences». Solo allora le anomalie guardate si segnano come viste.

Le basi sono compresse con gzip: `grep` sulla base conta byte compressi, e
`zcat base | grep -q` con `pipefail` riporta fallimento proprio quando trova. Si
decomprime una volta su un file fuori dal perimetro, in `/root`, e si cerca lì.

## Come si aggiunge un controllo

In `tools/ops/diagnostica.php`, dentro un blocco `if (daFare('nome'))`, si
chiama `annota('nome', 'regge'|'guasto'|'non_applicabile', 'la prova')`.

Due regole:

1. **La prova è un fatto, non un'opinione.** «i permessi sono a posto» non è
   una prova; «`.env` leggibile (0640), storage scrivibile» sì.
2. **`non_applicabile` non è una via di fuga.** Si usa solo quando la cosa da
   controllare non esiste in questo assetto — GeoIP non configurato, `version.txt`
   su una macchina di sviluppo — e la prova deve dirlo esplicitamente.

## Cosa questo sistema non fa

Non ripristina niente. Su una macchina sola, con migrazioni non sempre
reversibili, un ripristino automatico può fare più danni del guasto. La
decisione resta a una persona; quello che deve essere automatico è la
telefonata.

Non sostituisce il controllo di salute (`/health`) né la verifica dopo il
rilascio (`smoke_after_deploy.php`). Quelli dicono che il sito **risponde**.
Questo dice se risponde **nello stato che dichiara**, che è una domanda diversa
e — a giudicare dal 9 settembre — quella che non stavamo facendo.
