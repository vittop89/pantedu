<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit\ActivityLogger;
use App\Services\Contenuti\MaterialiSuSezioniNonAmmesse;
use App\Services\Contenuti\SpostamentoDiClasse;
use App\Support\AuthHelpers;
use InvalidArgumentException;

/**
 * Spostare i materiali da una classe a un'altra, dall'area del docente.
 *
 * Routes:
 *   GET  /area-docente/sposta-di-classe?classe={id}   l'elenco dei materiali della classe
 *   POST /area-docente/sposta-di-classe               sposta quelli scelti nella classe di arrivo
 *
 * ADR-041: fra le classi di partenza ci sono anche le sezioni che il docente non
 * può più usare (incarico tolto) ma dove ha ancora materiali, e in cima alla
 * pagina l'avviso di quanti sono (MaterialiSuSezioniNonAmmesse).
 *
 * Il lavoro sta in App\Services\Contenuti\SpostamentoDiClasse; qui c'e' solo
 * la pagina: la classe di partenza in query, le caselle, la classe di arrivo,
 * e il messaggio che dice cosa e' successo.
 */
final class TeacherMoveController
{
    private const PAGINA = '/area-docente/sposta-di-classe';

    private SpostamentoDiClasse $svc;

    public function __construct(?SpostamentoDiClasse $svc = null)
    {
        $this->svc = $svc ?? new SpostamentoDiClasse();
    }

    public function index(Request $req): Response
    {
        if (!AuthHelpers::isTeacherOrAdmin()) {
            return Response::html('<h1>403</h1><p>Solo docenti.</p>', 403);
        }
        $uid = (int)(Auth::user()['id'] ?? 0);
        if ($uid <= 0) {
            return Response::redirect('/login');
        }

        // ADR-041 — come partenza anche le sezioni che il docente non può più
        // usare ma dove ha ancora materiali; come arrivo solo le spuntate.
        $classi   = $this->svc->classiDiPartenza($uid);
        $arrivi   = array_values(array_filter($classi, static fn(array $c): bool => !$c['sospesa']));
        $classeDa = (int)($req->query['classe'] ?? 0);
        $partenza = null;
        foreach ($classi as $c) {
            if ($c['id'] === $classeDa) {
                $partenza = $c;
                break;
            }
        }
        $elenco = $partenza !== null ? $this->svc->elenco($uid, $classeDa) : null;
        $avvisoSezioni = (new MaterialiSuSezioniNonAmmesse())->avviso($uid);
        $csrf   = Csrf::token();
        $flash  = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        // Come le altre pagine /area-docente/*: la vista si include e si porta
        // dietro la cornice (nav + layout app.php, quindi sidebar).
        \ob_start();
        require __DIR__ . '/../../views/area_docente/sposta_di_classe.php';
        $html = (string)\ob_get_clean();

        $r = new Response($html, 200);
        $r->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $r;
    }

