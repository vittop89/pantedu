<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Domain\EtichettaCredenziale;
use App\Repositories\TeacherCredentialRepository as Repo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le regole di username, password e aggiunta delle credenziali di classe
 * (ADR-044), nei due versi, sul corpus condiviso con la prova del browser
 * (tests/Fixtures/credenziali-regole.json, tests/js-unit/credenziali-regole.test.js).
 *
 * Fino al 19 settembre 2026 una password di 73 caratteri passava (e bcrypt ne
 * teneva 72), «🙂🙂🙂» era rifiutata come corta invece che come fuori regola, e
 * non c'erano né metodi né costanti da stampare nella vista.
 */
final class TeacherCredentialRulesTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function corpus(): array
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/credenziali-regole.json');
        self::assertIsString($json);
        $c = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($c);
        return $c;
    }

    /** @return iterable<string,array{0:string}> */
    public static function usernameAccettati(): iterable
    {
        foreach (self::corpus()['username']['accettati'] as $u) {
            yield json_encode($u, JSON_UNESCAPED_UNICODE) => [$u];
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function usernameRifiutati(): iterable
    {
        foreach (self::corpus()['username']['rifiutati'] as $u) {
            yield json_encode($u, JSON_UNESCAPED_UNICODE) => [$u];
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function passwordAccettate(): iterable
    {
        foreach (self::corpus()['password']['accettate'] as $p) {
            yield json_encode($p, JSON_UNESCAPED_UNICODE) => [$p];
        }
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function passwordRifiutate(): iterable
    {
        foreach (self::corpus()['password']['rifiutate'] as $p => $codice) {
            yield json_encode((string)$p, JSON_UNESCAPED_UNICODE) => [(string)$p, $codice];
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function aggiunteAccettate(): iterable
    {
        foreach (self::corpus()['aggiunta']['accettate'] as $a) {
            yield json_encode($a, JSON_UNESCAPED_UNICODE) => [$a];
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function aggiunteRifiutate(): iterable
    {
        foreach (self::corpus()['aggiunta']['rifiutate'] as $a) {
            yield json_encode($a, JSON_UNESCAPED_UNICODE) => [$a];
        }
    }

    #[Test]
    #[DataProvider('usernameAccettati')]
    public function username_nella_regola_passa(string $u): void
    {
        self::assertNull(Repo::usernameValido($u));
    }

    #[Test]
    #[DataProvider('usernameRifiutati')]
    public function username_fuori_regola_e_rifiutato(string $u): void
    {
        self::assertSame('invalid_username', Repo::usernameValido($u));
    }

    #[Test]
    #[DataProvider('passwordAccettate')]
    public function password_nella_regola_passa(string $p): void
    {
        self::assertNull(Repo::passwordValida($p));
        self::assertSame(1, preg_match('/^(?:' . Repo::PASSWORD_HTML_PATTERN . ')$/u', $p), 'e il pattern del modulo la accetta');
    }

    #[Test]
    #[DataProvider('passwordRifiutate')]
    public function password_fuori_regola_ha_il_suo_codice(string $p, string $codice): void
    {
        self::assertSame($codice, Repo::passwordValida($p));
        // Il pattern che la vista stampa deve rifiutarla anche lui: altrimenti
        // il browser la manderebbe e il server la respingerebbe.
        self::assertNotSame(1, preg_match('/^(?:' . Repo::PASSWORD_HTML_PATTERN . ')$/u', $p), 'e il pattern del modulo la rifiuta');
    }

    #[Test]
    #[DataProvider('aggiunteAccettate')]
    public function aggiunta_nella_regola_passa(string $a): void
    {
        self::assertTrue(EtichettaCredenziale::aggiuntaValida($a));
    }

    #[Test]
    #[DataProvider('aggiunteRifiutate')]
    public function aggiunta_fuori_regola_e_rifiutata(string $a): void
    {
        self::assertFalse(EtichettaCredenziale::aggiuntaValida($a));
    }

    #[Test]
    public function le_costanti_dei_limiti_e_i_pattern_dicono_gli_stessi_numeri(): void
    {
        // La vista stampa minlength/maxlength dalle costanti e pattern dal
        // testo: se uno dei due cambia da solo, il modulo e il server si
        // separano di nuovo.
        self::assertStringContainsString('{' . Repo::USERNAME_MIN . ',' . Repo::USERNAME_MAX . '}', Repo::USERNAME_HTML_PATTERN);
        self::assertStringContainsString('{' . Repo::PASSWORD_MIN . ',' . Repo::PASSWORD_MAX . '}', Repo::PASSWORD_HTML_PATTERN);
        self::assertStringContainsString(',' . EtichettaCredenziale::AGGIUNTA_MAX . '}', EtichettaCredenziale::AGGIUNTA_HTML_PATTERN);
        // bcrypt considera i primi 72 byte: il massimo deve restare sotto.
        self::assertLessThanOrEqual(72, Repo::PASSWORD_MAX);
    }
}
