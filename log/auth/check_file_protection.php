<?php
/**
 * Script per verificare se un file contiene la protezione AuthCode.php
 * Legge il file sorgente grezzo invece di eseguirlo
 * 
 * Parametri POST:
 * - fileUrl: percorso relativo del file da controllare (es. /eser/ar/eser_ar3s/MAT/01_ESER-Insiemi-MAT.php)
 * 
 * Risposta JSON:
 * {
 *   "isProtected": true/false,
 *   "reason": "descrizione del motivo"
 * }
 */

header('Content-Type: application/json');

// Verifica che sia una richiesta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'error' => 'Metodo non consentito',
        'isProtected' => false
    ]);
    exit;
}

// Recupera il percorso del file
$fileUrl = isset($_POST['fileUrl']) ? $_POST['fileUrl'] : '';

if (empty($fileUrl)) {
    echo json_encode([
        'error' => 'Parametro fileUrl mancante',
        'isProtected' => false
    ]);
    exit;
}

// Costruisce il percorso assoluto del file
$documentRoot = $_SERVER['DOCUMENT_ROOT'];
$filePath = $documentRoot . $fileUrl;

// Verifica che il file esista
if (!file_exists($filePath)) {
    echo json_encode([
        'error' => 'File non trovato: ' . $fileUrl,
        'isProtected' => false,
        'filePath' => $filePath
    ]);
    exit;
}

// Verifica che sia un file (non una directory)
if (!is_file($filePath)) {
    echo json_encode([
        'error' => 'Il percorso non è un file: ' . $fileUrl,
        'isProtected' => false
    ]);
    exit;
}

try {
    // Legge il contenuto grezzo del file (SENZA eseguirlo)
    $fileContent = file_get_contents($filePath);
    
    if ($fileContent === false) {
        echo json_encode([
            'error' => 'Impossibile leggere il file: ' . $fileUrl,
            'isProtected' => false
        ]);
        exit;
    }
    
    // Pattern da cercare per identificare la protezione AuthCode.php
    $authCodePattern = "include_once \$_SERVER['DOCUMENT_ROOT'] . '/log/auth/AuthCode.php'";
    $authCodePatternAlt = 'include_once $_SERVER["DOCUMENT_ROOT"] . "/log/auth/AuthCode.php"';
    
    // Verifica se il file contiene uno dei pattern di protezione
    $hasAuthCode = (strpos($fileContent, $authCodePattern) !== false) || 
                   (strpos($fileContent, $authCodePatternAlt) !== false);
    
    if ($hasAuthCode) {
        echo json_encode([
            'isProtected' => true,
            'reason' => 'Include AuthCode.php presente nel codice sorgente',
            'fileUrl' => $fileUrl
        ]);
    } else {
        echo json_encode([
            'isProtected' => false,
            'reason' => 'Nessun include AuthCode.php trovato',
            'fileUrl' => $fileUrl
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Errore durante la verifica: ' . $e->getMessage(),
        'isProtected' => false
    ]);
}
?>
