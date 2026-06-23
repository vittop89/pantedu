<?php
/**
 * Logout Widget - Integrazione Dinamica
 * Sistema per l'inserimento dinamico del pulsante logout in qualsiasi pagina
 * 
 * Utilizzo:
 * 1. Includi questo file: include_once('/log/logout_widget.php');
 * 2. Chiama LogoutWidget::render($targetSelector, $options) dove necessario
 * 3. Oppure usa LogoutWidget::getScriptTag() per l'auto-inizializzazione
 */

class LogoutWidget {
    
    private static $isScriptIncluded = false;
    private static $instanceCount = 0;
    
    /**
     * Configurazione predefinita del widget
     */
    private static $defaultConfig = [
        'checkInterval' => 30000,
        'animationDuration' => 500,
        'apiEndpoint' => '/log/auth/get_user_info.php',
        'logoutEndpoint' => '/log/auth/logout.php',
        'autoInit' => true,
        'showSection' => true,
        'showRole' => true
    ];
    
    /**
     * Renderizza il widget logout
     * 
     * @param string $targetSelector - Selettore CSS dell'elemento target
     * @param array $options - Opzioni di configurazione
     * @return string HTML del widget
     */
    public static function render($targetSelector = null, $options = []) {
        $config = array_merge(self::$defaultConfig, $options);
        $instanceId = ++self::$instanceCount;
        
        $output = '';
        
        // Includi il JavaScript principale se non ancora fatto
        if (!self::$isScriptIncluded) {
            $output .= self::getScriptInclude();
            self::$isScriptIncluded = true;
        }
        
        // Script di inizializzazione specifica
        $output .= self::getInitScript($targetSelector, $config, $instanceId);
        
        return $output;
    }
    
    /**
     * Restituisce il tag script per includere il modulo JavaScript
     */
    public static function getScriptInclude() {
        return '<script src="/log/logout/logout_button.js"></script>' . "\n";
    }
    
    /**
     * Restituisce lo script di inizializzazione
     */
    private static function getInitScript($targetSelector, $config, $instanceId) {
        $configJson = json_encode($config, JSON_UNESCAPED_SLASHES);
        
        $script = "<script>\n";
        $script .= "// Logout Widget Instance {$instanceId}\n";
        $script .= "(function() {\n";
        $script .= "    const config = {$configJson};\n";
        
        if ($targetSelector) {
            // Inizializzazione diretta con target specificato
            $script .= "    document.addEventListener('DOMContentLoaded', function() {\n";
            $script .= "        if (typeof LogoutButton !== 'undefined') {\n";
            $script .= "            const success = LogoutButton.init('{$targetSelector}', config);\n";
            $script .= "            if (success) {\n";
            $script .= "                console.log('✅ LogoutWidget: Istanza {$instanceId} inizializzata su \"{$targetSelector}\"');\n";
            $script .= "            } else {\n";
            $script .= "                console.error('🚫 LogoutWidget: Errore inizializzazione istanza {$instanceId}');\n";
            $script .= "            }\n";
            $script .= "        } else {\n";
            $script .= "            console.error('🚫 LogoutWidget: LogoutButton non disponibile');\n";
            $script .= "        }\n";
            $script .= "    });\n";
        } else {
            // Auto-inizializzazione
            $script .= "    document.addEventListener('DOMContentLoaded', function() {\n";
            $script .= "        const autoTargets = document.querySelectorAll('[data-logout-widget]');\n";
            $script .= "        autoTargets.forEach((target, index) => {\n";
            $script .= "            if (typeof LogoutButton !== 'undefined') {\n";
            $script .= "                const success = LogoutButton.init(target, config);\n";
            $script .= "                if (success) {\n";
            $script .= "                    console.log('✅ LogoutWidget: Auto-init ' + (index + 1) + ' completata');\n";
            $script .= "                }\n";
            $script .= "            }\n";
            $script .= "        });\n";
            $script .= "    });\n";
        }
        
        $script .= "})();\n";
        $script .= "</script>\n";
        
        return $script;
    }
    
    /**
     * Renderizza un placeholder HTML per l'auto-inizializzazione
     */
    public static function getPlaceholder($cssClass = 'logout-placeholder', $attributes = []) {
        $attrString = '';
        foreach ($attributes as $key => $value) {
            $attrString .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
        }
        
        return "<div class=\"{$cssClass}\" data-logout-widget{$attrString}></div>\n";
    }
    
    /**
     * Metodo helper per integrazione rapida
     * 
     * @param string $targetSelector - Dove inserire il widget
     * @param array $options - Opzioni del widget
     */
    public static function quickIntegration($targetSelector = '.fm-upbar .fm-scrollbar-up-bar > div:first-child > div:first-child', $options = []) {
        echo self::render($targetSelector, $options);
    }
    
    /**
     * Verifica se l'utente è autenticato (per condizioni di rendering)
     */
    public static function isUserAuthenticated() {
        session_start();
        return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }
    
    /**
     * Ottiene informazioni dell'utente corrente
     */
    public static function getCurrentUserInfo() {
        if (!self::isUserAuthenticated()) {
            return null;
        }
        
        session_start();
        return [
            'username' => $_SESSION['username'] ?? 'Unknown',
            'role' => $_SESSION['role'] ?? 'student',
            'login_time' => $_SESSION['login_time'] ?? null,
            'authenticated_section' => $_SESSION['authenticated_section'] ?? null
        ];
    }
    
    /**
     * Renderizza condizionalmente (solo se autenticato)
     */
    public static function renderIfAuthenticated($targetSelector, $options = []) {
        if (self::isUserAuthenticated()) {
            return self::render($targetSelector, $options);
        }
        return '<!-- LogoutWidget: Utente non autenticato -->' . "\n";
    }
    
    /**
     * Debug del widget
     */
    public static function debug() {
        $info = [
            'script_included' => self::$isScriptIncluded,
            'instance_count' => self::$instanceCount,
            'user_authenticated' => self::isUserAuthenticated(),
            'user_info' => self::getCurrentUserInfo(),
            'session_id' => session_id(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo '<div style="background: #333; color: #fff; padding: 10px; margin: 10px; border-radius: 5px; font-family: monospace;">';
        echo '<h4 style="color: #4CAF50; margin: 0 0 10px 0;">🔍 LogoutWidget Debug</h4>';
        echo '<pre>' . json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . '</pre>';
        echo '</div>';
    }
    
    /**
     * Reset per testing
     */
    public static function reset() {
        self::$isScriptIncluded = false;
        self::$instanceCount = 0;
    }
}

// Funzioni helper globali per facilità d'uso
function render_logout_widget($targetSelector = null, $options = []) {
    return LogoutWidget::render($targetSelector, $options);
}

function logout_widget_quick($targetSelector = null) {
    echo LogoutWidget::render($targetSelector);
}

function logout_widget_if_auth($targetSelector, $options = []) {
    echo LogoutWidget::renderIfAuthenticated($targetSelector, $options);
}

?>
