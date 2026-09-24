<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\CorpoNonValido;
use App\Core\Request;
use App\Core\Response;
use App\Services\FileService;
use App\Services\OwnershipService;
use App\Services\RateLimiter;
use App\Services\TexBuilder;
use App\Services\TexBuilder\Selection;
use App\Services\TexBuilder\VersionPicker;
use Throwable;

/**
 * Endpoint admin per stampa TeX di singole verifiche o batch multi-variant.
 *
 * POST /admin/print
 *   body single: Selection JSON → .tex (come teacher)
 *
 * POST /admin/print/batch
 *   body: Selection JSON senza `variant` → genera tutte e 3 le varianti
 *   (normal, dsa, dyslexic) in un .zip in-memory
 *
 * Same TexBuilder del teacher — stessa output guarantee.
 * Salva sotto storage/temp/admin/tex/{stamp}_{sec}_{title}_{variant}.tex
 */
final class AdminPrintController
{
    private TexBuilder $tex;
    private FileService $files;
    private OwnershipService $owners;

    public function __construct(
        ?TexBuilder $tex = null,
        ?FileService $files = null,
        ?OwnershipService $owners = null,
    ) {
        $this->tex    = $tex    ?? new TexBuilder();
        $this->files  = $files  ?? new FileService();
        $this->owners = $owners ?? new OwnershipService(
            Config::get('app.paths.storage') . '/data/ownership.json'
        );
    }

    /** POST /admin/print — variante singola */
    public function generate(Request $req): Response
    {
        try {
            $actor   = $this->requireAdmin();
            $limiter = $this->limiter($actor);
            if ($limiter->isBlocked()) {
                return Response::json([
                    'ok' => false, 'error' => 'rate_limited',
                    'retry_after' => $limiter->remainingSeconds(),
                ], 429);
            }
            $limiter->hit();

            $payload = $this->corpo($req);
            $sel     = Selection::fromArray($payload);
            $variant = $payload['variant'] ?? VersionPicker::NORMAL;
            // G22.S4 — buildFlat ritorna il .tex monolitico self-contained
            // (vecchio path build() ritorna BuildResult multi-file).
            $tex     = $this->tex->buildFlat($sel, (string)$variant);

            $base    = $this->safeBasename($sel, (string)$variant);
            $rel     = "admin/tex/$base";
            $this->files->save('temp', $rel, $tex, 'tex');
            $this->owners->assign($actor, 'verifiche', '/' . $rel);

            return new Response(
                body: $tex,
                status: 200,
                headers: [
                    'Content-Type'        => 'application/x-tex; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $base . '"',
                    'X-Saved-Path'        => '/' . $rel,
                    'Cache-Control'       => 'no-store',
                ],
            );
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], CorpoNonValido::statoPer($e, 400));
        }
    }

    /** POST /admin/print/batch — tutte e 3 le varianti in un ZIP */
    public function batch(Request $req): Response
    {
        try {
            $actor   = $this->requireAdmin();
            $limiter = $this->limiter($actor);
            if ($limiter->isBlocked()) {
                return Response::json([
                    'ok' => false, 'error' => 'rate_limited',
                    'retry_after' => $limiter->remainingSeconds(),
                ], 429);
            }
            $limiter->hit();

            $payload = $this->corpo($req);
            $sel     = Selection::fromArray($payload);
            $outputs = $this->tex->buildAll($sel);

            if (!\class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('zip_extension_unavailable');
            }
            $zipPath = tempnam(sys_get_temp_dir(), 'fmbatch_') . '.zip';
            $zip = new \ZipArchive();
            $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            foreach ($outputs as $variant => $tex) {
                $name = $this->safeBasename($sel, $variant);
                $zip->addFromString($name, $tex);
                $rel = "admin/tex/$name";
                $this->files->save('temp', $rel, $tex, 'tex');
                $this->owners->assign($actor, 'verifiche', '/' . $rel);
            }
            $zip->close();

            $bytes = file_get_contents($zipPath);
            @unlink($zipPath);

            $zipName = $this->zipName($sel);
            return new Response(
                body: $bytes,
                status: 200,
                headers: [
                    'Content-Type'        => 'application/zip',
                    'Content-Disposition' => 'attachment; filename="' . $zipName . '"',
                    'Cache-Control'       => 'no-store',
                ],
            );
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], CorpoNonValido::statoPer($e, 400));
        }
    }

    // ──────────── helpers ────────────

    private function requireAdmin(): string
    {
        $user = Auth::user();
        if (!$user || empty($user['username']) || !Auth::hasAccess('admin')) {
            throw new \RuntimeException('forbidden');
        }
        return (string)$user['username'];
    }

    private function limiter(string $actor): RateLimiter
    {
        return new RateLimiter(
            key: "admin_print:$actor",
            maxAttempts: 20,   // admin più permissivo di teacher (10)
            lockoutSeconds: 300,
        );
    }

    /**
     * La selezione, come in TeacherPrintController: il corpo JSON dal lettore
     * comune, o il campo `selection` di un form multipart, entro 2 MB. Fino al
     * 23/9/2026 era `readBody()`, un'altra copia (A-52).
     *
     * @return array<mixed>
     */
    private function corpo(Request $req): array
    {
        if ($req->rawBody() === '' && isset($req->post['selection'])) {
            return Request::decodificaJson((string)$req->post['selection'], 2 * 1024 * 1024);
        }
        return $req->jsonObbligatorio(2 * 1024 * 1024);
    }

    private function safeBasename(Selection $sel, string $variant): string
    {
        $title = preg_replace('#[^A-Za-z0-9_\-]+#', '_', $sel->verTitle) ?: 'verifica';
        $title = trim($title, '_') ?: 'verifica';
        $ulid  = \App\Support\Ulid::generate();
        $tag   = strtolower($variant);
        $sec   = preg_replace('#[^A-Za-z0-9]+#', '', $sel->sectionCode());
        return "{$ulid}_{$sec}_{$title}_{$tag}.tex";
    }

    private function zipName(Selection $sel): string
    {
        $title = preg_replace('#[^A-Za-z0-9_\-]+#', '_', $sel->verTitle) ?: 'verifica';
        $stamp = date('Ymd_His');
        return "{$stamp}_{$title}_all_variants.zip";
    }
}
