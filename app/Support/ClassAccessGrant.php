<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use App\Core\Session;

/**
 * Credenziale di classe (ADR-032): il grant che entra in sessione quando uno
 * studente usa la credenziale creata dal docente, senza avere un account.
 *
 * PERCHE' ESISTE (2026-09-04)
 *   Il grant `fm_teacher_access` veniva scritto in sessione e poi non lo
 *   leggeva nessuno: gli endpoint di studio costruivano il viewer da
 *   Auth::user(), che per un ospite e' null, e ricadevano sul vincolo
 *   `__deny__`. Da qui in poi c'e' un solo posto che sa leggere il grant e
 *   dire cosa significa: un viewer senza identita', confinato ai contenuti
 *   PUBBLICATI del docente che ha creato la credenziale e, se la credenziale
 *   e' delimitata, alla sua classe.
 *
 * PORTACHIAVI (2026-09-05, piano classi-credenziali-scenari, C)
 *   La sessione tiene una LISTA di grant, uno per credenziale: lo studente
 *   inserisce una volta la credenziale di ogni docente e le vede insieme,
 *   ognuna nel proprio perimetro. `current()` resta il primo grant, per chi
 *   ha bisogno di uno solo (intestazione, istituto della sidebar). A ogni
 *   richiesta i grant si riverificano sul DB: una credenziale disattivata o
 *   scaduta cade, e lo studente ne riceve avviso invece di veder sparire i
 *   contenuti in silenzio. Con la sessione vuota e il cookie «ricorda», il
 *   portachiavi si ricostruisce dal DB.
 *
 * Nessun dato personale: il grant contiene l'id del docente, un'etichetta,
 * l'eventuale (istituto, indirizzo, classe) e l'id della credenziale. Non
 * identifica chi guarda.
 */
final class ClassAccessGrant
{
    /** Lista di grant (portachiavi). */
    public const SESSION_KEY = 'fm_class_keychain';
    /** Un grant solo: sessioni aperte prima del portachiavi, migrate al primo uso. */
    public const LEGACY_KEY = 'fm_teacher_access';
    /** Avvisi da mostrare una volta: credenziali cadute dal portachiavi. */
    public const NOTICES_KEY = 'fm_class_keychain_notices';

    /** @var list<array<string,mixed>>|null cache per richiesta (dopo la riverifica) */
    private static ?array $cache = null;

    // ── Lettura ────────────────────────────────────────────────────────

    /**
     * Tutti i grant validi, dal piu' vecchio; lista vuota per chi non ne ha.
     *
     * @return list<array{teacher_id:int,institute_id:?int,indirizzo:?string,classe:?string,label:string,materie_nomi:?string,source:string,credential_id:?int,granted_at:int}>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $list = self::readSession();
        if ($list === []) {
            $list = self::restoreFromCookie();
        }
        self::$cache = self::revalidate($list);
        return self::$cache;
    }

    /**
     * Il primo grant, normalizzato; null se il portachiavi e' vuoto.
     *
     * @return array{teacher_id:int,institute_id:?int,indirizzo:?string,classe:?string,label:string,materie_nomi:?string,source:string,credential_id:?int,granted_at:int}|null
     */
    public static function current(): ?array
    {
        return self::all()[0] ?? null;
    }

    /** Il visitatore corrente e' un ospite con almeno una credenziale di classe? */
    public static function isActive(): bool
    {
        return self::all() !== [];
    }

    /** Id del docente del primo grant, 0 se nessuno. */
    public static function teacherId(): int
    {
        return self::current()['teacher_id'] ?? 0;
    }

