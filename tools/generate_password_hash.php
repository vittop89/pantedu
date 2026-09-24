<?php
/**
 * CLI — generate bcrypt hash for .env credentials
 * Usage: php tools/generate_password_hash.php "my-plain-password"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$pass = $argv[1] ?? null;
if (!$pass) {
    fwrite(STDERR, "Usage: php tools/generate_password_hash.php \"<plain-password>\"\n");
    exit(1);
}

echo password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]) . "\n";
