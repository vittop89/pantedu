<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use InvalidArgumentException;
use RuntimeException;

/**
 * Scenario di esercizio dell'istanza (ADR-032, 2026-09-03).
 *
 * Tre scenari, gli stessi del pacchetto consegnato al DPO:
 *
 *   1. `personal`   — uso personale dell'autore. Nessuna iscrizione aperta,
 *                     nessun account studente: gli studenti consultano i
 *                     contenuti pubblicati con la credenziale del docente.
 *                     Titolare: il gestore dell'istanza.
 *   2. `colleagues` — colleghi docenti di qualunque scuola. Iscrizione dei
 *                     docenti aperta (con approvazione), nessun account
 *                     studente. Titolare: il gestore dell'istanza; la
 *                     piattaforma non e' uno strumento d'Istituto.
 *   3. `institute`  — adozione formale da parte di un Istituto, con account
 *                     studente (modalita' Completa / Ridotta / Anonima).
 *                     Titolare: l'Istituto; chi conduce l'istanza e'
 *                     Responsabile ex art. 28. Ammesso SOLO su un'istanza
 *                     dichiarata qualificata ACN (`INSTANCE_ACN_QUALIFIED`):
 *                     il Regolamento cloud ACN consente alle scuole di
 *                     avvalersi solo di infrastrutture qualificate, e
 *                     l'istanza di un privato non lo e'.
 *
 * Ogni scenario determina: chi puo' iscriversi, se esistono account studente,
 * chi e' il titolare, quale informativa viene servita, quali documenti fanno
 * da riferimento, e come si presentano le pagine di accesso.
 *
 * RAPPORTO CON DeploymentMode (ADR-017)
 *   Il modo legacy `single | institute` resta come asse derivato: lo scenario
 *   e' la sorgente di verita' e, quando viene cambiato, scrive anche
 *   `deployment.json` (institute ⇔ scenario 3) e `student_registration.json`
 *   (anonima fuori dallo scenario 3). Cosi' tutto il codice che interroga
 *   DeploymentMode e StudentRegistration continua a funzionare senza sapere
 *   degli scenari. In assenza del file dello scenario, lo scenario si deduce
 *   dal modo legacy: institute → 3, single → 1.
 *
 * Priorita' lookup: `storage/config/deployment_scenario.json` (pannello
 * /admin/system/deployment) > `DEPLOYMENT_SCENARIO` in .env > modo legacy.
 */
final class DeploymentScenario
{
    public const PERSONAL   = 'personal';
    public const COLLEAGUES = 'colleagues';
    public const INSTITUTE  = 'institute';
    public const ALL        = [self::PERSONAL, self::COLLEAGUES, self::INSTITUTE];

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    // ── Stato ────────────────────────────────────────────────────────────

    public static function current(): string
    {
        $rt = self::loadRuntime();
        if ($rt !== null && in_array((string)($rt['scenario'] ?? ''), self::ALL, true)) {
            return (string)$rt['scenario'];
        }
        $env = (string)Config::get('app.deployment_scenario', '');
        if (in_array($env, self::ALL, true)) {
            return $env;
        }
        return DeploymentMode::current() === DeploymentMode::INSTITUTE ? self::INSTITUTE : self::PERSONAL;
    }

    public static function isPersonal(): bool   { return self::current() === self::PERSONAL; }
    public static function isColleagues(): bool { return self::current() === self::COLLEAGUES; }
    public static function isInstitute(): bool  { return self::current() === self::INSTITUTE; }

    public static function number(string $scenario): int
    {
        return match ($scenario) {
            self::PERSONAL   => 1,
            self::COLLEAGUES => 2,
            self::INSTITUTE  => 3,
            default          => 0,
        };
    }

    public static function label(?string $scenario = null): string
    {
        $s = $scenario ?? self::current();
        return match ($s) {
            self::PERSONAL   => 'Scenario 1 — Uso personale dell\'autore',
            self::COLLEAGUES => 'Scenario 2 — Colleghi docenti di qualunque scuola',
            self::INSTITUTE  => 'Scenario 3 — Adozione da parte di un Istituto',
            default          => $s,
        };
    }

