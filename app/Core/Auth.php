<?php

namespace App\Core;

use App\Domain\User;
use App\Core\Database;
use App\Repositories\UserRepository;
use App\Repositories\UserRepositoryInterface;
use App\Services\BlockList;
use App\Services\RateLimiter;

final class Auth
{
    public const REASON_INVALID     = 'invalid_credentials';
    public const REASON_INACTIVE    = 'account_inactive';
    public const REASON_BLOCKED     = 'credentials_blocked';
    public const REASON_IP_BLOCKED  = 'ip_blocked_for_section';
    public const REASON_UNAUTHORIZED = 'unauthorized_section';
    public const REASON_RATE_LIMITED = 'rate_limited';

    public static function check(): bool
    {
        return (bool)Session::get('autenticato', false);
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        $username = Session::get('username', 'unknown');
        // G20.0 — aggiungi `id` (lookup DB cached per session)
        $id = Session::get('user_id', null);
        if ($id === null && $username !== 'unknown') {
            try {
                $stmt = \App\Core\Database::connection()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                $r = $stmt->fetchColumn();
                if ($r !== false) {
                    $id = (int)$r;
                    Session::put('user_id', $id);
                }
            } catch (\Throwable $_) {
            }
        }
        return [
            'id'       => $id !== null ? (int)$id : null,
            'username' => $username,
            'role'     => Session::get('user_role', 'guest'),
            'section'  => Session::get('authenticated_section'),
        ];
    }

    public static function role(): string
    {
        return (string)Session::get('user_role', 'guest');
    }

    public static function hasRole(string ...$roles): bool
    {
        return \in_array(self::role(), $roles, true);
    }

