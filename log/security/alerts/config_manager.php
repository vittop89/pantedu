<?php
session_start();

// Includi le funzioni comuni
require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/common-elements.php';

// Suppress linter warnings per funzioni definite nei file inclusi
// phpcs:disable Generic.PHP.ForbiddenFunctions
/* @phpstan-ignore-next-line */

// Controllo autenticazione admin
requireAdminAuth(); // @phpstan-ignore-line

// Genera il token CSRF per questa sessione admin (se non già presente)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Include le funzioni condivise per gli alert
if (file_exists('alert_functions.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/alert_functions.php';
}

// Conta gli alert attivi per la navbar
$alertCount = countActiveAlertsForAuth($_SERVER['DOCUMENT_ROOT'] . '/log/data/access_log.json');

// Path assoluto per evitare dipendenza dalla CWD del processo PHP
$configFile = __DIR__ . '/config.json';
$message = '';
$messageType = '';

// Carica configurazione esistente
$config = [];
if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true) ?: [];
}

// Gestione salvataggio configurazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    // Verifica token CSRF — protezione contro richieste cross-site forgery
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        die('Richiesta non autorizzata (token CSRF non valido)');
    }

    $newConfig = [
        'security_alerts' => [
            'excessive_access' => [
                'enabled' => isset($_POST['excessive_enabled']),
                'description' => 'Rileva quando un singolo IP/fingerprint accede più volte alla stessa sezione',
                'threshold_per_section' => (int)($_POST['excessive_threshold'] ?? 2),
                'time_window_hours' => (int)($_POST['excessive_time_window'] ?? 24),
                'risk_levels' => [
                    'low' => [
                        'min_accesses' => (int)($_POST['excessive_low_min'] ?? 3),
                        'max_accesses' => (int)($_POST['excessive_low_max'] ?? 25)
                    ],
                    'medium' => [
                        'min_accesses' => (int)($_POST['excessive_med_min'] ?? 26),
                        'max_accesses' => (int)($_POST['excessive_med_max'] ?? 50)
                    ],
                    'high' => [
                        'min_accesses' => (int)($_POST['excessive_high_min'] ?? 51),
                        'max_accesses' => 999999
                    ]
                ]
            ],
            'credential_sharing' => [
                'enabled' => isset($_POST['credential_enabled']),
                'description' => 'Rileva quando le stesse credenziali vengono utilizzate da molti IP diversi',
                'min_ips_required' => (int)($_POST['credential_min_ips'] ?? 5),
                'min_accesses_per_ip' => (int)($_POST['credential_min_accesses'] ?? 2),
                'time_window_hours' => (int)($_POST['credential_time_window'] ?? 24),
                'risk_levels' => [
                    'low' => [
                        'min_ips' => (int)($_POST['credential_low_min'] ?? 5),
                        'max_ips' => (int)($_POST['credential_low_max'] ?? 7)
                    ],
                    'medium' => [
                        'min_ips' => (int)($_POST['credential_med_min'] ?? 8),
                        'max_ips' => (int)($_POST['credential_med_max'] ?? 10)
                    ],
                    'high' => [
                        'min_ips' => (int)($_POST['credential_high_min'] ?? 11),
                        'max_ips' => 999999
                    ]
                ]
            ]
        ],
        'auto_blocking' => [
            'excessive_access' => [
                'enabled' => isset($_POST['auto_block_excessive_enabled']),
                'description' => 'Blocca automaticamente gli IP che superano le soglie di accesso',
                'risk_levels' => [
                    'low' => isset($_POST['auto_block_excessive_low']),
                    'medium' => isset($_POST['auto_block_excessive_medium']),
                    'high' => isset($_POST['auto_block_excessive_high'])
                ]
            ],
            'credential_sharing' => [
                'enabled' => isset($_POST['auto_block_credential_enabled']),
                'description' => 'Blocca automaticamente le credenziali condivise tra troppi IP',
                'risk_levels' => [
                    'low' => isset($_POST['auto_block_credential_low']),
                    'medium' => isset($_POST['auto_block_credential_medium']),
                    'high' => isset($_POST['auto_block_credential_high'])
                ]
            ]
        ],
        'general_settings' => [
            'auto_redirect' => [
                'enabled' => isset($_POST['auto_redirect_enabled']),
                'description' => 'Reindirizza automaticamente l\'admin agli alert quando ci sono anomalie attive'
            ],
            'log_retention' => [
                'days' => (int)($_POST['log_retention_days'] ?? 90),
                'description' => 'Numero di giorni per cui mantenere i log di accesso'
            ],
            'alert_retention' => [
                'days' => (int)($_POST['alert_retention_days'] ?? 30),
                'description' => 'Numero di giorni per cui mantenere gli alert visionati/bloccati'
            ]
        ],
        'version' => '1.0.0',
        'last_updated' => date('c'),
        'description' => 'Configurazione per il sistema di rilevamento anomalie negli accessi.'
    ];
    
    if (file_put_contents($configFile, json_encode($newConfig, JSON_PRETTY_PRINT), LOCK_EX)) {
        $config = $newConfig;
        $message = '✅ Configurazione salvata con successo!';
        $messageType = 'success';
    } else {
        $message = '❌ Errore nel salvare la configurazione.';
        $messageType = 'error';
    }
}

