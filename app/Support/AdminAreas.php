<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Aree del pannello di amministrazione che dipendono dallo scenario di
 * esercizio (ADR-032; piano docs/plans/classi-credenziali-scenari.md, B).
 *
 * Un solo posto decide: per ogni area, in quali scenari e' attiva, perche'
 * negli altri e' inerte e con che cosa si attiva. Menu, dashboard, pagine e
 * pannello Deployment leggono da qui, cosi' nessuno dei quattro puo' dire una
 * cosa diversa dagli altri.
 *
 * «Attiva» = nel menu e nella dashboard. «Inerte» = fuori dal menu, ma la
 * pagina resta raggiungibile per URL con un avviso in testa: un menu che
 * mostra cio' che non ha effetto insegna cose false (chi vede «Sezioni» in
 * scenario 1 pensa che le sezioni contino), mentre un segnalibro che smette
 * di funzionare fa perdere tempo. Le aree che non compaiono qui sono attive
 * in ogni scenario.
 */
final class AdminAreas
{
    public const ACTIVE = 'active';
    public const INERT  = 'inert';

    /**
     * @var array<string, array{
     *   label: string,
     *   href: string,
     *   active_in: list<string>,
     *   highlight_in: list<string>,
     *   why_inert: string,
     *   activates_with: string
     * }>
     */
    private const AREAS = [
        'registrations' => [
            'label'          => 'Registrazioni docenti',
            'href'           => '/admin#registrations',
            'active_in'      => [DeploymentScenario::COLLEAGUES, DeploymentScenario::INSTITUTE],
            'highlight_in'   => [DeploymentScenario::COLLEAGUES],
            'why_inert'      => 'Le iscrizioni sono chiuse: nessuna richiesta puo\' arrivare.',
            'activates_with' => 'lo scenario 2 (colleghi) o 3 (Istituto)',
        ],
        // ADR-041 (14/9/2026) — gli incarichi decidono anche quali sezioni un
        // docente può usare, quando l'istituto è «solo incaricati»: la pagina
        // conta in ogni scenario. Prima compariva solo nello scenario 3, e
        // negli altri non la si trovava dalla barra.
        'sections' => [
            'label'          => 'Sezioni e incarichi',
            'href'           => '/admin/sections',
            'active_in'      => [DeploymentScenario::PERSONAL, DeploymentScenario::COLLEAGUES, DeploymentScenario::INSTITUTE],
            'highlight_in'   => [],
            'why_inert'      => '',
            'activates_with' => '',
        ],
        'student_registration' => [
            'label'          => 'Registrazione studenti: modalita\' e classi ammesse',
            'href'           => '/admin/system/deployment#registrazione-studenti',
            'active_in'      => [DeploymentScenario::INSTITUTE],
            'highlight_in'   => [],
            'why_inert'      => 'Nessun account studente in questo scenario: '
                . 'le modalita\' di raccolta e le classi ammesse non hanno effetto.',
            'activates_with' => 'lo scenario 3 (Istituto)',
        ],
        'class_credentials' => [
            'label'          => 'Credenziali di classe',
            'href'           => '/admin/dashboard#credenziali-di-classe',
            'active_in'      => [DeploymentScenario::PERSONAL, DeploymentScenario::COLLEAGUES],
            'highlight_in'   => [],
            'why_inert'      => 'Nello scenario 3 gli studenti hanno un account: '
                . 'la porta non e\' la credenziale del docente.',
            'activates_with' => 'lo scenario 1 (personale) o 2 (colleghi)',
        ],
        'students_without_section' => [
            'label'          => 'Studenti senza sezione',
            'href'           => '/admin/sections',
            'active_in'      => [DeploymentScenario::INSTITUTE],
            'highlight_in'   => [],
            'why_inert'      => 'Senza account studente non c\'e\' nessuno da collocare in una sezione.',
            'activates_with' => 'lo scenario 3 (Istituto)',
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::AREAS);
    }

    public static function stateOf(string $key, ?string $scenario = null): string
    {
        $area = self::AREAS[$key] ?? null;
        if ($area === null) {
            return self::ACTIVE;
        }
        return in_array($scenario ?? DeploymentScenario::current(), $area['active_in'], true)
            ? self::ACTIVE
            : self::INERT;
    }

    public static function isActive(string $key, ?string $scenario = null): bool
    {
        return self::stateOf($key, $scenario) === self::ACTIVE;
    }

    /** L'area va messa in evidenza (per esempio le approvazioni nello scenario 2)? */
    public static function isHighlighted(string $key, ?string $scenario = null): bool
    {
        $area = self::AREAS[$key] ?? null;
        return $area !== null
            && in_array($scenario ?? DeploymentScenario::current(), $area['highlight_in'], true);
    }

    /**
     * Avviso da mostrare in testa a una pagina o a un blocco inerte; null se
     * l'area e' attiva o sconosciuta.
     *
     * @return array{key:string,label:string,why:string,activates_with:string,scenario:string,number:int,scenario_label:string}|null
     */
    public static function notice(string $key, ?string $scenario = null): ?array
    {
        $area = self::AREAS[$key] ?? null;
        $scenario ??= DeploymentScenario::current();
        if ($area === null || self::isActive($key, $scenario)) {
            return null;
        }
        return [
            'key'            => $key,
            'label'          => $area['label'],
            'why'            => $area['why_inert'],
            'activates_with' => $area['activates_with'],
            'scenario'       => $scenario,
            'number'         => DeploymentScenario::number($scenario),
            'scenario_label' => DeploymentScenario::label($scenario),
        ];
    }

    /**
     * Le aree inerti nello scenario, per il pannello Deployment: cosi' niente
     * e' davvero nascosto, e chi cerca «Sezioni» sa dove sono e perche' tacciono.
     *
     * @return list<array{key:string,label:string,href:string,why:string,activates_with:string}>
     */
    public static function inert(?string $scenario = null): array
    {
        $scenario ??= DeploymentScenario::current();
        $out = [];
        foreach (self::AREAS as $key => $area) {
            if (!in_array($scenario, $area['active_in'], true)) {
                $out[] = [
                    'key'            => $key,
                    'label'          => $area['label'],
                    'href'           => $area['href'],
                    'why'            => $area['why_inert'],
                    'activates_with' => $area['activates_with'],
                ];
            }
        }
        return $out;
    }

    /**
     * Matrice area × scenario, per test e documentazione.
     *
     * @return array<string, array<string, string>> chiave → [scenario → stato]
     */
    public static function matrix(): array
    {
        $out = [];
        foreach (self::AREAS as $key => $area) {
            foreach (DeploymentScenario::ALL as $s) {
                $out[$key][$s] = in_array($s, $area['active_in'], true) ? self::ACTIVE : self::INERT;
            }
        }
        return $out;
    }
}
