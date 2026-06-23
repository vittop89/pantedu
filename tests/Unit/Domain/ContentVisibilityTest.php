<?php

namespace Tests\Unit\Domain;

use App\Domain\ContentVisibility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentVisibilityTest extends TestCase
{
    #[Test]
    public function three_states(): void
    {
        $this->assertSame(3, \count(ContentVisibility::cases()));
    }

    #[Test]
    public function published_visible_to_students(): void
    {
        $this->assertTrue(ContentVisibility::PUBLISHED->isVisibleToStudents());
        $this->assertFalse(ContentVisibility::DRAFT->isVisibleToStudents());
        $this->assertFalse(ContentVisibility::ARCHIVED->isVisibleToStudents());
    }

    #[Test]
    public function tryFromString_handles_nulls(): void
    {
        $this->assertNull(ContentVisibility::tryFromString(null));
        $this->assertNull(ContentVisibility::tryFromString(''));
        $this->assertNull(ContentVisibility::tryFromString('pluto'));
        $this->assertSame(ContentVisibility::DRAFT, ContentVisibility::tryFromString('draft'));
    }

    #[Test]
    public function values_returns_string_list(): void
    {
        $this->assertSame(['draft', 'published', 'archived'], ContentVisibility::values());
    }

    #[Test]
    public function labels(): void
    {
        $this->assertSame('Bozza',      ContentVisibility::DRAFT->label());
        $this->assertSame('Pubblicato', ContentVisibility::PUBLISHED->label());
        $this->assertSame('Archiviato', ContentVisibility::ARCHIVED->label());
    }
}
