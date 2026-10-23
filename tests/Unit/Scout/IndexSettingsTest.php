<?php

namespace MatrixOne\Tests\Unit\Scout;

use InvalidArgumentException;
use MatrixOne\Scout\IndexSettings;
use MatrixOne\Tests\Unit\TestCase;

class IndexSettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = IndexSettings::fromArray([]);

        $this->assertNull($settings->fulltext);
        $this->assertSame(['__soft_deleted' => 'integer'], $settings->attributes);
        $this->assertSame('natural', $settings->mode);
        $this->assertFalse($settings->foldAccents);
        $this->assertNull($settings->embedding);
        $this->assertSame('fulltext', $settings->index);
    }

    public function testFullText2Index(): void
    {
        $this->assertSame('fulltext2', IndexSettings::fromArray(['index' => 'fulltext2', 'parser' => 'json'])->index);
    }

    public function testInvalidIndexType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexSettings::fromArray(['index' => 'hnsw']);
    }

    public function testFullText2OnlyAcceptsItsParsers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexSettings::fromArray(['index' => 'fulltext2', 'parser' => 'default']);
    }

    public function testTypedAttributes(): void
    {
        $settings = IndexSettings::fromArray([
            'filterable' => ['status', 'author_id' => 'integer'],
            'sortable' => ['published_at' => 'datetime'],
        ]);

        $this->assertSame([
            'status' => 'string',
            'author_id' => 'integer',
            'published_at' => 'datetime',
            '__soft_deleted' => 'integer',
        ], $settings->attributes);
        $this->assertSame(['published_at'], $settings->sortable);
        $this->assertSame('attr_author_id', $settings->column('author_id'));
    }

    public function testUnknownAttribute(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexSettings::fromArray([])->column('title');
    }

    public function testInvalidType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexSettings::fromArray(['filterable' => ['status' => 'json']]);
    }

    public function testInvalidName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexSettings::fromArray(['filterable' => ['status; drop']]);
    }
}