    /**
     * Phase 19 — re-fetch role + is_super_admin dal DB e aggiorna $_SESSION.
     * Da chiamare dopo privilege change (approve registration, setRole)
     * per evitare stale role in memoria. Se il current user non esiste in
     * DB ritorna false e destroy della sessione è raccomandato dal caller.
     */
    public static function refreshCurrentUserClaims(): bool
    {
        $username = (string)Session::get('username', '');
        if ($username === '') {
            return false;
        }
        if (!Database::isAvailable()) {
            return false;
        }
        $stmt = Database::connection()->prepare(
            'SELECT role, is_super_admin, active FROM users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        Session::put('user_role', (string)$row['role']);
        Session::put('is_super_admin', (bool)$row['is_super_admin']);
        Session::put('active', (bool)$row['active']);
        return true;
    }

    public static function hasAccess(string $zone): bool
    {
        $allowed = Config::get("roles.access_zones.$zone", []);
        if (\in_array(self::role(), $allowed, true)) {
            return true;
        }
        // Phase 14 — super-admin tecnico ha accesso alla zona admin
        // (manutenzione/metriche), indipendentemente dal role operativo.
        if ($zone === 'admin' && self::isSuperAdmin()) {
            return true;
        }
        return false;
    }

    /**
     * Phase 14 — flag tecnico ortogonale al role. Un super-admin è un
     * operatore tecnico con accesso tracciato a metriche/logs/materiali
     * altrui. NON ha accesso a dati personali studenti (vedi AclPolicy).
     *
     * La sessione cache il valore per la durata della sessione; un refresh
     * forzato legge da DB (via \App\Services\AclPolicy::isSuperAdmin()).
     */
    public static function isSuperAdmin(): bool
    {
        $cached = Session::get('is_super_admin');
        if ($cached !== null) {
            return (bool)$cached;
        }
        if (!self::check()) {
            return false;
        }
        if (!Database::isAvailable()) {
            return false;
        }
        $stmt = Database::connection()->prepare(
            'SELECT is_super_admin FROM users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([(string)Session::get('username', '')]);
        $flag = (bool)$stmt->fetchColumn();
        Session::put('is_super_admin', $flag);
        return $flag;
    }

    /**
     * Phase 25.Q — istituto attivo per l'utente loggato.
     *
     * Logica:
     *   - student → users.institute_id (1:1 fisso, immutabile in sessione)
     *   - teacher → cookie/session `current_institute_id` selezionato dal
     *               selettore UI, fallback al primo `teacher_institutes`
     *   - admin   → users.admin_institute_id (scope locale), oppure
     *               selettore se super-admin globale
     *   - super-admin senza scope → null (tutti gli istituti)
     *
     * @return int|null id istituto, null se super-admin globale o non auth
     */
    public static function currentInstitute(): ?int
    {
        if (!self::check()) {
            return null;
        }
        // Cache di sessione (set da TenantMiddleware al login)
        $cached = Session::get('current_institute_id');
        if ($cached !== null) {
            return (int)$cached;
        }
        if (!Database::isAvailable()) {
            return null;
        }
        $username = (string)Session::get('username', '');
        if ($username === '') {
            return null;
        }
        try {
            $stmt = Database::connection()->prepare(
                'SELECT institute_id, admin_institute_id, role FROM users WHERE username = ? LIMIT 1'
            );
            $stmt->execute([$username]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $role = (string)$row['role'];
            // Student / admin con scope locale → fisso
            if ($role === 'student' && $row['institute_id']) {
                $iid = (int)$row['institute_id'];
                Session::put('current_institute_id', $iid);
                return $iid;
            }
            if ($role === 'admin' && $row['admin_institute_id']) {
                $iid = (int)$row['admin_institute_id'];
                Session::put('current_institute_id', $iid);
                return $iid;
            }
            // Teacher → primo istituto del pivot
            if ($role === 'teacher') {
                $stmt = Database::connection()->prepare(
                    'SELECT institute_id FROM teacher_institutes WHERE user_id = ? ORDER BY institute_id LIMIT 1'
                );
                $stmt->execute([(int)Session::get('user_id', 0) ?: (int)$row['id'] ?? 0]);
                $iid = $stmt->fetchColumn();
                if ($iid !== false) {
                    Session::put('current_institute_id', (int)$iid);
                    return (int)$iid;
                }
            }
        } catch (\Throwable $_) {
        }
        return null;
    }

    /**
     * Phase 25.Q — imposta esplicitamente l'istituto attivo (selettore UI).
     * Permesso solo se l'utente ha effettivamente accesso a quell'istituto:
     *   - student: identità con institute_id (sola lettura, non cambia)
     *   - teacher: presente in teacher_institutes per quell'istituto
     *   - admin scoped: admin_institute_id == $iid
     *   - super-admin: qualunque istituto
     */
    public static function setCurrentInstitute(int $iid): bool
    {
        if (!self::check()) {
            return false;
        }
        if (self::isSuperAdmin()) {
            Session::put('current_institute_id', $iid);
            return true;
        }
        $role = self::role();
        $uid = (int)(Session::get('user_id', 0));
        if ($uid === 0) {
            return false;
        }
        if (!Database::isAvailable()) {
            return false;
        }
        try {
            $pdo = Database::connection();
            if ($role === 'student') {
                $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND institute_id = ?');
                $stmt->execute([$uid, $iid]);
                if ($stmt->fetchColumn()) {
                    Session::put('current_institute_id', $iid);
                    return true;
                }
            } elseif ($role === 'teacher') {
                $stmt = $pdo->prepare('SELECT 1 FROM teacher_institutes WHERE user_id = ? AND institute_id = ?');
                $stmt->execute([$uid, $iid]);
                if ($stmt->fetchColumn()) {
                    Session::put('current_institute_id', $iid);
                    return true;
                }
            } elseif ($role === 'admin') {
                $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND admin_institute_id = ?');
                $stmt->execute([$uid, $iid]);
                if ($stmt->fetchColumn()) {
                    Session::put('current_institute_id', $iid);
                    return true;
                }
            }
        } catch (\Throwable $_) {
        }
        return false;
    }

    /**
     * Phase 25.Q — verifica se l'utente loggato è admin (scope locale) di
     * un istituto specifico. Super-admin globale ritorna sempre true.
     */
    public static function isAdminOfInstitute(int $iid): bool
    {
        if (!self::check()) {
            return false;
        }
        if (self::isSuperAdmin()) {
            return true;
        }
        if (self::role() !== 'admin') {
            return false;
        }
        if (!Database::isAvailable()) {
            return false;
        }
        try {
            $stmt = Database::connection()->prepare(
                'SELECT 1 FROM users WHERE id = ? AND admin_institute_id = ? LIMIT 1'
            );
            $stmt->execute([(int)Session::get('user_id', 0), $iid]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $_) {
            return false;
        }
    }

    /**
     * Attempts login. Returns [User|null, reason|null].
     *
     * @param string $username
     * @param string $password
     * @param array{indirizzo:string,classe:string}|null $section
     * @param string|null $clientIp
     * @return array{0: ?User, 1: ?string}
     */
    public static function attempt(
        string $username,
        string $password,
        ?array $section = null,
        ?string $clientIp = null,
        ?UserRepositoryInterface $repo = null,
        ?BlockList $blockList = null,
        ?RateLimiter $limiter = null,
    ): array {
        $limiter ??= new RateLimiter(
            maxAttempts:    (int)Config::get('auth.rate_limit.max_attempts', 5),
            lockoutSeconds: (int)Config::get('auth.rate_limit.lockout_seconds', 300),
        );

        if ($limiter->isBlocked()) {
            return [null, self::REASON_RATE_LIMITED];
        }

        $repo      ??= self::defaultRepository($section);
        $blockList ??= self::defaultBlockList();

        $user = $repo->find($username);
        if (!$user || $user->passwordHash === '' || !$user->verifyPassword($password)) {
            $limiter->hit();
            return [null, self::REASON_INVALID];
        }
        if (!$user->active) {
            $limiter->hit();
            return [null, self::REASON_INACTIVE];
        }

        // Block checks — administrators bypass
        if (!$user->isAdmin()) {
            // Lockout legacy (JSON, manuale/permanente) + lockout WAF DB
            // (expiry-aware: auto-ban temporanei da brute-force, Phase audit
            // 2026-06-01). Il check DB rispetta expires_at, il JSON no.
            if ($blockList->isUsernameBlocked($username) || self::wafCredentialBlocked($username)) {
                $limiter->hit();
                return [null, self::REASON_BLOCKED];
            }
            if ($section && $clientIp) {
                $sectionCode = $section['indirizzo'] . $section['classe'];
                if ($blockList->isIpBlockedForSection($clientIp, $sectionCode)) {
                    $limiter->hit();
                    return [null, self::REASON_IP_BLOCKED];
                }
            }
        }

        if ($section) {
            $sectionCode = $section['indirizzo'] . $section['classe'];
            if (!$user->canAccessSection($sectionCode)) {
                $limiter->hit();
                return [null, self::REASON_UNAUTHORIZED];
            }
        }

        // Success — commit session state
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Session::put('autenticato', true);
        Session::put('username', $user->username);
        Session::put('user_role', $user->role);
        Session::put('login_time', time());
        // Phase 25.Q.7 — reset claim cached del precedente utente:
        // is_super_admin, user_id, current_institute_id potrebbero contenere
        // valori del PREVIOUS login. Forziamo refresh DB-backed alla prossima
        // chiamata (forget = remove key; lazy-load ricaricherà da DB).
        Session::forget('is_super_admin');
        Session::forget('user_id');
        Session::forget('current_institute_id');
        if ($section) {
            Session::put('authenticated_section', $section);
        }
        $limiter->reset();

        return [$user, null];
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    /**
     * Extract {indirizzo, classe} from a URL path like "/eser/ar/eser_ar5s/...".
     */
    public static function sectionFromUrl(string $url): ?array
    {
        $pattern = Config::get('auth.session_pattern', '#(?:eser|lab|map)_([a-z]+)(\d+[sb]?)#');
        if (preg_match($pattern, $url, $m)) {
            return ['indirizzo' => $m[1], 'classe' => $m[2]];
        }
        return null;
    }

    private static function defaultRepository(?array $section): UserRepositoryInterface
    {
        // Phase 18 — DB-only. Import one-shot dei JSON legacy via
        // tools/import_legacy_users_to_db.php popola `users`. JSON files
        // archiviati in _archive_phase18/users/.
        return new UserRepository();
    }

    /**
     * Lockout credenziali via WAF DB (waf_blocked_credentials, expiry-aware).
     * Best-effort: false su DB non disponibile (non blocca il login).
     */
    private static function wafCredentialBlocked(string $username): bool
    {
        try {
            return (new \App\Services\Waf\WafSecurityRepository())->isCredentialBlocked($username);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function defaultBlockList(): BlockList
    {
        $paths = Config::get('auth.paths');
        return new BlockList(
            blockedCredentialsPath: $paths['blocked_credentials'],
            blockedIpsPath:         $paths['blocked_ips'],
        );
    }
}
