<?php

declare(strict_types=1);

namespace App\Services\Verifica;

use App\Repositories\VerificaDocumentRepository;
use App\Services\Crypto\EncryptedBlobStore;
use App\Support\PdoTransactionRunner;
use App\Support\TransactionRunner;
use App\Support\Ulid;
use RuntimeException;
use Throwable;

/**
 * Phase G8 + G22.S4 — Service che coordina:
 *   - persistenza row verifica_documents (Repository)
 *   - blob TEX/PDF cifrato envelope (EncryptedBlobStore namespace 'verifiche_enc')
 *   - lettura/scrittura manifest multi-file (S4.B.2)
 *
 * I template (intestazione/griglia/criteri/footer) sono ora applicati
 * nativamente da `TexBuilder::buildFlat()` (S4.B.1) leggendo i file in
 * storage/templates/verifiche/{scope}/... — niente piu' applyTemplate
 * post-process ne' VerificaTemplateRepository (S4.B.4).
 *
 * Errori di dominio sono RuntimeException con codici stringa stabili
 * ('verifica_save_failed', 'verifica_pdf_too_large', ...) per mapping
 * lato controller in JSON response.
 */
final class VerificaDocumentService
{
    private const MAX_TEX_BYTES = 4 * 1024 * 1024;   // 4 MiB plaintext TEX
    private const MAX_PDF_BYTES = 30 * 1024 * 1024;  // 30 MiB PDF compilato
    private const PDF_MIME      = 'application/pdf';

    private VerificaDocumentRepository $docs;
    private EncryptedBlobStore $store;
    private TransactionRunner $tx;

    public function __construct(
        ?VerificaDocumentRepository $docs = null,
        ?EncryptedBlobStore $store = null,
        ?TransactionRunner $tx = null,
    ) {
        $this->docs  = $docs  ?? new VerificaDocumentRepository();
        $this->store = $store ?? new EncryptedBlobStore('verifiche_enc');
        $this->tx    = $tx    ?? new PdoTransactionRunner();
    }

    /**
     * Salva un nuovo verifica_document con TEX cifrato.
     *
     * @param array{
     *   teacher_id: int,
     *   materia: string,
     *   title: string,
     *   tex: string,
     *   exercise_ids?: list<int>,
     *   fm_db_section?: string,
     * } $input
     *
     * @return array Doc creato (incluso id, paths).
     */
    public function saveTex(array $input): array
    {
        $teacherId = (int)($input['teacher_id'] ?? 0);
        $materia   = trim((string)($input['materia'] ?? ''));
        $title     = trim((string)($input['title'] ?? ''));
        $tex       = (string)($input['tex'] ?? '');

        if ($teacherId <= 0) {
            throw new RuntimeException('verifica_invalid_teacher');
        }
        if ($materia === '') {
            throw new RuntimeException('verifica_materia_required');
        }
        if ($title === '') {
            throw new RuntimeException('verifica_title_required');
        }
        if ($tex === '') {
            throw new RuntimeException('verifica_tex_empty');
        }

        // G22.S4 — applyTemplate post-process rimosso. Il caller passa
        // gia' un .tex completo prodotto da TexBuilder::buildLegacy()
        // (alias di build(MODE_FLAT).flatten()), che inietta intestazione/
        // griglia/criteri/footer via i template in storage/templates/
        // verifiche/_default/... → singola fonte di verita'.

        if (strlen($tex) > self::MAX_TEX_BYTES) {
            throw new RuntimeException('verifica_tex_too_large');
        }

        // G22.S1 — atomic: se docs.create fallisce, il blob cifrato non
        // resta orfano sul filesystem. store.put avviene PRIMA del begin
        // (il put e' atomic via tmpfile+rename); su rollback DB
        // cancelliamo manualmente il file appena scritto.
        $ulid    = Ulid::generate();
        $relPath = $this->store->put($teacherId, $tex, $ulid);
        $kv      = $this->store->readKv($relPath);

        // G22.S2 — sha256 del .tex finale per cache PDF content-addressed.
        $texSha = hash('sha256', $tex);

        try {
            $id = $this->tx->run(fn(): int => $this->docs->create([
                'teacher_id'    => $teacherId,
                'materia'       => $materia,
                'title'         => $title,
                'fm_db_section' => $input['fm_db_section'] ?? 'VERIFICHE',
                'exercise_ids'  => $input['exercise_ids'] ?? [],
                'tex_blob_path' => $relPath,
                'tex_blob_kv'   => $kv,
                'tex_size'      => strlen($tex),
                'tex_sha256'    => $texSha,
            ]));
        } catch (Throwable $e) {
            $this->safeDeleteBlob($relPath);
            throw $e;
        }

        $doc = $this->docs->find($id);
        if (!$doc) {
            $this->safeDeleteBlob($relPath);
            throw new RuntimeException('verifica_save_failed');
        }
        return $doc;
    }

    /**
     * Recupera il TEX della verifica come stringa monolitica self-contained.
     *
     * Per row multi-file (S4.B.2+): legge la manifest, decifra ogni file,
     * costruisce un BuildResult locale e ritorna `flatten()` → singolo
     * .tex compilabile da pdflatex senza dipendenze esterne. Usato dal
     * download endpoint /api/verifica/{id}/tex e dal compilePdf fallback.
     *
     * Per row legacy single-blob (pre-S4.B.2): decifra direttamente il blob.
     *
     * Solo l'owner puo' leggere (envelope encryption).
     */
    public function readTex(int $teacherId, int $docId): string
    {
        $doc = $this->requireOwn($teacherId, $docId);

        // G22.S4.B.2 — multi-file path: ricomponi flat dalla manifest.
        $manifest = is_array($doc['tex_files'] ?? null) ? $doc['tex_files'] : [];
        if ($manifest) {
            return $this->assembleFlat($teacherId, $manifest, (string)($doc['variant'] ?? ''));
        }

        // Legacy single-blob path.
        if (!empty($doc['tex_blob_path'])) {
            return $this->store->get($teacherId, (string)$doc['tex_blob_path']);
        }
        throw new RuntimeException('verifica_tex_empty');
    }