    /**
     * Cosa cambia da uno scenario all'altro, per il pannello admin.
     *
     * @return array<string, array<string,string>> aspetto → [scenario → descrizione]
     */
    public static function comparison(): array
    {
        return [
            'Iscrizione docenti' => [
                self::PERSONAL   => 'chiusa: solo l\'autore',
                self::COLLEAGUES => 'aperta, con approvazione dell\'amministratore',
                self::INSTITUTE  => 'aperta ai docenti dell\'Istituto, con approvazione',
            ],
            'Account studenti' => [
                self::PERSONAL   => 'nessuno: accesso con la credenziale del docente',
                self::COLLEAGUES => 'nessuno: accesso con la credenziale del docente',
                self::INSTITUTE  => 'Completa / Ridotta / Anonima, a scelta del Titolare',
            ],
            'Titolare del trattamento' => [
                self::PERSONAL   => 'il gestore dell\'istanza',
                self::COLLEAGUES => 'il gestore dell\'istanza (art. 4(7) GDPR)',
                self::INSTITUTE  => 'l\'Istituto; il gestore e\' Responsabile ex art. 28',
            ],
            'Informativa servita' => [
                self::PERSONAL   => 'docs/privacy/informativa.md',
                self::COLLEAGUES => 'docs/privacy/informativa.md',
                self::INSTITUTE  => 'docs/privacy/informativa-istituto.md (Titolare = Istituto)',
            ],
            'DPA art. 28' => [
                self::PERSONAL   => 'non applicabile',
                self::COLLEAGUES => 'non applicabile',
                self::INSTITUTE  => 'obbligatorio (/legal/dpa)',
            ],
            'Pagine di accesso' => [
                self::PERSONAL   => '/login per l\'autore; /accesso-classe per gli studenti',
                self::COLLEAGUES => '/login e /register per i docenti; /accesso-classe per gli studenti',
                self::INSTITUTE  => '/login e /register per docenti e studenti; SPID/CIE in roadmap',
            ],
            'Infrastruttura' => [
                self::PERSONAL   => 'qualunque',
                self::COLLEAGUES => 'qualunque',
                self::INSTITUTE  => 'SOLO qualificata ACN, condotta dall\'Istituto o da un fornitore qualificato',
            ],
        ];
    }

    // ── Politiche derivate ───────────────────────────────────────────────

    /** L'iscrizione self-service dei docenti e' aperta? */
    public static function teacherSelfSignupOpen(): bool
    {
        return self::current() !== self::PERSONAL;
    }

    /** Esistono account studente? Solo nello scenario 3, e non in modalita' Anonima. */
    public static function studentAccountsEnabled(): bool
    {
        return self::isInstitute() && !StudentRegistration::isAnonymous();
    }

    /** @return list<string> ruoli ammessi al form di registrazione */
    public static function allowedRegistrationRoles(): array
    {
        return match (self::current()) {
            self::PERSONAL   => [],
            self::COLLEAGUES => ['teacher'],
            default          => self::studentAccountsEnabled() ? ['student', 'teacher'] : ['teacher'],
        };
    }

    /** Nome del Titolare, come compare nei documenti legali. */
    public static function controllerName(): string
    {
        if (self::isInstitute()) {
            return DeploymentMode::instituteLegalName() ?: 'Istituto scolastico';
        }
        return (string)(Config::get('app.instance_operator_name') ?: 'Gestore dell\'istanza');
    }

    public static function dpoContact(): string
    {
        return DeploymentMode::dpoContact();
    }

