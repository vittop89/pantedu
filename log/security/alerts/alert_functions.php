<?php
/**
 * Controlla se il blocco automatico è abilitato per un determinato tipo e livello di rischio
 * @param string $type Tipo di anomalia ('excessive_access' o 'credential_sharing')
 * @param string $riskLevel Livello di rischio ('LOW', 'MEDIUM', 'HIGH')
 * @return bool True se il blocco automatico è abilitato
 */
function isAutoBlockEnabled($type, $riskLevel) {
    static $config = null;
    
    if ($config === null) {
        $configFile = __DIR__ . '/config.json';
        if (file_exists($configFile)) {
            $configData = json_decode(file_get_contents($configFile), true);
            $config = $configData['auto_blocking'] ?? [];
        } else {
            $config = [];
        }
    }
    
    // Controlla se il tipo di blocco automatico è abilitato
    $typeConfig = $config[$type] ?? [];
    if (!($typeConfig['enabled'] ?? false)) {
        return false;
    }
    
    // Controlla se il livello di rischio specifico è abilitato per il blocco automatico
    $riskLevelKey = strtolower($riskLevel);
    return $typeConfig['risk_levels'][$riskLevelKey] ?? false;
}

/**
 * Funzioni per il sistema di Alert di Sicurezza
 * Utilizzate da common-elements.php e altre pagine
 * Ultima modifica: 23/08/2025 - Debug rimosso completamente
 */

