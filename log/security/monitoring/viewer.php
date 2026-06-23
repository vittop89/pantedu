<?php
/**
 * Visualizzatore dettagliato degli accessi
 */

// Include gli elementi comuni
require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/common-elements.php';
// Suppress linter warnings per funzioni definite nei file inclusi
// phpcs:disable Generic.PHP.ForbiddenFunctions
/* @phpstan-ignore-next-line */

// Verifica autenticazione (usando la funzione comune)
requireAdminAuth(); // @phpstan-ignore-line

require_once $_SERVER['DOCUMENT_ROOT'] . '/log/logging/access_logger.php';

$logger = new AccessLogger();

// Gli alert sono già disponibili come $alertCount dalla common-elements.php

// Parametri di filtro
$filterUser = $_GET['user'] ?? '';
$filterInstitute = $_GET['institute'] ?? '';
$filterClass = $_GET['class'] ?? '';
$filterDate = $_GET['date'] ?? '';
$limit = intval($_GET['limit'] ?? 100);

// Ottiene i dati filtrati
$accesses = [];
if ($filterUser) {
    $accesses = $logger->getUserAccesses($filterUser, $limit);
} elseif ($filterInstitute) {
    $accesses = $logger->getInstituteAccesses($filterInstitute, $limit);
} else {
    $accesses = $logger->getRecentAccesses($limit);
}

// Filtra per data se specificata
if ($filterDate && $accesses) {
    $accesses = array_filter($accesses, function($access) use ($filterDate) {
        return isset($access['date']) && $access['date'] === $filterDate;
    });
}

