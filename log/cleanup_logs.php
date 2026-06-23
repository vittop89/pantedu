<?php
/**
 * Script per anonimizzare i dati sensibili nei log di accesso
 * Anonimizza dati più vecchi di 2 mesi per privacy
 * Include pulizia del debug.log per conformità privacy
 */

// Rileva se siamo in browser o terminale
$isWeb = isset($_SERVER['HTTP_HOST']);
$nl = $isWeb ? "<br>" : "\n";

// Se siamo nel browser, iniziamo con HTML
if ($isWeb) {
    echo "<!DOCTYPE html><html><head><title>Anonimizzazione Log</title>";
    echo "<style>body{font-family:monospace;margin:20px;} .success{color:green;} .error{color:red;} .info{color:blue;}</style>";
    echo "</head><body>";
}

function printLine($message, $type = 'normal') {
    global $isWeb, $nl;
    
    if ($isWeb) {
        switch($type) {
            case 'success': echo "<span class='success'>✓ $message</span>$nl"; break;
            case 'error': echo "<span class='error'>✗ $message</span>$nl"; break;
            case 'info': echo "<span class='info'>$message</span>$nl"; break;
            default: echo "$message$nl"; break;
        }
    } else {
        echo "$message" . ($isWeb ? "<br>" : "\n");
    }
}


// Fix DOCUMENT_ROOT if empty (esecuzione da CLI/cron)
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    // dirname(__DIR__) = root del sito se sei in /log/
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
}
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/log/data';
$accessLog = $logDir . '/access_log.json';
$accessStats = $logDir . '/access_stats.json';
$debugLog = $_SERVER['DOCUMENT_ROOT'] . '/log/errors/debug.log';

printLine("=== ANONIMIZZAZIONE LOG DI ACCESSO ===", 'info');
printLine("Data: " . date('Y-m-d H:i:s'));

// Data limite: 2 mesi fa
$cutoffDate = date('Y-m-d', strtotime('-2 months'));
printLine("Anonimizzazione dati precedenti al: $cutoffDate", 'info');

$modified = 0;
$total = 0;

// Processa access_log.json
if (file_exists($accessLog)) {
    printLine("");
    printLine("--- Processando access_log.json ---", 'info');
    
    $logContent = file_get_contents($accessLog);
    $logs = json_decode($logContent, true);
    
    if ($logs && is_array($logs)) {
        $total = count($logs);
        printLine("Totale record: $total");
        
        foreach ($logs as &$entry) {
            // Controlla se il record è più vecchio di 2 mesi
            if (isset($entry['date']) && $entry['date'] < $cutoffDate) {
                $originalEntry = $entry;
                
                // Anonimizza IP address
                if (isset($entry['ip_address']) && !empty($entry['ip_address'])) {
                    $entry['ip_address'] = 'xxx.xxx.xxx.xxx';
                }
                
                // Anonimizza username (mantieni solo il ruolo)
                if (isset($entry['username']) && !empty($entry['username'])) {
                    $role = $entry['role'] ?? 'unknown';
                    $entry['username'] = 'anonymous_' . $role;
                }
                
                // Anonimizza session_id
                if (isset($entry['session_id']) && !empty($entry['session_id'])) {
                    $entry['session_id'] = 'xxxxxxxxxxxxxxxxxxxxxxx';
                }
                
                // Anonimizza user_agent (mantieni solo info browser base)
                if (isset($entry['user_agent']) && !empty($entry['user_agent'])) {
                    // Estrai solo il browser principale
                    if (strpos($entry['user_agent'], 'Chrome') !== false) {
                        $entry['user_agent'] = 'Chrome/xxx.x.x.x';
                    } elseif (strpos($entry['user_agent'], 'Firefox') !== false) {
                        $entry['user_agent'] = 'Firefox/xxx.x';
                    } elseif (strpos($entry['user_agent'], 'Safari') !== false) {
                        $entry['user_agent'] = 'Safari/xxx.x';
                    } else {
                        $entry['user_agent'] = 'Unknown Browser';
                    }
                }
                $modified++;
            }
        }
        
        // Salva il file modificato
        if ($modified > 0) {
            $newContent = json_encode($logs, JSON_PRETTY_PRINT);
            if (file_put_contents($accessLog, $newContent)) {
                printLine("access_log.json aggiornato", 'success');
            } else {
                printLine("Errore nel salvare access_log.json", 'error');
            }
        } else {
            printLine("Nessun record da anonimizzare in access_log.json", 'info');
        }
    } else {
        printLine("Errore nel parsing di access_log.json", 'error');
    }
} else {
    printLine("access_log.json non presente", 'info');
}

