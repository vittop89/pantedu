<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\InstituteRepository;
use App\Services\Verifica\TemplateFileStore;
use Throwable;

/**
 * G20.0 Phase 9 — Admin Files API per editor template verifiche.
 *
 * Endpoint:
 *   GET    /api/admin/verifica/files?scope={code}              — list file
 *   GET    /api/admin/verifica/files/{path}?scope={code}       — read raw
 *   POST   /api/admin/verifica/files/{path}?scope={code}       — write override
 *   DELETE /api/admin/verifica/files/{path}?scope={code}       — rimuove override
 *   POST   /api/admin/verifica/files/{path}/copy-from-default?scope={code}
 *   GET    /api/admin/verifica/scopes                          — list scope disponibili
 *
 * Permessi:
 *   _default: solo super_admin
 *   {institute_code}: super_admin O admin con teacher_institutes.role_at_inst='admin'
 */
final class VerificaFilesAdminController
{
    public function listScopes(Request $req): Response
    {
        if (!$this->guardSuper()) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $repo = new InstituteRepository();
        $institutes = $repo->listActive();
        return Response::json([
            'ok'     => true,
            'scopes' => array_merge(
                [['code' => '_default', 'label' => 'Tutti gli istituti (modello comune)']],
                array_map(fn($i) => [
                    'code'  => $i['code'],
                    'label' => trim((string)($i['header_label'] ?? '')) !== ''
                              ? (string)$i['header_label']
                              : (string)$i['name'],
                ], $institutes),
            ),
        ]);
    }

    public function listFiles(Request $req): Response
    {
        $scope = $this->extractScope($req);
        if (!$this->guardScope($scope)) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        try {
            return Response::json([
                'ok'    => true,
                'scope' => $scope,
                'files' => TemplateFileStore::list($scope),
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function readFile(Request $req): Response
    {
        $scope = $this->extractScope($req);
        if (!$this->guardScope($scope)) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $path = (string)($req->query['path'] ?? '');
        try {
            // Cascade content: scope (override) → _default
            $current = TemplateFileStore::read($scope, $path);
            $defaultContent = TemplateFileStore::read(TemplateFileStore::SCOPE_DEFAULT, $path);
            $isOverride = TemplateFileStore::readRaw($scope, $path) !== null;
            return Response::json([
                'ok'          => true,
                'scope'       => $scope,
                'path'        => $path,
                'content'     => $current,
                'default'     => $defaultContent,
                'is_override' => $isOverride,
                'has_default' => $defaultContent !== null,
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function writeFile(Request $req): Response
    {
        $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $scope   = (string)($payload['scope'] ?? $req->query['scope'] ?? TemplateFileStore::SCOPE_DEFAULT);
        if (!$this->guardScope($scope)) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $path    = (string)($payload['path'] ?? '');
        $content = (string)($payload['content'] ?? '');
        try {
            TemplateFileStore::write($scope, $path, $content);
            return Response::json(['ok' => true, 'bytes' => strlen($content)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function deleteFile(Request $req): Response
    {
        $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $scope   = (string)($payload['scope'] ?? $req->query['scope'] ?? TemplateFileStore::SCOPE_DEFAULT);
        if (!$this->guardScope($scope)) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        if ($scope === TemplateFileStore::SCOPE_DEFAULT) {
            return Response::json(['ok' => false, 'error' => 'cannot_delete_default'], 422);
        }
        $path = (string)($payload['path'] ?? '');
        try {
            $deleted = TemplateFileStore::delete($scope, $path);
            return Response::json(['ok' => true, 'deleted' => $deleted]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function copyFromDefault(Request $req): Response
    {
        $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $scope   = (string)($payload['scope'] ?? $req->query['scope'] ?? TemplateFileStore::SCOPE_DEFAULT);
        if (!$this->guardScope($scope)) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        if ($scope === TemplateFileStore::SCOPE_DEFAULT) {
            return Response::json(['ok' => false, 'error' => 'already_default'], 422);
        }
        $path = (string)($payload['path'] ?? '');
        try {
            $content = TemplateFileStore::read(TemplateFileStore::SCOPE_DEFAULT, $path);
            if ($content === null) {
                return Response::json(['ok' => false, 'error' => 'default_missing'], 404);
            }
            TemplateFileStore::write($scope, $path, $content);
            return Response::json(['ok' => true, 'bytes' => strlen($content)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    private function extractScope(Request $req): string
    {
        $scope = (string)($req->query['scope'] ?? TemplateFileStore::SCOPE_DEFAULT);
        return $scope !== '' ? $scope : TemplateFileStore::SCOPE_DEFAULT;
    }

    private function guardSuper(): bool
    {
        return Auth::check() && Auth::isSuperAdmin();
    }

    private function guardScope(string $scope): bool
    {
        if (!Auth::check()) {
            return false;
        }
        if (Auth::isSuperAdmin()) {
            return true;
        }
        if ($scope === TemplateFileStore::SCOPE_DEFAULT) {
            return false;
        }
        // Admin di istituto: super_admin OR teacher_institutes.role_at_inst='admin'
        if (!Auth::hasAccess('admin')) {
            return false;
        }
        try {
            $u = Auth::user();
            $stmt = Database::connection()->prepare(
                'SELECT COUNT(*) FROM teacher_institutes ti
                 JOIN institutes i ON i.id = ti.institute_id
                 WHERE ti.user_id = (SELECT id FROM users WHERE username = ? LIMIT 1)
                   AND i.code = ?
                   AND ti.role_at_inst = ?'
            );
            $stmt->execute([(string)($u['username'] ?? ''), $scope, 'admin']);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
