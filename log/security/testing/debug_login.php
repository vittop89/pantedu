<?php
/**
 * Debug Login System
 * Strumento per il debug completo del sistema di autenticazione
 * Mostra informazioni dettagliate su utenti, configurazioni, sessioni e access log
 */

// Include gli elementi comuni
require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/common-elements.php';

// Include esplicitamente le funzioni degli alert per garantire coerenza - usa percorso assoluto
if (!function_exists('countActiveAlertsForAuth')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/alert_functions.php';
}

// Suppress linter warnings per funzioni definite nei file inclusi
// phpcs:disable Generic.PHP.ForbiddenFunctions
/* @phpstan-ignore-next-line */

// Controllo autenticazione admin
requireAdminAuth(); // @phpstan-ignore-line

// Assicuriamoci che il conteggio degli alert sia disponibile
$alertCount = getActiveAlertCount(); // @phpstan-ignore-line

// Funzione per caricare utenti (copia da login.php)
function loadUsers($selectedIIS = null, $selectedCLS = null) {
    // Carica prima gli utenti admin centralizzati
    $adminUsersPath = $_SERVER['DOCUMENT_ROOT'] . '/log/data/admin_users.json';
    $allUsers = [];
    
    // Carica gli admin centralizzati se il file esiste
    if (file_exists($adminUsersPath)) {
        $adminJsonContent = file_get_contents($adminUsersPath);
        $adminData = json_decode($adminJsonContent, true);
        
        if (json_last_error() === JSON_ERROR_NONE && isset($adminData['users'])) {
            $allUsers = $adminData['users'];
        }
    }
    
    // Ora carica gli utenti locali
    $selectedIIS = $selectedIIS ?: 'ar';
    $selectedCLS = $selectedCLS ?: '2s';
    
    $optsel = $selectedIIS . $selectedCLS;
    $folder = substr($optsel, 0, 2);
    if (strpos($optsel, 'b') !== false) {
        $folder .= '_b';
    }
    
    $dirName = 'eser';
    $usersJsonPath = $_SERVER['DOCUMENT_ROOT'] . "/$dirName/$folder/{$dirName}_$optsel/users/users.json";
    
    // Carica utenti locali se il file esiste
    if (file_exists($usersJsonPath)) {
        $jsonContent = file_get_contents($usersJsonPath);
        $data = json_decode($jsonContent, true);
        
        if (json_last_error() === JSON_ERROR_NONE && isset($data['users'])) {
            $localUsers = $data['users'];
            $allUsers = array_merge($localUsers, $allUsers);
        }
    }
    
    return $allUsers;
}

// Funzione per caricare configurazione
function loadConfig($selectedIIS = null, $selectedCLS = null) {
    $adminUsersPath = $_SERVER['DOCUMENT_ROOT'] . '/log/data/admin_users.json';
    $defaultConfig = [
        'max_attempts' => 5,
        'lockout_time' => 300,
        'session_timeout' => 1800,
        'password_min_length' => 8,
        'require_secure_connection' => false
    ];
    
    if (file_exists($adminUsersPath)) {
        $adminJsonContent = file_get_contents($adminUsersPath);
        $adminData = json_decode($adminJsonContent, true);
        
        if (json_last_error() === JSON_ERROR_NONE && isset($adminData['config'])) {
            return array_merge($defaultConfig, $adminData['config']);
        }
    }
    
    return $defaultConfig;
}

// Parametri di debug
$selectedIIS = $_GET['iis'] ?? $_SESSION['selectedIIS'] ?? 'ar';
$selectedCLS = $_GET['cls'] ?? $_SESSION['selectedCLS'] ?? '2s';
$testUsername = $_GET['user'] ?? '';

// Carica dati
$users = loadUsers($selectedIIS, $selectedCLS);
$config = loadConfig($selectedIIS, $selectedCLS);

// Path calculations
$optsel = $selectedIIS . $selectedCLS;
$folder = substr($optsel, 0, 2);
if (strpos($optsel, 'b') !== false) {
    $folder .= '_b';
}
$dirName = 'eser';
$usersJsonPath = $_SERVER['DOCUMENT_ROOT'] . "/$dirName/$folder/{$dirName}_$optsel/users/users.json";
$adminUsersPath = $_SERVER['DOCUMENT_ROOT'] . '/log/data/admin_users.json';

