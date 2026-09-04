<?php

namespace App\Services;

/**
 * Read-only access to the legacy block lists.
 * Phase 2a bridges to existing /log/data/*.json files.
 * Write operations remain in legacy security admin panels (ported in Phase 2e).
 */
final class BlockList
{
    public function __construct(
        private readonly string $blockedCredentialsPath,
        private readonly string $blockedIpsPath,
    ) {
    }

    public function isUsernameBlocked(string $username): bool
    {
        foreach ($this->read($this->blockedCredentialsPath) as $row) {
            if (($row['username'] ?? null) === $username) {
                return true;
            }
        }
        return false;
    }

    public function isIpBlockedForSection(string $ip, string $section): bool
    {
        foreach ($this->read($this->blockedIpsPath) as $row) {
            if (($row['ip'] ?? null) === $ip && ($row['section'] ?? null) === $section) {
                return true;
            }
        }
        return false;
    }

    private function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }
}
