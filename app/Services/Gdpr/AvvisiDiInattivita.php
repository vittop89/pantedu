<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Config;
use App\Services\Mailer;
use App\Support\Anomalia;
use App\Support\IndirizzoPubblico;
use App\Support\IndirizzoPubblicoMancante;
use DateInterval;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Gli avvisi via email prima della cancellazione di un account inattivo
 * (24/9/2026, decisione del titolare).
 *
 * Un account senza accessi da `retention.inactive_account_days` giorni (730)
 * si cancella con la routine dell'art. 17. Prima riceve tre avvisi: 60, 30 e 7
 * giorni prima, ciascuno con la data esatta; basta un accesso per azzerare il
 * conto, perché la data si calcola dall'ultima attività.
 *
 * LE REGOLE
 *
 *   - Ultima attività: l'ultimo accesso; se non c'è, l'approvazione; se non
 *     c'è, l'iscrizione. È la stessa lettura di RetentionSql::INATTIVI_WHERE.
 *   - Data di cancellazione: ultima attività + il termine.
 *   - Ogni avviso parte una volta sola per quella data (tabella
 *     `avvisi_di_inattivita`, vincolo unico). Se il lavoro salta qualche
 *     notte, parte l'avviso dovuto più avanzato, non tutti quelli persi.
 *   - Regola dura: **nessun account si cancella se l'avviso dei 7 giorni non è
 *     partito almeno 7 giorni prima.** Se è partito in ritardo, la
 *     cancellazione slitta; e l'avviso dice la data vera.
 *   - Se l'email non parte, o l'account non ha un indirizzo, niente
 *     cancellazione e un'anomalia: il titolare lo vede nella diagnostica, e il
 *     giro dopo ci riprova.
 *   - Mai toccati gli account di amministrazione della piattaforma
 *     (super-amministratore e ruolo `administrator`).
 */
final class AvvisiDiInattivita
{
    /** Giorni prima della cancellazione in cui parte ciascun avviso, dal primo all'ultimo. */
    public const FASI = [60, 30, 7];

    /** L'ultimo avviso deve essere partito almeno da tanti giorni. */
    public const ULTIMO_AVVISO_GIORNI = 7;

    public const ANOMALIA = 'avviso_inattivita_non_partito';

    private PDO $pdo;
    /** @var \Closure(): ?Mailer */
    private \Closure $posta;
    private int $giorni;

    /**
     * @param (\Closure(): ?Mailer)|null $posta
     */
    public function __construct(PDO $pdo, ?\Closure $posta = null, ?int $giorni = null)
    {
        $this->pdo = $pdo;
        $this->posta = $posta ?? static fn(): ?Mailer => Mailer::fromConfig();
        $this->giorni = max(1, $giorni ?? (int)Config::get('retention.inactive_account_days', 730));
    }

    /**
     * Manda gli avvisi dovuti e dice quali account si possono cancellare.
     * Con `$prova` non manda niente e non scrive niente: dice che cosa farebbe.
     *
     * @return array{
     *   avvisi: list<array{id:int, fase:int, data:string}>,
     *   da_cancellare: list<int>,
     *   errori: list<string>
     * }
     */
    public function giro(DateTimeImmutable $ora, bool $prova): array
    {
        $esito = ['avvisi' => [], 'da_cancellare' => [], 'errori' => []];
        foreach ($this->candidati($ora) as $u) {
            $id = (int)$u['id'];
            $cancellazione = $this->dataDiCancellazione((string)$u['attivita']);
            $inviati = $this->inviati($id, $cancellazione);

            if ($this->siPuoCancellare($ora, $cancellazione, $inviati)) {
                $esito['da_cancellare'][] = $id;
                continue;
            }

            $fase = $this->faseDovuta($ora, $cancellazione, $inviati);
            if ($fase === null) {
                continue;
            }
            // La data che l'avviso dice: quella vera, anche se l'ultimo avviso
            // parte in ritardo (la cancellazione aspetta 7 giorni da lui).
            $quando = $cancellazione;
            if ($fase === self::ULTIMO_AVVISO_GIORNI) {
                $minimo = $ora->add(new DateInterval('P' . self::ULTIMO_AVVISO_GIORNI . 'D'));
                if ($minimo > $quando) {
                    $quando = $minimo;
                }
            }
            $esito['avvisi'][] = ['id' => $id, 'fase' => $fase, 'data' => $quando->format('Y-m-d')];
            if ($prova) {
                continue;
            }

            $problema = $this->manda($u, $fase, $quando);
            if ($problema !== null) {
                $esito['errori'][] = "user_id={$id}: avviso dei {$fase} giorni non partito ({$problema})";
                Anomalia::registra(
                    self::ANOMALIA,
                    "L'avviso di cancellazione per inattività non è partito: l'account non si cancella "
                    . 'finché un avviso dei 7 giorni non parte e non passano 7 giorni.',
                    ['utente' => $id, 'fase' => $fase, 'motivo' => $problema],
                    self::ANOMALIA . ':' . $id,
                );
                continue;
            }
            $this->pdo->prepare(
                'INSERT IGNORE INTO avvisi_di_inattivita (user_id, data_cancellazione, fase, inviato_at)
                 VALUES (?, ?, ?, ?)'
            )->execute([$id, $cancellazione->format('Y-m-d H:i:s'), $fase, $ora->format('Y-m-d H:i:s')]);
        }
        return $esito;
    }

