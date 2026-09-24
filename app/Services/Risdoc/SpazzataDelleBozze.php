<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Core\Database;
use App\Services\Mailer;
use App\Support\IndirizzoPubblico;
use PDO;
use Throwable;

/**
 * Il giro notturno che avvisa e poi cancella le bozze scadute (ADR-046).
 *
 * ── Divisione del lavoro ──────────────────────────────────────────────────
 *
 * {@see ScadenzaDelleBozze} dice **quando** muore una bozza, e non sa cosa sia
 * un database. Qui si va a prendere le righe, si scrive al docente, e solo
 * dopo si cancella. La separazione non e' eleganza: la regola dei giorni si
 * puo' provare su qualunque data senza far finta che sia un altro giorno,
 * mentre tutto cio' che tocca il database si prova contro un database vero.
 *
 * ── Prima l'avviso, poi la cancellazione. Sempre ──────────────────────────
 *
 * Una riga non si cancella se l'avviso non e' partito almeno
 * {@see ATTESA_DOPO_AVVISO_GIORNI} giorni prima. Nel giro normale l'avviso
 * parte con sette giorni di anticipo e questa condizione e' gia' soddisfatta
 * quando arriva la scadenza: non ritarda niente. Serve per il caso storto —
 * il lavoro fermo una settimana, il server spento, una migrazione lunga — in
 * cui al primo giro utile ci si troverebbe davanti righe gia' scadute e mai
 * annunciate. Lì la promessa «il docente viene avvisato prima» vale piu' della
 * puntualita' di tre giorni.
 *
 * ── Quando l'avviso non si puo' recapitare ────────────────────────────────
 *
 * Se il docente non ha un indirizzo valido, o l'istanza non manda posta, la
 * riga viene segnata lo stesso come avvisata e il giro prosegue. L'alternativa
 * sarebbe una conservazione che non finisce mai per un indirizzo mancante:
 * una minimizzazione che si autoannulla in silenzio. Il conto degli avvisi non
 * recapitati esce nel resoconto e lo guarda la diagnostica: deve restare una
 * cosa che si vede, non una che si assorbe.
 *
 * Un invio **fallito** (la posta risponde male) e' un'altra cosa: quello non
 * segna niente e si ritenta la notte dopo.
 *
 * ── «Zero cancellate» non e' un esito ─────────────────────────────────────
 *
 * Il resoconto porta sempre quante righe sono state **guardate**. Un giro che
 * cancella zero righe perche' non ce n'erano e un giro che cancella zero righe
 * perche' ha interrogato la tabella sbagliata si assomigliano troppo: e' la
 * forma di guasto che questo progetto insegue.
 */
final class SpazzataDelleBozze
{
    /** Giorni che devono passare fra l'avviso e la cancellazione. */
    public const ATTESA_DOPO_AVVISO_GIORNI = 3;

    public const INVIATA         = 'inviata';
    public const SENZA_INDIRIZZO = 'senza_indirizzo';
    public const SENZA_POSTA     = 'senza_posta';
    public const NON_PARTITA     = 'non_partita';

    /** @var (callable(): ?Mailer) */
    private $posta;

