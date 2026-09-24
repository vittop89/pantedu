<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\CorpoNonValido;
use App\Core\Request;
use App\Core\Response;
use App\Services\PrintInfoService;
use Throwable;

/**
 * Phase G9 — REST API moderna per print_info.
 *
 *   GET    /api/teacher/print-info?indirizzo=X&classe=Y&materia=Z  → load
 *   POST   /api/teacher/print-info                                  → save
 *   POST   /api/teacher/print-info/delete                           → delete
 *   GET    /api/teacher/print-info/list                             → list teacher
 *
 * Sostituisce gradualmente l'endpoint legacy `/manage_print_info.php`
 * (VerificheController::managePrintInfo) che usa shape `{success, data,
 * message}` poco ergonomica. La nuova API usa `{ok, data, error}` come
 * il resto della Phase G8 (VerificaController, MapsController).
 *
 * Il legacy `/manage_print_info.php` resta wired in routes/web.php per
 * back-compat finche' `infoVer` legacy frontend non viene refattorizzato
 * a usare i nuovi endpoint.
 */
final class PrintInfoController
{
    /** Le informazioni di stampa sono poche righe: 64 KB bastano. */
    private const LIMITE_CORPO = 64 * 1024;

    public function __construct(private readonly PrintInfoService $svc = new PrintInfoService())
    {
    }

    public function show(Request $req): Response
    {
        try {
            $username = $this->username();
            $data = $this->svc->load($username, $req->query);
            return Response::json([
                'ok'   => true,
                'data' => $data,
                'found' => $data !== null,
            ], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function save(Request $req): Response
    {
        try {
            $username = $this->username();
            $body = $this->corpo($req);
            $result = $this->svc->save($username, $body);
            return Response::json(['ok' => true, ...$result], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function delete(Request $req): Response
    {
        try {
            $username = $this->username();
            $body = $this->corpo($req);
            $deleted = $this->svc->delete($username, $body);
            return Response::json(['ok' => true, 'deleted' => $deleted], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function index(Request $req): Response
    {
        try {
            $username = $this->username();
            $rows = $this->svc->listForUser($username);
            return Response::json(['ok' => true, 'items' => $rows, 'count' => \count($rows)], 200);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], $this->statusFor($e));
        }
    }

    // ─────── helpers ───────

    private function username(): string
    {
        // G22.S15.bis Fase 5+ — delegate role check + username retrieval.
        return \App\Support\AuthHelpers::teacherUsernameOrThrow();
    }

    /**
     * Il corpo, JSON o form (compat con managePrintInfo legacy), dal lettore
     * comune di ADR-034 con il suo limite. Fino al 23/9/2026 qui c'era una
     * copia di `readJsonBody()` (A-52): la risposta a un corpo troppo grande
     * la decide adesso CorpoNonValido, 413, come per ogni altra rotta.
     *
     * @return array<mixed>
     */
    private function corpo(Request $req): array
    {
        if (!$req->isJson() && $req->post !== []) {
            return $req->body(self::LIMITE_CORPO);
        }
        return $req->jsonObbligatorio(self::LIMITE_CORPO);
    }

    private function statusFor(Throwable $e): int
    {
        if ($e instanceof CorpoNonValido) {
            return $e->stato();
        }
        $msg = $e->getMessage();
        return match (true) {
            $msg === 'unauthenticated' => 401,
            $msg === 'forbidden' => 403,
            str_starts_with($msg, 'print_info_missing_field') => 422,
            default => 400,
        };
    }
}
