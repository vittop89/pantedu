<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Le classi che uno studente puo' guardare (piano classi-credenziali-scenari, D).
 *
 * Dalla classe attuale si deriva l'insieme:
 *   - l'anno in corso con la sua sezione («3A», che per ClassCode include i «3»);
 *   - gli anni precedenti al livello di anno («2», «1»);
 *   - le sezioni degli anni passati solo se note, cioe' registrate nello
 *     storico del profilo («2B»), mai indovinate;
 *   - nessun anno futuro: «4» e «5» restano fuori, salvo cio' che il docente
 *     pubblica come «generale».
 *
 * Vale per lo studente con account e per l'ospite con credenziale di classe
 * (che non ha storico). Le verifiche degli anni passati restano fuori per
 * default: lo decide il filtro dei contenuti, non questa classe.
 *
 * Puro: nessun DB, nessuna sessione.
 */
final class ClassiFrequentate
{
    /** Anno di corso → nome, come lo dice la scuola. */
    public const ANNI = [
        1 => 'Prima',
        2 => 'Seconda',
        3 => 'Terza',
        4 => 'Quarta',
        5 => 'Quinta',
    ];

    /**
     * Le classi guardabili, dalla piu' recente. Vuoto se la classe attuale non
     * e' un codice riconosciuto: il chiamante la usa cosi' com'e', senza archivio.
     *
     * @param list<string> $storico sezioni passate note («2B», «1A»)
     * @return list<array{classe:string,anno:string,corrente:bool,label:string}>
     */
    public static function per(?string $classeAttuale, array $storico = []): array
    {
        $attuale = ClassCode::normalize($classeAttuale);
        $anno    = ClassCode::anno($attuale);
        if ($anno === null) {
            return [];
        }
        $out = [[
            'classe'   => $attuale,
            'anno'     => $anno,
            'corrente' => true,
            'label'    => self::label($attuale, true),
        ]];
        // Sezioni passate note, una per anno: se lo storico ne ha due dello
        // stesso anno (ripetenza), vale la prima in elenco, cioe' la piu' recente.
        /** @var array<int,string> $sezioniPassate anno → sezione nota */
        $sezioniPassate = [];
        foreach ($storico as $s) {
            $a = ClassCode::anno($s);
            if ($a === null || (int)$a >= (int)$anno || !ClassCode::isSezione($s)) {
                continue;
            }
            $sezioniPassate[(int)$a] ??= ClassCode::normalize($s);
        }
        for ($y = (int)$anno - 1; $y >= 1; $y--) {
            $c = $sezioniPassate[$y] ?? (string)$y;
            $out[] = [
                'classe'   => $c,
                'anno'     => (string)$y,
                'corrente' => false,
                'label'    => self::label($c, false),
            ];
        }
        return $out;
    }

    /**
     * La classe da guardare davvero se $richiesta e' ammessa, altrimenti null.
     *
     * «2» chiesto quando lo storico dice «2B» apre 2B (che include i «2»):
     * la sezione nota e' sempre la vista piu' completa di quell'anno.
     *
     * @param list<string> $storico
     */
    public static function ammette(?string $classeAttuale, array $storico, ?string $richiesta): ?string
    {
        $r = ClassCode::normalize($richiesta);
        if ($r === '') {
            return null;
        }
        foreach (self::per($classeAttuale, $storico) as $e) {
            if (strcasecmp($e['classe'], $r) === 0) {
                return $e['classe'];
            }
            if (!$e['corrente'] && $e['anno'] === strtoupper($r)) {
                return $e['classe'];
            }
        }
        return null;
    }

    /** «Terza · 3A (la tua classe)», «Seconda · 2B», «Prima». */
    public static function label(string $classe, bool $corrente): string
    {
        $anno = ClassCode::anno($classe);
        $nome = $anno !== null ? (self::ANNI[(int)$anno] ?? $anno . 'ª') : $classe;
        $sez  = ClassCode::isSezione($classe) ? ' · ' . strtoupper(ClassCode::normalize($classe)) : '';
        return $nome . $sez . ($corrente ? ' (la tua classe)' : '');
    }
}
