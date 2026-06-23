# Configurazione Cron Job per Anonimizzazione Log
# Sistema di protezione privacy conforme GDPR

## ISTRUZIONI PER ARUBA HOSTING

### 1. Accesso al pannello di controllo Aruba
- Accedi al pannello di controllo del tuo hosting
- Vai nella sezione "Cron Job" o "Operazioni pianificate"

### 2. Creazione del Cron Job
Inserisci i seguenti parametri:

**Comando da eseguire:**
```
/usr/bin/php /path/to/your/site/log/cleanup_logs.php
```

**Sostituire /path/to/your/site/ con il percorso reale del sito**

**Frequenza suggerita:**
- Ogni domenica alle 2:00 del mattino
- Cron expression: `0 2 * * 0`

**Parametri dettagliati:**
- Minuto: 0
- Ora: 2  
- Giorno del mese: * (ogni giorno)
- Mese: * (ogni mese)  
- Giorno della settimana: 0 (domenica)

### 3. Configurazione alternativa (settimanale)
Se preferisci un'esecuzione più frequente:

**Ogni 3 giorni alle 3:00:**
```
0 3 */3 * *
```

**Ogni lunedì alle 1:30:**
```
30 1 * * 1
```

### 4. Verifica del funzionamento

Dopo aver impostato il cron job, verifica il suo funzionamento:

1. Controlla il file di log: `/log/anonymization_log.txt`
2. Verifica la dashboard analytics per confermare la gestione dei dati
3. Monitora eventuali errori nel log degli errori del server

### 5. Esempio di output atteso

Il file `anonymization_log.txt` dovrebbe contenere righe simili a:

```
2025-08-19 02:00:15 - Record anonimizzati: 1247/2841 (cutoff: 2025-06-19)
2025-08-26 02:00:12 - Record anonimizzati: 89/2841 (cutoff: 2025-06-26) 
2025-09-02 02:00:18 - Record anonimizzati: 156/3024 (cutoff: 2025-07-02)
```

### 6. Considerazioni per Aruba

- **Percorsi assoluti**: Usa sempre percorsi assoluti per PHP e per i file
- **Permessi**: Assicurati che i file PHP abbiano i permessi corretti (644)
- **Output**: Su Aruba l'output del cron viene spesso inviato via email
- **Timeout**: Gli script non dovrebbero superare i 30 secondi di esecuzione

### 7. Test manuale

Prima di attivare il cron job, testa lo script manualmente:

```bash
# Da terminale SSH (se disponibile)
php /path/to/your/site/log/cleanup_logs.php

# Oppure accedendo via browser
https://tuosito.com/log/cleanup_logs.php
```

### 8. Monitoraggio

Controlla regolarmente:
- Esecuzione corretta del cron job
- Dimensioni dei file di log
- Funzionamento della dashboard analytics
- Conformità alle policy di privacy

### 9. Note di sicurezza

- Lo script è protetto da accesso diretto non autorizzato
- I dati vengono anonimizzati, non cancellati
- Il processo è irreversibile e conforme GDPR
- L'accesso alle statistiche rimane funzionale

### 10. Supporto tecnico

In caso di problemi:
1. Verifica i log di errore del server
2. Controlla i permessi dei file
3. Testa l'esecuzione manuale dello script
4. Contatta il supporto Aruba se necessario

---

**IMPORTANTE:** Questa configurazione garantisce la protezione automatica
della privacy degli utenti mantenendo le funzionalità statistiche essenziali.
