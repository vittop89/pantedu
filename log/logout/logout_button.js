/**
 * Modulo Logout Button
 * Sistema modulare per la gestione del logout integrato dinamicamente
 * 
 * Utilizzo:
 * 1. Includi questo script nella pagina
 * 2. Chiama LogoutButton.init(targetSelector) dove targetSelector è l'elemento dove inserire il pulsante
 */

const LogoutButton = {
    // Configurazione
    config: {
        apiEndpoint: '/log/auth/get_user_info.php',
        logoutEndpoint: '/log/auth/logout.php',
        checkInterval: 30000, // Controlla stato ogni 30 secondi
        animationDuration: 500
    },
    
    // Stato interno
    state: {
        isAuthenticated: false,
        userData: null,
        checkTimer: null,
        isInitialized: false
    },
    
    // Template HTML del pulsante logout
    templates: {
        logoutSection: `
            <div class="fm-logout-module" id="logout-section" style="display: none;">
                <div class="fm-logout-section">
                    <!-- Indicatori utente e sezione - in cima -->
                    <span id="user-indicator" class="fm-user-indicator" style="display: none;">
                        <span id="current-user">-</span>
                    </span>
                    <span id="section-indicator" class="fm-section-indicator" style="display: none;">
                        <span id="current-section">-</span>
                    </span>
                    
                    <!-- Pulsante Logout - in fondo -->
                    <button id="btnLogout" class="fm-logout-btn" title="Disconnetti dalla sessione">
                        LOGOUT
                    </button>
                </div>
            </div>
        `,
        
        loadingButton: `
            <button class="fm-logout-btn" disabled style="opacity: 0.6;">
                ⏳ LOADING...
            </button>
        `
    },
    
    // CSS dinamico integrato con il tema dell'UpBar
    injectStyles: function() {
        // Stili ora definiti in layout_es.css - non necessari qui
        return;
    },
    
    // Inizializzazione del modulo. Idempotente: se il target contiene già
    // #logout-section (re-invocazione dopo SPA swap), aggiorna solo lo stato
    // auth invece di ri-iniettare il template (evita duplicati).
    init: function(targetSelector, options = {}) {
        try {
            Object.assign(this.config, options);

            const targetElement = targetSelector
                ? document.querySelector(targetSelector)
                : document.querySelector('.fm-scrollbar-up-bar');

            if (!targetElement) {
                console.warn('LogoutButton: target non trovato:', targetSelector || '.fm-scrollbar-up-bar');
                return false;
            }

            const alreadyMounted = !!targetElement.querySelector('#logout-section');

            if (!alreadyMounted) {
                this.injectStyles();
                targetElement.insertAdjacentHTML('afterbegin', this.templates.logoutSection);
                this.attachEventListeners();
            }

            // Sempre: re-check stato auth (ruolo può essere cambiato fra swap)
            this.checkAuthenticationStatus();

            if (!this.state.checkTimer) {
                this.startPeriodicCheck();
            }

            this.state.isInitialized = true;
            
            return true;
        } catch (error) {
            console.error('LogoutButton: Errore durante l\'inizializzazione:', error);
            return false;
        }
    },
    
    // Attach event listeners
    attachEventListeners: function() {
        const logoutBtn = document.getElementById('btnLogout');
        if (!logoutBtn) return;
        
        // Click handler
        logoutBtn.addEventListener('click', (e) => this.handleLogoutClick(e));
        
        // Hover effects (già gestiti da CSS, ma possiamo aggiungere logica JS se necessario)
        logoutBtn.addEventListener('mouseenter', () => {
            if (!logoutBtn.disabled) {
                logoutBtn.style.background = 'linear-gradient(45deg, #ff5252, #d32f2f)';
                logoutBtn.style.transform = 'translateY(-1px)';
                logoutBtn.style.boxShadow = '0 2px 8px rgba(255, 82, 82, 0.4)';
            }
        });
        
        logoutBtn.addEventListener('mouseleave', () => {
            if (!logoutBtn.disabled) {
                logoutBtn.style.background = 'linear-gradient(45deg, #ff6b6b, #ee5a52)';
                logoutBtn.style.transform = 'translateY(0)';
                logoutBtn.style.boxShadow = 'none';
            }
        });
    },
    
    // Gestione click logout
    handleLogoutClick: function(e) {
        e.preventDefault();
        
        const logoutBtn = e.target;
        const sectionInfo = this.state.userData?.section_display;
        let confirmMessage = '🚪 Sei sicuro di voler uscire dalla sessione corrente?';
        
        if (sectionInfo) {
            confirmMessage += `\n\nSezione corrente: ${sectionInfo.address} - Classe ${sectionInfo.class}`;
        }
        
        confirmMessage += '\n\nDovrai effettuare nuovamente il login per accedere a questa sezione.';
        
        const confirmed = confirm(confirmMessage);
        
        if (confirmed) {
            this.performLogout(logoutBtn);
        }
    },
    
    // Esegue il logout
    performLogout: function(button) {
        // Feedback visivo
        button.textContent = '⏳ USCITA...';
        button.disabled = true;
        button.classList.add('fm-logout-loading');
        
        // Nasconde gli indicatori
        const userIndicator = document.getElementById('user-indicator');
        const sectionIndicator = document.getElementById('section-indicator');
        if (userIndicator) userIndicator.style.display = 'none';
        if (sectionIndicator) sectionIndicator.style.display = 'none';
        
        // Redirect al logout
        const currentUrl = window.location.pathname + window.location.search;
        const logoutUrl = `${this.config.logoutEndpoint}?redirect=${encodeURIComponent(currentUrl)}`;
        
        setTimeout(() => {
            window.location.href = logoutUrl;
        }, this.config.animationDuration);
    },
    
    // Verifica stato autenticazione
    checkAuthenticationStatus: function() {
        fetch(this.config.apiEndpoint, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Cache-Control': 'no-cache'
            }
        })
        .then(response => {
            return response.json();
        })
        .then(data => {
            this.updateAuthenticationState(data);
        })
        .catch(error => {
            console.warn('LogoutButton: Errore verifica autenticazione:', error);
            this.handleAuthError();
        });
    },
    
    // Aggiorna stato autenticazione
    updateAuthenticationState: function(data) {
        const logoutSection = document.getElementById('logout-section');
        
        if (!logoutSection) {
            console.error('Logout section non trovata nel DOM!');
            return;
        }
        
        if (data.authenticated) {
            this.state.isAuthenticated = true;
            this.state.userData = data;
            
            // Mostra la sezione logout
            logoutSection.style.display = 'block';
            
            // Gestione accesso admin - mostra/nascondi sezione admin
            this.toggleAdminAccess(data.role === 'administrator');
            
            // Aggiorna indicatore utente
            this.updateUserIndicator(data);
            
            // Aggiorna indicatore sezione
            this.updateSectionIndicator(data);
            
        } else {
            this.state.isAuthenticated = false;
            this.state.userData = null;
            
            // Nascondi la sezione logout e rimuovi accesso admin
            logoutSection.style.display = 'none';
            this.toggleAdminAccess(false);
        }
    },
    
    // Gestione visibilità sezione admin
    toggleAdminAccess: function(isAdmin) {
        const body = document.body;
        const upbar = document.querySelector('.fm-upbar');
        const wrapperMods = document.getElementById('wrapper-mods');
        
        if (isAdmin) {
            // Aggiungi classe per mostrare sezioni admin
            body.classList.add('fm-admin-access');
            if (upbar) upbar.classList.add('fm-admin-access');
            
            // Aggiungi pulsante Analytics se non esiste già
            this.addAnalyticsButton(wrapperMods);
        } else {
            // Rimuovi classe (per sicurezza CSS)
            body.classList.remove('fm-admin-access');
            if (upbar) upbar.classList.remove('fm-admin-access');
            
            // NOTA: Gli elementi admin-only non vengono mai caricati nel DOM
            // grazie al controllo lato server in UpBar_Es_loader.php
            // Questo garantisce che non possano essere recuperati modificando il JS
            
            // Rimuovi pulsante Analytics se presente
            this.removeAnalyticsButton();
        }
    },
    
    // Aggiungi pulsante Analytics per admin
    addAnalyticsButton: function(wrapperMods) {
        if (!wrapperMods) return;
        
        // Controlla se il pulsante esiste già
        const existingBtn = document.getElementById('analytics-btn');
        if (existingBtn) return;
        
        // Crea il pulsante Analytics
        const analyticsBtn = document.createElement('button');
        analyticsBtn.id = 'analytics-btn';
        analyticsBtn.className = 'fm-btn-up-bar';
        analyticsBtn.title = 'Accesso al sistema Analytics (Solo Admin)';
        analyticsBtn.innerHTML = '📊 ANALYTICS';
        
        // Click handler
        analyticsBtn.addEventListener('click', (e) => {
            e.preventDefault();
            window.open('/log/security/monitoring/index.php', '_blank', 'width=1200,height=800,scrollbars=yes,resizable=yes');
        });
        
        // Aggiungi il pulsante al wrapper-mods in append
        wrapperMods.append(analyticsBtn);
    },
    
    // Rimuovi pulsante Analytics
    removeAnalyticsButton: function() {
        const analyticsBtn = document.getElementById('analytics-btn');
        if (analyticsBtn) {
            analyticsBtn.remove();
        }
    },
    
    // Aggiorna indicatore utente
    updateUserIndicator: function(data) {
        const userIndicator = document.getElementById('user-indicator');
        const currentUserSpan = document.getElementById('current-user');
        
        if (userIndicator && currentUserSpan && data.username) {
            // Username server-controlled: usa textContent per evitare XSS.
            currentUserSpan.textContent = data.username;

            // Indicatore admin costruito da costanti client (corona), mai da stringhe server.
            if (data.role === 'administrator') {
                const crown = document.createElement('span');
                crown.style.color = '#ffd700';
                crown.title = 'Amministratore';
                crown.textContent = ' 👑';
                currentUserSpan.appendChild(crown);
            }

            userIndicator.style.display = 'block';
        }
    },
    
    // Aggiorna indicatore sezione
    updateSectionIndicator: function(data) {
        const sectionIndicator = document.getElementById('section-indicator');
        const currentSectionSpan = document.getElementById('current-section');
        
        if (sectionIndicator && currentSectionSpan && data.section_display) {
            const section = data.section_display;
            const sectionText = `${section.address} ${section.class}`;
            
            currentSectionSpan.textContent = sectionText;
            sectionIndicator.style.display = 'block';
        } else if (sectionIndicator) {
            sectionIndicator.style.display = 'none';
        }
    },
    
    // Gestione errori di autenticazione
    handleAuthError: function() {
        // In caso di errore, mantieni il pulsante visibile ma con stato di errore
        const logoutSection = document.getElementById('logout-section');
        if (logoutSection) {
            logoutSection.style.display = 'block';
            
            const userIndicator = document.getElementById('user-indicator');
            const currentUserSpan = document.getElementById('current-user');
            
            if (userIndicator && currentUserSpan) {
                currentUserSpan.innerHTML = '<span style="color: #ffaa00;">⚠️ Errore</span>';
                userIndicator.style.display = 'block';
            }
        }
    },
    
    // Avvia controllo periodico dello stato
    startPeriodicCheck: function() {
        if (this.state.checkTimer) {
            clearInterval(this.state.checkTimer);
        }
        
        this.state.checkTimer = setInterval(() => {
            // Mobile/3G: non svegliare la radio quando la pagina è in background
            // (risparmia batteria e dati su connessioni metered).
            if (document.visibilityState === 'hidden') return;
            if (this.state.isAuthenticated) {
                this.checkAuthenticationStatus();
            }
        }, this.config.checkInterval);
    },
    
    // Ferma controllo periodico
    stopPeriodicCheck: function() {
        if (this.state.checkTimer) {
            clearInterval(this.state.checkTimer);
            this.state.checkTimer = null;
        }
    },
    
    // Distruzione del modulo
    destroy: function() {
        this.stopPeriodicCheck();
        
        const logoutSection = document.getElementById('logout-section');
        if (logoutSection) {
            logoutSection.remove();
        }
        
        const styles = document.getElementById('logout-module-styles');
        if (styles) {
            styles.remove();
        }
        
        this.state.isInitialized = false;
        console.log('🗑️ LogoutButton: Modulo distrutto');
    },
    
    // API pubblica per forzare aggiornamento
    refresh: function() {
        if (this.state.isInitialized) {
            this.checkAuthenticationStatus();
        }
    }
};

// Export per utilizzo come modulo (se supportato)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = LogoutButton;
}

// Auto-inizializzazione se configurata
document.addEventListener('DOMContentLoaded', function() {
    // Cerca automaticamente un target con data-logout-button
    const autoTarget = document.querySelector('[data-logout-button]');
    
    if (autoTarget) {
        const targetSelector = autoTarget.getAttribute('data-logout-target') || autoTarget;
        LogoutButton.init(targetSelector);
    }
    
    // Cerca anche targets con data-logout-widget o inizializza con .fm-scrollbar-up-bar
    const widgetTargets = document.querySelectorAll('[data-logout-widget]');

    if (widgetTargets.length > 0) {
        widgetTargets.forEach((target) => {
            LogoutButton.init(target);
        });
    } else {
        // Se non trova container specifici, prova con .fm-scrollbar-up-bar
        const scrollbarUpBar = document.querySelector('.fm-scrollbar-up-bar');
        if (scrollbarUpBar) {
            LogoutButton.init('.fm-scrollbar-up-bar');
        }
    }
});

// Registra LogoutButton nella finestra globale
window.LogoutButton = LogoutButton;
