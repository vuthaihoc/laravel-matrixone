<?php

namespace MatrixOne\Support;

use Stringable;

/**
 * Builds a MatrixOne boolean-mode full-text query without hand-writing
 * operators; user input is stripped of operator characters.
 *
 *     FullTextQuery::make()->must('python')->encourage('data', 'science')->mustNot('legacy');
 *     // +python data science -legacy
 *
 * Use it with whereFullTextQuery() / searchFullText(), which run it in
 * boolean mode. BM25 scoring (ft_relevancy_algorithm) makes encourage() and
 * discourage() affect the ranking the most.
 */
final class FullTextQuery implements Stringable
{
    /** @var list<string> */
    private array $parts = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * Match documents containing any of the words of free text, ranked by
     * relevance: the behaviour of MySQL's natural language mode. MatrixOne's
     * natural language mode instead only matches words appearing together.
     */
    public static function anyOf(string $text): self
    {
        return (new self)->encourage(...self::words($text));
    }

    /**
     * Terms every match must contain (`+term`).
     */
    public function must(string ...$terms): self
    {
        return $this->add('+', $terms);
    }

    /**
     * Terms no match may contain (`-term`).
     */
    public function mustNot(string ...$terms): self
    {
        return $this->add('-', $terms);
    }

    /**
     * Optional terms that raise the relevance of matches containing them.
     */
    public function encourage(string ...$terms): self
    {
        return $this->add('', $terms);
    }

    /**
     * Terms that lower the relevance of matches containing them (`~term`).
     */
    public function discourage(string ...$terms): self
    {
        return $this->add('~', $terms);
    }

    /**
     * An exact phrase (`"words in order"`).
     *
     * MatrixOne only honours a phrase when it is the whole query: combined
     * with other terms or operators (including a leading `+`), its words
     * are matched individually.
     */
    public function phrase(string $phrase): self
    {
        $words = self::words($phrase);

        if ($words !== []) {
            $this->parts[] = '"'.implode(' ', $words).'"';
        }

        return $this;
    }

    /**
     * Words starting with the given prefix (`+prefix*`); required unless $required is false.
     */
    public function prefix(string $prefix, bool $required = true): self
    {
        foreach (self::words($prefix) as $word) {
            $this->parts[] = ($required ? '+' : '').$word.'*';
        }

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->parts === [];
    }

    public function toString(): string
    {
        return implode(' ', $this->parts);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @param  array<int|string, string>  $terms
     */
    private function add(string $operator, array $terms): self
    {
        foreach ($terms as $term) {
            foreach (self::words($term) as $word) {
                $this->parts[] = $operator.$word;
            }
        }

        return $this;
    }

    /**
     * Split text into words, removing boolean-mode operator characters.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $clean = (string) preg_replace('/[+\-~<>()"*@\\\\]+/u', ' ', $text);

        return array_values(array_filter(
            preg_split('/\s+/u', trim($clean)) ?: [],
            fn (string $word) => $word !== ''
        ));
    }
}
