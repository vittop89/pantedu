<?php

declare(strict_types=1);

namespace App\Services\Maps;

use App\Core\Database;
use App\Domain\ContentVisibility;
use App\Repositories\MapShareRepository;
use PDO;

/**
 * Phase G2 — Decisore di accesso per mappe (content_type='mappa').
 *
 * Risponde alle 3 domande:
 *   - canView($mapId, $userId)?  Ha diritto a leggere il blob in chiaro?
 *   - canCopy($mapId, $userId)?  Puo' aprirla in modalita' "modifica copia"
 *                                (genera nuova row con parent_map_id)?
 *   - canEdit($mapId, $userId)?  Puo' modificare l'originale?
 *
 * Regole (in ordine di priorita'):
 *   1. canEdit = solo owner.
 *   2. canView/canCopy = OR fra:
 *      a. owner sempre yes.
 *      b. super_admin con audit_reason (delegato al caller, vedi ADR-008).
 *      c. map_is_public = 1 → canView yes (canCopy delegato a map_shares).
 *      d. map_shares row matchante per uno scope tuple del user.
 *      e. visibility='published' + pubblicazione per la classe del viewer (ADR-037).
 *
 * Side-effect: nessuno qui (lookup-only). Logging audit demandato al caller
 * per evitare doppio log su check ripetuti.
 */
final class MapPermissionService
{
    private MapShareRepository $shares;

    public function __construct(?MapShareRepository $shares = null)
    {
        $this->shares = $shares ?? new MapShareRepository();
    }

    public function canEdit(int $mapId, int $userId): bool
    {
        $row = $this->loadMap($mapId);
        return $row !== null && (int)$row['teacher_id'] === $userId;
    }

    /**
     * @param ?ViewerContext $context  Opzionale: contesto della sessione
     *   (institute_id corrente, indirizzo+classe per studenti loggati via
     *   teacher_access_credentials). Se null → solo grant teacher/institute
     *   diretti vengono valutati (no class-scope).
     *
     * @phpstan-type ViewerContext array{
     *   institute_id?: int,
     *   indirizzo?: string,
     *   classe?: string
     * }
     */
    public function canView(int $mapId, int $userId, ?array $context = null): bool
    {
        $row = $this->loadMap($mapId);
        if ($row === null) {
            return false;
        }
        if ((int)$row['teacher_id'] === $userId) {
            return true;
        }
        if ((int)$row['map_is_public'] === 1) {
            return true;
        }
        $perm = $this->bestSharePermission($mapId, $userId, $context);
        if ($perm !== null) {
            return true;
        }
        return $this->matchesPublishedClass($row, $context);
    }

    public function canCopy(int $mapId, int $userId, ?array $context = null): bool
    {
        $row = $this->loadMap($mapId);
        if ($row === null) {
            return false;
        }
        if ((int)$row['teacher_id'] === $userId) {
            return true;
        }
        $perm = $this->bestSharePermission($mapId, $userId, $context);
        return $perm === 'copy';
    }

