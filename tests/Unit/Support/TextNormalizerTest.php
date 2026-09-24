<?php

namespace MatrixOne\Tests\Unit\Support;

use MatrixOne\Support\TextNormalizer;
use MatrixOne\Tests\Unit\TestCase;

class TextNormalizerTest extends TestCase
{
    public function testFoldAccents(): void
    {
        $this->assertSame(
            'Tieng Viet Da Nang hoc 中文 Elan',
            TextNormalizer::foldAccents('Tiếng Việt Đà Nẵng học 中文 Élan')
        );
    }
}
