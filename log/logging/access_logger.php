<?php
/**
 * Sistema di Logging degli Accessi Utenti
 * Traccia gli accessi per indirizzo e classe basandosi sul linkref
 */

class AccessLogger {
    
    private $logFile;
    private $statsFile;
    
    // Mappatura degli indirizzi
    private $instituteMap = [
        'ar' => 'Artistico',
        'sc' => 'Scientifico',
        'cl' => 'Classico',
        'li' => 'Linguistico',
        'af' => 'Amministrazione e Finanza'
    ];
    
    // Mappatura delle classi
    private $classMap = [
        '1s' => 'Prima Standard',
        '2s' => 'Seconda Standard', 
        '3s' => 'Terza Standard',
        '4s' => 'Quarta Standard',
        '5s' => 'Quinta Standard',
        '1b' => 'Prima Bilinguismo',
        '2b' => 'Seconda Bilinguismo',
        '3b' => 'Terza Bilinguismo',
        '4b' => 'Quarta Bilinguismo',
        '5b' => 'Quinta Bilinguismo'
    ];
    
    public function __construct() {
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/log/data';
        $this->logFile = $logDir . '/access_log.json';
        $this->statsFile = $logDir . '/access_stats.json';
        
        // Crea la cartella data se non esiste
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Crea i file se non esistono
        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, json_encode([], JSON_PRETTY_PRINT));
        }
        if (!file_exists($this->statsFile)) {
            file_put_contents($this->statsFile, json_encode([
                'daily_stats' => [],
                'user_stats' => [],
                'institute_stats' => [],
                'class_stats' => []
            ], JSON_PRETTY_PRINT));
        }
    }
    
    /**
     * Inizializza i file e le cartelle necessarie
     */
    private function initializeFiles() {
        $logDir = dirname($this->logFile);
        
        // Log di debug per verificare i percorsi
        error_log("AccessLogger: Tentativo di creare cartella: " . $logDir);
        error_log("AccessLogger: Percorso logFile: " . $this->logFile);
        error_log("AccessLogger: Percorso statsFile: " . $this->statsFile);
        
        // Crea la cartella data se non esiste
        if (!is_dir($logDir)) {
            if (mkdir($logDir, 0755, true)) {
                error_log("AccessLogger: Cartella creata con successo: " . $logDir);
            } else {
                error_log("AccessLogger: ERRORE nella creazione cartella: " . $logDir);
            }
        } else {
            error_log("AccessLogger: Cartella già esistente: " . $logDir);
        }
        
        // Crea i file se non esistono
        if (!file_exists($this->logFile)) {
            if (file_put_contents($this->logFile, json_encode([], JSON_PRETTY_PRINT))) {
                error_log("AccessLogger: File log creato: " . $this->logFile);
            } else {
                error_log("AccessLogger: ERRORE creazione file log: " . $this->logFile);
            }
        }
        if (!file_exists($this->statsFile)) {
            if (file_put_contents($this->statsFile, json_encode([
                'daily_stats' => [],
                'user_stats' => [],
                'institute_stats' => [],
                'class_stats' => []
            ], JSON_PRETTY_PRINT))) {
                error_log("AccessLogger: File stats creato: " . $this->statsFile);
            } else {
                error_log("AccessLogger: ERRORE creazione file stats: " . $this->statsFile);
            }
        }
    }
    
    /**
     * Registra un accesso utente
     */
    public function logAccess($username, $userRole, $linkref = null, $userAgent = null, $actionType = 'login') {
        // Debug log all'inizio
        error_log("AccessLogger::logAccess STARTED - Username: $username, Role: $userRole, Action: $actionType");
        
        // Verifica che i file esistano prima di procedere
        if (!file_exists($this->logFile) || !file_exists($this->statsFile)) {
            error_log("AccessLogger::logAccess - Files not found, initializing...");
            $this->initializeFiles();
        }
        
        // Se è un logout, registra solo nel debug.log e non negli accessi
        if ($actionType === 'logout') {
            error_log("AccessLogger::logAccess - Processing LOGOUT for: $username");
            $this->logLogout($username, $userRole, $linkref);
            return null;
        }
        
        error_log("AccessLogger::logAccess - Processing LOGIN for: $username");
        
        $accessData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'date' => date('Y-m-d'),
            'time' => date('H:i:s'),
            'username' => $username,
            'role' => $userRole,
            'linkref' => $linkref,
            'redirect_page' => $linkref, // La pagina di redirect è il linkref stesso
            'user_agent' => $userAgent,
            'ip_address' => $this->getUserIP(),
            'session_id' => session_id(),
            'action_type' => $actionType
        ];
        
        // Analizza il linkref per estrarre indirizzo e classe
        if ($linkref) {
            $pathInfo = $this->parsePathInfo($linkref);
            $accessData = array_merge($accessData, $pathInfo);
        }
        
        // Salva nel log degli accessi
        $this->saveAccessLog($accessData);
        
        // Aggiorna le statistiche
        $this->updateStats($accessData);
        
        return $accessData;
    }
    
    /**
     * Registra un logout nel debug.log
     */
    private function logLogout($username, $userRole, $fromPage = null) {
        $debugLogPath = $_SERVER['DOCUMENT_ROOT'] . '/log/errors/debug.log';
        
        $logMessage = sprintf(
            "[%s] LOGOUT - User: %s (Role: %s, IP: %s, Session: %s)",
            date('d-M-Y H:i:s T'),
            $username,
            $userRole,
            $this->getUserIP(),
            session_id()
        );
        
        if ($fromPage) {
            $logMessage .= sprintf(", From Page: %s", $fromPage);
        }
        
        $logMessage .= "\n";
        
        // Scrive nel debug.log
        file_put_contents($debugLogPath, $logMessage, FILE_APPEND | LOCK_EX);
        
        return true;
    }
    
    /**
     * Analizza il percorso per estrarre indirizzo e classe
     */
    private function parsePathInfo($linkref) {
        $pathInfo = [
            'institute_code' => null,
            'institute_name' => null,
            'class_code' => null,
            'class_name' => null,
            'subject' => null,
            'lesson_number' => null,
            'lesson_topic' => null
        ];
        
        // Esempio: /eser/ar/eser_ar2s/MAT/1_MAT-prova-ar2s.php
        if (preg_match('#/eser/([a-z]+)/eser_([a-z]+\d+[sb]?)/#', $linkref, $matches)) {
            $instituteCode = $matches[1];
            $fullClassCode = $matches[2];
            
            $pathInfo['institute_code'] = $instituteCode;
            $pathInfo['institute_name'] = $this->instituteMap[$instituteCode] ?? 'Sconosciuto';
            
            // Estrae il codice classe (es: ar2s -> 2s)
            if (preg_match('#([a-z]+)(\d+[sb]?)$#', $fullClassCode, $classMatches)) {
                $classCode = $classMatches[2];
                $pathInfo['class_code'] = $classCode;
                $pathInfo['class_name'] = $this->classMap[$classCode] ?? 'Sconosciuta';
            }
            
            // Estrae materia e informazioni lezione
            if (preg_match('#/([A-Z]+)/(\d+)_[A-Z]+-([^-]+)-#', $linkref, $lessonMatches)) {
                $pathInfo['subject'] = $lessonMatches[1];
                $pathInfo['lesson_number'] = $lessonMatches[2];
                $pathInfo['lesson_topic'] = $lessonMatches[3];
            }
        }
        
        return $pathInfo;
    }
    
    /**
     * Salva nel log degli accessi
     */
    private function saveAccessLog($accessData) {
        $logs = json_decode(file_get_contents($this->logFile), true) ?: [];
        $logs[] = $accessData;
        
        // Mantieni solo gli ultimi 1000 accessi per non far crescere troppo il file
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        file_put_contents($this->logFile, json_encode($logs, JSON_PRETTY_PRINT));
    }
    
    /**
     * Aggiorna le statistiche
     */
    private function updateStats($accessData) {
        $stats = json_decode(file_get_contents($this->statsFile), true) ?: [
            'daily_stats' => [],
            'user_stats' => [],
            'institute_stats' => [],
            'class_stats' => []
        ];
        
        $date = $accessData['date'];
        $username = $accessData['username'];
        $instituteCode = $accessData['institute_code'] ?? 'unknown';
        $classCode = $accessData['class_code'] ?? 'unknown';
        
        // Statistiche giornaliere
        if (!isset($stats['daily_stats'][$date])) {
            $stats['daily_stats'][$date] = [
                'total_accesses' => 0,
                'unique_users' => [],
                'institutes' => [],
                'classes' => []
            ];
        }
        $stats['daily_stats'][$date]['total_accesses']++;
        if (!in_array($username, $stats['daily_stats'][$date]['unique_users'])) {
            $stats['daily_stats'][$date]['unique_users'][] = $username;
        }
        
        // Statistiche per utente
        if (!isset($stats['user_stats'][$username])) {
            $stats['user_stats'][$username] = [
                'total_accesses' => 0,
                'first_access' => $accessData['timestamp'],
                'last_access' => $accessData['timestamp'],
                'institutes_visited' => [],
                'classes_visited' => [],
                'subjects_accessed' => []
            ];
        }
        $stats['user_stats'][$username]['total_accesses']++;
        $stats['user_stats'][$username]['last_access'] = $accessData['timestamp'];
        
        if ($instituteCode && !in_array($instituteCode, $stats['user_stats'][$username]['institutes_visited'])) {
            $stats['user_stats'][$username]['institutes_visited'][] = $instituteCode;
        }
        if ($classCode && !in_array($classCode, $stats['user_stats'][$username]['classes_visited'])) {
            $stats['user_stats'][$username]['classes_visited'][] = $classCode;
        }
        if (isset($accessData['subject']) && !in_array($accessData['subject'], $stats['user_stats'][$username]['subjects_accessed'])) {
            $stats['user_stats'][$username]['subjects_accessed'][] = $accessData['subject'];
        }
        
        // Statistiche per indirizzo
        if ($instituteCode) {
            if (!isset($stats['institute_stats'][$instituteCode])) {
                $stats['institute_stats'][$instituteCode] = [
                    'name' => $accessData['institute_name'],
                    'total_accesses' => 0,
                    'unique_users' => [],
                    'classes' => []
                ];
            }
            $stats['institute_stats'][$instituteCode]['total_accesses']++;
            if (!in_array($username, $stats['institute_stats'][$instituteCode]['unique_users'])) {
                $stats['institute_stats'][$instituteCode]['unique_users'][] = $username;
            }
        }
        
        // Statistiche per classe
        if ($classCode) {
            if (!isset($stats['class_stats'][$classCode])) {
                $stats['class_stats'][$classCode] = [
                    'name' => $accessData['class_name'],
                    'total_accesses' => 0,
                    'unique_users' => [],
                    'institutes' => []
                ];
            }
            $stats['class_stats'][$classCode]['total_accesses']++;
            if (!in_array($username, $stats['class_stats'][$classCode]['unique_users'])) {
                $stats['class_stats'][$classCode]['unique_users'][] = $username;
            }
            if ($instituteCode && !in_array($instituteCode, $stats['class_stats'][$classCode]['institutes'])) {
                $stats['class_stats'][$classCode]['institutes'][] = $instituteCode;
            }
        }
        
        file_put_contents($this->statsFile, json_encode($stats, JSON_PRETTY_PRINT));
    }
    
    /**
     * Ottiene l'IP dell'utente
     */
    private function getUserIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        }
    }
    
    /**
     * Ottiene le statistiche
     */
    public function getStats($type = 'all') {
        // Verifica che il file esista, altrimenti crea la struttura base
        if (!file_exists($this->statsFile)) {
            $this->initializeFiles();
        }
        
        $stats = json_decode(file_get_contents($this->statsFile), true) ?: [
            'daily_stats' => [],
            'user_stats' => [],
            'institute_stats' => [],
            'class_stats' => []
        ];
        
        if ($type === 'all') {
            return $stats;
        } else {
            return $stats[$type] ?? [];
        }
    }
    
    /**
     * Ottiene gli accessi recenti (filtrando logout)
     */
    public function getRecentAccesses($limit = 50) {
        // Verifica che il file esista, altrimenti crea la struttura base
        if (!file_exists($this->logFile)) {
            $this->initializeFiles();
        }
        
        $logs = json_decode(file_get_contents($this->logFile), true) ?: [];
        
        // Filtra i logout (mantieni solo login e altre azioni)
        $logs = array_filter($logs, function($log) {
            return !isset($log['action_type']) || $log['action_type'] !== 'logout';
        });
        
        // Riordina per timestamp decrescente
        usort($logs, function($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });
        
        return array_slice($logs, 0, $limit);
    }
    
    /**
     * Ottiene gli accessi per un utente specifico (filtrando logout)
     */
    public function getUserAccesses($username, $limit = 100) {
        $logs = json_decode(file_get_contents($this->logFile), true) ?: [];
        $userLogs = array_filter($logs, function($log) use ($username) {
            return $log['username'] === $username && 
                   (!isset($log['action_type']) || $log['action_type'] !== 'logout');
        });
        
        // Riordina per timestamp decrescente
        usort($userLogs, function($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });
        
        return array_slice($userLogs, 0, $limit);
    }
    
    /**
     * Ottiene gli accessi per un indirizzo specifico (filtrando logout)
     */
    public function getInstituteAccesses($instituteCode, $limit = 100) {
        $logs = json_decode(file_get_contents($this->logFile), true) ?: [];
        $instituteLogs = array_filter($logs, function($log) use ($instituteCode) {
            return isset($log['institute_code']) && $log['institute_code'] === $instituteCode &&
                   (!isset($log['action_type']) || $log['action_type'] !== 'logout');
        });
        
        // Riordina per timestamp decrescente
        usort($instituteLogs, function($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });
        
        return array_slice($instituteLogs, 0, $limit);
    }
    
    /**
     * Pulisce i vecchi log (più vecchi di X giorni)
     */
    public function cleanOldLogs($daysToKeep = 30) {
        $logs = json_decode(file_get_contents($this->logFile), true) ?: [];
        $cutoffDate = date('Y-m-d', strtotime("-$daysToKeep days"));
        
        $filteredLogs = array_filter($logs, function($log) use ($cutoffDate) {
            return isset($log['date']) && $log['date'] >= $cutoffDate;
        });
        
        $removedCount = count($logs) - count($filteredLogs);
        
        if ($removedCount > 0) {
            file_put_contents($this->logFile, json_encode(array_values($filteredLogs), JSON_PRETTY_PRINT));
            
            // Pulisci anche le statistiche giornaliere vecchie
            $stats = json_decode(file_get_contents($this->statsFile), true) ?: [];
            if (isset($stats['daily_stats'])) {
                $stats['daily_stats'] = array_filter($stats['daily_stats'], function($date) use ($cutoffDate) {
                    return $date >= $cutoffDate;
                }, ARRAY_FILTER_USE_KEY);
                file_put_contents($this->statsFile, json_encode($stats, JSON_PRETTY_PRINT));
            }
        }
        
        return $removedCount;
    }
    
    /**
     * Pulisce completamente tutti i log e statistiche
     */
    public function clearAllLogs() {
        // Conta i record prima di eliminare
        $logs = json_decode(file_get_contents($this->logFile), true) ?: [];
        $totalLogs = count($logs);
        
        // Resetta i file con strutture vuote
        file_put_contents($this->logFile, json_encode([], JSON_PRETTY_PRINT));
        file_put_contents($this->statsFile, json_encode([
            'daily_stats' => [],
            'user_stats' => [],
            'institute_stats' => [],
            'class_stats' => []
        ], JSON_PRETTY_PRINT));
        
        // Pulisce anche i file degli alert di sicurezza correlati
        $this->clearSecurityAlerts();
        
        return $totalLogs;
    }
    
    /**
     * Pulisce i file degli alert di sicurezza
     */
    private function clearSecurityAlerts() {
        $dataDir = $_SERVER['DOCUMENT_ROOT'] . '/log/data';
        
        // File degli alert da pulire
        $alertFiles = [
            $dataDir . '/reviewed_alerts.json',
            $dataDir . '/removal_history.json'
        ];
        
        foreach ($alertFiles as $file) {
            if (file_exists($file)) {
                // Per removal_history.json, mantieni solo la struttura base
                if (basename($file) === 'removal_history.json') {
                    $baseStructure = [
                        'credential_removals' => [],
                        'ip_removals' => [],
                        'alert_removals' => [],
                        'metadata' => [
                            'created_at' => date('Y-m-d H:i:s'),
                            'description' => 'File per tracciare tutti gli sblocchi e rimozioni di visionato per alert, credenziali bloccate e IP bloccati',
                            'version' => '1.0'
                        ]
                    ];
                    file_put_contents($file, json_encode($baseStructure, JSON_PRETTY_PRINT));
                } else {
                    // Per reviewed_alerts.json, svuota completamente
                    file_put_contents($file, json_encode([], JSON_PRETTY_PRINT));
                }
                error_log("AccessLogger: Pulito file alert: " . basename($file));
            }
        }
        
        // Nota: I file di blocco (blocked_ips.json, blocked_credentials.json) 
        // e le esclusioni (auto_block_exclusions.json) vengono mantenuti intenzionalmente
        error_log("AccessLogger: Alert di sicurezza puliti - blocchi e esclusioni mantenuti");
    }
}

// Funzione di utilità per registrare un accesso
function logUserAccess($username, $userRole, $linkref = null, $actionType = 'login') {
    // Debug log per tracciare le chiamate
    error_log("logUserAccess CALLED - Username: $username, Role: $userRole, Action: $actionType, Linkref: " . ($linkref ?? 'null'));
    
    $logger = new AccessLogger();
    $result = $logger->logAccess($username, $userRole, $linkref, $_SERVER['HTTP_USER_AGENT'] ?? null, $actionType);
    
    // Debug del risultato
    error_log("logUserAccess RESULT - " . ($result ? "SUCCESS" : "FAILED"));
    
    return $result;
}

// Funzione di utilità per registrare un logout
function logUserLogout($username, $userRole, $fromPage = null) {
    return logUserAccess($username, $userRole, $fromPage, 'logout');
}

// Funzione per ottenere le statistiche
function getAccessStats($type = 'all') {
    $logger = new AccessLogger();
    return $logger->getStats($type);
}
?>
