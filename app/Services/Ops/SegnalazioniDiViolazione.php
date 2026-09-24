<?php

declare(strict_types=1);

namespace App\Services\Ops;

use PDO;
use Throwable;

/**
 * Le segnalazioni di violazione che nessuno ha ancora valutato.
 *
 * ── Il buco che questa classe chiude ──────────────────────────────────────
 *
 * Il piano per le violazioni lo dichiarava come il proprio punto più debole:
 * una segnalazione che arriva dai moduli pubblici **non apre da sola** un
 * incidente nel registro, quindi è un passaggio che si può dimenticare. E le
 * due strade pubbliche — `/dpo-contact` con oggetto `breach_report`,
 * `/segnalazione-contenuti` con categoria `gdpr_art9` — sono proprio le sole
 * che possano rilevare il rischio R20, che nessun controllo automatico vede.
 * Perdere quella segnalazione vuol dire non accorgersene più, mentre le
 * settantadue ore dell'art. 33 scorrono lo stesso.
 *
 * ── Perché non si apre l'incidente da soli ────────────────────────────────
 *
 * I due moduli sono pubblici e senza autenticazione, e dalla migrazione 136
 * una riga del registro non si cancella mai. Farli scrivere direttamente
 * vorrebbe dire che chiunque può riempire per sempre un registro di
 * accountability. L'incidente lo apre una persona; il sistema si accorge se
 * non lo fa.
 *
 * ── Le sei ore ────────────────────────────────────────────────────────────
 *
 * La diagnostica gira alle 07:00 e alle 19:00. Con una soglia di sei ore
 * l'avviso parte entro dodici ore dalla segnalazione, e ne restano sessanta
 * delle settantadue. Una soglia più stretta non arriverebbe prima — è il
 * timer a decidere — e una più larga mangerebbe margine senza comprare nulla.
 */
final class SegnalazioniDiViolazione
{
    /** Ore dopo le quali una segnalazione senza incidente è un guasto. */
    public const ORE = 6;

    /** L'oggetto del modulo DPO che apre l'istruttoria dell'art. 33. */
    public const OGGETTO_DPO = 'breach_report';

    /** La categoria del modulo di segnalazione contenuti che fa lo stesso. */
    public const CATEGORIA_TAKEDOWN = 'gdpr_art9';

    /**
     * Quali segnalazioni sono in ritardo, fra quelle aperte.
     *
     * Pura: prende quello che il database ha restituito e decide. Si prova
     * senza database, ed è il motivo per cui sta qui e non dentro la query.
     *
     * L'età arriva **già calcolata dal database** e non si ricava qui da una
     * data. Misurato il 22/9/2026: `created_at` è una TIMESTAMP che MariaDB
     * restituisce in UTC, mentre `strtotime()` la leggeva con il fuso di PHP
     * (Europe/Rome) — una segnalazione di otto ore ne risultava dieci. Con una
     * soglia di sei ore un errore di due è molto, e sarebbe stato invisibile:
     * il controllo scattava comunque, solo con il numero sbagliato.
     *
     * @param list<array{fonte: string, id: int, ore: int}> $aperte
     *        segnalazioni senza incidente collegato, con le ore trascorse
     * @param int $ore soglia
     * @return list<array{fonte: string, id: int, ore: int}> in ritardo, dalla più vecchia
     */
    public static function inRitardo(array $aperte, int $ore = self::ORE): array
    {
        // Un'età negativa vuol dire che il database non l'ha saputa calcolare
        // (data nulla o illeggibile): non deve **nascondere** una
        // segnalazione, quindi conta come in ritardo. Sbaglia dalla parte del
        // rumore, che è l'unica accettabile qui.
        $fuori = array_values(array_filter(
            $aperte,
            static fn(array $s): bool => $s['ore'] < 0 || $s['ore'] >= $ore,
        ));

        usort($fuori, static fn(array $a, array $b): int => $b['ore'] <=> $a['ore']);

        return $fuori;
    }

