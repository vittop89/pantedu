<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\DeploymentScenario;

/**
 * Il docente che non ha indicato nessuna scuola.
 *
 * ── Perche' esiste (22 settembre 2026) ────────────────────────────────────
 *
 * La scuola e' un dato diverso dall'indirizzo e dalla classe: quelle dicono
 * che cosa insegni, la scuola dice **dove lavori**. E' un dato che identifica
 * il posto di lavoro di una persona, e chiederlo per forza a chi si iscrive a
 * uno strumento personale non e' proporzionato — l'art. 5(1)(c) chiede di
 * raccogliere quello che serve, non quello che torna comodo.
 *
 * ── La sorpresa: lo stato esisteva gia' ───────────────────────────────────
 *
 * Misurato il 22/9/2026: «docente senza scuola» era **gia' raggiungibile**, in
 * due clic dal profilo, perche' lo scollegamento non controlla che ne resti
 * almeno una. Non si sta inventando un caso nuovo: si sta dichiarando un caso
 * che esisteva e che nessuno aveva mai guardato.
 *
 * E non era guardato bene. Sedici punti del codice rispondevano 404
 * `institute_not_found`, e **nessun pezzo di interfaccia traduceva quel
 * codice**: al docente arrivava l'errore grezzo di rete. Il «catalogo
 * globale», che sembrava la rete di sicurezza, restituiva zero voci di ogni
 * tipo — la colonna e' NOT NULL e le righe globali le aveva cancellate la
 * migrazione 043.
 *
 * ── Perche' l'elenco sta qui e non in sedici posti ────────────────────────
 *
 * Lo stesso elenco serve in tre momenti: quando il docente sceglie di non
 * indicare la scuola all'iscrizione, quando apre una pagina che senza scuola
 * non ha niente da mostrare, e quando scollega l'ultima dal profilo. Scritto
 * tre volte, dopo un mese ne esistono tre versioni diverse e una sola e' vera.
 *
 * ── Lo scenario 3 e' un'altra cosa ────────────────────────────────────────
 *
 * Dove la piattaforma e' adottata da un Istituto, la scuola resta
 * obbligatoria. Non per una ragione di principio: li' un docente senza scuola
 * non puo' avere incarichi di sezione ne' pubblicare, e un account che non puo'
 * fare niente e' peggio di un campo in piu'. Scelta dell'utente, 22/9/2026.
 */
final class SenzaScuola
{
    /**
     * Che cosa resta spento. In ordine di quanto e' probabile che serva.
     *
     * @return list<array{cosa: string, perche: string}>
     */
    public static function cosaSiPerde(): array
    {
        return [
            [
                'cosa'   => 'il catalogo di indirizzi, classi e materie',
                'perche' => 'e\' della scuola: senza, gli elenchi restano vuoti e non si possono '
                          . 'scegliere indirizzo, classe o materia',
            ],
            [
                'cosa'   => 'le fonti e i libri in adozione',
                'perche' => 'sono le adozioni di quella scuola',
            ],
            [
                'cosa'   => 'le credenziali di classe',
                'perche' => 'una credenziale legata a una classe ha bisogno del catalogo della scuola '
                          . '(senza classe si crea lo stesso)',
            ],
            [
                'cosa'   => 'gli incarichi sulle sezioni',
                // 2026-09-22 — diceva «li da' l'amministratore della scuola», e
                // lo diceva al docente sul modulo d'iscrizione, due paragrafi
                // sopra al testo che lo attribuisce correttamente
                // all'amministratore della PIATTAFORMA. Chi istruisce un
                // reclamo prende la prima delle due.
                'perche' => 'li da\' l\'amministratore della piattaforma, e senza una scuola '
                          . 'non c\'e\' su quali classi darli',
            ],
            [
                'cosa'   => 'la pubblicazione in rete e la condivisione con i colleghi',
                'perche' => 'le une e le altre passano per la scuola',
            ],
            [
                'cosa'   => 'il nome e l\'intestazione della scuola nei documenti',
                'perche' => 'i documenti si generano lo stesso, con l\'intestazione predefinita',
            ],
        ];
    }

    /**
     * Che cosa continua a funzionare. Si dice, perche' un elenco di sole
     * perdite fa credere che senza scuola non si possa fare niente — e non e'
     * vero.
     *
     * @return list<string>
     */
    public static function cosaResta(): array
    {
        return [
            'scrivere esercizi, verifiche, mappe e documenti',
            'compilare i modelli e generarne il PDF',
            'le credenziali di classe senza una classe',
            'le copie sul proprio Drive o su GitHub',
        ];
    }

    /**
     * La scuola e' obbligatoria in questa istanza?
     *
     * Solo nello scenario 3. Negli altri due il docente si iscrive come
     * professionista, e dove lavora sono affari suoi.
     */
    public static function obbligatoria(): bool
    {
        return DeploymentScenario::isInstitute();
    }

    /**
     * La frase da mostrare a chi non ne ha una. Una sola, tenuta qui.
     */
    public static function avviso(): string
    {
        $perse = array_map(static fn(array $v): string => $v['cosa'], self::cosaSiPerde());

        return 'Non hai indicato una scuola. Senza, restano spenti: '
            . self::elenco($perse) . '. La aggiungi quando vuoi dal tuo profilo.';
    }

    /**
     * Il corpo che le API mandano quando il docente non ha una scuola.
     *
     * ── Perche' il codice di stato resta quello di prima ──────────────────
     *
     * Verrebbe voglia di rispondere 200 con un elenco vuoto: sarebbe piu'
     * elegante e sarebbe una bugia sulle rotte che **scrivono**, dove «non hai
     * una scuola» vuol dire che l'operazione non e' avvenuta. E cambiare il
     * codice di stato di tredici rotte insieme rompe i client in silenzio, che
     * e' esattamente cio' che non si vuole fare mentre si sistema un difetto
     * di comunicazione.
     *
     * Resta quindi il 404, e resta `error` per chi lo leggeva. Cambia cio' che
     * mancava: **la spiegazione e dove si rimedia**. Prima il corpo era
     * `{"error":"institute_not_found"}` e nessun pezzo di interfaccia lo
     * traduceva: al docente arrivava un errore di rete grezzo.
     *
     * @return array<string,mixed>
     */
    public static function risposta(): array
    {
        return [
            'error'        => 'institute_not_found',
            'senza_scuola' => true,
            'messaggio'    => self::avviso(),
            'dove'         => '/area-docente/profilo',
        ];
    }

    /** @param list<string> $voci */
    private static function elenco(array $voci): string
    {
        if ($voci === []) {
            return '';
        }
        if (\count($voci) === 1) {
            return $voci[0];
        }
        $ultima = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultima;
    }
}