// Processa access_stats.json
if (file_exists($accessStats)) {
    printLine("", 'info'); // Riga vuota
    printLine("--- Processando access_stats.json ---", 'info');
    
    $statsContent = file_get_contents($accessStats);
    $stats = json_decode($statsContent, true);
    
    if ($stats && is_array($stats)) {
        $statsModified = false;
        
        // Anonimizza daily_stats (rimuovi giorni vecchi)
        if (isset($stats['daily_stats'])) {
            $originalCount = count($stats['daily_stats']);
            $stats['daily_stats'] = array_filter($stats['daily_stats'], function($date) use ($cutoffDate) {
                return $date >= $cutoffDate;
            }, ARRAY_FILTER_USE_KEY);
            $newCount = count($stats['daily_stats']);
            
            if ($originalCount > $newCount) {
                printLine("Rimossi " . ($originalCount - $newCount) . " giorni dalle statistiche giornaliere", 'success');
                $statsModified = true;
            }
        }
        
        // Anonimizza user_stats (rimuovi dettagli utenti)
        if (isset($stats['user_stats'])) {
            $userCount = count($stats['user_stats']);
            if ($userCount > 0) {
                // Mantieni solo statistiche aggregate per ruolo
                $roleStats = [];
                foreach ($stats['user_stats'] as $username => $userData) {
                    $role = $userData['role'] ?? 'unknown';
                    if (!isset($roleStats[$role])) {
                        $roleStats[$role] = ['total_accesses' => 0, 'role' => $role];
                    }
                    $roleStats[$role]['total_accesses'] += $userData['total_accesses'] ?? 0;
                }
                $stats['user_stats'] = $roleStats;
                printLine("Anonimizzate statistiche utenti (aggregate per ruolo)", 'success');
                $statsModified = true;
            }
        }
        
        // Salva il file modificato
        if ($statsModified) {
            $newStatsContent = json_encode($stats, JSON_PRETTY_PRINT);
            if (file_put_contents($accessStats, $newStatsContent)) {
                printLine("access_stats.json aggiornato", 'success');
            } else {
                printLine("Errore nel salvare access_stats.json", 'error');
            }
        } else {
            printLine("Nessuna modifica necessaria in access_stats.json", 'info');
        }
    } else {
        printLine("Errore nel parsing di access_stats.json", 'error');
    }
} else {
    printLine("access_stats.json non presente", 'info');
}

// Processa debug.log (pulizia messaggi di login più vecchi di 2 mesi)
if (file_exists($debugLog)) {
    printLine("", 'info'); // Riga vuota
    printLine("--- Processando debug.log ---", 'info');
    
    $debugContent = file_get_contents($debugLog);
    if ($debugContent !== false) {
        $lines = explode("\n", $debugContent);
        $originalLineCount = count($lines);
        $filteredLines = [];
        $removedLines = 0;
        
        printLine("Totale righe: $originalLineCount");
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                $filteredLines[] = $line;
                continue;
            }
            
            // Header del file o righe senza timestamp
            if (strpos($line, '[') !== 0 || strpos($line, ']') === false) {
                $filteredLines[] = $line;
                continue;
            }
            
            // Estrai la data dal formato [DD-Mon-YYYY HH:mm:ss Timezone]
            if (preg_match('/^\[(\d{2}-\w{3}-\d{4})\s/', $line, $matches)) {
                $logDateStr = $matches[1];
                // Converti formato DD-Mon-YYYY in YYYY-MM-DD
                $logDate = DateTime::createFromFormat('d-M-Y', $logDateStr);
                
                if ($logDate) {
                    $logDateFormatted = $logDate->format('Y-m-d');
                    
                    // Se la data è più vecchia di 2 mesi, rimuovi la riga
                    if ($logDateFormatted < $cutoffDate) {
                        $removedLines++;
                        continue; // Salta questa riga
                    }
                }
            }
            
            // Mantieni la riga se non è più vecchia di 2 mesi o se non riusciamo a parsare la data
            $filteredLines[] = $line;
        }
        
        // Salva il file modificato solo se abbiamo rimosso delle righe
        if ($removedLines > 0) {
            $newDebugContent = implode("\n", $filteredLines);
            if (file_put_contents($debugLog, $newDebugContent)) {
                printLine("debug.log aggiornato - Rimosse $removedLines righe", 'success');
            } else {
                printLine("Errore nel salvare debug.log", 'error');
            }
        } else {
            printLine("Nessun messaggio da rimuovere in debug.log", 'info');
        }
        
        $remainingLines = count($filteredLines);
        printLine("Righe rimanenti: $remainingLines", 'info');
    } else {
        printLine("Errore nella lettura di debug.log", 'error');
    }
} else {
    printLine("debug.log non presente", 'info');
}

// Log dell'operazione
$logMessage = date('Y-m-d H:i:s') . " - Record anonimizzati: $modified/$total (cutoff: $cutoffDate)\n";
file_put_contents($logDir . '/anonymization_log.txt', $logMessage, FILE_APPEND);

printLine("", 'info'); // Riga vuota
printLine("=== ANONIMIZZAZIONE COMPLETATA ===", 'info');
printLine("Record processati: $total", 'info');
printLine("Record anonimizzati: $modified", 'info');
printLine("Data limite: $cutoffDate", 'info');
printLine("I dati recenti (ultimi 2 mesi) sono mantenuti per analisi.", 'info');
printLine("Debug.log pulito per conformità privacy (rimossi login vecchi).", 'info');

// Chiudi HTML se è una esecuzione web
if (isset($_SERVER['HTTP_HOST'])) {
    echo "</body></html>";
}
?>
