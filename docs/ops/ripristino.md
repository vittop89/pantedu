# Ripristinare Pantedu da una copia di sicurezza

Scritto il 10 settembre 2026, dopo il **primo ripristino di prova vero**. Fino
a quel giorno del salvataggio cifrato si sapeva che il file saliva su
Backblaze e che la sua impronta combaciava. Non si sapeva se si riapriva, né
se quello che c'era dentro bastava a far ripartire il sito. La prova ha
trovato due cose che non bastavano: sono scritte più sotto, perché sono il
motivo per cui questa procedura è fatta così.

## Che cosa contiene una copia

Due posti, su B2, nel secchio `pantedu-backup-vps`.

**Il fascicolo notturno**, `pantedu-backup-AAAAMMGG_HHMM.tar.gpg`, cifrato con
GPG simmetrico (AES256), accanto alla sua impronta `.sha256`. Dentro:

| pezzo | che cos'è |
|---|---|
| `db_*.sql.gz` | `mysqldump` dello schema `pantedu`, con routine, trigger ed eventi |
| `grants_*.sql` | utenti del database e permessi (vedi la prima trappola) |
| `storage_*.tar.zst` | i dati d'istanza da `PANTEDU_DATA_PATH/storage`, **tranne** i blob cifrati, cache, sessioni, temporanei, GeoIP e le copie stesse |
| `config_*.tar.gz` | `.env`, `.env.local` e `app/Config/` — **le chiavi di runtime** |

**I blob cifrati**, in `blob/maps_enc/attuale/` e `blob/verifiche_enc/attuale/`:
mappe e verifiche, già cifrate dall'applicazione (circa 2,5 GB). Non stanno nel
fascicolo perché non comprimono e non cambiano quasi mai: si sincronizzano ogni
notte, caricando solo quelli nuovi. Un blob cancellato in locale non sparisce da
B2: finisce in `blob/<cartella>/rimossi/<data>/`, e dopo un anno viene tolto —
la stessa durata promessa nella DPIA per le copie.

La passphrase sta in `/etc/pantedu/backup.env`, che **non è nella copia**: se
si perde quella, il fascicolo è carta straccia. Va tenuta anche altrove.

## Le due trappole che la prova ha trovato

### 1. Le viste e chi ha il diritto di leggerle

Il dump contiene otto **viste**, e una di queste è `teacher_content` — quella
da cui l'applicazione legge tutti i contenuti dei docenti. Sono create così:

```sql
CREATE DEFINER=`pantedu_app`@`localhost` SQL SECURITY DEFINER VIEW teacher_content AS …
```

`SQL SECURITY DEFINER` vuol dire che la vista gira con i permessi del suo
definitore. Se quell'utente non esiste, ogni lettura risponde:

```
ERROR 1356 (HY000): View '….teacher_content' references invalid table(s) or
column(s) or function(s) or definer/invoker of view lack rights to use them
```

Il dump **non** porta gli utenti. Su un server nuovo il caricamento riesce
senza un errore, e poi il sito non funziona. Dal 10 settembre 2026 il
fascicolo contiene `grants_*.sql`, con gli hash delle password: sta solo lì
dentro, mai in chiaro accanto. Le copie precedenti non ce l'hanno.

### 2. La cartella sbagliata

Fino al 10 settembre 2026 il salvataggio archiviava `storage/` **dentro il
repository**. Da maggio 2026 i dati d'istanza stanno sotto
`PANTEDU_DATA_PATH` (`/var/lib/pantedu-data`), fuori dal repository. Quindi le
copie contenevano 35 MB di cache e modelli, e non contenevano i 3 GB veri:
mappe e verifiche cifrate, `objects`, `data`, la catena di audit, il GDPR.
Tutti i fascicoli su B2 dal 31 maggio in poi pesano 18–21 MB: è quella la
data minima del difetto. E la prova di ripristino di quella mattina diceva
«l'archivio si apre: 1447 voci» — verde, e sbagliata: guardava che si aprisse,
non che contenesse i dati giusti.

Adesso il salvataggio legge `PANTEDU_DATA_PATH` come la legge l'applicazione,
si ferma se `objects/` o `data/` mancano dall'archivio, e la prova confronta il
contenuto cartella per cartella con la cartella dei dati.

**Le copie anteriori al 10 settembre 2026 non contengono i dati d'istanza**:
servono per il database, non per i file.

## Ripristino su una macchina nuova

Nell'ordine. Il passo 4 prima del 5 non è un dettaglio: caricare il dump senza
gli utenti crea viste che nessuno può leggere.

