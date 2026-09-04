<?php
/**
 * In# Assicuriamoci che il conteggio degli alert sia disponibile
$alertCount = getActiveAlertCount();ce principale dell'area Analytics - Solo per Amministratori
 */

session_start();

// Includi le funzioni comuni

require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/common-elements.php';
if (!function_exists('requireAdminAuth')) {
    die('common-elements.php non caricato o funzione non trovata');
}

// Include esplicitamente le funzioni degli alert per garantire coerenza - usa percorso assoluto
if (!function_exists('countActiveAlertsForAuth')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/alert_functions.php';
}

// Verifica autenticazione admin
requireAdminAuth();

// Assicuriamoci che il conteggio degli alert sia disponibile
$alertCount = getActiveAlertCount();

require_once $_SERVER['DOCUMENT_ROOT'] . '/log/logging/access_logger.php';
$logger = new AccessLogger();
$stats = $logger->getStats();

renderHtmlHead('Analytics - Area Amministrativa');
?>
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: white;
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 50px 20px;
            text-align: center;
        }
        .hero {
            margin-bottom: 50px;
        }
        .hero h1 {
            font-size: 3em;
            margin: 0 0 20px 0;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .hero p {
            font-size: 1.2em;
            opacity: 0.9;
            margin: 0;
        }
        .user-info {
            background: rgba(255,255,255,0.1);
            padding: 15px 25px;
            border-radius: 25px;
            display: inline-block;
            margin-bottom: 40px;
            backdrop-filter: blur(10px);
        }
        .tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 50px;
        }
        .tool-card {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .tool-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.15);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .tool-icon {
            font-size: 3em;
            margin-bottom: 20px;
            display: block;
        }
        .tool-card h3 {
            margin: 0 0 15px 0;
            font-size: 1.4em;
        }
        .tool-card p {
            margin: 0 0 25px 0;
            opacity: 0.9;
            line-height: 1.6;
        }
        .btn {
            display: inline-block;
            padding: 12px 25px;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            background: #f8f9ff;
        }
        .stats-overview {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .stat-item {
            text-align: center;
            padding: 20px;
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
        }
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
            color: #ffffff;
        }
        .stat-label {
            opacity: 0.8;
            font-size: 0.9em;
        }
        .footer {
            text-align: center;
            opacity: 0.7;
            margin-top: 50px;
        }
        /* .nav-links {
            margin-top: 30px;
        } */
        .nav-links a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            margin: 0 15px;
            padding: 8px 16px;
            border-radius: 20px;
            transition: all 0.3s ease;
        }
        .nav-links a:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
    </style>
</head>
<body>
    <?php renderNavbar('index'); ?>
    
    <?php renderContainerStart(); ?>
    
    <?php renderHeader('🛡️ Analytics Dashboard', 'Sistema di monitoraggio e analisi accessi utenti'); ?>

        <div class="user-info">
            <strong>👤 Benvenuto, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Amministratore'); ?>!</strong>
            Ultima sessione: <?php echo date('d/m/Y H:i', $_SESSION['login_time'] ?? time()); ?>
        </div>

        <!-- Statistiche Rapide -->
        <div class="stats-overview">
            <h3>📊 Panoramica Sistema</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number"><?php echo count($stats['user_stats'] ?? []); ?></div>
                    <div class="stat-label">Utenti Attivi</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo count($stats['institute_stats'] ?? []); ?></div>
                    <div class="stat-label">Indirizzi</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo count($stats['class_stats'] ?? []); ?></div>
                    <div class="stat-label">Classi</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo array_sum(array_column($stats['daily_stats'] ?? [], 'total_accesses')); ?></div>
                    <div class="stat-label">Accessi Totali</div>
                </div>
            </div>
        </div>

        <!-- Strumenti Disponibili -->
        <div class="tools-grid">
            <div class="tool-card">
                <span class="tool-icon">📊</span>
                <h3>Dashboard Principale</h3>
                <p>Visualizza statistiche generali, top utenti, indirizzi più attivi e accessi recenti con grafici intuitivi.</p>
                <a href="dashboard.php" class="btn">Apri Dashboard</a>
            </div>

            <div class="tool-card">
                <span class="tool-icon">👁️</span>
                <h3>Visualizzatore Avanzato</h3>
                <p>Strumento di ricerca avanzata con filtri per utente, indirizzo, classe e data per analisi dettagliate.</p>
                <a href="viewer.php" class="btn">Apri Visualizzatore</a>
            </div>

            <div class="tool-card">
                <span class="tool-icon">🔍</span>
                <h3>Debug System</h3>
                <p>Diagnostica completa del sistema di autenticazione con informazioni tecniche e troubleshooting.</p>
                <a href="../testing/debug_login.php" class="btn">Apri Debug</a>
            </div>
        </div>

        <!-- Accessi Rapidi -->
        <div class="nav-links">
            <h3>🔗 Accessi Rapidi</h3>
            <a href="/log/security/monitoring/index.php">🏠 Torna al Sistema</a>
            <a href="../auth/logout.php">🚪 Logout</a>
            <a href="../../">📚 Sito Principale</a>
        </div>

        <div class="footer">
            <p>Sistema di Analytics v1.0 - Aggiornato <?php echo date('d/m/Y H:i'); ?></p>
        </div>

    <?php renderContainerEnd(); ?>
</body>
</html>
