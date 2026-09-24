# Ripristino da backup: ri-cancellazione (art. 17 GDPR)

**Quando si applica.** Ogni volta che il database viene ripristinato da una
copia di sicurezza, per qualunque ragione: guasto, errore operativo, prova
di ripristino con dati reali.

**Perché esiste (2026-09-04).** Una copia di sicurezza fatta prima di una
cancellazione contiene l'account com'era: la riga di `users` con nome,
cognome, email e nome utente, i contenuti, **e la chiave del docente**
(`teacher_keys`), insieme alla chiave master di quel giorno (il fascicolo
notturno porta anche `.env.local`). Ripristinarla fa rinascere un account che
l'interessato aveva chiesto di eliminare, o che la retention aveva
anonimizzato, con i suoi contenuti leggibili. Questa procedura lo chiude nel
solo modo possibile: rifare le cancellazioni, che rifanno anche la
distruzione della chiave.

Fino al 24/9/2026 questo paragrafo diceva che il crypto-shredding rende
illeggibili i contenuti «anche nelle copie di sicurezza, perché la sua chiave
non esiste più». Non è vero: la chiave non esiste più nel database in
servizio, ma c'è in ogni copia fatta prima.

## Procedura

1. **Annotare la data della copia ripristinata** (`T0`), dal nome del file
   di backup.

2. **Rieseguire le cancellazioni richieste dagli interessati** dopo `T0`.
   La lista è in `deletion_requests`: le richieste con `executed_at > T0`
   erano state eseguite e il ripristino le ha annullate. Il job giornaliero
   (`pantedu-gdpr-deletions.timer`) esegue solo le richieste in
   `cooling_off` scadute, quindi quelle già segnate `executed` vanno prima
   riportate in coda, con la stessa data di scadenza: l'interessato il
   periodo di attesa l'aveva già fatto.

   ```sql
   UPDATE deletion_requests SET status = 'cooling_off'
    WHERE status = 'executed' AND executed_at > 'T0';
   ```

   ```bash
   php tools/gdpr/execute_deletions.php --apply
   ```

3. **Rieseguire l'anonimizzazione per inattività**, che il ripristino può
   avere annullato per gli account scaduti fra `T0` e oggi:

   ```bash
   php tools/gdpr/anonymize_expired.php --apply
   ```

4. **Rieseguire la purga dei registri**, che altrimenti tornano a contenere
   righe oltre i termini dichiarati:

   ```bash
   php tools/audit/purge_old_logs.php --apply
   ```

5. **Registrare l'evento** in `crypto_custody_events` dal pannello
   `/admin/crypto-status` (tipo `data_recovered`, con `T0`, la ragione del
   ripristino e l'esito dei passi 2-4), così che la ri-cancellazione sia
   verificabile come lo è la cancellazione originaria.

## Limite residuo

Fra il ripristino e il completamento dei passi 2-4 le righe anagrafiche
esistono di nuovo sul server. La finestra va tenuta a minuti: i tre comandi
si lanciano subito dopo il ripristino, prima di riaprire il servizio.

Fino a quando le copie contengono un account cancellato: il termine è quello
della copia più vecchia che è stata fatta prima della cancellazione. Con i
valori predefiniti del codice (misurati il 24/9/2026; sul server
`backup.env` li può cambiare):

| copia | dove | quanto resta |
|---|---|---|
| fascicolo cifrato notturno | sul server | 8-9 giorni (`RETENTION_LOCAL_DAYS=7`, `find -mtime +7`) |
| fascicolo cifrato notturno | fuori sede (B2) | fino a circa 5 mesi: le ultime 3, una per settimana per 4 settimane, una al mese per 6 mesi e un posto «annuale» (`B2_KEEP_*`), che però tiene sempre una copia recente, non una di un anno prima: simulando la rotazione una notte dopo l'altra, la copia più vecchia tenuta arriva a 158 giorni |
| salvataggio giornaliero di `teacher_keys` | sul server, non cifrato (le chiavi sono avvolte con la master) | 31-32 giorni (`tools/crypto/backup_teacher_keys.sh`, `find -mtime +30`) |
| istantanea prima di ogni rilascio | sul server, non cifrata | le ultime cinque, quale che sia la data (`tools/webhook/deploy-container.sh`) |
| blob cifrati di mappe e verifiche tolti | fuori sede (B2, `blob/<cartella>/rimossi/`) | un anno; illeggibili quando nessuna copia contiene più la chiave del docente |

Il fascicolo fuori sede si toglie con `rclone delete`, senza
`--b2-hard-delete`. Secondo la documentazione di rclone, su B2 così il file
si nasconde e ne resta una versione, finché le regole del contenitore non la
eliminano. Non è misurato: quelle regole non stanno nel repository, e vanno
lette sul server prima di scrivere un termine per le copie fuori sede.

Il passo 3 funziona dal 24/9/2026: prima `anonymize_expired.php` ignorava
`--apply` e, lanciato a mano senza `GDPR_RETENTION_ENABLED=1`, girava in
prova.

La cancellazione e l'anonimizzazione (dal 24/9/2026 una sola routine,
`App\Services\Gdpr\CancellazioneDellAccount`) non toccano nessuna di queste
copie.