```bash
# 1. Portare giù il fascicolo e verificarlo PRIMA di fidarsene.
rclone copy b2-pantedu:pantedu-backup-vps/pantedu-backup-AAAAMMGG_HHMM.tar.gpg .
rclone copy b2-pantedu:pantedu-backup-vps/pantedu-backup-AAAAMMGG_HHMM.tar.gpg.sha256 .
sha256sum -c pantedu-backup-AAAAMMGG_HHMM.tar.gpg.sha256   # deve dire OK

# 2. Decifrare (chiede la passphrase di /etc/pantedu/backup.env).
gpg --decrypt --output bundle.tar pantedu-backup-AAAAMMGG_HHMM.tar.gpg
tar -xf bundle.tar

# 3. Il database vuoto, con la stessa collazione.
mysql -e "CREATE DATABASE pantedu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. PRIMA gli utenti, poi i dati.
mysql < grants_*.sql

# 5. Adesso il dump.
zcat db_*.sql.gz | mysql pantedu

# 6. Le chiavi di runtime nel repository, i dati d'istanza nella loro cartella.
tar -xzf config_*.tar.gz -C /var/www/pantedu
mkdir -p /var/lib/pantedu-data/storage
tar -xf storage_*.tar.zst -C /var/lib/pantedu-data/storage

# 7. I blob cifrati, da B2.
rclone copy b2-pantedu:pantedu-backup-vps/blob/maps_enc/attuale      /var/lib/pantedu-data/storage/maps_enc
rclone copy b2-pantedu:pantedu-backup-vps/blob/verifiche_enc/attuale /var/lib/pantedu-data/storage/verifiche_enc

# 8. Proprietario e gruppo come li vuole l'applicazione.
chown -R pantedu:www-data /var/lib/pantedu-data/storage
```

Poi `php tools/ops/diagnostica.php`: se i sette controlli reggono, il
ripristino è a posto davvero.

**Il passo 8 basta perché tutto ciò che l'applicazione scrive sta sotto
`/var/lib/pantedu-data/storage`**, e non accanto: le stampe (`storage/temp`),
i PDF (`storage/tex_pdf`), i temporanei delle verifiche
(`storage/verifiche/temp`), le soglie degli allarmi e la cache dei blocchi
(`storage/security/`). È l'invariante che tiene insieme tre cose: il `chown`
qui sopra, il salvataggio notturno (che archivia `storage/`) e il controllo
d'avvio del container, che prima di prendere traffico prova a scrivere in
ognuna di quelle cartelle e si ferma se non ci riesce
(`docker/verifica-avvio.php`). Una cartella fratello di `storage/` non
sarebbe coperta da nessuna delle tre, e la scrittura fallirebbe rispondendo
«salvato» — misurato il 20/9/2026.

Una cartella `/var/lib/pantedu-data/log/` su un'istanza vecchia si può
lasciare dov'è: non la scrive più nessuno. `blocked_credentials.json` e
`blocked_ips.json` li rilegge una volta sola `WafSecurityRepository`, per
portarne il contenuto nel database, che è la fonte di verità.

**Senza `config_*.tar.gz` il database è illeggibile anche se è tutto lì.** I
contenuti dei docenti sono cifrati con chiavi che stanno in `.env.local`
(`KMS_MASTER_KEY`): ripristinare il solo database vuol dire ripristinare del
rumore.

## La prova periodica

```bash
sudo bash tools/ops/prova-ripristino.sh
```

Fa il giro completo su un database di servizio (`pantedu_prova_ripristino`),
confronta con la produzione il database, **il contenuto dell'archivio dei
dati** (cartella per cartella) e **il numero dei blob su B2**, e poi cancella
tutto: la produzione non viene mai toccata. Da rilanciare dopo ogni
cambiamento al salvataggio, e comunque ogni tanto — una copia non provata è
un'ipotesi.

Quello che la prova **non** copre: ricostruisce il database in uno schema con
un altro nome, quindi le viste lì non funzionano per costruzione. Verifica che
il file dei permessi ci sia, che è la condizione perché funzionino una volta
ripristinate al posto giusto.

## Se la passphrase è persa

Non c'è recupero per il fascicolo: è cifratura simmetrica, senza chiavi di
ricambio e senza depositi. È il singolo punto di rottura più serio di questa
catena, e l'unica difesa è tenere la passphrase in un secondo posto, fuori dal
VPS e fuori da Backblaze.