// Filtra per classe se specificata
if ($filterClass && $accesses) {
    $accesses = array_filter($accesses, function($access) use ($filterClass) {
        return isset($access['class_code']) && $access['class_code'] === $filterClass;
    });
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

$stats = $logger->getStats();

// Mappatura degli indirizzi
$institutionNames = [
    'ar' => 'Artistico',
    'sc' => 'Scientifico',
    'cl' => 'Classico',
    'li' => 'Linguistico',
    'af' => 'AFM'
];
?>
<?php
renderHtmlHead('Visualizzatore Accessi Dettagliato');
?>
<style>
        * { box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            background: #f5f6fa;
        }
        
        .header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            padding: 20px; 
            text-align: center;
        }
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 20px; 
        }
        .filters { 
            background: white; 
            padding: 20px; 
            border-radius: 10px; 
            margin-bottom: 20px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .filter-row { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 15px; 
            margin-bottom: 15px;
        }
        .filter-group label { 
            display: block; 
            margin-bottom: 5px; 
            font-weight: 600; 
            color: #333;
        }
        .filter-group select, .filter-group input { 
            width: 100%; 
            padding: 8px 12px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
            font-size: 14px;
        }
        .btn { 
            padding: 10px 20px; 
            background: #667eea; 
            color: white; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer; 
            text-decoration: none; 
            display: inline-block;
        }
        .btn:hover { background: #5a6fd8; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #5a6268; }
        .results-header { 
            background: white; 
            padding: 15px 20px; 
            border-radius: 10px 10px 0 0; 
            border-bottom: 2px solid #667eea;
        }
        .results-table { 
            width: 100%; 
            border-collapse: collapse; 
            background: white; 
            border-radius: 0 0 10px 10px; 
            overflow: hidden; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .results-table th, .results-table td { 
            padding: 12px; 
            text-align: left; 
            border-bottom: 1px solid #eee; 
            vertical-align: top;
        }
        .results-table th { 
            background: #667eea; 
            color: white; 
            font-weight: 600; 
            position: sticky; 
            top: 0; 
            z-index: 10;
        }
        .results-table tr:hover { background: #f8f9ff; }
        .results-table tr:last-child td { border-bottom: none; }
        .address-badge { 
            display: inline-block; 
            padding: 4px 8px; 
            background: #e9ecef; 
            border-radius: 12px; 
            font-size: 12px; 
            font-weight: 600; 
            color: #495057;
        }
        .class-badge { 
            display: inline-block; 
            padding: 4px 8px; 
            background: #d1ecf1; 
            border-radius: 12px; 
            font-size: 12px; 
            font-weight: 600; 
            color: #0c5460;
        }
        .role-badge { 
            display: inline-block; 
            padding: 4px 8px; 
            border-radius: 12px; 
            font-size: 12px; 
            font-weight: 600;
        }
        .role-student { background: #d4edda; color: #155724; }
        .role-administrator { background: #f8d7da; color: #721c24; }
        .no-results { 
            text-align: center; 
            padding: 40px; 
            color: #6c757d; 
            background: white; 
            border-radius: 10px;
        }
        .stats-summary { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); 
            gap: 20px; 
            margin-bottom: 20px;
        }
        .stat-box { 
            background: white; 
            padding: 20px; 
            border-radius: 10px; 
            text-align: center; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .stat-number { 
            font-size: 2em; 
            font-weight: bold; 
            color: #667eea; 
            margin-bottom: 5px;
        }
        .stat-label { 
            color: #6c757d; 
            font-size: 0.9em;
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
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        .debug-info {
            background: #f8f9fa;
            border-left: 4px solid #6c757d;
            padding: 12px 16px;
            margin: 10px 0;
            border-radius: 0 5px 5px 0;
            color: #495057;
            font-size: 0.9em;
        }
        .debug-info .icon {
            color: #6c757d;
            margin-right: 8px;
        }
        .page-cell {
            max-width: 180px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-family: monospace;
            font-size: 0.9em;
            color: #666;
        }
    </style>

<?php
// Navbar dinamica
renderNavbar('viewer');

// Header
renderHeader('👁️ Visualizzatore Accessi Dettagliato', 'Analisi dettagliata degli accessi per indirizzi e classi');

// Container start
renderContainerStart();
?>
        <!-- Nota informativa debug -->
        <div class="debug-info">
            <span class="icon">🔍</span>
            <strong>Informazioni aggiuntive:</strong> Per ulteriori dettagli di debug e troubleshooting, consultare il file <code>debug.log</code> accessibile lato server.
        </div>

        <!-- Filtri -->
        <div class="filters">
            <h3>🔍 Filtri di Ricerca</h3>
            <form method="GET">
                <div class="filter-row">
                    <div class="filter-group">
                        <label>👤 Utente:</label>
                        <select name="user">
                            <option value="">-- Tutti gli utenti --</option>
                            <?php
                            $userStats = $stats['user_stats'] ?? [];
                            
                            if (isAggregatedData($userStats)):
                                // Per dati aggregati, mostra avviso
                            ?>
                                <option value="" disabled>⚠️ Filtro utenti non disponibile con dati aggregati</option>
                                <option value="" disabled>ℹ️ Dettagli utenti disponibili solo per accessi recenti</option>
                            <?php 
                            else:
                                // Per dati dettagliati, mostra lista utenti
                                foreach ($userStats as $username => $data):
                            ?>
                                <option value="<?php echo htmlspecialchars($username); ?>" <?php echo $filterUser === $username ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($username); ?> (<?php echo $data['total_accesses'] ?? 0; ?> accessi)
                                </option>
                            <?php 
                                endforeach; 
                            endif;
                            ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>🏫 Indirizzo:</label>
                        <select name="institute">
                            <option value="">-- Tutti gli indirizzi --</option>
                            <?php
                            $instituteStats = $stats['institute_stats'] ?? [];
                            foreach ($instituteStats as $code => $data):
                                $name = $institutionNames[$code] ?? $code;
                            ?>
                                <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $filterInstitute === $code ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($name); ?> (<?php echo $data['total_accesses'] ?? 0; ?> accessi)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>🎓 Classe:</label>
                        <select name="class">
                            <option value="">-- Tutte le classi --</option>
                            <?php
                            $classStats = $stats['class_stats'] ?? [];
                            foreach ($classStats as $code => $data):
                            ?>
                                <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $filterClass === $code ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($code); ?> (<?php echo $data['total_accesses'] ?? 0; ?> accessi)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>📅 Data:</label>
                        <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label>📊 Limite Risultati:</label>
                        <select name="limit">
                            <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 risultati</option>
                            <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 risultati</option>
                            <option value="200" <?php echo $limit == 200 ? 'selected' : ''; ?>>200 risultati</option>
                            <option value="500" <?php echo $limit == 500 ? 'selected' : ''; ?>>500 risultati</option>
                        </select>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 15px;">
                    <button type="submit" class="btn">🔍 Applica Filtri</button>
                    <a href="?" class="btn btn-secondary">🔄 Reset</a>
                    <a href="dashboard.php" class="btn btn-secondary">📊 Dashboard</a>
                </div>
            </form>
        </div>

        <!-- Statistiche Riassuntive -->
        <div class="stats-summary">
            <div class="stat-box">
                <div class="stat-number"><?php echo count($accesses); ?></div>
                <div class="stat-label">Accessi Trovati</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo count(array_unique(array_column($accesses, 'username'))); ?></div>
                <div class="stat-label">Utenti Unici</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo count(array_unique(array_filter(array_column($accesses, 'institute_code')))); ?></div>
                <div class="stat-label">Indirizzi</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo count(array_unique(array_filter(array_column($accesses, 'class_code')))); ?></div>
                <div class="stat-label">Classi</div>
            </div>
        </div>

        <!-- Risultati -->
        <?php if (!empty($accesses)): ?>
            <div class="results-header">
                <h3>📋 Risultati della Ricerca</h3>
                <p>Mostrando <?php echo count($accesses); ?> accessi</p>
            </div>
            
            <div style="overflow-x: auto;">
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>Data/Ora</th>
                            <th>Utente</th>
                            <th>Ruolo</th>
                            <th>Indirizzo</th>
                            <th>Classe</th>
                            <th>Pagina</th>
                            <th>IP</th>
                            <th>Materia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accesses as $access): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($access['timestamp'] ?? 'N/D'); ?></td>
                                <td><?php echo htmlspecialchars($access['username'] ?? 'N/D'); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo $access['role'] ?? 'student'; ?>">
                                        <?php echo ucfirst($access['role'] ?? 'student'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($access['institute_code'] ?? null): ?>
                                        <span class="address-badge">
                                            <?php echo htmlspecialchars($institutionNames[$access['institute_code']] ?? $access['institute_code']); ?>
                                        </span>
                                    <?php else: ?>
                                        N/D
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($access['class_code'] ?? null): ?>
                                        <span class="class-badge"><?php echo htmlspecialchars($access['class_code']); ?></span>
                                    <?php else: ?>
                                        N/D
                                    <?php endif; ?>
                                </td>
                                <td class="page-cell" title="<?php echo htmlspecialchars($access['redirect_page'] ?? $access['linkref'] ?? 'N/D'); ?>">
                                    <?php 
                                    $page = $access['redirect_page'] ?? $access['linkref'] ?? 'N/D';
                                    $pageDisplay = basename($page);
                                    if (strlen($pageDisplay) > 25) {
                                        echo htmlspecialchars(substr($pageDisplay, 0, 22) . '...');
                                    } else {
                                        echo htmlspecialchars($pageDisplay);
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($access['ip_address'] ?? 'N/D'); ?></td>
                                <td><?php echo htmlspecialchars($access['subject'] ?? 'N/D'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php else: ?>
            <div class="no-results">
                <h3>🔍 Nessun risultato trovato</h3>
                <p>Non sono stati trovati accessi con i criteri di ricerca specificati.</p>
                <a href="?" class="btn">🔄 Reset Filtri</a>
            </div>
        <?php endif; ?>

    <?php renderContainerEnd(); ?>
</body>
</html>
