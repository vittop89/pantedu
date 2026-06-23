<?php
/**
 * Sistema di Autenticazione Unificato - AuthCode.php
 *
 * @deprecated (Phase 13) Ruolo auth ora coperto dal router moderno:
 *   - Middleware `auth` + `role:*`  (app/Core/Router + routes/web.php)
 *   - Session centralizzata in `App\Core\Session`
 *   - Login form `/login` in `App\Controllers\AuthController::showLogin`
 *
 * Questo file resta SOLO come shim per le ~40 pagine legacy eser/**.php
 * che fanno `include AuthCode.php` all'apertura. ExerciseViewController
 * wrappa queste pagine via `BodyExtractor` → app.php moderno: l'auth è
 * già stata verificata dal router PRIMA che la pagina venga inclusa,
 * quindi AuthCode qui non deve bloccare. Quando tutte le pagine legacy
 * saranno migrate al sistema /studio/ (ExerciseStudyController), questo
 * file andrà cancellato.
 *
 * NON modificare senza aggiornare routes/web.php auth middleware.
 */

// (Phase 13) Se la pagina legacy è wrapped da ExerciseViewController
// (chiamata via /eser/, /verifiche/, ... attraverso il router moderno),
// l'auth è già stata verificata dal middleware `auth` + `role:*` e la
// sessione già avviata da App\Core\Session. Early return: non serve
// duplicare session_start + debug log + logging module.
if (defined('FM_LEGACY_WRAPPED')) {
    return;
}

// Molte pagine legacy (es. eser/**/2_MAT-prova_2-sc3s.php) emettono
// <!DOCTYPE html> PRIMA di includere questo file. Su PHP 8.3
// (con output_buffering=Off di default) `session_start()` e
// `ini_set('session.*')` falliscono con "headers already sent".
// Apriamo un buffer se non ce n'è già uno attivo — copre questo
// caso e rende robusta ogni chiamata successiva.
if (!headers_sent() && ob_get_level() === 0) {
    ob_start();
}

// Configurazione sicura delle sessioni (solo se la sessione non è
// ancora stata avviata e se possiamo ancora mandare header)
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 0) == 443;
    ini_set('session.cookie_secure', $isHttps ? 1 : 0);
    session_start();
} elseif (session_status() === PHP_SESSION_NONE) {
    // Headers già inviati — non possiamo toccare la session config,
    // ma possiamo almeno avviare la sessione in modalità compatibile
    // (PHP userà la config di default).
    @session_start();
}

// Debug per tracciare il flusso
error_log("AUTH UNIFIED: AuthCode.php accessed - URL: " . ($_SERVER['REQUEST_URI'] ?? 'unknown') . " - Session: " . session_id());

// Include il sistema di logging
require_once __DIR__ . '/../logging/access_logger.php';

// Phase 6e: sidebar_auth / sidebar_auth_context erano il protocollo
// postMessage tra iframe figlio e pagina padre. L'iframe è stato
// rimosso; questo ramo è dead code. Lo teniamo racchiuso in un
// `if (false)` per non rompere file di prova che ancora importano
// AuthCode — tutta la logica sotto non viene mai eseguita.
if (false && isset($_GET['sidebar_auth']) && $_GET['sidebar_auth'] == '1') {
    if (isset($_SESSION['autenticato']) && $_SESSION['autenticato'] === true) {
        // Utente autenticato, ma verifica se ha privilegi per sezioni riservate
        $userRole = $_SESSION['user_role'] ?? 'unknown';
        $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
        
        // Se la richiesta sidebar è per sezioni che richiedono admin/collaboratore
        // (determiniamo questo dal fatto che stiamo verificando AuthCode.php)
        $allowedRoles = getAllowedRolesForPath($currentUrl);
        
        if (in_array($userRole, $allowedRoles)) {
            // Utente ha i privilegi necessari
            echo '<script>
                console.log("✅ AuthCode Unified: Utente autenticato con privilegi - messaggio sidebar");
                parent.postMessage({
                    type: "auth_success",
                    userRole: "' . $userRole . '",
                    username: "' . ($_SESSION['username'] ?? 'unknown') . '",
                    sidebar: true,
                    immediate: true
                }, "*");
            </script>';
            echo '<div style="text-align:center; padding:20px; font-family:Arial;">
                    <h3 style="color: #4CAF50;">✅ Accesso autorizzato</h3>
                    <p>Caricamento contenuto sidebar...</p>
                  </div>';
            exit;
        } else {
            // Utente autenticato ma senza privilegi sufficienti per questa sezione
            echo '<script>
                console.log("⚠️ AuthCode Unified: Utente senza privilegi - richiesto login admin");
                parent.postMessage({
                    type: "auth_required",
                    reason: "insufficient_privileges",
                    currentRole: "' . $userRole . '",
                    requiredRoles: ' . json_encode($allowedRoles) . ',
                    message: "Questa sezione richiede privilegi di amministratore o collaboratore."
                }, "*");
            </script>';
            echo '<div style="text-align:center; padding:20px; font-family:Arial; background:#fff3cd; border-left:4px solid #ffc107;">
                    <h3 style="color: #856404;">🔒 Accesso Limitato</h3>
                    <p><strong>Questa sezione è riservata agli amministratori.</strong></p>
                    <p>Il tuo account (' . htmlspecialchars($userRole) . ') non ha i permessi necessari.</p>
                    <p style="font-size:0.9em; color:#6c757d;">
                        Per accedere, esegui il logout e accedi con un account amministratore.
                    </p>
                    <a href="/log/auth/logout.php" style="
                        display:inline-block; margin-top:10px; padding:8px 16px; 
                        background:#dc3545; color:white; text-decoration:none; 
                        border-radius:4px; font-size:0.9em;">
                        🚪 Logout
                    </a>
                  </div>';
            exit;
        }
    } else {
        // Utente non autenticato per sidebar - mostra form di login embedded
        echo '<script>
            console.log("🔑 AuthCode Unified: Utente non autenticato - form login sidebar");
        </script>';
        
        // Determina il messaggio in base alla sezione
        $parsedUrl = parse_url($_SERVER['REQUEST_URI']);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        $section = $queryParams['section'] ?? '';
        
        // Messaggi personalizzati per sezione
        if ($section === '#Verif') {
            $title = '🔐 Accesso negato';
            $message = '<strong>Questa sezione richiede l\'autenticazione da Amministratore.</strong>';
            $instruction = 'Clicca qui per accedere con le credenziali da amministratore:';
        } else {
            $title = '🔐 Accesso negato agli studenti';
            $message = '<strong>Questa sezione richiede l\'autenticazione da Docente.</strong>';
            $instruction = 'Clicca qui per accedere con le tue credenziali:';
        }
        
        echo '<div style="text-align:center; padding:20px; font-family:Arial; background:#e7f3ff; border-left:4px solid #007bff;">
                <h3 style="color: #004085;">' . $title . '</h3>
                <p>' . $message . '</p>
                <p>' . $instruction . '</p>
                <a href="/log/auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']) . '&sidebar_auth_context=1" 
                   style="
                        display:inline-block; margin-top:10px; padding:10px 20px; 
                        background:#007bff; color:white; text-decoration:none; 
                        border-radius:4px; font-weight:bold;">
                    🔑 Vai al Login
                </a>
              </div>';
        exit;
    }
}

