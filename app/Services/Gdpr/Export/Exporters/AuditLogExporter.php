<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use PDO;

/**
 * Phase 25.R.23 — Exporter audit log (Art. 6(1)(c) GDPR).
 *
 * Disponibile SOLO per export authority/admin: include log accessi
 * privilegiati + crypto access log per investigation forensics.
 * Skip in self-service (l'utente non ha bisogno del proprio log di accesso).
 *
 * ── Il difetto, corretto il 23/9/2026 (revisione A-62, R-6 passo 1) ───────
 *
 * Le due SELECT sui registri di accesso chiedevano colonne che nessuna
 * migrazione ha mai creato (`accessed_at`/`actor_user_id` su
 * `privileged_access_log`, `occurred_at`/`kv`/`fp_hash` su
 * `crypto_access_log`), e l'errore finiva in un catch vuoto. Il bundle
 * firmato per l'autorità dichiarava così due registri con zero righe: una
 * dichiarazione falsa nel documento che deve provare l'accountability.
 * Anche il filtro `resource_type = "user" AND resource_id = ?` non trovava
 * niente: nessuno scrittore registra così (nel database locale, il 23/9,
 * zero righe su 8.129).
 *
 * Ora le colonne sono quelle di `database/schema.sql` e delle migrazioni 012
 * e 100 (le stesse di AdminLogsController), e il soggetto si cerca come lo
 * scrivono davvero gli scrittori:
 *
 *   - privileged_access_log: `user_id` è CHI ha agito (PrivilegedAccessLogger
 *     lo ricava dallo username in sessione); la risorsa sta in `resource_id`,
 *     che per i middleware `sadmin_audit` e `audit_reason` è il percorso
 *     della richiesta, senza query string. Il soggetto è quindi «attore» se
 *     `user_id` è lui, «bersaglio» se il percorso lo nomina
 *     (PERCORSI_DEL_SOGGETTO). Una lettura che non lo nomina nel percorso
 *     (un filtro in query string, un id nel corpo) non gli si può
 *     attribuire: il riepilogo lo scrive in `criteria`, perché uno zero non
 *     venga letto come «nessuno ha mai guardato i suoi dati».
 *   - crypto_access_log: TeacherCryptoService scrive `accessor_id` (chi) e
 *     `teacher_id` (di chi è la chiave); si prendono entrambe le relazioni.
 *
 * Ogni riga porta `relation`: `actor`, `target` o `actor+target`.
 *
 * Un registro che non si legge non conta zero: il conteggio resta null, il
 * file non entra nel bundle e l'errore va in `errors` del riepilogo (che il
 * manifest riporta sezione per sezione). Il conteggio del file ha accanto il
 * totale del registro: col tetto di righe, «1000» detto di un registro che
 * ne ha 413.723 (un solo docente, nel database locale il 23/9) sarebbe
 * un'altra dichiarazione falsa.
 */
final class AuditLogExporter implements ContentExporterInterface
{
    /** Righe al massimo per ciascun registro di accesso nel bundle. */
    public const LIMITE_RIGHE = 1000;

    /** Eventi di custodia al massimo (il tetto che c'era già). */
    private const LIMITE_CUSTODIA = 500;

    /**
     * Percorsi che nominano un utente come risorsa: `{id}` è il suo id, e
     * vale anche ogni percorso sotto (`/api/admin/users/{id}/role`).
     *
     * Solo percorsi di `routes/web.php` in cui `{id}` è davvero un id utente
     * (non `/admin/registrations/{id}`, che è una registrazione in attesa).
     * `/api/admin/users/{id}/…` oggi non passa da `audit_reason`: sta qui
     * perché, quando ci passerà (R-6 passo 6), le sue righe entrino senza
     * toccare l'export. Una rotta nuova che nomina un utente va aggiunta qui.
     */
    public const PERCORSI_DEL_SOGGETTO = [
        '/api/admin/analytics/teacher/{id}',
        '/api/admin/users/{id}',
    ];

    // Proprietà classica e non promossa con `readonly`: l'analizzatore PHP di
    // semgrep non legge `readonly` nei parametri del costruttore, e il file
    // finirebbe fra quelli letti solo in parte, dove le regole non girano
    // (tools/ci/cancello-semgrep.mjs, 23/9/2026).
    private int $limite;

    public function __construct(int $limite = self::LIMITE_RIGHE)
    {
        $this->limite = $limite;
    }

    public function getKey(): string
    {
        return 'audit_log';
    }
    public function getLabel(): string
    {
        return 'Audit log (accessi + crypto)';
    }
    public function getCategory(): string
    {
        return 'meta';
    }
    public function isAvailableForSelfService(): bool
    {
        return false; // NON in self-service
    }
    public function isAvailableForAuthority(): bool
    {
        return true;
    }

