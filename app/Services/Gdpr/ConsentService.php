<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Database;
use App\Support\DeploymentScenario;
use App\Support\ImprontaIp;
use PDO;

/**
 * Phase 25.C3 — Service per gestione consensi GDPR (Art. 6, 7, 9).
 *
 * Tipi consenso supportati:
 *   - analytics        (cookie analytics non strettamente necessari)
 *   - marketing        (email marketing, futuro)
 *   - institute_share  (condivisione contenuti con docenti istituto)
 *   - pool_share       (pool repository condiviso)
 *   - third_party_export (export verso Google Drive; il nome comprende
 *     anche Overleaf, tolto dall'applicazione il 21/9/2026. Il valore è
 *     dato salvato nei consensi e non si riscrive.)
 *   - art9_bes_dsa     [RISERVATO — non in uso]: previsto per future feature
 *                      che potessero introdurre tracking studente-DSA personale.
 *                      Oggi NON applicabile: l'app tratta DSA solo come metadata
 *                      di contenuto del docente (verifica codebase 2026-04-27).
 *
 * Pattern revoke:
 *   - revoke() = UPDATE revoked_at = NOW(), NON DELETE.
 *   - listActive(user_id) ritorna le row con revoked_at IS NULL.
 *   - audit log scritto in consent_audit ad ogni grant/revoke.
 *
 * Versioning informativa:
 *   - text_version: la versione del testo dell'informativa dello scenario
 *     attivo (il `versione:` del suo file), dal 23/9/2026.
 *   - User che ha consensi con text_version vecchio → reconfirm prompt al login.
 *
 * Impronte di IP e User-Agent:
 *   - IP e User-Agent non si salvano in chiaro.
 *   - L'IP, intero, come impronta con chiave (HMAC-SHA256, App\Support\ImprontaIp)
 *     in `consents.ip_hash` e `consent_audit.ip_hash`, dal 24/9/2026; le
 *     righe di prima hanno lo SHA-256 senza chiave.
 *   - Lo User-Agent intero come SHA-256 senza chiave.
 *   - Sono dati pseudonimi, non anonimi: con il segreto del server un
 *     indirizzo noto si ritrova (tools/audit/impronta_ip.php).
 */
final class ConsentService
{
    public const TYPES = [
        'art9_bes_dsa', 'analytics', 'marketing',
        'institute_share', 'pool_share', 'third_party_export',
    ];

    public const LEGAL_BASES = [
        'art6_1_a_consent', 'art6_1_b_contract', 'art6_1_c_legal_obligation',
        'art6_1_e_public_interest', 'art6_1_f_legitimate', 'art9_2_a_explicit_consent',
    ];

