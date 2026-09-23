<?php

namespace MatrixOne\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Store a string attribute in lower case.
 *
 * MatrixOne ignores `_ci` collations: `=` and unique indexes are
 * case-sensitive. Normalizing values such as e-mails when they are written
 * restores MySQL-like uniqueness and lets lookups use an index
 * (`where('email', Str::lower($input))`).
 */
class Lowercase implements CastsInboundAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return is_string($value) ? mb_strtolower($value) : $value;
    }
}
