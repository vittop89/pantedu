<?php

namespace App\Services;

use App\Repositories\UserRepository;
use RuntimeException;

/**
 * Service per endpoint "check" (password admin, file protection pattern).
 */
final class CheckService
{
    /** Ritorna true se la password matcha un admin attivo (DB-only). */
    public function verifyAdminPassword(string $password): bool
    {
        if ($password === '') {
            return false;
        }
        foreach ((new UserRepository())->all() as $user) {
            if ($user->isAdmin() && $user->active && $user->verifyPassword($password)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rileva la presenza dell'include AuthCode.php in un file eser/verifica.
     * @return array{isProtected:bool, reason:string, fileUrl:string}
     */
    public function isFileProtected(string $fileUrl): array
    {
        if ($fileUrl === '') {
            throw new RuntimeException('fileUrl_missing');
        }
        $filePath = $_SERVER['DOCUMENT_ROOT'] . $fileUrl;
        if (!is_file($filePath)) {
            throw new RuntimeException('file_not_found');
        }
        $content = @file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException('read_failed');
        }
        $patterns = [
            "include_once \$_SERVER['DOCUMENT_ROOT'] . '/log/auth/AuthCode.php'",
            'include_once $_SERVER["DOCUMENT_ROOT"] . "/log/auth/AuthCode.php"',
        ];
        $has = false;
        foreach ($patterns as $p) {
            if (str_contains($content, $p)) {
                $has = true;
                break;
            }
        }
        return [
            'isProtected' => $has,
            'reason'      => $has ? 'Include AuthCode.php presente nel codice sorgente' : 'Nessun include AuthCode.php trovato',
            'fileUrl'     => $fileUrl,
        ];
    }
}
