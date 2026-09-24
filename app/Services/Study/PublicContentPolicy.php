<?php

declare(strict_types=1);

namespace App\Services\Study;

use App\Core\Database;
use App\Repositories\SidebarSectionRepository;
use App\Support\ClsNormalizer;

/**
 * Regola di visibilita' PUBBLICA (guest, nessuna sessione) dei contenuti.
 *
 * WS4: un contenuto e' pubblico sse appartiene al docente che pubblica in rete
 * (proprietarioPubblico: quello scelto dall'amministratore; senza scelta,
 * nessuno), e' `published` e la sua SEZIONE e' `publish_public`.
 * La regola era scritta due
 * volte (ContentStudyController per il JSON del singolo contenuto,
 * PublicStudyController per le rotte pubbliche): dal 2026-09-04 (revisione
 * architetturale, intervento P5) vive qui, e chi la cambia la cambia per
 * tutti. Metodi statici, nessuno stato: sicuri anche a DB assente (0 /
 * false / deny).
 */
final class PublicContentPolicy
{
    /**
     * Type-segment URL delle sezioni-documento collassate (migr 078): 'risdoc'
     * e 'bes' non sono content_type ma SEZIONI il cui content_type e' 'document'.
     */
    public const SECTION_DOC_TYPES = ['risdoc', 'bes'];

    /** Filtro sentinella: nessuna riga soddisfa indirizzo '__deny__'. */
    private const DENY_INDIRIZZO = '__deny__';

    /**
     * Il docente i cui contenuti pubblicati sono visibili senza login. 0 se nessuno.
     *
     * È quello scelto in /admin/sidebar-config (`users.pubblica_in_rete`,
     * migrazione 129), se è un docente attivo. Senza scelta non c'è niente in
     * rete: la barra dei visitatori mostra solo l'accesso (sezioniPubbliche).
     * Fino al 15/9/2026 era per regola il super-amministratore docente; dal 4/9
     * quello di produzione non è docente, e la home pubblica mostrava sezioni
     * vuote. La migrazione 129 sceglie il super-amministratore docente dove
     * c'era, così nessuna installazione cambia da sola.
     */
    public static function proprietarioPubblico(): int
    {
        return self::sceltoPerLaRete() ?? 0;
    }

    /**
     * Le sezioni che un visitatore senza login vede nella barra: quelle marcate
     * `publish_public`, ma solo se c'è un docente che pubblica in rete. Senza,
     * nessuna: una sezione pubblica vuota, con i selettori che non trovano niente,
     * non serve a nessuno.
     *
     * @return list<array<string, mixed>>
     */
    public static function sezioniPubbliche(): array
    {
        if (self::proprietarioPubblico() <= 0) {
            return [];
        }
        return (new SidebarSectionRepository())->publicSections();
    }

