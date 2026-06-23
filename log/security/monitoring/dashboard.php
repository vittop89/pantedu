<?php
/**
 * Dashboard per visualizzare le statistiche di accesso
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

// Verifica autenticazione (usando la funzione comune)
requireAdminAuth(); // @phpstan-ignore-line

require_once $_SERVER['DOCUMENT_ROOT'] . '/log/logging/access_logger.php';

$logger = new AccessLogger();

// Gli alert sono già disponibili tramite common-elements.php
// Non serve chiamare getActiveAlertCount() di nuovo qui

// Gli alert sono già disponibili come $alertCount dalla common-elements.php

// Gestione azioni
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'clean_old':
            $days = intval($_GET['days'] ?? 30);
            $removed = $logger->cleanOldLogs($days);
            $message = "Rimossi $removed record più vecchi di $days giorni.";
            break;
        case 'clear_all':
            $totalRemoved = $logger->clearAllLogs();
            $message = "Puliti completamente tutti i log. Rimossi $totalRemoved record totali.";
            break;
        case 'export':
            $type = $_GET['type'] ?? 'recent';
            exportData($logger, $type);
            exit;
    }
}

function exportData($logger, $type) {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="access_stats_' . date('Y-m-d') . '.json"');
    
    switch ($type) {
        case 'stats':
            echo json_encode($logger->getStats(), JSON_PRETTY_PRINT);
            break;
        case 'recent':
            echo json_encode($logger->getRecentAccesses(500), JSON_PRETTY_PRINT);
            break;
        default:
            echo json_encode(['error' => 'Tipo non supportato'], JSON_PRETTY_PRINT);
    }
}

/**
 * Determina se i dati utente sono in formato aggregato (per ruolo) o dettagliato (per utente)
 */
function isAggregatedData($userStats) {
    if (empty($userStats)) return false;
    
    // Controlla se esiste almeno un ruolo tipico (student, teacher, administrator)
    $roles = ['student', 'teacher', 'administrator'];
    foreach ($roles as $role) {
        if (isset($userStats[$role]) && isset($userStats[$role]['user_count'])) {
            return true;
        }
    }
    return false;
}

/**
 * Calcola statistiche compatibili da dati aggregati o dettagliati
 */
function getCompatibleUserStats($userStats) {
    if (isAggregatedData($userStats)) {
        // Dati aggregati - calcoliamo il totale utenti e accessi
        $totalUsers = 0;
        $totalAccesses = 0;
        $roleBreakdown = [];
        
        foreach ($userStats as $role => $data) {
            if (isset($data['user_count']) && isset($data['total_accesses'])) {
                $totalUsers += $data['user_count'];
                $totalAccesses += $data['total_accesses'];
                $roleBreakdown[$role] = $data;
            }
        }
        
        return [
            'type' => 'aggregated',
            'total_users' => $totalUsers,
            'total_accesses' => $totalAccesses,
            'roles' => $roleBreakdown
        ];
    } else {
        // Dati dettagliati - calcoliamo dai singoli utenti
        $totalUsers = count($userStats);
        $totalAccesses = array_sum(array_column($userStats, 'total_accesses'));
        $topUsers = $userStats;
        uasort($topUsers, function($a, $b) {
            return ($b['total_accesses'] ?? 0) - ($a['total_accesses'] ?? 0);
        });
        
        return [
            'type' => 'detailed',
            'total_users' => $totalUsers,
            'total_accesses' => $totalAccesses,
            'top_users' => array_slice($topUsers, 0, 10, true)
        ];
    }
}

$stats = $logger->getStats();
$recentAccesses = $logger->getRecentAccesses(20);

