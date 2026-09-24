<?php

/**
 * Lancia tools/crypto/rotate_kek.php vero dentro PotaturaDelleVersioniTest.
 *
 * Non è una prova (il nome non finisce con Test.php): è il processo figlio che
 * la prova avvia. Fa quello che farebbe il manutentore, con tre differenze:
 *
 *   - il database è quello delle prove (la prova passa APP_ENV=testing);
 *   - la chiave master è quella generata dalla prova (PROVA_KMS_MASTER_KEY),
 *     non quella di sviluppo: le righe della prova sono avvolte con lei;
 *   - i due registri append-only, crypto_access_log e teacher_recovery_audit,
 *     sono coperti da tabelle TEMPORANEE sulla connessione che lo script userà
 *     (Database::connection() è una sola per processo): le righe che lo script
 *     scrive lì spariscono con il processo.
 *
 * Il bootstrap si carica qui, prima dello script, per poter sostituire la
 * chiave e creare le tabelle; il require_once dello script poi non lo ripete.
 */

declare(strict_types=1);

require __DIR__ . '/../../../app/bootstrap.php';

$chiave = getenv('PROVA_KMS_MASTER_KEY');
if (!is_string($chiave) || $chiave === '') {
    fwrite(STDERR, "manca PROVA_KMS_MASTER_KEY\n");
    exit(90);
}
$_ENV['KMS_MASTER_KEY'] = $chiave;

$pdo = App\Core\Database::connection();
foreach (['crypto_access_log', 'teacher_recovery_audit'] as $registro) {
    $pdo->exec("CREATE TEMPORARY TABLE zz_ombra_$registro LIKE $registro");
    $pdo->exec("ALTER TABLE zz_ombra_$registro RENAME TO $registro");
}

$argv = array_merge([__DIR__ . '/../../../tools/crypto/rotate_kek.php'], array_slice($argv, 1));
$argc = count($argv);
require __DIR__ . '/../../../tools/crypto/rotate_kek.php';