    /**
     * Il docente scelto per la rete, se c'è ed è un docente attivo; null altrimenti
     * (anche a colonna assente, prima della migrazione 129).
     */
    public static function sceltoPerLaRete(): ?int
    {
        if (!Database::isAvailable()) {
            return null;
        }
        try {
            $id = Database::connection()->query(
                "SELECT id FROM users WHERE pubblica_in_rete_unica = 1 AND role = 'teacher' AND active = 1 LIMIT 1"
            )->fetchColumn();
            return $id !== false ? (int)$id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Sceglie il docente che pubblica in rete; null toglie la scelta, e senza
     * login resta solo l'accesso. Un id che non è un docente attivo non si
     * accetta, e la scelta di prima resta.
     *
     * @throws \InvalidArgumentException docente_non_valido
     */
    public static function scegliPerLaRete(?int $docente): void
    {
        $pdo = Database::connection();
        // Dentro una transazione di chi chiama (le prove) si usa quella.
        $mia = !$pdo->inTransaction();
        if ($mia) {
            $pdo->beginTransaction();
        }
        try {
            // Prima si controlla, poi si cambia: un docente non valido lascia la
            // scelta com'era, anche dentro la transazione di chi chiama.
            if ($docente !== null) {
                $st = $pdo->prepare(
                    "SELECT id FROM users WHERE id = ? AND role = 'teacher' AND active = 1 FOR UPDATE"
                );
                $st->execute([$docente]);
                if ($st->fetchColumn() === false) {
                    throw new \InvalidArgumentException('docente_non_valido');
                }
            }
            $pdo->exec('UPDATE users SET pubblica_in_rete = 0 WHERE pubblica_in_rete = 1');
            if ($docente !== null) {
                $pdo->prepare('UPDATE users SET pubblica_in_rete = 1 WHERE id = ?')->execute([$docente]);
            }
            if ($mia) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($mia) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Un contenuto e' pubblicamente visibile sse: appartiene al docente che
     * pubblica in rete, e' published, e la sua SEZIONE e' publish_public (gate per
     * section_id, non per content_type → bes pubblica non espone risdoc). Per
     * i contenuti legacy senza section_id si ammette solo un tipo UNIVOCO
     * (mappa/esercizio/verifica) di una sezione pubblica, mai 'document'.
     *
     * @param array<string, mixed> $row
     */
    public static function isPublic(array $row): bool
    {
        $saId = self::proprietarioPubblico();
        if (
            $saId <= 0
            || (int)($row['teacher_id'] ?? 0) !== $saId
            || (string)($row['visibility'] ?? '') !== 'published'
        ) {
            return false;
        }
        $repo = new SidebarSectionRepository();
        $secId = (int)($row['section_id'] ?? 0);
        if ($secId > 0) {
            return in_array($secId, $repo->publicSectionIds(), true);
        }
        $ct = (string)($row['content_type'] ?? '');
        if ($ct === '' || $ct === 'document') {
            return false;
        }
        foreach ($repo->publicSections() as $ps) {
            if ((string)($ps['default_content_type'] ?? '') === $ct) {
                return true;
            }
        }
        return false;
    }

    /**
     * Filtri di ricerca per gli endpoint pubblici. NON usa Auth/viewerContext:
     * serve solo contenuti `published` del docente che pubblica in rete nelle sezioni
     * publish_public, filtrati per i selettori (ind, cls, subj, topic). Tipo
     * non pubblico → sentinella deny (lista vuota).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function scopedFilters(array $params, string $type): array
    {
        $deny = ['content_type' => $type, 'indirizzo' => self::DENY_INDIRIZZO];
        $repo = new SidebarSectionRepository();
        $saId = self::proprietarioPubblico();
        if ($saId <= 0) {
            return $deny;
        }
        $base = [
            'subject_code' => $params['subj'] ?? null,
            'indirizzo'    => $params['ind'] ?? null,
            'classe'       => isset($params['cls']) ? ClsNormalizer::shrink((string)$params['cls']) : null,
            'topic'        => $params['topic'] ?? null,
            'visibility'   => 'published',
            'teacher_id'   => $saId,
        ];

        // Sezioni-documento (bes/risdoc): SOLO via la specifica sezione pubblica
        // (section_id). Se la sezione richiesta NON è publish_public → deny: così
        // bes pubblica NON espone i documenti risdoc (riservati) e viceversa.
        if (in_array($type, self::SECTION_DOC_TYPES, true)) {
            $pub = $repo->publicSectionByKey($type);
            if (!$pub) {
                return $deny;
            }
            $base['section_id'] = (int)$pub['id'];
            return array_filter($base, static fn($v) => $v !== null);
        }

        // 'document' senza una sezione pubblica esplicita → MAI per tipo (ambiguo
        // tra bes/risdoc) → deny.
        if ($type === 'document') {
            return $deny;
        }

        // Tipi UNIVOCI (mappa/esercizio/verifica): per content_type, e solo nelle
        // sezioni pubbliche che hanno quel tipo come predefinito (più i contenuti
        // legacy senza sezione, che isPublic ammette allo stesso modo).
        //
        // 16/9/2026 — prima il filtro era il solo tipo: una mappa creata nel
        // Laboratorio (sezione non pubblica) usciva nella sidepage Mappe della
        // home senza login, e la sua vista pubblica rispondeva 404.
        $sezioni = $repo->publicSectionIdsForType($type);
        if ($sezioni === []) {
            return $deny;
        }
        $base['content_type'] = $type;
        $base['section_id_in_or_null'] = $sezioni;
        return array_filter($base, static fn($v) => $v !== null);
    }

    /**
     * Filtri per una SEZIONE pubblica: tutti i tipi pubblicati ancorati alla
     * sezione (ADR-027: ogni sidepage crea qualsiasi tipo, e la sidepage carica
     * per sezione). Una sezione che non è publish_public → sentinella deny.
     * Stessa regola di isPublic, che per un contenuto con sezione guarda solo
     * quella.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function scopedFiltersPerSezione(array $params, string $sectionKey): array
    {
        $deny = ['indirizzo' => self::DENY_INDIRIZZO];
        $saId = self::proprietarioPubblico();
        if ($saId <= 0 || $sectionKey === '') {
            return $deny;
        }
        $sezione = (new SidebarSectionRepository())->publicSectionByKey($sectionKey);
        if (!$sezione) {
            return $deny;
        }
        return array_filter([
            'subject_code' => $params['subj'] ?? null,
            'indirizzo'    => $params['ind'] ?? null,
            'classe'       => isset($params['cls']) ? ClsNormalizer::shrink((string)$params['cls']) : null,
            'topic'        => $params['topic'] ?? null,
            'visibility'   => 'published',
            'teacher_id'   => $saId,
            'section_id'   => (int)$sezione['id'],
        ], static fn($v) => $v !== null);
    }

    /** True se i filtri sono la sentinella deny (nessun contenuto pubblico per quel tipo). */
    public static function isDeny(array $filters): bool
    {
        return ($filters['indirizzo'] ?? null) === self::DENY_INDIRIZZO;
    }
}