// Mappatura degli indirizzi per la visualizzazione
$institutionNames = [
    'ar' => 'Artistico',
    'sc' => 'Scientifico', 
    'cl' => 'Classico',
    'li' => 'Linguistico',
    'af' => 'AFM'
];
?>
<?php
renderHtmlHead('Dashboard Accessi Utenti');
?>
<style>
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); 
            gap: 20px; 
            margin-bottom: 30px; 
        }
        }
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); 
            gap: 20px; 
            margin-bottom: 30px; 
        }
        .stat-card { 
            background: white; 
            border-radius: 10px; 
            padding: 20px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .stat-card h3 { 
            margin-top: 0; 
            color: #333; 
            border-bottom: 2px solid #667eea; 
            padding-bottom: 10px;
        }
        .stat-number { 
            font-size: 2em; 
            font-weight: bold; 
            color: #667eea; 
            margin: 10px 0;
        }
        .stat-list { 
            max-height: 200px; 
            overflow-y: auto; 
            border: 1px solid #eee; 
            border-radius: 5px;
        }
        .stat-item { 
            padding: 8px 12px; 
            border-bottom: 1px solid #eee; 
            display: flex; 
            justify-content: space-between;
        }
        .stat-item:last-child { border-bottom: none; }
        .recent-table { 
            width: 100%; 
            border-collapse: collapse; 
            background: white; 
            border-radius: 10px; 
            overflow: hidden; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .recent-table th, .recent-table td { 
            padding: 12px; 
            text-align: left; 
            border-bottom: 1px solid #eee;
        }
        .recent-table th { 
            background: #667eea; 
            color: white; 
            font-weight: 600;
        }
        .recent-table tr:hover { background: #f8f9ff; }
        .page-cell {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-family: monospace;
            font-size: 0.9em;
            color: #666;
        }
        .actions { 
            background: white; 
            padding: 20px; 
            border-radius: 10px; 
            margin-bottom: 20px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .btn { 
            display: inline-block; 
            padding: 10px 20px; 
            margin: 5px; 
            background: #667eea; 
            color: white; 
            text-decoration: none; 
            border-radius: 5px; 
            border: none; 
            cursor: pointer;
        }
        .btn:hover { background: #5a6fd8; }
        .btn-danger { background: #e74c3c; }
        .btn-danger:hover { background: #c0392b; }
        .btn-danger-critical { 
            background: #c0392b !important; 
            border: none !important;
        }
        .btn-danger-critical:hover { 
            background: #a93226 !important; 
        }
        .btn-success { background: #27ae60; }
        .btn-success:hover { background: #219a52; }
        .message { 
            padding: 15px; 
            margin: 10px 0; 
            border-radius: 5px; 
            background: #d4edda; 
            color: #155724; 
            border: 1px solid #c3e6cb;
        }
    </style>

<?php
// Navbar dinamica
renderNavbar('dashboard');

// Header
renderHeader('📊 Dashboard Accessi Utenti', 'Sistema di monitoraggio accessi per indirizzi e classi');

// Container start con messaggi
$messages = [];
if (isset($message)) {
    $messages[] = ['type' => 'success', 'text' => $message];
}
renderContainerStart($messages);
?>
        <div class="actions">
            <h3>🔧 Azioni</h3>
            <a href="?action=export&type=stats" class="btn btn-success">📥 Esporta Statistiche</a>
            <a href="?action=export&type=recent" class="btn btn-success">📥 Esporta Accessi Recenti</a>
            <a href="?action=clean_old&days=30" class="btn btn-danger" onclick="return confirm('Rimuovere i log più vecchi di 30 giorni?')">🧹 Pulisci Log Vecchi</a>
            <a href="?action=clear_all" class="btn btn-danger btn-danger-critical" onclick="return confirm('⚠️ ATTENZIONE: Questa azione eliminerà TUTTI i log e le statistiche in modo permanente!\n\nVerranno eliminati anche TUTTI gli alert di sicurezza correlati.\n\nI blocchi IP e credenziali già attivi rimarranno invariati.\n\nQuesta operazione NON può essere annullata!\n\nSei assolutamente sicuro di voler procedere?')">🗑️ Pulisci Tutti i Log</a>
            <a href="viewer.php" class="btn">👁️ Visualizzatore Dettagliato</a>
        </div>

        <div class="stats-grid">
            <!-- Statistiche Generali -->
            <div class="stat-card">
                <h3>📈 Statistiche Generali</h3>
                <div>
                    <strong>Utenti Totali:</strong> 
                    <div class="stat-number">
                        <?php 
                        $userStatsCompat = getCompatibleUserStats($stats['user_stats'] ?? []);
                        echo $userStatsCompat['total_users']; 
                        ?>
                    </div>
                </div>
                <div>
                    <strong>Indirizzi Attivi:</strong> 
                    <div class="stat-number"><?php echo count($stats['institute_stats'] ?? []); ?></div>
                </div>
                <div>
                    <strong>Classi Attive:</strong> 
                    <div class="stat-number"><?php echo count($stats['class_stats'] ?? []); ?></div>
                </div>
            </div>

            <!-- Top Indirizzi -->
            <div class="stat-card">
                <h3>🏫 Indirizzi più Attivi</h3>
                <div class="stat-list">
                    <?php 
                    $institutes = $stats['institute_stats'] ?? [];
                    uasort($institutes, function($a, $b) {
                        return ($b['total_accesses'] ?? 0) - ($a['total_accesses'] ?? 0);
                    });
                    foreach (array_slice($institutes, 0, 10, true) as $code => $data): 
                        $name = $institutionNames[$code] ?? $code;
                    ?>
                        <div class="stat-item">
                            <span><?php echo htmlspecialchars($name); ?></span>
                            <span><?php echo $data['total_accesses'] ?? 0; ?> accessi</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Top Classi -->
            <div class="stat-card">
                <h3>🎓 Classi più Attive</h3>
                <div class="stat-list">
                    <?php 
                    $classes = $stats['class_stats'] ?? [];
                    uasort($classes, function($a, $b) {
                        return ($b['total_accesses'] ?? 0) - ($a['total_accesses'] ?? 0);
                    });
                    foreach (array_slice($classes, 0, 10, true) as $code => $data): 
                    ?>
                        <div class="stat-item">
                            <span><?php echo htmlspecialchars($code); ?></span>
                            <span><?php echo $data['total_accesses'] ?? 0; ?> accessi</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Top Utenti -->
            <div class="stat-card">
                <h3>👥 Statistiche Utenti</h3>
                <div class="stat-list">
                    <?php 
                    $userStatsCompat = getCompatibleUserStats($stats['user_stats'] ?? []);
                    
                    if ($userStatsCompat['type'] === 'aggregated'): 
                        // Mostra statistiche per ruolo (dati anonimizzati)
                        foreach ($userStatsCompat['roles'] as $role => $data):
                            $roleNames = [
                                'student' => '👨‍🎓 Studenti',
                                'teacher' => '👨‍🏫 Docenti', 
                                'administrator' => '👨‍💼 Amministratori'
                            ];
                            $displayName = $roleNames[$role] ?? ucfirst($role);
                    ?>
                        <div class="stat-item">
                            <span><?php echo $displayName; ?></span>
                            <span><?php echo $data['user_count']; ?> utenti (<?php echo $data['total_accesses']; ?> accessi)</span>
                        </div>
                    <?php 
                        endforeach;
                    else: 
                        // Mostra top utenti individuali (dati dettagliati)
                        foreach ($userStatsCompat['top_users'] as $username => $data): 
                    ?>
                        <div class="stat-item">
                            <span><?php echo htmlspecialchars($username); ?></span>
                            <span><?php echo $data['total_accesses'] ?? 0; ?> accessi</span>
                        </div>
                    <?php 
                        endforeach; 
                    endif; 
                    ?>
                    
                    <?php if ($userStatsCompat['type'] === 'aggregated'): ?>
                        <div class="stat-item" style="border-top: 1px solid #ddd; margin-top: 10px; padding-top: 10px; font-weight: bold;">
                            <span>ℹ️ Dati aggregati per privacy (GDPR)</span>
                            <span>Dettagli individuali disponibili solo per accessi recenti</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statistiche Giornaliere Recenti -->
            <div class="stat-card">
                <h3>📅 Accessi per Giorno (Ultimi 7)</h3>
                <div class="stat-list">
                    <?php 
                    $dailyStats = $stats['daily_stats'] ?? [];
                    krsort($dailyStats); // Ordina per data decrescente
                    foreach (array_slice($dailyStats, 0, 7, true) as $date => $data): 
                    ?>
                        <div class="stat-item">
                            <span><?php echo htmlspecialchars($date); ?></span>
                            <span><?php echo $data['total_accesses'] ?? 0; ?> accessi</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Accessi Recenti -->
        <div class="stat-card">
            <h3>🕒 Accessi Recenti</h3>
            <div style="overflow-x: auto;">
                <table class="recent-table">
                    <thead>
                        <tr>
                            <th>Data/Ora</th>
                            <th>Utente</th>
                            <th>Indirizzo</th>
                            <th>Classe</th>
                            <th>Pagina</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAccesses as $access): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($access['timestamp'] ?? 'N/D'); ?></td>
                                <td><?php echo htmlspecialchars($access['username'] ?? 'N/D'); ?></td>
                                <td><?php 
                                    $instituteCode = $access['institute_code'] ?? '';
                                    echo htmlspecialchars($institutionNames[$instituteCode] ?? $instituteCode ?: 'N/D'); 
                                ?></td>
                                <td><?php echo htmlspecialchars($access['class_code'] ?? 'N/D'); ?></td>
                                <td class="page-cell" title="<?php echo htmlspecialchars($access['redirect_page'] ?? $access['linkref'] ?? 'N/D'); ?>">
                                    <?php 
                                    $page = $access['redirect_page'] ?? $access['linkref'] ?? 'N/D';
                                    $pageDisplay = basename($page);
                                    if (strlen($pageDisplay) > 30) {
                                        echo htmlspecialchars(substr($pageDisplay, 0, 27) . '...');
                                    } else {
                                        echo htmlspecialchars($pageDisplay);
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($access['ip_address'] ?? 'N/D'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
    
    <!-- Modal per Alert di Sicurezza -->
    <?php if ($alertCount > 0): ?>
    <div id="securityAlertModal" class="alert-modal">
        <div class="alert-modal-content">
            <h2>🚨 Alert di Sicurezza</h2>
            <p>Rilevati <strong><?= $alertCount ?></strong> accessi anomali (>10 accessi/utente/sezione oggi).</p>
            <p>Vuoi visualizzare immediatamente gli alert di sicurezza?</p>
            <div class="alert-modal-buttons">
                <a href="security_alerts.php" class="btn btn-danger">🚨 Visualizza Alert</a>
                <button class="btn btn-secondary" onclick="closeAlertModal()">Più tardi</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Auto-apertura modal se ci sono alert
        <?php if ($alertCount > 0): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Controlla se l'utente ha già visto gli alert oggi
            const lastAlertCheck = localStorage.getItem('lastSecurityAlertCheck');
            const today = '<?= date('Y-m-d') ?>';
            
            if (lastAlertCheck !== today) {
                document.getElementById('securityAlertModal').style.display = 'flex';
            }
        });
        <?php endif; ?>

<?php
renderContainerEnd();
renderHtmlFooter();
?>