// Rendi l'HTML head
renderHtmlHead('Debug Login System'); // @phpstan-ignore-line
?>
<style>
    .error {
        color: #721c24;
        background-color: #f8d7da;
        padding: 10px;
        border-radius: 8px;
        border-left: 4px solid #dc3545;
    }
    .success {
        color: #155724;
        background-color: #d4edda;
        padding: 10px;
        border-radius: 8px;
        border-left: 4px solid #28a745;
    }
    .warning {
        color: #856404;
        background-color: #fff3cd;
        padding: 10px;
        border-radius: 8px;
        border-left: 4px solid #ffc107;
    }
    .path-info {
        background: #e3f2fd;
        border: 1px solid #007bff;
        border-radius: 8px;
        padding: 15px;
        margin: 10px 0;
    }
    .path-info strong {
        color: #007bff;
    }
    .path-info code {
        background: rgba(0, 123, 255, 0.1);
        padding: 2px 6px;
        border-radius: 4px;
        font-family: monospace;
    }
    .file-status {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 0.9em;
        margin-left: 10px;
    }
    .file-exists {
        background-color: #4CAF50;
        color: white;
    }
    .file-missing {
        background-color: #f44336;
        color: white;
    }
    details {
        margin: 15px 0;
    }
    summary {
        font-weight: 600;
        cursor: pointer;
        padding: 8px 0;
        color: #667eea;
    }
    summary:hover {
        color: #5a6fd8;
    }
    .user-card {
        background: white;
        border-radius: 8px;
        padding: 15px;
        margin: 10px 0;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    .user-card.admin {
        border-left: 4px solid #ff6b35;
        background: #fff5f2;
    }
    .user-card.student {
        border-left: 4px solid #4a90e2;
        background: #f0f8ff;
    }
    .user-name {
        font-weight: bold;
        font-size: 1.2em;
        margin-bottom: 8px;
    }
    .user-name.admin {
        color: #d73027;
    }
    .user-name.student {
        color: #2166ac;
    }
    .user-details {
        font-size: 0.9em;
        line-height: 1.4;
    }
</style>
</head>
<body>
    <?php
    renderNavbar('debug_login'); // @phpstan-ignore-line
    renderHeader('🔍 Debug Login System', 'Sistema di debugging completo per l\'autenticazione'); // @phpstan-ignore-line
    renderContainerStart(); // @phpstan-ignore-line
    ?>

    <!-- Controlli -->
    <div class="section">
        <div class="debug-controls">
            <h3>⚙️ Controlli Debug</h3>
            <form method="GET" action="">
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="iis">Indirizzo:</label>
                            <select name="iis" id="iis">
                                <option value="ar" <?php echo $selectedIIS === 'ar' ? 'selected' : ''; ?>>Artistico (ar)</option>
                                <option value="sc" <?php echo $selectedIIS === 'sc' ? 'selected' : ''; ?>>Scientifico (sc)</option>
                                <option value="cl" <?php echo $selectedIIS === 'cl' ? 'selected' : ''; ?>>Classico (cl)</option>
                                <option value="li" <?php echo $selectedIIS === 'li' ? 'selected' : ''; ?>>Linguistico (li)</option>
                                <option value="af" <?php echo $selectedIIS === 'af' ? 'selected' : ''; ?>>AFM (af)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="cls">Classe:</label>
                            <select name="cls" id="cls">
                                <option value="1s" <?php echo $selectedCLS === '1s' ? 'selected' : ''; ?>>1s</option>
                                <option value="2s" <?php echo $selectedCLS === '2s' ? 'selected' : ''; ?>>2s</option>
                                <option value="3s" <?php echo $selectedCLS === '3s' ? 'selected' : ''; ?>>3s</option>
                                <option value="4s" <?php echo $selectedCLS === '4s' ? 'selected' : ''; ?>>4s</option>
                                <option value="5s" <?php echo $selectedCLS === '5s' ? 'selected' : ''; ?>>5s</option>
                                <option value="1b" <?php echo $selectedCLS === '1b' ? 'selected' : ''; ?>>1b</option>
                                <option value="2b" <?php echo $selectedCLS === '2b' ? 'selected' : ''; ?>>2b</option>
                                <option value="3b" <?php echo $selectedCLS === '3b' ? 'selected' : ''; ?>>3b</option>
                                <option value="4b" <?php echo $selectedCLS === '4b' ? 'selected' : ''; ?>>4b</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="user">Test Username:</label>
                            <input type="text" 
                                   name="user" 
                                   id="user" 
                                   value="<?php echo htmlspecialchars($testUsername); ?>" 
                                   placeholder="Username da testare">
                        </div>
                    </div>
                    <div class="form-col-auto">
                        <button type="submit" class="btn btn-primary">🔄 Aggiorna</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Informazioni Sistema -->
    <div class="section">
        <h2>📊 Informazioni Sistema</h2>
        <div class="info-grid">
            <div class="info-card">
                <div class="info-label">Data/Ora Corrente:</div>
                <div class="info-value"><?php echo date('Y-m-d H:i:s'); ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">Indirizzo Selezionato:</div>
                <div class="info-value"><?php echo $selectedIIS; ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">Classe Selezionata:</div>
                <div class="info-value"><?php echo $selectedCLS; ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">Combinazione (optsel):</div>
                <div class="info-value"><?php echo $optsel; ?></div>
            </div>
        </div>
    </div>

    <!-- Percorsi File -->
    <div class="section">
        <h2>📁 Percorsi File</h2>
        <div class="path-info">
            <strong>Admin Users (Centralizzato):</strong><br>
            <code><?php echo $adminUsersPath; ?></code>
            <span class="file-status <?php echo file_exists($adminUsersPath) ? 'file-exists' : 'file-missing'; ?>">
                <?php echo file_exists($adminUsersPath) ? 'ESISTE' : 'MANCANTE'; ?>
            </span>
        </div>
        <div class="path-info">
            <strong>Users Locali:</strong><br>
            <code><?php echo $usersJsonPath; ?></code>
            <span class="file-status <?php echo file_exists($usersJsonPath) ? 'file-exists' : 'file-missing'; ?>">
                <?php echo file_exists($usersJsonPath) ? 'ESISTE' : 'MANCANTE'; ?>
            </span>
        </div>
    </div>

    <!-- Configurazione -->
    <div class="section">
        <h2>⚙️ Configurazione Attiva</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Parametro</th>
                    <th>Valore</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($config as $key => $value): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($key); ?></strong></td>
                    <td><?php echo is_bool($value) ? ($value ? 'true' : 'false') : htmlspecialchars($value); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Utenti Caricati -->
    <div class="section">
        <h2>👥 Utenti Caricati</h2>
        <?php if (empty($users)): ?>
            <div class="error">⚠️ Nessun utente trovato! Verificare i percorsi dei file.</div>
        <?php else: ?>
            <?php foreach ($users as $username => $userData): ?>
                <?php 
                // Determina il tipo di utente (compatibile con 'type' e 'role')
                $userType = $userData['type'] ?? $userData['role'] ?? 'student';
                $isAdmin = in_array($userType, ['admin', 'administrator', 'admin_user']);
                ?>
                <div class="user-card <?php echo $isAdmin ? 'admin' : 'student'; ?>">
                    <div class="user-name <?php echo $isAdmin ? 'admin' : 'student'; ?>">
                        <?php echo $isAdmin ? '🔑' : '🎓'; ?> <?php echo htmlspecialchars($username); ?>
                    </div>
                    <div class="user-details">
                        <strong>Tipo:</strong> <?php echo htmlspecialchars($userType); ?><br>
                        <strong>Attivo:</strong> <?php echo ($userData['active'] ?? true) ? '✅ Sì' : '❌ No'; ?><br>
                        <?php if (isset($userData['email'])): ?>
                        <strong>Email:</strong> <?php echo htmlspecialchars($userData['email']); ?><br>
                        <?php endif; ?>
                        <strong>Password Hash:</strong> <?php echo isset($userData['password_hash']) ? '✅ Presente' : '❌ Mancante'; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Test Password -->
    <?php if ($testUsername && isset($users[$testUsername])): ?>
    <div class="section">
        <h2>🧪 Test Password per "<?php echo htmlspecialchars($testUsername); ?>"</h2>
        <?php
        $userData = $users[$testUsername];
        echo "<div class='info-card'>";
        echo "<div class='info-label'>Utente Trovato:</div>";
        echo "<div class='info-value'>✅ Sì</div>";
        echo "</div>";
        
        if (isset($userData['password_hash'])) {
            echo "<div class='success'>Hash password disponibile: " . substr($userData['password_hash'], 0, 20) . "...</div>";
        }
        
        echo "<div class='info'>🔒 Password in chiaro rimosse per sicurezza</div>";
        
        if (!($userData['active'] ?? true)) {
            echo "<div class='error'>⚠️ ATTENZIONE: Utente disattivato!</div>";
        }
        ?>
    </div>
    <?php elseif ($testUsername): ?>
    <div class="section">
        <h2>🧪 Test Password per "<?php echo htmlspecialchars($testUsername); ?>"</h2>
        <div class="error">❌ Utente non trovato!</div>
    </div>
    <?php endif; ?>

    <!-- Informazioni Ultima Sessione -->
    <?php if ($testUsername): ?>
    <div class="section">
        <h2>🔐 Informazioni ultima sessione</h2>
        <?php
        // Cerca l'ultimo accesso dell'utente specificato
        $accessLogPath = $_SERVER['DOCUMENT_ROOT'] . '/log/data/access_log.json';
        $lastUserAccess = null;
        
        if (file_exists($accessLogPath)) {
            $accessLog = json_decode(file_get_contents($accessLogPath), true);
            if ($accessLog && is_array($accessLog)) {
                // Cerca dall'ultimo al primo per trovare l'ultimo accesso dell'utente
                for ($i = count($accessLog) - 1; $i >= 0; $i--) {
                    if (isset($accessLog[$i]['username']) && $accessLog[$i]['username'] === $testUsername) {
                        $lastUserAccess = $accessLog[$i];
                        break;
                    }
                }
            }
        }
        
        if ($lastUserAccess): ?>
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-label">Username:</div>
                    <div class="info-value"><?php echo htmlspecialchars($lastUserAccess['username']); ?></div>
                </div>
                <?php if (isset($lastUserAccess['timestamp'])): ?>
                <div class="info-card">
                    <div class="info-label">Ultimo Login:</div>
                    <div class="info-value"><?php echo htmlspecialchars($lastUserAccess['timestamp']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (isset($lastUserAccess['ip_address']) || isset($lastUserAccess['ip'])): ?>
                <div class="info-card">
                    <div class="info-label">IP Address:</div>
                    <div class="info-value"><?php echo htmlspecialchars($lastUserAccess['ip_address'] ?? $lastUserAccess['ip']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (isset($lastUserAccess['linkref']) || isset($lastUserAccess['class_code'])): ?>
                <div class="info-card">
                    <div class="info-label">Classe/Sezione:</div>
                    <div class="info-value"><?php 
                        $classInfo = $lastUserAccess['linkref'] ?? $lastUserAccess['class_code'] ?? 'N/A';
                        echo htmlspecialchars($classInfo); 
                    ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <details style="margin-top: 15px;">
                <summary>Mostra struttura dati log di ultimo accesso</summary>
                <pre style="margin-top: 10px; background: #f8f9fa; padding: 10px; border-radius: 5px; overflow-x: auto;">
<?php echo htmlspecialchars(json_encode($lastUserAccess, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?>
                </pre>
            </details>
        <?php else: ?>
            <div class="warning">⚠️ Nessun accesso trovato per l'utente "<?php echo htmlspecialchars($testUsername); ?>"</div>
            <div class="info">💡 Per generare dati di log, l'utente deve effettuare almeno un login di successo.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php renderContainerEnd(); // @phpstan-ignore-line ?>
</body>
</html>
