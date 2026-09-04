<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use PDO;

/**
 * Repository per risdoc_compilations.
 *
 * Ogni compilazione è un'istanza valorizzata del template legata al
 * docente loggato + `compilation_key` (slug dei campi del
 * .dynamic-selector-container: classe/sezione/indirizzo/disciplina).
 * UPSERT per sovrascrivere salvataggi precedenti con stessa chiave.
 *
 * CIFRATURA (2026-09-04, migration 101)
 *   `data_json` conteneva in chiaro tutto cio' che il docente scrive nel
 *   documento — campi e testo libero. Ora, se il KMS e' configurato, il JSON
 *   viene cifrato con la chiave del docente (stesso envelope dei contenuti,
 *   ADR-006: data_ct/iv/tag/kv) e `data_json` resta NULL. In lettura si
 *   decifra se c'e' il ciphertext, altrimenti si legge il plaintext legacy;
 *   le righe legacy si convertono con
 *   tools/gdpr/encrypt_risdoc_compilations.php. Il crypto-shredding del
 *   docente rende illeggibili anche le compilazioni.
 *
 *   Fuori da questa classe nessuno legge `data_json` direttamente: chi lo
 *   faceva (TemplateController, TemplateViewController) passa da
 *   {@see latestData()}.
 */
final class CompilationRepository
{
    private ?TeacherCryptoService $crypto = null;

    public function __construct(?TeacherCryptoService $crypto = null)
    {
        // Lazy: il servizio crypto nasce al primo uso, cosi' i test e le
        // installazioni senza KMS non lo istanziano per niente.
        $this->crypto = $crypto;
    }

    private function crypto(): TeacherCryptoService
    {
        return $this->crypto ??= new TeacherCryptoService();
    }

