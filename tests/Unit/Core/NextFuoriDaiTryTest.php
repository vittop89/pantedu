<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Un middleware chiama `$next` fuori dai try che hanno un catch (23/9/2026).
 *
 * `$next` è il resto della richiesta: gli altri middleware e il controller.
 * Chiamarlo dentro un try che ha un catch significa che il catch prende anche
 * le eccezioni del controller. Se poi il catch richiama `$next`, il controller
 * gira una seconda volta e ne ripete gli effetti; se risponde altro, l'errore
 * sparisce travestito da quella risposta. Il 5/9/2026 il WAF rispondeva 403
 * «waf_challenge» a un import mancante in GroupController; il 23/9 la
 * revisione architetturale (A-1) ha misurato il controller rieseguito da tre
 * cancelli, e cercando lo schema negli altri middleware se n'è trovato un
 * quarto, `teacher_subjects`, scritto il 14/9: lo schema torna. La forma
 * giusta: il try avvolge solo i controlli e produce una decisione, e `$next`
 * si chiama fuori (vedi wiki/routing-and-api.md, «Middleware»).
 *
 * Un try con il solo `finally` non conta: non prende eccezioni. Se un giorno
 * servisse un catch che registra e rilancia, si cambia questa regola, non la
 * si aggira. Il Kernel non chiama `$next`: la sua regola è in
 * KernelEccezioniTest.
 *
 * Che cosa vede: una chiamata diretta `$next(...)`, o di una variabile che è
 * un alias diretto di `$next` nello stesso file (`$avanti = $next;`, anche a
 * catena), dentro il corpo di un try che ha un catch o dentro un catch.
 * Che cosa NON vede, e va guardato a mano in revisione:
 *  - `call_user_func($next, ...)`, `call_user_func_array`, `($next)(...)`,
 *    `$next->__invoke(...)`;
 *  - `$next` passato a un aiutante (`$this->avanti($next)`, `array_map`) o
 *    messo in una proprietà (`$this->next = $next`), e chiamato da lì;
 *  - un alias che non è un assegnamento diretto (`$x = $a ?: $next`,
 *    `[$x] = [$next]`) o che nasce in un altro file;
 *  - una closure che cattura `$next` (`use ($next)`), definita fuori dal try e
 *    chiamata dentro.
 * Per i middleware globali e per `teacher_subjects` c'è anche la prova
 * dinamica (KernelEccezioniTest, TosAcceptanceMiddlewareTest), che guarda il
 * comportamento e non la forma; per gli altri middleware c'è solo questa.
 * L'alias si riconosce per nome in tutto il file: se lo stesso nome indica
 * altro in un'altra funzione, la prova può dare un rosso falso, mai un verde
 * falso.
 */
final class NextFuoriDaiTryTest extends TestCase
{
    /**
     * Le righe delle chiamate `$next(...)`, o di un suo alias diretto, che
     * stanno nel corpo di un try con almeno un catch, o nel corpo di un catch.
     *
     * @return list<int>
     */
    public static function chiamateProtette(string $sorgente): array
    {
        $t = token_get_all($sorgente);
        $n = \count($t);
        $nomi = self::aliasDiNext($t);

        // Gli intervalli di token [apertura, chiusura] dei corpi a rischio.
        $intervalli = [];
        for ($i = 0; $i < $n; $i++) {
            if (!\is_array($t[$i]) || !\in_array($t[$i][0], [T_TRY, T_CATCH], true)) {
                continue;
            }
            $apre = self::prossimaGraffa($t, $i);
            $chiude = self::graffaCheChiude($t, $apre);
            if ($t[$i][0] === T_CATCH || self::prossimoSignificativo($t, $chiude) === T_CATCH) {
                $intervalli[] = [$apre, $chiude];
            }
        }

        $righe = [];
        for ($i = 0; $i < $n; $i++) {
            if (!\is_array($t[$i]) || $t[$i][0] !== T_VARIABLE || !isset($nomi[$t[$i][1]])) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n && \is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (($t[$j] ?? null) !== '(') {
                continue; // passato come argomento, non chiamato
            }
            foreach ($intervalli as [$apre, $chiude]) {
                if ($i > $apre && $i < $chiude) {
                    $righe[] = $t[$i][2];
                    break;
                }
            }
        }
        return $righe;
    }

    /**
     * `$next` e le variabili che ne sono un alias diretto: `$x = $next;`, e poi
     * `$y = $x;` e così via finché se ne trovano. Solo l'assegnamento semplice
     * a una variabile, chiuso dal punto e virgola: `$x = $next($req);` è una
     * chiamata, non un alias.
     *
     * @param list<mixed> $t
     * @return array<string, true>
     */
    private static function aliasDiNext(array $t): array
    {
        $nomi = ['$next' => true];
        do {
            $prima = \count($nomi);
            for ($i = 0, $n = \count($t); $i < $n; $i++) {
                if (!\is_array($t[$i]) || $t[$i][0] !== T_VARIABLE || isset($nomi[$t[$i][1]])) {
                    continue;
                }
                $uguale = self::indiceSignificativo($t, $i);
                if ($uguale === null || $t[$uguale] !== '=') {
                    continue;
                }
                $valore = self::indiceSignificativo($t, $uguale);
                if ($valore === null || !\is_array($t[$valore]) || $t[$valore][0] !== T_VARIABLE) {
                    continue;
                }
                $fine = self::indiceSignificativo($t, $valore);
                if (isset($nomi[$t[$valore][1]]) && $fine !== null && $t[$fine] === ';') {
                    $nomi[$t[$i][1]] = true;
                }
            }
        } while (\count($nomi) > $prima);
        return $nomi;
    }

