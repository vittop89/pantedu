# Creazione del super-admin

> Guida per l'istituto che installa pantedu. Il primo account amministratore
> **non** deve essere quello dello sviluppatore: ogni scuola definisce il
> **proprio** super-admin, con le proprie credenziali.

## Cos'è il super-admin

Il super-admin è un account docente con il flag tecnico `is_super_admin = 1`.
Oltre alle funzioni di docente, attiva i pannelli `/admin/*` (istituti,
sidebar, backup, monitoraggio, configurazione deployment, ecc.).

**Non è un "ruolo" separato**: è un docente normale con un permesso elevato.
Per questo va creato con cautela e con una password robusta.

## In breve

Si crea con lo script idempotente `tools/seeds/seed_super_admin.php`, che è
**parametrico**: tutti i dati (username, nome, email, istituto) si passano via
variabili d'ambiente. Nessun dato è cablato nel codice.

```bash
# 1. Imposta i dati del TUO super-admin e del TUO istituto
export SEED_ADMIN_USERNAME="mario.rossi"
export SEED_ADMIN_FIRSTNAME="Mario"
export SEED_ADMIN_LASTNAME="Rossi"
export SEED_ADMIN_EMAIL="mario.rossi@tuoistituto.edu.it"
export SEED_INSTITUTE_CODE="ABIS01234X"                 # cod. meccanografico reale
export SEED_INSTITUTE_NAME='I.I.S. "Nome Istituto"'
export SEED_INSTITUTE_CITY="Tua Città"
export SEED_INSTITUTE_REGION="Tua Regione"

# 2. Imposta una password forte (NON passarla sulla riga di comando:
#    finirebbe nella history della shell). Usa una variabile letta da prompt:
read -rs SEED_ADMIN_PASSWORD; export SEED_ADMIN_PASSWORD

# 3. Esegui
php tools/seeds/seed_super_admin.php
```

Lo script:
- crea (o aggiorna) l'utente con `is_super_admin = 1`, stato `approved`, attivo;
- crea (o aggiorna) l'istituto e collega il docente all'istituto;
- è **idempotente**: rieseguirlo non crea duplicati.

## Variabili d'ambiente

| Variabile | Obbligatoria | Default (solo dev) |
|---|---|---|
| `SEED_ADMIN_USERNAME`   | consigliata | `superadmin` |
| `SEED_ADMIN_FIRSTNAME`  | consigliata | `Vittorio` |
| `SEED_ADMIN_LASTNAME`   | consigliata | `Pantaleo` |
| `SEED_ADMIN_EMAIL`      | consigliata | (email dev) |
| `SEED_ADMIN_PASSWORD`   | **sì** (min 8 caratteri) | — |
| `SEED_INSTITUTE_CODE`   | consigliata | codice dev |
| `SEED_INSTITUTE_NAME`   | consigliata | istituto dev |
| `SEED_INSTITUTE_CITY`   | consigliata | città dev |
| `SEED_INSTITUTE_REGION` | consigliata | regione dev |

> I default servono **solo** per lo sviluppo locale. Nel clone pubblicato sono
> stati sostituiti con segnaposto neutri (vedi
> [PUBLISHING.md](publication/PUBLISHING.md)). In produzione passa **sempre**
> le tue variabili.

## Regole di sicurezza

1. **Password forte e personale.** Minimo 8 caratteri (consigliati 14+, con
   lettere/numeri/simboli). Non riusare password di altri servizi.
2. **Mai in chiaro.** Non scrivere la password nel `.env` versionato, nei
   commit, nei log o nella history della shell. Usa il prompt (`read -rs`) o un
   gestore di segreti.
3. **Un super-admin per persona reale.** Evita account "condivisi": ogni
   amministratore deve avere il proprio, per la tracciabilità
   (`privileged_access_log`).
4. **Rotazione.** Se la password può essere stata esposta, rieseguila lo script
   con una nuova password (aggiorna l'hash dell'utente esistente).
5. **Revoca.** Per togliere i privilegi: `UPDATE users SET is_super_admin = 0
   WHERE username = '...';` (resta docente). Per disattivare l'account:
   `UPDATE users SET active = 0, status = 'disabled' WHERE username = '...';`.

## Verifica

Dopo l'esecuzione, controlla a DB:

```sql
SELECT id, username, role, is_super_admin, status, active
FROM users WHERE username = 'mario.rossi';
```

Deve risultare `is_super_admin = 1`, `status = 'approved'`, `active = 1`.
Poi accedi dall'interfaccia: nella topbar/sidebar devono comparire le voci
`/admin/*`.

## Promuovere un docente esistente

Se il docente è già registrato, basta elevarlo (senza ricreare l'account):

```sql
UPDATE users SET is_super_admin = 1 WHERE username = 'docente.esistente';
```
