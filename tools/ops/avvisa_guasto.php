<?php

declare(strict_types=1);

/**
 * Manda un avviso quando un'unità systemd del progetto fallisce.
 *
 * Perché esiste: fino all'8 settembre 2026 le reti di sicurezza del rilascio
 * scrivevano il guasto dove nessuno guardava. `deploy.sh` esce con 1 se
 * l'istantanea del database non riesce, se le migrazioni falliscono o se il
 * sito non risponde, e i commenti nel codice dicevano che l'unità systemd
 * «risulta failed, che è un segnale che si vede». Non si vedeva: nessuna unità
 * sulla macchina usava `OnFailure=`, e la verifica di quel giorno ha trovato
 * due unità in stato `failed` da ore — `logrotate` e la sincronizzazione delle
 * liste di minacce — senza che nessuno lo sapesse.
 *
 * Come si aggancia: `OnFailure=pantedu-avviso@%n.service` sulle unità che
 * contano. systemd passa il nome dell'unità fallita come primo argomento.
 *
 * Perché la posta e non altro: l'applicazione manda già email tramite Resend
 * (un'API HTTP, non SMTP), quindi non serve installare un MTA e non serve
 * nessuna credenziale nuova. Si riusa `Mailer::fromConfig()`, cioè lo stesso
 * canale che manda i recuperi password: se smette di funzionare, se ne accorge
 * anche qualcun altro.
 *
 * Non fa niente di più che avvisare. Non tenta ripristini: su una macchina
 * sola, con migrazioni non sempre reversibili, un ripristino automatico può
 * fare più danni del guasto. La decisione resta a una persona; quello che deve
 * essere automatico è la telefonata.
 *
 * Uso:
 *   php tools/ops/avvisa_guasto.php <nome-unita> [--prova]
 *
 * Esce 0 se l'avviso è partito, 1 se non è stato possibile mandarlo. Un
 * fallimento qui non deve poter cascare: chi lo chiama è già un percorso di
 * errore.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Mailer;
use App\Support\BrigliaAvvisi;

/** Righe di giornale dell'unità, per capire subito senza collegarsi. */
function ultimeRighe(string $unita, int $quante = 25): string
{
    $comando = sprintf(
        'journalctl -u %s --no-pager -n %d 2>/dev/null',
        escapeshellarg($unita),
        $quante
    );
    $uscita = @shell_exec($comando);

    $testo = is_string($uscita) ? trim($uscita) : '';

    // `journalctl` senza il gruppo `systemd-journal` non dice «non posso»:
    // mostra il giornale dell'utente, che è vuoto, e stampa «-- No entries --».
    // Letto di fretta sembra «non è successo niente», che è il contrario della
    // verità. L'unità `pantedu-avviso@.service` dichiara
    // `SupplementaryGroups=systemd-journal` apposta; se un avviso arriva con
    // questa riga al posto del giornale, quel pezzo non ha funzionato.
    if ($testo === '' || $testo === '-- No entries --') {
        return '(il giornale non è leggibile da questo processo: manca il gruppo'
             . " systemd-journal?\n Guardare a mano con: journalctl -u {$unita} -n 40)";
    }

    return $testo;
}

/** Chi va avvisato: chiave dedicata, altrimenti il mittente dell'istanza. */
function destinatario(): string
{
    $configurato = trim((string)($_ENV['ALERT_EMAIL'] ?? ''));
    if ($configurato !== '') {
        return $configurato;
    }

    /** @var array{from: string} $posta */
    $posta = require __DIR__ . '/../../app/Config/mail.php';

    return trim($posta['from']);
}

$unita = $argv[1] ?? '';
$prova = in_array('--prova', $argv, true);

if ($unita === '') {
    fwrite(STDERR, "uso: php tools/ops/avvisa_guasto.php <nome-unita> [--prova]\n");
    exit(2);
}

$a = destinatario();
if ($a === '') {
    fwrite(STDERR, "[avviso] nessun destinatario: valorizza ALERT_EMAIL o APP_MAIL_FROM.\n");
    exit(1);
}

