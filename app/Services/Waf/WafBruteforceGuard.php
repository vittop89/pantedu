<?php

declare(strict_types=1);

namespace App\Services\Waf;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * WafBruteforceGuard — ponte login-fallito → auto-ban (audit 2026-06-01).
 *
 * Prima esisteva solo un rate-limit per-IP a finestra scorrevole (429, mai un
 * ban duraturo) + un rate-limiter di sessione azzerabile droppando il cookie.
 * Nessun ban persistente automatico veniva mai scritto in
 * waf_blocked_ips / waf_blocked_credentials (popolate solo a mano dall'admin).
 *
 * Questo guard chiude il ponte:
 *   - LOCKOUT USERNAME (basso rischio): N fallimenti sullo stesso username in
 *     una finestra → blocco TEMPORANEO di quell'username (waf_blocked_credentials).
 *   - BAN IP su CREDENTIAL STUFFING (mirato, NON penalizza i NAT scolastici):
 *     un IP che fallisce su molti username DISTINTI in poco tempo = bot di
 *     stuffing → ban IP temporaneo (waf_blocked_ips, enforced dal WafMiddleware).
 *     Un singolo studente che sbaglia password molte volte sullo STESSO account
 *     NON fa scattare il ban IP (conta gli username distinti, non i tentativi).
 *
 * L'enforcement è già esistente: WafMiddleware blocca gli IP in waf_blocked_ips
 * e Auth::attempt consulta waf_blocked_credentials (expiry-aware).
 */
final class WafBruteforceGuard
{
    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly ?WafConfigRepository $config = null,
        private readonly ?WafSecurityRepository $security = null,
    ) {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Registra un tentativo di login fallito e applica gli auto-ban se le
     * soglie sono superate. Best-effort: non solleva mai (non deve rompere
     * il flusso di login).
     */
    public function registerFailure(string $ip, string $username): void
    {
        $ip = trim($ip);
        if ($ip === '' || $ip === '0.0.0.0') {
            return;
        }
        $username = substr(trim($username), 0, 190);
        try {
            $cfg = $this->config ?? new WafConfigRepository();
            $sec = $this->security ?? new WafSecurityRepository();
            $pdo = $this->db();

            $pdo->prepare('INSERT INTO waf_login_failures (ip, username) VALUES (?, ?)')
                ->execute([$ip, $username]);

            // Prune dei record vecchi (oltre 1h) — bounded, indicizzato.
            $pdo->prepare('DELETE FROM waf_login_failures WHERE created_at < (NOW() - INTERVAL 1 HOUR)')
                ->execute();

            // --- Lockout per-username ---
            if ($username !== '') {
                $uWin   = max(60, $cfg->getInt('bf_user_window_sec', 900));
                $uThr   = max(3, $cfg->getInt('bf_user_threshold', 10));
                $uLock  = max(60, $cfg->getInt('bf_user_lock_sec', 900));
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM waf_login_failures
                     WHERE username = ? AND created_at >= (NOW() - INTERVAL ? SECOND)'
                );
                $stmt->execute([$username, $uWin]);
                if ((int)$stmt->fetchColumn() >= $uThr) {
                    $sec->blockCredential(
                        $username,
                        'Auto: troppi tentativi falliti',
                        'system:bruteforce',
                        new \DateTimeImmutable('+' . $uLock . ' seconds'),
                        'auth_bruteforce',
                        false, // NON scrivere nel JSON legacy (privo di expiry)
                    );
                }
            }

            // --- Ban IP su credential stuffing (username distinti) ---
            $iWin = max(60, $cfg->getInt('bf_ip_window_sec', 600));
            $iThr = max(5, $cfg->getInt('bf_ip_distinct_threshold', 15));
            $iBan = max(60, $cfg->getInt('bf_ip_ban_sec', 1800));
            $stmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT username) FROM waf_login_failures
                 WHERE ip = ? AND created_at >= (NOW() - INTERVAL ? SECOND)'
            );
            $stmt->execute([$ip, $iWin]);
            if ((int)$stmt->fetchColumn() >= $iThr) {
                $sec->blockIp(
                    $ip,
                    null, // section NULL = blacklist globale (enforced dal WafMiddleware)
                    'Auto: credential stuffing (molti username distinti)',
                    null,
                    'system:bruteforce',
                    new \DateTimeImmutable('+' . $iBan . ' seconds'),
                    'auth_bruteforce',
                );
            }
        } catch (Throwable) {
            // best-effort: in CLI o con DB non disponibile non bloccare il login.
        }
    }

    /**
     * Reset dei fallimenti per un username dopo login riuscito.
     */
    public function clearOnSuccess(string $username): void
    {
        $username = substr(trim($username), 0, 190);
        if ($username === '') {
            return;
        }
        try {
            $this->db()->prepare('DELETE FROM waf_login_failures WHERE username = ?')
                ->execute([$username]);
        } catch (Throwable) {
        }
    }
}
