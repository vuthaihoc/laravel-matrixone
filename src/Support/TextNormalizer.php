<?php

namespace MatrixOne\Support;

use Normalizer;
use RuntimeException;

/**
 * Text normalization for full-text indexes. MatrixOne's full-text parsers
 * have no language support: no stemming, no stop words, no accent folding.
 */
class TextNormalizer
{
    /**
     * Remove diacritics, so "Tiếng Việt" and "tieng viet" index and match
     * alike. Non-Latin scripts (e.g. Chinese) are left untouched.
     */
    public static function foldAccents(string $text): string
    {
        if (! class_exists(Normalizer::class)) {
            throw new RuntimeException('Folding accents requires the intl PHP extension.');
        }

        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);

        if ($decomposed === false) {
            return $text;
        }

        $folded = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);

        return strtr($folded, ['đ' => 'd', 'Đ' => 'D']);
    }
}
