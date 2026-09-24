<?php
/**
 * Phase 25.D — Genera una nuova KMS_MASTER_KEY (32 bytes hex).
 *
 * Usage:
 *   php tools/crypto/generate_kms_key.php
 *
 * Output: hex 64-char + istruzioni per inserire in .env e backup off-line.
 *
 * SECURITY:
 *   - Generato via random_bytes() (CSPRNG: /dev/urandom o equivalente OS).
 *   - NON salva mai la chiave su disco — print to stdout una sola volta.
 *   - Dopo l'output: dev MUST copy → .env + backup off-line (Yubikey,
 *     paper BIP-39, password manager).
 *   - Ogni rigenerazione INVALIDA tutte le KEK esistenti → tutti i body
 *     cifrati diventano illeggibili. Run SOLO al setup iniziale o dopo
 *     KMS rotation completa documentata.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: questo script va eseguito solo da CLI.\n");
    exit(1);
}

$bytes = random_bytes(32);
$hex = bin2hex($bytes);

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  NEW KMS_MASTER_KEY GENERATED                                    ║\n";
echo "║  Generated at: " . date('Y-m-d H:i:s T') . str_pad('', 31) . "║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "  KMS_MASTER_KEY=" . $hex . "\n";
echo "\n";
echo "PROCEDURA:\n";
echo "\n";
echo "  1. Copia la riga sopra in .env (riga unica, no quote, no spazi):\n";
echo "       echo 'KMS_MASTER_KEY={$hex}' >> .env\n";
echo "\n";
echo "  2. Backup OFF-LINE OBBLIGATORIO (Phase 25.D11):\n";
echo "     a) Yubikey hardware token: PGP-encrypt blob della key.\n";
echo "     b) Paper backup: converti hex → BIP-39 32-word seed phrase.\n";
echo "        (https://github.com/iancoleman/bip39 offline)\n";
echo "     c) Password manager (1Password/Bitwarden) come backup secondario.\n";
echo "\n";
echo "  3. NON committare .env con la key in git public.\n";
echo "     (.gitignore note: in pantedu repo è private, ma KMS_MASTER\n";
echo "      è eccezione — usa .env.local se vuoi separazione.)\n";
echo "\n";
echo "  4. Test che funzioni:\n";
echo "       php -r 'require \"app/bootstrap.php\"; \$s = new \\App\\Services\\Crypto\\TeacherCryptoService(); echo \$s->isConfigured() ? \"OK\\n\" : \"FAIL\\n\";'\n";
echo "\n";
echo "  5. PERDITA DI QUESTA CHIAVE = perdita TUTTI i dati cifrati.\n";
echo "     Crypto-shredding di TUTTI i docenti contemporaneamente.\n";
echo "     Tieni il backup OFF-LINE come una credenziale critica.\n";
echo "\n";