    /**
     * L'indice del primo token dopo `$da` che non è spazio né commento.
     *
     * @param list<mixed> $t
     */
    private static function indiceSignificativo(array $t, int $da): ?int
    {
        for ($i = $da + 1, $n = \count($t); $i < $n; $i++) {
            $tok = $t[$i];
            if (\is_array($tok) && \in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /** @param list<mixed> $t */
    private static function prossimaGraffa(array $t, int $da): int
    {
        for ($i = $da + 1, $n = \count($t); $i < $n; $i++) {
            if ($t[$i] === '{') {
                return $i;
            }
        }
        return $n;
    }

    /** @param list<mixed> $t */
    private static function graffaCheChiude(array $t, int $apre): int
    {
        $profondita = 0;
        for ($i = $apre, $n = \count($t); $i < $n; $i++) {
            $tok = $t[$i];
            $apreNellaStringa = \is_array($tok)
                && \in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
            if ($tok === '{' || $apreNellaStringa) {
                $profondita++;
            } elseif ($tok === '}') {
                $profondita--;
                if ($profondita === 0) {
                    return $i;
                }
            }
        }
        return $n;
    }

    /**
     * Il tipo del primo token dopo `$da` che non è spazio né commento.
     *
     * @param list<mixed> $t
     */
    private static function prossimoSignificativo(array $t, int $da): int|string|null
    {
        $i = self::indiceSignificativo($t, $da);
        if ($i === null) {
            return null;
        }
        return \is_array($t[$i]) ? $t[$i][0] : $t[$i];
    }

    #[Test]
    public function nessun_middleware_chiama_next_dentro_un_try_con_catch(): void
    {
        $file = glob(\dirname(__DIR__, 3) . '/app/Middleware/*.php') ?: [];
        // Una regola che non guarda niente dice sempre di sì: i middleware
        // erano quindici il 23/9/2026.
        $this->assertGreaterThan(10, \count($file), 'i middleware sono una quindicina, non zero');

        $protette = [];
        foreach ($file as $f) {
            foreach (self::chiamateProtette((string)file_get_contents($f)) as $riga) {
                $protette[] = 'app/Middleware/' . basename($f) . ':' . $riga;
            }
        }
        $this->assertSame([], $protette, sprintf(
            "%d chiamate a \$next dentro un try con catch, o dentro un catch.\n\n"
            . "Il catch prenderebbe anche le eccezioni del controller, e il controller "
            . "girerebbe due volte (o il suo errore sparirebbe). Nel try metti solo il "
            . "controllo e una decisione; chiama \$next dopo.\n\n  %s",
            \count($protette),
            implode("\n  ", $protette)
        ));
    }

    /** Il controllo, nei due versi: trova la forma di prima, lascia stare quella giusta. */
    #[Test]
    public function il_controllo_trova_la_forma_sbagliata_e_lascia_stare_quella_giusta(): void
    {
        $sbagliata = <<<'PHP'
            <?php
            function prima($req, $next) {
                try {
                    if (controlla()) {
                        return $next($req);
                    }
                } catch (\Throwable $e) {
                    return $next($req);
                }
            }
            PHP;
        $this->assertSame([5, 8], self::chiamateProtette($sbagliata));

        $giusta = <<<'PHP'
            <?php
            function dopo($req, $next) {
                try {
                    $passa = controlla("{$req->path}");
                } catch (\Throwable $e) {
                    $passa = true;
                }
                if ($passa) {
                    return $next($req);
                }
                try {
                    return $next($req);
                } finally {
                    pulisci();
                }
                return altro(fn($r) => $next);
            }
            PHP;
        $this->assertSame([], self::chiamateProtette($giusta));
    }

    /** L'alias diretto, nei due versi: dentro il try si vede, fuori no. */
    #[Test]
    public function il_controllo_vede_anche_l_alias_diretto_di_next(): void
    {
        $sbagliata = <<<'PHP'
            <?php
            function prima($req, $next) {
                $avanti = $next;
                $ancora = $avanti;
                try {
                    return $avanti($req);
                } catch (\Throwable $e) {
                    return $ancora($req);
                }
            }
            PHP;
        $this->assertSame([6, 8], self::chiamateProtette($sbagliata));

        $giusta = <<<'PHP'
            <?php
            function dopo($req, $next) {
                $avanti = $next;
                $risposta = $next($req);
                try {
                    $passa = controlla($risposta);
                    $conto = $risposta($req);
                } catch (\Throwable $e) {
                    $passa = true;
                }
                return $passa ? $avanti($req) : $risposta;
            }
            PHP;
        $this->assertSame(
            [],
            self::chiamateProtette($giusta),
            '`$risposta = $next($req)` è una chiamata, non un alias: `$risposta(...)` nel try non conta'
        );
    }
}
