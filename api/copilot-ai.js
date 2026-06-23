// ============================================================================
// COPILOT AI INTEGRATION MODULE
// File: api/copilot-ai.js
// Version: 20260202c
// ============================================================================

/**
 * Modulo per l'integrazione di GitHub Copilot AI negli editor
 * Gestisce pannelli AI, conversazioni, comandi rapidi e inserimento codice
 */
const CopilotAI = {
  _state: {
    apiToken: null,
    conversationHistory: new Map(), // Map per editor
    currentEditorId: null,
    isProcessing: false,
    hasShownWelcome: false
  },

  /**
   * Configura il token API di GitHub
   */
  setApiToken: function(token) {
    this._state.apiToken = token;
    localStorage.setItem('github_copilot_token', token);
    console.log('✅ Token GitHub Copilot configurato con successo');
  },

  /**
   * Carica il token salvato
   */
  loadApiToken: function() {
    const saved = localStorage.getItem('github_copilot_token');
    if (saved) {
      this._state.apiToken = saved;
      return true;
    }
    return false;
  },

  /**
   * Toggle del pannello AI per uno specifico editor
   */
  togglePanel: function(editorId) {
    // Se non specificato, usa l'editor con focus
    if (!editorId) {
      editorId = EditorSystem.getFocusedEditorId();
    }
    
    if (!editorId) {
      alert('Seleziona prima un editor');
      return;
    }
    
    // Trova il wrapper dell'editor
    const $editorWrapper = $(`#${editorId}`).closest('.fm-editor-wrapper');
    if (!$editorWrapper.length) {
      console.error('Editor wrapper non trovato per:', editorId);
      return;
    }
    
    // Cerca o crea il pannello per questo specifico editor
    let $panel = $editorWrapper.find('.aiCopilotPanel');
    
    if (!$panel.length) {
      // Crea un nuovo pannello per questo editor
      this._createPanelForEditor($editorWrapper, editorId);
      $panel = $editorWrapper.find('.aiCopilotPanel');
    }
    
    const isVisible = $panel.is(':visible');
    
    // Nascondi tutti gli altri pannelli
    $('.aiCopilotPanel').not($panel).slideUp(200);
    
    if (isVisible) {
      $panel.slideUp(200);
    } else {
      // Carica token se disponibile
      if (!this._state.apiToken) {
        this.loadApiToken();
      }
      
      // Imposta l'editor corrente
      this._state.currentEditorId = editorId;
      
      // Mostra il pannello
      $panel.slideDown(200);
      $panel.find('.aiCopilotInput').focus();
      
      // Mostra messaggio di benvenuto se è la prima volta PER QUESTO EDITOR
      const history = this._state.conversationHistory.get(editorId) || [];
      if (history.length === 0 && !this._state.hasShownWelcome) {
        const welcomeMsg = this._state.apiToken ? 
          'Ciao! Sono l\'AI Assistant. Token già configurato.\n\nPosso aiutarti ad analizzare e modificare il contenuto dell\'editor. Prova a chiedermi qualcosa!' :
          'Ciao! Sono l\'AI Assistant.\n\nPer iniziare, configura il tuo token GitHub:\nsetToken("tuo_token_qui")\n\nIl token verrà salvato nel browser.';
        this._addMessageToPanel($panel, 'system', welcomeMsg);
        this._state.hasShownWelcome = true;
      }
    }
  },
  
  /**
   * Crea un pannello AI per uno specifico editor
   */
  _createPanelForEditor: function($editorWrapper, editorId) {
    const panelHtml = `
      <div class="aiCopilotPanel" style="display:none;" data-editor-id="${editorId}">
        <div class="aiCopilotHeader">
          <span>🤖 AI Assistant (GitHub Copilot)</span>
          <button class="closeAiCopilot" onclick="CopilotAI.togglePanel('${editorId}')" title="Chiudi">✕</button>
        </div>
        <div class="aiCopilotContent">
          <div class="aiCopilotMessages"></div>
          <div class="aiCopilotInputArea">
            <textarea class="aiCopilotInput" placeholder="Chiedi all'AI di analizzare o modificare il contenuto dell'editor..." rows="3"></textarea>
            <div class="aiCopilotControls">
              <label><input type="checkbox" class="aiIncludeDom" checked> Includi DOM editor</label>
              <button class="aiSendBtn" onclick="CopilotAI.sendMessage('${editorId}')">Invia</button>
            </div>
          </div>
        </div>
        <div class="aiCopilotStatus"></div>
      </div>
    `;
    
    $editorWrapper.append(panelHtml);
    
    // Aggiungi listener per Enter
    const $panel = $editorWrapper.find('.aiCopilotPanel');
    $panel.find('.aiCopilotInput').on('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        this.sendMessage(editorId);
      }
    });
    
    // Rendi il pannello trascinabile
    this._makePanelDraggable($panel);
  },

  /**
   * Invia un messaggio all'API
   */
  sendMessage: async function(editorId) {
    // Trova il pannello specifico
    const $panel = $(`.aiCopilotPanel[data-editor-id="${editorId}"]`);
    if (!$panel.length) return;
    
    const input = $panel.find('.aiCopilotInput');
    const message = input.val().trim();
    
    if (!message) return;
    
    // Gestione comandi rapidi
    const quickCommand = this._handleQuickCommands(message, editorId, $panel);
    if (quickCommand) {
      input.val('');
      return;
    }
    
    // Gestione comandi speciali
    if (message.startsWith('setToken(')) {
      const tokenMatch = message.match(/setToken\(['"](.+?)['"]\)/);
      if (tokenMatch) {
        this.setApiToken(tokenMatch[1]);
        input.val('');
        this._addMessageToPanel($panel, 'system', '✅ Token configurato correttamente!');
        return;
      }
    }
    
    if (message === '/clear') {
      this.clearConversation(editorId);
      input.val('');
      return;
    }
    
    if (message === '/help') {
      this._showHelp($panel);
      input.val('');
      return;
    }
    
    if (!this._state.apiToken) {
      this._showStatusInPanel($panel, 'Configura prima il token con: setToken("tuo_token")', 'error');
      return;
    }
    
    if (this._state.isProcessing) {
      this._showStatusInPanel($panel, 'Attendere il completamento della richiesta precedente', 'warning');
      return;
    }
    
    // Aggiungi messaggio utente
    this._addMessageToPanel($panel, 'user', message);
    input.val('');
    
    // Prepara il contesto
    const context = this._prepareContext(editorId);
    
    // Invia richiesta
    await this._sendToAPI(message, context, editorId, $panel);
  },
  
  /**
   * Gestisce comandi rapidi per formattazione LaTeX/HTML
   */
  _handleQuickCommands: function(message, editorId, $panel) {
    // /formula [descrizione]
    if (message.startsWith('/formula ')) {
      const desc = message.substring(9);
      const html = `<div>\\(${desc}\\)</div>`;
      this._insertIntoEditor(editorId, html);
      this._addMessageToPanel($panel, 'system', `✅ Formula inserita: ${desc}`);
      return true;
    }
    
    // /frazione [num] [den]
    if (message.startsWith('/frazione ')) {
      const parts = message.substring(10).split(' ');
      if (parts.length >= 2) {
        const html = `<div>\\(\\dfrac{${parts[0]}}{${parts[1]}}\\)</div>`;
        this._insertIntoEditor(editorId, html);
        this._addMessageToPanel($panel, 'system', `✅ Frazione inserita: ${parts[0]}/${parts[1]}`);
        return true;
      }
    }
    
    // /tabella
    if (message === '/tabella') {
      const html = `<div>\\(\\begin{array}{|l|l|}</div>
<div>&nbsp;&nbsp;\\hline</div>
<div>&nbsp;&nbsp;DATI&nbsp;\\&amp;&nbsp;INCOGNITE&nbsp;\\\\</div>
<div>&nbsp;&nbsp;\\hline</div>
<div>&nbsp;&nbsp;dato_1&nbsp;\\&amp;&nbsp;x=?\\\\</div>
<div>&nbsp;&nbsp;dato_2&nbsp;\\&amp;&nbsp;y=?&nbsp;&nbsp;\\\\</div>
<div>&nbsp;&nbsp;&nbsp;&nbsp;\\hline</div>
<div>\\end{array}\\)<br><br></div>`;
      this._insertIntoEditor(editorId, html);
      this._addMessageToPanel($panel, 'system', '✅ Tabella dati/incognite inserita');
      return true;
    }
    
    // /cerchia [var] [colore]
    if (message.startsWith('/cerchia ')) {
      const parts = message.substring(9).split(' ');
      if (parts.length >= 2) {
        const varName = parts[0];
        const color = parts[1] || 'red';
        const html = `<div>\\(\\enclose{circle}[mathcolor=${color}]{${varName}}\\)</div>`;
        this._insertIntoEditor(editorId, html);
        this._addMessageToPanel($panel, 'system', `✅ Variabile cerchiata: ${varName} (${color})`);
        return true;
      }
    }
    
    // /unita [valore] [unità]
    if (message.startsWith('/unita ')) {
      const parts = message.substring(7).split(' ');
      if (parts.length >= 2) {
        const value = parts[0];
        const unit = parts.slice(1).join(' ');
        const html = `<div>\\(${value}\\text{ ${unit}}\\)</div>`;
        this._insertIntoEditor(editorId, html);
        this._addMessageToPanel($panel, 'system', `✅ Valore con unità inserito: ${value} ${unit}`);
        return true;
      }
    }
    
    // /soluzione [valore]
    if (message.startsWith('/soluzione ')) {
      const value = message.substring(11);
      const html = `<span class="fm-solution">${value}</span>`;
      this._insertIntoEditor(editorId, html);
      this._addMessageToPanel($panel, 'system', `✅ Soluzione inserita: ${value}`);
      return true;
    }
    
    return false;
  },
  
  /**
   * Inserisce HTML nell'editor attivo
   */
  _insertIntoEditor: function(editorId, html) {
    // Cerca l'editor in vari modi
    let editor = null;
    
    // Metodo 1: cerca tramite data-editor-id nel pannello
    const $panelWrapper = $(`.aiCopilotPanel[data-editor-id="${editorId}"]`).closest('.fm-editor-wrapper');
    if ($panelWrapper.length) {
      editor = $panelWrapper.find('.Editor')[0];
    }
    
    // Metodo 2: cerca direttamente l'elemento con id
    if (!editor) {
      const $editorElement = $(`#${editorId}`);
      if ($editorElement.length && $editorElement.hasClass('Editor')) {
        editor = $editorElement[0];
      } else if ($editorElement.length) {
        editor = $editorElement.closest('.fm-editor-wrapper').find('.Editor')[0];
      }
    }
    
    // Metodo 3: usa l'editor con focus
    if (!editor) {
      const focusedId = EditorSystem.getFocusedEditorId();
      if (focusedId) {
        editor = $(`#${focusedId}`)[0];
      }
    }
    
    if (!editor) {
      console.error('❌ Editor non trovato per inserimento. EditorId:', editorId);
      alert('Impossibile trovare l\'editor. Seleziona prima un editor cliccandoci sopra.');
      return;
    }
    
    console.log('✅ Editor trovato per inserimento:', editor.id || editorId);
    
    // Salva la selezione
    const selection = window.getSelection();
    const range = selection.rangeCount > 0 ? selection.getRangeAt(0) : null;
    
    // Inserisci l'HTML
    if (range && editor.contains(range.commonAncestorContainer)) {
      const tempDiv = document.createElement('div');
      tempDiv.innerHTML = html;
      const fragment = document.createDocumentFragment();
      while (tempDiv.firstChild) {
        fragment.appendChild(tempDiv.firstChild);
      }
      range.deleteContents();
      range.insertNode(fragment);
      console.log('✅ HTML inserito alla posizione del cursore');
    } else {
      // Se non c'è selezione, aggiungi alla fine
      editor.insertAdjacentHTML('beforeend', html);
      console.log('✅ HTML aggiunto alla fine dell\'editor');
    }
    
    // Trigger change event e rendering MathJax
    $(editor).trigger('input');
    
    // Renderizza MathJax se disponibile
    if (typeof LatexRender !== 'undefined' && LatexRender.MathJaxRender) {
      LatexRender.MathJaxRender($(editor));
      console.log('✅ MathJax renderizzato');
    }
  },
  
  /**
   * Mostra l'help dei comandi disponibili
   */
  _showHelp: function($panel) {
    const helpText = `
📚 **Comandi Disponibili:**

**Comandi rapidi formattazione:**
• \`/formula [espressione]\` - Inserisce formula LaTeX
• \`/frazione [num] [den]\` - Crea frazione con \\dfrac
• \`/tabella\` - Inserisce tabella dati/incognite
• \`/cerchia [var] [colore]\` - Cerchia variabile (red/blue/green)
• \`/unita [valore] [unità]\` - Formatta valore con unità
• \`/soluzione [valore]\` - Inserisce in <span class="fm-solution">

**Comandi sistema:**
• \`/help\` - Mostra questo messaggio
• \`/clear\` - Pulisce la conversazione
• \`setToken("token")\` - Configura il token API

**Esempi:**
\`/formula x^2 + y^2 = r^2\`
\`/frazione a+b c-d\`
\`/cerchia x red\`
\`/unita 5 m/s\`
`;
    this._addMessageToPanel($panel, 'system', helpText);
  },

  /**
   * Prompt di sistema con regole LaTeX/HTML
   */
  _getSystemPrompt: function() {
    return `Sei un assistente esperto in formattazione HTML e LaTeX matematico per esercizi didattici.

## REGOLE FONDAMENTALI DI FORMATTAZIONE:

### 1. FORMATTAZIONE LATEX MATEMATICA:
- **Formule inline:** Usa SEMPRE \\( e \\) per racchiudere espressioni matematiche
- **Frazioni:** Usa SEMPRE \\dfrac{}{} invece di \\frac{}{}
- **Formule sulla stessa riga:** Sviluppa sequenze di formule/calcoli sulla STESSA riga quando possibile
  Esempio: \\(x = \\dfrac{a}{b} = \\dfrac{5}{2} = 2{,}5\\)
- **Ambienti:** Tutti gli ambienti \\begin{...}...\\end{...} vanno inseriti dentro \\( \\)
  ⚠️ IMPORTANTE: NON chiudere con \\) immediatamente dopo \\begin{array}
  ❌ ERRATO: \\(\\begin{array}{|c|}\\)
  ✅ CORRETTO: \\(\\begin{array}{|c|} ... \\end{array}\\)
- **NON usare \\\\** per andare a capo, tranne in array, aligned* o cases

### 2. UNITÀ DI MISURA:
- Accompagna SEMPRE i numeri con le unità usando \\text{}
  Esempio: 5\\text{ m}, 3{,}5\\text{ kg}
- Se le unità si semplificano, usa \\cancel{}
  Esempio: \\dfrac{10\\cancel{\\text{ m}}}{2\\cancel{\\text{ m}}}=5

### 3. FORMATTAZIONE HTML COMPATTA:
- **Usa <div> SOLO per:** 
  * Titoli/intestazioni separate (es. "SOLUZIONE:", "DATI:")
  * Paragrafi concettualmente distinti
  * Passaggi matematici che DEVONO stare su righe separate
- **NON usare <div> per:** ogni singola formula in una sequenza di calcoli
- **Spezzare una formula lunga:** usa <br> invece di <div>
- **Simboli speciali:** usa &lt; per < e &gt; per >
- **Compattezza:** Raggruppa formule correlate nello stesso <div> quando ha senso logico

### 4. STRUTTURE MATEMATICHE:
- **Incognite cerchiate:**
  * \\enclose{circle}[mathcolor=red]{x}: Incognite principali
  * \\enclose{circle}[mathcolor=blue]{y}: Incognite ausiliarie secondarie
  * \\enclose{circle}[mathcolor=green]{z}: Incognite ausiliarie terziarie

### 5. ESEMPIO DI FORMATTAZIONE CORRETTA:
\`\`\`html
<div><strong>SOLUZIONE:</strong></div>
<div>Identifico i coefficienti: \\(a = 2, b = -5, c = -3\\)</div>
<div>Calcolo il discriminante: \\(\\Delta = b^2 - 4ac = (-5)^2 - 4(2)(-3) = 25 + 24 = 49\\)</div>
<div>Poiché \\(\\Delta > 0\\), ci sono due soluzioni reali distinte.</div>
<div>Applico la formula: \\(\\enclose{circle}[mathcolor=red]{x} = \\dfrac{-b \\pm \\sqrt{\\Delta}}{2a} = \\dfrac{5 \\pm 7}{4}\\)</div>
<div>Soluzioni: \\(x_1 = \\dfrac{5+7}{4} = 3\\) e \\(x_2 = \\dfrac{5-7}{4} = -\\dfrac{1}{2}\\)</div>
\`\`\`

### 6. COMANDI DISPONIBILI:
- **/formula [descrizione]**: Genera una formula matematica LaTeX
- **/frazione [num] [den]**: Crea frazione con \\dfrac{}{}
- **/tabella**: Crea struttura tabella dati/incognite
- **/cerchia [var] [colore]**: Cerchia variabile (red/blue/green)
- **/unita [valore] [unità]**: Formatta valore con unità
- **/soluzione [valore]**: Inserisce in <span class="fm-solution">

Quando generi contenuto matematico:
1. Mantienilo COMPATTO e leggibile
2. Raggruppa calcoli correlati nello stesso <div>
3. Usa <div> solo per separare concetti diversi
4. Sviluppa sequenze di uguaglianze sulla stessa riga quando possibile`;
  },

  /**
   * Prepara il contesto per l'AI
   */
  _prepareContext: function(editorId) {
    const $panel = $(`.aiCopilotPanel[data-editor-id="${editorId}"]`);
    const includeDom = $panel.find('.aiIncludeDom').is(':checked');
    
    const context = {
      editorId: editorId,
      timestamp: new Date().toISOString()
    };
    
    if (includeDom && editorId) {
      const editor = $(`#${editorId}`);
      if (editor.length) {
        context.editorContent = {
          html: editor.html(),
          text: editor.text(),
          type: EditorSystem.getEditorType(editorId)
        };
        
        // Aggiungi informazioni sul problema/elemento
        const problem = editor.closest('.fm-groupcollex');
        if (problem.length) {
          context.problemInfo = {
            id: PathManager.extractProblemID(problem),
            type: problem.attr('tipo'),
            hasCheckboxA: problem.find('.checkboxA').is(':checked'),
            hasCheckboxB: problem.find('.checkboxB').is(':checked')
          };
        }
      }
    }
    
    return context;
  },

  /**
   * Invia richiesta all'API GitHub Copilot tramite proxy PHP
   */
  _sendToAPI: async function(userMessage, context, editorId, $panel) {
    this._state.isProcessing = true;
    this._showStatusInPanel($panel, 'Elaborazione in corso...', 'info');
    
    try {
      const contextPrompt = context.editorContent ? 
        `\n\nContesto editor (${context.editorContent.type}):\n${context.editorContent.text.substring(0, 1000)}` : '';
      
      // Recupera cronologia per questo editor
      const history = this._state.conversationHistory.get(editorId) || [];
      
      // Usa il system prompt con le regole LaTeX/HTML
      const systemPrompt = this._getSystemPrompt();
      
      // Chiamata al proxy PHP invece che diretta
      // Usa cartella /api/ per evitare controlli di autenticazione
      console.log('📡 Copilot API v20260202c - Sending POST request to:', '/api/copilot.php');
      console.log('🔑 Token present:', !!this._state.apiToken, 'Length:', this._state.apiToken ? this._state.apiToken.length : 0);
      const response = await fetch('/api/copilot.php?_=' + Date.now(), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Cache-Control': 'no-cache'
        },
        cache: 'no-store',
        body: JSON.stringify({
          token: this._state.apiToken,
          payload: {
            messages: [
              {
                role: 'system',
                content: systemPrompt
              },
              ...history.slice(-6), // Ultimi 3 scambi
              {
                role: 'user',
                content: userMessage + contextPrompt
              }
            ],
            model: 'gpt-4',
            temperature: 0.7,
            max_tokens: 2000
          }
        })
      });
      
      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        console.error('❌ API Error Response:', errorData);
        const errorMsg = errorData.error || errorData.message || JSON.stringify(errorData) || response.statusText;
        throw new Error(`API Error: ${response.status} - ${errorMsg}`);
      }
      
      const data = await response.json();
      const aiResponse = data.choices[0].message.content;
      
      // Salva nella cronologia per questo editor
      const newHistory = [
        ...history,
        { role: 'user', content: userMessage },
        { role: 'assistant', content: aiResponse }
      ];
      this._state.conversationHistory.set(editorId, newHistory);
      
      // Mostra risposta
      this._addMessageToPanel($panel, 'assistant', aiResponse);
      
      // Controlla se ci sono azioni da eseguire
      this._executeActions(aiResponse, context, $panel);
      
      this._showStatusInPanel($panel, 'Risposta ricevuta', 'success');
      
    } catch (error) {
      console.error('Errore API Copilot:', error);
      this._addMessageToPanel($panel, 'error', `Errore: ${error.message}`);
      this._showStatusInPanel($panel, 'Errore nella richiesta', 'error');
    } finally {
      this._state.isProcessing = false;
    }
  },

  /**
   * Esegue azioni suggerite dall'AI
   */
  _executeActions: function(response, context, $panel) {
    // Cerca blocchi di codice HTML/LaTeX
    const codeBlockRegex = /```(?:html|latex)?\n([\s\S]+?)```/g;
    let match;
    
    while ((match = codeBlockRegex.exec(response)) !== null) {
      const code = match[1].trim();
      
      // Aggiungi pulsante per applicare il codice
      const applyBtn = $('<button class="aiApplyCodeBtn">📋 Applica questo codice</button>');
      applyBtn.on('click', () => {
        if (context.editorId) {
          const editor = $(`#${context.editorId}`);
          if (editor.length) {
            editor.html(code);
            LatexRender.MathJaxRender(editor);
            this._showStatusInPanel($panel, 'Codice applicato all\'editor', 'success');
          }
        }
      });
      
      $panel.find('.aiCopilotMessages').append(applyBtn);
    }
  },

  /**
   * Aggiungi messaggio alla chat specifica
   */
  _addMessageToPanel: function($panel, role, content) {
    const messagesContainer = $panel.find('.aiCopilotMessages');
    const messageDiv = $('<div class="aiMessage"></div>');
    messageDiv.addClass(`aiMessage-${role}`);
    
    // Formatta il contenuto
    const formattedContent = content
      .replace(/```([\s\S]+?)```/g, '<pre><code>$1</code></pre>')
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\n/g, '<br>');
    
    messageDiv.html(`<strong>${this._getRoleLabel(role)}:</strong><br>${formattedContent}`);
    messagesContainer.append(messageDiv);
    
    // Scroll automatico
    messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
  },

  /**
   * Ottieni etichetta per il ruolo
   */
  _getRoleLabel: function(role) {
    const labels = {
      'user': '👤 Tu',
      'assistant': '🤖 AI',
      'system': 'ℹ️ Sistema',
      'error': '❌ Errore'
    };
    return labels[role] || role;
  },

  /**
   * Mostra stato in un pannello specifico
   */
  _showStatusInPanel: function($panel, message, type = 'info') {
    const statusDiv = $panel.find('.aiCopilotStatus');
    statusDiv.removeClass('status-info status-success status-error status-warning');
    statusDiv.addClass(`status-${type}`);
    statusDiv.text(message).show();
    
    setTimeout(() => {
      statusDiv.fadeOut();
    }, 3000);
  },

  /**
   * Pulisci conversazione per un editor specifico
   */
  clearConversation: function(editorId) {
    this._state.conversationHistory.delete(editorId);
    const $panel = $(`.aiCopilotPanel[data-editor-id="${editorId}"]`);
    $panel.find('.aiCopilotMessages').empty();
    this._addMessageToPanel($panel, 'system', 'Conversazione cancellata');
  },

  /**
   * Inizializza gestori eventi
   */
  init: function() {
    // Carica token salvato
    this.loadApiToken();
    
    console.log('✅ CopilotAI Module v20260202c inizializzato');
  },
  
  /**
   * Rende il pannello AI trascinabile
   */
  _makePanelDraggable: function($panel) {
    const $header = $panel.find('.aiCopilotHeader');
    let isDragging = false;
    let startX, startY, initialLeft, initialTop;
    
    $header.on('mousedown', function(e) {
      // Ignora se clicchi sul pulsante di chiusura
      if ($(e.target).closest('.closeAiCopilot').length) return;
      
      isDragging = true;
      $panel.addClass('dragging');
      
      // Ottieni la posizione corrente del pannello
      const rect = $panel[0].getBoundingClientRect();
      initialLeft = rect.left;
      initialTop = rect.top;
      
      // Posizione del mouse all'inizio del drag
      startX = e.clientX;
      startY = e.clientY;
      
      e.preventDefault();
    });
    
    $(document).on('mousemove', function(e) {
      if (!isDragging) return;
      
      // Calcola il nuovo offset
      const deltaX = e.clientX - startX;
      const deltaY = e.clientY - startY;
      
      const newLeft = initialLeft + deltaX;
      const newTop = initialTop + deltaY;
      
      // Limiti dello schermo (viewport)
      const maxLeft = window.innerWidth - $panel.outerWidth();
      const maxTop = window.innerHeight - $panel.outerHeight();
      
      // Applica i limiti
      const boundedLeft = Math.max(0, Math.min(newLeft, maxLeft));
      const boundedTop = Math.max(0, Math.min(newTop, maxTop));
      
      $panel.css({
        left: boundedLeft + 'px',
        top: boundedTop + 'px',
        right: 'auto'
      });
    });
    
    $(document).on('mouseup', function() {
      if (isDragging) {
        isDragging = false;
        $panel.removeClass('dragging');
      }
    });
  }
};

// Espone CopilotAI globalmente
window.CopilotAI = CopilotAI;

console.log('✅ Copilot AI Module caricato (v20260202c)');
