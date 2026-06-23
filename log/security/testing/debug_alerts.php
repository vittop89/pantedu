<?php
/**
 * Script di debug per testare il sistema di alert
 */
session_start();

// Include gli elementi comuni
require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/common-elements.php';

// Verifica autenticazione admin
requireAdminAuth();

echo "<h1>Debug Alert System</h1>";

// Test della funzione countActiveAlertsForAuth
$logPath = '../data/access_log.json';
$alertCount = countActiveAlertsForAuth($logPath);
echo "<p><strong>Alert attivi:</strong> $alertCount</p>";

// Test del path del file alert_functions
$alertFunctionsPath = __DIR__ . '/alert_functions.php';
echo "<p><strong>Path alert_functions.php:</strong> $alertFunctionsPath</p>";
echo "<p><strong>File esiste:</strong> " . (file_exists($alertFunctionsPath) ? 'SI' : 'NO') . "</p>";

// Test del path del log
$logPath = '../data/access_log.json';
echo "<p><strong>Path access_log.json:</strong> " . realpath($logPath) . "</p>";
echo "<p><strong>Log esiste:</strong> " . (file_exists($logPath) ? 'SI' : 'NO') . "</p>";

if (file_exists($logPath)) {
    $logData = json_decode(file_get_contents($logPath), true);
    echo "<p><strong>Entries nel log:</strong> " . count($logData ?: []) . "</p>";
}

// Test della navbar
echo "<h2>Test Navbar</h2>";
renderNavbar('debug');

echo "<p><a href='index.php'>← Torna all'index</a></p>";
?>
