<?php
function tailFile($filepath, $lines = 50) {
    $f = fopen($filepath, "r");
    $buffer = '';
    $chunkSize = 4096;
    $pos = -1;
    $lineCount = 0;
    $output = [];

    fseek($f, 0, SEEK_END);
    $filesize = ftell($f);

    while ($filesize > 0 && $lineCount < $lines) {
        $seek = min($chunkSize, $filesize);
        $filesize -= $seek;
        fseek($f, $filesize);
        $buffer = fread($f, $seek) . $buffer;
        $linesArr = explode("\n", $buffer);
        $lineCount = count($linesArr) - 1;
    }
    fclose($f);
    $linesArr = array_slice($linesArr, -$lines);
    return $linesArr;
}

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/log/errors/debug.log';

echo "<h2>🔍 Ultimi Log di Debug</h2>";

if (file_exists($logFile)) {
    $recentLines = tailFile($logFile, 50);

    echo "<pre style='background:#f5f5f5; padding:15px; max-height:500px; overflow-y:scroll; font-size:12px;'>";
    foreach ($recentLines as $line) {
        // Evidenzia le righe importanti
        if (strpos($line, 'LOGIN DEBUG') !== false) {
            echo "<span style='color: #007bff; font-weight: bold;'>" . htmlspecialchars($line) . "</span><br>";
        } elseif (strpos($line, 'LOGIN ERROR') !== false) {
            echo "<span style='color: #dc3545; font-weight: bold;'>" . htmlspecialchars($line) . "</span><br>";
        } elseif (strpos($line, 'LOGIN') !== false) {
            echo "<span style='color: #28a745;'>" . htmlspecialchars($line) . "</span><br>";
        } else {
            echo htmlspecialchars($line);
        }
    }
    echo "</pre>";
} else {
    echo "<p>❌ File di log non trovato: $logFile</p>";
}

echo "<p><a href='test_sidebar_login.php'>← Torna al test</a></p>";