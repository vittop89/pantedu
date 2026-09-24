<?php

declare(strict_types=1);

namespace App\Services\Study;

use App\Core\Config;
use App\Core\Session;
use App\Domain\ViewerContext;
use App\Repositories\TeacherContentRepository;
use App\Services\CurriculumService;
use App\Support\Anomalia;

/**
 * Le materie che chi studia trova nel selettore della barra: solo quelle in
 * cui c'è almeno un materiale che può vedere (19/9/2026).
 *
 * Prima il selettore offriva tutto il vocabolario attivo della scuola: in
 * produzione, alla credenziale di una terza, diciassette materie di cui
 * quindici vuote; e senza una materia scelta ogni pannello chiedeva i
 * contenuti una volta per materia.
 *
 * **Stesso gate dell'elenco, niente query parallela.** Per ogni materia si
 * chiede a TeacherContentRepository::search, con i filtri di ChiStudia e
 * `limit 1`, se esiste una riga: è la stessa domanda che fa
 * /api/study/content.json, quindi selettore ed elenco non possono divergere.
 * Una SELECT DISTINCT unica costerebbe meno, ma dovrebbe leggere la materia
 * della pubblicazione (una pubblicazione in un'altra scuola può chiamarla in
 * un altro modo) e ripetere il perimetro: sarebbe una seconda copia del gate.
 *
 * **Cache breve.** Una materia per query, fino a diciassette query per pagina:
 * il risultato si tiene per la richiesta e, in sessione, per sessanta secondi
 * (la stessa finestra di /api/study/topics.json). Un materiale appena
 * pubblicato arriva nel selettore entro un minuto.
 *
 * Docenti e amministratori non passano di qui: il loro selettore resta quello
 * delle loro materie.
 */
final class MaterieConMateriali
{
    /** Chiave di sessione della cache: una mappa chiave → {t, v}. */
    public const CHIAVE_SESSIONE = 'fm_materie_con_materiali';
    /** Secondi di validità di una risposta in sessione. */
    public const FINESTRA = 60;
    /** Quante risposte tenere in sessione (una per classe guardata, in pratica). */
    private const MAX_VOCI = 8;

    /** @var array<string, list<string>> cache per richiesta */
    private static array $perRichiesta = [];

    private ChiStudia $chi;
    private TeacherContentRepository $repo;

    public function __construct(?ChiStudia $chi = null, ?TeacherContentRepository $repo = null)
    {
        $this->chi  = $chi  ?? new ChiStudia();
        $this->repo = $repo ?? new TeacherContentRepository();
    }

    /**
     * Il vocabolario delle materie della scuola di chi studia — lo stesso che
     * il layout mette nella barra — o null se chi guarda non studia (docente,
     * amministratore, visitatore senza credenziale).
     *
     * @return list<array<string,mixed>>|null
     */
    public static function vocabolarioDiChiStudia(): ?array
    {
        $scuola = ChiStudia::scuolaDelVocabolario();
        if ($scuola === null) {
            return null;
        }
        $svc = new CurriculumService(
            jsonPath:  Config::get('app.paths.storage') . '/data/curriculum.json',
        );
        return array_values($svc->allActiveForInstitute($scuola)['materie'] ?? []);
    }

    /**
     * Le materie per il selettore della barra, e se il filtro ha funzionato.
     *
     * Il calcolo non deve far cadere la pagina: se fallisce — una query che
     * non gira, una colonna cambiata da una migrazione, la sessione non
     * scrivibile — resta il vocabolario intero, che è quello che si vedeva
     * prima del 19/9/2026. Ma quel ripiego **è** il difetto corretto qui:
     * diciassette materie di cui quindici vuote, e i pannelli che chiedono i
     * contenuti una volta per materia. Tornarci in silenzio significherebbe
     * non accorgersene mai, quindi si scrive nel registro delle anomalie, che
     * `tools/ops/diagnostica.php` legge a timer (CLAUDE.md: gli errori si
     * correggono, non si silenziano).
     *
     * `filtrate` false toglie alla barra il segno
     * `data-fm-materie-di-chi-studia`: senza il filtro quelle voci non sono
     * più «tutte e sole le materie da chiedere», e i pannelli tornano al
     * comportamento di prima invece di fidarsi di un elenco non calcolato.
     *
     * @param list<array<string,mixed>> $voci
     * @param ?callable(list<array<string,mixed>>): list<array<string,mixed>> $calcolo per le prove
     * @return array{materie: list<array<string,mixed>>, filtrate: bool}
     */
    public static function perLaBarra(array $voci, ?callable $calcolo = null): array
    {
        $calcolo ??= static fn(array $v): array => (new self())->filtra($v);
        try {
            return ['materie' => $calcolo($voci), 'filtrate' => true];
        } catch (\Throwable $e) {
            Anomalia::registra(
                'materie_di_chi_studia_non_filtrate',
                'Il selettore delle materie di chi studia è tornato al vocabolario intero: il filtro non è riuscito.',
                [
                    'errore'    => $e::class,
                    // Niente segreti: il messaggio di un guasto di query dice
                    // la colonna, non la credenziale, e si taglia comunque.
                    'messaggio' => mb_substr($e->getMessage(), 0, 300),
                    'dove'      => basename($e->getFile()) . ':' . $e->getLine(),
                    'materie'   => \count($voci),
                ],
            );
            return ['materie' => array_values($voci), 'filtrate' => false];
        }
    }

