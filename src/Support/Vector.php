<?php

namespace MatrixOne\Support;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

class Vector
{
    /**
     * Convert a PHP vector into MatrixOne's text literal, e.g. "[0.1,0.2]".
     *
     * @param  Arrayable<int, mixed>|array<int, mixed>|string  $vector
     */
    public static function toLiteral(Arrayable|array|string $vector): string
    {
        if (is_string($vector)) {
            return $vector;
        }

        $values = $vector instanceof Arrayable ? $vector->toArray() : $vector;

        $floats = array_map(static function ($value): float {
            if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
                throw new InvalidArgumentException('Vector elements must be numeric.');
            }

            return (float) $value;
        }, array_values($values));

        // json_encode() uses the shortest round-trippable float representation.
        return json_encode($floats, JSON_THROW_ON_ERROR);
    }

    /**
     * Parse MatrixOne's text representation into a list of floats.
     *
     * @return array<int, float>
     */
    public static function fromLiteral(string $value): array
    {
        $value = trim($value);

        if ($value === '' || $value === '[]') {
            return [];
        }

        return array_map('floatval', explode(',', trim($value, '[] ')));
    }
}