    /** Upsert: insert o update se esiste già (teacher_id+template_id+compilation_key). */
    public function save(int $teacherId, int $templateId, string $compilationKey, string $label, ?string $classe, ?string $sezione, ?string $indirizzo, ?string $disciplina, string $dataJson): int
    {
        // Fase D — solo FK ids (varchar dropped)
        $L = \App\Support\CurriculumLookup::class;
        $indId = $indirizzo !== null && $indirizzo !== ''
            ? $L::idFromCodeForTeacher('indirizzi', (string)$indirizzo, $teacherId) : null;
        $clsId = $classe !== null && $classe !== ''
            ? $L::idFromCodeForTeacher('classi', (string)$classe, $teacherId) : null;

        [$plain, $ct, $iv, $tag, $kv] = $this->seal($teacherId, $dataJson);

        $stmt = Database::connection()->prepare('INSERT INTO risdoc_compilations_data
                (teacher_id, template_id, compilation_key, label,
                 classe_id, sezione, indirizzo_id, disciplina,
                 data_json, data_ct, data_iv, data_tag, data_kv)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                label=VALUES(label),
                classe_id=VALUES(classe_id),
                sezione=VALUES(sezione),
                indirizzo_id=VALUES(indirizzo_id),
                disciplina=VALUES(disciplina),
                data_json=VALUES(data_json),
                data_ct=VALUES(data_ct),
                data_iv=VALUES(data_iv),
                data_tag=VALUES(data_tag),
                data_kv=VALUES(data_kv)');
        $stmt->execute([
            $teacherId, $templateId, $compilationKey, $label,
            $clsId, $sezione ?: null, $indId, $disciplina ?: null,
            $plain, $ct, $iv, $tag, $kv,
        ]);
        $lastId = (int)Database::connection()->lastInsertId();
        if ($lastId > 0) {
            return $lastId;
        }
        // Su UPDATE path, lastInsertId=0: lookup via unique key.
        $q = Database::connection()->prepare('SELECT id FROM risdoc_compilations
             WHERE teacher_id=? AND template_id=? AND compilation_key=? LIMIT 1');
        $q->execute([$teacherId, $templateId, $compilationKey]);
        return (int)$q->fetchColumn();
    }

    /** Lista compilazioni del docente per un template, ordinate per updated_at desc.
     *  Filtri opzionali per matchare il contesto corrente del form. Senza dati. */
    public function listByTeacher(int $teacherId, int $templateId, ?string $classe = null, ?string $sezione = null, ?string $indirizzo = null, ?string $disciplina = null): array
    {
        $sql = 'SELECT id, compilation_key, label, classe, sezione, indirizzo,
                       disciplina, created_at, updated_at
                FROM risdoc_compilations
                WHERE teacher_id=? AND template_id=?';
        $args = [$teacherId, $templateId];
        // Filtri opzionali: match stretto se passato, ignora se null.
        if ($classe !== null) {
            $sql .= ' AND classe = ?';
            $args[] = $classe;
        }
        if ($sezione !== null) {
            $sql .= ' AND sezione = ?';
            $args[] = $sezione;
        }
        if ($indirizzo !== null) {
            $sql .= ' AND indirizzo = ?';
            $args[] = $indirizzo;
        }
        if ($disciplina !== null) {
            $sql .= ' AND disciplina = ?';
            $args[] = $disciplina;
        }
        $sql .= ' ORDER BY updated_at DESC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Dettaglio con `data_json` in chiaro (decifrato se cifrato). Le colonne
     * del ciphertext non escono da qui.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $teacherId, int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id, template_id, compilation_key, label, classe, sezione,
                    indirizzo, disciplina, data_json, data_ct, data_iv, data_tag, data_kv,
                    created_at, updated_at
             FROM risdoc_compilations
             WHERE teacher_id=? AND id=? LIMIT 1');
        $stmt->execute([$teacherId, $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $r['data_json'] = $this->open($teacherId, $r);
        unset($r['data_ct'], $r['data_iv'], $r['data_tag'], $r['data_kv']);
        return $r;
    }

    /**
     * Ultima compilazione del docente per un template, gia' decodificata.
     * Vuoto se non c'e' o se non e' decifrabile.
     *
     * @return array<string,mixed>
     */
    public function latestData(int $teacherId, int $templateId): array
    {
        if ($teacherId <= 0 || $templateId <= 0) {
            return [];
        }
        $stmt = Database::connection()->prepare('SELECT data_json, data_ct, data_iv, data_tag, data_kv
             FROM risdoc_compilations
             WHERE teacher_id=? AND template_id=?
             ORDER BY updated_at DESC LIMIT 1');
        $stmt->execute([$teacherId, $templateId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return [];
        }
        $decoded = json_decode($this->open($teacherId, $r), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function delete(int $teacherId, int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM risdoc_compilations_data WHERE teacher_id=? AND id=?');
        $stmt->execute([$teacherId, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cifra il JSON per il docente, se il KMS e' configurato; altrimenti resta
     * in chiaro (installazioni di sviluppo senza chiave). Mai entrambi.
     *
     * @return array{0:?string,1:?string,2:?string,3:?string,4:?int} [plain, ct, iv, tag, kv]
     */
    private function seal(int $teacherId, string $plain): array
    {
        if ($teacherId > 0 && $this->crypto()->isConfigured()) {
            $env = $this->crypto()->encrypt($teacherId, $plain);
            return [null, $env['ciphertext'], $env['iv'], $env['tag'], (int)$env['kv']];
        }
        return [$plain, null, null, null, null];
    }

    /**
     * Plaintext di una riga: decifrato se c'e' il ciphertext, altrimenti il
     * `data_json` legacy. Un fallimento di decifratura (chiave perduta,
     * shredding) si annota e produce una compilazione vuota, non un 500.
     *
     * @param array<string,mixed> $r
     */
    private function open(int $teacherId, array $r): string
    {
        if (!empty($r['data_ct']) && !empty($r['data_iv']) && !empty($r['data_tag'])) {
            try {
                return $this->crypto()->decrypt($teacherId, [
                    'ciphertext' => $r['data_ct'],
                    'iv'         => $r['data_iv'],
                    'tag'        => $r['data_tag'],
                    'kv'         => (int)($r['data_kv'] ?? 1),
                ]);
            } catch (\Throwable $e) {
                \error_log('[CompilationRepository] decifratura fallita per teacher ' . $teacherId . ': ' . $e->getMessage());
                return '';
            }
        }
        return (string)($r['data_json'] ?? '');
    }
}
