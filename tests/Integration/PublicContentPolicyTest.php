<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\Study\PublicContentPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regola di visibilita' pubblica (P5, 2026-09-04). Le asserzioni valgono in
 * ogni stato del database di test: con o senza super-admin docente, con o
 * senza sezioni pubbliche. Quello che NON deve mai succedere e' che un
 * contenuto non published, di un altro docente, o di tipo 'document' senza
 * sezione pubblica, risulti pubblico.
 */
final class PublicContentPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!Database::isAvailable()) {
            self::markTestSkipped('database non disponibile');
        }
    }

    #[Test]
    public function drafts_and_other_teachers_are_never_public(): void
    {
        $sa = PublicContentPolicy::proprietarioPubblico();
        self::assertGreaterThanOrEqual(0, $sa);

        self::assertFalse(PublicContentPolicy::isPublic([
            'teacher_id' => $sa, 'visibility' => 'draft', 'content_type' => 'mappa',
        ]), 'draft del super-admin');
        self::assertFalse(PublicContentPolicy::isPublic([
            'teacher_id' => $sa + 1, 'visibility' => 'published', 'content_type' => 'mappa',
        ]), 'published di un altro docente');
        self::assertFalse(PublicContentPolicy::isPublic([
            'teacher_id' => $sa, 'visibility' => 'published', 'content_type' => 'document',
        ]), "'document' legacy senza sezione");
        self::assertFalse(PublicContentPolicy::isPublic([]), 'riga vuota');
    }

    #[Test]
    public function document_type_and_unknown_section_types_are_denied(): void
    {
        $params = ['ind' => 'sc', 'cls' => '2s', 'subj' => 'MAT'];

        $f = PublicContentPolicy::scopedFilters($params, 'document');
        self::assertTrue(PublicContentPolicy::isDeny($f));
        self::assertSame('document', $f['content_type']);

        self::assertTrue(PublicContentPolicy::isDeny(PublicContentPolicy::scopedFilters($params, 'tipo_inesistente')));
    }

    #[Test]
    public function public_filters_never_widen_beyond_published_super_admin_content(): void
    {
        $f = PublicContentPolicy::scopedFilters(['ind' => 'sc', 'cls' => '2s', 'subj' => 'MAT', 'topic' => 'Moto'], 'mappa');
        if (PublicContentPolicy::isDeny($f)) {
            self::assertArrayNotHasKey('teacher_id', $f, 'la sentinella non porta altri filtri');
            return;
        }
        self::assertSame('published', $f['visibility']);
        self::assertSame(PublicContentPolicy::proprietarioPubblico(), $f['teacher_id']);
        self::assertSame('mappa', $f['content_type']);
        self::assertSame('MAT', $f['subject_code']);
        self::assertSame('Moto', $f['topic']);
    }
}
