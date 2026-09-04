<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Session;

/**
 * Credenziale di classe (ADR-032): il grant che TeacherCredentialController
 * mette in sessione quando uno studente entra con la credenziale creata dal
 * docente, senza avere un account.
 *
 * PERCHE' ESISTE (2026-09-04)
 *   Il grant `fm_teacher_access` veniva scritto in sessione e poi non lo
 *   leggeva nessuno: gli endpoint di studio costruivano il viewer da
 *   Auth::user(), che per un ospite e' null, e ricadevano sul vincolo
 *   `__deny__`. La "modalita' Anonima" descritta a DPO e informativa —
 *   accesso con la credenziale del docente — non mostrava nulla. Da qui in
 *   poi c'e' un solo posto che sa leggere il grant e dire cosa significa:
 *   un viewer senza identita', confinato ai contenuti PUBBLICATI del docente
 *   che ha creato la credenziale e, se la credenziale e' delimitata, alla
 *   sua classe.
 *
 * Nessun dato personale: il grant contiene l'id del docente, un'etichetta e
 * l'eventuale (istituto, indirizzo, classe). Non identifica chi guarda.
 */
final class ClassAccessGrant
{
    public const SESSION_KEY = 'fm_teacher_access';

    /**
     * Grant corrente, normalizzato; null se assente o non valido.
     *
     * @return array{teacher_id:int,institute_id:?int,indirizzo:?string,classe:?string,label:string,source:string}|null
     */
    public static function current(): ?array
    {
        try {
            $raw = Session::get(self::SESSION_KEY);
        } catch (\Throwable) {
            return null;
        }
        return \is_array($raw) ? self::fromArray($raw) : null;
    }

    /** Il visitatore corrente e' un ospite con credenziale di classe? */
    public static function isActive(): bool
    {
        return self::current() !== null;
    }

    /** Id del docente della credenziale, 0 se nessun grant. */
    public static function teacherId(): int
    {
        return self::current()['teacher_id'] ?? 0;
    }

    /**
     * Normalizza un grant grezzo (dalla sessione o da un test). Pura: nessun
     * accesso a sessione o DB.
     *
     * @param array<string,mixed> $raw
     * @return array{teacher_id:int,institute_id:?int,indirizzo:?string,classe:?string,label:string,source:string}|null
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
        return [
            'teacher_id'   => $teacherId,
            'institute_id' => $inst > 0 ? $inst : null,
            'indirizzo'    => $str($raw['indirizzo'] ?? null),
            'classe'       => $str($raw['classe'] ?? null),
            'label'        => (string)($str($raw['label'] ?? null) ?? ''),
            'source'       => (string)($str($raw['source'] ?? null) ?? 'teacher_access_credentials'),
        ];
    }

    /**
     * Contesto per MapPermissionService::canView(): solo se la credenziale
     * e' delimitata a una classe di un istituto noto, altrimenti null.
     *
     * @return array{institute_id:int,indirizzo:string,classe:string}|null
     */
    public static function mapContext(): ?array
    {
        $g = self::current();
        if ($g === null || $g['institute_id'] === null || $g['indirizzo'] === null || $g['classe'] === null) {
            return null;
        }
        return [
            'institute_id' => $g['institute_id'],
            'indirizzo'    => $g['indirizzo'],
            'classe'       => $g['classe'],
        ];
    }

    /**
     * Istituto di riferimento del grant: quello della credenziale, oppure il
     * primo del docente. Serve a risolvere le sezioni della sidebar (quali
     * sono nascoste agli studenti) senza un account.
     */
    public static function instituteId(): int
    {
        $g = self::current();
        if ($g === null) {
            return 0;
        }
        if ($g['institute_id'] !== null) {
            return $g['institute_id'];
        }
        try {
            return TeacherContextResolver::firstInstituteId($g['teacher_id']);
        } catch (\Throwable) {
            return 0;
        }
    }
}