// APP_MAIL_FROM è una casella send-only, per dichiarazione in `.env.example`:
// mandarci un avviso vuol dire non mandarlo. Si parte lo stesso — meglio un
// messaggio in un posto sbagliato che nessun messaggio — ma lo si dice forte
// nel giornale, perché è una configurazione da correggere, non un dettaglio.
/** @var array{from: string} $postaConfig */
$postaConfig = require __DIR__ . '/../../app/Config/mail.php';
if (trim((string)($_ENV['ALERT_EMAIL'] ?? '')) === '' && $a === trim($postaConfig['from'])) {
    fwrite(
        STDERR,
        "[avviso] ALERT_EMAIL non e' valorizzata: l'avviso va a {$a}, che e' una"
        . " casella send-only e nessuno legge. Valorizzare ALERT_EMAIL.\n"
    );
}

// 2026-09-10 — briglia sugli avvisi ripetuti.
//
// `OnFailure=` va su ogni lavoro automatico, anche su quelli che girano ogni
// minuto: senza, un guasto resta muto. Ma un guasto su un lavoro al minuto,
// senza briglia, sono quattrocento email in una notte — e la richiesta era di
// correggere gli errori, non di annegarli.
//
// Dentro la finestra gli avvisi si contano invece di partire, e il primo che
// parte dopo dice quanti ne sono stati soppressi. Non è silenzio: è un numero.
// `--prova` la scavalca, perché serve proprio a vedere il messaggio.
$soppressi = 0;
if (!$prova) {
    $decisione = BrigliaAvvisi::consulta($unita);
    if (!$decisione['manda']) {
        // Sul giornale resta scritto, così `journalctl -u pantedu-avviso@…`
        // mostra che l'unità sta fallendo di continuo anche senza le email.
        echo sprintf(
            "[avviso] %s è fallita di nuovo: avviso soppresso (%d dall'ultimo mandato, finestra %d min).\n",
            $unita,
            $decisione['soppressi'],
            (int)(BrigliaAvvisi::FINESTRA_SECONDI / 60)
        );
        exit(0);
    }
    $soppressi = $decisione['soppressi'];
}

$istanza = trim((string)($_ENV['APP_URL'] ?? '')) ?: 'questa istanza';
$quando  = date('d/m/Y H:i:s T');

$oggetto = sprintf('[Pantedu] %s è fallita', $unita);
$corpo   = <<<TESTO
    L'unità systemd «{$unita}» è fallita su {$istanza}.

    Quando: {$quando}

    Che cosa fare, nell'ordine:

      1. leggere il perché:      systemctl status {$unita}
      2. se è il rilascio, il log dice il comando per tornare indietro:
                                 tail -60 /var/log/pantedu-deploy.log
      3. quando è risolta:       systemctl reset-failed {$unita}

    Nessun ripristino automatico è stato tentato: su una macchina sola può
    fare più danni del guasto. Questo messaggio serve a far sapere, non a
    decidere.

    ── ultime righe del giornale ──────────────────────────────────────────

    TESTO;

if ($soppressi > 0) {
    $corpo = sprintf(
        "ATTENZIONE: questa unità è fallita altre %d volte da quando è partito\n"
        . "l'avviso precedente, e quegli avvisi sono stati soppressi per non\n"
        . "riempire la casella. Non è un guasto singolo: si ripete.\n\n",
        $soppressi
    ) . $corpo;
    $oggetto = sprintf('[Pantedu] %s è fallita (%d volte)', $unita, $soppressi + 1);
}

$corpo .= ultimeRighe($unita) . "\n";

if ($prova) {
    echo "destinatario: {$a}\n\n{$oggetto}\n\n{$corpo}\n";
    exit(0);
}

$mailer = Mailer::fromConfig();
if ($mailer === null) {
    fwrite(STDERR, "[avviso] la posta non è configurata su questa istanza: avviso non mandato.\n");
    exit(1);
}

if (!$mailer->send($a, $oggetto, $corpo)) {
    fwrite(STDERR, "[avviso] invio fallito verso {$a}.\n");
    exit(1);
}

echo "[avviso] mandato a {$a} per {$unita}\n";
exit(0);