    public function export(ExportContext $ctx): ExportSection
    {
        $section = new ExportSection('audit_log', 'audit', $this->getLabel());
        $db  = Database::connection();
        $uid = $ctx->userId;
        /** @var list<string> $errori */
        $errori = [];

        // Accessi privilegiati: il soggetto ha agito, o il percorso lo nomina.
        [$bersaglio, $parBersaglio] = self::condizioneBersaglio($uid);
        $privAccess = $this->leggi(
            $db,
            'privileged_access_log',
            "id, created_at, user_id, actor_name, actor_role, action, resource_type,
             resource_id, reason, outcome, HEX(ip_hash) AS ip_hash, HEX(ua_hash) AS ua_hash,
             CONCAT_WS('+', IF(user_id = ?, 'actor', NULL), IF($bersaglio, 'target', NULL)) AS relation",
            [$uid, ...$parBersaglio],
            "user_id = ? OR $bersaglio",
            [$uid, ...$parBersaglio],
            'created_at DESC, id DESC',
            $this->limite,
            $errori,
        );

        // Operazioni crypto: sulla chiave del soggetto, o fatte da lui.
        $cryptoAccess = $this->leggi(
            $db,
            'crypto_access_log',
            "id, accessed_at, accessor_id, teacher_id, table_name, row_id, operation,
             reason, outcome,
             CONCAT_WS('+', IF(accessor_id = ?, 'actor', NULL), IF(teacher_id = ?, 'target', NULL)) AS relation",
            [$uid, $uid],
            'teacher_id = ? OR accessor_id = ?',
            [$uid, $uid],
            'accessed_at DESC, id DESC',
            $this->limite,
            $errori,
        );

        // Crypto custody events (autorità che hanno richiesto accesso a questo teacher)
        $custodyEvents = $this->leggi(
            $db,
            'crypto_custody_events',
            'id, event_type, occurred_at, actor_user_id, authority_name,
             authority_ref, legal_basis, description, evidence_url',
            [],
            'teacher_id = ?',
            [$uid],
            'occurred_at DESC, id DESC',
            min($this->limite, self::LIMITE_CUSTODIA),
            $errori,
        );

        $registri = [
            'privileged_access_log' => $privAccess,
            'crypto_access_log'     => $cryptoAccess,
            'crypto_custody_events' => $custodyEvents,
        ];
        foreach ($registri as $registro => $letto) {
            if ($letto !== null) {
                $section->addJsonFile($registro . '.json', $letto['righe']);
            }
        }

        $section->setSummary([
            'privileged_access_count' => $privAccess === null ? null : count($privAccess['righe']),
            'privileged_access_total' => $privAccess['totale'] ?? null,
            'crypto_access_count'     => $cryptoAccess === null ? null : count($cryptoAccess['righe']),
            'crypto_access_total'     => $cryptoAccess['totale'] ?? null,
            'custody_events_count'    => $custodyEvents === null ? null : count($custodyEvents['righe']),
            'custody_events_total'    => $custodyEvents['totale'] ?? null,
            'criteria'                => [
                'privileged_access_log' => 'righe in cui il soggetto ha agito (user_id) o in cui il percorso '
                    . 'della risorsa lo nomina (' . implode(', ', self::PERCORSI_DEL_SOGGETTO) . '); '
                    . 'le letture che non lo nominano nel percorso non gli sono attribuibili',
                'crypto_access_log'     => 'operazioni sulla chiave del soggetto (teacher_id) '
                    . 'o fatte dal soggetto (accessor_id)',
                'crypto_custody_events' => 'eventi di custodia sulla chiave del soggetto (teacher_id)',
                'limit'                 => $this->limite,
            ],
            'errors'                  => $errori,
        ]);
        return $section;
    }

    /**
     * Una lettura di registro: le righe (al più $limite, dalle più recenti) e
     * il totale di quelle che rispondono al filtro. Null se il registro non
     * si legge; il motivo finisce in $errori e nel log del server.
     *
     * @param list<mixed>  $parColonne parametri dei segnaposto in $colonne
     * @param list<mixed>  $parFiltro  parametri dei segnaposto in $filtro
     * @param list<string> $errori
     * @return array{righe: list<array<string,mixed>>, totale: int}|null
     */
    private function leggi(
        PDO $db,
        string $registro,
        string $colonne,
        array $parColonne,
        string $filtro,
        array $parFiltro,
        string $ordine,
        int $limite,
        array &$errori,
    ): ?array {
        try {
            $conta = $db->prepare("SELECT COUNT(*) FROM $registro WHERE $filtro");
            $conta->execute($parFiltro);
            $totale = (int)$conta->fetchColumn();

            $stmt = $db->prepare(
                "SELECT $colonne FROM $registro WHERE $filtro ORDER BY $ordine LIMIT " . max(1, $limite)
            );
            $stmt->execute([...$parColonne, ...$parFiltro]);
            /** @var list<array<string,mixed>> $righe */
            $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return ['righe' => $righe, 'totale' => $totale];
        } catch (\Throwable $e) {
            $errori[] = $registro . ': ' . $e->getMessage();
            error_log('[AuditLogExporter] ' . $registro . ' non letto: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * La condizione «il percorso della risorsa nomina l'utente $uid», con i
     * suoi parametri: il percorso esatto o uno sotto, mai un id che comincia
     * con le stesse cifre (`/teacher/14` non prende `/teacher/140`).
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function condizioneBersaglio(int $uid): array
    {
        $parti = [];
        $parametri = [];
        foreach (self::PERCORSI_DEL_SOGGETTO as $modello) {
            $percorso = str_replace('{id}', (string)$uid, $modello);
            $parti[] = '(resource_id = ? OR resource_id LIKE ?)';
            $parametri[] = $percorso;
            $parametri[] = addcslashes($percorso, '%_\\') . '/%';
        }
        return ['(' . implode(' OR ', $parti) . ')', $parametri];
    }
}
