// ==================================================================
// ESEMPIO DI INIZIALIZZAZIONE - Da aggiungere al tuo script principale
// ==================================================================

$(document).ready(function() {
    // Inizializza CopilotAI
    if (typeof CopilotAI !== 'undefined') {
        CopilotAI.init();
        console.log('✅ CopilotAI inizializzato');
    }
    
    // ... resto del tuo codice di inizializzazione
});

// ==================================================================
// GUIDA ALL'USO
// ==================================================================

/*
1. CONFIGURAZIONE INIZIALE:
   - Clicca sul pulsante 🤖 nella toolbar
   - Nella chat, scrivi: setToken("il_tuo_token_github")
   - Il token verrà salvato in localStorage

2. USO BASE:
   - Clicca su un editor per selezionarlo
   - Clicca sul pulsante 🤖
   - Scrivi la tua richiesta (es: "Analizza questo contenuto")
   - L'AI avrà accesso al DOM dell'editor se la checkbox è spuntata

3. ESEMPI DI RICHIESTE:
   - "Correggi gli errori di formattazione in questo HTML"
   - "Converti questo testo in formato LaTeX"
   - "Analizza la struttura di questo esercizio"
   - "Suggerisci miglioramenti per questo contenuto"
   - "Crea una tabella HTML con 3 colonne e 4 righe"

4. APPLICARE SUGGERIMENTI:
   - Se l'AI fornisce codice, apparirà un pulsante "📋 Applica questo codice"
   - Cliccalo per inserire il codice nell'editor attivo

5. COMANDI SPECIALI:
   - setToken("token") - Configura il token API
   - /clear - Pulisce la conversazione (da implementare)

6. PRIVACY:
   - Il token è salvato solo nel tuo browser (localStorage)
   - Con GitHub Enterprise, i dati NON sono usati per training
   - Puoi disabilitare l'invio del DOM deselezionando la checkbox
*/