    /** @return list<int> docenti del portachiavi, senza ripetizioni */
    public static function teacherIds(): array
    {
        $ids = [];
        foreach (self::all() as $g) {
            $ids[$g['teacher_id']] = true;
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * Avvisi accumulati (credenziali cadute), consumati per default.
     *
     * @return list<array{label:string,reason:string}>
     */
    public static function notices(bool $consume = true): array
    {
        try {
            $raw = Session::get(self::NOTICES_KEY);
            $out = \is_array($raw) ? array_values($raw) : [];
            if ($consume && $out !== []) {
                Session::forget(self::NOTICES_KEY);
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Scrittura ──────────────────────────────────────────────────────

    /**
     * Aggiunge (o sostituisce) un grant. Sostituisce quello con la stessa
     * credenziale, o dello stesso docente se nessuna delle due ha una
     * credenziale (self-access): cosi' rientrare non duplica.
     *
     * @param array<string,mixed> $raw
     */
    public static function add(array $raw): void
    {
        $g = self::fromArray($raw);
        if ($g === null) {
            return;
        }
        $list = array_values(array_filter(self::readSession(), static function (array $x) use ($g): bool {
            if ($g['credential_id'] !== null || $x['credential_id'] !== null) {
                return $x['credential_id'] !== $g['credential_id'];
            }
            return $x['teacher_id'] !== $g['teacher_id'];
        }));
        $list[] = $g;
        self::persist($list);
    }

    /** Toglie il grant di una credenziale; ritorna il grant tolto, o null. */
    public static function remove(int $credentialId): ?array
    {
        $removed = null;
        $list = [];
        foreach (self::readSession() as $g) {
            if ($g['credential_id'] === $credentialId && $removed === null) {
                $removed = $g;
                continue;
            }
            $list[] = $g;
        }
        self::persist($list);
        return $removed;
    }

    public static function clear(): void
    {
        self::persist([]);
        try {
            Session::forget(self::LEGACY_KEY);
        } catch (\Throwable) {
            // sessione non disponibile: niente da pulire
        }
    }

    /** Per i test: dimentica la cache di richiesta. */
    public static function resetCache(): void
    {
        self::$cache = null;
    }

    // ── Normalizzazione (pura) ────────────────────────────────────────

    /**
     * Normalizza un grant grezzo (dalla sessione o da un test). Pura: nessun
     * accesso a sessione o DB.
     *
     * @param array<string,mixed> $raw
     * @return array{teacher_id:int,institute_id:?int,indirizzo:?string,classe:?string,label:string,materie_nomi:?string,source:string,credential_id:?int,granted_at:int}|null
     */
    public static function fromArray(array $raw): ?array
    {
        $teacherId = (int)($raw['teacher_id'] ?? 0);
        if ($teacherId <= 0) {
            return null;
        }
        $str = static function (mixed $v): ?string {
            if (!\is_string($v) && !\is_int($v)) {
                return null;
            }
            $s = trim((string)$v);
            return $s === '' ? null : $s;
        };
        $inst = (int)($raw['institute_id'] ?? 0);
        $cred = (int)($raw['credential_id'] ?? 0);
        return [
            'teacher_id'    => $teacherId,
            'institute_id'  => $inst > 0 ? $inst : null,
            'indirizzo'     => $str($raw['indirizzo'] ?? null),
            'classe'        => $str($raw['classe'] ?? null),
            'label'         => (string)($str($raw['label'] ?? null) ?? ''),
            // ADR-044 — i nomi delle materie dell'etichetta («Fisica, Matematica»),
            // dal catalogo della scuola: il title nel portachiavi, perché sigle
            // come DPA o STG non dicono niente a uno studente.
            'materie_nomi'  => $str($raw['materie_nomi'] ?? null),
            'source'        => (string)($str($raw['source'] ?? null) ?? 'teacher_access_credentials'),
            'credential_id' => $cred > 0 ? $cred : null,
            'granted_at'    => (int)($raw['granted_at'] ?? 0),
        ];
    }

    // ── Derivati per i consumatori ─────────────────────────────────────

    /**
     * Contesto per MapPermissionService::canView(): il primo grant delimitato
     * a una classe di un istituto noto, altrimenti null.
     *
     * @return array{institute_id:int,indirizzo:string,classe:string}|null
     */
    public static function mapContext(): ?array
    {
        foreach (self::all() as $g) {
            if ($g['institute_id'] !== null && $g['indirizzo'] !== null && $g['classe'] !== null) {
                return [
                    'institute_id' => $g['institute_id'],
                    'indirizzo'    => $g['indirizzo'],
                    'classe'       => $g['classe'],
                ];
            }
        }
        return null;
    }

    /**
     * Istituto di riferimento del portachiavi: quello della prima credenziale
     * che lo dichiara, oppure il primo istituto del primo docente. Serve a
     * risolvere le sezioni della sidebar (quali sono nascoste agli studenti)
     * senza un account.
     */
    public static function instituteId(): int
    {
        $all = self::all();
        if ($all === []) {
            return 0;
        }
        foreach ($all as $g) {
            if ($g['institute_id'] !== null) {
                return $g['institute_id'];
            }
        }
        try {
            return TeacherContextResolver::privateFilesInstituteId($all[0]['teacher_id']);
        } catch (\Throwable) {
            return 0;
        }
    }

    // ── Sessione, cookie, riverifica ───────────────────────────────────

    /** @return list<array<string,mixed>> */
    private static function readSession(): array
    {
        try {
            $raw = Session::get(self::SESSION_KEY);
            $list = [];
            if (\is_array($raw)) {
                foreach ($raw as $item) {
                    $g = \is_array($item) ? self::fromArray($item) : null;
                    if ($g !== null) {
                        $list[] = $g;
                    }
                }
            }
            // Sessioni aperte prima del portachiavi: un grant solo, migrato qui.
            $legacy = Session::get(self::LEGACY_KEY);
            if ($list === [] && \is_array($legacy)) {
                $g = self::fromArray($legacy);
                if ($g !== null) {
                    $list[] = $g;
                    self::persist($list);
                }
                Session::forget(self::LEGACY_KEY);
            }
            return $list;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<array<string,mixed>> $list */
    private static function persist(array $list): void
    {
        self::$cache = null;
        try {
            Session::put(self::SESSION_KEY, $list === [] ? null : array_values($list));
        } catch (\Throwable) {
            // sessione non disponibile (CLI, test senza sessione)
        }
    }

    /**
     * I nomi delle materie di una credenziale, dal catalogo della sua scuola,
     * nell'ordine delle sigle dell'etichetta. Frammento SQL su `t`
     * (teacher_access_credentials_data); null senza materie.
     */
    private const SQL_MATERIE_NOMI = "(SELECT GROUP_CONCAT(ce.label ORDER BY ce.code SEPARATOR ', ')
                FROM curriculum_entries ce
               WHERE ce.kind = 'materie' AND ce.institute_id = t.institute_id
                 AND t.materie IS NOT NULL AND t.materie <> ''
                 AND FIND_IN_SET(ce.code, t.materie) > 0)";

    /**
     * Riverifica sul DB i grant che vengono da una credenziale: disattivata
     * o scaduta, il grant cade e resta un avviso. Senza DB si tiene cio' che
     * c'e': meglio uno studente che legge un'ora in piu' che una pagina vuota
     * per un guasto.
     *
     * ADR-044 — rilegge anche l'etichetta e i nomi delle materie: un'etichetta
     * ricomposta dal docente arriva agli studenti già entrati alla richiesta
     * successiva. Prima si rileggevano solo id, active ed expires_at, e
     * l'etichetta restava quella dell'ingresso finché la sessione durava.
     *
     * @param list<array<string,mixed>> $list
     * @return list<array<string,mixed>>
     */
    private static function revalidate(array $list): array
    {
        $ids = [];
        foreach ($list as $g) {
            if ($g['credential_id'] !== null) {
                $ids[] = $g['credential_id'];
            }
        }
        if ($ids === [] || !Database::isAvailable()) {
            return $list;
        }
        try {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $stmt = Database::connection()->prepare(
                'SELECT t.id, t.active, t.expires_at, t.label, ' . self::SQL_MATERIE_NOMI . " AS materie_nomi
                   FROM teacher_access_credentials_data t WHERE t.id IN ($place)"
            );
            $stmt->execute($ids);
            $rows = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['id']] = $r;
            }
        } catch (\Throwable) {
            return $list;
        }
        $today = date('Y-m-d');
        $kept = [];
        $notices = [];
        $changed = false;
        foreach ($list as $g) {
            $cid = $g['credential_id'];
            if ($cid === null) {
                $kept[] = $g;
                continue;
            }
            $row = $rows[$cid] ?? null;
            $reason = null;
            if ($row === null) {
                $reason = 'eliminata';
            } elseif ((int)$row['active'] !== 1) {
                $reason = 'disattivata';
            } elseif (!empty($row['expires_at']) && (string)$row['expires_at'] < $today) {
                $reason = 'scaduta';
            }
            if ($reason === null) {
                $fresh = self::fromArray(['label' => $row['label'] ?? null, 'materie_nomi' => $row['materie_nomi'] ?? null] + $g);
                if ($fresh !== null && ($fresh['label'] !== $g['label'] || $fresh['materie_nomi'] !== ($g['materie_nomi'] ?? null))) {
                    $g = $fresh;
                    $changed = true;
                }
                $kept[] = $g;
            } else {
                $notices[] = ['label' => $g['label'], 'reason' => $reason];
            }
        }
        if ($changed && $notices === []) {
            self::persist($kept);
        }
        if ($notices !== []) {
            self::persist($kept);
            try {
                $prev = Session::get(self::NOTICES_KEY);
                Session::put(self::NOTICES_KEY, array_merge(\is_array($prev) ? $prev : [], $notices));
            } catch (\Throwable) {
                // senza sessione l'avviso non ha dove stare
            }
            if ($kept === []) {
                ClassKeychainCookie::clear();
            }
        }
        return $kept;
    }

    /**
     * Con la sessione vuota e il cookie «ricorda», ricostruisce il portachiavi
     * dal DB: solo credenziali attive e non scadute, con i loro dati attuali.
     *
     * @return list<array<string,mixed>>
     */
    private static function restoreFromCookie(): array
    {
        $ids = ClassKeychainCookie::read();
        if ($ids === [] || !Database::isAvailable()) {
            return [];
        }
        try {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $stmt = Database::connection()->prepare(
                'SELECT t.id, t.teacher_id, t.label, t.indirizzo, t.classe, t.institute_id, '
                . self::SQL_MATERIE_NOMI . " AS materie_nomi
                   FROM teacher_access_credentials t
                  WHERE t.id IN ($place) AND t.active = 1
                    AND (t.expires_at IS NULL OR t.expires_at >= CURDATE())"
            );
            $stmt->execute($ids);
            $list = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $g = self::fromArray([
                    'teacher_id'    => $r['teacher_id'],
                    'label'         => $r['label'],
                    'materie_nomi'  => $r['materie_nomi'],
                    'indirizzo'     => $r['indirizzo'],
                    'classe'        => $r['classe'],
                    'institute_id'  => $r['institute_id'],
                    'credential_id' => $r['id'],
                    'source'        => 'remember_cookie',
                    'granted_at'    => time(),
                ]);
                if ($g !== null) {
                    $list[] = $g;
                }
            }
        } catch (\Throwable) {
            return [];
        }
        if ($list === []) {
            ClassKeychainCookie::clear();
            return [];
        }
        self::persist($list);
        return $list;
    }
}