    /** @param (callable(): ?Mailer)|null $posta chi da' il mittente; di norma Mailer::fromConfig */
    public function __construct(private ?PDO $pdo = null, ?callable $posta = null)
    {
        $this->posta = $posta ?? static fn(): ?Mailer => Mailer::fromConfig();
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * La colonna esiste? Dove la migrazione 138 non e' applicata non c'e'
     * niente da spazzare, e dirlo e' un'informazione: un giro che «non trova
     * niente» perche' la colonna manca sembrerebbe un giro riuscito.
     */
    public function pronta(): bool
    {
        try {
            $this->db()->query('SELECT exported_at FROM risdoc_compilations_data LIMIT 1')->fetchColumn();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Tutte le compilazioni con la loro sorte, e l'ora del database.
     *
     * L'ora arriva dalla stessa interrogazione che porta le righe: e' la stessa
     * `NOW()` che ha scritto `updated_at`, e non quella di PHP, che gira su
     * Europe/Rome mentre il database sta su UTC.
     *
     * @return array{adesso: string, righe: list<array<string,mixed>>}
     */
    public function censimento(): array
    {
        $righe = $this->db()->query(
            'SELECT c.id,
                    c.teacher_id,
                    c.label,
                    c.updated_at,
                    c.exported_at,
                    c.expiry_warned_at,
                    NOW() AS adesso,
                    COALESCE(NULLIF(t.argomento, ""), t.code) AS modello
               FROM risdoc_compilations_data c
               LEFT JOIN risdoc_templates t ON t.id = c.template_id
              ORDER BY c.teacher_id, c.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        // `adesso` va letto una volta sola e da una riga qualunque: se la
        // tabella e' vuota lo si chiede a parte, perche' il resoconto lo dice
        // comunque.
        $adesso = $righe !== []
            ? (string)$righe[0]['adesso']
            : (string)$this->db()->query('SELECT NOW()')->fetchColumn();

        $fuori = [];
        foreach ($righe as $r) {
            $scaricata  = $r['exported_at'] !== null ? (string)$r['exported_at'] : null;
            $modificata = (string)$r['updated_at'];
            $avvisata   = $r['expiry_warned_at'] !== null ? (string)$r['expiry_warned_at'] : null;

            $fuori[] = [
                'id'         => (int)$r['id'],
                'docente'    => (int)$r['teacher_id'],
                'etichetta'  => (string)$r['label'],
                'modello'    => (string)($r['modello'] ?? ''),
                'scaricata'  => $scaricata,
                'modificata' => $modificata,
                'avvisata'   => $avvisata,
                'scade'      => ScadenzaDelleBozze::scadeIl($scaricata, $modificata)->format('Y-m-d'),
                'rimasti'    => ScadenzaDelleBozze::giorniRimasti($scaricata, $modificata, $adesso),
                'motivo'     => ScadenzaDelleBozze::motivo($scaricata, $modificata),
                'stato'      => ScadenzaDelleBozze::stato($scaricata, $modificata, $avvisata, $adesso),
            ];
        }

        return ['adesso' => $adesso, 'righe' => $fuori];
    }

    /**
     * Le righe a cui il giro manda l'avviso stanotte: quelle nel preavviso, e
     * quelle già scadute che l'avviso non l'hanno mai avuto.
     *
     * ── Il caso che mancava (23/9/2026, revisione Risdoc A2) ──────────────
     *
     * `ScadenzaDelleBozze::stato()` dice «da cancellare» appena la scadenza è
     * passata, avviso o no; il giro avvisava solo le righe «da avvisare», e la
     * cancellazione vuole l'avviso. Una riga che saltava la finestra dei sette
     * giorni — già scaduta quando il timer è stato installato, o il lavoro fermo
     * una settimana — non veniva quindi **mai** avvisata né cancellata: il
     * «caso storto» che il commento in testa alla classe promette di coprire.
     * Ora la si avvisa la prima notte utile, e dopo i tre giorni si cancella
     * come le altre. Per lei il preavviso effettivo è di tre giorni, non sette.
     *
     * Logica pura, come le due funzioni qui sotto.
     *
     * @param  list<array<string,mixed>> $righe
     * @return list<array<string,mixed>>
     */
    public static function daAvvisare(array $righe): array
    {
        $fuori = [];
        foreach ($righe as $r) {
            $stato = $r['stato'] ?? '';
            $avvisata = $r['avvisata'] ?? null;
            $mai = !\is_string($avvisata) || trim($avvisata) === '';
            if (
                $stato === ScadenzaDelleBozze::DA_AVVISARE
                || ($stato === ScadenzaDelleBozze::DA_CANCELLARE && $mai)
            ) {
                $fuori[] = $r;
            }
        }

        return $fuori;
    }

    /**
     * Le righe da cancellare davvero: scadute **e** avvisate da abbastanza
     * tempo. Logica pura: prende il censimento gia' fatto.
     *
     * @param  list<array<string,mixed>> $righe
     * @return list<array<string,mixed>>
     */
    public static function maturePerLaCancellazione(array $righe, string $adesso): array
    {
        $limite = strtotime($adesso . ' -' . self::ATTESA_DOPO_AVVISO_GIORNI . ' days');
        if ($limite === false) {
            return [];
        }

        $fuori = [];
        foreach ($righe as $r) {
            if (($r['stato'] ?? '') !== ScadenzaDelleBozze::DA_CANCELLARE) {
                continue;
            }
            $avvisata = $r['avvisata'] ?? null;
            if (!\is_string($avvisata) || trim($avvisata) === '') {
                continue;
            }
            $quando = strtotime($avvisata);
            if ($quando !== false && $quando <= $limite) {
                $fuori[] = $r;
            }
        }

        return $fuori;
    }

    /**
     * Le righe scadute che NON si cancellano perche' l'avviso non e' ancora
     * partito, o e' partito da poco. Servono al resoconto: sono il ritardo che
     * il giro si prende di proposito, e deve essere visibile.
     *
     * @param  list<array<string,mixed>> $righe
     * @return list<array<string,mixed>>
     */
    public static function scaduteInAttesa(array $righe, string $adesso): array
    {
        $mature = [];
        foreach (self::maturePerLaCancellazione($righe, $adesso) as $r) {
            $mature[(int)$r['id']] = true;
        }

        $fuori = [];
        foreach ($righe as $r) {
            if (
                ($r['stato'] ?? '') === ScadenzaDelleBozze::DA_CANCELLARE
                && !isset($mature[(int)$r['id']])
            ) {
                $fuori[] = $r;
            }
        }

        return $fuori;
    }

    /**
     * Le righe **bloccate**: scadute, mai avvisate, e ormai in ritardo.
     *
     * ── Il buco che questa funzione chiude (misurato il 22/9/2026) ────────
     *
     * Una riga non si cancella finche' l'avviso non e' partito. Se la posta e'
     * rotta, l'avviso non parte **mai**: la riga resta scaduta e non avvisata
     * per sempre, e — questo e' il punto — non compare fra le mature, perche'
     * le mature richiedono `expiry_warned_at`. L'invariante della diagnostica,
     * che guarda le mature, resterebbe **verde** mentre la conservazione
     * dichiarata non viene applicata a nessuna riga.
     *
     * E' esattamente la forma di guasto che questo progetto insegue: non
     * qualcosa che si rompe, ma qualcosa che smette di funzionare senza dirlo.
     * Trovato provando il giro con la posta assente sulla macchina di
     * sviluppo — l'esito era `non_partita` e il resoconto diceva zero errori.
     *
     * Il ritardo tollerato e' il preavviso stesso: l'avviso doveva partire
     * sette giorni **prima** della scadenza, quindi una riga sette giorni
     * **dopo** la scadenza e ancora muta ha due settimane di tentativi falliti
     * alle spalle. Non e' piu' un guasto passeggero.
     *
     * @param  list<array<string,mixed>> $righe
     * @return list<array<string,mixed>>
     */
    public static function bloccate(array $righe, string $adesso): array
    {
        $fuori = [];
        foreach (self::scaduteInAttesa($righe, $adesso) as $r) {
            $avvisata = $r['avvisata'] ?? null;
            if (\is_string($avvisata) && trim($avvisata) !== '') {
                continue; // avvisata: sta solo aspettando i tre giorni
            }
            if ((int)$r['rimasti'] <= -ScadenzaDelleBozze::PREAVVISO_GIORNI) {
                $fuori[] = $r;
            }
        }

        return $fuori;
    }

    /**
     * Un giro intero.
     *
     * Con `$applica` a falso non scrive niente: dice solo che cosa farebbe. E'
     * il verso prudente per difetto, come gli altri lavori di conservazione
     * del progetto.
     *
     * @return array{
     *     pronta: bool,
     *     adesso: string,
     *     guardate: int,
     *     da_avvisare: int,
     *     avvisi: array<string,int>,
     *     da_cancellare: int,
     *     cancellate: int,
     *     scadute_in_attesa: int,
     *     bloccate: int,
     *     errori: list<string>
     * }
     */
    public function gira(bool $applica): array
    {
        if (!$this->pronta()) {
            return [
                'pronta'            => false,
                'adesso'            => '',
                'guardate'          => 0,
                'da_avvisare'       => 0,
                'avvisi'            => [],
                'da_cancellare'     => 0,
                'cancellate'        => 0,
                'scadute_in_attesa' => 0,
                'bloccate'          => 0,
                'errori'            => ['la migrazione 138 non e\' applicata: colonna exported_at assente'],
            ];
        }

        $censimento = $this->censimento();
        $adesso = $censimento['adesso'];
        $righe  = $censimento['righe'];
        $errori = [];

        // ── Gli avvisi, uno per docente ──────────────────────────────────
        $perDocente = [];
        foreach (self::daAvvisare($righe) as $r) {
            $perDocente[(int)$r['docente']][] = $r;
        }

        $avvisi = [];
        foreach ($perDocente as $docente => $sue) {
            $esito = $applica
                ? $this->avvisa($docente, $sue)
                : self::INVIATA; // in prova si dichiara cosa si farebbe
            $avvisi[$esito] = ($avvisi[$esito] ?? 0) + 1;

            if (!$applica || $esito === self::NON_PARTITA) {
                // Non si segna: la notte dopo si ritenta.
                continue;
            }

            try {
                $this->segnaAvvisate(array_map(static fn(array $r): int => (int)$r['id'], $sue));
            } catch (Throwable $e) {
                $errori[] = 'segnatura avvisi docente ' . $docente . ': ' . $e->getMessage();
            }
        }

        // ── Le cancellazioni ─────────────────────────────────────────────
        // Si rifa' il censimento quando si e' scritto qualcosa: le righe
        // appena segnate come avvisate non maturano oggi (servono i tre
        // giorni), ma rileggere costa poco e toglie di mezzo la domanda.
        if ($applica && $avvisi !== []) {
            $censimento = $this->censimento();
            $adesso = $censimento['adesso'];
            $righe  = $censimento['righe'];
        }

        $mature  = self::maturePerLaCancellazione($righe, $adesso);
        $inAttesa = self::scaduteInAttesa($righe, $adesso);

        $cancellate = 0;
        if ($applica && $mature !== []) {
            try {
                $cancellate = $this->cancella(array_map(static fn(array $r): int => (int)$r['id'], $mature));
            } catch (Throwable $e) {
                $errori[] = 'cancellazione: ' . $e->getMessage();
            }
        }

        return [
            'pronta'            => true,
            'adesso'            => $adesso,
            'guardate'          => \count($righe),
            'da_avvisare'       => \count($perDocente),
            'avvisi'            => $avvisi,
            'da_cancellare'     => \count($mature),
            'cancellate'        => $cancellate,
            'scadute_in_attesa' => \count($inAttesa),
            'bloccate'          => \count(self::bloccate($righe, $adesso)),
            'errori'            => $errori,
        ];
    }

    /**
     * @param list<int> $ids
     */
    public function cancella(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $segnaposto = implode(',', array_fill(0, \count($ids), '?'));
        $st = $this->db()->prepare(
            "DELETE FROM risdoc_compilations_data WHERE id IN ({$segnaposto})"
        );
        $st->execute(array_map('intval', $ids));

        return $st->rowCount();
    }

    /**
     * @param list<int> $ids
     */
    public function segnaAvvisate(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        // `updated_at = updated_at`: segnare un avviso non e' una modifica del
        // docente, e se `updated_at` saltasse la scadenza si sposterebbe in
        // avanti proprio mentre si sta annunciando che arriva.
        $segnaposto = implode(',', array_fill(0, \count($ids), '?'));
        $st = $this->db()->prepare(
            "UPDATE risdoc_compilations_data
                SET expiry_warned_at = NOW(), updated_at = updated_at
              WHERE id IN ({$segnaposto})"
        );
        $st->execute(array_map('intval', $ids));

        return $st->rowCount();
    }

    /**
     * Scrive al docente. Non solleva mai: torna una costante di esito, perche'
     * un giro notturno che muore a meta' lista lascia il lavoro fatto a meta'.
     *
     * @param list<array<string,mixed>> $sue
     */
    public function avvisa(int $docente, array $sue): string
    {
        try {
            $u = $this->docente($docente);
            $to = $u['email'] !== '' && filter_var($u['email'], FILTER_VALIDATE_EMAIL) ? $u['email'] : null;
            if ($to === null) {
                return self::SENZA_INDIRIZZO;
            }

            $mailer = ($this->posta)();
            if ($mailer === null) {
                return self::SENZA_POSTA;
            }

            // 23/9/2026 — senza `app.url` l'avviso non parte: IndirizzoPubblico
            // registra l'anomalia e lancia, il catch qui sotto risponde
            // NON_PARTITA, e la notte dopo si ritenta senza aver cancellato
            // niente (una bozza non si cancella senza avviso). Fino a quel
            // giorno il ripiego era il dominio di produzione.
            $sito = IndirizzoPubblico::radice('avviso_bozze');
            [$oggetto, $testo] = self::messaggio($u['saluto'], $sue, $sito);

            return $mailer->send($to, $oggetto, $testo) ? self::INVIATA : self::NON_PARTITA;
        } catch (Throwable $e) {
            error_log('[SpazzataDelleBozze] avviso al docente ' . $docente . ': ' . $e->getMessage());
            return self::NON_PARTITA;
        }
    }

    /**
     * Il testo dell'avviso. Funzione pura: si prova leggendola, senza posta.
     *
     * Non nomina il contenuto della bozza — l'etichetta la sceglie il docente e
     * puo' contenere una classe, ma non si cita nulla di cio' che c'e' dentro:
     * una mail e' un canale che non controlliamo.
     *
     * @param  list<array<string,mixed>> $sue
     * @return array{0:string,1:string}
     */
    public static function messaggio(string $saluto, array $sue, string $sito): array
    {
        $quante = \count($sue);
        $oggetto = $quante === 1
            ? '[pantedu] Una bozza sta per essere cancellata'
            : sprintf('[pantedu] %d bozze stanno per essere cancellate', $quante);

        $righe = [];
        foreach ($sue as $r) {
            $righe[] = sprintf(
                '  · %s (%s) — si cancella il %s',
                (string)$r['etichetta'],
                (string)($r['modello'] ?? ''),
                self::allItaliana((string)$r['scade'])
            );
        }

        $perche = self::perche($sue);

        $testo = 'Ciao ' . ($saluto !== '' ? $saluto : 'docente') . ",\n\n"
            . ($quante === 1
                ? "una tua bozza di compilazione sta per essere cancellata dal server:\n\n"
                : "alcune tue bozze di compilazione stanno per essere cancellate dal server:\n\n")
            . implode("\n", $righe) . "\n\n"
            . $perche . "\n\n"
            . "COSA PUOI FARE\n"
            . "  · se ti serve ancora, aprila e modificala: il conto riparte da capo;\n"
            . "  · se ti basta il documento, scaricalo e conservalo dove tieni gli atti della scuola;\n"
            . "  · se non ti serve piu', non fare niente.\n\n"
            . "Si cancella la bozza salvata sul server, non il modello: quello resta, e "
            . "puoi ricompilarlo quando vuoi.\n\n"
            . "Le tue compilazioni: {$sito}/area-docente\n\n"
            . "— Pantedu\n";

        return [$oggetto, $testo];
    }

    /**
     * @param list<array<string,mixed>> $sue
     */
    private static function perche(array $sue): string
    {
        $scaricate = 0;
        foreach ($sue as $r) {
            if (($r['motivo'] ?? '') === 'scaricata') {
                $scaricate++;
            }
        }

        if ($scaricate === \count($sue)) {
            return sprintf(
                "PERCHE'\n  Le hai scaricate, quindi il documento ce l'hai. Le bozze salvate sul\n"
                . "  server si cancellano %d giorni dopo lo scaricamento, perche' possono\n"
                . "  contenere dati di persone e non ha senso tenerle piu' del necessario.",
                ScadenzaDelleBozze::GRAZIA_GIORNI
            );
        }

        if ($scaricate === 0) {
            return "PERCHE'\n  Sono ferme da prima di giugno: l'anno scolastico a cui si riferivano e'\n"
                . "  chiuso. Le bozze salvate sul server si cancellano a fine anno scolastico,\n"
                . "  perche' possono contenere dati di persone e non ha senso tenerle piu' del\n"
                . "  necessario.";
        }

        return sprintf(
            "PERCHE'\n  Alcune le hai scaricate (si cancellano %d giorni dopo), altre sono ferme\n"
            . "  da prima di giugno e l'anno scolastico a cui si riferivano e' chiuso. Le\n"
            . "  bozze salvate sul server non si tengono piu' del necessario, perche'\n"
            . "  possono contenere dati di persone.",
            ScadenzaDelleBozze::GRAZIA_GIORNI
        );
    }

    private static function allItaliana(string $iso): string
    {
        $t = strtotime($iso);

        return $t === false ? $iso : date('d/m/Y', $t);
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
}
