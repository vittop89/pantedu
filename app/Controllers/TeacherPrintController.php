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
 * Endpoint POST /teacher/print
 * Body: JSON Selection (vedi App\Services\TexBuilder\Selection).
 * Risposta: file .tex con Content-Disposition: attachment.
 *
 * Sicurezza:
 *  - Middleware: auth + role:teacher + csrf + log (vedi routes/web.php)
 *  - Rate-limit: 10 richieste / 5 min per sessione (chiave dedicata)
 *  - File salvato sotto storage/data/teachers/{username}/tex/<basename>.tex
 *    e registrato in OwnershipService kind="verifiche".
 */
final class TeacherPrintController
{
    /** Una selezione di esercizi: 2 MB, come prima (A-52). */
    private const LIMITE_CORPO = 2 * 1024 * 1024;

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

    public function generate(Request $req): Response
    {
        try {
            $user = Auth::user();
            if (!$user || empty($user['username'])) {
                return Response::fail('unauthenticated', 401);
            }
            $username = (string)$user['username'];

            $limiter = new RateLimiter(
                key: "teacher_print:$username",
                maxAttempts: 10,
                lockoutSeconds: 300,
            );
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
            // G22.S4 — buildFlat ritorna .tex monolitico self-contained
            // (include latexindent pass-through via VPS, vedi TexBuilder).
            // G27.badge — propaga teacher_id+institute_id per BadgeRenderer (SOL).
            $teacherId   = \App\Support\TeacherContextResolver::userIdFromUsername($username);
            $instituteId = \App\Support\TeacherContextResolver::privateFilesInstituteId($teacherId);
            $tex     = $this->tex->buildFlat($sel, (string)$variant, [
                'teacher_id'   => $teacherId,
                'institute_id' => $instituteId,
            ]);

            $basename = $this->safeBasename($sel, (string)$variant);
            $relative = "teachers/$username/tex/$basename";

            $this->files->save('temp', $relative, $tex, 'tex');
            $this->owners->assign($username, 'verifiche', '/' . $relative);
            $this->saveToDb($username, $sel->verTitle, (string)$variant, $basename, $tex);
            // Nota: NON facciamo limiter->reset() — ogni richiesta consuma
            // lo slot, anche se va a buon fine. È il comportamento atteso
            // del rate limit per evitare loop di stampa massiva.

            return new Response(
                body: $tex,
                status: 200,
                headers: [
                    'Content-Type'        => 'application/x-tex; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $basename . '"',
                    'X-Saved-Path'        => '/' . $relative,
                    'Cache-Control'       => 'no-store',
                ],
            );
        } catch (Throwable $e) {
            // Un corpo troppo grande è 413, non 400 (A-52): prima questa rotta
            // rispondeva 400 a tutto, e PrintInfo 413 allo stesso errore.
            return Response::json(['ok' => false, 'error' => $e->getMessage()], CorpoNonValido::statoPer($e, 400));
        }
    }

    /**
     * La selezione: il corpo JSON dal lettore comune di ADR-034, o il campo
     * `selection` di un form multipart (lì il corpo grezzo è vuoto), con la
     * stessa regola e lo stesso limite. Fino al 23/9/2026 era una copia di
     * `readJsonBody()` (A-52).
     *
     * @return array<mixed>
     */
    private function corpo(Request $req): array
    {
        if ($req->rawBody() === '' && isset($req->post['selection'])) {
            return Request::decodificaJson((string)$req->post['selection'], self::LIMITE_CORPO);
        }
        return $req->jsonObbligatorio(self::LIMITE_CORPO);
    }

    /**
     * G22.S15.bis Fase 5+ — saveToDb no-op: la persistenza M11 in
     * teacher_verifiche e' deprecated. Il flow moderno (topbar
     * /api/verifica/save-tex) salva direttamente in teacher_content
     * + verifica_documents. Stub mantenuto per non rompere il flow
     * principale (generate continua a ritornare il TeX).
     */
    private function saveToDb(string $username, string $title, string $variant, string $filename, string $tex): void
    {
        // No-op: tabella teacher_verifiche in drop via migration 038.
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
}
