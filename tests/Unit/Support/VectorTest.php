<?php

namespace MatrixOne\Tests\Unit\Support;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use MatrixOne\Support\Vector;
use MatrixOne\Tests\Unit\TestCase;

class VectorTest extends TestCase
{
    public function testToLiteral(): void
    {
        $this->assertSame('[1,0.1,-3.0e-7,4]', Vector::toLiteral([1, 0.1, -3e-7, '4']));
        $this->assertSame('[0.5,1.5]', Vector::toLiteral(new Collection([0.5, 1.5])));
        $this->assertSame('[1,2]', Vector::toLiteral('[1,2]'));
    }

    public function testToLiteralRejectsNonNumericElements(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Vector::toLiteral([1, 'x']);
    }

    public function testFromLiteral(): void
    {
        $this->assertSame([1.0, 0.1, -3.0e-7], Vector::fromLiteral('[1, 0.1, -0.0000003]'));
        $this->assertSame([], Vector::fromLiteral('[]'));
    }
}