    public function save(Request $req): Response
    {
        if (!AuthHelpers::isTeacherOrAdmin()) {
            return Response::html('<h1>403</h1><p>Solo docenti.</p>', 403);
        }
        $uid       = (int)(Auth::user()['id'] ?? 0);
        $classeDa  = (int)($req->post['classe_da'] ?? 0);
        $classeA   = (int)($req->post['classe_a'] ?? 0);
        $contenuti = self::elencoId($req->post['contenuti'] ?? []);
        $verifiche = self::elencoId($req->post['verifiche'] ?? []);
        $torna     = self::PAGINA . ($classeDa > 0 ? '?classe=' . $classeDa : '');

        if ($classeA <= 0) {
            $_SESSION['flash'] = ['type' => 'warn', 'title' => 'Manca la classe di arrivo.',
                'message' => 'Scegli in quale classe spostare i materiali selezionati.'];
            return Response::redirect($torna);
        }
        try {
            $esito = $this->svc->sposta($uid, $contenuti, $verifiche, $classeDa, $classeA);
        } catch (InvalidArgumentException $e) {
            $codice = $e->getMessage();
            [$titolo, $messaggio] = match (true) {
                $codice === 'niente_da_spostare' => ['Nessun elemento selezionato.',
                    'Spunta almeno un contenuto o una verifica da spostare.'],
                $codice === 'classe_non_spuntata' => ['Classe non tua.',
                    'La classe di arrivo dev\'essere fra quelle che hai spuntato nel profilo. Una sezione di cui non hai l\'incarico può essere solo di partenza.'],
                $codice === 'stessa_classe' => ['Stessa classe.',
                    'La classe di arrivo è quella di partenza: niente da spostare.'],
                $codice === 'istituti_diversi' => ['Due scuole diverse.',
                    'La classe di arrivo dev\'essere della stessa scuola di quella di partenza: materia e corso dei materiali sono di quella scuola.'],
                str_starts_with($codice, 'indirizzo_non_spuntato:') => ['Manca il corso della classe di arrivo.',
                    sprintf('Quella sezione è del corso «%s», che non hai spuntato nel profilo: spuntalo, poi riprova.',
                        substr($codice, strlen('indirizzo_non_spuntato:')))],
                default => ['Spostamento non riuscito.', $codice],
            };
            $_SESSION['flash'] = ['type' => 'error', 'title' => $titolo, 'message' => $messaggio];
            return Response::redirect($torna);
        }

        ActivityLogger::event(
            'content_moved_class',
            subjectType: 'user',
            subjectId:   (string)$uid,
            // ADR-037, S6: i posti toccati, con gli id.
            details:     ['da' => $classeDa, 'a' => $classeA, 'classe' => $esito['classe'],
                          'indirizzo' => $esito['indirizzo'],
                          'da_classe' => $esito['da_classe'], 'da_indirizzo' => $esito['da_indirizzo'],
                          'ids' => $esito['ids'], 'contenuti' => $esito['contenuti'],
                          'verifiche' => $esito['verifiche'], 'varianti' => $esito['varianti'],
                          'doppi' => $esito['doppi'], 'posti' => $esito['posti']],
        );
        $totale = $esito['contenuti'] + $esito['verifiche'];
        $messaggio = $esito['indirizzo'] !== null
            ? sprintf('Ora stanno nella classe %s del corso %s: la barra laterale li trova lì.', $esito['classe'], $esito['indirizzo'])
            : sprintf('Ora stanno nella classe %s: la barra laterale li trova lì.', $esito['classe']);
        if ($esito['doppi'] > 0) {
            $messaggio .= ' ' . sprintf(
                $esito['doppi'] === 1
                    ? 'Un materiale era già pubblicato in %s con uno stato diverso: ora ha due posti in quella classe, scegli quale tenere da «Dove vale».'
                    : '%2$d materiali erano già pubblicati in %1$s con uno stato diverso: ora hanno due posti in quella classe, scegli quale tenere da «Dove vale».',
                $esito['classe'],
                $esito['doppi']
            );
        }
        $_SESSION['flash'] = $totale > 0
            ? ['type' => $esito['doppi'] > 0 ? 'warn' : 'success',
               'title' => self::titoloEsito($esito),
               'message' => $messaggio]
            : ['type' => 'warn', 'title' => 'Nessuna modifica.',
               'message' => 'Gli elementi scelti non erano tuoi, o non esistono più.'];
        return Response::redirect(self::PAGINA . '?classe=' . $classeA);
    }

    /**
     * «55 contenuti e 1 verifica spostati da «1» a «3».», con il corso delle
     * sezioni. Fino al 15/9/2026 diceva solo l'arrivo: dopo uno spostamento
     * sbagliato non si capiva da dove fossero partiti.
     *
     * @param array{contenuti:int,verifiche:int,classe:string,indirizzo:?string,da_classe:string,da_indirizzo:?string} $esito
     */
    public static function titoloEsito(array $esito): string
    {
        $nome = static fn(string $codice, ?string $indirizzo): string => $indirizzo !== null && $indirizzo !== ''
            ? $codice . ' · ' . $indirizzo
            : $codice;
        return sprintf(
            '%s da «%s» a «%s».',
            self::conta($esito),
            $nome($esito['da_classe'], $esito['da_indirizzo']),
            $nome($esito['classe'], $esito['indirizzo'])
        );
    }

    /** @param array{contenuti:int,verifiche:int} $esito */
    private static function conta(array $esito): string
    {
        $parti = [];
        if ($esito['contenuti'] > 0) {
            $parti[] = $esito['contenuti'] === 1 ? '1 contenuto spostato' : $esito['contenuti'] . ' contenuti spostati';
        }
        if ($esito['verifiche'] > 0) {
            $parti[] = $esito['verifiche'] === 1 ? '1 verifica spostata' : $esito['verifiche'] . ' verifiche spostate';
        }
        return implode(' e ', $parti);
    }

    /**
     * @param mixed $valori  `contenuti[]` dalle caselle, o una stringa «1,2,3»
     * @return list<int>
     */
    private static function elencoId(mixed $valori): array
    {
        if (\is_string($valori)) {
            $valori = explode(',', $valori);
        }
        if (!\is_array($valori)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', $valori), static fn(int $i): bool => $i > 0));
    }
}
