<?php

/**
 * Salvataggi cifrati dell'istanza (tools/backup/encrypted_backup.sh).
 *
 *   dir  dove lo script scrive `pantedu-backup-*.tar.gpg`: BACKUP_DIR, o
 *        `/var/backups/pantedu` se la variabile manca o è vuota — la stessa
 *        regola di `${BACKUP_DIR:-/var/backups/pantedu}` nello script.
 *
 * La leggono la diagnostica (controllo `lavori`, tools/ops/diagnostica.php) e
 * l'esercitazione sulle violazioni (tools/gdpr/breach_drill.php), che fino al
 * 23/9/2026 la prendevano da `$_ENV` con `??`: con `BACKUP_DIR=` vuota, come in
 * `.env.example`, cercavano i salvataggi alla radice del disco e davano un
 * allarme fisso (revisione architetturale del 23/9/2026, A-35; voce 71 del
 * registro del debito).
 */

declare(strict_types=1);

return [
    'dir' => \App\Core\Config::testoDallAmbiente('BACKUP_DIR', '/var/backups/pantedu'),
];