    /**
     * Ritorna ('owner' | 'copy' | 'view' | 'public' | 'class' | null) per
     * audit + UI badge ("↳ copia" vs "🔗 condivisa con te" vs "🌐 pubblica").
     */
    public function describeAccess(int $mapId, int $userId, ?array $context = null): ?string
    {
        $row = $this->loadMap($mapId);
        if ($row === null) {
            return null;
        }
        if ((int)$row['teacher_id'] === $userId) {
            return 'owner';
        }
        $perm = $this->bestSharePermission($mapId, $userId, $context);
        if ($perm !== null) {
            return $perm; // 'copy' o 'view'
        }
        if ((int)$row['map_is_public'] === 1) {
            return 'public';
        }
        if ($this->matchesPublishedClass($row, $context)) {
            return 'class';
        }
        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadMap(int $mapId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, teacher_id, content_type, indirizzo, classe, subject_code,
                    visibility, map_is_public, map_blob_path
             FROM teacher_content
             WHERE id = ? AND content_type = "mappa"
             LIMIT 1'
        );
        $stmt->execute([$mapId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Costruisce gli scope tuples per il user (+ context) e cerca la
     * migliore permission matching su map_shares.
     *
     * @param array<string,mixed>|null $context
     */
    private function bestSharePermission(int $mapId, int $userId, ?array $context = null): ?string
    {
        $tuples = $this->buildScopeTuplesFor($userId, $context);
        return $this->shares->bestPermissionFor($mapId, $tuples);
    }

    /**
     * Costruisce gli scope tuples per il user. Sempre include teacher/student
     * (id stesso) + institute (via teacher_institutes per docenti, via
     * users.institute_id per studenti). Class tuple SOLO se passato in
     * $context (non risolvibile da DB perche' la classe del studente non
     * e' un attributo persistente di users — deriva dal login via
     * teacher_access_credentials).
     *
     * @param array<string,mixed>|null $context
     * @return list<array{0:string,1:string}>
     */
    private function buildScopeTuplesFor(int $userId, ?array $context = null): array
    {
        $tuples = [['teacher', (string)$userId], ['student', (string)$userId]];

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT DISTINCT inst FROM (
               SELECT institute_id AS inst FROM teacher_institutes WHERE user_id = ?
               UNION
               SELECT institute_id AS inst FROM users WHERE id = ? AND institute_id IS NOT NULL
             ) t"
        );
        $stmt->execute([$userId, $userId]);
        $instIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'inst');
        foreach ($instIds as $instId) {
            $tuples[] = ['institute', (string)$instId];
        }

        // Class scope solo se il caller fornisce context (sessione studente
        // post-login o teacher in browsing context di una specifica classe).
        if (
            $context !== null
            && isset($context['institute_id'], $context['indirizzo'], $context['classe'])
        ) {
            $tuples[] = ['class', $context['institute_id'] . '|' . $context['indirizzo'] . '|' . $context['classe']];
        }

        return $tuples;
    }

    /**
     * Controlla se il context del viewer (institute+indirizzo+classe della
     * sessione) matcha una pubblicazione della mappa (ADR-037, fase 1):
     * visibility='published' = accesso per la classe. Senza context (es.
     * teacher logged but no class context) → no match.
     *
     * @param array<string,mixed>             $row
     * @param array<string,mixed>|null        $context
     */
    private function matchesPublishedClass(array $row, ?array $context): bool
    {
        // Composizione behavior-preserving: il letterale 'published' delega
        // al value object ContentVisibility (ADR pilota policy unica). Parsing
        // null-safe → qualsiasi visibility != PUBLISHED non matcha (identico
        // all'effetto del confronto stringa precedente).
        if (ContentVisibility::tryFromString($row['visibility'] ?? null) !== ContentVisibility::PUBLISHED) {
            return false;
        }
        if ($context === null || empty($context['indirizzo']) || empty($context['classe'])) {
            return false;
        }
        // ADR-037, fase 1 (2026-09-13) — una pubblicazione della mappa,
        // pubblicata, per quella coppia, nella scuola del contesto (se il
        // contesto la porta). Prima si confrontavano le sigle della riga, e una
        // mappa di un'altra scuola con le stesse sigle passava.
        $scuola = (int)($context['institute_id'] ?? 0);
        foreach (\App\Support\Pubblicazioni::delContenuto((int)($row['id'] ?? 0)) as $p) {
            if ($p['visibility'] !== ContentVisibility::PUBLISHED->value) {
                continue;
            }
            if ($scuola > 0 && $p['institute_id'] !== $scuola) {
                continue;
            }
            if ($p['indirizzo'] === $context['indirizzo'] && $p['classe'] === $context['classe']) {
                return true;
            }
        }
        return false;
    }
}