    /**
     * Registra un consenso (grant). Idempotent: se l'utente ha già una
     * consent attiva (revoked_at IS NULL) per lo stesso type+text_version,
     * ritorna l'id esistente. Altrimenti crea nuova row.
     *
     * Per Art. 9 (BES/DSA): legal_basis MUST essere 'art9_2_a_explicit_consent'.
     */
    public function grant(int $userId, string $type, string $textVersion, ?array $audit = null): int
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("invalid consent type: $type");
        }
        $legalBasis = $type === 'art9_bes_dsa' ? 'art9_2_a_explicit_consent' : 'art6_1_a_consent';

        $db = Database::connection();
        // Idempotent: verifica se già esiste consenso attivo identico
        $existing = $db->prepare(
            'SELECT id FROM consents
             WHERE user_id=? AND consent_type=? AND text_version=? AND revoked_at IS NULL
             LIMIT 1'
        );
        $existing->execute([$userId, $type, $textVersion]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            return (int)$existingId;
        }

        // L'indirizzo e lo User-Agent arrivano in chiaro: qui diventano impronte.
        $ip     = isset($audit['ip']) ? (string)$audit['ip'] : null;
        $uaHash = $audit['ua']  ?? null;

        $stmt = $db->prepare(
            'INSERT INTO consents
                (user_id, consent_type, granted, granted_at, ip_hash, user_agent_hash,
                 legal_basis, text_version, notes)
             VALUES (?, ?, 1, NOW(), ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId, $type, ImprontaIp::di($ip),
            $uaHash ? hash('sha256', (string)$uaHash, true) : null,
            $legalBasis, $textVersion, $audit['notes'] ?? null,
        ]);
        $id = (int)$db->lastInsertId();

        $this->logAudit($id, $userId, $type, 'granted', $textVersion, $ip);
        return $id;
    }

    /**
     * Revoca un consenso (Art. 7 §3 — diritto di revoca).
     * UPDATE revoked_at, audit log scritto.
     */
    public function revoke(int $userId, string $type, ?array $audit = null): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE consents SET revoked_at = NOW()
             WHERE user_id = ? AND consent_type = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$userId, $type]);
        if ($stmt->rowCount() === 0) {
            return false;
        }

        $this->logAudit(null, $userId, $type, 'revoked', null, $audit['ip'] ?? null);
        return true;
    }

    /**
     * Lista consensi attivi (revoked_at IS NULL) per l'utente, decoded.
     *
     * @return array<int, array{id:int, consent_type:string, granted_at:string, text_version:string, legal_basis:string}>
     */
    public function listActive(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, consent_type, granted_at, text_version, legal_basis, notes
             FROM consents
             WHERE user_id = ? AND revoked_at IS NULL
             ORDER BY granted_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Verifica se l'utente ha consenso attivo per un tipo specifico.
     * Usato dai gates: es. AnalyticsService deve check hasActive('analytics')
     * prima di tracciare.
     */
    public function hasActive(int $userId, string $type): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM consents
             WHERE user_id = ? AND consent_type = ? AND revoked_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$userId, $type]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Versione dell'informativa che l'utente vede adesso: quella del testo
     * dello scenario attivo, letta dal suo file (DeploymentScenario::
     * versioneInformativa()). Fino al 23/9/2026 era `gdpr.text_version`, la
     * versione di docs/privacy/informativa.md ricopiata in configurazione:
     * negli scenari 1 e 3, che servono un altro testo, ogni consenso citava
     * il testo sbagliato (revisione architetturale, DOC-19).
     */
    public function currentTextVersion(): string
    {
        return DeploymentScenario::versioneInformativa();
    }

    /**
     * Returns array di consent_type per cui l'utente NON ha consenso al
     * text_version corrente — richiede reconfirm al login (Art. 7 §1).
     *
     * Effetto di un cambio di versione (portato qui da app/Config/gdpr.php,
     * tolto il 23/9/2026): i tipi prestati sulla versione precedente tornano
     * in `GET /me/consents`. Dal 15/9/2026 nessuna pagina li ripropone (il
     * banner dei cookie è stato tolto, e la piattaforma non raccoglie
     * consensi facoltativi). Il vecchio consenso NON viene revocato: resta
     * valido e tracciato finché l'utente non si esprime. Non è un cancello:
     * l'art. 7 §4 vuole il consenso libero, e subordinare l'accesso alla sua
     * conferma lo vizierebbe (diversamente da ToS/AUP, che hanno
     * TosAcceptanceMiddleware).
     */
    public function needsReconfirm(int $userId): array
    {
        $current = $this->currentTextVersion();
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT consent_type FROM consents
             WHERE user_id = ?
               AND text_version != ?
               AND revoked_at IS NULL
               AND consent_type NOT IN (
                  SELECT consent_type FROM consents
                  WHERE user_id = ? AND text_version = ? AND revoked_at IS NULL
               )'
        );
        $stmt->execute([$userId, $current, $userId, $current]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function logAudit(?int $consentId, int $userId, string $type, string $event, ?string $textVersion, ?string $ip): void
    {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO consent_audit
                    (consent_id, user_id, consent_type, event, text_version, ip_hash)
                 VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([
                $consentId, $userId, $type, $event, $textVersion,
                ImprontaIp::di($ip),
            ]);
        } catch (\Throwable) {
            // Best-effort audit
        }
    }
}
