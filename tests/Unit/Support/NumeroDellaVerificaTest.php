<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RigheDellaBarra;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il riquadro davanti a una verifica, nella barra laterale.
 *
 * Richiesta dell'utente (20/9/2026): «i link delle verifiche derivano sempre
 * dai corrispettivi esercizi, quindi devono presentare sempre lo stesso tag».
 * Una verifica in `topic` porta il **titolo** dell'esercizio da cui nasce;
 * l'esercizio in `topic` ha il suo numero. L'abbinamento si fa per titolo,
 * dentro lo stesso ambito.
 *
 * I casi limite sono quelli veri, misurati in produzione lo stesso giorno: la
 * verifica il cui esercizio è stato cancellato (99) e quella che ne trova due
 * perché due esercizi della stessa classe si chiamano uguale (105).
 */
final class NumeroDellaVerificaTest extends TestCase
{
    /** @return array<string,mixed> */
    private function verifica(int $id, string $topic, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'teacher_id' => 77, 'subject_code' => 'MAT',
            'indirizzo' => 'AAA', 'classe' => '5', 'topic' => $topic,
            'title' => $topic, 'content_type' => 'verifica',
        ], $extra);
    }

    /** @return array<string,mixed> */
    private function esercizio(int $id, string $titolo, string $numero, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'teacher_id' => 77, 'subject_code' => 'MAT',
            'indirizzo' => 'AAA', 'classe' => '5', 'topic' => $numero,
            'title' => $titolo, 'content_type' => 'esercizio',
        ], $extra);
    }

    #[Test]
    public function la_verifica_prende_il_numero_del_suo_esercizio(): void
    {
        $numeri = RigheDellaBarra::abbinaNumeri(
            [$this->verifica(81, 'Derivate')],
            [$this->esercizio(49, 'Derivate', '4.0')],
        );

        $this->assertSame([81 => '4.0'], $numeri);
    }

    #[Test]
    public function l_esercizio_di_un_altra_classe_non_conta(): void
    {
        $numeri = RigheDellaBarra::abbinaNumeri(
            [$this->verifica(81, 'Derivate')],
            [
                $this->esercizio(49, 'Derivate', '4.0', ['classe' => '4']),
                $this->esercizio(50, 'Derivate', '9.0', ['subject_code' => 'FIS']),
                $this->esercizio(51, 'Derivate', '8.0', ['indirizzo' => 'SCI']),
                $this->esercizio(52, 'Derivate', '7.0', ['teacher_id' => 140]),
            ],
        );

        $this->assertSame([], $numeri);
    }

    #[Test]
    public function senza_esercizio_non_si_inventa_niente(): void
    {
        // La 99 «provaar»: l'esercizio da cui nasceva è stato cancellato.
        $this->assertSame([], RigheDellaBarra::abbinaNumeri(
            [$this->verifica(99, 'provaar')],
            [$this->esercizio(49, 'Derivate', '4.0')],
        ));
    }

    #[Test]
    public function con_due_esercizi_dello_stesso_nome_si_tace(): void
    {
        // La 105 «Sistemi lineari»: due esercizi con lo stesso titolo nella
        // stessa classe, con numeri diversi. Indovinare sarebbe peggio.
        $this->assertSame([], RigheDellaBarra::abbinaNumeri(
            [$this->verifica(105, 'Sistemi lineari')],
            [
                $this->esercizio(58, 'Sistemi lineari', '2.0'),
                $this->esercizio(59, 'Sistemi lineari', '3.0'),
            ],
        ));
    }

    #[Test]
    public function due_esercizi_con_lo_stesso_numero_non_sono_un_dubbio(): void
    {
        $this->assertSame([105 => '2.0'], RigheDellaBarra::abbinaNumeri(
            [$this->verifica(105, 'Sistemi lineari')],
            [
                $this->esercizio(58, 'Sistemi lineari', '2.0'),
                $this->esercizio(1013, 'Sistemi lineari', 'Sistemi lineari'),
            ],
        ));
    }

    #[Test]
    public function un_esercizio_senza_numero_non_e_un_etichetta(): void
    {
        // Il caso vero della 1013: una bozza il cui `topic` è il titolo.
        $this->assertSame([], RigheDellaBarra::abbinaNumeri(
            [$this->verifica(105, 'Sistemi lineari')],
            [$this->esercizio(1013, 'Sistemi lineari', 'Sistemi lineari')],
        ));
    }

    #[Test]
    public function il_numero_puo_avere_piu_livelli(): void
    {
        $this->assertSame([81 => '4.2.1'], RigheDellaBarra::abbinaNumeri(
            [$this->verifica(81, 'Derivate')],
            [$this->esercizio(49, 'Derivate', '4.2.1')],
        ));
    }

    #[Test]
    public function una_verifica_senza_argomento_non_cerca_niente(): void
    {
        $this->assertSame([], RigheDellaBarra::abbinaNumeri(
            [$this->verifica(962, '')],
            [$this->esercizio(49, 'Derivate', '4.0')],
        ));
    }
}