    /**
     * Gli account che il primo avviso può già riguardare: attività più vecchia
     * del termine meno 60 giorni. Mai gli amministratori della piattaforma.
     *
     * @return list<array<string,mixed>>
     */
    private function candidati(DateTimeImmutable $ora): array
    {
        $limite = $ora->sub(new DateInterval('P' . max(0, $this->giorni - self::FASI[0]) . 'D'));
        $st = $this->pdo->prepare(
            "SELECT id, email, first_name,
                    COALESCE(last_access_at, approved_at, created_at) AS attivita
               FROM users
              WHERE status <> 'anonymized'
                AND COALESCE(is_super_admin, 0) = 0
                AND role <> 'administrator'
                AND COALESCE(last_access_at, approved_at, created_at) < ?
              ORDER BY id"
        );
        $st->execute([$limite->format('Y-m-d H:i:s')]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function dataDiCancellazione(string $attivita): DateTimeImmutable
    {
        return (new DateTimeImmutable($attivita))->add(new DateInterval('P' . $this->giorni . 'D'));
    }

    /** @return array<int, DateTimeImmutable> fase => quando è partito */
    private function inviati(int $id, DateTimeImmutable $cancellazione): array
    {
        $st = $this->pdo->prepare(
            'SELECT fase, inviato_at FROM avvisi_di_inattivita WHERE user_id = ? AND data_cancellazione = ?'
        );
        $st->execute([$id, $cancellazione->format('Y-m-d H:i:s')]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['fase']] = new DateTimeImmutable((string)$r['inviato_at']);
        }
        return $out;
    }

    /** @param array<int, DateTimeImmutable> $inviati */
    private function siPuoCancellare(DateTimeImmutable $ora, DateTimeImmutable $cancellazione, array $inviati): bool
    {
        $ultimo = $inviati[self::ULTIMO_AVVISO_GIORNI] ?? null;
        if ($ultimo === null || $ora < $cancellazione) {
            return false;
        }
        return $ora >= $ultimo->add(new DateInterval('P' . self::ULTIMO_AVVISO_GIORNI . 'D'));
    }

    /**
     * L'avviso da mandare adesso: il più avanzato fra quelli dovuti e non
     * ancora partiti, o nessuno.
     *
     * @param array<int, DateTimeImmutable> $inviati
     */
    private function faseDovuta(DateTimeImmutable $ora, DateTimeImmutable $cancellazione, array $inviati): ?int
    {
        $mancanti = ($cancellazione->getTimestamp() - $ora->getTimestamp()) / 86400;
        $fasi = self::FASI;
        sort($fasi); // dalla più avanzata (7) alla prima (60)
        foreach ($fasi as $fase) {
            if ($mancanti <= $fase) {
                return isset($inviati[$fase]) ? null : $fase;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $u
     * @return string|null il problema, o null se l'email è partita
     */
    private function manda(array $u, int $fase, DateTimeImmutable $quando): ?string
    {
        $indirizzo = trim((string)($u['email'] ?? ''));
        if ($indirizzo === '' || !filter_var($indirizzo, FILTER_VALIDATE_EMAIL)) {
            return 'account senza un indirizzo valido';
        }
        try {
            $mailer = ($this->posta)();
        } catch (Throwable $e) {
            return 'mittente non costruito: ' . $e->getMessage();
        }
        if (!$mailer instanceof Mailer) {
            return 'posta non configurata';
        }
        try {
            $sito = IndirizzoPubblico::radice('avvisi_inattivita');
        } catch (IndirizzoPubblicoMancante) {
            return 'indirizzo del sito non configurato';
        }
        [$oggetto, $testo] = self::email((string)($u['first_name'] ?? ''), $fase, $quando, $sito);
        try {
            return $mailer->send($indirizzo, $oggetto, $testo) ? null : 'invio rifiutato';
        } catch (Throwable $e) {
            return 'invio fallito: ' . $e->getMessage();
        }
    }

    /**
     * L'oggetto e il testo dell'avviso.
     *
     * @return array{0:string, 1:string}
     */
    public static function email(string $nome, int $fase, DateTimeImmutable $quando, string $sito): array
    {
        $data = $quando->format('d/m/Y');
        $saluto = trim($nome) !== '' ? 'Ciao ' . trim($nome) . ',' : 'Ciao,';
        $ultimo = $fase === self::ULTIMO_AVVISO_GIORNI ? "È l'ultimo avviso.\n\n" : '';
        $recapito = trim((string)Config::get('mail.dpo_email', ''));
        $contatto = $recapito !== ''
            ? "Per domande sulla privacy: {$recapito}."
            : "Per domande sulla privacy: {$sito}/dpo-contact.";
        $oggetto = "Il tuo account Pantedu sarà cancellato il {$data}";
        $testo = "{$saluto}\n\n"
            . "il tuo account Pantedu non viene usato da quasi due anni. Se non accedi,\n"
            . "il {$data} verrà cancellato, come dice l'informativa privacy.\n\n"
            . $ultimo
            . "Per tenerlo basta accedere una volta prima di quella data:\n\n"
            . "{$sito}/login\n\n"
            . "Con la cancellazione si tolgono i tuoi contenuti e i tuoi dati, e la\n"
            . "chiave che li cifra. Nelle copie di sicurezza fatte prima restano fino\n"
            . "alla loro scadenza, al più qualche mese.\n\n"
            . "Se vuoi i tuoi dati prima, da «I tuoi dati» puoi scaricarli.\n\n"
            . "{$contatto}\n\n"
            . "— Pantedu\n";
        return [$oggetto, $testo];
    }
}
