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
 *   L'allineamento lo fa solo `persist()`, cioe' il pannello. Scenario e
 *   modo scritti a mano (DEPLOYMENT_SCENARIO e DEPLOYMENT_MODE in .env, o i
 *   file di storage/config) possono non combaciare, e lo scenario 3 scritto
 *   a mano salta il controllo di INSTANCE_ACN_QUALIFIED. Dal 23/9/2026, con
 *   APP_ENV=production, il container non parte in quei due casi
 *   (docker/verifica-avvio.php; revisione del 23/9, A-8).
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

    /** Il file del pannello, con la meccanica di SostituzioneSuFile (A-38). */
    private const FILE = 'deployment_scenario.json';

    // ── Stato ────────────────────────────────────────────────────────────

    public static function current(): string
    {
        $rt = self::loadRuntime();
        if ($rt !== null) {
            return (string)$rt['scenario'];
        }
        $env = (string)Config::get('app.deployment_scenario', '');
        if (in_array($env, self::ALL, true)) {
            return $env;
        }
        return DeploymentMode::current() === DeploymentMode::INSTITUTE ? self::INSTITUTE : self::PERSONAL;
    }

    public static function isPersonal(): bool
    {
        return self::current() === self::PERSONAL;
    }
    public static function isColleagues(): bool
    {
        return self::current() === self::COLLEAGUES;
    }
    public static function isInstitute(): bool
    {
        return self::current() === self::INSTITUTE;
    }

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
                self::PERSONAL   => 'docs/privacy/informativa-personale.md',
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

    /**
     * Sull'istanza possono esserci piu' docenti? Non nello scenario 1: c'e'
     * solo l'autore (tabella della Decisione; informativa dello scenario 1,
     * §2). Decide se a chi studia con la credenziale si parla di «la
     * credenziale di un altro docente» (il link della barra laterale e il
     * titolo di /accesso-classe, 19/9/2026): nello scenario 1 quell'altro
     * docente non esiste.
     *
     * Non decide se una credenziale in piu' si possa aggiungere: il
     * portachiavi ne tiene piu' d'una anche dello stesso docente, e il modulo
     * di /accesso-classe c'e' in ogni scenario (20/9/2026, ADR-032).
     */
    public static function consentePiuDocenti(): bool
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
        // Un'informativa per scenario (2026-09-06): chi e' Titolare e chi sono
        // gli interessati cambiano, e un testo solo per 1 e 2 lasciava in
        // dubbio se le iscrizioni fossero aperte.
        return match ($s) {
            self::INSTITUTE => $base . 'informativa-istituto.md',
            self::PERSONAL  => $base . 'informativa-personale.md',
            default         => $base . 'informativa.md',
        };
    }

    /** @var array<string, string> la versione di ciascun file, letta una volta per processo */
    private static array $versioniInformativa = [];

    /**
     * La versione del testo dell'informativa servita nello scenario (quello
     * attivo, se non se ne indica uno): il campo `versione:` del frontmatter
     * del file che `informativaFile()` sceglie. È la versione che i consensi
     * registrano (ConsentService::currentTextVersion()).
     *
     * 23/9/2026 (revisione architetturale DOC-19). I consensi si
     * registravano con `gdpr.text_version`, un numero ricopiato in
     * app/Config/gdpr.php da docs/privacy/informativa.md: giusto nello
     * scenario 2, sbagliato negli altri due, dove si serve un altro testo con
     * un registro di versioni proprio (2.16 registrato contro testi alla 1.2
     * e alla 1.3). Adesso il numero si legge dal file che l'utente vede, e
     * non c'è una copia da tenere allineata.
     *
     * Un file senza versione è un guasto del pacchetto, non un caso da
     * indovinare: si lancia, e un consenso non si registra contro un testo
     * che non si sa dire (tests/Unit/Services/Gdpr/InformativaVersionTest.php
     * prova che i tre file la dichiarano).
     *
     * @throws RuntimeException informativa_senza_versione
     */
    public static function versioneInformativa(?string $scenario = null): string
    {
        $file = self::informativaFile($scenario);
        if (!isset(self::$versioniInformativa[$file])) {
            $versione = self::versioneDelFrontmatter(is_file($file) ? (string)file_get_contents($file) : '');
            if ($versione === null) {
                throw new RuntimeException('informativa_senza_versione');
            }
            self::$versioniInformativa[$file] = $versione;
        }
        return self::$versioniInformativa[$file];
    }

    /** Il `versione:` del frontmatter YAML in testa a un markdown, o null. */
    public static function versioneDelFrontmatter(string $markdown): ?string
    {
        if (!preg_match('/\A---\r?\n(.*?)\r?\n---/s', $markdown, $fm)) {
            return null;
        }
        if (!preg_match('/^versione:[ \t]*["\']?([^"\'\r\n]+?)["\']?[ \t]*$/m', $fm[1], $m)) {
            return null;
        }
        $versione = trim($m[1]);
        return $versione === '' ? null : $versione;
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
                'label' => match ($s) {
                    self::INSTITUTE => 'Informativa privacy dell\'Istituto (art. 13 GDPR)',
                    self::PERSONAL  => 'Informativa privacy — uso personale (art. 13 GDPR)',
                    default         => 'Informativa privacy — docenti iscritti (art. 13 GDPR)',
                },
                'route' => '/privacy/informativa',
                'note'  => match ($s) {
                    self::INSTITUTE => 'Titolare: l\'Istituto; chi conduce l\'istanza e\' Responsabile ex art. 28',
                    self::PERSONAL  => 'Titolare: il gestore dell\'istanza; nessuna iscrizione, studenti solo con credenziale di classe',
                    default         => 'Titolare: il gestore dell\'istanza; docenti iscritti e studenti con credenziale di classe',
                },
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
            'more_teachers'       => self::consentePiuDocenti(),
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

        SostituzioneSuFile::scrivi(self::FILE, [
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
    }

    /** Rimuove l'override runtime dello scenario (si torna a env / modo legacy). */
    public static function clearRuntime(): bool
    {
        return SostituzioneSuFile::togli(self::FILE);
    }

    public static function resetCache(): void
    {
        SostituzioneSuFile::dimentica(self::FILE);
    }

    // ── Interni ──────────────────────────────────────────────────────────

    /**
     * L'override, o null se manca o è corrotto: uno scenario fuori
     * enumerazione conta come corrotto, e SostituzioneSuFile lo scrive nel
     * registro delle anomalie (prima: una riga in error_log).
     *
     * @return array<string,mixed>|null
     */
    private static function loadRuntime(): ?array
    {
        return SostituzioneSuFile::leggi(
            self::FILE,
            static fn(array $dati): bool => in_array($dati['scenario'] ?? null, self::ALL, true),
        );
    }
}
