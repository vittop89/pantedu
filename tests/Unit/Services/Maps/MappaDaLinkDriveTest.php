<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Maps;

use App\Services\Maps\MappaDaLinkDrive;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Una mappa drawio da un link pubblico di Google Drive (15/9/2026), senza rete.
 *
 * Il caso vero: il link che diagrams.net produce con «Pubblica link» da un file
 * su Drive, `viewer.diagrams.net/?…#U<https://drive.google.com/uc?id=…&export=download>`,
 * incollato in «🔗 Link esterno». Nei due versi: le forme di Drive si
 * riconoscono, il resto no; e il server scarica solo da drive.google.com, un
 * identificativo che costruisce lui, e accetta solo un drawio.
 */
final class MappaDaLinkDriveTest extends TestCase
{
    private const ID = '1AbCdEfGhIjKlMnOpQrStUvWxYz_-012';

    #[Test]
    public function riconosce_le_forme_dei_link_di_drive(): void
    {
        $id = self::ID;
        $drive = "https://drive.google.com/uc?id=$id&export=download";
        foreach ([
            "https://drive.google.com/file/d/$id/view?usp=sharing",
            "https://drive.google.com/file/d/$id",
            "https://drive.google.com/open?id=$id",
            $drive,
            "https://drive.usercontent.google.com/download?id=$id&export=download",
            'https://viewer.diagrams.net/?tags=%7B%7D&lightbox=1&highlight=0000ff&edit=_blank&layers=1&nav=1&title=mappa.drawio&dark=auto#U' . rawurlencode($drive),
            "https://app.diagrams.net/#G$id",
            'https://viewer.diagrams.net/?url=' . rawurlencode($drive),
        ] as $link) {
            self::assertSame($id, MappaDaLinkDrive::idDalLink($link), $link);
        }
    }

    #[Test]
    public function ogni_altro_link_non_e_di_drive(): void
    {
        foreach ([
            'https://example.org/mappa.drawio',
            'https://viewer.diagrams.net/?lightbox=1#R7ZZNj5swEIb%2FDcdIgAlJjk2y2x62UqUcejaxAavGpmay2fz6jrEJpKRSV6q0PfSCZ',
            'https://drive.google.com/drive/folders/' . self::ID,
            'https://drive.google.com/open?id=corto',
            'javascript:alert(1)//drive.google.com/file/d/' . self::ID,
            'https://drive.google.com.example.org/file/d/' . self::ID,
            '',
        ] as $link) {
            self::assertNull(MappaDaLinkDrive::idDalLink($link), $link);
        }
    }

    #[Test]
    public function scarica_da_drive_con_l_identificativo_e_accetta_un_drawio(): void
    {
        $chieste = [];
        $xml = '<mxfile host="drive"><diagram id="d" name="P">x</diagram></mxfile>';
        $servizio = new MappaDaLinkDrive(function (string $url) use (&$chieste, $xml): array {
            $chieste[] = $url;
            return ['status' => 200, 'body' => $xml, 'host' => 'drive.usercontent.google.com'];
        });

        self::assertSame($xml, $servizio->xml(self::ID));
        self::assertSame(['https://drive.google.com/uc?export=download&id=' . self::ID], $chieste, 'l\'indirizzo lo costruisce il server');
    }

    #[Test]
    public function rifiuta_cio_che_non_e_un_drawio_pubblico_di_google(): void
    {
        $risposta = static fn(int $status, string $body, string $host = 'drive.google.com'): MappaDaLinkDrive
            => new MappaDaLinkDrive(static fn(string $url): array => ['status' => $status, 'body' => $body, 'host' => $host]);

        foreach ([
            'link_non_pubblico'      => $risposta(200, '<!DOCTYPE html><html><body>Accedi</body></html>'),
            'link_non_pubblico '     => $risposta(404, ''),
            'link_non_drawio'        => $risposta(200, '%PDF-1.7 …'),
            'link_non_raggiungibile' => $risposta(200, '<mxfile/>', 'example.org'),
            'link_non_raggiungibile ' => $risposta(500, ''),
            'payload_too_large'      => $risposta(200, '<mxfile>' . str_repeat('a', MappaDaLinkDrive::MAX_BYTES) . '</mxfile>'),
        ] as $atteso => $servizio) {
            try {
                $servizio->xml(self::ID);
                self::fail("accettato: $atteso");
            } catch (\RuntimeException $e) {
                self::assertSame(trim($atteso), $e->getMessage());
            }
        }

        $nessuna = new MappaDaLinkDrive(static function (string $url): array {
            throw new \LogicException('nessuna richiesta per un identificativo non valido');
        });
        $this->expectExceptionMessage('link_non_drawio');
        $nessuna->xml('../../etc/passwd');
    }
}
