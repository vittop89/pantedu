<?php
/**
 * Elementi comuni per il sistema Analytics
 * Include navbar dinamica, header base e funzioni condivise
 */

// Assicurati che la sessione sia avviata
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include le funzioni degli alert se non già incluse
$alertFunctionsPath = $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/alert_functions.php';
if (file_exists($alertFunctionsPath)) {
    require_once $alertFunctionsPath;
} else {
    // Fallback: definisci funzioni vuote per evitare errori
    function countActiveAlertsForAuth($logFile) {
        return 0;
    }
    function loadSecurityConfigForAlerts() {
        return [
            'security_alerts' => [
                'excessive_access' => ['enabled' => true, 'threshold_per_section' => 2, 'time_window_hours' => 24],
                'credential_sharing' => ['enabled' => true, 'min_ips_required' => 5, 'min_accesses_per_ip' => 2, 'time_window_hours' => 24]
            ]
        ];
    }
    function getReviewedAlerts() {
        return [];
    }
    function isAlertReviewed($fingerprint, $ip, $section) {
        return false;
    }
    function isIPBlockedForAlert($ip, $section) {
        return false;
    }
    function isCredentialAlertReviewed($fingerprint, $username) {
        return false;
    }
    function isCredentialsBlocked($username) {
        return false;
    }
    error_log("Warning: alert_functions.php not found, using fallback functions");
}

// Determina il percorso corretto per gli alert in base alla posizione del file
function getAlertFilePath() {
    // Usa sempre il percorso assoluto per evitare problemi
    return $_SERVER['DOCUMENT_ROOT'] . '/log/data/access_log.json';
}

/**
 * Funzione per ottenere il conteggio degli alert attivi
 * Questa funzione viene utilizzata per garantire consistenza tra tutte le pagine
 */
function getActiveAlertCount() {
    // Se alert_functions.php è caricato, usa la funzione da lì
    if (function_exists('countActiveAlertsForAuth')) {
        $logFilePath = getAlertFilePath();
        return countActiveAlertsForAuth($logFilePath);
    }
    // Fallback se le funzioni degli alert non sono disponibili
    return 0;
}

// Conta gli alert attivi
$logFilePath = getAlertFilePath();
$alertCount = getActiveAlertCount();

/**
 * Genera la navbar dinamica
 * @param string $activePage - La pagina attualmente attiva (dashboard, viewer, security_alerts, config_manager, debug_login)
 * @param array $customLinks - Link personalizzati aggiuntivi (opzionale)
 */