// Funzione per caricare la configurazione degli alert
function loadSecurityConfigForAlerts() {
    // Usa path assoluto per evitare problemi di directory relativa
    $configFile = $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/config.json';
    
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

// Funzione per ottenere gli alert revisionati
function getReviewedAlerts() {
    // Usa path assoluto per evitare problemi di directory relativa
    $reviewedFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/reviewed_alerts.json';
    
    if (!file_exists($reviewedFile)) {
        return [];
    }
    
    $data = json_decode(file_get_contents($reviewedFile), true);
    return $data ?: [];
}

// Funzione per controllare se un alert è stato revisionato
function isAlertReviewed($fingerprint, $ip, $section, $date = null) {
    $reviewed = getReviewedAlerts();
    $alertDate = $date ?? date('Y-m-d');
    $alertId = hash('sha256', $fingerprint . '|' . $ip . '|' . $section . '|' . $alertDate);
    return isset($reviewed[$alertId]);
}

// Funzione per controllare se un alert di credenziali è stato revisionato
function isCredentialAlertReviewed($fingerprint, $username, $date = null) {
    $reviewed = getReviewedAlerts();
    $alertDate = $date ?? date('Y-m-d');
    $alertId = hash('sha256', $fingerprint . '|' . $username . '|credential_sharing|' . $alertDate);
    return isset($reviewed[$alertId]);
}

// Funzione per controllare se le credenziali sono bloccate
function isCredentialsBlocked($username) {
    $blockedCredsFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/blocked_credentials.json';
    if (!file_exists($blockedCredsFile)) {
        return false;
    }
    
    $blockedCreds = json_decode(file_get_contents($blockedCredsFile), true);
    if (!$blockedCreds) {
        return false;
    }
    
    foreach ($blockedCreds as $block) {
        if ($block['username'] === $username) {
            return true;
        }
    }
    
    return false;
}

// Funzione per controllare se un IP è bloccato per una sezione
function isIPBlockedForAlert($ip, $section) {
    $blockedIPsFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/blocked_ips.json';
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

// Funzione principale per rilevare accessi anomali
function detectAnomalousAccessForAlerts($logFile) {
    if (!file_exists($logFile)) {
        return [];
    }
    
    // Carica la configurazione
    $config = loadSecurityConfigForAlerts();
    $securityConfig = $config['security_alerts'] ?? [];
    
    $logData = json_decode(file_get_contents($logFile), true);
    if (!$logData) {
        return [];
    }
    
    $today = date('Y-m-d');
    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    $accessCounts = [];
    $credentialAccesses = [];
    $anomalies = [];
    
    // Carica gli alert già visionati per gestire gli incrementali
    $reviewedAlerts = getReviewedAlerts();
    
    // Conteggio accessi per utente/sezione negli ultimi 30 giorni
    foreach ($logData as $entry) {
        $entryDate = date('Y-m-d', strtotime($entry['timestamp']));
        // Filtra solo gli accessi negli ultimi 30 giorni
        if ($entryDate < $thirtyDaysAgo || $entryDate > $today) continue;
        
        // Identifica utente (IP + User Agent + Username come fingerprint)
        // IMPORTANTE: Include username per distinguere utenti diversi dallo stesso IP/browser
        $ip = $entry['ip_address'] ?? $entry['ip'] ?? 'unknown';
        $userAgent = $entry['user_agent'] ?? 'unknown';
        $username = $entry['username'] ?? 'anonymous';
        $userFingerprint = hash('sha256', $ip . '|' . $userAgent . '|' . $username);
        
        // Determina sezione da linkref o class_code
        $section = 'unknown';
        if (!empty($entry['linkref'])) {
            // Estrai sezione dal linkref (es: /eser/li/eser_li4s/FRA/litterature.php)
            if (preg_match('/\/eser\/([a-z]+)\/eser_([a-z]+[0-9]+[sb]?)\//', $entry['linkref'], $matches)) {
                $section = $matches[2]; // li4s, ar2s, cl3s, etc.
            } elseif (preg_match('/([a-z]+)_([a-z]+[0-9]+[sb]?)/', $entry['linkref'], $matches)) {
                $section = $matches[2]; // fallback per il vecchio formato
            }
        } elseif (!empty($entry['class_code'])) {
            $section = $entry['class_code'];
        } elseif (!empty($entry['username'])) {
            $section = $entry['username']; // Fallback su username
        }
        
        $key = $userFingerprint . '|' . $section . '|' . $entryDate;
        
        if (!isset($accessCounts[$key])) {
            $accessCounts[$key] = [
                'count' => 0,
                'ip' => $ip,
                'section' => $section,
                'username' => $username,
                'date' => $entryDate,
                'first_access' => $entry['timestamp'],
                'last_access' => $entry['timestamp'],
                'user_agent' => $userAgent
            ];
        }
        
        $accessCounts[$key]['count']++;
        $accessCounts[$key]['last_access'] = $entry['timestamp'];
        
        // Traccia accessi per credenziali (username) da diversi IP
        if ($username !== 'anonymous' && $username !== '') {
            $credKey = $username . '|' . $entryDate; // Aggiungi data per gestire per giorno
            if (!isset($credentialAccesses[$credKey])) {
                $credentialAccesses[$credKey] = [
                    'username' => $username,
                    'date' => $entryDate,
                    'ips' => []
                ];
            }
            if (!isset($credentialAccesses[$credKey]['ips'][$ip])) {
                $credentialAccesses[$credKey]['ips'][$ip] = [
                    'count' => 0,
                    'sections' => [],
                    'first_access' => $entry['timestamp'],
                    'last_access' => $entry['timestamp']
                ];
            }
            $credentialAccesses[$credKey]['ips'][$ip]['count']++;
            $credentialAccesses[$credKey]['ips'][$ip]['last_access'] = $entry['timestamp'];
            if (!in_array($section, $credentialAccesses[$credKey]['ips'][$ip]['sections'])) {
                $credentialAccesses[$credKey]['ips'][$ip]['sections'][] = $section;
            }
        }
    }
    
    // Identifica anomalie per accessi multipli usando la configurazione
    $excessiveConfig = $securityConfig['excessive_access'] ?? ['enabled' => true, 'threshold_per_section' => 2];
    if ($excessiveConfig['enabled'] ?? true) {
        $threshold = $excessiveConfig['threshold_per_section'] ?? 2;
        $riskLevels = $excessiveConfig['risk_levels'] ?? [
            'low' => ['min_accesses' => 3, 'max_accesses' => 25],
            'medium' => ['min_accesses' => 26, 'max_accesses' => 50],
            'high' => ['min_accesses' => 51, 'max_accesses' => 999999]
        ];
        
        foreach ($accessCounts as $key => $data) {
            if ($data['count'] > $threshold) {
                $keyParts = explode('|', $key);
                $fingerprint = $keyParts[0];
                $section = $keyParts[1];
                $date = $keyParts[2];
                
                $isReviewed = isAlertReviewed($fingerprint, $data['ip'], $data['section'], $date);
                
                // Controlla se è un alert incrementale per lo stesso giorno
                $isIncremental = false;
                if ($date === $today) {
                    // Cerca alert già visionati per lo stesso fingerprint/sezione e data
                    foreach ($reviewedAlerts as $reviewed) {
                        if ($reviewed['type'] === 'excessive_access' && 
                            $reviewed['user_fingerprint'] === $fingerprint && 
                            $reviewed['section'] === $section &&
                            substr($reviewed['timestamp'], 0, 10) === $date) {
                            $isIncremental = true;
                            break;
                        }
                    }
                }
                
                // Determina il livello di rischio basato sulla configurazione
                $riskLevel = 'LOW';
                foreach (['high', 'medium', 'low'] as $level) {
                    $levelConfig = $riskLevels[$level] ?? [];
                    $min = $levelConfig['min_accesses'] ?? 0;
                    $max = $levelConfig['max_accesses'] ?? 999999;
                    if ($data['count'] >= $min && $data['count'] <= $max) {
                        $riskLevel = strtoupper($level);
                        break;
                    }
                }
                
                $anomalies[] = [
                    'type' => 'excessive_access',
                    'user_fingerprint' => $fingerprint,
                    'ip' => $data['ip'],
                    'username' => $data['username'],
                    'section' => $data['section'],
                    'date' => $date,
                    'is_incremental' => $isIncremental,
                    'access_count' => $data['count'],
                    'first_access' => $data['first_access'],
                    'last_access' => $data['last_access'],
                    'user_agent' => $data['user_agent'],
                    'risk_level' => $riskLevel,
                    'is_reviewed' => $isReviewed
                ];
                
                // Blocco automatico per accessi eccessivi (configurabile per livello di rischio)
                if (isAutoBlockEnabled('excessive_access', $riskLevel) && !$isReviewed && !isIPBlockedForAlert($data['ip'], $data['section'])) {
                    $autoBlockReason = "Accesso eccessivo rilevato: {$data['count']} accessi alla sezione '{$data['section']}' in data {$date} (Rischio: {$riskLevel})";
                    if (autoBlockIPForSection($data['ip'], $data['section'], $autoBlockReason)) {
                        error_log("SISTEMA SICUREZZA: Auto-bloccato IP {$data['ip']} per sezione {$data['section']} - Risk Level {$riskLevel}");
                    }
                }
            }
        }
    }
    
    // Identifica anomalie per credenziali utilizzate da multipli IP usando la configurazione
    $credentialConfig = $securityConfig['credential_sharing'] ?? ['enabled' => true, 'min_ips_required' => 5, 'min_accesses_per_ip' => 2];
    if ($credentialConfig['enabled'] ?? true) {
        $minIPs = $credentialConfig['min_ips_required'] ?? 5;
        $minAccessesPerIP = $credentialConfig['min_accesses_per_ip'] ?? 2;
        $riskLevels = $credentialConfig['risk_levels'] ?? [
            'low' => ['min_ips' => 5, 'max_ips' => 7],
            'medium' => ['min_ips' => 8, 'max_ips' => 10],
            'high' => ['min_ips' => 11, 'max_ips' => 999999]
        ];
        
        foreach ($credentialAccesses as $credKey => $credData) {
            $username = $credData['username'];
            $date = $credData['date'];
            $ipData = $credData['ips'];
            
            $validIPs = array_filter($ipData, function($data) use ($minAccessesPerIP) {
                return $data['count'] >= $minAccessesPerIP;
            });
            
            if (count($validIPs) >= $minIPs) {
                $totalAccesses = array_sum(array_column($validIPs, 'count'));
                $allIPs = array_keys($validIPs);
                $allSections = array_unique(array_merge(...array_column($validIPs, 'sections')));
                
                // Determina il livello di rischio basato sulla configurazione
                $riskLevel = 'LOW';
                $ipCount = count($allIPs);
                foreach (['high', 'medium', 'low'] as $level) {
                    $levelConfig = $riskLevels[$level] ?? [];
                    $min = $levelConfig['min_ips'] ?? 0;
                    $max = $levelConfig['max_ips'] ?? 999999;
                    if ($ipCount >= $min && $ipCount <= $max) {
                        $riskLevel = strtoupper($level);
                        break;
                    }
                }
                
                // Crea un fingerprint unico per questo tipo di anomalia includendo la data
                $credentialFingerprint = hash('sha256', $username . '|credential_sharing|' . $date . '|' . implode(',', $allIPs));
                $isReviewed = isCredentialAlertReviewed($credentialFingerprint, $username, $date);
                
                // Controlla se è un alert incrementale per lo stesso giorno
                $isIncremental = false;
                if ($date === $today) {
                    // Cerca alert già visionati per lo stesso username e data
                    foreach ($reviewedAlerts as $reviewed) {
                        if ($reviewed['type'] === 'credential_sharing' && 
                            $reviewed['username'] === $username && 
                            substr($reviewed['timestamp'], 0, 10) === $date) {
                            $isIncremental = true;
                            break;
                        }
                    }
                }
                
                $anomalies[] = [
                    'type' => 'credential_sharing',
                    'user_fingerprint' => $credentialFingerprint,
                    'username' => $username,
                    'date' => $date,
                    'is_incremental' => $isIncremental,
                    'ip_addresses' => $allIPs,
                    'ip_count' => count($allIPs),
                    'total_access_count' => $totalAccesses,
                    'sections_accessed' => $allSections,
                    'section' => count($allSections) > 1 
                        ? 'Multiple (' . implode(', ', array_slice($allSections, 0, 3)) . (count($allSections) > 3 ? '...' : '') . ')'
                        : $allSections[0],
                    'first_access' => min(array_column($validIPs, 'first_access')),
                    'last_access' => max(array_column($validIPs, 'last_access')),
                    'risk_level' => $riskLevel,
                    'is_reviewed' => $isReviewed,
                    'ip_details' => $validIPs
                ];
                
                // Blocco automatico per credenziali condivise (configurabile per livello di rischio)
                if (isAutoBlockEnabled('credential_sharing', $riskLevel) && !$isReviewed && !isCredentialsBlocked($username)) {
                    $autoBlockReason = "Condivisione credenziali rilevata: {$ipCount} IP diversi utilizzano le stesse credenziali in data {$date} (Rischio: {$riskLevel})";
                    if (autoBlockCredentials($username, $autoBlockReason)) {
                        error_log("SISTEMA SICUREZZA: Auto-bloccate credenziali {$username} - Risk Level {$riskLevel} - {$ipCount} IP coinvolti");
                    }
                }
            }
        }
    }
    
    // Ordina per data (più recenti prima), poi per livello di rischio, poi per numero di accessi
    usort($anomalies, function($a, $b) {
        // Prima ordina per data (più recente prima)
        $aDate = $a['date'] ?? date('Y-m-d');
        $bDate = $b['date'] ?? date('Y-m-d');
        $dateComparison = strcmp($bDate, $aDate);
        if ($dateComparison !== 0) {
            return $dateComparison;
        }
        
        // Poi per livello di rischio
        $riskOrder = ['HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1];
        $aRisk = $riskOrder[$a['risk_level']] ?? 0;
        $bRisk = $riskOrder[$b['risk_level']] ?? 0;
        
        if ($aRisk === $bRisk) {
            // Infine per numero di accessi (decrescente)
            $aCount = $a['access_count'] ?? $a['total_access_count'] ?? 0;
            $bCount = $b['access_count'] ?? $b['total_access_count'] ?? 0;
            return $bCount - $aCount;
        }
        return $bRisk - $aRisk;
    });
    
    return $anomalies;
}

// Funzione per controllare se un username è nella lista di esclusioni per il blocco automatico
function isUsernameExcludedFromAutoBlock($username) {
    $exclusionsFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/auto_block_exclusions.json';
    if (!file_exists($exclusionsFile)) {
        return false;
    }
    
    $exclusions = json_decode(file_get_contents($exclusionsFile), true);
    if (!$exclusions) {
        return false;
    }
    
    return isset($exclusions['credentials'][$username]);
}

// Funzione per controllare se un IP+sezione è nella lista di esclusioni per il blocco automatico
function isIPExcludedFromAutoBlock($ip, $section) {
    $exclusionsFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/auto_block_exclusions.json';
    if (!file_exists($exclusionsFile)) {
        return false;
    }
    
    $exclusions = json_decode(file_get_contents($exclusionsFile), true);
    if (!$exclusions) {
        return false;
    }
    
    $blockKey = $ip . '|' . $section;
    return isset($exclusions['ips'][$blockKey]);
}

// Funzione per aggiungere un username alla lista di esclusioni (quando viene sbloccato manualmente)
function addUsernameToAutoBlockExclusions($username, $reason = '') {
    $exclusionsFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/auto_block_exclusions.json';
    
    // Carica le esclusioni esistenti
    $exclusions = ['credentials' => [], 'ips' => []];
    if (file_exists($exclusionsFile)) {
        $data = json_decode(file_get_contents($exclusionsFile), true);
        $exclusions = $data ?: $exclusions;
    }
    
    // Aggiungi l'esclusione per le credenziali
    $exclusions['credentials'][$username] = [
        'excluded_at' => date('Y-m-d H:i:s'),
        'reason' => $reason ?: 'Sbloccato manualmente dall\'amministratore',
        'type' => 'manual_unblock_exclusion'
    ];
    
    // Salva il file
    return file_put_contents($exclusionsFile, json_encode($exclusions, JSON_PRETTY_PRINT));
}

// Funzione per aggiungere un IP+sezione alla lista di esclusioni (quando viene sbloccato manualmente)
function addIPToAutoBlockExclusions($ip, $section, $reason = '') {
    $exclusionsFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/auto_block_exclusions.json';
    
    // Carica le esclusioni esistenti
    $exclusions = ['credentials' => [], 'ips' => []];
    if (file_exists($exclusionsFile)) {
        $data = json_decode(file_get_contents($exclusionsFile), true);
        $exclusions = $data ?: $exclusions;
    }
    
    // Aggiungi l'esclusione per l'IP
    $blockKey = $ip . '|' . $section;
    $exclusions['ips'][$blockKey] = [
        'ip' => $ip,
        'section' => $section,
        'excluded_at' => date('Y-m-d H:i:s'),
        'reason' => $reason ?: 'Sbloccato manualmente dall\'amministratore',
        'type' => 'manual_unblock_exclusion'
    ];
    
    // Salva il file
    return file_put_contents($exclusionsFile, json_encode($exclusions, JSON_PRETTY_PRINT));
}

// Funzione per contare solo gli alert ATTIVI (non visionati E non bloccati)
function countActiveAlertsForAuth($logFile) {
    $anomalies = detectAnomalousAccessForAlerts($logFile);
    $activeCount = 0;
    
    foreach ($anomalies as $anomaly) {
        // Salta gli alert già visionati
        if ($anomaly['is_reviewed']) {
            continue;
        }
        
        if ($anomaly['type'] === 'credential_sharing') {
            // Per alert di condivisione credenziali, controlla se le credenziali sono bloccate
            if (isCredentialsBlocked($anomaly['username'])) {
                continue;
            }
        } elseif ($anomaly['type'] === 'excessive_access') {
            // Per alert di accesso eccessivo, controlla se l'IP è bloccato per quella sezione
            if (isset($anomaly['ip']) && isset($anomaly['section'])) {
                if (isIPBlockedForAlert($anomaly['ip'], $anomaly['section'])) {
                    continue;
                }
            }
        }
        
        // Se arriviamo qui, l'alert è attivo
        $activeCount++;
    }

    return $activeCount;
}

// Funzione per bloccare automaticamente credenziali compromesse (risk_level HIGH)
function autoBlockCredentials($username, $reason = '') {
    // Escludi l'admin dal blocco automatico
    if ($username === 'admin') {
        error_log("AUTO-BLOCK PREVENTED: Admin account cannot be auto-blocked");
        return false;
    }
    
    // Controlla se il username è nella lista di esclusioni (sbloccato manualmente)
    if (isUsernameExcludedFromAutoBlock($username)) {
        error_log("AUTO-BLOCK PREVENTED: Username '$username' is excluded from auto-block (manually unblocked)");
        return false;
    }
    
    $blockedCredsFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/blocked_credentials.json';
    error_log("AUTO-BLOCK CREDENTIALS - Attempting to auto-block username: $username, Reason: $reason");
    
    // Carica i blocchi esistenti
    $blockedCreds = [];
    if (file_exists($blockedCredsFile)) {
        $data = json_decode(file_get_contents($blockedCredsFile), true);
        $blockedCreds = $data ?: [];
    }
    
    // Controlla se le credenziali sono già bloccate
    foreach ($blockedCreds as $block) {
        if ($block['username'] === $username) {
            error_log("AUTO-BLOCK CREDENTIALS - Username $username is already blocked");
            return false; // Già bloccato
        }
    }
    
    // Crea l'entry per il blocco automatico
    $blockEntry = [
        'username' => $username,
        'blocked_at' => date('Y-m-d H:i:s'),
        'blocked_by' => 'SYSTEM_AUTO_BLOCK',
        'reason' => 'AUTO-BLOCK: ' . $reason,
        'type' => 'credential_auto_blocked',
        'auto_block' => true
    ];
    
    // Aggiungi il nuovo blocco
    $blockedCreds[] = $blockEntry;
    
    // Salva il file
    if (file_put_contents($blockedCredsFile, json_encode($blockedCreds, JSON_PRETTY_PRINT))) {
        error_log("AUTO-BLOCK CREDENTIALS - Successfully auto-blocked username: $username");
        return true;
    } else {
        error_log("AUTO-BLOCK CREDENTIALS - Failed to save blocked credentials file for username: $username");
        return false;
    }
}

// Funzione per bloccare automaticamente IP per sezione (risk_level HIGH)
function autoBlockIPForSection($ip, $section, $reason = '') {
    // Controlla se l'IP+sezione è nella lista di esclusioni (sbloccato manualmente)
    if (isIPExcludedFromAutoBlock($ip, $section)) {
        error_log("AUTO-BLOCK PREVENTED: IP '$ip' for section '$section' is excluded from auto-block (manually unblocked)");
        return false;
    }
    
    $blockedIPsFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/blocked_ips.json';
    error_log("AUTO-BLOCK IP - Attempting to auto-block IP: $ip for section: $section, Reason: $reason");
    
    // Carica i blocchi esistenti
    $blockedIPs = [];
    if (file_exists($blockedIPsFile)) {
        $data = json_decode(file_get_contents($blockedIPsFile), true);
        $blockedIPs = $data ?: [];
    }
    
    // Controlla se l'IP è già bloccato per questa sezione
    foreach ($blockedIPs as $block) {
        if ($block['ip'] === $ip && $block['section'] === $section) {
            error_log("AUTO-BLOCK IP - IP $ip is already blocked for section $section");
            return false; // Già bloccato
        }
    }
    
    // Crea l'entry per il blocco automatico
    $blockEntry = [
        'ip' => $ip,
        'section' => $section,
        'blocked_at' => date('Y-m-d H:i:s'),
        'blocked_by' => 'SYSTEM_AUTO_BLOCK',
        'reason' => 'AUTO-BLOCK: ' . $reason,
        'type' => 'ip_auto_blocked',
        'auto_block' => true
    ];
    
    // Aggiungi il nuovo blocco
    $blockedIPs[] = $blockEntry;
    
    // Salva il file
    if (file_put_contents($blockedIPsFile, json_encode($blockedIPs, JSON_PRETTY_PRINT))) {
        error_log("AUTO-BLOCK IP - Successfully auto-blocked IP: $ip for section: $section");
        return true;
    } else {
        error_log("AUTO-BLOCK IP - Failed to save blocked IPs file for IP: $ip, section: $section");
        return false;
    }
}

// Funzione per rimuovere un username dalla lista di esclusioni (riabilita il blocco automatico)
function removeUsernameFromAutoBlockExclusions($username) {
    $exclusionsFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/auto_block_exclusions.json';
    if (!file_exists($exclusionsFile)) {
        return true; // Se il file non esiste, considera l'operazione come riuscita
    }
    
    $exclusions = json_decode(file_get_contents($exclusionsFile), true);
    if (!$exclusions) {
        return true;
    }
    
    // Rimuovi l'esclusione per le credenziali
    if (isset($exclusions['credentials'][$username])) {
        unset($exclusions['credentials'][$username]);
        error_log("AUTO-BLOCK EXCLUSIONS - Removed username '$username' from exclusions (auto-block re-enabled)");
    }
    
    // Salva il file aggiornato
    return file_put_contents($exclusionsFile, json_encode($exclusions, JSON_PRETTY_PRINT));
}

// Funzione per rimuovere un IP+sezione dalla lista di esclusioni (riabilita il blocco automatico)
function removeIPFromAutoBlockExclusions($ip, $section) {
    $exclusionsFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/auto_block_exclusions.json';
    if (!file_exists($exclusionsFile)) {
        return true; // Se il file non esiste, considera l'operazione come riuscita
    }
    
    $exclusions = json_decode(file_get_contents($exclusionsFile), true);
    if (!$exclusions) {
        return true;
    }
    
    // Rimuovi l'esclusione per l'IP
    $blockKey = $ip . '|' . $section;
    if (isset($exclusions['ips'][$blockKey])) {
        unset($exclusions['ips'][$blockKey]);
        error_log("AUTO-BLOCK EXCLUSIONS - Removed IP '$ip' for section '$section' from exclusions (auto-block re-enabled)");
    }
    
    // Salva il file aggiornato
    return file_put_contents($exclusionsFile, json_encode($exclusions, JSON_PRETTY_PRINT));
}
?>