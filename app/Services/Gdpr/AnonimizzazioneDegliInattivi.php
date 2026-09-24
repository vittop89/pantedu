<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Database;
use App\Support\RetentionSql;
use DateInterval;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Gli account inattivi oltre il termine (730 giorni per difetto,
 * `retention.inactive_account_days`) si cancellano con la stessa routine
 * dell'art. 17: CancellazioneDellAccount (24/9/2026, rilievo DOC-18).
 *
 * Chi è «inattivo» lo decide RetentionSql::INATTIVI_WHERE, e solo lei: la
 * condizione e le sue quattro prove stanno lì e in
 * tests/Unit/Support/RetentionInattiviTest.php.
 *
 * Fino al 24/9/2026 tools/gdpr/anonymize_expired.php faceva un solo UPDATE su
 * `users` (email, nome, cognome, password, `active`, `status`): niente
 * distruzione della chiave, niente contenuti, e il nome utente restava.
 * L'informativa prometteva il crypto-shredding.
 *
 * Un account per volta, ognuno con la sua transazione: un errore su uno non
 * ferma gli altri, e finisce fra gli errori (lo script esce con 1, e il timer
 * lo segnala).
 */
final class AnonimizzazioneDegliInattivi
{
    /** Il motivo scritto nel registro della cifratura accanto alla distruzione della chiave. */
    public const MOTIVO = 'retention_730_giorni';

    private ?PDO $pdo;
    private ?CancellazioneDellAccount $cancellazione;
    /** @var (\Closure(): ?\App\Services\Mailer)|null */
    private ?\Closure $posta;

    /**
     * @param (\Closure(): ?\App\Services\Mailer)|null $posta il mittente degli avvisi (le prove ne passano uno finto)
     */
    public function __construct(
        ?PDO $pdo = null,
        ?CancellazioneDellAccount $cancellazione = null,
        ?\Closure $posta = null,
    ) {
        $this->pdo = $pdo;
        $this->cancellazione = $cancellazione;
        $this->posta = $posta;
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Gli id degli account inattivi da più di `$giorni` giorni rispetto a
     * `$ora`, in ordine.
     *
     * @return list<int>
     */
    public function inattivi(DateTimeImmutable $ora, int $giorni): array
    {
        $limite = $ora->sub(new DateInterval('P' . max(1, $giorni) . 'D'))->format('Y-m-d H:i:s');
        $st = $this->db()->prepare('SELECT id FROM users WHERE ' . RetentionSql::INATTIVI_WHERE . ' ORDER BY id');
        $st->execute([$limite, $limite, $limite]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Manda gli avvisi dovuti e cancella gli account per cui l'ultimo avviso è
     * partito da almeno 7 giorni (AvvisiDiInattivita); con `$prova` dice
     * soltanto che cosa farebbe.
     *
     * 24/9/2026 — prima si cancellava chiunque fosse inattivo oltre il
     * termine, senza avvisi e senza escludere gli amministratori.
     *
     * Un avviso che non parte non fa fallire il giro: diventa un'anomalia, e
     * l'account non si cancella. Fallisce il giro, invece, una cancellazione
     * non riuscita (`errori`).
     *
     * @return array{
     *   trovati: list<int>,
     *   avvisi: list<array{id:int, fase:int, data:string}>,
     *   avvisi_non_partiti: list<string>,
     *   cancellati: int,
     *   errori: list<string>
     * }
     */
    public function esegui(DateTimeImmutable $ora, int $giorni, bool $prova): array
    {
        $giro = (new AvvisiDiInattivita($this->db(), $this->posta, $giorni))->giro($ora, $prova);
        $trovati = $giro['da_cancellare'];
        $esito = [
            'trovati' => $trovati,
            'avvisi' => $giro['avvisi'],
            'avvisi_non_partiti' => $giro['errori'],
            'cancellati' => 0,
            'errori' => [],
        ];
        if ($prova) {
            return $esito;
        }
        $cancellazione = $this->cancellazione ?? new CancellazioneDellAccount($this->pdo);
        foreach ($trovati as $id) {
            try {
                $cancellazione->esegui($id, self::MOTIVO);
                $esito['cancellati']++;
            } catch (Throwable $e) {
                $esito['errori'][] = "user_id={$id}: " . $e->getMessage();
            }
        }
        return $esito;
    }
}