    /**
     * L'istanza e' dichiarata su infrastruttura qualificata ACN? Si dichiara
     * in .env (`INSTANCE_ACN_QUALIFIED=true`) da chi conduce l'istanza: e' una
     * responsabilita' che il pannello non puo' assumersi da solo.
     */
    public static function instanceAcnQualified(): bool
    {
        return filter_var(Config::get('app.instance_acn_qualified', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Motivo per cui uno scenario NON e' attivabile adesso, o null.
     *
     * @param array{institute_owner_email?:string,institute_legal_name?:string} $institute
     */
    public static function activationBlocker(string $scenario, array $institute = [], int $activeUsers = 0): ?string
    {
        if (!in_array($scenario, self::ALL, true)) {
            return 'invalid_scenario';
        }
        if ($scenario === self::PERSONAL && $activeUsers > 1) {
            // Stessa protezione del vecchio switch institute→single: non si
            // lasciano account "dormienti" fingendo che non esistano.
            return 'personal_blocked_active_users';
        }
        if ($scenario === self::INSTITUTE) {
            if (!self::instanceAcnQualified()) {
                return 'institute_requires_acn_instance';
            }
            if (!filter_var((string)($institute['institute_owner_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                return 'invalid_email';
            }
            $name = trim((string)($institute['institute_legal_name'] ?? ''));
            if ($name === '' || strlen($name) > 255) {
                return 'invalid_name';
            }
        }
        return null;
    }

    /**
     * Blocchi per ciascuno scenario, senza i dati dell'Istituto (che arrivano
     * dal form): serve al pannello per spiegare cosa e' attivabile.
     *
     * @return array<string, ?string>
     */
    public static function blockers(int $activeUsers): array
    {
        $out = [];
        foreach (self::ALL as $s) {
            $out[$s] = match ($s) {
                self::PERSONAL  => $activeUsers > 1 ? 'personal_blocked_active_users' : null,
                self::INSTITUTE => self::instanceAcnQualified() ? null : 'institute_requires_acn_instance',
                default         => null,
            };
        }
        return $out;
    }

    // ── Documenti di riferimento ─────────────────────────────────────────

    /** File markdown dell'informativa servita su /privacy/informativa. */
    public static function informativaFile(?string $scenario = null): string
    {
        $s    = $scenario ?? self::current();
        $base = dirname(__DIR__, 2) . '/docs/privacy/';
        return $s === self::INSTITUTE ? $base . 'informativa-istituto.md' : $base . 'informativa.md';
    }

    /**
     * Documenti legali di riferimento nello scenario, nell'ordine in cui
     * vanno mostrati (footer delle pagine legali, form di registrazione,
     * pannello admin).
     *
     * @return list<array{key:string,label:string,route:string,note:string}>
     */
    public static function legalDocuments(?string $scenario = null): array
    {
        $s = $scenario ?? self::current();
        $inst = $s === self::INSTITUTE;
        $docs = [
            [
                'key'   => 'informativa',
                'label' => $inst ? 'Informativa privacy dell\'Istituto (art. 13 GDPR)' : 'Informativa privacy (art. 13 GDPR)',
                'route' => '/privacy/informativa',
                'note'  => $inst
                    ? 'Titolare: l\'Istituto; chi conduce l\'istanza e\' Responsabile ex art. 28'
                    : 'Titolare: il gestore dell\'istanza',
            ],
            [
                'key'   => 'tos',
                'label' => 'Termini di Servizio — docente',
                'route' => '/legal/tos',
                'note'  => $s === self::PERSONAL ? 'pubblicati; nessuna iscrizione aperta' : 'accettati alla registrazione',
            ],
            [
                'key'   => 'aup',
                'label' => 'Acceptable Use Policy',
                'route' => '/legal/aup',
                'note'  => $s === self::PERSONAL ? 'pubblicata; nessuna iscrizione aperta' : 'accettata alla registrazione',
            ],
        ];
        if ($inst) {
            $docs[] = [
                'key'   => 'dpa',
                'label' => 'Accordo sul trattamento dei dati (DPA, art. 28)',
                'route' => '/legal/dpa',
                'note'  => 'obbligatorio fra l\'Istituto e chi conduce l\'istanza',
            ];
        }
        $docs[] = ['key' => 'takedown',    'label' => 'Procedura Notice & Takedown',      'route' => '/legal/takedown-procedure', 'note' => ''];
        $docs[] = ['key' => 'ai-act',      'label' => 'Assessment AI Act',               'route' => '/legal/ai-act',             'note' => ''];
        $docs[] = ['key' => 'ai-literacy', 'label' => 'Alfabetizzazione IA (art. 4 AI Act)', 'route' => '/legal/ai-literacy',   'note' => ''];
        $docs[] = ['key' => 'security',    'label' => 'Misure di sicurezza (art. 32)',   'route' => '/security',                 'note' => ''];
        return $docs;
    }

    // ── Snapshot e persistenza ───────────────────────────────────────────

    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        $rt  = self::loadRuntime();
        $cur = self::current();
        $source = $rt !== null
            ? 'runtime_override'
            : ((string)Config::get('app.deployment_scenario', '') !== '' ? 'env' : 'legacy_mode');
        return [
            'scenario'            => $cur,
            'number'              => self::number($cur),
            'label'               => self::label($cur),
            'source'              => $source,
            'updated_at'          => $rt['updated_at'] ?? null,
            'updated_by'          => $rt['updated_by'] ?? null,
            'reason'              => $rt['reason'] ?? null,
            'acn_qualified'       => self::instanceAcnQualified(),
            'teacher_signup_open' => self::teacherSelfSignupOpen(),
            'student_accounts'    => self::studentAccountsEnabled(),
            'controller'          => self::controllerName(),
            'dpo_contact'         => self::dpoContact(),
        ];
    }

    /**
     * Cambia scenario. Scrive il file dello scenario e allinea gli assi
     * legacy: modo (institute ⇔ 3) e registrazione studenti (anonima fuori
     * dal 3). La validazione (`activationBlocker`) e' del chiamante: qui si
     * rifiuta solo un valore fuori enumerazione.
     *
     * @param array{institute_owner_email?:string,institute_legal_name?:string} $institute
     */
    public static function persist(string $scenario, string $actor, string $reason, array $institute = []): void
    {
        if (!in_array($scenario, self::ALL, true)) {
            throw new InvalidArgumentException('invalid_scenario');
        }

        self::writeJson(self::runtimePath(), [
            'scenario'   => $scenario,
            'updated_at' => date('c'),
            'updated_by' => $actor,
            'reason'     => $reason,
        ]);

        if ($scenario === self::INSTITUTE) {
            DeploymentMode::persistRuntime([
                'mode'                  => DeploymentMode::INSTITUTE,
                'institute_owner_email' => (string)($institute['institute_owner_email'] ?? ''),
                'institute_legal_name'  => (string)($institute['institute_legal_name'] ?? ''),
            ]);
        } else {
            DeploymentMode::persistRuntime([
                'mode'                  => DeploymentMode::SINGLE,
                'institute_owner_email' => '',
                'institute_legal_name'  => '',
            ]);
            // Fuori dallo scenario 3 non esistono account studente: la
            // registrazione studenti resta disattivata qualunque cosa dicesse
            // il file precedente.
            StudentRegistration::persist(StudentRegistration::ANONYMOUS, StudentRegistration::onlySuperadminClasses());
        }

        self::$cache = null;
    }

    /** Rimuove l'override runtime dello scenario (si torna a env / modo legacy). */
    public static function clearRuntime(): bool
    {
        $path = self::runtimePath();
        self::$cache = null;
        return !is_file($path) || @unlink($path);
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }

    // ── Interni ──────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private static function loadRuntime(): ?array
    {
        if (self::$cache !== null) {
            return self::$cache['_loaded'] === false ? null : self::$cache;
        }
        $path = self::runtimePath();
        if (!is_file($path)) {
            self::$cache = ['_loaded' => false];
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || !in_array((string)($data['scenario'] ?? ''), self::ALL, true)) {
            error_log('[DeploymentScenario] override illeggibile, ignorato: ' . $path);
            self::$cache = ['_loaded' => false];
            return null;
        }
        $data['_loaded'] = true;
        self::$cache = $data;
        return $data;
    }

    /** @param array<string,mixed> $data */
    private static function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot_create_config_dir');
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('cannot_encode_config');
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('cannot_write_config');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('cannot_rename_config');
        }
        @chmod($path, 0640);
    }

    private static function runtimePath(): string
    {
        return (string)Config::get('app.paths.storage') . '/config/deployment_scenario.json';
    }
}
