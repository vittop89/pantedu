<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Services\Contenuti\MaterialiSuSezioniNonAmmesse;
use App\Support\IndirizzoPubblico;
use PDO;
use Throwable;

/**
 * Togliere a un docente l'incarico su una o più classi (15/9/2026, scelte
 * dell'utente): che cosa c'è di suo lì, per l'avviso all'amministratore prima di
 * confermare, e l'email al docente dopo. Una classe è una sezione («2A») o, da
 * ADR-043, un anno di un corso («3» di architettura).
 *
 * CHE COSA SUCCEDE AL DOCENTE (ADR-041, ADR-043)
 *   - con «solo incaricati» la classe sparisce dai suoi menù (con «nessuno»
 *     solo una sezione): non pubblica più lì e non crea credenziali lì;
 *   - i suoi materiali restano sulla sezione, non si cancellano e non si
 *     spostano; li ritrova in «Sposta di classe» come «senza incarico»;
 *   - chi entra con una sua credenziale di classe su quella sezione continua a
 *     vederli: la visibilità segue la credenziale, non l'incarico;
 *   - gli studenti con account della sezione (solo nello scenario Istituto) non
 *     vedono più i suoi contenuti: per loro conta l'incarico.
 *   Fino al 15/9/2026 la conferma diceva a tutti «gli studenti di quelle sezioni
 *   non vedranno più i suoi contenuti», vero solo per gli ultimi.
 *
 * PERCHÉ UN'EMAIL, E SOLO SE C'È QUALCOSA DA FARE
 *   Il cruscotto avvisa il docente dei materiali rimasti, ma solo quando entra: può
 *   non entrare per settimane, e intanto l'amministratore può portarli sull'anno.
 *   Assegnare non gli toglie niente, e non gli si scrive. Togliere una classe su
 *   cui non ha materiali né credenziali non gli lascia niente da fare, e non gli
 *   si scrive nemmeno (scelta dell'utente, 15/9/2026: prima partiva lo stesso).
 *
 * L'email non ferma il salvataggio: senza posta configurata, senza un indirizzo
 * valido o con l'invio fallito, l'incarico resta tolto e il messaggio
 * all'amministratore lo dice.
 */
final class AvvisoIncarichiTolti
{
    public const INVIATA         = 'inviata';
    public const SENZA_INDIRIZZO = 'senza_indirizzo';
    public const SENZA_POSTA     = 'senza_posta';
    public const NON_PARTITA     = 'non_partita';
    /** Nessun materiale e nessuna credenziale sulle classi tolte: niente da scrivere. */
    public const NIENTE_DA_FARE  = 'niente_da_fare';

    /** Singolare e plurale dei tipi di contenuto (`content_subtype`), come nella barra. */
    private const TIPI = [
        'mappa'     => ['mappa', 'mappe'],
        'esercizio' => ['esercizio', 'esercizi'],
        'verifica'  => ['verifica', 'verifiche'],
        'lab'       => ['laboratorio', 'laboratori'],
        'document'  => ['documento', 'documenti'],
    ];

    /** @var (callable(): ?Mailer) */
    private $posta;

