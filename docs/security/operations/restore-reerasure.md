# Ripristino da backup: ri-cancellazione (art. 17 GDPR)

**Quando si applica.** Ogni volta che il database viene ripristinato da una
copia di sicurezza, per qualunque ragione: guasto, errore operativo, prova
di ripristino con dati reali.

**Perché esiste (2026-09-04).** Il crypto-shredding rende illeggibili i
contenuti di un docente cancellato anche nelle copie di sicurezza, perché la
sua chiave non esiste più. Le righe anagrafiche — nome, cognome, email,
username — no: restano nella copia così com'erano al momento del backup.
Ripristinare una copia fatta prima di una cancellazione fa quindi rinascere
un account che l'interessato aveva chiesto di eliminare, o che la retention
aveva anonimizzato. La DPIA (R10) dichiara il limite; questa procedura lo
chiude nel solo modo possibile: rifare le cancellazioni.

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
Le copie di sicurezza ruotano entro un anno (`tools/backup/encrypted_backup.sh`):
oltre quel termine nessuna copia contiene più l'anagrafica cancellata.
