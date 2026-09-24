<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\TeacherMoveController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il messaggio dopo «Sposta di classe» dice da dove a dove (15/9/2026).
 *
 * Diceva solo «55 contenuti spostati e 1 verifica spostata in «3»»: dopo uno
 * spostamento sbagliato l'utente non sapeva più da quale classe fosse partito.
 */
final class TeacherMoveTitoloEsitoTest extends TestCase
{
    #[Test]
    public function dice_la_classe_di_partenza_e_quella_di_arrivo(): void
    {
        self::assertSame(
            '55 contenuti spostati e 1 verifica spostata da «1» a «3».',
            TeacherMoveController::titoloEsito(['contenuti' => 55, 'verifiche' => 1, 'classe' => '3', 'indirizzo' => null, 'da_classe' => '1', 'da_indirizzo' => null])
        );
    }

    #[Test]
    public function con_le_sezioni_dice_anche_il_corso(): void
    {
        self::assertSame(
            '1 contenuto spostato da «3A · SCI» a «3».',
            TeacherMoveController::titoloEsito(['contenuti' => 1, 'verifiche' => 0, 'classe' => '3', 'indirizzo' => null, 'da_classe' => '3A', 'da_indirizzo' => 'SCI'])
        );
        self::assertSame(
            '2 verifiche spostate da «2» a «2A · SCI».',
            TeacherMoveController::titoloEsito(['contenuti' => 0, 'verifiche' => 2, 'classe' => '2A', 'indirizzo' => 'SCI', 'da_classe' => '2', 'da_indirizzo' => ''])
        );
    }
}
