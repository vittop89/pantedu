<?php
/**
 * @deprecated (Phase 13) — user management legacy UI (1606 righe).
 *
 * Sostituita parzialmente da:
 *   - /admin/registrations            (RegistrationController::listPending)
 *   - /admin/registrations/{id}/approve  (approva self-signup)
 *   - /admin/whoami                   (AdminController::whoAmI)
 *   - /admin/tools/hash               (hash generator moderno)
 *
 * Operazioni ancora legacy (create/edit/delete da form PHP): candidate
 * per migrazione in `AdminController::users*` + views/admin/users.php
 * quando sarà priorità.
 */

// Include gli elementi comuni
require_once $_SERVER['DOCUMENT_ROOT'] . '/log/security/alerts/common-elements.php';

// Suppress linter warnings per funzioni definite nei file inclusi
// phpcs:disable Generic.PHP.ForbiddenFunctions
/* @phpstan-ignore-next-line */

// Verifica autenticazione (usando la funzione comune)
requireAdminAuth(); // @phpstan-ignore-line

// Genera il token CSRF per questa sessione admin (se non già presente)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Se è una richiesta POST, gestisci le azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Verifica token CSRF — protezione contro richieste cross-site forgery
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Richiesta non autorizzata (token CSRF non valido)']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    
    // Validazione password admin per operazioni pericolose
    // Nota: add_user è protetto da controllo ruolo sessione separato (vedi sotto)
    if (in_array($action, ['clear_users', 'delete_user', 'add_collaborator'])) {
        // Determina quale campo password usare in base all'azione
        $admin_password = '';
        if ($action === 'add_collaborator') {
            // La password per questa azione arriva dal campo specifico del form collaboratori
            $admin_password = $_POST['admin_password'] ?? ''; // Inviata dal JS come 'admin_password'
        } else {
            // Per le altre azioni, arriva dal popup di autenticazione
            $admin_password = $_POST['admin_password'] ?? '';
        }

        if (empty($admin_password)) {
            echo json_encode(['success' => false, 'message' => 'Password amministratore richiesta']);
            exit;
        }
        
        // Leggi la password admin dal file JSON (percorso più robusto)
        $admin_file = $_SERVER['DOCUMENT_ROOT'] . '/log/data/admin_users.json';
        if (!file_exists($admin_file)) {
            echo json_encode([
                'success' => false, 
                'message' => 'File admin non trovato'
            ]);
            exit;
        }
        
        $admin_data = json_decode(file_get_contents($admin_file), true);
        if (!isset($admin_data['users']['admin']['password_hash'])) {
            echo json_encode([
                'success' => false, 
                'message' => 'Admin non configurato correttamente'
            ]);
            exit;
        }
        
        $correct_admin_hash = $admin_data['users']['admin']['password_hash'];
        
        if (!password_verify($admin_password, $correct_admin_hash)) {
            echo json_encode([
                'success' => false, 
                'message' => 'Password admin non corretta'
            ]);
            exit;
        }
    }
    
    if ($action === 'add_user') {
        // Verifica aggiuntiva: solo utenti con ruolo admin possono aggiungere utenti
        $session_role = $_SESSION['role'] ?? $_SESSION['admin_role'] ?? '';
        if ($session_role !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permessi insufficienti per questa operazione']);
            exit;
        }
        handleAddUser();
    } else if ($action === 'clear_users') {
        handleClearUsers();
    } else if ($action === 'delete_user') {
        handleDeleteUser();
    } else if ($action === 'add_collaborator') {
        handleAddCollaborator();
    } else {
        echo json_encode(['success' => false, 'message' => 'Azione non valida']);
        exit;
    }
}

function handleAddUser() {
    // Validazione dei dati in input
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $indirizzo = trim($_POST['indirizzo'] ?? '');
    $classe = trim($_POST['classe'] ?? '');

    if (empty($username) || empty($password) || empty($indirizzo) || empty($classe)) {
        echo json_encode(['success' => false, 'message' => 'Tutti i campi sono obbligatori']);
        exit;
    }

    if (strlen($username) < 5) {
        echo json_encode(['success' => false, 'message' => 'L\'username deve essere di almeno 5 caratteri']);
        exit;
    }

    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'La password deve essere di almeno 8 caratteri']);
        exit;
    }

    // Validazione caratteri speciali
    $special_chars = ',;.:-_@#[]\|!"£$%&/()=?^\'+*°ç';
    $special_count = 0;
    for ($i = 0; $i < strlen($password); $i++) {
        if (strpos($special_chars, $password[$i]) !== false) {
            $special_count++;
        }
    }
    
    if ($special_count < 2) {
        echo json_encode(['success' => false, 'message' => 'La password deve contenere almeno 2 caratteri speciali tra: ' . $special_chars]);
        exit;
    }

    // Validazione almeno un numero
    if (!preg_match('/[0-9]/', $password)) {
        echo json_encode(['success' => false, 'message' => 'La password deve contenere almeno un numero']);
        exit;
    }

    // Validazione indirizzo
    $indirizzi_validi = ['sc', 'cl', 'li', 'ar', 'af', 'afm'];
    if (!in_array($indirizzo, $indirizzi_validi)) {
        echo json_encode(['success' => false, 'message' => 'Indirizzo non valido']);
        exit;
    }

    // Validazione classe
    $classi_valide = ['1s', '2s', '3s', '4s', '5s', '1b', '2b', '3b', '4b'];
    if (!in_array($classe, $classi_valide)) {
        echo json_encode(['success' => false, 'message' => 'Classe non valida']);
        exit;
    }

    // Costruisci il percorso della cartella
    $base_path = dirname(dirname(__DIR__)) . '/eser';  // Va su di 2 livelli: admin -> log -> root
    $folder_name = 'eser_' . $indirizzo . $classe;
    $folder_path = $base_path . '/' . $indirizzo . '/' . $folder_name;

    if (!is_dir($folder_path)) {
        echo json_encode(['success' => false, 'message' => 'Cartella non trovata: ' . $folder_name]);
        exit;
    }

    // Crea la cartella users se non esiste
    $users_folder = $folder_path . '/users';
    if (!is_dir($users_folder)) {
        if (!mkdir($users_folder, 0755, true)) {
            echo json_encode(['success' => false, 'message' => 'Impossibile creare la cartella users']);
            exit;
        }
    }

    // Percorso del file users.json
    $users_file = $users_folder . '/users.json';

    // Leggi il file esistente o crea una struttura vuota
    $users_data = ['users' => []];
    if (file_exists($users_file)) {
        $json_content = file_get_contents($users_file);
        if ($json_content !== false) {
            $decoded = json_decode($json_content, true);
            if ($decoded !== null && isset($decoded['users'])) {
                $users_data = $decoded;
            }
        }
    }

    // Controlla se l'username esiste già
    if (isset($users_data['users'][$username])) {
        echo json_encode(['success' => false, 'message' => 'Username già esistente']);
        exit;
    }

    // Crea l'hash della password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Crea il codice corso combinando indirizzo e classe
    $course_code = $indirizzo . $classe;

    // Aggiungi il nuovo utente (SOLO password_hash per sicurezza)
    $users_data['users'][$username] = [
        'password_hash' => $password_hash,
        'role' => 'student',
        'course' => $course_code,
        'created' => date('Y-m-d'),
        'active' => true
    ];

    // Salva il file JSON
    $json_output = json_encode($users_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($users_file, $json_output) === false) {
        echo json_encode(['success' => false, 'message' => 'Errore nel salvataggio del file']);
        exit;
    }

    // Imposta i permessi del file
    chmod($users_file, 0644);

    echo json_encode([
        'success' => true, 
        'message' => 'Utente "' . $username . '" aggiunto con successo in ' . $folder_name,
        'path' => $users_file,
        'security_note' => 'Password salvata solo in formato hash per sicurezza'
    ]);
    exit;
}