    /**
     * @param (callable(): ?Mailer)|null $posta chi dà il mittente; di norma Mailer::fromConfig
     */
    public function __construct(
        private ?PDO $pdo = null,
        ?callable $posta = null,
        private ?MaterialiSuSezioniNonAmmesse $materiali = null,
    ) {
        $this->posta = $posta ?? static fn(): ?Mailer => Mailer::fromConfig();
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    private function materiali(): MaterialiSuSezioniNonAmmesse
    {
        return $this->materiali ??= new MaterialiSuSezioniNonAmmesse($this->pdo);
    }

    /**
     * L'anteprima per l'amministratore: chi, dove, che cosa c'è su ogni sezione
     * e se al docente arriverà l'email.
     *
     * @param list<string> $sezioni
     * @return array{docente:string,modalita:string,email:string,sezioni:list<array{code:string,descrizione:string,materiali:int,credenziali:int,studenti:int}>}
     */
    public function anteprima(int $docente, int $istituto, string $indirizzo, array $sezioni): array
    {
        $u = $this->docente($docente);
        $righe = [];
        $conteggi = $this->materiali()->sulleSezioni($docente, $istituto, $indirizzo, $sezioni);
        foreach ($conteggi as $r) {
            $righe[] = [
                'code'        => $r['code'],
                'descrizione' => self::descrivi($r),
                'materiali'   => array_sum($r['tipi']) + $r['verifiche'],
                'credenziali' => $r['credenziali'],
                'studenti'    => $r['studenti'],
            ];
        }
        return [
            'docente'  => $u['nome'],
            'modalita' => (new SezioniDeiDocenti($this->pdo))->modalita($istituto),
            // 'si' se l'email partirà; altrimenti il motivo per cui non partirà.
            'email'    => !self::daFare($conteggi)
                ? self::NIENTE_DA_FARE
                : ($this->email($u) === null
                    ? self::SENZA_INDIRIZZO
                    : (($this->posta)() === null ? self::SENZA_POSTA : 'si')),
            'sezioni'  => $righe,
        ];
    }

    /**
     * Scrive al docente le sezioni tolte e che cosa ci ha. Da chiamare dopo aver
     * tolto gli incarichi: i conteggi non cambiano, la modalità sì può contare.
     *
     * @param list<string> $tolte
     * @param int|null $autore l'amministratore che ha tolto gli incarichi: la risposta va a lui
     * @return string una delle costanti INVIATA, NIENTE_DA_FARE, SENZA_INDIRIZZO, SENZA_POSTA, NON_PARTITA
     */
    public function invia(int $docente, int $istituto, string $indirizzo, array $tolte, ?int $autore = null): string
    {
        if ($tolte === []) {
            return self::NON_PARTITA;
        }
        try {
            $righe = $this->materiali()->sulleSezioni($docente, $istituto, $indirizzo, $tolte);
            if (!self::daFare($righe)) {
                return self::NIENTE_DA_FARE;
            }
            $u = $this->docente($docente);
            $to = $this->email($u);
            if ($to === null) {
                return self::SENZA_INDIRIZZO;
            }
            $mailer = ($this->posta)();
            if ($mailer === null) {
                return self::SENZA_POSTA;
            }
            $st = $this->db()->prepare('SELECT COALESCE(NULLIF(name, ""), code) FROM institutes WHERE id = ?');
            $st->execute([$istituto]);
            $scuola = (string)($st->fetchColumn() ?: '');
            $modalita = (new SezioniDeiDocenti($this->pdo))->modalita($istituto);
            // 23/9/2026 — il collegamento è l'azione da fare: senza `app.url`
            // l'avviso non parte (IndirizzoPubblico registra e lancia, il
            // catch risponde NON_PARTITA e l'amministratore lo legge). Fino a
            // quel giorno il ripiego era il dominio di produzione.
            $sito = IndirizzoPubblico::radice('avviso_incarichi');
            [$oggetto, $testo] = self::messaggio($u['saluto'], $scuola, $indirizzo, $righe, $modalita, $sito);
            return $mailer->send($to, $oggetto, $testo, $this->rispostaA($autore)) ? self::INVIATA : self::NON_PARTITA;
        } catch (Throwable $e) {
            error_log('[AvvisoIncarichiTolti] ' . $e->getMessage());
            return self::NON_PARTITA;
        }
    }

    /**
     * C'è qualcosa che il docente deve sapere per agire: materiali (anche solo
     * pubblicazioni «per più classi») o credenziali attive su quelle classi.
     *
     * @param list<array{tipi:array<string,int>,verifiche:int,bersagli:int,credenziali:int}> $righe
     */
    public static function daFare(array $righe): bool
    {
        foreach ($righe as $r) {
            if (array_sum($r['tipi']) + $r['verifiche'] + $r['bersagli'] + $r['credenziali'] > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * «3 mappe · 2 esercizi · 1 verifica generata (4 posti in più)», o «nessun
     * materiale». I bersagli di «per più classi» a parte, perché non si spostano
     * da «Sposta di classe».
     *
     * @param array{tipi:array<string,int>,verifiche:int,posti:int,bersagli:int} $r
     */
    public static function descrivi(array $r): string
    {
        $parti = [];
        // Nell'ordine della barra (mappe, esercizi, verifiche…), non in quello del database.
        $tipi = array_replace(array_intersect_key(array_fill_keys(array_keys(self::TIPI), 0), $r['tipi']), $r['tipi']);
        foreach ($tipi as $tipo => $n) {
            if ($n <= 0) {
                continue;
            }
            [$uno, $tanti] = self::TIPI[$tipo] ?? [$tipo, $tipo];
            $parti[] = $n . ' ' . ($n === 1 ? $uno : $tanti);
        }
        if ($r['verifiche'] > 0) {
            $parti[] = $r['verifiche'] . ' ' . ($r['verifiche'] === 1 ? 'verifica generata' : 'verifiche generate');
        }
        $testo = $parti === [] ? 'nessun materiale' : implode(' · ', $parti);
        if ($parti !== [] && $r['posti'] > 0) {
            $testo .= ' (' . $r['posti'] . ($r['posti'] === 1 ? ' posto in più' : ' posti in più') . ')';
        }
        if ($r['bersagli'] > 0) {
            $testo .= ($parti === [] ? '; ' : ' · ') . $r['bersagli']
                . ($r['bersagli'] === 1 ? ' pubblicazione «per più classi»' : ' pubblicazioni «per più classi»');
        }
        return $testo;
    }

    /**
     * Oggetto e testo dell'email. Senza accesso al database: si prova da solo.
     *
     * @param list<array{code:string,tipi:array<string,int>,verifiche:int,posti:int,bersagli:int,credenziali:int,studenti:int}> $righe
     * @return array{0:string,1:string}
     */
    public static function messaggio(string $saluto, string $scuola, string $indirizzo, array $righe, string $modalita, string $sito): array
    {
        $codici = array_column($righe, 'code');
        $una = \count($codici) === 1;
        $elenco = implode(', ', $codici);
        $dove = ($indirizzo !== '' ? " dell'indirizzo $indirizzo" : '') . ($scuola !== '' ? " ($scuola)" : '');

        $oggetto = 'Pantedu — ' . ($una ? 'incarico tolto sulla classe ' : 'incarichi tolti sulle classi ') . $elenco;
        $t = "Ciao $saluto,\n\n"
            . "l'amministratore della piattaforma ti ha tolto l'incarico " . ($una ? 'sulla classe ' : 'sulle classi ')
            . "$elenco$dove.\n\n";

        if ($modalita !== SezioniDeiDocenti::TUTTI) {
            $t .= ($una ? 'La classe non compare più' : 'Le classi non compaiono più')
                . " nei tuoi menù: non puoi pubblicarvi nuovi materiali né creare credenziali di classe.\n\n";
        }

        $conMateriali = array_values(array_filter(
            $righe,
            static fn(array $r): bool => array_sum($r['tipi']) + $r['verifiche'] + $r['bersagli'] > 0
        ));
        if ($conMateriali === []) {
            $t .= ($una ? 'Sulla classe' : 'Su queste classi') . " non hai materiali pubblicati.\n\n";
        } else {
            $t .= "I tuoi materiali restano dove sono, non si cancellano e non si spostano da soli:\n";
            foreach ($righe as $r) {
                $t .= '- ' . $r['code'] . ': ' . self::descrivi($r) . "\n";
            }
            $t .= "\nLi ritrovi in «Sposta di classe», dove "
                . ($una ? 'la classe compare' : 'le classi compaiono')
                . " come «senza incarico», e da lì puoi portarli su un'altra delle tue classi:\n"
                . "$sito/area-docente/sposta-di-classe\n\n";
        }

        $credenziali = array_sum(array_column($righe, 'credenziali'));
        if ($credenziali > 0) {
            $t .= $credenziali === 1
                ? "La tua credenziale di classe su " . ($una ? 'questa classe' : 'queste classi') . " resta attiva: chi la usa continua a vedere i materiali.\n\n"
                : "Le tue $credenziali credenziali di classe su " . ($una ? 'questa classe' : 'queste classi') . " restano attive: chi le usa continua a vedere i materiali.\n\n";
        }
        $studenti = array_sum(array_column($righe, 'studenti'));
        if ($studenti > 0) {
            $t .= $studenti === 1
                ? "Lo studente con account di " . ($una ? 'questa classe' : 'queste classi') . " non vede più i tuoi contenuti.\n\n"
                : "I $studenti studenti con account di " . ($una ? 'questa classe' : 'queste classi') . " non vedono più i tuoi contenuti.\n\n";
        }

        $t .= "Se ridanno l'incarico, " . ($una ? 'la classe torna' : 'le classi tornano')
            . " nei tuoi menù con i materiali che sono ancora lì.\n\n"
            . "Se pensi che sia un errore, rispondi a questa email.\n\n— Pantedu\n";
        return [$oggetto, $t];
    }

    /**
     * A chi arriva la risposta del docente. L'email lo invita a rispondere se
     * pensa a un errore, e decidere è dell'amministratore che ha tolto
     * l'incarico: la risposta va a lui. Senza un suo indirizzo valido, alla
     * casella delle risposte della piattaforma (APP_MAIL_REPLY_TO); senza
     * nemmeno quella, alla casella dei diritti.
     *
     * Fino al 15/9/2026 andava sempre a dpo@ (lo schema degli avvisi di custodia
     * delle chiavi): una contestazione organizzativa finiva nella casella delle
     * richieste privacy. Segnalato dall'utente, provando l'email.
     */
    private function rispostaA(?int $autore): ?string
    {
        if ($autore !== null && $autore > 0) {
            $st = $this->db()->prepare('SELECT email FROM users WHERE id = ?');
            $st->execute([$autore]);
            $email = trim((string)($st->fetchColumn() ?: ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }
        $risposte = trim((string)Config::get('mail.reply_to', ''));
        if ($risposte !== '' && filter_var($risposte, FILTER_VALIDATE_EMAIL)) {
            return $risposte;
        }
        // Senza nemmeno la casella dei diritti, il Reply-To resta il mittente
        // (23/9/2026: il ripiego era la casella del DPO di produzione).
        $dpo = trim((string)Config::get('mail.dpo_email', ''));
        return $dpo !== '' ? $dpo : null;
    }

    /** @return array{nome:string,saluto:string,email:string} */
    private function docente(int $id): array
    {
        $st = $this->db()->prepare(
            'SELECT username, first_name, email,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(" ", first_name, last_name)), ""), username) AS nome
               FROM users WHERE id = ?'
        );
        $st->execute([$id]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!\is_array($u)) {
            return ['nome' => 'docente #' . $id, 'saluto' => '', 'email' => ''];
        }
        return [
            'nome'   => (string)$u['nome'],
            'saluto' => trim((string)($u['first_name'] ?? '')) ?: (string)$u['username'],
            'email'  => trim((string)($u['email'] ?? '')),
        ];
    }

    /** @param array{email:string} $u */
    private function email(array $u): ?string
    {
        return $u['email'] !== '' && filter_var($u['email'], FILTER_VALIDATE_EMAIL) ? $u['email'] : null;
    }
}