// Estrai i valori per i form
$excessive = $config['security_alerts']['excessive_access'] ?? [];
$credential = $config['security_alerts']['credential_sharing'] ?? [];
$autoBlock = $config['auto_blocking'] ?? [];
$general = $config['general_settings'] ?? [];
renderHtmlHead('⚙️ Configurazione Alert Sicurezza - Pantedu Analytics');
?>
    <style>
        .config-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }

        .config-section h2 {
            color: #667eea;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .risk-levels {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .risk-level {
            background: rgba(0,0,0,0.05);
            padding: 15px;
            border-radius: 8px;
            min-width: 0; /* Permette al contenuto di restringersi */
        }

        .risk-level h4 {
            margin-bottom: 10px;
            color: #666;
        }

        .risk-low { border-left: 4px solid #17a2b8; }
        .risk-medium { border-left: 4px solid #ffc107; }
        .risk-high { border-left: 4px solid #dc3545; }

        .description {
            font-size: 0.9em;
            color: #666;
            font-style: italic;
            margin-bottom: 15px;
        }

        .checkbox-group {
            margin: 8px 0;
        }

        .checkbox-group input[type="checkbox"] {
            margin-right: 8px;
        }

        .checkbox-group label {
            cursor: pointer;
            font-weight: normal;
            transition: color 0.3s ease, opacity 0.3s ease;
        }

        .checkbox-group input[type="checkbox"]:disabled + label {
            color: #999;
            opacity: 0.6;
            cursor: not-allowed;
        }

        .checkbox-group input[type="checkbox"]:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .auto-block-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            position: relative;
            overflow: hidden;
        }

        .auto-block-warning::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: #f39c12;
        }

        .auto-block-warning h4 {
            color: #856404;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }

        .auto-block-warning p {
            color: #856404;
            margin: 0;
            font-size: 14px;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <?php renderNavbar('config_manager'); ?>

    <?php renderHeader('⚙️ Configurazione Alert di Sicurezza', 'Gestisci i parametri per il rilevamento delle anomalie negli accessi'); ?>
    
    <?php renderContainerStart(); ?>

        <div style="margin-top: 15px;">
            <a href="security_alerts.php" style="background: #007bff; color: white; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 14px;">
                ← Torna agli Alert
            </a>
        </div>
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <!-- Token CSRF per protezione contro richieste cross-site forgery -->
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <!-- Configurazione Accessi Eccessivi -->
            <div class="config-section">
                <h2>🚨 Alert per Accessi Eccessivi</h2>
                <div class="description">
                    Rileva quando un singolo IP/fingerprint accede più volte alla stessa sezione in un giorno.
                </div>

                <div class="form-group">
                    <label>Stato:</label>
                    <div class="fm-checkbox-group">
                        <input type="checkbox" id="excessive_enabled" name="excessive_enabled" <?= ($excessive['enabled'] ?? true) ? 'checked' : '' ?>>
                        <label for="excessive_enabled">Abilitato</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Soglia Accessi:</label>
                    <input type="number" name="excessive_threshold" value="<?= $excessive['threshold_per_section'] ?? 2 ?>" min="1" max="100">
                </div>

                <div class="form-group inline-text">
                    <label>Finestra Temporale:</label>
                    <input type="number" name="excessive_time_window" value="<?= $excessive['time_window_hours'] ?? 24 ?>" min="1" max="168">
                    <span>ore</span>
                </div>

                <h3>Livelli di Rischio</h3>
                <div class="risk-levels">
                    <div class="risk-level risk-low">
                        <h4>🔵 Basso</h4>
                        <div class="form-group">
                            <label>Min:</label>
                            <input type="number" name="excessive_low_min" value="<?= $excessive['risk_levels']['low']['min_accesses'] ?? 3 ?>" min="1">
                        </div>
                        <div class="form-group">
                            <label>Max:</label>
                            <input type="number" name="excessive_low_max" value="<?= $excessive['risk_levels']['low']['max_accesses'] ?? 25 ?>" min="1">
                        </div>
                    </div>
                    <div class="risk-level risk-medium">
                        <h4>🟡 Medio</h4>
                        <div class="form-group">
                            <label>Min:</label>
                            <input type="number" name="excessive_med_min" value="<?= $excessive['risk_levels']['medium']['min_accesses'] ?? 26 ?>" min="1">
                        </div>
                        <div class="form-group">
                            <label>Max:</label>
                            <input type="number" name="excessive_med_max" value="<?= $excessive['risk_levels']['medium']['max_accesses'] ?? 50 ?>" min="1">
                        </div>
                    </div>
                    <div class="risk-level risk-high">
                        <h4>🔴 Alto</h4>
                        <div class="form-group">
                            <label>Min:</label>
                            <input type="number" name="excessive_high_min" value="<?= $excessive['risk_levels']['high']['min_accesses'] ?? 51 ?>" min="1">
                        </div>
                        <p><em>Max: illimitato</em></p>
                    </div>
                </div>
            </div>

            <!-- Configurazione Condivisione Credenziali -->
            <div class="config-section">
                <h2>🔑 Alert per Condivisione Credenziali</h2>
                <div class="description">
                    Rileva quando le stesse credenziali vengono utilizzate da molti IP diversi.
                </div>

                <div class="form-group">
                    <label>Stato:</label>
                    <div class="fm-checkbox-group">
                        <input type="checkbox" id="credential_enabled" name="credential_enabled" <?= ($credential['enabled'] ?? true) ? 'checked' : '' ?>>
                        <label for="credential_enabled">Abilitato</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Min IP Richiesti:</label>
                    <input type="number" name="credential_min_ips" value="<?= $credential['min_ips_required'] ?? 5 ?>" min="2" max="50">
                </div>

                <div class="form-group">
                    <label>Min Accessi per IP:</label>
                    <input type="number" name="credential_min_accesses" value="<?= $credential['min_accesses_per_ip'] ?? 2 ?>" min="1" max="10">
                </div>

                <div class="form-group inline-text">
                    <label>Finestra Temporale:</label>
                    <input type="number" name="credential_time_window" value="<?= $credential['time_window_hours'] ?? 24 ?>" min="1" max="168">
                    <span>ore</span>
                </div>

                <h3>Livelli di Rischio</h3>
                <div class="risk-levels">
                    <div class="risk-level risk-low">
                        <h4>🔵 Basso</h4>
                        <div class="form-group">
                            <label>Min IP:</label>
                            <input type="number" name="credential_low_min" value="<?= $credential['risk_levels']['low']['min_ips'] ?? 5 ?>" min="2">
                        </div>
                        <div class="form-group">
                            <label>Max IP:</label>
                            <input type="number" name="credential_low_max" value="<?= $credential['risk_levels']['low']['max_ips'] ?? 7 ?>" min="2">
                        </div>
                    </div>
                    <div class="risk-level risk-medium">
                        <h4>🟡 Medio</h4>
                        <div class="form-group">
                            <label>Min IP:</label>
                            <input type="number" name="credential_med_min" value="<?= $credential['risk_levels']['medium']['min_ips'] ?? 8 ?>" min="2">
                        </div>
                        <div class="form-group">
                            <label>Max IP:</label>
                            <input type="number" name="credential_med_max" value="<?= $credential['risk_levels']['medium']['max_ips'] ?? 10 ?>" min="2">
                        </div>
                    </div>
                    <div class="risk-level risk-high">
                        <h4>🔴 Alto</h4>
                        <div class="form-group">
                            <label>Min IP:</label>
                            <input type="number" name="credential_high_min" value="<?= $credential['risk_levels']['high']['min_ips'] ?? 11 ?>" min="2">
                        </div>
                        <p><em>Max: illimitato</em></p>
                    </div>
                </div>
            </div>

            <!-- Configurazione Blocco Automatico -->
            <div class="config-section">
                <h2>🔒 Blocco Automatico</h2>
                <div class="description">
                    Configura il blocco automatico per i diversi livelli di rischio. Quando abilitato, il sistema bloccherà automaticamente IP o credenziali che raggiungono i livelli di rischio selezionati.
                </div>

                <!-- Blocco automatico per accessi eccessivi -->
                <h3>🚨 Blocco per Accessi Eccessivi</h3>
                <div class="form-group">
                    <label>Stato:</label>
                    <div class="fm-checkbox-group">
                        <input type="checkbox" id="auto_block_excessive_enabled" name="auto_block_excessive_enabled" <?= ($autoBlock['excessive_access']['enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="auto_block_excessive_enabled">Abilita blocco automatico IP per accessi eccessivi</label>
                    </div>
                </div>

                <div class="risk-levels">
                    <div class="risk-level risk-low">
                        <h4>🔵 Blocco Rischio Basso</h4>
                        <div class="fm-checkbox-group">
                            <input type="checkbox" id="auto_block_excessive_low" name="auto_block_excessive_low" <?= ($autoBlock['excessive_access']['risk_levels']['low'] ?? false) ? 'checked' : '' ?>>
                            <label for="auto_block_excessive_low">Blocca automaticamente</label>
                        </div>
                    </div>
                    <div class="risk-level risk-medium">
                        <h4>🟡 Blocco Rischio Medio</h4>
                        <div class="fm-checkbox-group">
                            <input type="checkbox" id="auto_block_excessive_medium" name="auto_block_excessive_medium" <?= ($autoBlock['excessive_access']['risk_levels']['medium'] ?? false) ? 'checked' : '' ?>>
                            <label for="auto_block_excessive_medium">Blocca automaticamente</label>
                        </div>
                    </div>
                    <div class="risk-level risk-high">
                        <h4>🔴 Blocco Rischio Alto</h4>
                        <div class="fm-checkbox-group">
                            <input type="checkbox" id="auto_block_excessive_high" name="auto_block_excessive_high" <?= ($autoBlock['excessive_access']['risk_levels']['high'] ?? true) ? 'checked' : '' ?>>
                            <label for="auto_block_excessive_high">Blocca automaticamente</label>
                        </div>
                    </div>
                </div>

                <!-- Blocco automatico per condivisione credenziali -->
                <h3>👥 Blocco per Condivisione Credenziali</h3>
                <div class="form-group">
                    <label>Stato:</label>
                    <div class="fm-checkbox-group">
                        <input type="checkbox" id="auto_block_credential_enabled" name="auto_block_credential_enabled" <?= ($autoBlock['credential_sharing']['enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="auto_block_credential_enabled">Abilita blocco automatico credenziali condivise</label>
                    </div>
                </div>

                <div class="risk-levels">
                    <div class="risk-level risk-low">
                        <h4>🔵 Blocco Rischio Basso</h4>
                        <div class="fm-checkbox-group">
                            <input type="checkbox" id="auto_block_credential_low" name="auto_block_credential_low" <?= ($autoBlock['credential_sharing']['risk_levels']['low'] ?? false) ? 'checked' : '' ?>>
                            <label for="auto_block_credential_low">Blocca automaticamente</label>
                        </div>
                    </div>
                    <div class="risk-level risk-medium">
                        <h4>🟡 Blocco Rischio Medio</h4>
                        <div class="fm-checkbox-group">
                            <input type="checkbox" id="auto_block_credential_medium" name="auto_block_credential_medium" <?= ($autoBlock['credential_sharing']['risk_levels']['medium'] ?? false) ? 'checked' : '' ?>>
                            <label for="auto_block_credential_medium">Blocca automaticamente</label>
                        </div>
                    </div>
                    <div class="risk-level risk-high">
                        <h4>🔴 Blocco Rischio Alto</h4>
                        <div class="fm-checkbox-group">
                            <input type="checkbox" id="auto_block_credential_high" name="auto_block_credential_high" <?= ($autoBlock['credential_sharing']['risk_levels']['high'] ?? true) ? 'checked' : '' ?>>
                            <label for="auto_block_credential_high">Blocca automaticamente</label>
                        </div>
                    </div>
                </div>

                <div class="auto-block-warning">
                    <h4>⚠️ Importante</h4>
                    <p>
                        Il blocco automatico è una misura di sicurezza avanzata. Assicurati di testare le soglie per evitare falsi positivi. 
                        È consigliabile abilitare il blocco automatico solo per i livelli di rischio ALTO inizialmente.
                    </p>
                </div>
            </div>

            <!-- Impostazioni Generali -->
            <div class="config-section">
                <h2>⚙️ Impostazioni Generali</h2>

                <div class="form-group">
                    <label>Auto-Redirect:</label>
                    <div class="fm-checkbox-group">
                        <input type="checkbox" id="auto_redirect_enabled" name="auto_redirect_enabled" <?= ($general['auto_redirect']['enabled'] ?? true) ? 'checked' : '' ?>>
                        <label for="auto_redirect_enabled">Reindirizza automaticamente agli alert quando l'admin effettua il login</label>
                    </div>
                </div>

                <div class="form-group inline-text">
                    <label>Ritenzione Log:</label>
                    <input type="number" name="log_retention_days" value="<?= $general['log_retention']['days'] ?? 90 ?>" min="1" max="365">
                    <span>giorni</span>
                </div>

                <div class="form-group inline-text">
                    <label>Ritenzione Alert:</label>
                    <input type="number" name="alert_retention_days" value="<?= $general['alert_retention']['days'] ?? 30 ?>" min="1" max="180">
                    <span>giorni</span>
                </div>
            </div>

            <!-- Pulsanti -->
            <div style="text-align: center; padding: 20px;">
                <button type="submit" name="save_config" class="btn btn-primary">💾 Salva Configurazione</button>
                <a href="security_alerts.php" class="btn btn-secondary">🔙 Torna agli Alert</a>
            </div>
        </form>
    </div>

    <script>
        // Validazione form lato client
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            
            // Gestione dinamica dei checkbox di blocco automatico
            const autoBlockExcessiveEnabled = document.getElementById('auto_block_excessive_enabled');
            const autoBlockCredentialEnabled = document.getElementById('auto_block_credential_enabled');
            
            // Gestisci l'abilitazione/disabilitazione dei livelli di rischio per accessi eccessivi
            function toggleExcessiveRiskLevels() {
                const riskCheckboxes = ['auto_block_excessive_low', 'auto_block_excessive_medium', 'auto_block_excessive_high'];
                const isEnabled = autoBlockExcessiveEnabled.checked;
                
                riskCheckboxes.forEach(id => {
                    const checkbox = document.getElementById(id);
                    if (checkbox) {
                        checkbox.disabled = !isEnabled;
                        if (!isEnabled) {
                            checkbox.checked = false;
                        }
                    }
                });
            }
            
            // Gestisci l'abilitazione/disabilitazione dei livelli di rischio per credenziali
            function toggleCredentialRiskLevels() {
                const riskCheckboxes = ['auto_block_credential_low', 'auto_block_credential_medium', 'auto_block_credential_high'];
                const isEnabled = autoBlockCredentialEnabled.checked;
                
                riskCheckboxes.forEach(id => {
                    const checkbox = document.getElementById(id);
                    if (checkbox) {
                        checkbox.disabled = !isEnabled;
                        if (!isEnabled) {
                            checkbox.checked = false;
                        }
                    }
                });
            }
            
            // Aggiungi gli event listener
            if (autoBlockExcessiveEnabled) {
                autoBlockExcessiveEnabled.addEventListener('change', toggleExcessiveRiskLevels);
                toggleExcessiveRiskLevels(); // Applica stato iniziale
            }
            
            if (autoBlockCredentialEnabled) {
                autoBlockCredentialEnabled.addEventListener('change', toggleCredentialRiskLevels);
                toggleCredentialRiskLevels(); // Applica stato iniziale
            }
            
            form.addEventListener('submit', function(e) {
                const excessiveMin = parseInt(document.querySelector('input[name="excessive_threshold"]').value);
                const credentialMinIPs = parseInt(document.querySelector('input[name="credential_min_ips"]').value);
                
                if (excessiveMin < 1) {
                    alert('La soglia accessi deve essere almeno 1');
                    e.preventDefault();
                    return;
                }
                
                if (credentialMinIPs < 2) {
                    alert('Il numero minimo di IP per la condivisione credenziali deve essere almeno 2');
                    e.preventDefault();
                    return;
                }
                
                // Avviso per il blocco automatico abilitato
                const autoBlockEnabled = autoBlockExcessiveEnabled.checked || autoBlockCredentialEnabled.checked;
                if (autoBlockEnabled) {
                    const riskLevelsSelected = 
                        document.getElementById('auto_block_excessive_low').checked ||
                        document.getElementById('auto_block_excessive_medium').checked ||
                        document.getElementById('auto_block_excessive_high').checked ||
                        document.getElementById('auto_block_credential_low').checked ||
                        document.getElementById('auto_block_credential_medium').checked ||
                        document.getElementById('auto_block_credential_high').checked;
                    
                    if (riskLevelsSelected && !confirm('⚠️ Hai abilitato il blocco automatico. Confermi di voler salvare questa configurazione? Il sistema bloccherà automaticamente IP/credenziali che raggiungono i livelli di rischio selezionati.')) {
                        e.preventDefault();
                        return;
                    }
                }
            });
        });
    </script>

    <?php renderContainerEnd(); ?>
</body>
</html>