function handleClearUsers() {
    $indirizzo = trim($_POST['indirizzo'] ?? '');
    $classe = trim($_POST['classe'] ?? '');

    if (empty($indirizzo) || empty($classe)) {
        echo json_encode(['success' => false, 'message' => 'Indirizzo e classe sono obbligatori']);
        exit;
    }

    // Validazione indirizzo e classe
    $indirizzi_validi = ['sc', 'cl', 'li', 'ar', 'af', 'afm'];
    $classi_valide = ['1s', '2s', '3s', '4s', '5s', '1b', '2b', '3b', '4b'];
    
    if (!in_array($indirizzo, $indirizzi_validi) || !in_array($classe, $classi_valide)) {
        echo json_encode(['success' => false, 'message' => 'Indirizzo o classe non validi']);
        exit;
    }

    // Costruisci il percorso del file
    $base_path = dirname(dirname(__DIR__)) . '/eser';
    $folder_name = 'eser_' . $indirizzo . $classe;
    $folder_path = $base_path . '/' . $indirizzo . '/' . $folder_name;
    $users_file = $folder_path . '/users/users.json';

    if (!file_exists($users_file)) {
        // Path assoluto solo nel log server, mai nella risposta client
        error_log("user_manager handleClearUsers: file non trovato — {$users_file}");
        echo json_encode(['success' => false, 'message' => "File users.json non trovato per {$folder_name}"]);
        exit;
    }

    // Conta gli utenti esistenti prima della pulizia
    $existing_data = json_decode(file_get_contents($users_file), true);
    $user_count = isset($existing_data['users']) ? count($existing_data['users']) : 0;

    // Crea struttura vuota
    $empty_data = ['users' => []];
    $json_output = json_encode($empty_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if (file_put_contents($users_file, $json_output) === false) {
        echo json_encode(['success' => false, 'message' => 'Errore nella pulizia del file']);
        exit;
    }

    echo json_encode([
        'success' => true, 
        'message' => "File users.json pulito con successo per {$folder_name}. Rimossi {$user_count} utenti.",
        'cleared_users' => $user_count
    ]);
    exit;
}

function handleDeleteUser() {
    $indirizzo = trim($_POST['indirizzo'] ?? '');
    $classe = trim($_POST['classe'] ?? '');
    $username = trim($_POST['target_username'] ?? '');

    if (empty($indirizzo) || empty($classe) || empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Indirizzo, classe e username sono obbligatori']);
        exit;
    }

    // Validazione indirizzo e classe
    $indirizzi_validi = ['sc', 'cl', 'li', 'ar', 'af', 'afm'];
    $classi_valide = ['1s', '2s', '3s', '4s', '5s', '1b', '2b', '3b', '4b'];
    
    if (!in_array($indirizzo, $indirizzi_validi) || !in_array($classe, $classi_valide)) {
        echo json_encode(['success' => false, 'message' => 'Indirizzo o classe non validi']);
        exit;
    }

    // Costruisci il percorso del file
    $base_path = dirname(dirname(__DIR__)) . '/eser';
    $folder_name = 'eser_' . $indirizzo . $classe;
    $folder_path = $base_path . '/' . $indirizzo . '/' . $folder_name;
    $users_file = $folder_path . '/users/users.json';

    if (!file_exists($users_file)) {
        // Path assoluto solo nel log server, mai nella risposta client
        error_log("user_manager handleDeleteUser: file non trovato — {$users_file}");
        echo json_encode(['success' => false, 'message' => "File users.json non trovato per {$folder_name}"]);
        exit;
    }

    // Leggi il file esistente
    $users_data = json_decode(file_get_contents($users_file), true);
    
    if (!isset($users_data['users']) || !isset($users_data['users'][$username])) {
        // Log server-side senza esporre la lista utenti al client
        error_log("user_manager: username '{$username}' non trovato in {$folder_name}");
        echo json_encode([
            'success' => false,
            'message' => "Username '{$username}' non trovato in {$folder_name}"
        ]);
        exit;
    }

    // Rimuovi l'utente
    unset($users_data['users'][$username]);

    // Salva il file aggiornato
    $json_output = json_encode($users_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($users_file, $json_output) === false) {
        echo json_encode(['success' => false, 'message' => 'Errore nel salvataggio del file']);
        exit;
    }

    echo json_encode([
        'success' => true, 
        'message' => "Utente '{$username}' eliminato con successo da {$folder_name}",
        'remaining_users' => count($users_data['users'])
    ]);
    exit;
}

function handleAddCollaborator() {
    // Validazione dei dati in input
    $collab_username = trim($_POST['collab_username'] ?? '');
    $collab_password = trim($_POST['collab_password_new'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $collab_notes = trim($_POST['collab_notes'] ?? '');

    if (empty($collab_username) || empty($collab_password) || empty($confirm_password)) {
        echo json_encode(['success' => false, 'message' => 'Username, password e conferma password sono obbligatori']);
        exit;
    }

    if (strlen($collab_username) < 5) {
        echo json_encode(['success' => false, 'message' => 'L\'username collaboratore deve essere di almeno 5 caratteri']);
        exit;
    }

    if (strlen($collab_password) < 10) {
        echo json_encode(['success' => false, 'message' => 'La password collaboratore deve essere di almeno 10 caratteri']);
        exit;
    }

    if ($collab_password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'Le password non coincidono']);
        exit;
    }

    // Validazione password per collaboratore (meno rigorosa dell'admin)
    $special_chars = ',;.:-_@#[]\|!"£$%&/()=?^\'+*°ç';
    $special_count = 0;
    for ($i = 0; $i < strlen($collab_password); $i++) {
        if (strpos($special_chars, $collab_password[$i]) !== false) {
            $special_count++;
        }
    }
    
    if ($special_count < 2) {
        echo json_encode(['success' => false, 'message' => 'La password collaboratore deve contenere almeno 2 caratteri speciali']);
        exit;
    }

    // Validazione almeno 1 numero
    if (!preg_match('/[0-9]/', $collab_password)) {
        echo json_encode(['success' => false, 'message' => 'La password collaboratore deve contenere almeno 1 numero']);
        exit;
    }

    // Percorso del file collaboratori (percorso robusto)
    $collab_file = $_SERVER['DOCUMENT_ROOT'] . '/log/data/collaborators.json';
    
    if (!file_exists($collab_file)) {
        echo json_encode(['success' => false, 'message' => 'File collaboratori non trovato']);
        exit;
    }

    // Leggi il file collaboratori esistente
    $collab_data = json_decode(file_get_contents($collab_file), true);
    
    if (!$collab_data) {
        echo json_encode(['success' => false, 'message' => 'File collaboratori corrotto']);
        exit;
    }

    // Controlla se l'username già esiste (incluso active_users)
    $all_sections = ['pending_users', 'approved_users', 'rejected_users', 'active_users'];
    foreach ($all_sections as $section) {
        if (isset($collab_data[$section]) && isset($collab_data[$section][$collab_username])) {
            $status_names = [
                'pending_users' => 'in attesa di approvazione',
                'approved_users' => 'già approvato',
                'rejected_users' => 'rifiutato precedentemente',
                'active_users' => 'già attivo'
            ];
            echo json_encode([
                'success' => false, 
                'message' => 'Username collaboratore già esistente (' . ($status_names[$section] ?? 'in un\'altra sezione') . ')'
            ]);
            exit;
        }
    }

    // Crea l'hash della password collaboratore
    $password_hash = password_hash($collab_password, PASSWORD_DEFAULT);

    // Aggiungi il collaboratore direttamente come attivo
    if (!isset($collab_data['active_users'])) {
        $collab_data['active_users'] = [];
    }

    // Ottieni l'admin corrente (gestito dal sistema di autenticazione)
    $current_admin = 'admin'; // Default sicuro se non c'è sessione
    
    // Se requireAdminAuth() usa le sessioni, recupera l'username corrente
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['admin_username'])) {
        $current_admin = $_SESSION['admin_username'];
    } else if (isset($_SESSION['username'])) {
        $current_admin = $_SESSION['username'];
    }

    $collab_data['active_users'][$collab_username] = [
        'password_hash' => $password_hash,
        'notes' => $collab_notes,
        'role' => 'collaborator',
        'status' => 'active',
        'created_date' => date('Y-m-d H:i:s'),
        'created_by_admin' => $current_admin,
        'last_login' => null
    ];

    // Aggiorna metadati
    $collab_data['metadata']['last_updated'] = date('Y-m-d H:i:s');

    // Salva il file JSON
    $json_output = json_encode($collab_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($collab_file, $json_output) === false) {
        echo json_encode(['success' => false, 'message' => 'Errore nel salvataggio del file collaboratori']);
        exit;
    }

    // Imposta i permessi del file
    chmod($collab_file, 0644);

    echo json_encode([
        'success' => true, 
        'message' => 'Collaboratore "' . $collab_username . '" creato con successo',
        'details' => 'Il collaboratore è ora attivo e può accedere al sistema',
        'security_note' => 'Password salvata in formato hash sicuro'
    ]);
    exit;
}

