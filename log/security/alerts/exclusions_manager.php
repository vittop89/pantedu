<?php
session_start();

// Includi le funzioni comuni
require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/common-elements.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/alert_functions.php';

// Suppress linter warnings per funzioni definite nei file inclusi
// phpcs:disable Generic.PHP.ForbiddenFunctions
/* @phpstan-ignore-next-line */

// Controllo autenticazione admin
requireAdminAuth(); // @phpstan-ignore-line

// Funzione per ottenere le esclusioni attive
function getActiveExclusions() {
    $exclusionsFile = $_SERVER['DOCUMENT_ROOT'] . '/log/data/auto_block_exclusions.json';
    if (!file_exists($exclusionsFile)) {
        return ['credentials' => [], 'ips' => []];
    }
    
    $exclusions = json_decode(file_get_contents($exclusionsFile), true);
    return $exclusions ?: ['credentials' => [], 'ips' => []];
}

// Gestione azioni POST
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'remove_credential_exclusion') {
        $username = $_POST['username'] ?? '';
        
        if (!empty($username)) {
            if (removeUsernameFromAutoBlockExclusions($username)) {
                $message = "Esclusione rimossa per '$username'. Le credenziali sono ora soggette a blocco automatico.";
                $messageType = 'success';
            } else {
                $message = "Errore nel rimuovere l'esclusione per '$username'.";
                $messageType = 'error';
            }
        }
    } elseif ($action === 'remove_ip_exclusion') {
        $ip = $_POST['ip'] ?? '';
        $section = $_POST['section'] ?? '';
        
        if (!empty($ip) && !empty($section)) {
            if (removeIPFromAutoBlockExclusions($ip, $section)) {
                $message = "Esclusione rimossa per IP '$ip' nella sezione '$section'. L'IP è ora soggetto a blocco automatico.";
                $messageType = 'success';
            } else {
                $message = "Errore nel rimuovere l'esclusione per IP '$ip' nella sezione '$section'.";
                $messageType = 'error';
            }
        }
    } elseif ($action === 'add_credential_exclusion') {
        $username = $_POST['username'] ?? '';
        $reason = $_POST['reason'] ?? '';
        
        if (!empty($username) && !empty($reason)) {
            if (addUsernameToAutoBlockExclusions($username, $reason)) {
                $message = "Esclusione aggiunta per '$username'. Le credenziali non saranno più soggette a blocco automatico.";
                $messageType = 'success';
            } else {
                $message = "Errore nell'aggiungere l'esclusione per '$username'.";
                $messageType = 'error';
            }
        }
    } elseif ($action === 'add_ip_exclusion') {
        $ip = $_POST['ip'] ?? '';
        $section = $_POST['section'] ?? '';
        $reason = $_POST['reason'] ?? '';
        
        if (!empty($ip) && !empty($reason)) {
            // Se section è vuoto, usa "generic" come valore di default
            if (empty($section)) {
                $section = 'generic';
            }
            
            if (addIPToAutoBlockExclusions($ip, $section, $reason)) {
                $sectionDisplay = ($section === 'generic') ? 'tutte le sezioni' : "sezione '$section'";
                $message = "Esclusione aggiunta per IP '$ip' nella $sectionDisplay. L'IP non sarà più soggetto a blocco automatico.";
                $messageType = 'success';
            } else {
                $message = "Errore nell'aggiungere l'esclusione per IP '$ip' nella sezione '$section'.";
                $messageType = 'error';
            }
        }
    }
    
    // Pattern PRG (Post-Redirect-Get)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($message)) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $messageType;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Recupera i messaggi flash
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$exclusions = getActiveExclusions();