    /** La colonna del legame esiste? (migrazione 137 applicata) */
    public static function legameEsiste(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT incident_id FROM dpo_requests LIMIT 0');
            $pdo->query('SELECT incident_id FROM takedown_requests LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Le segnalazioni di violazione aperte e senza incidente collegato.
     *
     * «Aperte» vuol dire: non marcate come posta indesiderata, e non chiuse.
     * Una segnalazione chiusa senza incidente è una valutazione che si è
     * conclusa con «non era una violazione», ed è legittima: l'art. 33 §5
     * chiede di documentare anche quelle, ma non è questo controllo a farlo.
     *
     * L'età la calcola il database, con il suo orologio: confrontare una
     * TIMESTAMP restituita in UTC con `time()` di PHP significa sbagliare di
     * tutto il fuso (vedi `inRitardo`).
     *
     * @return list<array{fonte: string, id: int, ore: int}>
     */
    public static function apertaSenzaIncidente(PDO $pdo): array
    {
        $fuori = [];

        $st = $pdo->prepare(
            'SELECT id, COALESCE(TIMESTAMPDIFF(HOUR, created_at, NOW()), -1) AS ore
               FROM dpo_requests
              WHERE subject = ? AND incident_id IS NULL
                AND status NOT IN ("spam", "closed")
              ORDER BY created_at'
        );
        $st->execute([self::OGGETTO_DPO]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fuori[] = ['fonte' => 'dpo', 'id' => (int)$r['id'], 'ore' => (int)$r['ore']];
        }

        $st = $pdo->prepare(
            'SELECT id, COALESCE(TIMESTAMPDIFF(HOUR, submitted_at, NOW()), -1) AS ore
               FROM takedown_requests
              WHERE violation_type = ? AND incident_id IS NULL
                AND status NOT IN ("rejected", "closed")
              ORDER BY submitted_at'
        );
        $st->execute([self::CATEGORIA_TAKEDOWN]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fuori[] = ['fonte' => 'takedown', 'id' => (int)$r['id'], 'ore' => (int)$r['ore']];
        }

        return $fuori;
    }

    /** Dove si va a guardarla, per il messaggio della diagnostica. */
    public static function dove(string $fonte, int $id): string
    {
        return $fonte === 'takedown'
            ? "/admin/takedown/{$id}"
            : "/admin/data-requests/{$id}";
    }

    /**
     * Lega una segnalazione all'incidente che ne è nato.
     *
     * È l'unico modo di far tacere l'invariante senza chiudere la
     * segnalazione: o si è aperto un incidente, o si è deciso che non era una
     * violazione. Non esiste una terza via, ed è voluto — la terza via
     * sarebbe «me ne occupo dopo», che è precisamente il difetto che questo
     * controllo esiste per rendere rumoroso.
     *
     * @param string $fonte 'dpo' oppure 'takedown'
     */
    public static function collega(PDO $pdo, string $fonte, int $id, int $incidente): bool
    {
        $tabella = $fonte === 'takedown' ? 'takedown_requests' : 'dpo_requests';
        $st = $pdo->prepare(
            "UPDATE {$tabella} SET incident_id = ? WHERE id = ? AND incident_id IS NULL"
        );
        $st->execute([$incidente, $id]);

        return $st->rowCount() > 0;
    }

    /**
     * Apre l'incidente dell'art. 33 a partire da una segnalazione e lascia
     * scritto il legame. Serve a due pannelli — quello delle richieste al DPO
     * e quello delle segnalazioni di contenuti — quindi sta qui e non in un
     * controller.
     *
     * `detected_at` è **adesso**, non la data della segnalazione: le
     * settantadue ore decorrono da quando il titolare ne viene a conoscenza, e
     * se la segnalazione è rimasta ferma la conoscenza è di oggi. Metterci la
     * data d'arrivo sembrerebbe più preciso e sarebbe una bugia in favore
     * nostro — per giunta impossibile da correggere dopo, perché il trigger
     * della migrazione 136 congela quel campo. `occurred_at` invece è la data
     * della segnalazione: è la stima migliore di quando il fatto è avvenuto.
     *
     * @return array{id: int}|array{errore: string} l'incidente, o il perché no
     */
    public static function apriIncidente(
        PDO $pdo,
        string $fonte,
        int $id,
        string $note,
        int $chi,
    ): array {
        if (!self::legameEsiste($pdo)) {
            return ['errore' => 'Migrazione 137 non applicata'];
        }

        $tabella = $fonte === 'takedown' ? 'takedown_requests' : 'dpo_requests';
        $colonna = $fonte === 'takedown' ? 'submitted_at' : 'created_at';

        // `adesso` lo dà il database, non `date()` di PHP.
        //
        // Misurato il 22/9/2026: l'applicazione gira su Europe/Rome
        // (app/bootstrap.php) e il database su UTC. Una data scritta da PHP
        // finisce quindi DUE ORE NEL FUTURO rispetto a `NOW()`, e `detected_at`
        // è il campo da cui decorrono le settantadue ore dell'art. 33 — per
        // giunta congelato dal trigger della migrazione 136, quindi
        // incorreggibile. Due ore di margine regalate a sé stessi, in un
        // registro che serve a dimostrare il contrario.
        $st = $pdo->prepare(
            "SELECT {$colonna} AS quando, incident_id, NOW() AS adesso FROM {$tabella} WHERE id = ?"
        );
        $st->execute([$id]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        if (!$riga) {
            return ['errore' => 'Segnalazione non trovata'];
        }
        // Già aperto: si torna su quello, non se ne apre un secondo. Le righe
        // del registro non si cancellano (migrazione 136), quindi un doppione
        // resterebbe lì per sempre.
        if ($riga['incident_id'] !== null) {
            return ['id' => (int)$riga['incident_id']];
        }

        $incidente = (new \App\Repositories\Gdpr\DataBreachRepository())->create([
            'occurred_at' => (string)$riga['quando'],
            'detected_at' => (string)$riga['adesso'],
            'severity'    => 'high',
            'description' => "Aperto da una segnalazione ({$fonte} #{$id}).\n\n" . $note,
        ], $chi);

        self::collega($pdo, $fonte, $id, $incidente);

        return ['id' => $incidente];
    }
}