renderHtmlHead('Gestione Utenti'); // @phpstan-ignore-line
?>
<style>
        /* Override per mantenere gli stili originali del User Manager */
        .container h1, .container h2, .container h3 {
            color: #333 !important;
        }
        
        .header h1 {
            color: white !important;
        }
        
        .um-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            padding: 30px;
        }
        
        .um-container h1, .um-container h2, .um-container h3 {
            text-align: center;
            color: #333;
            margin-bottom: 15px;
            font-size: 28px;
        }
        
        .security-banner {
            background: linear-gradient(45deg, #27ae60, #2ecc71);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
            font-weight: bold;
        }
        
        .security-banner .icon {
            font-size: 1.5em;
            margin-right: 10px;
        }
        
        .fm-form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            align-items: center;
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
        }
        
        .form-group {
            flex: 1;
            display: flow-root;
        }
        
        .um-sel-lab, .form-group label {
            display: block;
            font-weight: 600;
            color: #333 !important;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .um-sel, .form-group input[type="text"], .form-group input[type="password"], .form-group select {
            width: 100% !important;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            box-sizing: border-box;
            background: white;
        }
        
        .um-sel:focus, .form-group input[type="text"]:focus, .form-group input[type="password"]:focus, .form-group select:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .um-btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 15px;
        }
        
        .um-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .um-container .message {
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-weight: 500;
        }
        
        .um-container .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .um-container .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .selection-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .security-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            color: #856404;
        }
        
        .danger-section {
            background: #f8d7da;
            border: 2px solid #f5c6cb;
            border-radius: 10px;
            padding: 25px;
            margin: 30px 0;
            border-left: 4px solid #dc3545;
        }
        
        .danger-section h2, .danger-section h3 {
            color: #721c24 !important;
            margin-top: 0;
        }
        
        .btn-danger {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 10px 5px;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.4);
        }
        
        .btn-warning {
            background: linear-gradient(45deg, #ffc107, #e0a800);
            color: #212529;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 10px 5px;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 10px 5px;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
        }
        
        .btn-info {
            background: linear-gradient(45deg, #17a2b8, #138496);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 10px 5px;
        }
        
        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.4);
        }
        
        .admin-auth {
            background: #e2e3e5;
            border: 1px solid #d1d3d4;
            border-radius: 8px;
            padding: 20px;
            margin: 15px 0;
            display: none;
            transition: all 0.3s ease;
        }
        
        .admin-auth.active {
            display: block;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .admin-auth label {
            color: #495057 !important;
            font-weight: bold;
        }
        
        .admin-auth input {
            margin-top: 10px;
        }
        
        .requirements-notice {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            font-weight: bold;
            animation: flash 2s ease-in-out;
        }
        
        @keyframes flash {
            0%, 100% { background: #fff3cd; }
            50% { background: #ffeaa7; }
        }
        
        /* Fix per i small text */
        .form-group small {
            color: #666 !important;
            font-size: 12px;
            margin-top: 5px;
            display: block;
        }
    </style>

<?php
// Navbar dinamica con pagina attiva
renderNavbar('user_manager'); // @phpstan-ignore-line

// Header
renderHeader('👥 Gestione Utenti', 'Sistema di gestione utenti per classi e indirizzi'); // @phpstan-ignore-line

// Container start
renderContainerStart(); // @phpstan-ignore-line
?>
    <div class="um-container">
        <div class="security-banner">
            <span class="icon">🔒</span>
            SISTEMA SICURO: Le password sono salvate SOLO in formato hash crittografato
        </div>
        
        <div class="fm-form-section">
            <h2 style="margin-top: 0; color: #667eea;">Aggiungi Nuovo Utente</h2>
            
            <form id="userForm">
                <!-- Token CSRF per protezione contro richieste cross-site forgery -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label class="um-sel-lab">Indirizzo:</label>
                        <select class="um-sel" id="sel-iis" name="indirizzo" required>
                            <option value="" disabled selected>Scegli l'indirizzo:</option>
                            <optgroup label="Liceo">
                                <option value="sc">Scientifico</option>
                                <option value="cl">Classico</option>
                                <option value="li">Linguistico</option>
                                <option value="ar">Artistico</option>
                            </optgroup>
                            <optgroup label="Indirizzo Tecnico">
                                <option value="af">Amministrazione Finanza e Marketing</option>
                            </optgroup>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="um-sel-lab">Classe:</label>
                        <select class="um-sel" id="sel-cls" name="classe" required>
                            <option value="" disabled selected>Scegli la classe:</option>
                            <optgroup label="Standard">
                                <option value="1s">Classe I</option>
                                <option value="2s">Classe II</option>
                                <option value="3s">Classe III</option>
                                <option value="4s">Classe IV</option>
                                <option value="5s">Classe V</option>
                            </optgroup>
                            <optgroup label="Liceo breve">
                                <option value="1b">Classe I (breve)</option>
                                <option value="2b">Classe II (breve)</option>
                                <option value="3b">Classe III (breve)</option>
                                <option value="4b">Classe IV (breve)</option>
                            </optgroup>
                        </select>
                    </div>
                </div>
                
                <div class="form-row" style="align-items: flex-start;">
                    <div class="form-group">
                        <label for="username">👤 Username:</label>
                        <input type="text" id="username" name="username" required 
                               placeholder="Inserisci username (min. 5 caratteri)" 
                               minlength="5" maxlength="50">
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            Richiesti: minimo 5 caratteri
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">🔒 Password:</label>
                        <input type="password" id="password" name="password" required 
                               placeholder="Min 8 car., 2 speciali, 1 numero" 
                               minlength="8" maxlength="100">
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            Richiesti: min 8 caratteri, almeno 2 speciali (,;.:-_@#[]|!"£$%&amp;/()=?^'+*°ç), almeno 1 numero
                        </small>
                    </div>
                </div>
                
                <div class="security-info">
                    <strong>🔒 Nota Sicurezza:</strong> La password verrà automaticamente crittografata e salvata solo in formato hash. 
                    Non sarà mai memorizzata in chiaro per garantire la massima sicurezza.
                </div>
                
                <div class="selection-info" id="selectionInfo" style="display: none;">
                    <h3>📋 Riepilogo Selezione</h3>
                    <p><strong>Indirizzo:</strong> <span id="selectedAddress"></span></p>
                    <p><strong>Classe:</strong> <span id="selectedClass"></span></p>
                    <p><strong>Cartella di destinazione:</strong> <span id="targetFolder"></span></p>
                </div>
                
                <button type="submit" class="um-btn" id="submitBtn">
                    ✅ Aggiungi Utente (Sicuro)
                </button>
            </form>
            
            <div id="message"></div>
        </div>
        
        <!-- SEZIONE OPERAZIONI PERICOLOSE -->
        <div class="danger-section">
            <h2>⚠️ Operazioni di Amministrazione</h2>
            <p><strong>ATTENZIONE:</strong> Le seguenti operazioni sono irreversibili e richiedono autenticazione admin.</p>
            
            <!-- Pulizia completa -->
            <div style="margin: 20px 0; padding: 15px; background: #fff; border-radius: 8px; border-left: 3px solid #ffc107;">
                <h3>🧹 Pulisci File Utenti</h3>
                <p>Rimuove TUTTI gli utenti dal file users.json della classe selezionata.</p>
                <p><strong>📋 Prima di procedere:</strong> Seleziona <u>indirizzo</u> e <u>classe</u> dal form sopra.</p>
                <div id="clearRequirements" class="requirements-notice" style="display: none;">
                    ⚠️ <strong>Seleziona prima indirizzo e classe!</strong>
                </div>
                <button type="button" class="btn-warning" id="clearUsersBtn" disabled>
                    🧹 Pulisci Tutti gli Utenti
                </button>
                <div id="clearMessage"></div>
            </div>
            
            <!-- Eliminazione utente specifico -->
            <div style="margin: 20px 0; padding: 15px; background: #fff; border-radius: 8px; border-left: 3px solid #dc3545;">
                <h3>🗑️ Elimina Utente Specifico</h3>
                <p>Rimuove un singolo utente dal file users.json della classe selezionata.</p>
                <p><strong>📋 Prima di procedere:</strong> Seleziona <u>indirizzo</u>, <u>classe</u> e inserisci <u>username</u> dal form sopra.</p>
                <div id="deleteRequirements" class="requirements-notice" style="display: none;">
                    ⚠️ <strong>Seleziona prima indirizzo, classe e inserisci username!</strong>
                </div>
                <button type="button" class="btn-danger" id="deleteUserBtn" disabled>
                    🗑️ Elimina Utente Specifico
                </button>
                <div id="deleteMessage"></div>
            </div>
            
            <!-- Creazione nuovo collaboratore -->
            <div style="margin: 20px 0; padding: 15px; background: #fff; border-radius: 8px; border-left: 3px solid #17a2b8;">
                <h3>🤝 Crea Nuovo Collaboratore</h3>
                <p>Crea un nuovo account collaboratore attivo immediatamente. Richiede autenticazione admin.</p>
                <!-- Nota UX: Usa il popup di autenticazione come le altre operazioni per coerenza -->
                <div style="background: #d1ecf1; padding: 10px; border-radius: 5px; margin: 10px 0; border-left: 3px solid #17a2b8;">
                    <strong>ℹ️ REQUISITI COLLABORATORE:</strong><br>
                    • Minimo 10 caratteri<br>
                    • Almeno 2 caratteri speciali<br>
                    • Almeno 1 numero<br>
                    • **Autenticazione amministratore richiesta**
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="collab_username">👤 Username Collaboratore:</label>
                        <input type="text" id="collab_username" placeholder="Username collaboratore (min. 5 caratteri)" 
                               minlength="5" maxlength="50">
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            Richiesti: minimo 5 caratteri
                        </small>
                    </div>
                </div>
                
                <div class="form-row" style="align-items: flex-start;">
                    <div class="form-group">
                        <label for="collab_password_new">🔐 Password Collaboratore:</label>
                        <input type="password" id="collab_password_new" placeholder="Password sicura (min. 10 caratteri)" 
                               minlength="10" maxlength="255">
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            Richiesti: min 10 caratteri, 2 speciali, 1 numero
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">🔐 Conferma Password:</label>
                        <input type="password" id="confirm_password" placeholder="Ripeti la password" 
                               minlength="10" maxlength="255">
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            Deve coincidere con la password già inserita
                        </small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="collab_notes">📝 Note (facoltativo):</label>
                        <textarea id="collab_notes" placeholder="Ruolo, area di competenza, ecc." 
                                  maxlength="500" rows="3" style="width: 100%; padding: 12px; border: 2px solid #e1e5e9; border-radius: 8px; font-size: 14px; resize: vertical;"></textarea>
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            Informazioni aggiuntive sul collaboratore
                        </small>
                    </div>
                </div>
                
                <button type="button" class="btn-info" id="addCollabBtn" style="background: linear-gradient(45deg, #17a2b8, #138496); color: white; margin-top: 15px;">
                    🤝 Crea Collaboratore
                </button>
                <div id="collaboratorMessage"></div>
            </div>
            
            <!-- Autenticazione Admin -->
            <div class="admin-auth" id="adminAuth">
                <h4>🔐 Autenticazione Amministratore</h4>
                <p>Inserisci la password admin per confermare l'operazione:</p>
                <label for="adminPassword">Password Admin:</label>
                <input type="password" id="adminPassword" placeholder="Password amministratore" 
                       style="width: 100%; padding: 10px; margin-top: 10px;">
                <br>
                <button type="button" class="um-btn" id="confirmAdminBtn" style="margin-top: 15px; width: auto; padding: 10px 20px;">
                    ✅ Conferma Operazione
                </button>
                <button type="button" class="btn-warning" id="cancelAdminBtn" style="width: auto; padding: 10px 20px;">
                    ❌ Annulla
                </button>
            </div>
        </div>
    </div>

    <script>
        // Mappatura degli indirizzi per i nomi completi
        const addressNames = {
            'sc': 'Scientifico',
            'cl': 'Classico',
            'li': 'Linguistico',
            'ar': 'Artistico',
            'af': 'Amministrazione Finanza e Marketing'
        };
        
        const classNames = {
            '1s': 'Prima Standard',
            '2s': 'Seconda Standard',
            '3s': 'Terza Standard',
            '4s': 'Quarta Standard',
            '5s': 'Quinta Standard',
            '1b': 'Prima Breve',
            '2b': 'Seconda Breve',
            '3b': 'Terza Breve',
            '4b': 'Quarta Breve'
        };
        
        // Elementi del DOM
        const addressSelect = document.getElementById('sel-iis');
        const classSelect = document.getElementById('sel-cls');
        const selectionInfo = document.getElementById('selectionInfo');
        const selectedAddress = document.getElementById('selectedAddress');
        const selectedClass = document.getElementById('selectedClass');
        const targetFolder = document.getElementById('targetFolder');
        const form = document.getElementById('userForm');
        const messageDiv = document.getElementById('message');
        const submitBtn = document.getElementById('submitBtn');
        
        // Nuovi elementi per le operazioni admin
        const clearUsersBtn = document.getElementById('clearUsersBtn');
        const deleteUserBtn = document.getElementById('deleteUserBtn');
        const addCollabBtn = document.getElementById('addCollabBtn');
        const adminAuth = document.getElementById('adminAuth');
        const adminPasswordInput = document.getElementById('adminPassword');
        const confirmAdminBtn = document.getElementById('confirmAdminBtn');
        const cancelAdminBtn = document.getElementById('cancelAdminBtn');
        const usernameInput = document.getElementById('username'); // Riutilizziamo il campo esistente
        
        // Nuovi elementi per creazione collaboratore
        const collabUsernameInput = document.getElementById('collab_username');
        const collabPasswordNewInput = document.getElementById('collab_password_new');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const collabNotesInput = document.getElementById('collab_notes');
        
        // Variabile per tracciare l'operazione corrente
        let currentOperation = null;
        
        // Salva e ripristina valori dei form
        function saveFormValues() {
            localStorage.setItem('userManager_address', addressSelect.value);
            localStorage.setItem('userManager_class', classSelect.value);
            localStorage.setItem('userManager_username', usernameInput.value);
        }
        
        function restoreFormValues() {
            const savedAddress = localStorage.getItem('userManager_address');
            const savedClass = localStorage.getItem('userManager_class');
            const savedUsername = localStorage.getItem('userManager_username');
            
            if (savedAddress) addressSelect.value = savedAddress;
            if (savedClass) classSelect.value = savedClass;
            if (savedUsername) usernameInput.value = savedUsername;
            
            updateSelectionInfo();
        }
        
        // Ripristina i valori al caricamento della pagina
        window.addEventListener('load', restoreFormValues);
        
        // Aggiorna le informazioni di selezione
        function updateSelectionInfo() {
            const addressValue = addressSelect.value;
            const classValue = classSelect.value;
            
            // Salva i valori ogni volta che cambiano
            saveFormValues();
            
            if (addressValue && classValue) {
                selectedAddress.textContent = addressNames[addressValue] || addressValue;
                selectedClass.textContent = classNames[classValue] || classValue;
                targetFolder.textContent = `eser_${addressValue}${classValue}`;
                selectionInfo.style.display = 'block';
                
                // Abilita i pulsanti di amministrazione
                clearUsersBtn.disabled = false;
                updateDeleteButtonState();
                
                // Nasconde i messaggi di requisiti
                document.getElementById('clearRequirements').style.display = 'none';
                document.getElementById('deleteRequirements').style.display = 'none';
            } else {
                selectionInfo.style.display = 'none';
                
                // Disabilita i pulsanti di amministrazione
                clearUsersBtn.disabled = true;
                deleteUserBtn.disabled = true;
            }
        }
        
        // Aggiorna lo stato del pulsante elimina utente
        function updateDeleteButtonState() {
            const hasSelection = addressSelect.value && classSelect.value;
            const hasUsername = usernameInput.value.trim().length >= 5;
            deleteUserBtn.disabled = !(hasSelection && hasUsername);
            
            // Salva l'username
            saveFormValues();
            
            // Gestisce il messaggio di requisiti per l'eliminazione
            if (!hasSelection || !hasUsername) {
                if (deleteUserBtn.disabled && (addressSelect.value || classSelect.value || usernameInput.value)) {
                    document.getElementById('deleteRequirements').style.display = 'block';
                }
            } else {
                document.getElementById('deleteRequirements').style.display = 'none';
            }
        }
        
        // Event listeners per i selettori
        addressSelect.addEventListener('change', updateSelectionInfo);
        classSelect.addEventListener('change', updateSelectionInfo);
        
        // Event listener per il campo username da eliminare
        usernameInput.addEventListener('input', updateDeleteButtonState);
        
        // Validazione password in tempo reale
        const passwordInput = document.getElementById('password');
        
        function validatePassword(password) {
            const errors = [];
            
            if (password.length < 8) {
                errors.push('Minimo 8 caratteri');
            }
            
            // Conta caratteri speciali
            const specialChars = ',;.:-_@#[]\\\\|!\\"£$%&/()=?^\\+*°ç';
            let specialCount = 0;
            for (let char of password) {
                if (specialChars.includes(char)) {
                    specialCount++;
                }
            }
            
            if (specialCount < 2) {
                errors.push('Almeno 2 caratteri speciali');
            }
            
            // Verifica presenza di almeno un numero
            if (!/[0-9]/.test(password)) {
                errors.push('Almeno 1 numero');
            }
            
            return errors;
        }
        
        // Validazione password amministratore (più rigorosa)
        function validateAdminPassword(password) {
            const errors = [];
            
            if (password.length < 12) {
                errors.push('Minimo 12 caratteri');
            }
            
            // Conta caratteri speciali
            const specialChars = ',;.:-_@#[]\\\\|!\\"£$%&/()=?^\\+*°ç';
            let specialCount = 0;
            for (let char of password) {
                if (specialChars.includes(char)) {
                    specialCount++;
                }
            }
            
            if (specialCount < 3) {
                errors.push('Almeno 3 caratteri speciali');
            }
            
            // Verifica presenza di almeno 2 numeri
            const numberMatches = password.match(/[0-9]/g);
            if (!numberMatches || numberMatches.length < 2) {
                errors.push('Almeno 2 numeri');
            }
            
            // Verifica maiuscole e minuscole
            if (!/[A-Z]/.test(password)) {
                errors.push('Almeno 1 maiuscola');
            }
            
            if (!/[a-z]/.test(password)) {
                errors.push('Almeno 1 minuscola');
            }
            
            return errors;
        }
        
        // Validazione password collaboratore (meno rigorosa dell'admin)
        function validateCollaboratorPassword(password) {
            const errors = [];
            
            if (password.length < 10) {
                errors.push('Minimo 10 caratteri');
            }
            
            // Conta caratteri speciali
            const specialChars = ',;.:-_@#[]\\\\|!\\"£$%&/()=?^\\+*°ç';
            let specialCount = 0;
            for (let char of password) {
                if (specialChars.includes(char)) {
                    specialCount++;
                }
            }
            
            if (specialCount < 2) {
                errors.push('Almeno 2 caratteri speciali');
            }
            
            // Verifica presenza di almeno 1 numero
            if (!/[0-9]/.test(password)) {
                errors.push('Almeno 1 numero');
            }
            
            return errors;
        }
        
        function updatePasswordValidation() {
            const password = passwordInput.value;
            const errors = validatePassword(password);
            
            // Rimuovi eventuali messaggi precedenti
            const existingFeedback = passwordInput.parentNode.querySelector('.password-feedback');
            if (existingFeedback) {
                existingFeedback.remove();
            }
            
            if (password && errors.length > 0) {
                const feedback = document.createElement('div');
                feedback.className = 'password-feedback';
                feedback.style.cssText = 'color: #dc3545; font-size: 12px; margin-top: 5px; padding: 5px; background: #f8d7da; border-radius: 4px;';
                feedback.innerHTML = '<strong>⚠️ Password non valida:</strong><br>• ' + errors.join('<br>• ');
                passwordInput.parentNode.appendChild(feedback);
            } else if (password && errors.length === 0) {
                const feedback = document.createElement('div');
                feedback.className = 'password-feedback';
                feedback.style.cssText = 'color: #28a745; font-size: 12px; margin-top: 5px; padding: 5px; background: #d4edda; border-radius: 4px;';
                feedback.innerHTML = '<strong>✅ Password valida!</strong>';
                passwordInput.parentNode.appendChild(feedback);
            }
        }
        
        passwordInput.addEventListener('input', updatePasswordValidation);
        passwordInput.addEventListener('blur', updatePasswordValidation);
        
        // Validazione password collaboratore in tempo reale
        function updateCollabPasswordValidation() {
            const password = collabPasswordNewInput.value;
            const confirmPassword = confirmPasswordInput.value;
            const errors = validateCollaboratorPassword(password);
            
            // Rimuovi eventuali messaggi precedenti
            const existingFeedback = collabPasswordNewInput.parentNode.querySelector('.collab-password-feedback');
            if (existingFeedback) {
                existingFeedback.remove();
            }
            
            if (password && errors.length > 0) {
                const feedback = document.createElement('div');
                feedback.className = 'collab-password-feedback';
                feedback.style.cssText = 'color: #dc3545; font-size: 12px; margin-top: 5px; padding: 5px; background: #f8d7da; border-radius: 4px;';
                feedback.innerHTML = '<strong>⚠️ Password collaboratore non valida:</strong><br>• ' + errors.join('<br>• ');
                collabPasswordNewInput.parentNode.appendChild(feedback);
            } else if (password && errors.length === 0) {
                const feedback = document.createElement('div');
                feedback.className = 'collab-password-feedback';
                feedback.style.cssText = 'color: #28a745; font-size: 12px; margin-top: 5px; padding: 5px; background: #d4edda; border-radius: 4px;';
                feedback.innerHTML = '<strong>✅ Password collaboratore valida!</strong>';
                collabPasswordNewInput.parentNode.appendChild(feedback);
            }
            
            // Verifica corrispondenza password
            const confirmFeedback = confirmPasswordInput.parentNode.querySelector('.collab-confirm-password-feedback');
            if (confirmFeedback) {
                confirmFeedback.remove();
            }
            
            if (confirmPassword && password !== confirmPassword) {
                const feedback = document.createElement('div');
                feedback.className = 'collab-confirm-password-feedback';
                feedback.style.cssText = 'color: #dc3545; font-size: 12px; margin-top: 5px; padding: 5px; background: #f8d7da; border-radius: 4px;';
                feedback.innerHTML = '<strong>❌ Le password non coincidono</strong>';
                confirmPasswordInput.parentNode.appendChild(feedback);
            } else if (confirmPassword && password === confirmPassword && errors.length === 0) {
                const feedback = document.createElement('div');
                feedback.className = 'collab-confirm-password-feedback';
                feedback.style.cssText = 'color: #28a745; font-size: 12px; margin-top: 5px; padding: 5px; background: #d4edda; border-radius: 4px;';
                feedback.innerHTML = '<strong>✅ Password confermate!</strong>';
                confirmPasswordInput.parentNode.appendChild(feedback);
            }
        }
        
        if (collabPasswordNewInput) {
            collabPasswordNewInput.addEventListener('input', updateCollabPasswordValidation);
            collabPasswordNewInput.addEventListener('blur', updateCollabPasswordValidation);
        }
        
        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', updateCollabPasswordValidation);
            confirmPasswordInput.addEventListener('blur', updateCollabPasswordValidation);
        }
        
        // Gestione operazioni admin
        clearUsersBtn.addEventListener('click', function() {
            if (!addressSelect.value || !classSelect.value) {
                document.getElementById('clearRequirements').style.display = 'block';
                setTimeout(() => {
                    document.getElementById('clearRequirements').style.display = 'none';
                }, 4000);
                return;
            }
            currentOperation = 'clear_users';
            showAdminAuth('Pulire tutti gli utenti dalla classe selezionata?', clearUsersBtn);
        });
        
        deleteUserBtn.addEventListener('click', function() {
            const username = usernameInput.value.trim();
            if (!addressSelect.value || !classSelect.value || !username) {
                document.getElementById('deleteRequirements').style.display = 'block';
                setTimeout(() => {
                    document.getElementById('deleteRequirements').style.display = 'none';
                }, 4000);
                return;
            }
            currentOperation = 'delete_user';
            showAdminAuth(`Eliminare l'utente "${username}" dalla classe selezionata?`, deleteUserBtn);
        });
        
        // Gestione creazione nuovo collaboratore
        addCollabBtn.addEventListener('click', function() {
            const collabUsername = collabUsernameInput.value.trim();
            const collabPassword = collabPasswordNewInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            // Validazione rigorosa dei campi (SENZA password admin)
            if (!collabUsername || !collabPassword || !confirmPassword) {
                showMessage('❌ Username, Password e Conferma Password sono obbligatori', 'error', 'collaboratorMessage');
                return;
            }
            
            if (collabUsername.length < 5) {
                showMessage('❌ L\'username collaboratore deve essere di almeno 5 caratteri', 'error', 'collaboratorMessage');
                return;
            }
            
            if (collabPassword !== confirmPassword) {
                showMessage('❌ Le password non coincidono', 'error', 'collaboratorMessage');
                return;
            }
            
            const collabPasswordErrors = validateCollaboratorPassword(collabPassword);
            if (collabPasswordErrors.length > 0) {
                showMessage('❌ Password collaboratore non valida: ' + collabPasswordErrors.join(', '), 'error', 'collaboratorMessage');
                return;
            }
            
            // Usa il popup di autenticazione come le altre operazioni
            currentOperation = 'add_collaborator';
            showAdminAuth(`Creare il collaboratore "${collabUsername}"?`, addCollabBtn);
        });
        
        confirmAdminBtn.addEventListener('click', executeAdminOperation);
        cancelAdminBtn.addEventListener('click', cancelAdminOperation);
        
        // Supporto per invio password admin con Enter
        adminPasswordInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                executeAdminOperation();
            }
        });
        
        // Mostra pannello autenticazione admin
        function showAdminAuth(message, targetElement = null) {
            adminAuth.className = 'admin-auth active';
            adminAuth.querySelector('p').textContent = message;
            adminPasswordInput.value = '';
            
            // Se viene specificato un elemento target, posiziona il dialog dopo di esso
            if (targetElement) {
                // Rimuove il dialog dalla sua posizione attuale
                adminAuth.remove();
                // Lo inserisce dopo l'elemento target
                targetElement.parentNode.insertBefore(adminAuth, targetElement.nextSibling);
            }
            
            adminPasswordInput.focus();
        }
        
        // Nasconde pannello autenticazione admin
        function cancelAdminOperation() {
            adminAuth.className = 'admin-auth';
            currentOperation = null;
            adminPasswordInput.value = '';
            
            // Riporta il dialog nella sua posizione originale (alla fine del container)
            const container = document.querySelector('.container');
            if (container && !container.contains(adminAuth)) {
                container.appendChild(adminAuth);
            }
        }
        
        // Esegue l'operazione admin dopo autenticazione
        async function executeAdminOperation() {
            const adminPassword = adminPasswordInput.value.trim();
            if (!adminPassword) {
                showMessage('⚠️ Inserisci la password amministratore', 'error');
                return;
            }
            
            if (!currentOperation) {
                showMessage('❌ Nessuna operazione selezionata', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', currentOperation);
            formData.append('admin_password', adminPassword);
            formData.append('indirizzo', addressSelect.value);
            formData.append('classe', classSelect.value);
            // Token CSRF letto dal campo hidden del form principale
            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }
            
            if (currentOperation === 'delete_user') {
                const username = usernameInput.value.trim();
                formData.append('target_username', username);
            }

            if (currentOperation === 'add_collaborator') {
                const collabUsername = collabUsernameInput.value.trim();
                const collabPassword = collabPasswordNewInput.value;
                const confirmPassword = confirmPasswordInput.value;
                const collabNotes = collabNotesInput.value.trim();

                formData.append('collab_username', collabUsername);
                formData.append('collab_password_new', collabPassword);
                formData.append('confirm_password', confirmPassword);
                formData.append('collab_notes', collabNotes);
            }
            
            confirmAdminBtn.disabled = true;
            confirmAdminBtn.textContent = '🔐 Autenticazione...';
            
            try {
                const response = await fetch('user_manager.php', {
                    method: 'POST',
                    body: formData
                });
                
                const responseText = await response.text();

                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    
                    // Determina il contenitore di messaggio in base all'operazione
                    let messageContainer = 'message'; // default
                    if (currentOperation === 'clear_users') {
                        messageContainer = 'clearMessage';
                    } else if (currentOperation === 'delete_user') {
                        messageContainer = 'deleteMessage';
                    } else if (currentOperation === 'add_collaborator') {
                        messageContainer = 'collaboratorMessage';
                    }
                    
                    showMessage('Errore del server: risposta non valida', 'error', messageContainer);
                    return;
                }

                if (result.success) {
                    // Determina il contenitore di messaggio in base all'operazione
                    let messageContainer = 'message'; // default
                    if (currentOperation === 'clear_users') {
                        messageContainer = 'clearMessage';
                    } else if (currentOperation === 'delete_user') {
                        messageContainer = 'deleteMessage';
                    } else if (currentOperation === 'add_collaborator') {
                        messageContainer = 'collaboratorMessage';
                    }
                    
                    showMessage(result.message, 'success', messageContainer);
                    cancelAdminOperation();
                    
                    // Reset del form se l'operazione è andata a buon fine
                    if (currentOperation === 'delete_user') {
                        usernameInput.value = '';
                        updateDeleteButtonState();
                    } else if (currentOperation === 'add_collaborator') {
                        // Reset campi collaboratore
                        collabUsernameInput.value = '';
                        collabPasswordNewInput.value = '';
                        confirmPasswordInput.value = '';
                        collabNotesInput.value = '';
                        
                        // Rimuovi feedback di validazione
                        const feedbacks = document.querySelectorAll('.collab-password-feedback, .collab-confirm-password-feedback');
                        feedbacks.forEach(feedback => feedback.remove());
                    }
                } else {
                    // Determina il contenitore di messaggio in base all'operazione
                    let messageContainer = 'message'; // default
                    if (currentOperation === 'clear_users') {
                        messageContainer = 'clearMessage';
                    } else if (currentOperation === 'delete_user') {
                        messageContainer = 'deleteMessage';
                    } else if (currentOperation === 'add_collaborator') {
                        messageContainer = 'collaboratorMessage';
                    }
                    
                    showMessage(result.message, 'error', messageContainer);
                }
            } catch (error) {
                
                // Determina il contenitore di messaggio in base all'operazione
                let messageContainer = 'message'; // default
                if (currentOperation === 'clear_users') {
                    messageContainer = 'clearMessage';
                } else if (currentOperation === 'delete_user') {
                    messageContainer = 'deleteMessage';
                } else if (currentOperation === 'add_collaborator') {
                    messageContainer = 'collaboratorMessage';
                }
                
                showMessage('Errore di connessione: ' + error.message, 'error', messageContainer);
            } finally {
                confirmAdminBtn.disabled = false;
                confirmAdminBtn.textContent = '✅ Conferma Operazione';
            }
        }
        
        // Mostra messaggio — testo server sempre via textContent, mai innerHTML
        function showMessage(message, type, targetContainer = null) {
            // Determina il contenitore di destinazione
            let containerDiv;
            if (targetContainer) {
                containerDiv = document.getElementById(targetContainer);
                if (!containerDiv) {
                    containerDiv = messageDiv;
                }
            } else {
                containerDiv = messageDiv;
            }

            // Crea elemento in modo sicuro contro XSS
            const messageContainer = document.createElement('div');
            messageContainer.className = `message ${type}`;
            messageContainer.style.cssText = 'margin-top: 15px;';

            // Il testo del server è sempre textContent — nessuna concatenazione HTML
            messageContainer.textContent = message;

            containerDiv.innerHTML = ''; // Pulisce messaggi precedenti
            containerDiv.appendChild(messageContainer);

            setTimeout(() => {
                containerDiv.innerHTML = '';
            }, 8000);
        }
        
        // Gestione invio form
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Validazione finale password
            const password = passwordInput.value;
            const passwordErrors = validatePassword(password);
            
            if (passwordErrors.length > 0) {
                showMessage('❌ Password non valida: ' + passwordErrors.join(', '), 'error');
                return;
            }
            
            const formData = new FormData(form);
            formData.append('action', 'add_user');
            
            submitBtn.disabled = true;
            submitBtn.textContent = '🔐 Crittografia in corso...';
            
            try {
                const response = await fetch('user_manager.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Mostra il messaggio principale in modo sicuro via textContent
                    showMessage(result.message, 'success');
                    form.reset();
                    updateSelectionInfo();
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Errore di connessione: ' + error.message, 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = '✅ Aggiungi Utente (Sicuro)';
            }
        });
    </script>

<?php renderContainerEnd(); // @phpstan-ignore-line ?>

<?php renderHtmlFooter(); // @phpstan-ignore-line ?>