function renderNavbar($activePage = '', $customLinks = []) {
    // Ricalcola sempre il conteggio degli alert per garantire coerenza
    $alertCount = getActiveAlertCount();
    
    // Determina i percorsi in base alla directory corrente
    $currentDir = dirname($_SERVER['SCRIPT_FILENAME']);
    $isInAdmin = (strpos($currentDir, '/admin') !== false || strpos($currentDir, '\\admin') !== false);
    $isInMonitoring = (strpos($currentDir, '/monitoring') !== false || strpos($currentDir, '\\monitoring') !== false);
    $isInTesting = (strpos($currentDir, '/testing') !== false || strpos($currentDir, '\\testing') !== false);
    
    // Definisci i percorsi base
    if ($isInAdmin) {
        $basePath = '../security/alerts/';
        $testingPath = '../security/testing/';
        $monitoringPath = '../security/monitoring/';
    } elseif ($isInMonitoring) {
        $basePath = '../alerts/';
        $testingPath = '../testing/';
        $monitoringPath = '';
    } elseif ($isInTesting) {
        $basePath = '../alerts/';
        $testingPath = '';
        $monitoringPath = '../monitoring/';
    } else {
        // Se siamo in security/alerts/
        $basePath = '';
        $testingPath = '../testing/';
        $monitoringPath = '../monitoring/';
    }
    $adminPath = $isInAdmin ? '' : '../admin/';
    
    // Username dalla sessione
    $username = htmlspecialchars($_SESSION['username'] ?? 'Admin');
    
    // Personalizza il titolo della navbar in base alla pagina
    $navTitle = ($activePage === 'debug_login') ? '🔍 Debug System' : '🛡️ Admin Analytics';
    
    echo '<nav class="navbar">';
    echo '    <div class="nav-container">';
    echo '        <div class="nav-brand">' . $navTitle . '</div>';
    
    // Badge degli alert (solo se ci sono alert attivi)
    if ($alertCount > 0) {
        $alertUrl = $basePath . 'security_alerts.php';
        echo '        <a href="' . $alertUrl . '" class="security-alert">';
        echo '            🚨 ' . $alertCount . ' ALERT';
        echo '        </a>';
    }
    
    echo '        <ul class="nav-links">';
    
    // Link Dashboard
    $dashboardClass = ($activePage === 'dashboard') ? ' class="active"' : '';
    echo '            <li><a href="' . $monitoringPath . 'dashboard.php"' . $dashboardClass . '>📊 Dashboard</a></li>';
    
    // Link Visualizzatore
    $viewerClass = ($activePage === 'viewer') ? ' class="active"' : '';
    echo '            <li><a href="' . $monitoringPath . 'viewer.php"' . $viewerClass . '>👁️ Visualizzatore</a></li>';
    
    // Link Alert Sicurezza (con evidenziazione se ci sono alert)
    $securityClass = ($activePage === 'security_alerts') ? ' class="active"' : '';
    $securityStyle = ($alertCount > 0 && $activePage !== 'security_alerts') ? ' style="background: rgba(220, 53, 69, 0.2);"' : '';
    echo '            <li><a href="' . $basePath . 'security_alerts.php"' . $securityClass . $securityStyle . '>🚨 Alert Sicurezza</a></li>';
    
    // Link Debug
    $debugClass = ($activePage === 'debug_login') ? ' class="active"' : '';
    echo '            <li><a href="' . $testingPath . 'debug_login.php"' . $debugClass . '>🔍 Debug</a></li>';
    
    // Link User Manager
    $userManagerClass = ($activePage === 'user_manager') ? ' class="active"' : '';
    echo '            <li><a href="/log/admin/user_manager.php"' . $userManagerClass . '>👥 Utenti</a></li>';
    
    // Link Home
    echo '            <li><a href="/log/security/monitoring/index.php">🏠 Home</a></li>';
    
    // Link personalizzati aggiuntivi
    foreach ($customLinks as $link) {
        $activeClass = ($activePage === $link['key']) ? ' class="active"' : '';
        echo '            <li><a href="' . $link['url'] . '"' . $activeClass . '>' . $link['label'] . '</a></li>';
    }
    
    echo '        </ul>';
    echo '        <div class="nav-user">';
    echo '            👤 ' . $username;
    echo '        </div>';
    echo '    </div>';
    echo '</nav>';
}

/**
 * Genera l'header standard
 * @param string $title - Titolo della pagina
 * @param string $subtitle - Sottotitolo (opzionale)
 * @param array $extraContent - Content aggiuntivo da inserire nell'header (opzionale)
 */
function renderHeader($title, $subtitle = '', $extraContent = []) {
    echo '<div class="header">';
    echo '    <h1>' . htmlspecialchars($title) . '</h1>';
    
    if ($subtitle) {
        echo '    <p>' . htmlspecialchars($subtitle) . '</p>';
    }
    
    // Contenuto extra (es: badge alert, bottoni, etc.)
    foreach ($extraContent as $content) {
        echo '    ' . $content;
    }
    
    echo '</div>';
}

/**
 * Genera la sezione container di apertura
 * @param array $messages - Array di messaggi da mostrare [['type' => 'success/error', 'text' => 'messaggio']]
 */
function renderContainerStart($messages = []) {
    echo '<div class="container">';
    
    // Mostra messaggi se presenti
    foreach ($messages as $message) {
        $type = htmlspecialchars($message['type'] ?? 'info');
        $text = htmlspecialchars($message['text'] ?? '');
        echo '    <div class="message ' . $type . '">' . $text . '</div>';
    }
}

/**
 * Chiude il container
 */
function renderContainerEnd() {
    echo '</div>';
}

/**
 * Verifica l'autenticazione admin (funzione comune)
 * @param string $redirectPath - Path per il redirect di login (default: ../auth/login.php)
 */
function requireAdminAuth($redirectPath = '/log/auth/login.php') {
    if (!isset($_SESSION['autenticato']) || $_SESSION['user_role'] !== 'administrator') {
        $return_url = urlencode($_SERVER['REQUEST_URI']);
        header("Location: $redirectPath?redirect=$return_url");
        exit();
    }
}