    /**
     * Le voci di materia (righe del vocabolario) con almeno un materiale
     * visibile a chi guarda, nell'ordine in cui arrivano.
     *
     * @param list<array<string,mixed>> $voci
     * @param ?string $classe   la classe chiesta (null: quella di chi guarda)
     * @param ?string $indirizzo l'indirizzo chiesto (null: quello di chi guarda)
     * @return list<array<string,mixed>>
     */
    public function filtra(array $voci, ?string $classe = null, ?string $indirizzo = null): array
    {
        $codici = [];
        foreach ($voci as $v) {
            $c = trim((string)($v['code'] ?? ''));
            if ($c !== '') {
                $codici[$c] = true;
            }
        }
        $con = array_flip($this->codici(array_keys($codici), $classe, $indirizzo));
        return array_values(array_filter(
            $voci,
            static fn(array $v): bool => isset($con[trim((string)($v['code'] ?? ''))])
        ));
    }

    /**
     * I codici di materia, fra quelli dati, con almeno un materiale visibile a
     * chi guarda. Per chi vede tutti gli scope (docente, amministratore) tornano
     * tutti: il filtro è per chi studia.
     *
     * @param list<string> $codici
     * @return list<string>
     */
    public function codici(array $codici, ?string $classe = null, ?string $indirizzo = null): array
    {
        $ctx = $this->chi->contesto();
        if ($ctx->canSeeAllScopes()) {
            return array_values($codici);
        }
        // Senza una classe chiesta vale quella che la barra mostra al primo
        // disegno, cioè quella di chi guarda: è ciò che chiederanno i pannelli.
        $classe    = $classe    ?? $ctx->classe;
        $indirizzo = $indirizzo ?? $ctx->indirizzo;

        $chiave = $this->chiave($ctx, $codici, $classe, $indirizzo);
        if (isset(self::$perRichiesta[$chiave])) {
            return self::$perRichiesta[$chiave];
        }
        $salvato = self::daSessione($chiave);
        if ($salvato !== null) {
            return self::$perRichiesta[$chiave] = $salvato;
        }

        // Il perimetro si calcola una volta: la materia è l'unico filtro che
        // cambia da una domanda all'altra, e ChiStudia::filtri() non la tocca.
        $params = [];
        if ($classe !== null && $classe !== '') {
            $params['cls'] = $classe;
        }
        if ($indirizzo !== null && $indirizzo !== '') {
            $params['ind'] = $indirizzo;
        }
        $perimetro = $this->chi->filtri($params);

        $con = [];
        foreach ($codici as $code) {
            $filtri = $perimetro;
            $filtri['subject_code'] = $code;
            $filtri['limit'] = 1;
            if ($this->repo->search($filtri) !== []) {
                $con[] = $code;
            }
        }

        self::$perRichiesta[$chiave] = $con;
        self::inSessione($chiave, $con);
        return $con;
    }

    /** Per i test (e per chi pubblica e vuole vedere subito): dimentica le cache. */
    public static function dimentica(): void
    {
        self::$perRichiesta = [];
        try {
            Session::forget(self::CHIAVE_SESSIONE);
        } catch (\Throwable) {
            // senza sessione non c'è niente da dimenticare
        }
    }

    /**
     * Chi guarda, la classe chiesta e le materie candidate: se cambia una di
     * queste cose la risposta non vale più.
     *
     * @param list<string> $codici
     */
    private function chiave(ViewerContext $ctx, array $codici, ?string $classe, ?string $indirizzo): string
    {
        $grants = [];
        foreach ($ctx->grants as $g) {
            $grants[] = [
                (int)($g['teacher_id'] ?? 0),
                (int)($g['credential_id'] ?? 0),
                (int)($g['institute_id'] ?? 0),
                (string)($g['indirizzo'] ?? ''),
                (string)($g['classe'] ?? ''),
            ];
        }
        sort($codici);
        return sha1((string)json_encode([
            $ctx->role?->value, $ctx->teacherId, $ctx->instituteId, $ctx->indirizzo, $ctx->classe,
            $ctx->storico, $grants, $classe, $indirizzo, $codici,
        ]));
    }

    /** @return list<string>|null */
    private static function daSessione(string $chiave): ?array
    {
        try {
            $mappa = Session::get(self::CHIAVE_SESSIONE);
        } catch (\Throwable) {
            return null;
        }
        $voce = \is_array($mappa) ? ($mappa[$chiave] ?? null) : null;
        if (!\is_array($voce) || !isset($voce['t'], $voce['v']) || !\is_array($voce['v'])) {
            return null;
        }
        if (time() - (int)$voce['t'] >= self::FINESTRA) {
            return null;
        }
        return array_values(array_map('strval', $voce['v']));
    }

    /** @param list<string> $valore */
    private static function inSessione(string $chiave, array $valore): void
    {
        try {
            $mappa = Session::get(self::CHIAVE_SESSIONE);
            $mappa = \is_array($mappa) ? $mappa : [];
            $ora = time();
            // Via le risposte scadute, e le più vecchie oltre il tetto.
            $mappa = array_filter(
                $mappa,
                static fn($v): bool => \is_array($v) && isset($v['t']) && $ora - (int)$v['t'] < self::FINESTRA
            );
            $mappa[$chiave] = ['t' => $ora, 'v' => $valore];
            if (\count($mappa) > self::MAX_VOCI) {
                uasort($mappa, static fn(array $a, array $b): int => (int)$a['t'] <=> (int)$b['t']);
                $mappa = \array_slice($mappa, -self::MAX_VOCI, null, true);
            }
            Session::put(self::CHIAVE_SESSIONE, $mappa);
        } catch (\Throwable) {
            // sessione non disponibile (CLI): resta la cache per richiesta
        }
    }
}