renderHtmlHead('🛡️ Gestione Esclusioni Auto-Block - Pantedu Analytics');
?>
<style>
    .exclusion-card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        border-left: 5px solid #28a745;
    }
    
    .exclusion-item {
        background: rgba(0,0,0,0.05);
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 10px;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 15px;
        align-items: center;
    }
    
    .exclusion-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
    }
    
    .detail-item {
        display: flex;
        flex-direction: column;
    }
    
    .detail-label {
        font-size: 0.8em;
        color: #666;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .detail-value {
        font-weight: 500;
        margin-top: 2px;
    }
    
    .add-form {
        background: rgba(23, 162, 184, 0.1);
        border: 1px solid #17a2b8;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 30px;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
    }
    
    .form-group label {
        font-weight: 600;
        color: #555;
        margin-bottom: 5px;
    }
    
    .form-group input, .form-group textarea {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 0.9em;
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
    
    .btn-primary {
        background: #007bff;
        color: white;
    }
    
    .btn-primary:hover {
        background: #0056b3;
    }
    
    .btn-info {
        background: #17a2b8;
        color: white;
    }
    
    .btn-info:hover {
        background: #138496;
    }
    
    .message {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 500;
    }
    
    .message.success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .message.error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .no-exclusions {
        text-align: center;
        padding: 40px;
        color: #666;
        font-style: italic;
    }
    
    .section-title {
        font-size: 1.5em;
        font-weight: 600;
        margin: 30px 0 15px 0;
        padding-bottom: 10px;
        border-bottom: 2px solid #007bff;
        color: #333;
    }
</style>
</head>
<body>
    <?php renderNavbar('security_alerts'); ?>
    
    <?php renderHeader('🛡️ Gestione Esclusioni Auto-Block', 'Gestisci elementi esclusi dal blocco automatico'); ?>

    <?php renderContainerStart(); ?>

        <?php if ($message): ?>
        <div class="message <?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <div style="text-align: center; margin: 20px 0;">
            <a href="security_alerts.php" style="background: #6c757d; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-size: 16px; display: inline-flex; align-items: center; gap: 8px;">
                ← Torna agli Alert di Sicurezza
            </a>
        </div>

        <div class="section-title">➕ Aggiungi Nuove Esclusioni</div>
        
        <div class="add-form">
            <h3>Aggiungi Esclusione Credenziali</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_credential_exclusion">
                <div class="form-row">
                    <div class="form-group">
                        <label>Username:</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>Motivo:</label>
                        <input type="text" name="reason" required placeholder="Es: Account autorizzato amministratore">
                    </div>
                    <div class="form-group" style="align-self: end;">
                        <button type="submit" class="btn btn-info">Aggiungi Esclusione</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="add-form">
            <h3>Aggiungi Esclusione IP</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_ip_exclusion">
                <div class="form-row">
                    <div class="form-group">
                        <label>Indirizzo IP:</label>
                        <input type="text" name="ip" required placeholder="Es: 192.168.1.100">
                    </div>
                    <div class="form-group">
                        <label>Sezione:</label>
                        <input type="text" name="section" placeholder="Es: ar2s, li4s">
                    </div>
                    <div class="form-group">
                        <label>Motivo:</label>
                        <input type="text" name="reason" required placeholder="Es: IP autorizzato della scuola">
                    </div>
                    <div class="form-group" style="align-self: end;">
                        <button type="submit" class="btn btn-info">Aggiungi Esclusione</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="section-title">🔐 Esclusioni Credenziali Attive (<?php echo count($exclusions['credentials']); ?>)</div>
        
        <?php if (empty($exclusions['credentials'])): ?>
        <div class="no-exclusions">
            Nessuna esclusione attiva per le credenziali.
        </div>
        <?php else: ?>
        <div class="exclusion-card">
            <?php foreach ($exclusions['credentials'] as $username => $data): ?>
            <div class="exclusion-item">
                <div class="exclusion-details">
                    <div class="detail-item">
                        <span class="detail-label">Username</span>
                        <span class="detail-value"><?php echo htmlspecialchars($username); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Data Esclusione</span>
                        <span class="detail-value"><?php echo htmlspecialchars($data['excluded_at']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Motivo</span>
                        <span class="detail-value"><?php echo htmlspecialchars($data['reason']); ?></span>
                    </div>
                </div>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Sei sicuro di voler rimuovere l\'esclusione per \'<?php echo htmlspecialchars($username); ?>\'? Le credenziali torneranno ad essere soggette a blocco automatico.');">
                    <input type="hidden" name="action" value="remove_credential_exclusion">
                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
                    <button type="submit" class="btn btn-danger">Rimuovi Esclusione</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="section-title">🌐 Esclusioni IP Attive (<?php echo count($exclusions['ips']); ?>)</div>
        
        <?php if (empty($exclusions['ips'])): ?>
        <div class="no-exclusions">
            Nessuna esclusione attiva per gli IP.
        </div>
        <?php else: ?>
        <div class="exclusion-card">
            <?php foreach ($exclusions['ips'] as $key => $data): ?>
            <div class="exclusion-item">
                <div class="exclusion-details">
                    <div class="detail-item">
                        <span class="detail-label">Indirizzo IP</span>
                        <span class="detail-value"><?php echo htmlspecialchars($data['ip']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Sezione</span>
                        <span class="detail-value"><?php echo htmlspecialchars($data['section']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Data Esclusione</span>
                        <span class="detail-value"><?php echo htmlspecialchars($data['excluded_at']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Motivo</span>
                        <span class="detail-value"><?php echo htmlspecialchars($data['reason']); ?></span>
                    </div>
                </div>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Sei sicuro di voler rimuovere l\'esclusione per IP \'<?php echo htmlspecialchars($data['ip']); ?>\' nella sezione \'<?php echo htmlspecialchars($data['section']); ?>\'? L\'IP tornerà ad essere soggetto a blocco automatico.');">
                    <input type="hidden" name="action" value="remove_ip_exclusion">
                    <input type="hidden" name="ip" value="<?php echo htmlspecialchars($data['ip']); ?>">
                    <input type="hidden" name="section" value="<?php echo htmlspecialchars($data['section']); ?>">
                    <button type="submit" class="btn btn-danger">Rimuovi Esclusione</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php renderContainerEnd(); ?>
</body>
</html>
