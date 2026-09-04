<?php
session_start();

// Includi le funzioni comuni
require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/common-elements.php';

// Includi le funzioni degli alert aggiornate per 30 giorni
require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/alert_functions.php';
// Suppress linter warnings per funzioni definite nei file inclusi
// phpcs:disable Generic.PHP.ForbiddenFunctions
/* @phpstan-ignore-next-line */

// Controllo autenticazione admin (usa lo stesso sistema del dashboard)
requireAdminAuth();// @phpstan-ignore-line

// Funzione per caricare la configurazione degli alert
function loadSecurityConfig() {
    $configFile = 'config.json';
    if (!file_exists($configFile)) {
        // Configurazione di default se il file non esiste
        return [
            'security_alerts' => [
                'excessive_access' => [
                    'enabled' => true,
                    'threshold_per_section' => 2,
                    'time_window_hours' => 24,
                    'risk_levels' => [
                        'low' => ['min_accesses' => 3, 'max_accesses' => 25],
                        'medium' => ['min_accesses' => 26, 'max_accesses' => 50],
                        'high' => ['min_accesses' => 51, 'max_accesses' => 999999]
                    ]
                ],
                'credential_sharing' => [
                    'enabled' => true,
                    'min_ips_required' => 5,
                    'min_accesses_per_ip' => 2,
                    'time_window_hours' => 24,
                    'risk_levels' => [
                        'low' => ['min_ips' => 5, 'max_ips' => 7],
                        'medium' => ['min_ips' => 8, 'max_ips' => 10],
                        'high' => ['min_ips' => 11, 'max_ips' => 999999]
                    ]
                ]
            ],
            'general_settings' => [
                'auto_redirect' => ['enabled' => true]
            ]
        ];
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    return $config ?: [];
}

// Funzioni per gestire alert delle credenziali condivise - usa quelle da alert_functions.php

function markAlertAsReviewed($fingerprint, $ip, $section, $accessCount, $reason = '', $date = null) {
    $reviewedFile = '../../data/reviewed_alerts.json';
    $reviewed = getReviewedAlerts();
    
    // Usa la data fornita o quella corrente se non specificata
    $alertDate = $date ? $date : date('Y-m-d');
    $alertId = hash('sha256', $fingerprint . '|' . $ip . '|' . $section . '|' . $alertDate);
    $reviewed[$alertId] = [
        'fingerprint' => $fingerprint,
        'ip' => $ip,
        'section' => $section,
        'access_count' => $accessCount,
        'reviewed_at' => date('Y-m-d H:i:s'),
        'reviewed_by' => $_SESSION['username'] ?? 'unknown',
        'reason' => $reason,
        'type' => 'reviewed'
    ];
    
    error_log("MARK REVIEWED - Alert marked as reviewed: IP $ip, Section $section, Reason: $reason");
    return file_put_contents($reviewedFile, json_encode($reviewed, JSON_PRETTY_PRINT));
}

function unmarkAlertAsReviewed($fingerprint, $ip, $section, $date = null) {
    $reviewedFile = '../../data/reviewed_alerts.json';
    $reviewed = getReviewedAlerts();
    
    // Usa la data fornita o quella corrente se non specificata
    $alertDate = $date ? $date : date('Y-m-d');
    $alertId = hash('sha256', $fingerprint . '|' . $ip . '|' . $section . '|' . $alertDate);
    
    // Registra la rimozione nel file storico
    logRemovalAction('alert', [
        'type' => 'excessive_access_unmark',
        'ip' => $ip,
        'section' => $section,
        'fingerprint' => $fingerprint,
        'date' => $alertDate,
        'alert_id' => $alertId
    ]);
    
    unset($reviewed[$alertId]);
    
    return file_put_contents($reviewedFile, json_encode($reviewed, JSON_PRETTY_PRINT));
}

function markCredentialAlertAsReviewed($fingerprint, $username, $reason = '', $date = null) {
    $reviewedFile = '../../data/reviewed_alerts.json';
    $reviewed = getReviewedAlerts();
    
    // Usa la data fornita o quella corrente se non specificata
    $alertDate = $date ? $date : date('Y-m-d');
    $alertId = hash('sha256', $fingerprint . '|' . $username . '|credential_sharing|' . $alertDate);
    $reviewed[$alertId] = [
        'fingerprint' => $fingerprint,
        'username' => $username,
        'alert_type' => 'credential_sharing',
        'reviewed_at' => date('Y-m-d H:i:s'),
        'reviewed_by' => $_SESSION['username'] ?? 'unknown',
        'reason' => $reason,
        'type' => 'reviewed'
    ];
    
    error_log("MARK CREDENTIAL REVIEWED - Credential sharing alert marked as reviewed: Username $username, Reason: $reason");
    return file_put_contents($reviewedFile, json_encode($reviewed, JSON_PRETTY_PRINT));
}

function unmarkCredentialAlertAsReviewed($fingerprint, $username, $date = null) {
    $reviewedFile = '../../data/reviewed_alerts.json';
    $reviewed = getReviewedAlerts();
    
    // Usa la data fornita o quella corrente se non specificata
    $alertDate = $date ? $date : date('Y-m-d');
    $alertId = hash('sha256', $fingerprint . '|' . $username . '|credential_sharing|' . $alertDate);
    
    // Registra la rimozione nel file storico
    logRemovalAction('alert', [
        'type' => 'credential_sharing_unmark',
        'username' => $username,
        'fingerprint' => $fingerprint,
        'date' => $alertDate,
        'alert_id' => $alertId
    ]);
    
    unset($reviewed[$alertId]);
    
    return file_put_contents($reviewedFile, json_encode($reviewed, JSON_PRETTY_PRINT));
}

// Funzioni per gestire il file di rimozioni unificato
function logRemovalAction($type, $data) {
    $removalFile = '../../data/removal_history.json';
    $history = [];
    
    if (file_exists($removalFile)) {
        $content = json_decode(file_get_contents($removalFile), true);
        $history = $content ?: [];
    }
    
    // Assicurati che le chiavi esistano
    if (!isset($history[$type . '_removals'])) {
        $history[$type . '_removals'] = [];
    }
    
    // Aggiungi la nuova voce
    $entry = array_merge($data, [
        'removed_at' => date('Y-m-d H:i:s'),
        'removed_by' => $_SESSION['username'] ?? 'unknown'
    ]);
    
    $history[$type . '_removals'][] = $entry;
    
    return file_put_contents($removalFile, json_encode($history, JSON_PRETTY_PRINT));
}

// Funzione per bloccare le credenziali
function blockCredentials($username, $reason = '') {
    $blockedCredsFile = '../../data/blocked_credentials.json';
    error_log("BLOCK CREDENTIALS - Attempting to block username: $username, Reason: $reason");
    
    // Carica i blocchi esistenti
    $blockedCreds = [];
    if (file_exists($blockedCredsFile)) {
        $data = json_decode(file_get_contents($blockedCredsFile), true);
        $blockedCreds = $data ?: [];
    }
    
    // Controlla se le credenziali sono già bloccate
    foreach ($blockedCreds as $block) {
        if ($block['username'] === $username) {
            error_log("BLOCK CREDENTIALS - Username $username is already blocked");
            return false; // Già bloccato
        }
    }
    
    // Crea l'entry per il blocco
    $blockEntry = [
        'username' => $username,
        'blocked_at' => date('Y-m-d H:i:s'),
        'blocked_by' => $_SESSION['username'] ?? 'admin',
        'reason' => $reason,
        'type' => 'credential_blocked'
    ];
    
    // Aggiungi il nuovo blocco
    $blockedCreds[] = $blockEntry;
    
    // Salva il file
    $result = file_put_contents($blockedCredsFile, json_encode($blockedCreds, JSON_PRETTY_PRINT), LOCK_EX);
    
    if ($result !== false) {
        error_log("BLOCK CREDENTIALS - Successfully blocked username $username");
        return true;
    } else {
        error_log("BLOCK CREDENTIALS - Failed to save blocked credentials file");
        return false;
    }
}

// Usa isCredentialsBlocked() da alert_functions.php

// Funzione per controllare se un IP è bloccato per una sezione
function isIPBlocked($ip, $section) {
    $blockedIPsFile = '../../data/blocked_ips.json';
    if (!file_exists($blockedIPsFile)) {
        return false;
    }
    
    $blockedIPs = json_decode(file_get_contents($blockedIPsFile), true);
    if (!$blockedIPs) {
        return false;
    }
    
    foreach ($blockedIPs as $block) {
        if ($block['ip'] === $ip && $block['section'] === $section) {
            return true;
        }
    }
    
    return false;
}

// Usa isCredentialsBlocked() da alert_functions.php

// Funzione per bloccare un IP per una sezione specifica
function blockIPForSection($ip, $section, $reason = '') {
    $blockedIPsFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/blocked_ips.json';
    error_log("BLOCK IP SECTION - Attempting to block IP: $ip for section: $section, Reason: $reason");
    
    // Carica i blocchi esistenti
    $blockedIPs = [];
    if (file_exists($blockedIPsFile)) {
        $data = json_decode(file_get_contents($blockedIPsFile), true);
        $blockedIPs = $data ?: [];
    }
    
    // Crea l'entry per il blocco
    $blockEntry = [
        'ip' => $ip,
        'section' => $section,
        'blocked_at' => date('Y-m-d H:i:s'),
        'blocked_by' => $_SESSION['username'] ?? 'admin',
        'reason' => $reason,
        'type' => 'blocked'
    ];
    
    // Aggiungi il nuovo blocco
    $blockedIPs[] = $blockEntry;
    
    // Salva il file
    $result = file_put_contents($blockedIPsFile, json_encode($blockedIPs, JSON_PRETTY_PRINT), LOCK_EX);
    
    if ($result !== false) {
        error_log("BLOCK IP SECTION - Successfully blocked IP $ip for section $section");
        return true;
    } else {
        error_log("BLOCK IP SECTION - Failed to save blocked IPs file");
        return false;
    }
}

// Funzioni di sblocco
function unblockCredentials($username) {
    $blockedCredsFile = '../../data/blocked_credentials.json';
    
    if (!file_exists($blockedCredsFile)) {
        return false;
    }
    
    $blockedCreds = json_decode(file_get_contents($blockedCredsFile), true);
    if (!$blockedCreds) {
        return false;
    }
    
    $originalCount = count($blockedCreds);
    $blockedCreds = array_filter($blockedCreds, function($block) use ($username) {
        return $block['username'] !== $username;
    });
    
    if (count($blockedCreds) < $originalCount) {
        // Registra la rimozione
        logRemovalAction('credential', [
            'type' => 'credential_unblock',
            'username' => $username
        ]);
        
        // Riordina gli indici
        $blockedCreds = array_values($blockedCreds);
        
        $result = file_put_contents($blockedCredsFile, json_encode($blockedCreds, JSON_PRETTY_PRINT), LOCK_EX);
        
        // NUOVO: Aggiungi alle esclusioni per prevenire ri-blocco automatico
        if ($result !== false) {
            addUsernameToAutoBlockExclusions($username, "Sbloccato manualmente dall'amministratore tramite interfaccia web");
            error_log("UNBLOCK CREDENTIALS - Username '$username' sbloccato e aggiunto alle esclusioni auto-block");
        }
        
        return $result !== false;
    }
    
    return false;
}

function unblockIP($ip, $section) {
    $blockedIPsFile = '../../data/blocked_ips.json';
    
    if (!file_exists($blockedIPsFile)) {
        return false;
    }
    
    $blockedIPs = json_decode(file_get_contents($blockedIPsFile), true);
    if (!$blockedIPs) {
        return false;
    }
    
    $originalCount = count($blockedIPs);
    $blockedIPs = array_filter($blockedIPs, function($block) use ($ip, $section) {
        return !($block['ip'] === $ip && $block['section'] === $section);
    });
    
    if (count($blockedIPs) < $originalCount) {
        // Registra la rimozione
        logRemovalAction('ip', [
            'type' => 'ip_unblock',
            'ip' => $ip,
            'section' => $section
        ]);
        
        // Riordina gli indici
        $blockedIPs = array_values($blockedIPs);
        
        $result = file_put_contents($blockedIPsFile, json_encode($blockedIPs, JSON_PRETTY_PRINT), LOCK_EX);
        
        // NUOVO: Aggiungi alle esclusioni per prevenire ri-blocco automatico
        if ($result !== false) {
            addIPToAutoBlockExclusions($ip, $section, "Sbloccato manualmente dall'amministratore tramite interfaccia web");
            error_log("UNBLOCK IP - IP '$ip' per sezione '$section' sbloccato e aggiunto alle esclusioni auto-block");
        }
        
        return $result !== false;
    }
    
    return false;
}

// Gestione azioni POST
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'block_ip' && !empty($_POST['ip']) && !empty($_POST['section'])) {
        $originalIp = $_POST['ip'];
        $section = $_POST['section'];
        $blockReason = trim($_POST['block_reason'] ?? '');
        $ip = filter_var($_POST['ip'], FILTER_VALIDATE_IP);
        
        if ($ip && !empty($blockReason) && blockIPForSection($ip, $section, $blockReason)) {
            $message = "IP $ip bloccato per la sezione $section con successo. Motivo: $blockReason";
            $messageType = 'success';
            // Reset del flag per permettere un nuovo controllo auto-redirect
            unset($_SESSION['alerts_checked_today']);
        } else {
            if (!$ip) {
                $message = "Errore: IP $originalIp non è valido.";
            } elseif (empty($blockReason)) {
                $message = "Errore: È richiesta una motivazione per il blocco.";
            } else {
                $message = "Errore nel bloccare l'IP $ip per la sezione $section.";
            }
            $messageType = 'error';
        }
    } elseif ($action === 'mark_reviewed') {
        $fingerprint = $_POST['fingerprint'] ?? '';
        $ip = $_POST['ip'] ?? '';
        $section = $_POST['section'] ?? '';
        $accessCount = $_POST['access_count'] ?? 0;
        $date = $_POST['date'] ?? '';
        $reviewReason = trim($_POST['review_reason'] ?? '');
        
        if (!empty($fingerprint) && !empty($ip) && !empty($section) && !empty($date) && !empty($reviewReason)) {
            if (markAlertAsReviewed($fingerprint, $ip, $section, $accessCount, $reviewReason, $date)) {
                $message = "Alert marcato come visionato. Giustificazione: $reviewReason";
                $messageType = 'success';
                // Reset del flag per permettere un nuovo controllo auto-redirect
                unset($_SESSION['alerts_checked_today']);
            } else {
                $message = "Errore nel marcare l'alert come visionato.";
                $messageType = 'error';
            }
        } else {
            $message = "Errore: Tutti i campi sono obbligatori per marcare come visionato.";
            $messageType = 'error';
        }
    } elseif ($action === 'unmark_reviewed') {
        $fingerprint = $_POST['fingerprint'] ?? '';
        $ip = $_POST['ip'] ?? '';
        $section = $_POST['section'] ?? '';
        $date = $_POST['date'] ?? '';
        
        if (!empty($fingerprint) && !empty($ip) && !empty($section) && !empty($date)) {
            if (unmarkAlertAsReviewed($fingerprint, $ip, $section, $date)) {
                $message = "Alert rimosso dai visionati.";
                $messageType = 'success';
            } else {
                $message = "Errore nel rimuovere l'alert dai visionati.";
                $messageType = 'error';
            }
        } else {
            $message = "Errore: Tutti i campi sono obbligatori per rimuovere l'alert dai visionati.";
            $messageType = 'error';
        }
    } elseif ($action === 'block_credentials') {
        $username = $_POST['username'] ?? '';
        $blockReason = trim($_POST['block_reason'] ?? '');
        
        if (!empty($username) && !empty($blockReason)) {
            // Prima controlla se le credenziali sono già bloccate
            if (isCredentialsBlocked($username)) {
                $message = "Le credenziali di '$username' sono già state bloccate in precedenza.";
                $messageType = 'error';
            } else {
                if (blockCredentials($username, $blockReason)) {
                    $message = "Credenziali di '$username' bloccate con successo. Motivo: $blockReason";
                    $messageType = 'success';
                    // Reset del flag per permettere un nuovo controllo auto-redirect
                    unset($_SESSION['alerts_checked_today']);
                } else {
                    $message = "Errore nel bloccare le credenziali di '$username'. Verificare i log per dettagli.";
                    $messageType = 'error';
                }
            }
        } else {
            $message = "Errore: Username e motivazione sono obbligatori per il blocco credenziali.";
            $messageType = 'error';
        }
    } elseif ($action === 'mark_credential_reviewed') {
        $fingerprint = $_POST['fingerprint'] ?? '';
        $username = $_POST['username'] ?? '';
        $date = $_POST['date'] ?? '';
        $reviewReason = trim($_POST['review_reason'] ?? '');
        
        if (!empty($fingerprint) && !empty($username) && !empty($date) && !empty($reviewReason)) {
            if (markCredentialAlertAsReviewed($fingerprint, $username, $reviewReason, $date)) {
                $message = "Alert di condivisione credenziali per '$username' marcato come visionato. Giustificazione: $reviewReason";
                $messageType = 'success';
                // Reset del flag per permettere un nuovo controllo auto-redirect
                unset($_SESSION['alerts_checked_today']);
            } else {
                $message = "Errore nel marcare l'alert delle credenziali come visionato.";
                $messageType = 'error';
            }
        } else {
            $message = "Errore: Tutti i campi sono obbligatori per marcare l'alert credenziali come visionato.";
            $messageType = 'error';
        }
    } elseif ($action === 'unmark_credential_reviewed') {
        $fingerprint = $_POST['fingerprint'] ?? '';
        $username = $_POST['username'] ?? '';
        $date = $_POST['date'] ?? '';
        
        if (!empty($fingerprint) && !empty($username) && !empty($date)) {
            if (unmarkCredentialAlertAsReviewed($fingerprint, $username, $date)) {
                $message = "Alert di condivisione credenziali per '$username' rimosso dai visionati.";
                $messageType = 'success';
            } else {
                $message = "Errore nel rimuovere l'alert delle credenziali dai visionati.";
                $messageType = 'error';
            }
        } else {
            $message = "Errore: Tutti i campi sono obbligatori per rimuovere l'alert credenziali dai visionati.";
            $messageType = 'error';
        }
    } elseif ($action === 'unblock_credentials') {
        $username = $_POST['username'] ?? '';
        
        if (!empty($username)) {
            if (unblockCredentials($username)) {
                $message = "Credenziali di '$username' sbloccate con successo e aggiunte alle esclusioni (non saranno più bloccate automaticamente).";
                $messageType = 'success';
            } else {
                $message = "Errore nello sbloccare le credenziali di '$username'.";
                $messageType = 'error';
            }
        } else {
            $message = "Errore: Username obbligatorio per lo sblocco.";
            $messageType = 'error';
        }
    } elseif ($action === 'unblock_ip') {
        $ip = $_POST['ip'] ?? '';
        $section = $_POST['section'] ?? '';
        
        if (!empty($ip) && !empty($section)) {
            if (unblockIP($ip, $section)) {
                $message = "IP '$ip' sbloccato per la sezione '$section' con successo e aggiunto alle esclusioni (non sarà più bloccato automaticamente).";
                $messageType = 'success';
            } else {
                $message = "Errore nello sbloccare l'IP '$ip' per la sezione '$section'.";
                $messageType = 'error';
            }
        } else {
            $message = "Errore: IP e sezione obbligatori per lo sblocco.";
            $messageType = 'error';
        }
    }
    
    // Implementa il pattern PRG (Post-Redirect-Get) per evitare risubmit al refresh
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($message)) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $messageType;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Recupera e pulisce i messaggi flash
$message = '';
$messageType = '';
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Rileva accessi anomali usando le funzioni aggiornate per 30 giorni
$logFilePath = $_SERVER['DOCUMENT_ROOT'] . '/log/data/access_log.json';

