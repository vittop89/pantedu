<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il PHP del container crea file scrivibili dal gruppo (24/9/2026).
 *
 * Il giro delle cancellazioni dell'art. 17 gira sull'host come `pantedu`, che
 * sta nel gruppo `www-data`; i file dei docenti li crea il PHP del container
 * come `www-data`. Con la umask predefinita (022) le cartelle nascevano 0755 e
 * `pantedu` non poteva toglierne i file: misurato in produzione il 24/9, 81
 * cartelle. Con `umask = 007` su php-fpm nascono 0770.
 *
 * Nei due versi: la prova legge la sezione di php-fpm e non un `umask`
 * qualunque del file (quello di un altro programma non basta).
 */
final class UmaskDelContainerTest extends TestCase
{
    /** La umask dichiarata nella sezione di un programma di supervisord, o null. */
    private static function umaskDi(string $conf, string $programma): ?string
    {
        $dentro = false;
        foreach (preg_split('/\R/', $conf) ?: [] as $riga) {
            if (preg_match('/^\s*\[(.+)\]\s*$/', $riga, $m) === 1) {
                $dentro = trim($m[1]) === 'program:' . $programma;
                continue;
            }
            if ($dentro && preg_match('/^\s*umask\s*=\s*([0-7]+)\s*$/', $riga, $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }

    #[Test]
    public function php_fpm_crea_file_scrivibili_dal_gruppo_e_mai_dagli_altri(): void
    {
        $conf = (string)file_get_contents(__DIR__ . '/../../../docker/supervisord.conf');

        self::assertSame('007', self::umaskDi($conf, 'php-fpm'));
    }

    #[Test]
    public function la_umask_di_un_altro_programma_non_conta(): void
    {
        $conf = "[program:php-fpm]\ncommand = x\n\n[program:nginx]\numask = 007\n";

        self::assertNull(self::umaskDi($conf, 'php-fpm'));
        self::assertSame('007', self::umaskDi($conf, 'nginx'));
    }
}
