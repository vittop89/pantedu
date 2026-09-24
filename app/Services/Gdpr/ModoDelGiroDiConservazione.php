<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

/**
 * Se il giro della conservazione (tools/gdpr/anonymize_expired.php) prova o
 * applica.
 *
 * Applica se è acceso `retention.retention_enabled` (GDPR_RETENTION_ENABLED)
 * o se c'è `--apply`. **`--dry-run` vince su tutto**, anche sulla variabile.
 *
 * 24/9/2026 — in produzione GDPR_RETENTION_ENABLED sta anche in `.env.local`,
 * non solo nell'unità systemd: lanciato a mano «senza flag», come la sua
 * intestazione chiamava la prova a secco, lo script applicava. È successo
 * durante l'allineamento del server di quella sera: la «prova» ha tolto lo
 * storico delle iscrizioni, cioè quello che il giro vero avrebbe fatto un
 * secondo dopo. Una prova a secco che dipende dall'ambiente non è una prova:
 * da allora ce n'è una che non ne dipende.
 */
final class ModoDelGiroDiConservazione
{
    /**
     * @param list<string> $argv
     */
    public static function provaASecco(bool $abilitato, array $argv): bool
    {
        if (\in_array('--dry-run', $argv, true)) {
            return true;
        }
        return !($abilitato || \in_array('--apply', $argv, true));
    }
}