    /**
     * G22.S4.B.2 — Decifra tutti i file della manifest e ritorna la lista
     * `[{path, content}, ...]` pronta per essere materializzata (es. tar.gz
     * per VPS /compile-bundle in S4.B.3, o ZIP/VSC bundle assembly).
     *
     * Solo l'owner puo' leggere (TKEK envelope check delegato a store->get).
     *
     * @return list<array{path:string, content:string}>
     */
    public function readManifestFiles(int $teacherId, int $docId): array
    {
        $doc = $this->requireOwn($teacherId, $docId);
        $manifest = is_array($doc['tex_files'] ?? null) ? $doc['tex_files'] : [];
        if (!$manifest) {
            return [];
        }
        $out = [];
        foreach ($manifest as $f) {
            if (!is_array($f) || empty($f['path']) || empty($f['blob_path'])) {
                continue;
            }
            $entry = [
                'path'    => (string)$f['path'],
                'content' => '',
                'missing' => false,
            ];
            // G22.S10b — defensive: blob può mancare (migrazione parziale,
            // reap accidentale). Non rompere l'intera lista: marca missing
            // e lascia l'UI mostrare ❌. L'utente può recuperare salvando
            // da modello comune o ricreando da capo.
            try {
                if (!$this->store->exists((string)$f['blob_path'])) {
                    $entry['missing'] = true;
                    $entry['content'] = "% G22.S10b — file mancante (blob non trovato): {$f['path']}\n"
                                      . "% Ricrea il contenuto qui o ripristina da modello comune.\n";
                } else {
                    $entry['content'] = $this->store->get($teacherId, (string)$f['blob_path']);
                }
            } catch (Throwable $e) {
                $entry['missing'] = true;
                $entry['content'] = "% G22.S10b — errore lettura blob ({$e->getMessage()})\n"
                                  . "% Path: {$f['path']}\n";
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * G22.S4.B.2 — Ricostruisce il .tex monolitico da una manifest multi-file
     * passando per `BuildResult::flatten()`. Usato da readTex e dal
     * compilePdf fallback (quando il VPS endpoint /compile-bundle non e'
     * ancora disponibile, S4.B.3).
     *
     * @param list<array{path:string, blob_path:string}> $manifest
     */
    private function assembleFlat(int $teacherId, array $manifest, string $variantKind): string
    {
        $files = [];
        foreach ($manifest as $f) {
            if (!is_array($f) || empty($f['path']) || empty($f['blob_path'])) {
                continue;
            }
            $files[] = [
                'path'    => (string)$f['path'],
                'content' => $this->store->get($teacherId, (string)$f['blob_path']),
            ];
        }
        if (!$files) {
            throw new RuntimeException('verifica_manifest_empty');
        }

        $kind = $variantKind !== '' && preg_match('/(SOL|NOR|DSA|DIS)$/', $variantKind, $m)
            ? $m[1] : 'NOR';

        $br = new \App\Services\TexBuilder\BuildResult(
            files:   $files,
            mode:    \App\Services\TexBuilder\BuildResult::MODE_FLAT,
            variant: $kind,
        );
        return $br->flatten();
    }

    /**
     * G22.S10 — Aggiorna i contenuti dei file della manifest multi-file.
     *
     * Riceve `[{path, content}, ...]` (paths relativi normalizzati). Per ogni
     * file il cui content è cambiato (o nuovo path) scrive un nuovo blob
     * envelope-encrypted; ricostruisce la manifest preservando l'ordine dei
     * path richiesti; calcola sha256 sul .tex flattened (cache PDF
     * coherence); aggiorna la row; cancella i blob orfani.
     *
     * Vincoli:
     *  - Almeno 1 file con path === 'main.tex' (è il root del compile).
     *  - Path già presenti nella manifest preservano blob esistente se
     *    content invariato (no churn IO).
     *
     * @param list<array{path:string, content:string}> $files
     */
    public function updateTexFiles(int $teacherId, int $docId, array $files): array
    {
        $doc = $this->requireOwn($teacherId, $docId);

        if (!$files) {
            throw new RuntimeException('verifica_files_empty');
        }

        // Validation + normalize. Convention main file: BuildResult::flatten()
        // sceglie il root in base alla variant_kind (es. versioni/main_NOR.tex).
        // Qui non vincoliamo il nome del root: validiamo solo path safety.
        // G27.ggb-zero-byte fix — getTexFiles ritorna binari (geogebra/N.pdf,
        // immagini) con content='' (is_binary placeholder). Il frontend
        // li rimanda invariati nel POST: senza filtro, sha256(empty) ≠ blob
        // esistente → riscrivremmo geogebra/N.pdf come 0 bytes, rompendo
        // pdflatex ("reading image file failed"). Indicizziamo qui i path
        // binari preservati così la fase di build manifest sotto li riusa.
        $oldManifestRaw = is_array($doc['tex_files'] ?? null) ? $doc['tex_files'] : [];
        $oldByPathFast  = [];
        foreach ($oldManifestRaw as $of) {
            if (is_array($of) && !empty($of['path'])) {
                $oldByPathFast[(string)$of['path']] = $of;
            }
        }
        $preserved = [];
        $normalized = [];
        $totalSize = 0;
        foreach ($files as $f) {
            if (!is_array($f) || !isset($f['path'], $f['content'])) {
                throw new RuntimeException('verifica_file_invalid');
            }
            $path = trim((string)$f['path']);
            $content = (string)$f['content'];
            if (
                $path === '' || str_contains($path, '..') || str_starts_with($path, '/')
                || str_contains($path, '\\') || !preg_match('#^[a-zA-Z0-9_./-]+$#', $path)
            ) {
                throw new RuntimeException('verifica_file_path_invalid');
            }
            // Skip binary placeholder: content vuoto + path con extension binary
            // + entry esistente con blob valido → preserva entry as-is.
            if (
                $content === ''
                && preg_match('/\.(pdf|png|jpe?g|gif|svg|webp)$/i', $path)
                && isset($oldByPathFast[$path])
                && !empty($oldByPathFast[$path]['blob_path'])
            ) {
                $preserved[$path] = $oldByPathFast[$path];
                $totalSize += (int)($oldByPathFast[$path]['size'] ?? 0);
                continue;
            }
            $size = strlen($content);
            if ($size > self::MAX_TEX_BYTES) {
                throw new RuntimeException('verifica_file_too_large');
            }
            $totalSize += $size;
            $normalized[] = ['path' => $path, 'content' => $content];
        }
        if ($totalSize > self::MAX_TEX_BYTES) {
            throw new RuntimeException('verifica_tex_too_large');
        }

        // Esistenti per riuso blob (se sha256 invariata = stesso content).
        $oldManifest = is_array($doc['tex_files'] ?? null) ? $doc['tex_files'] : [];
        $oldByPath = [];
        // G22.S15.bis Fase 5 — index per sha256 sull'old manifest, così se
        // un file cambia path ma il content è identico ad un altro blob già
        // esistente, riusiamo quel blob senza scrivere su disco.
        $oldBySha = [];
        foreach ($oldManifest as $of) {
            if (is_array($of) && !empty($of['path'])) {
                $oldByPath[(string)$of['path']] = $of;
                if (!empty($of['sha256']) && !empty($of['blob_path'])) {
                    $oldBySha[(string)$of['sha256']] = $of;
                }
            }
        }

        $writtenBlobs = [];
        $newManifest  = [];
        // Dedup intra-manifest: stesso content in due path differenti scrive
        // un solo blob (es. geogebra/1.pdf e geogebra/2.pdf identici).
        $shaToBlob = [];
        try {
            foreach ($normalized as $f) {
                $sha = hash('sha256', $f['content']);
                $existing = $oldByPath[$f['path']] ?? null;
                if ($existing && ($existing['sha256'] ?? '') === $sha) {
                    // Content invariato sullo stesso path: riusa blob esistente.
                    $newManifest[] = $existing;
                    continue;
                }
                // Cache miss su path: prova match per sha256 (cross-path).
                if (isset($shaToBlob[$sha])) {
                    $shared = $shaToBlob[$sha];
                    $newManifest[] = [
                        'path'      => $f['path'],
                        'blob_path' => $shared['blob_path'],
                        'blob_kv'   => $shared['blob_kv'],
                        'sha256'    => $sha,
                        'size'      => strlen($f['content']),
                    ];
                    continue;
                }
                if (isset($oldBySha[$sha])) {
                    $shared = $oldBySha[$sha];
                    $entry = [
                        'path'      => $f['path'],
                        'blob_path' => (string)$shared['blob_path'],
                        'blob_kv'   => (int)($shared['blob_kv'] ?? 0),
                        'sha256'    => $sha,
                        'size'      => strlen($f['content']),
                    ];
                    $shaToBlob[$sha] = ['blob_path' => $entry['blob_path'], 'blob_kv' => $entry['blob_kv']];
                    $newManifest[] = $entry;
                    continue;
                }
                $relPath = $this->store->put($teacherId, $f['content'], Ulid::generate());
                $writtenBlobs[] = $relPath;
                $blobKv  = $this->store->readKv($relPath);
                $shaToBlob[$sha] = ['blob_path' => $relPath, 'blob_kv' => $blobKv];
                $newManifest[] = [
                    'path'      => $f['path'],
                    'blob_path' => $relPath,
                    'blob_kv'   => $blobKv,
                    'sha256'    => $sha,
                    'size'      => strlen($f['content']),
                ];
            }
            // Append preserved binary entries (geogebra/N.pdf etc.) — vedi commento sopra.
            foreach ($preserved as $entry) {
                $newManifest[] = $entry;
            }
            // G27.ggb-attach fix — preserva i binari (geogebra/N.pdf, immagini)
            // presenti nel VECCHIO manifest ma ASSENTI dal payload. Sono
            // server-managed (attach GeoGebra): subito dopo l'attach il client può
            // non conoscerli (re-fetch stantio) → il save li scartava e il compile
            // dava "File `geogebra/N' not found". Qui li ri-aggiungiamo as-is.
            $newPaths = [];
            foreach ($newManifest as $nm) {
                if (!empty($nm['path'])) {
                    $newPaths[(string)$nm['path']] = true;
                }
            }
            foreach ($oldManifest as $of) {
                if (!is_array($of)) {
                    continue;
                }
                $p = (string)($of['path'] ?? '');
                if ($p === '' || isset($newPaths[$p]) || empty($of['blob_path'])) {
                    continue;
                }
                if (preg_match('/\.(pdf|png|jpe?g|gif|svg|webp)$/i', $p)) {
                    $newManifest[] = $of;
                    $newPaths[$p]  = true;
                }
            }
        } catch (Throwable $e) {
            $this->safeDeleteBlobs($writtenBlobs);
            throw $e;
        }

        // Computa sha256 sul .tex flat per cache PDF coherence.
        $kind = (string)($doc['variant'] ?? '');
        $kind = $kind !== '' && preg_match('/(SOL|NOR|DSA|DIS)$/', $kind, $m) ? $m[1] : 'NOR';
        $br = new \App\Services\TexBuilder\BuildResult(
            files:   $normalized,
            mode:    \App\Services\TexBuilder\BuildResult::MODE_FLAT,
            variant: $kind,
        );
        $flatSha = hash('sha256', $br->flatten());

        try {
            $this->docs->updateTexFiles($docId, $newManifest, $totalSize, $flatSha);
        } catch (Throwable $e) {
            $this->safeDeleteBlobs($writtenBlobs);
            throw $e;
        }

        // Reap blob orfani (nel vecchio manifest ma non nel nuovo).
        $newPaths = array_column($newManifest, 'blob_path');
        $newPathsSet = array_flip($newPaths);
        foreach ($oldManifest as $of) {
            if (
                is_array($of) && !empty($of['blob_path'])
                && !isset($newPathsSet[(string)$of['blob_path']])
            ) {
                $this->safeDeleteBlob((string)$of['blob_path']);
            }
        }

        // G27.batch-sync — propaga path-by-path alle sibling nello stesso batch.
        // Regola: stesso path = stesso content. Mantiene l'invariante della
        // dedup applicata al saveBatch iniziale, evita drift tra varianti.
        // Ritorna count di sibling aggiornate (per toast UI).
        $syncedCount = 0;
        $batchId = (string)($doc['batch_id'] ?? '');
        if ($batchId !== '') {
            $syncedCount = $this->propagateToBatchSiblings($teacherId, $docId, $batchId, $newManifest);
        }

        $result = $this->docs->find($docId) ?? throw new RuntimeException('verifica_save_failed');
        $result['_synced_siblings'] = $syncedCount;
        return $result;
    }

    /**
     * G27.batch-sync — Per ogni path nel nuovo manifest del doc target, cerca
     * lo stesso path nelle sibling-row del batch e aggiorna l'entry per puntare
     * al nuovo blob_path. Variant-specific (main_KIND, esercizi_KIND): solo le
     * sibling che hanno LO STESSO path vengono toccate (es. main_SOL.tex →
     * solo A_SOL/B_SOL, NON A_NOR).
     *
     * Ricomputa tex_sha256 leggendo il content dei blob (necessario per cache
     * PDF coherence: la prossima compilePdf cerca cache per sha del flat).
     *
     * @return int numero di sibling effettivamente aggiornate.
     */
    private function propagateToBatchSiblings(
        int $teacherId,
        int $sourceDocId,
        string $batchId,
        array $newManifest,
    ): int {
        $newByPath = [];
        foreach ($newManifest as $e) {
            if (!empty($e['path'])) {
                $newByPath[(string)$e['path']] = $e;
            }
        }
        if (!$newByPath) {
            return 0;
        }

        $siblings = $this->docs->listForBatch($teacherId, $batchId);
        $synced = 0;
        foreach ($siblings as $sibling) {
            if ((int)$sibling['id'] === $sourceDocId) {
                continue;
            }
            $sibManifest = is_array($sibling['tex_files'] ?? null) ? $sibling['tex_files'] : [];
            if (!$sibManifest) {
                continue;
            }

            $changed = false;
            $updated = [];
            $totalSize = 0;
            foreach ($sibManifest as $sibEntry) {
                $path = (string)($sibEntry['path'] ?? '');
                if (isset($newByPath[$path])) {
                    $newEntry = $newByPath[$path];
                    if ((string)($sibEntry['blob_path'] ?? '') !== (string)$newEntry['blob_path']) {
                        $updated[] = [
                            'path'      => $path,
                            'blob_path' => (string)$newEntry['blob_path'],
                            'blob_kv'   => (int)$newEntry['blob_kv'],
                            'sha256'    => (string)$newEntry['sha256'],
                            'size'      => (int)$newEntry['size'],
                        ];
                        $totalSize += (int)$newEntry['size'];
                        $changed = true;
                        continue;
                    }
                }
                $updated[] = $sibEntry;
                $totalSize += (int)($sibEntry['size'] ?? 0);
            }
            if (!$changed) {
                continue;
            }

            // Ricomputa flat sha leggendo content (necessario per cache PDF).
            try {
                $kind = (string)($sibling['variant'] ?? '');
                $kind = preg_match('/(SOL|NOR|DSA|DIS)$/', $kind, $m) ? $m[1] : 'NOR';
                $files = [];
                foreach ($updated as $u) {
                    $content = '';
                    try {
                        if ($this->store->exists((string)$u['blob_path'])) {
                            $content = $this->store->get($teacherId, (string)$u['blob_path']);
                        }
                    } catch (Throwable) {
                        $content = '';
                    }
                    $files[] = ['path' => (string)$u['path'], 'content' => $content];
                }
                $br = new \App\Services\TexBuilder\BuildResult(
                    files:   $files,
                    mode:    \App\Services\TexBuilder\BuildResult::MODE_FLAT,
                    variant: $kind,
                );
                $newSha = hash('sha256', $br->flatten());
                $this->docs->updateTexFiles((int)$sibling['id'], $updated, $totalSize, $newSha);
                $synced++;
            } catch (Throwable $e) {
                error_log("[batch-sync] sibling " . (int)$sibling['id'] . " update failed: " . $e->getMessage());
            }
        }
        return $synced;
    }

    /**
     * G21.1 — Aggiorna SOLO il sorgente .tex di una verifica esistente.
     * Usato dal preview modal per "Salva senza ricompilare" o salvataggio
     * concorrente con ricompila.
     *
     * Cifra envelope nuovo blob, sovrascrive vecchio blob path nel DB,
     * cancella vecchio blob su filesystem (cleanup).
     */
    public function updateTex(int $teacherId, int $docId, string $texSource): array
    {
        $doc = $this->requireOwn($teacherId, $docId);

        if ($texSource === '') {
            throw new RuntimeException('verifica_tex_empty');
        }
        if (strlen($texSource) > self::MAX_TEX_BYTES) {
            throw new RuntimeException('verifica_tex_too_large');
        }

        // Salva nuovo blob cifrato + ottieni metadata.
        $newPath = $this->store->put($teacherId, $texSource, Ulid::generate());
        $kv      = $this->store->readKv($newPath);

        // Cleanup vecchio blob (best-effort, non bloccante).
        if (
            !empty($doc['tex_blob_path']) && $doc['tex_blob_path'] !== $newPath
            && $this->store->exists($doc['tex_blob_path'])
        ) {
            $this->store->delete($doc['tex_blob_path']);
        }

        // G22.S2 — aggiorna anche tex_sha256 cosi' la cache PDF resta
        // allineata: la prossima compilePdf cerchera' un PDF con la sha
        // corrente. Se l'utente ha modificato il TEX dal preview modal,
        // il vecchio PDF non e' piu' la cache valida.
        $newSha = hash('sha256', $texSource);
        $this->docs->updateTexBlob($docId, $newPath, $kv, strlen($texSource), $newSha);
        return $this->docs->find($docId) ?? throw new RuntimeException('verifica_save_failed');
    }

    /**
     * G22.S2 — Tenta hit sulla cache PDF content-addressed: cerca un altro
     * verifica_document dello stesso docente con lo stesso tex_sha256 e
     * pdf_blob_path popolato. Se trovato:
     *   1. Decifra il PDF cached usando la TKEK del docente (envelope ADR-006)
     *   2. Verifica magic bytes %PDF- (defensive)
     *   3. Cifra envelope nuovo blob per la row corrente
     *   4. Aggiorna pdf_blob_path/kv/size della row corrente via attachPdf
     * Ritorna il doc aggiornato se hit, null se miss.
     *
     * Scope per-teacher OBBLIGATO: il docente proprietario detiene la TKEK
     * → puo' decifrare i PROPRI PDF cached e ri-cifrarli per nuove row.
     * Cross-teacher cache violerebbe envelope encryption (richiederebbe
     * 2 TKEK in chiaro contemporaneamente lato server).
     *
     * Side-effect su miss: nessuno. Il caller continua il flusso VPS.
     *
     * Errori soft (decifratura fallita, magic bytes invalidi): ritorna null
     * → cache miss, fallback a compile normale. Niente exception per non
     * bloccare il flusso utente su una row legacy/corrotta.
     *
     * @param string $texSource sorgente TEX (necessario per calcolare sha
     *                          se e' override del preview modal o la row
     *                          corrente non ha sha persistita).
     */
    public function attachCachedPdfFor(int $teacherId, int $docId, string $texSource): ?array
    {
        $doc = $this->requireOwn($teacherId, $docId);
        if ($texSource === '') {
            return null;
        }

        $sha = hash('sha256', $texSource);

        // G27.compile.selfcache — Self-cache hit: se il doc CORRENTE ha gia'
        // un PDF blob valido AND il suo tex_sha256 matcha il TEX corrente,
        // ritorniamo il doc as-is. Senza questo, ogni re-click su "Compila"
        // sullo stesso doc ricompilava al VPS (perche' findCachedPdf esclude
        // il doc corrente) → 502 quando VPS in throttle/sovraccarico.
        if (!empty($doc['pdf_blob_path']) && (string)($doc['tex_sha256'] ?? '') === $sha) {
            return $doc;
        }

        $cached = $this->docs->findCachedPdf($teacherId, $sha, $docId);
        if ($cached === null) {
            return null;
        }
        if (empty($cached['pdf_blob_path'])) {
            return null;
        }

        try {
            $pdfBin = $this->store->get($teacherId, (string)$cached['pdf_blob_path']);
        } catch (Throwable) {
            return null; // blob mancante / decifratura fallita → miss
        }
        if ($pdfBin === '' || substr($pdfBin, 0, 5) !== '%PDF-') {
            return null;
        }

        // Riusa filename del cached (oppure default verifica_{id}.pdf).
        $filename = (string)($cached['pdf_filename'] ?? '');
        if ($filename === '') {
            $filename = 'verifica_' . $docId . '.pdf';
        }

        try {
            return $this->attachPdf($teacherId, $docId, $pdfBin, $filename);
        } catch (Throwable) {
            // Se attachPdf fallisce (es. >30MiB o filesystem) non rompiamo
            // il flusso: cache miss e si compila normalmente.
            return null;
        }
    }

    /**
     * G22.S15.bis Fase 4 — Aggiunge un PDF GeoGebra al bundle multi-file.
     *
     * Pipeline:
     *   1. Validazione owner del doc
     *   2. Decode SVG base64
     *   3. Chiamata VPS `/svg-to-pdf` via SvgToPdfClient → PDF vettoriale
     *   4. Lettura manifest corrente, find next free `geogebra/N.pdf`
     *   5. updateTexFiles con bundle + nuovo PDF in coda
     *   6. Ritorna `{path: "geogebra/N", index: N, label}` per l'inserimento
     *      di `\includegraphics{geogebra/N}` nel CodeMirror.
     *
     * @return array{path:string, index:int, label:string, pdf_size:int}
     */
    public function attachGeoGebraPdf(int $teacherId, int $docId, string $svgB64, string $label = ''): array
    {
        $doc = $this->requireOwn($teacherId, $docId);

        $svgRaw = base64_decode($svgB64, true);
        if ($svgRaw === false || $svgRaw === '') {
            throw new RuntimeException('svg_b64_invalid');
        }
        if (strlen($svgRaw) > 4 * 1024 * 1024) {
            throw new RuntimeException('svg_too_large');
        }

        // SVG → PDF via VPS rsvg-convert
        $endpoint = (string)\App\Core\Config::get('tex_compile.endpoint', '');
        $secret   = (string)\App\Core\Config::get('tex_compile.secret', '');
        if ($endpoint === '' || $secret === '') {
            throw new RuntimeException('tex_compile_disabled');
        }
        $caBundle = (string)\App\Core\Config::get('tex_compile.ca_bundle', '');
        $client = new \App\Services\TexCompile\SvgToPdfClient($endpoint, $secret, 15, $caBundle);
        $r = $client->convert($svgRaw, "verifica_{$docId}_geogebra");
        if (!$r['ok'] || $r['pdf'] === null) {
            throw new RuntimeException('svg_to_pdf_failed: ' . ($r['log'] ?? ''));
        }
        $pdfBinary = (string)$r['pdf'];

        // Next free index: le geogebra/N.pdf sono CONDIVISE tra le varianti del
        // batch (updateTexFiles le propaga via propagateToBatchSiblings). Il
        // manifest del SINGOLO doc attivo può non vederle tutte → calcolare
        // nextIdx solo su di esso causava collisioni (es. nuovo "1.pdf" mentre
        // esisteva già). Scansioniamo geogebra/N.pdf su TUTTE le varianti del
        // batch. Regex tollerante a un eventuale prefisso di path.
        $existingFiles = $this->readManifestFiles($teacherId, $docId);
        $nextIdx = 1;
        // G27 — il binario va salvato nello STESSO posto degli esistenti. I tex
        // della verifica vivono in `versioni/` e `\includegraphics{geogebra/N}`
        // risolve relativo a versioni/ → il PDF deve stare in
        // `versioni/geogebra/N.pdf`. Salvarlo alla radice (`geogebra/N.pdf`)
        // dava "! LaTeX Error: File `geogebra/N' not found". Default versioni/,
        // ma adottiamo il prefisso REALE degli esistenti se presente.
        $geoPrefix = 'versioni/';
        $scanForMax = function (array $files) use (&$nextIdx, &$geoPrefix): void {
            foreach ($files as $f) {
                if (preg_match('#^(.*?)geogebra/(\d+)\.pdf$#', (string)($f['path'] ?? ''), $m)) {
                    $nextIdx = max($nextIdx, (int)$m[2] + 1);
                    $geoPrefix = $m[1]; // prefisso reale (es. "versioni/")
                }
            }
        };
        $scanForMax($existingFiles);
        $batchId = (string)($doc['batch_id'] ?? '');
        if ($batchId !== '') {
            foreach ($this->docs->listForBatch($teacherId, $batchId) as $sib) {
                if ((int)($sib['id'] ?? 0) === $docId) {
                    continue;
                }
                try {
                    $scanForMax($this->readManifestFiles($teacherId, (int)$sib['id']));
                } catch (\Throwable) {
                    // sibling illeggibile: non blocca l'attach
                }
            }
        }
        $newPath = "{$geoPrefix}geogebra/{$nextIdx}.pdf";

        // Bundle aggiornato: tutti i file esistenti + nuovo PDF
        $newFiles = $existingFiles;
        $newFiles[] = ['path' => $newPath, 'content' => $pdfBinary];

        // Riusa updateTexFiles per persistere (validazione + envelope encrypt + manifest)
        $this->updateTexFiles($teacherId, $docId, $newFiles);

        return [
            'path'          => "geogebra/{$nextIdx}",  // SENZA .pdf — \includegraphics lo aggiunge (relativo a versioni/)
            'manifest_path' => $newPath,               // path reale nel manifest/filetree (es. versioni/geogebra/N.pdf)
            'index'         => $nextIdx,
            'label'         => $label,
            'pdf_size'      => strlen($pdfBinary),
        ];
    }

    /**
     * Allega un PDF caricato (compilazione esterna: Overleaf, locale).
     * Cifra envelope e aggiorna la row.
     */
    public function attachPdf(int $teacherId, int $docId, string $pdfBinary, string $filename): array
    {
        $doc = $this->requireOwn($teacherId, $docId);

        if ($pdfBinary === '') {
            throw new RuntimeException('verifica_pdf_empty');
        }
        if (strlen($pdfBinary) > self::MAX_PDF_BYTES) {
            throw new RuntimeException('verifica_pdf_too_large');
        }
        if (substr($pdfBinary, 0, 5) !== '%PDF-') {
            throw new RuntimeException('verifica_pdf_invalid');
        }

        // Se gia' presente un PDF, sostituiscilo (delete vecchio blob).
        if (!empty($doc['pdf_blob_path']) && $this->store->exists($doc['pdf_blob_path'])) {
            $this->store->delete($doc['pdf_blob_path']);
        }

        $relPath = $this->store->put($teacherId, $pdfBinary, Ulid::generate());
        $kv      = $this->store->readKv($relPath);

        $this->docs->attachPdf(
            $docId,
            $relPath,
            $kv,
            strlen($pdfBinary),
            $this->sanitizeFilename($filename)
        );
        return $this->docs->find($docId) ?? throw new RuntimeException('verifica_save_failed');
    }

    /** Recupera il PDF binary decifrato (per inline preview o download). */
    public function readPdf(int $teacherId, int $docId): array
    {
        $doc = $this->requireOwn($teacherId, $docId);
        if (empty($doc['pdf_blob_path'])) {
            throw new RuntimeException('verifica_pdf_missing');
        }
        $bin = $this->store->get($teacherId, $doc['pdf_blob_path']);
        return [
            'binary'   => $bin,
            'filename' => (string)($doc['pdf_filename'] ?? ($doc['title'] . '.pdf')),
            'mime'     => self::PDF_MIME,
        ];
    }

    /**
     * Cancella la verifica + tutti i blob TEX (multi-file) e PDF.
     *
     * G22.S15.bis Fase 5 — se la row appartiene a un batch (batch_id non
     * vuoto, generato da saveBatch con N varianti A/B × {SOL,NOR,DSA,DIS}),
     * cancella TUTTE le varianti del batch in una sola operazione, perchè:
     *   1. UX: l'utente che clicca 🗑 nel sidepage si aspetta di rimuovere
     *      la verifica intera, non solo la SOL (variant rappresentativa).
     *   2. Storage Option C: i blob possono essere SHARED tra varianti
     *      (stesso sha256 → un solo blob fisico). Cancellare una sola
     *      variante romperebbe le altre (blob orfani referenziati).
     *
     * Dedup blob_paths in deletion set per evitare double-delete su blob
     * shared (safeDeleteBlob è già idempotente, ma evitiamo IO inutile).
     */
    public function deleteDoc(int $teacherId, int $docId): void
    {
        $doc = $this->requireOwn($teacherId, $docId);

        $batchId = isset($doc['batch_id']) ? (string)$doc['batch_id'] : '';
        $rows = $batchId !== ''
            ? $this->docs->listForBatch($teacherId, $batchId)
            : [$doc];

        // Defense in depth: filter cross-teacher rows (listForBatch già
        // applica WHERE teacher_id, ma cinghia di sicurezza).
        $rows = array_values(array_filter(
            $rows,
            fn($r) => is_array($r) && (int)($r['teacher_id'] ?? 0) === $teacherId
        ));
        if (!$rows) {
            // Edge: doc trovato ma listForBatch ha ritornato vuoto (race).
            // Cancella almeno il doc target.
            $rows = [$doc];
        }

        // Raccogli set unico di blob da cancellare (dedup paths).
        $blobs = [];
        foreach ($rows as $row) {
            $manifest = is_array($row['tex_files'] ?? null) ? $row['tex_files'] : [];
            foreach ($manifest as $f) {
                if (is_array($f) && !empty($f['blob_path'])) {
                    $blobs[(string)$f['blob_path']] = true;
                }
            }
            if (!empty($row['tex_blob_path'])) {
                $blobs[(string)$row['tex_blob_path']] = true;
            }
            if (!empty($row['pdf_blob_path'])) {
                $blobs[(string)$row['pdf_blob_path']] = true;
            }
        }

        // Cancella tutte le row del batch (in una transazione per atomicità).
        $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        $this->tx->run(function () use ($ids): void {
            foreach ($ids as $rid) {
                $this->docs->delete($rid);
            }
        });

        // Reap blob post-commit: se la tx fallisce, i blob restano (il caller
        // riproverà o uno script di GC li recupererà). Best-effort.
        $this->safeDeleteBlobs(array_keys($blobs));
    }

    public function listForTeacher(
        int $teacherId,
        ?string $materia = null,
        ?string $indirizzo = null,
        ?string $classe = null,
    ): array {
        return $this->docs->listForTeacher($teacherId, $materia, null, $indirizzo, $classe);
    }

    public function listMaterieForTeacher(int $teacherId): array
    {
        return $this->docs->listMaterieForTeacher($teacherId);
    }

    /**
     * G16 — Genera in batch fino a 8 varianti A/B × {SOL, NOR, DSA, DIS}.
     *
     * Replica della logica legacy script_sel-mod.js btnCopyver loop.
     * Ogni variante:
     *   - Body verifica via TexBuilder.build(sel, latexVariant)
     *     latexVariant ∈ {NORMAL, DSA, DYSLEXIC} mappato dalla VersionPicker
     *   - 4 sezioni dal pack resolver con flag adatti:
     *     * SOL → solo intestazione (no griglia/misure/footer — legacy:
     *             le solutioni vanno SENZA misure/griglie)
     *     * NOR → intestazione + griglia + misure (se flag), no footer
     *     * DSA → intestazione + griglia + misure + footer (se Compensa)
     *     * DIS → DYSLEXIC font + come DSA
     *   - Salva crypto + DB row con batch_id + variant
     *
     * @param array{
     *   teacher_id: int, materia: string, title: string,
     *   selection: array,                            payload Selection
     *   exercise_ids?: list<int>, fm_db_section?: string,
     *   template_context: array,                     placeholders
     *   tipologia?: string,
     *   compensa?: bool,                             #Compensa checkbox
     *   includeGriglia?: bool,                       #griglie
     *   includeMisure?: bool,                        #misure
     *   nPrint?: int|null, nPrintDSA?: int|null, nPrintDIS?: int|null,
     *   variants?: list<string>                      override esplicito (es. ['A_SOL','A_NOR'])
     * } $input
     *
     * @return array{batch_id: string, docs: list<array>}
     */
    public function saveBatch(array $input): array
    {
        $teacherId = (int)($input['teacher_id'] ?? 0);
        if ($teacherId <= 0) {
            throw new RuntimeException('verifica_invalid_teacher');
        }

        $materia  = trim((string)($input['materia'] ?? ''));
        $title    = trim((string)($input['title'] ?? ''));
        $selection = (array)($input['selection'] ?? []);
        $context   = (array)($input['template_context'] ?? []);

        if ($materia === '') {
            throw new RuntimeException('verifica_materia_required');
        }
        if ($title === '') {
            throw new RuntimeException('verifica_title_required');
        }

        // G19.44 — version_label utente da #versione input; pre-check conflict
        // contro (teacher, materia, title, variant, version_label). Se presente
        // e !force → throw verifica_version_conflict con context.
        $versionLabel = trim((string)($input['version_label'] ?? ''));
        $force = !empty($input['force']);

        // Determina quali varianti generare: se input['variants'] e' fornito,
        // usalo; altrimenti deriva da nPrint/nPrintDSA/nPrintDIS + dsa flag.
        $variants = self::resolveVariantsToGenerate($input);
        if ($variants === []) {
            throw new RuntimeException('verifica_no_variants_to_generate');
        }

        // G19.44 — conflict check single-pass: una sola query, decisione
        // unica (throw se !force, raccolta blob da reap se force).
        // G22.S1 — pre-fix: il branch `force` chiamava `store->delete` con
        // 2 argomenti (teacherId, path) ma la firma e' (path) single — bug
        // silenzioso che lasciava blob orfani su disco. Ora i blob sono
        // raccolti in $blobsToReap e cancellati dopo il commit della tx
        // (vedi sotto), garantendo che un rollback non perda il blob esistente.
        $existing = $this->docs->findExistingForBatch(
            $teacherId,
            $materia,
            $title,
            $variants,
            $versionLabel
        );
        if (!$force && !empty($existing)) {
            throw new RuntimeException(json_encode([
                'code'          => 'verifica_version_conflict',
                'version_label' => $versionLabel,
                'title'         => $title,
                'existing_ids'  => array_map(fn($d) => (int)$d['id'], $existing),
            ]));
        }
        $blobsToReap = [];
        if ($force) {
            foreach ($existing as $row) {
                // G22.S4.B.2 — raccolta multi-file della manifest.
                $manifest = is_array($row['tex_files'] ?? null) ? $row['tex_files'] : [];
                foreach ($manifest as $f) {
                    if (is_array($f) && !empty($f['blob_path'])) {
                        $blobsToReap[] = (string)$f['blob_path'];
                    }
                }
                // Legacy single-blob (row pre-S4.B.2).
                if (!empty($row['tex_blob_path'])) {
                    $blobsToReap[] = (string)$row['tex_blob_path'];
                }
                if (!empty($row['pdf_blob_path'])) {
                    $blobsToReap[] = (string)$row['pdf_blob_path'];
                }
            }
        }

        $batchId = Ulid::generate();
        $texBuilder = new \App\Services\TexBuilder();

        $compensa  = !empty($input['compensa']);

        $sel = \App\Services\TexBuilder\Selection::fromArray($selection);
        // G20.6 — propaga i flag InfoVer rilevanti dentro $sel->options cosi'
        // selection_json li snapshotta e selectionFromDoc (al rebuild ZIP/VSC)
        // li ritrova. Senza questo, compensa veniva persa al rebuild perche'
        // selectionFromDoc legge solo $data['options'].
        $sel->options['compensa']       = !empty($input['compensa']);
        $sel->options['dsa']            = !empty($input['dsa']);
        $sel->options['includeGriglia'] = !\array_key_exists('includeGriglia', $input) || !empty($input['includeGriglia']);
        $sel->options['includeMisure']  = !\array_key_exists('includeMisure', $input) || !empty($input['includeMisure']);

        // G22.S1 — Phase 1: build + put blob fuori dalla transazione.
        // Le operazioni costose (TexBuilder + crypto envelope encrypt + I/O)
        // non devono tenere lock DB. I blob scritti vengono tracciati in
        // $writtenBlobs per cleanup su rollback. Le row da inserire sono
        // accumulate in $preparedRows e committate atomicamente in Phase 2.
        $writtenBlobs = [];
        $preparedRows = [];
        // G22.S15.bis Fase 5 — dedup blob per sha256 nel batch.
        //   Stesso content (es. verifica.sty, intestazione, geogebra/N.pdf)
        //   replicato in N varianti → UN solo blob fisico cifrato + N
        //   manifest che puntano allo stesso blob_path. Risparmio storage
        //   significativo (≈75% per 4 varianti, più alto per immagini).
        //   Scope: per-saveBatch (cross-variant). I blob shared sono
        //   tracciati una sola volta in $writtenBlobs per evitare double
        //   delete su rollback.
        $shaToBlob = [];      // sha256 → ['blob_path'=>..., 'blob_kv'=>...]
        try {
            foreach ($variants as $vKey) {
                // vKey: 'A_SOL', 'A_NOR', 'A_DSA', 'A_DIS', 'B_SOL', 'B_NOR', 'B_DSA', 'B_DIS'
                [$ver, $type] = explode('_', $vKey, 2);
                $sel->version = $ver === 'B' ? 'B' : 'A';

                $latexVariant = match ($type) {
                    'DSA' => \App\Services\TexBuilder\VersionPicker::DSA,
                    'DIS' => \App\Services\TexBuilder\VersionPicker::DYSLEXIC,
                    default => \App\Services\TexBuilder\VersionPicker::NORMAL,
                };

                $selOptions = $sel->options;
                $sel->options['includeSolutions'] = ($type === 'SOL');

                // G22.S4.B.2 — multi-file storage nativo: cifriamo OGNI file
                // del bundle separatamente (verifica.sty, intestazione,
                // ulteriori_misure, BES_DSA, griglie, main_*, esercizi_*).
                // La row salva la `manifest tex_files` JSON con i puntatori
                // ai blob; non c'e' piu' un blob singolo monolitico.
                // G22.S9 fix — passa variant_kind esplicito ($type = SOL/NOR/
                // DSA/DIS) perche' SOL e NOR usano lo stesso latexVariant
                // (NORMAL) e senza override TexBuilder li mappava entrambi
                // a kind=NOR → bundle senza main_SOL.tex → flatten/compile
                // bundle fail "main file mancante per variant SOL".
                // G27.badge — propaga teacher_id + institute_id cosi' TexBuilder
                // (variant SOL) carica sources.registry.json e ottiene
                // BadgeRenderer attivo. institute_id derivato via TeacherContextResolver
                // (la coppia teacher↔institute e' 1:N ma per i contenuti
                // "private/{tid}" si usa il primo, allineato a ContentStudyController).
                $instituteId = \App\Support\TeacherContextResolver::firstInstituteId($teacherId);
                $build = $texBuilder->build($sel, $latexVariant, [
                    'mode'           => \App\Services\TexBuilder\BuildResult::MODE_FLAT,
                    'variant_kind'   => $type,
                    'compensa'       => $compensa,
                    'institute_name' => (string)(self::ctxOrNull($context, 'ISTITUTO_NOME') ?? ''),
                    'docente_nome'   => (string)(self::ctxOrNull($context, 'DOCENTE_NOME') ?? ''),
                    'teacher_id'     => $teacherId,
                    'institute_id'   => $instituteId,
                ]);
                $sel->options = $selOptions; // restore

                // G22.S15.bis Fase 4 — pre-process GeoGebra: scansiona
                // tutti i file per pattern `\fmgeogebra{base64}{label}`,
                // decodifica SVG → PDF vettoriale via VPS rsvg-convert,
                // salva ogni PDF come `geogebra/N.pdf` nel bundle, sostituisce
                // i marker con `\includegraphics{geogebra/N}` nel TeX.
                // Vantaggio: il TeX salvato (e scaricabile) è leggibile
                // (no base64 inline ingombrante) ed include immagini
                // come file separati nel bundle multi-file.
                $bundleFiles = $build->files;
                $endpoint = (string)\App\Core\Config::get('tex_compile.endpoint', '');
                $secret   = (string)\App\Core\Config::get('tex_compile.secret', '');
                if ($endpoint !== '' && $secret !== '') {
                    $hasGgb = false;
                    foreach ($bundleFiles as $f) {
                        if (isset($f['content']) && strpos((string)$f['content'], '\\fmgeogebra') !== false) {
                            $hasGgb = true;
                            break;
                        }
                    }
                    if ($hasGgb) {
                        try {
                            $caBundle = (string)\App\Core\Config::get('tex_compile.ca_bundle', '');
                            $svgClient = new \App\Services\TexCompile\SvgToPdfClient($endpoint, $secret, 15, $caBundle);
                            $pre = new \App\Services\GeoGebra\GeoGebraTexPreProcessor($svgClient);
                            $bundleFiles = $pre->processBundle($bundleFiles, "verifica_save_{$vKey}");
                        } catch (\Throwable $e) {
                            error_log("[geogebra-pre saveBatch] failed (best-effort): " . $e->getMessage());
                        }
                    }
                }

                // G22.S2 — sha256 della verifica = hash del .tex flattened
                // (canonical content), per cache PDF lookup. Lo calcoliamo
                // qui senza materializzare il flatten su disco. Recompute su
                // bundleFiles processato (ora con \includegraphics).
                $brProcessed = new \App\Services\TexBuilder\BuildResult(
                    files:   $bundleFiles,
                    mode:    \App\Services\TexBuilder\BuildResult::MODE_FLAT,
                    variant: $type,
                );
                $flatTex = $brProcessed->flatten();
                if (\strlen($flatTex) > self::MAX_TEX_BYTES) {
                    throw new RuntimeException('verifica_tex_too_large');
                }
                $variantSha = hash('sha256', $flatTex);
                $totalSize  = 0;

                $manifest = [];
                foreach ($bundleFiles as $f) {
                    $contentSha = hash('sha256', $f['content']);
                    // Dedup intra-batch: se un'altra variante ha già scritto
                    // un blob con lo stesso sha256, riusa blob_path/blob_kv
                    // (la TKEK del docente è la stessa, niente decifratura
                    // intermedia richiesta).
                    if (isset($shaToBlob[$contentSha])) {
                        $shared = $shaToBlob[$contentSha];
                        $manifest[] = [
                            'path'      => (string)$f['path'],
                            'blob_path' => $shared['blob_path'],
                            'blob_kv'   => $shared['blob_kv'],
                            'sha256'    => $contentSha,
                            'size'      => \strlen($f['content']),
                        ];
                        $totalSize += \strlen($f['content']);
                        continue;
                    }
                    $relPath = $this->store->put($teacherId, $f['content'], Ulid::generate());
                    $writtenBlobs[] = $relPath;
                    $blobKv  = $this->store->readKv($relPath);
                    $shaToBlob[$contentSha] = ['blob_path' => $relPath, 'blob_kv' => $blobKv];
                    $manifest[] = [
                        'path'      => (string)$f['path'],
                        'blob_path' => $relPath,
                        'blob_kv'   => $blobKv,
                        'sha256'    => $contentSha,
                        'size'      => \strlen($f['content']),
                    ];
                    $totalSize += \strlen($f['content']);
                }

                $variantTitle = $title . ' — ' . $vKey;
                $selectionJson = json_encode([
                    'verTitle' => $sel->verTitle,
                    'iis'      => $sel->iis,
                    'cls'      => $sel->cls,
                    'mater'    => $sel->mater,
                    'anno'     => $sel->anno,
                    'sezione'  => $sel->sezione,
                    'problems' => $sel->problems,
                    'options'  => $sel->options,
                    'context'  => $context,
                ], JSON_UNESCAPED_UNICODE);

                $preparedRows[] = [
                    'teacher_id'    => $teacherId,
                    'materia'       => $materia,
                    'indirizzo'     => $sel->iis,
                    'classe'        => $sel->cls,
                    'title'         => $variantTitle,
                    'fm_db_section' => $input['fm_db_section'] ?? 'VERIFICHE',
                    'batch_id'      => $batchId,
                    'variant'       => $vKey,
                    'version_label' => $versionLabel,
                    'exercise_ids'  => $input['exercise_ids'] ?? [],
                    'selection_json' => $selectionJson,
                    // G22.S4.B.2 — niente piu' tex_blob_path/kv: la manifest
                    // tex_files contiene i puntatori ai blob multipli. Le
                    // row legacy (pre-S4.B.2) restano accessibili via il
                    // path single-blob fallback in readTex/compilePdf.
                    'tex_files'     => $manifest,
                    'tex_size'      => $totalSize,
                    'tex_sha256'    => $variantSha,
                ];
            }
        } catch (Throwable $e) {
            // Phase 1 fallita (es. tex_too_large alla 5a variante): cleanup
            // dei blob gia' scritti. Niente DB tx aperta in questa fase.
            $this->safeDeleteBlobs($writtenBlobs);
            throw $e;
        }

        // G22.S1 — Phase 2: transazione DB atomica.
        //   - delete row esistenti (force) → rollback ripristina
        //   - create N nuove row → tutte o nessuna
        // Su rollback i blob nuovi (scritti in Phase 1) restano sul disco
        // e vengono cancellati dal catch sotto. I blob esistenti (force)
        // restano integri perche' il loro reap avviene SOLO post-commit.
        try {
            $existingForce = $existing;
            $createdIds = $this->tx->run(function () use ($force, $existingForce, $preparedRows): array {
                if ($force) {
                    foreach ($existingForce as $row) {
                        $this->docs->delete((int)$row['id']);
                    }
                }
                $ids = [];
                foreach ($preparedRows as $row) {
                    $ids[] = $this->docs->create($row);
                }
                return $ids;
            });
        } catch (Throwable $e) {
            $this->safeDeleteBlobs($writtenBlobs);
            throw $e;
        }

        // G22.S1 — Phase 3: post-commit reap dei blob force-replaced.
        // Eventuale fallimento qui lascia solo file orfani su disco
        // (DB gia' consistente, le row puntano ai blob nuovi). Best-effort.
        $this->safeDeleteBlobs($blobsToReap);

        $docs = [];
        foreach ($createdIds as $id) {
            $doc = $this->docs->find($id);
            if ($doc) {
                $docs[] = $doc;
            }
        }
        return ['batch_id' => $batchId, 'docs' => $docs];
    }

    /**
     * G22.S1 — Cancella un singolo blob ignorando errori (best-effort).
     * Usato nei path di rollback / post-commit reap dove l'eventuale
     * fallimento del filesystem non deve mascherare l'errore originale.
     */
    private function safeDeleteBlob(string $relPath): void
    {
        if ($relPath === '') {
            return;
        }
        try {
            if ($this->store->exists($relPath)) {
                $this->store->delete($relPath);
            }
        } catch (Throwable) {
            // Ignored: cleanup orfano non bloccante.
        }
    }

    /** @param list<string> $relPaths */
    private function safeDeleteBlobs(array $relPaths): void
    {
        foreach ($relPaths as $p) {
            $this->safeDeleteBlob($p);
        }
    }

    /**
     * Decide quali varianti generare in base a:
     *   - input['variants'] se esplicito
     *   - input['dsa'] (#DSA checkbox) abilita DSA + DIS
     *   - input['nPrint'/'nPrintDSA'/'nPrintDIS'] count copie (>0 = include)
     * Default: A_SOL + B_SOL sempre (se variants vuoto).
     *
     * @return list<string>
     */
    private static function resolveVariantsToGenerate(array $input): array
    {
        if (\is_array($input['variants'] ?? null) && $input['variants']) {
            $allowed = ['A_SOL', 'A_NOR', 'A_DSA', 'A_DIS', 'B_SOL', 'B_NOR', 'B_DSA', 'B_DIS'];
            return array_values(array_filter(
                array_map(static fn($v) => (string)$v, $input['variants']),
                static fn($v) => \in_array($v, $allowed, true)
            ));
        }
        $hasDsa = !empty($input['dsa']);
        $nNor   = (int)($input['nPrint']    ?? 0);
        $nDsa   = (int)($input['nPrintDSA'] ?? 0);
        $nDis   = (int)($input['nPrintDIS'] ?? 0);

        // G19.7 — versions iniziale dipende dallo stato dei checkbox A / R
        // del client. Frontend invia `versions: ['A']`, `['R']`, o `['A', 'R']`
        // (v. topbar-modern.buildSelectionFromDOM). Mappa R→B per back-compat
        // con la naming legacy A/B (B = Recupero).
        $versions = [];
        if (\is_array($input['versions'] ?? null)) {
            foreach ($input['versions'] as $v) {
                $u = strtoupper((string)$v);
                if ($u === 'R') {
                    $u = 'B';     // R alias di B
                }
                if (\in_array($u, ['A', 'B'], true) && !\in_array($u, $versions, true)) {
                    $versions[] = $u;
                }
            }
        }
        if (!$versions) {
            $versions = ['A', 'B']; // fallback default (back-compat G16)
        }

        $out = [];
        foreach ($versions as $ver) {
            $out[] = $ver . '_SOL';                          // SOL sempre
            if ($nNor > 0) {
                $out[] = $ver . '_NOR';
            }
            if ($hasDsa && $nDsa > 0) {
                $out[] = $ver . '_DSA';
            }
            if ($hasDsa && $nDis > 0) {
                $out[] = $ver . '_DIS';
            }
        }
        return $out;
    }

    /** Helper: ritorna $arr[$key] se non vuoto, altrimenti null. */
    private static function ctxOrNull(array $arr, string $key): ?string
    {
        $v = $arr[$key] ?? null;
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private function requireOwn(int $teacherId, int $docId): array
    {
        $doc = $this->docs->find($docId);
        if (!$doc) {
            throw new RuntimeException('verifica_not_found');
        }
        if ((int)$doc['teacher_id'] !== $teacherId) {
            throw new RuntimeException('verifica_forbidden');
        }
        return $doc;
    }

    /** G19 — wrapper pubblico ownership-checked di requireOwn(). Restituisce
     *  null se non trovato/non di proprietà (no throw) per uso defensive. */
    public function find(int $teacherId, int $docId): ?array
    {
        try {
            return $this->requireOwn($teacherId, $docId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^\w.\-]+/u', '_', $name) ?? 'verifica.pdf';
        $name = trim($name, '_.');
        if ($name === '') {
            $name = 'verifica.pdf';
        }
        if (!str_ends_with(strtolower($name), '.pdf')) {
            $name .= '.pdf';
        }
        return substr($name, 0, 200);
    }
}