// Determina i ruoli permessi in base al percorso
function getAllowedRolesForPath($filePath) {
    // Per richieste sidebar_auth, determina i privilegi in base alla sezione richiesta
    if (strpos($filePath, 'sidebar_auth=1') !== false) {
        // Estrae il parametro 'section' dall'URL
        $parsedUrl = parse_url($filePath);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        $section = $queryParams['section'] ?? '';
        
        error_log("AUTH ROLES - Sidebar auth request for section: '$section'");
        
        // Verif (#Verif) - SOLO amministratori
        if ($section === '#Verif') {
            error_log("AUTH ROLES - Section Verif: requiring ONLY administrator");
            return ['administrator'];
        }
        
        // RisDoc (#RisDoc) - Amministratori e Collaboratori
        if ($section === '#RisDoc') {
            error_log("AUTH ROLES - Section RisDoc: requiring administrator or collaborator");
            return ['administrator', 'collaborator'];
        }
        
        // Default per altre sezioni protette - admin e collaboratori
        error_log("AUTH ROLES - Default protected section: requiring administrator or collaborator");
        return ['administrator', 'collaborator'];
    }
    
    // Se il percorso contiene '/eser/' permetti admin e studenti
    if (strpos($filePath, '/eser/') !== false) {
        return ['administrator', 'student'];
    }
    
    // Per tutti gli altri percorsi permetti solo admin e collaboratori
    return ['administrator', 'collaborator'];
}