/**
 * Genera il DOCTYPE e head HTML standard
 * @param string $pageTitle - Titolo della pagina
 * @param array $extraCSS - Array di file CSS aggiuntivi
 * @param array $extraJS - Array di file JS aggiuntivi
 */
function renderHtmlHead($pageTitle, $extraCSS = [], $extraJS = []) {
    echo '<!DOCTYPE html>';
    echo '<html lang="it">';
    echo '<head>';
    echo '    <meta charset="UTF-8">';
    echo '    <meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '    <title>' . htmlspecialchars($pageTitle) . ' - Pantedu Analytics</title>';
    
    // CSS comune
    echo '    <link rel="stylesheet" href="/log/security/alerts/common-styles.css">';
    
    // CSS aggiuntivi
    foreach ($extraCSS as $css) {
        echo '    <link rel="stylesheet" href="' . htmlspecialchars($css) . '">';
    }
    
    // JavaScript aggiuntivi
    foreach ($extraJS as $js) {
        echo '    <script src="' . htmlspecialchars($js) . '"></script>';
    }
    
    echo '</head>';
    echo '<body class="pantedu-admin-dashboard">';
}

/**
 * Chiude il documento HTML
 */
function renderHtmlFooter() {
    echo '</body>';
    echo '</html>';
}

/**
 * Genera una tabella dati standard
 * @param array $headers - Array delle intestazioni
 * @param array $rows - Array delle righe (array associativo)
 * @param array $options - Opzioni aggiuntive per la tabella
 */
function renderDataTable($headers, $rows, $options = []) {
    $tableClass = $options['class'] ?? 'data-table';
    $emptyMessage = $options['empty_message'] ?? 'Nessun dato disponibile';
    
    echo '<div class="table-container">';
    echo '    <table class="' . $tableClass . '">';
    
    // Headers
    echo '        <thead>';
    echo '            <tr>';
    foreach ($headers as $header) {
        echo '                <th>' . htmlspecialchars($header) . '</th>';
    }
    echo '            </tr>';
    echo '        </thead>';
    
    // Body
    echo '        <tbody>';
    if (empty($rows)) {
        echo '            <tr>';
        echo '                <td colspan="' . count($headers) . '" style="text-align: center; padding: 20px; color: #666;">';
        echo '                    ' . htmlspecialchars($emptyMessage);
        echo '                </td>';
        echo '            </tr>';
    } else {
        foreach ($rows as $row) {
            echo '            <tr>';
            foreach ($headers as $key => $header) {
                $cellValue = is_numeric($key) ? ($row[$key] ?? '') : ($row[$key] ?? '');
                echo '                <td>' . htmlspecialchars($cellValue) . '</td>';
            }
            echo '            </tr>';
        }
    }
    echo '        </tbody>';
    
    echo '    </table>';
    echo '</div>';
}

/**
 * Genera statistiche in card formato
 * @param array $stats - Array di statistiche [['label' => 'Label', 'value' => 'Value', 'icon' => '📊']]
 */
function renderStatsCards($stats) {
    echo '<div class="summary-stats">';
    
    foreach ($stats as $stat) {
        $icon = $stat['icon'] ?? '📊';
        $label = htmlspecialchars($stat['label'] ?? '');
        $value = htmlspecialchars($stat['value'] ?? '0');
        $extraClass = $stat['class'] ?? '';
        
        echo '    <div class="stat-card ' . $extraClass . '">';
        echo '        <div class="stat-icon">' . $icon . '</div>';
        echo '        <div class="stat-number">' . $value . '</div>';
        echo '        <div class="stat-label">' . $label . '</div>';
        echo '    </div>';
    }
    
    echo '</div>';
}

// Funzioni di utilità per il debug
function debugLog($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    
    if ($data !== null) {
        $logMessage .= " - Data: " . json_encode($data);
    }
    
    error_log($logMessage);
}

function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'proprio ora';
    if ($time < 3600) return floor($time/60) . ' minuti fa';
    if ($time < 86400) return floor($time/3600) . ' ore fa';
    if ($time < 2592000) return floor($time/86400) . ' giorni fa';
    if ($time < 31536000) return floor($time/2592000) . ' mesi fa';
    
    return floor($time/31536000) . ' anni fa';
}
