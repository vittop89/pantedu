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
    /** ADR-040 — l'amministratore di istituto fuori dallo scenario 3. */
    public const REASON_SCENARIO    = 'role_not_in_scenario';

    /**
     * Ogni quanto, al massimo, una sessione aperta rilegge dal database ruolo,
     * stato dell'account e flag di super-admin (23/9/2026).
     *
     * Fino a quel giorno si leggevano solo al login: disattivare un account o
     * cambiargli il ruolo non toccava le sessioni aperte, che restavano dentro
     * fino a dodici ore con il ruolo di prima; il flag di super-admin viveva in
     * due cache diverse, una per tutta la sessione e una di cinque minuti
     * (revisione 2026-09, rilievi A-66 e A-47). Ora un minuto vale per tutti e
     * tre, ed è un TTL solo: lo leggono AuthMiddleware e
     * AclPolicy::isSuperAdmin(), attraverso chiudiSeRevocata(). Non più di
     * sessanta secondi: lo fissa AccountRevocatoFuoriDallaSessioneTest.
     *
     * Il costo: una SELECT sulla chiave univoca `username` per sessione, al più
     * ogni sessanta secondi (misurato in SessioneRilettaDalDatabaseTest).
     */
    public const CLAIMS_TTL_SECONDS = 60;

    /**
     * Vero mentre chiudiSeRevocata() sta verificando la sessione (23/9/2026).
     *
     * La verifica può tornare su sé stessa: il registro chiede chi è l'attore
     * (Auth::actorRole(), cioè AclPolicy::isSuperAdmin(), cioè di nuovo
     * chiudiSeRevocata()), e così qualunque codice che, sul cammino della
     * verifica, chieda il flag. Senza questo segno, con la sessione ancora
     * aperta e i claims scaduti, il giro non finisce e consuma memoria finché
     * il processo non muore: misurato oltre 1 GB nella verifica del ramo che
     * ha introdotto la rilettura. La domanda che rientra riceve «non chiusa»;
     * AclPolicy, con i claims non ancora riletti, risponde no. Lo fissa
     * AccountRevocatoFuoriDallaSessioneTest.
     */
    private static bool $verificaInCorso = false;

    /**
     * ADR-040 — l'amministratore di istituto esiste solo nello scenario 3, dove
     * il Titolare è l'Istituto. Fuori non entra, e una sessione aperta prima
     * che lo scenario cambiasse non ha nessuna zona e nessun ambito.
     */
    public static function amministratoreDiIstitutoFuoriScenario(string $role): bool
    {
        return $role === \App\Domain\Role::INSTITUTE_ADMIN->value
            && !\App\Support\DeploymentScenario::isInstitute();
    }

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
     *
     * 23/9/2026 — segna anche quando li ha letti (`claims_at`). Accetta i
     * valori nuovi così come sono: è per il cambio che l'utente fa su di sé,
     * nella stessa richiesta e dopo Session::regenerate(). La rilettura
     * periodica non passa da qui ma da chiudiSeRevocata(), che un cambio lo
     * tratta come una revoca.
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
        $row = self::leggiClaims($username);
        if ($row === null) {
            return false;
        }
        Session::put('user_role', (string)$row['role']);
        Session::put('is_super_admin', (bool)$row['is_super_admin']);
        Session::put('active', (bool)$row['active']);
        Session::put('claims_at', time());
        return true;
    }

    /**
     * Ruolo, flag di super-admin e stato dell'account, come stanno nel
     * database; null se l'account non c'è più.
     *
     * @return array{role: string, is_super_admin: bool, active: bool}|null
     */
    private static function leggiClaims(string $username): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT role, is_super_admin, active FROM users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            return null;
        }
        return [
            'role'           => (string)$row['role'],
            'is_super_admin' => (bool)$row['is_super_admin'],
            'active'         => (bool)$row['active'],
        ];
    }

    /**
     * La sessione aperta è ancora quella che il login ha concesso? Se no la
     * chiude, e dice true (23/9/2026).
     *
     * Rilegge i claims dal database se sono più vecchi di CLAIMS_TTL_SECONDS
     * (revisione 2026-09, rilievo A-66). La sessione si chiude se l'account
     * non c'è più, se è disattivato, e se ruolo o flag di super-admin non sono
     * più quelli della sessione. Un cambio non si prende in corsa: al login
     * dopo ripassano tutti i controlli del login — secondo fattore dovuto per
     * il ruolo, Termini, cambio password —, che per una promozione ad
     * amministratore sono la 2FA obbligatoria. Fino al 23/9 la sessione
     * prendeva il ruolo nuovo, e una promozione saltava la 2FA.
     *
     * La chiamano AuthMiddleware e AclPolicy::isSuperAdmin(): quest'ultima
     * anche sulle rotte senza `auth` (il cancello di Grafana, /metrics, i due
     * cancelli globali del Kernel). E /auth/user-info, prima di rispondere.
     * Chi la trova chiusa prosegue da ospite.
     *
     * Se il database non risponde non si chiude niente e non si segna la
     * lettura: si riprova alla richiesta dopo, e AclPolicy non concede il
     * flag che non ha potuto verificare. Buttare fuori tutti per un database
     * che singhiozza sarebbe peggio del minuto di ritardo.
     *
     * Una domanda che torna qui mentre la verifica è in corso (vedi
     * $verificaInCorso) riceve false e non la ripete.
     */
    public static function chiudiSeRevocata(): bool
    {
        if (!self::check() || self::$verificaInCorso) {
            return false;
        }
        self::$verificaInCorso = true;
        try {
            return self::verificaEChiudi();
        } finally {
            self::$verificaInCorso = false;
        }
    }

    /** Il corpo di chiudiSeRevocata(), senza il segno contro il rientro. */
    private static function verificaEChiudi(): bool
    {
        $motivo = self::motivoDiRevoca();
        if ($motivo === null) {
            return false;
        }
        // Prima si chiude, poi si scrive: il registro chiede chi è l'attore
        // (Auth::actorRole(), cioè AclPolicy::isSuperAdmin()), e con la
        // sessione ancora aperta la domanda tornerebbe qui (la ferma
        // $verificaInCorso, ma l'attore sarebbe quello di una sessione
        // revocata). L'attore si passa a mano, com'era nella sessione chiusa.
        $utente = self::user() ?? [];
        $ruolo  = Session::get('is_super_admin') === true ? 'super_admin' : self::role();
        self::chiudiSessione();
        \App\Services\Audit\ActivityLogger::event(
            'session_revoked',
            subjectType: 'user',
            subjectId:   (string)($utente['username'] ?? ''),
            details:     ['motivo' => $motivo],
            outcome:     'denied',
            actorUserId: isset($utente['id']) ? (int)$utente['id'] : null,
            actorName:   (string)($utente['username'] ?? ''),
            actorRole:   $ruolo,
        );
        return true;
    }

    /**
     * Perché la sessione non vale più, o null se vale (o se non si è potuto
     * verificarlo). Con claims ancora freschi non legge il database.
     */
    private static function motivoDiRevoca(): ?string
    {
        $letti = Session::get('claims_at');
        if (\is_int($letti) && time() - $letti < self::CLAIMS_TTL_SECONDS) {
            // Freschi: li ha appena scritti refreshCurrentUserClaims(), che
            // accetta anche un account non attivo (un minore approvato prima
            // del consenso del genitore).
            return Session::get('active') === false ? 'account_disattivato' : null;
        }
        try {
            if (!Database::isAvailable()) {
                return null;
            }
            $riga = self::leggiClaims((string)Session::get('username', ''));
        } catch (\Throwable $e) {
            \error_log('[Auth::chiudiSeRevocata] claims non riletti: ' . $e->getMessage());
            return null;
        }
        if ($riga === null) {
            return 'account_assente';
        }
        if (!$riga['active']) {
            return 'account_disattivato';
        }
        if ($riga['role'] !== self::role()) {
            return 'ruolo_cambiato';
        }
        // Dopo il login il flag non è in sessione (establishSession lo
        // dimentica): la prima lettura lo fissa, quelle dopo lo confrontano.
        $flag = Session::get('is_super_admin');
        if ($flag !== null && (bool)$flag !== $riga['is_super_admin']) {
            return 'super_admin_cambiato';
        }
        Session::put('is_super_admin', $riga['is_super_admin']);
        Session::put('active', true);
        Session::put('claims_at', time());
        return null;
    }

    /**
     * Chiude la sessione di un account revocato, come fa il timeout di
     * inattività (Session::enforceTimeout): via i dati e il cookie, e una
     * sessione vuota per il resto della richiesta.
     */
    private static function chiudiSessione(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            Session::destroy();
            session_start();
        }
        $_SESSION = [];
    }

    public static function hasAccess(string $zone): bool
    {
        if (self::amministratoreDiIstitutoFuoriScenario(self::role())) {
            return false;
        }
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
     * Le zone di `roles.access_zones` a cui l'utente ha accesso, nell'ordine
     * della configurazione (2026-09-14).
     *
     * Le legge il client da `data-fm-zones` sul body (views/layout/app.php). Il
     * client chiede la zona, non il ruolo: così non deve sapere che in
     * database l'amministratore è `administrator`, che `admin` è un alias
     * storico, né quali ruoli stanno in quale zona. Prima tre moduli JS
     * confrontavano il ruolo con liste scritte a mano, e due avevano `admin`:
     * l'amministratore finiva sugli elenchi dello studente.
     *
     * @return list<string>
     */
    public static function zone(): array
    {
        $zone = [];
        foreach (array_keys((array)Config::get('roles.access_zones', [])) as $zona) {
            if (self::hasAccess((string)$zona)) {
                $zone[] = (string)$zona;
            }
        }
        return $zone;
    }

    /**
     * Ruolo da scrivere nei log di audit.
     *
     * `role` viene da users.role e non dice nulla sul super-admin, che e' un
     * flag separato (users.is_super_admin): un super-admin con role='teacher'
     * finiva a registro come un docente qualunque, indistinguibile da chi non
     * puo' fare quelle operazioni. Il visualizzatore log colora gia' in modo
     * distinto il valore 'super_admin' — gli mancava solo di riceverlo.
     */
    public static function actorRole(): string
    {
        try {
            if (self::isSuperAdmin()) {
                return 'super_admin';
            }
        } catch (\Throwable $e) {
            // Un log di audit non deve fallire perche' il DB non risponde:
            // si degrada al ruolo di sessione invece di perdere la riga.
            \error_log('[Auth::actorRole] super-admin check failed: ' . $e->getMessage());
        }
        return self::role();
    }

    /**
     * Phase 14 — flag tecnico ortogonale al role. Un super-admin è un
     * operatore tecnico con accesso tracciato a metriche/logs/materiali
     * altrui. NON ha accesso a dati personali studenti (vedi AclPolicy).
     *
     * 23/9/2026 — delega ad AclPolicy::isSuperAdmin(), che è l'unica
     * implementazione (rilievo A-47). Qui c'era una copia che teneva il valore
     * in sessione fino al logout, mentre quella di AclPolicy lo rileggeva ogni
     * cinque minuti: un flag revocato nel database restava valido per
     * `role:admin` fino a dodici ore. Ora il TTL è uno, CLAIMS_TTL_SECONDS.
     */
    public static function isSuperAdmin(): bool
    {
        return \App\Services\AclPolicy::isSuperAdmin();
    }

    /**
     * Phase 25.Q — istituto attivo per l'utente loggato.
     *
     * Logica:
     *   - student → users.institute_id (1:1 fisso, immutabile in sessione)
     *   - teacher → cookie/session `current_institute_id` selezionato dal
     *               selettore UI, fallback al primo `teacher_institutes`
     *   - institute_admin → users.admin_institute_id, solo nello scenario 3
     *               (ADR-040; fino al 14/9/2026 si confrontava `admin`, un
     *               ruolo che nessuna zona conosceva)
     *   - super-admin senza scope → null (tutti gli istituti)
     *
     * @return int|null id istituto, null se super-admin globale o non auth
     */
    public static function currentInstitute(): ?int
    {
        if (!self::check()) {
            return null;
        }
        // ADR-040 — prima della cache: una sessione aperta nello scenario 3 ha
        // l'istituto in sessione, e se lo scenario cambia non deve tenerlo.
        if (self::amministratoreDiIstitutoFuoriScenario(self::role())) {
            return null;
        }
        // Cache di sessione: la scrive setCurrentInstitute() (selettore UI via
        // TenantController) oppure questo stesso metodo alla prima lettura.
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
            if ($role === \App\Domain\Role::INSTITUTE_ADMIN->value && $row['admin_institute_id']) {
                if (self::amministratoreDiIstitutoFuoriScenario($role)) {
                    return null;
                }
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
     *   - institute_admin: admin_institute_id == $iid, solo nello scenario 3
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
            } elseif (
                $role === \App\Domain\Role::INSTITUTE_ADMIN->value
                && !self::amministratoreDiIstitutoFuoriScenario($role)
            ) {
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
        if (
            self::role() !== \App\Domain\Role::INSTITUTE_ADMIN->value
            || self::amministratoreDiIstitutoFuoriScenario(self::role())
        ) {
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
        bool $establishSession = true,
    ): array {
        $limiter ??= new RateLimiter(
            maxAttempts:    (int)Config::get('auth.rate_limit.max_attempts', 5),
            lockoutSeconds: (int)Config::get('auth.rate_limit.lockout_seconds', 300),
        );

        if ($limiter->isBlocked()) {
            return [null, self::REASON_RATE_LIMITED];
        }

        $repo      ??= self::defaultRepository();
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
        // ADR-040 — dopo la password, così il motivo lo legge solo chi la
        // conosce: a chi prova i nomi utente non dice quali account esistono.
        // Non è un tentativo fallito, e non si conta come tale.
        if (self::amministratoreDiIstitutoFuoriScenario($user->role)) {
            return [null, self::REASON_SCENARIO];
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
            // 23/9/2026 (A-75) — il codice nello stesso formato di User::$course,
            // "{indirizzo}.{classe}", composto dalla stessa funzione che lo
            // compone per la registrazione e per UserRepository. Qui prima si
            // scriveva "sc1s" e il corso era "sc.1s" (o null): uno studente non
            // entrava mai. Il codice senza punto resta sopra, per la lista dei
            // blocchi per sezione, che lo scrive così.
            $sectionCode = \App\Services\Student\StudentProfileService::course(
                $section['indirizzo'],
                $section['classe'],
            ) ?? '';
            if (!$user->canAccessSection($sectionCode)) {
                $limiter->hit();
                return [null, self::REASON_UNAUTHORIZED];
            }
        }

        // Credenziali valide. La sessione si apre subito, tranne quando il
        // chiamante deve prima interporre un secondo fattore: in quel caso
        // password corretta NON significa ancora "autenticato", e la sessione
        // resta chiusa finche' il codice TOTP non e' stato verificato.
        if ($establishSession) {
            self::establishSession($user, $section);
        }
        $limiter->reset();

        return [$user, null];
    }

    /**
     * Apre la sessione autenticata per un utente gia' verificato.
     *
     * Estratto da attempt() perche' il login a due fattori deve poterlo
     * invocare in un secondo momento, dopo la verifica del codice: senza,
     * l'unico modo sarebbe stato duplicare qui le chiavi di sessione, con la
     * garanzia che prima o poi le due copie sarebbero divergute.
     *
     * Rigenera l'id di sessione: e' la difesa contro la session fixation, e
     * vale sia per il login diretto sia per il secondo passaggio.
     */
    public static function establishSession(User $user, ?array $section = null): void
    {
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
        // 23/9/2026 — anche l'ora dell'ultima lettura: i claims del login
        // precedente non contano per questo.
        Session::forget('claims_at');
        Session::forget('active');
        if ($section) {
            Session::put('authenticated_section', $section);
        }

        self::registraAccesso($user->username);
    }

    /**
     * Segna che questo account è stato usato adesso.
     *
     * Qui e non nel controller perché questo è il punto **unico** in cui una
     * sessione autenticata nasce: ci passa il login diretto e ci passa quello
     * che completa il secondo fattore. Metterlo nel controller avrebbe voluto
     * dire scriverlo in due posti e dimenticarne uno il giorno che se ne
     * aggiunge un terzo.
     *
     * A che serve: la retention dichiara che gli account **inattivi** da due
     * anni vengono anonimizzati. Fino alla migrazione 108 quel dato non
     * esisteva, e `anonymize_expired.php` ripiegava su `approved_at`, che
     * misura l'anzianità dell'iscrizione e non l'uso — cioè avrebbe
     * anonimizzato chi il sistema lo stava usando.
     *
     * Non può far fallire il login. Un accesso legittimo non si nega perché
     * non si è riusciti a scrivere una data statistica: se il database non
     * risponde, se ne riparla al prossimo accesso.
     */
    private static function registraAccesso(string $username): void
    {
        if (!Database::isAvailable()) {
            return;
        }
        try {
            Database::connection()
                ->prepare('UPDATE users SET last_access_at = NOW() WHERE username = ?')
                ->execute([$username]);
        } catch (\Throwable) {
            // Vedi sopra: il silenzio qui è voluto.
        }
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

    private static function defaultRepository(): UserRepositoryInterface
    {
        // Phase 18 — DB-only. Import one-shot dei JSON legacy via
        // tools/import_legacy_users_to_db.php popola `users`. JSON files
        // archiviati in _archive_phase18/users/.
        // 23/9/2026 — tolto il parametro `$section`, che non usava: faceva
        // credere che l'URL di provenienza scegliesse il repository degli
        // utenti (revisione architetturale del 23/9/2026, A-59). La sezione
        // serve solo al blocco dell'IP per sezione, più sopra in attempt().
        return new UserRepository();
    }

    /**
     * Lockout credenziali via WAF DB (waf_blocked_credentials, expiry-aware).
     * Best-effort: false su DB non disponibile (non blocca il login).
     */
    private static function wafCredentialBlocked(string $username): bool
    {
        try {
            return (new \App\Repositories\Waf\WafSecurityRepository())->isCredentialBlocked($username);
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
