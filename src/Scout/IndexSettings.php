<?php

namespace MatrixOne\Scout;

use InvalidArgumentException;

/**
 * Settings of one matrixone-index Scout index, from
 * config('scout.matrixone-index.index-settings') keyed by model class or
 * index name:
 *
 *     Article::class => [
 *         'fulltext' => ['title', 'body'],          // default: every attribute
 *         'filterable' => ['status', 'author_id' => 'integer'],
 *         'sortable' => ['published_at' => 'datetime'],
 *         'parser' => 'ngram',                      // or 'json', default parser when null
 *         'fold_accents' => true,                   // "tieng viet" matches "Tiếng Việt"
 *         'prefix' => true,                         // "learn" matches "learning"
 *         'mode' => 'natural',                      // 'boolean' keeps user operators
 *         'embedding' => 1536,                      // vector dimensions for ->semantic() / ->hybrid()
 *     ],
 */
final class IndexSettings
{
    /** Column types accepted for filterable and sortable attributes. */
    public const TYPES = ['string', 'integer', 'float', 'boolean', 'datetime'];

    /**
     * @param  list<string>|null  $fulltext
     * @param  array<string, string>  $attributes  attribute => type, filterable or sortable
     * @param  list<string>  $sortable
     */
    public function __construct(
        public readonly ?array $fulltext,
        public readonly array $attributes,
        public readonly array $sortable,
        public readonly ?string $parser,
        public readonly bool $foldAccents,
        public readonly bool $prefix,
        public readonly string $mode,
        public readonly ?int $embedding,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $filterable = self::typed($settings['filterable'] ?? []);
        $sortable = self::typed($settings['sortable'] ?? []);

        $parser = $settings['parser'] ?? null;
        if ($parser !== null && (! is_string($parser) || ! preg_match('/^[a-z0-9_]+$/i', $parser))) {
            throw new InvalidArgumentException('Invalid MatrixOne full-text parser.');
        }

        $mode = $settings['mode'] ?? 'natural';
        if (! in_array($mode, ['natural', 'boolean'], true)) {
            throw new InvalidArgumentException('The MatrixOne index mode must be "natural" or "boolean".');
        }

        $embedding = $settings['embedding'] ?? null;
        if ($embedding !== null && (! is_int($embedding) || $embedding < 1)) {
            throw new InvalidArgumentException('The MatrixOne index embedding must be a number of dimensions.');
        }

        $fulltext = $settings['fulltext'] ?? null;

        return new self(
            is_array($fulltext) ? array_values(array_map('strval', $fulltext)) : null,
            // Soft delete metadata is always filterable.
            $filterable + $sortable + ['__soft_deleted' => 'integer'],
            array_keys($sortable),
            $parser,
            (bool) ($settings['fold_accents'] ?? false),
            (bool) ($settings['prefix'] ?? false),
            $mode,
            $embedding,
        );
    }

    /**
     * The index column holding an attribute.
     */
    public function column(string $attribute): string
    {
        if (! isset($this->attributes[$attribute])) {
            throw new InvalidArgumentException(
                "The [{$attribute}] attribute is not filterable or sortable; add it to scout.matrixone-index.index-settings."
            );
        }

        return 'attr_'.$attribute;
    }

    /**
     * @return array<string, string>
     */
    private static function typed(mixed $attributes): array
    {
        $typed = [];

        foreach ((array) $attributes as $key => $value) {
            $value = is_scalar($value) ? (string) $value : '';
            [$name, $type] = is_int($key) ? [$value, 'string'] : [(string) $key, $value];

            if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                throw new InvalidArgumentException("Invalid MatrixOne index attribute name [{$name}].");
            }

            if (! in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid type [{$type}] for MatrixOne index attribute [{$name}].");
            }

            $typed[$name] = $type;
        }

        return $typed;
    }
}