// Debug: verifica che il file esista
if (!file_exists($logFilePath)) {
    $anomalies = [];
    $alertCount = 0;
} else {
    // Usa getActiveAlertCount() per garantire coerenza con tutte le altre pagine
    $alertCount = getActiveAlertCount();
    
    // Ottieni le anomalie separatamente per la visualizzazione
    $anomalies = detectAnomalousAccessForAlerts($logFilePath);
}

// Controlla se siamo arrivati dal login
$fromLogin = isset($_GET['from_login']) && $_GET['from_login'] === '1';

renderHtmlHead('🚨 Alert di Sicurezza - Pantedu Analytics');
?>
    <style>
        .alert-badge {
            display: inline-block;
            background: #dc3545;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: bold;
            margin-left: 10px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .alert-card.reviewed {
            border-left-color: #28a745;
        }

        .reviewed-badge {
            background: #28a745;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7em;
            font-weight: bold;
            margin-left: 10px;
        }

        /* CSS per le card collassate - applica max-height solo se ci sono anomalie */
        <?php if (!empty($anomalies)): ?>
        .alert-card.collapsed {
            max-height: 80px !important;
            overflow: hidden !important;
            transition: none;
        }
        <?php endif; ?>

        /* Riduce il margin-bottom del header quando la card è compressa */
        .alert-card.collapsed .card-header {
            margin-bottom: 0px !important;
        }

        /* Nascondi i dettagli quando la card è collassata */
        .alert-card.collapsed .alert-details {
            display: none !important;
        }

        /* Nascondi i bottoni delle azioni quando la card è collassata - SOLO quelli non nel header */
        .alert-card.collapsed .action-buttons:not(.card-header .action-buttons) {
            display: none !important;
        }

        /* DEFAULT: nascondi solo i dettagli */
        .alert-details {
            display: none !important;
        }

        /* I pulsanti nel card-header devono essere sempre visibili */
        .card-header .action-buttons {
            display: flex !important;
            gap: 10px !important;
            align-items: center !important;
        }

        .card-header .btn {
            display: inline-block !important;
            visibility: visible !important;
            opacity: 1 !important;
            /* Stili di base per i pulsanti */
            padding: 8px 16px !important;
            border-radius: 4px !important;
            text-decoration: none !important;
            cursor: pointer !important;
            border: 1px solid transparent !important;
        }

        .btn-primary {
            background-color: #007bff !important;
            color: white !important;
        }

        .btn-info {
            background-color: #17a2b8 !important;
            color: white !important;
        }

        /* Nascondi solo i pulsanti di azione dentro .actions-section */
        .actions-section,
        .alert-card > .action-buttons:not(.card-header .action-buttons) {
            display: none !important;
        }

        /* Mostra quando la card è espansa */
        .alert-card.expanded .alert-details {
            display: grid !important;
        }

        .alert-card.expanded .actions-section,
        .alert-card.expanded > .action-buttons:not(.card-header .action-buttons) {
            display: block !important;
        }

        .alert-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 15px 0;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        /* Dettagli visibili quando la card è espansa */
        .alert-card.expanded .alert-details {
            display: grid !important;
            opacity: 1;
            visibility: visible;
        }

        /* Dettagli nascosti quando la card è collassata */
        .alert-card.collapsed .alert-details {
            display: none !important;
            opacity: 0;
            visibility: hidden;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: flex-end;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        /* Bottoni visibili quando la card è espansa */
        .alert-card.expanded .action-buttons {
            display: flex !important;
            opacity: 1;
            visibility: visible;
        }

        /* Bottoni nascosti quando la card è collassata - SOLO quelli non nel header */
        .alert-card.collapsed .action-buttons:not(.card-header .action-buttons) {
            display: none !important;
            opacity: 0;
            visibility: hidden;
        }

        .expand-toggle {
            cursor: pointer !important;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 0.8em;
            margin-left: 10px;
            transition: all 0.3s ease;
            font-weight: 500;
            pointer-events: auto !important;
            z-index: 10;
            position: relative;
        }

        .expand-toggle:hover {
            background: #0056b3 !important;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .expand-toggle:active {
            transform: translateY(0);
            background: #004085 !important;
        }

        /* Stile per il bottone "Vedi Dettagli" coerente con badge e pulsanti */
        .details-btn {
            display: inline-block;
            background: #6f42c1;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 10px;
        }

        .details-btn:hover {
            background: #5a35a3;
            color: white;
            text-decoration: none;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .details-btn:active {
            transform: translateY(0);
            background: #4c2d92;
        }

        .alert-card {
            position: relative;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border-left: 5px solid #dc3545;
            /* RIMOSSA la transizione che interferisce con JavaScript */
            overflow: hidden;
        }

        .alert-high {
            border-left-color: #dc3545;
            background: rgba(220, 53, 69, 0.1);
        }

        .alert-medium {
            border-left-color: #ffc107;
            background: rgba(255, 193, 7, 0.1);
        }

        .alert-low {
            border-left-color: #17a2b8;
            background: rgba(23, 162, 184, 0.1);
        }

        .risk-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
            color: white;
        }

        .risk-high { background: #dc3545; }
        .risk-medium { background: #ffc107; color: #333; }
        .risk-low { background: #17a2b8; }

        .alert-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }

        .detail-item {
            background: rgba(0,0,0,0.05);
            padding: 10px;
            border-radius: 6px;
        }

        .detail-label {
            font-size: 0.8em;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
        }

        .detail-value {
            font-weight: 500;
            margin-top: 5px;
        }

        .action-form {
            background: rgba(0,0,0,0.05);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .action-form label {
            font-weight: 600;
            color: #555;
        }

        .action-form input[type="text"] {
            width: 100%;
            max-width: 350px;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9em;
            transition: border-color 0.3s ease;
        }

        .action-form input[type="text"]:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background: #0056b3;
        }

        .message {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .message.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .message.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .no-alerts {
            text-align: center;
            padding: 40px;
            color: #28a745;
        }

        .no-alerts .icon {
            font-size: 4em;
            margin-bottom: 20px;
        }

        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #007bff;
        }

        .stat-label {
            font-size: 0.9em;
            color: #666;
            margin-top: 5px;
        }
        
        .header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            padding: 20px; 
            text-align: center;
        }
        .security-alert {
            background: #dc3545;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: bold;
            margin-right: 15px;
            animation: pulse 2s infinite;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .security-alert:hover {
            background: #c82333;
            color: white;
            text-decoration: none;
        }
        
        /* Container per i bottoni laterali di rimozione */
        .lateral-remove-btn {
            position: absolute;
            left: -15px;
            top: 50%;
            transform: translateY(-50%);
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 5;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(220, 53, 69, 0.4);
        }
        
        .lateral-remove-btn:hover {
            background: #c82333;
            transform: translateY(-50%) scale(1.1);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.6);
        }
    </style>
</head>
<body>
    <?php renderNavbar('security_alerts'); ?>
    
    <?php renderHeader('🚨 Alert di Sicurezza (30 giorni)', 'Monitoraggio accessi anomali - Ultimi 30 giorni'); ?>

    <?php renderContainerStart(); ?>

        
        <?php if ($fromLogin): ?>
            <?php if ($alertCount > 0): ?>
                <p style="background: rgba(255,193,7,0.8); color: #333; padding: 10px; border-radius: 8px; margin: 10px 0;">
                    ⚠️ <strong>Accesso Amministratore Rilevato:</strong> Sono stati trovati <?= $alertCount ?> alert di sicurezza attivi non visionati. Ti preghiamo di rivederli prima di continuare.
                </p>
            <?php else: ?>
                <p style="background: rgba(40,167,69,0.8); color: white; padding: 10px; border-radius: 8px; margin: 10px 0;">
                    ✅ <strong>Tutti gli alert sono stati gestiti!</strong> Non ci sono più alert attivi che richiedono attenzione.
                </p>
            <?php endif; ?>
        <?php endif; ?>

    <!-- Pulsante Configurazione -->
    <div style="text-align: center; margin: 20px 0;">
        <a href="config_manager.php" style="background: #28a745; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-size: 16px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-right: 10px;">
            ⚙️ Configurazione Alert
        </a>
        <a href="exclusions_manager.php" style="background: #6f42c1; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-size: 16px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            🛡️ Gestione Esclusioni
        </a>
    </div>

        <?php if ($message): ?>
            <div class="message <?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="summary-stats">
            <div class="stat-card">
                <div class="stat-number"><?= $alertCount ?></div>
                <div class="stat-label">Alert Attivi</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php
                    $activeAnomalies = array_filter($anomalies, function($a) {
                        if ($a['is_reviewed']) return false;
                        if ($a['type'] === 'credential_sharing') {
                            return !isCredentialsBlocked($a['username']);
                        } elseif ($a['type'] === 'excessive_access') {
                            return !isIPBlocked($a['ip'] ?? '', $a['section'] ?? '');
                        }
                        return true;
                    });
                    echo count(array_filter($activeAnomalies, fn($a) => $a['risk_level'] === 'HIGH'));
                ?></div>
                <div class="stat-label">Rischio Alto</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php
                    echo count(array_filter($activeAnomalies, fn($a) => $a['risk_level'] === 'MEDIUM'));
                ?></div>
                <div class="stat-label">Rischio Medio</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php
                    echo count(array_filter($activeAnomalies, fn($a) => $a['risk_level'] === 'LOW'));
                ?></div>
                <div class="stat-label">Rischio Basso</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php
                    echo count(array_filter($activeAnomalies, fn($a) => $a['type'] === 'credential_sharing'));
                ?></div>
                <div class="stat-label">Condivisione Credenziali</div>
            </div>
        </div>

        <?php if (empty($anomalies)): ?>
            <div class="alert-card no-alerts">
                <div class="icon">✅</div>
                <h2>Nessun accesso anomalo rilevato</h2>
                <p>Tutti gli accessi degli ultimi 30 giorni rientrano nei parametri normali (≤ 2 accessi per utente/sezione - modalità test).</p>
            </div>
        <?php else: ?>
            <?php
            // Separa le anomalie in attive e inattive
            $activeAnomaliesCards = [];
            $inactiveAnomaliesCards = [];
            
            foreach ($anomalies as $anomaly) {
                $isInactive = false;
                $inactiveReason = '';
                
                if ($anomaly['is_reviewed']) {
                    $isInactive = true;
                    $inactiveReason = 'reviewed';
                } elseif ($anomaly['type'] === 'credential_sharing' && isCredentialsBlocked($anomaly['username'])) {
                    $isInactive = true;
                    $inactiveReason = 'blocked_credentials';
                } elseif ($anomaly['type'] === 'excessive_access' && isIPBlocked($anomaly['ip'] ?? '', $anomaly['section'] ?? '')) {
                    $isInactive = true;
                    $inactiveReason = 'blocked_ip';
                }
                
                $anomaly['inactive_reason'] = $inactiveReason;
                
                if ($isInactive) {
                    $inactiveAnomaliesCards[] = $anomaly;
                } else {
                    $activeAnomaliesCards[] = $anomaly;
                }
            }
            ?>
            
            <!-- Container Alert Attivi -->
            <?php if (!empty($activeAnomaliesCards)): ?>
                <div class="alert-container active-alerts">
                    <div style="background: #dc3545; color: white; padding: 15px; margin: 20px 0 15px 0; border-radius: 8px; font-size: 1.3em; display: flex; justify-content: space-between; align-items: center;">
                        <span>🚨 Alert Attivi (<?= count($activeAnomaliesCards) ?>)</span>
                        <div style="display: flex; gap: 8px; align-items: center; font-size: 0.8em;">
                            <span>Ordina per:</span>
                            <select id="active-sort-field" onchange="sortCards('active-alerts')" style="padding: 4px 8px; border: none; border-radius: 4px; background: white; color: #333;">
                                <option value="ip">IP</option>
                                <option value="username">Username</option>
                                <option value="type">Tipologia</option>
                                <option value="first_date">Prima data accesso</option>
                                <option value="last_date">Ultima data accesso</option>
                                <option value="risk">Rischio</option>
                            </select>
                            <select id="active-sort-order" onchange="sortCards('active-alerts')" style="padding: 4px 8px; border: none; border-radius: 4px; background: white; color: #333;">
                                <option value="asc">↑ Crescente</option>
                                <option value="desc">↓ Decrescente</option>
                            </select>
                        </div>
                    </div>
                    <div class="cards-container" id="active-alerts-cards">
                    <?php foreach ($activeAnomaliesCards as $anomaly): ?>
                        <?php include 'alert_card_template.php'; ?>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Container Alert Visionati/Bloccati -->
            <?php if (!empty($inactiveAnomaliesCards)): ?>
                <div class="alert-container inactive-alerts" style="margin-top: 40px;">
                    <div style="background: #6c757d; color: white; padding: 15px; margin: 20px 0 15px 0; border-radius: 8px; font-size: 1.3em; display: flex; justify-content: space-between; align-items: center;">
                        <span>👁️ Alert Visionati/Bloccati (<?= count($inactiveAnomaliesCards) ?>)</span>
                        <div style="display: flex; gap: 8px; align-items: center; font-size: 0.8em;">
                            <span>Ordina per:</span>
                            <select id="inactive-sort-field" onchange="sortCards('inactive-alerts')" style="padding: 4px 8px; border: none; border-radius: 4px; background: white; color: #333;">
                                <option value="ip">IP</option>
                                <option value="username">Username</option>
                                <option value="type">Tipologia</option>
                                <option value="first_date">Prima data accesso</option>
                                <option value="last_date">Ultima data accesso</option>
                                <option value="risk">Rischio</option>
                            </select>
                            <select id="inactive-sort-order" onchange="sortCards('inactive-alerts')" style="padding: 4px 8px; border: none; border-radius: 4px; background: white; color: #333;">
                                <option value="asc">↑ Crescente</option>
                                <option value="desc">↓ Decrescente</option>
                            </select>
                        </div>
                    </div>
                    <div class="cards-container" id="inactive-alerts-cards">
                    <?php foreach ($inactiveAnomaliesCards as $anomaly): ?>
                        <?php include 'alert_card_template.php'; ?>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        function confirmBlockIP(section, form) {
            const reason = form.querySelector('input[name="block_reason"]').value.trim();
            if (!reason) {
                alert('È necessario inserire una motivazione per il blocco.');
                return false;
            }
            return confirm(`Sei sicuro di voler bloccare questo IP per la sezione ${section}?\n\nMotivo: ${reason}`);
        }

        function confirmBlockCredentials(username, form) {
            const reason = form.querySelector('input[name="block_reason"]').value.trim();
            if (!reason) {
                alert('È necessario inserire una motivazione per il blocco.');
                return false;
            }
            return confirm(`Sei sicuro di voler bloccare le credenziali di ${username}?\n\nMotivo: ${reason}`);
        }

        function confirmMarkReviewed(form) {
            const reason = form.querySelector('input[name="review_reason"]').value.trim();
            if (!reason) {
                alert('È necessario inserire una giustificazione per marcare come visionato.');
                return false;
            }
            return confirm(`Sei sicuro di voler marcare questo alert come visionato?\n\nGiustificazione: ${reason}`);
        }

        function confirmMarkCredentialReviewed(form) {
            const reason = form.querySelector('input[name="review_reason"]').value.trim();
            if (!reason) {
                alert('È necessario inserire una giustificazione per marcare come visionato.');
                return false;
            }
            return confirm(`Sei sicuro di voler marcare questo alert di condivisione credenziali come visionato?\n\nGiustificazione: ${reason}`);
        }

        function toggleAlert(cardId) {
            const card = document.getElementById(cardId);
            if (!card) {
                console.error('Card not found:', cardId);
                return;
            }
            
            const details = card.querySelector('.alert-details');
            const actionSections = card.querySelectorAll('.actions-section, .action-form');
            const toggleButton = card.querySelector('.toggle-text');
            
            if (card.classList.contains('expanded')) {
                // COLLASSA
                card.classList.remove('expanded');
                card.classList.add('collapsed');
                if (details) details.style.display = 'none';
                actionSections.forEach(section => {
                    section.style.display = 'none';
                });
                if (toggleButton) toggleButton.textContent = '▼ Espandi';
            } else {
                // ESPANDI
                card.classList.remove('collapsed');
                card.classList.add('expanded');
                if (details) details.style.display = 'grid';
                actionSections.forEach(section => {
                    section.style.display = 'block';
                });
                if (toggleButton) toggleButton.textContent = '▲ Comprimi';
            }
        }

        // Funzione per ordinare le card
        function sortCards(containerType) {
            console.log(`🔄 Sorting ${containerType}`);
            
            const container = document.getElementById(containerType + '-cards');
            if (!container) {
                console.error('Container not found:', containerType + '-cards');
                return;
            }
            
            // Ottieni i valori dai dropdown - correggi gli ID
            const fieldId = containerType === 'active-alerts' ? 'active-sort-field' : 'inactive-sort-field';
            const orderId = containerType === 'active-alerts' ? 'active-sort-order' : 'inactive-sort-order';
            
            const sortField = document.getElementById(fieldId);
            const sortOrderEl = document.getElementById(orderId);
            
            if (!sortField || !sortOrderEl) {
                console.error('Sort dropdowns not found:', fieldId, orderId);
                return;
            }
            
            const sortBy = sortField.value;
            const sortOrder = sortOrderEl.value;
            
            console.log(`📋 Sorting by ${sortBy} in ${sortOrder} order`);
            
            const cards = Array.from(container.querySelectorAll('.alert-card'));
            console.log(`📋 Found ${cards.length} cards to sort`);
            
            cards.sort((a, b) => {
                let valueA, valueB;
                
                switch (sortBy) {
                    case 'ip':
                        // Ottieni IP dal titolo
                        const titleA = a.querySelector('h3').textContent;
                        const titleB = b.querySelector('h3').textContent;
                        
                        console.log(`🔍 Card A title: "${titleA}"`);
                        console.log(`🔍 Card B title: "${titleB}"`);
                        
                        if (titleA.includes('🔑')) { 
                            // Credenziali condivise - cerca nella parentesi per conteggio IP
                            const matchA = titleA.match(/\((\d+)\s+IP\)/);
                            valueA = matchA ? parseInt(matchA[1]) : 0; // Ordina per numero di IP
                            console.log(`📊 Credential sharing A: ${matchA ? matchA[1] : '0'} IP`);
                        } else { 
                            // Accesso eccessivo - IP specifico nel titolo
                            const matchA = titleA.match(/\(([^)]+)\)$/);
                            valueA = matchA ? matchA[1].trim() : '';
                            console.log(`📍 Excessive access A: "${valueA}"`);
                        }
                        
                        if (titleB.includes('🔑')) {
                            const matchB = titleB.match(/\((\d+)\s+IP\)/);
                            valueB = matchB ? parseInt(matchB[1]) : 0;
                            console.log(`📊 Credential sharing B: ${matchB ? matchB[1] : '0'} IP`);
                        } else {
                            const matchB = titleB.match(/\(([^)]+)\)$/);
                            valueB = matchB ? matchB[1].trim() : '';
                            console.log(`📍 Excessive access B: "${valueB}"`);
                        }
                        
                        console.log(`🔢 Final values - A: ${valueA}, B: ${valueB}`);
                        break;
                        
                    case 'username':
                        valueA = a.querySelector('h3').textContent.split(':')[1]?.split('(')[0]?.trim() || '';
                        valueB = b.querySelector('h3').textContent.split(':')[1]?.split('(')[0]?.trim() || '';
                        break;
                        
                    case 'type':
                        valueA = a.querySelector('h3').textContent.includes('🔑') ? 'credential_sharing' : 'excessive_access';
                        valueB = b.querySelector('h3').textContent.includes('🔑') ? 'credential_sharing' : 'excessive_access';
                        break;
                        
                    case 'first_date':
                        const firstDateA = a.querySelector('.detail-label').textContent === 'Primo accesso' ? 
                            a.querySelector('.detail-label').nextElementSibling?.textContent : '';
                        const firstDateB = b.querySelector('.detail-label').textContent === 'Primo accesso' ? 
                            b.querySelector('.detail-label').nextElementSibling?.textContent : '';
                        
                        // Cerca l'elemento con label "Primo accesso"
                        const firstElA = Array.from(a.querySelectorAll('.detail-label')).find(el => el.textContent === 'Primo accesso');
                        const firstElB = Array.from(b.querySelectorAll('.detail-label')).find(el => el.textContent === 'Primo accesso');
                        
                        valueA = firstElA ? new Date(firstElA.nextElementSibling.textContent.split(' ')[0].split('/').reverse().join('-')) : new Date(0);
                        valueB = firstElB ? new Date(firstElB.nextElementSibling.textContent.split(' ')[0].split('/').reverse().join('-')) : new Date(0);
                        break;
                        
                    case 'last_date':
                        const lastElA = Array.from(a.querySelectorAll('.detail-label')).find(el => el.textContent === 'Ultimo accesso');
                        const lastElB = Array.from(b.querySelectorAll('.detail-label')).find(el => el.textContent === 'Ultimo accesso');
                        
                        valueA = lastElA ? new Date(lastElA.nextElementSibling.textContent.split(' ')[0].split('/').reverse().join('-')) : new Date(0);
                        valueB = lastElB ? new Date(lastElB.nextElementSibling.textContent.split(' ')[0].split('/').reverse().join('-')) : new Date(0);
                        break;
                        
                    case 'risk':
                        const riskOrder = { 'high': 3, 'medium': 2, 'low': 1 };
                        valueA = riskOrder[a.className.match(/alert-(high|medium|low)/)?.[1] || 'low'];
                        valueB = riskOrder[b.className.match(/alert-(high|medium|low)/)?.[1] || 'low'];
                        break;
                        
                    default:
                        return 0;
                }
                
                let result;
                if (sortBy === 'first_date' || sortBy === 'last_date' || sortBy === 'risk') {
                    result = valueA - valueB; // Confronto numerico
                } else if (sortBy === 'ip') {
                    // Gestione speciale per IP
                    if (typeof valueA === 'number' && typeof valueB === 'number') {
                        result = valueA - valueB; // Entrambi numeri (credenziali condivise)
                    } else if (typeof valueA === 'string' && typeof valueB === 'string') {
                        // Entrambi stringhe IP - ordina per ottetti
                        const ipToNum = (ip) => {
                            const parts = ip.split('.');
                            return parts.length === 4 ? 
                                (parseInt(parts[0]) << 24) + (parseInt(parts[1]) << 16) + (parseInt(parts[2]) << 8) + parseInt(parts[3]) : 0;
                        };
                        result = ipToNum(valueA) - ipToNum(valueB);
                    } else {
                        // Tipi misti: metti numeri prima delle stringhe
                        result = typeof valueA === 'number' ? -1 : 1;
                    }
                } else {
                    result = valueA.toString().localeCompare(valueB.toString()); // Confronto stringhe
                }
                
                // Inverti se decrescente - SPECIALE per IP e TIPOLOGIA: inverti la logica
                if (sortBy === 'ip' || sortBy === 'type') {
                    // Per IP e TIPOLOGIA: "crescente" inverte l'ordine normale
                    return sortOrder === 'asc' ? -result : result;
                } else {
                    // Per tutto il resto: normale
                    return sortOrder === 'desc' ? -result : result;
                }
            });
            
            // Rimuovi e reinserisci le card nell'ordine corretto
            cards.forEach(card => container.removeChild(card));
            cards.forEach(card => container.appendChild(card));
            
            console.log(`✅ Sorted ${cards.length} cards by ${sortBy} (${sortOrder})`);
        }

        // Auto-refresh ogni 5 minuti se necessario
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Initializing cards...');
            
            // Inizializza tutte le card come collapsed
            const allCards = document.querySelectorAll('.alert-card');
            console.log('📋 Found cards:', allCards.length);
            
            allCards.forEach((card, index) => {
                console.log(`🔄 Processing card ${index + 1}: ${card.id}`);
                
                if (!card.classList.contains('expanded') && !card.classList.contains('collapsed')) {
                    card.classList.add('collapsed');
                }
                
                // Forza i pulsanti del header ad essere visibili
                const headerButtons = card.querySelector('.card-header .action-buttons');
                if (headerButtons) {
                    console.log(`✅ Found header buttons for card ${index + 1}`);
                    headerButtons.style.display = 'flex';
                    headerButtons.style.visibility = 'visible';
                    headerButtons.style.opacity = '1';
                    
                    // Forza ogni pulsante singolarmente
                    const buttons = headerButtons.querySelectorAll('.btn');
                    buttons.forEach((btn, btnIndex) => {
                        console.log(`🔘 Making button ${btnIndex + 1} visible`);
                        btn.style.display = 'inline-block';
                        btn.style.visibility = 'visible';
                        btn.style.opacity = '1';
                    });
                } else {
                    console.log(`❌ No header buttons found for card ${index + 1}`);
                }
                
                // Nascondi i dettagli e i pulsanti di azione per le card collapsed
                if (card.classList.contains('collapsed')) {
                    const details = card.querySelector('.alert-details');
                    const actionSections = card.querySelectorAll('.actions-section, .action-form');
                    
                    if (details) details.style.display = 'none';
                    actionSections.forEach(section => {
                        section.style.display = 'none';
                    });
                }
            });
            
            console.log('✅ Card initialization complete');
            
            // Inizializza i container per l'ordinamento
            const activeContainer = document.getElementById('active-alerts-cards');
            const inactiveContainer = document.getElementById('inactive-alerts-cards');
            
            if (activeContainer) {
                const activeCards = document.querySelectorAll('.active-alerts .alert-card');
                activeCards.forEach(card => activeContainer.appendChild(card));
                console.log(`📦 Moved ${activeCards.length} active cards to sortable container`);
            }
            
            if (inactiveContainer) {
                const inactiveCards = document.querySelectorAll('.inactive-alerts .alert-card');
                inactiveCards.forEach(card => inactiveContainer.appendChild(card));
                console.log(`📦 Moved ${inactiveCards.length} inactive cards to sortable container`);
            }
            
            setTimeout(() => {
                window.location.reload();
            }, 300000);
        });
    </script>

    <?php renderContainerEnd(); ?>
</body>
</html>