// Controllo di accesso principale
function checkAccess() {
    $callerPath = $_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
    $allowedRoles = getAllowedRolesForPath($callerPath);
    
    // Verifica se l'utente è autenticato
    if (!isset($_SESSION['autenticato']) || $_SESSION['autenticato'] !== true) {
        error_log("AUTH UNIFIED: User not authenticated, redirecting to login from: $callerPath");
        redirectToLogin();
        exit;
    }
    
    // Verifica se l'utente ha un ruolo permesso
    $userRole = $_SESSION['user_role'] ?? 'unknown';
    if (!in_array($userRole, $allowedRoles)) {
        http_response_code(403);
        error_log("AUTH UNIFIED: Access denied - User role '$userRole' not allowed for: $callerPath");
        
        // Determina il tipo di messaggio in base al contesto
        $isStudentSection = strpos($callerPath, '/eser/') !== false;
        $isCollaborator = $userRole === 'collaborator';
        $isStudent = $userRole === 'student';
        $isAdmin = $userRole === 'administrator';
        
        // Messaggi personalizzati in base al ruolo e alla sezione
        if ($isStudentSection && $isCollaborator) {
            // Collaboratore prova ad accedere a sezione studenti
            echo '<div style="text-align:center; padding:50px; font-family:Arial; background:#fff3cd; border-left:4px solid #ffc107;">
                    <h2 style="color: #856404;">📚 Sezione Riservata agli Studenti</h2>
                    <p><strong>Questa sezione è accessibile solo agli studenti registrati.</strong></p>
                    <p>Il tuo account collaboratore non può accedere ai contenuti didattici degli studenti.</p>
                    <p style="font-size:0.9em; color:#6c757d;">
                        Ruolo attuale: <strong>Collaboratore</strong> | Richiesto: <strong>Studente</strong>
                    </p>
                    <a href="javascript:void(0)" onclick="handleAuthCodeLogout()" style="
                        display:inline-block; margin-top:10px; padding:8px 16px; 
                        background:#dc3545; color:white; text-decoration:none; 
                        border-radius:4px; font-size:0.9em; cursor:pointer;">
                        🚪 Logout
                    </a>
                  </div>';
        } elseif (!$isStudentSection && $isStudent) {
            // Studente prova ad accedere a sezione riservata (admin/collaboratori)
            echo '<div style="text-align:center; padding:50px; font-family:Arial; background:#fff3cd; border-left:4px solid #ffc107;">
                    <h2 style="color: #856404;">🔒 Sezione Riservata</h2>
                    <p><strong>Questa sezione è riservata a docenti e collaboratori.</strong></p>
                    <p>Il tuo account studente non ha i permessi necessari.</p>
                    <p style="font-size:0.9em; color:#6c757d;">
                        Ruolo attuale: <strong>Studente</strong> | Richiesto: <strong>Docente/Collaboratore</strong>
                    </p>
                    <a href="javascript:void(0)" onclick="handleAuthCodeLogout()" style="
                        display:inline-block; margin-top:10px; padding:8px 16px; 
                        background:#dc3545; color:white; text-decoration:none; 
                        border-radius:4px; font-size:0.9em; cursor:pointer;">
                        🚪 Logout
                    </a>
                  </div>';
        } else {
            // Messaggio generico per altri casi
            echo '<div style="text-align:center; padding:50px; font-family:Arial;">
                    <h2 style="color: #dc3545;">⛔ Accesso Negato</h2>
                    <p>Non hai i permessi necessari per accedere a questa sezione.</p>
                    <p style="font-size:0.9em; color:#6c757d;">
                        Ruolo: <strong>' . htmlspecialchars($userRole) . '</strong> | 
                        Richiesti: <strong>' . implode(', ', $allowedRoles) . '</strong>
                    </p>
                    <a href="javascript:void(0)" onclick="handleAuthCodeLogout()" style="
                        display:inline-block; margin-top:10px; padding:8px 16px; 
                        background:#dc3545; color:white; text-decoration:none; 
                        border-radius:4px; font-size:0.9em; cursor:pointer;">
                        🚪 Logout
                    </a>
                  </div>';
        }
        
        // JavaScript per gestire il logout con conferma (identico al pulsante UpBar)
        echo '<script>
        function handleAuthCodeLogout() {
            const confirmed = confirm("🚪 Sei sicuro di voler uscire dalla sessione corrente?\\n\\nDovrai effettuare nuovamente il login per accedere a questa sezione.");
            if (confirmed) {
                const currentUrl = window.location.pathname + window.location.search;
                const logoutUrl = "/log/auth/logout.php?redirect=" + encodeURIComponent(currentUrl);
                window.location.href = logoutUrl;
            }
        }
        </script>';
        
        exit;
    }
    
    // Accesso autorizzato - registra nel log
    $username = $_SESSION['username'] ?? 'unknown';
    error_log("AUTH UNIFIED: Access granted - User '$username' (role: $userRole) to: $callerPath");
    logUserAccess($username, $userRole, $callerPath, 'access');
}

// Redirect a login.php (sistema unificato)
function redirectToLogin() {
    $currentUrl = $_SERVER['REQUEST_URI'];
    $loginUrl = '/log/auth/login.php?redirect=' . urlencode($currentUrl);

    error_log("AUTH UNIFIED: Redirecting to login: $loginUrl");

    if (!headers_sent()) {
        header("Location: $loginUrl");
        exit;
    }
    // Headers già inviati dalla pagina chiamante (es. <!DOCTYPE> prima
    // dell'include). Fallback JS + meta refresh per il redirect.
    $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES);
    echo "<meta http-equiv=\"refresh\" content=\"0;url=$safeUrl\">";
    echo "<script>window.location.replace(" . json_encode($loginUrl) . ");</script>";
    exit;
}

// Controllo timeout sessione (30 minuti)
$timeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    error_log("AUTH UNIFIED: Session timeout, redirecting to login");
    redirectToLogin();
}
$_SESSION['last_activity'] = time();

// Rigenera ID sessione per sicurezza
if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 300) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// ESECUZIONE AUTOMATICA
// Non eseguire controllo per contesti speciali
$isSpecialContext = (
    (isset($_GET['sidebar_auth']) && $_GET['sidebar_auth'] == '1') ||
    (basename($_SERVER['SCRIPT_NAME']) === 'login_iframe.php')
);

if (!$isSpecialContext) {
    checkAccess();
}

?>
