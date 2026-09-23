<?php

namespace MatrixOne\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use MatrixOne\Support\Vector;

/**
 * Cast a MatrixOne vecf32 / vecf64 column to a PHP list of floats.
 *
 * @implements CastsAttributes<array<int, float>|null, Arrayable<int, mixed>|array<int, mixed>|string|null>
 */
class AsVector implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, float>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("The [{$key}] attribute is not a vector.");
        }

        return Vector::fromLiteral($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) && ! is_string($value) && ! $value instanceof Arrayable) {
            throw new InvalidArgumentException("The [{$key}] attribute must be an array of numbers.");
        }

        /** @var Arrayable<int, mixed>|array<int, mixed>|string $value */
        return Vector::toLiteral($value);
    }
}
