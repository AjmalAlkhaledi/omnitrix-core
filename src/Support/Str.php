<?php

declare(strict_types=1);

namespace Omnitrix\Support;

use function array_search;
use function ctype_upper;
use function in_array;
use function is_string;
use function lcfirst;
use function preg_match;
use function preg_replace;
use function str_replace;
use function strtolower;
use function substr;
use function ucfirst;
use function ucwords;

/** Naming helpers used for table, class and file names. English pluralisation rules only. */
final class Str
{
    private const array IRREGULAR = [
        'person' => 'people', 'child' => 'children', 'man' => 'men', 'woman' => 'women',
        'mouse' => 'mice', 'goose' => 'geese', 'tooth' => 'teeth', 'foot' => 'feet',
    ];

    private const array UNCOUNTABLE = ['data', 'equipment', 'information', 'media', 'metadata', 'news', 'series', 'species', 'feedback'];

    private function __construct() {}

    /** `UserProfile`, `userProfile`, `user-profile` → `user_profile`. */
    public static function snake(string $value): string
    {
        $value = preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $value) ?? $value;
        $value = preg_replace('/[\s\-]+/', '_', $value) ?? $value;

        return strtolower(preg_replace('/_+/', '_', $value) ?? $value);
    }

    /** `user_profile`, `user-profile`, `user profile` → `UserProfile`. */
    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $value)));
    }

    /** `user_profile` → `userProfile`. */
    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    /** `post` → `posts`, `category` → `categories`, `user_profile` → `user_profiles`. */
    public static function plural(string $word): string
    {
        [$prefix, $last] = self::splitLast($word);
        $lower = strtolower($last);
        if ($lower === '' || in_array($lower, self::UNCOUNTABLE, true)) {
            return $word;
        }
        if (isset(self::IRREGULAR[$lower])) {
            return $prefix . self::matchCase(self::IRREGULAR[$lower], $last);
        }
        if (preg_match('/[^aeiou]y$/i', $last) === 1) {
            return $prefix . substr($last, 0, -1) . 'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/i', $last) === 1) {
            return $prefix . $last . 'es';
        }

        return $prefix . $last . 's';
    }

    /** `posts` → `post`, `categories` → `category`. */
    public static function singular(string $word): string
    {
        [$prefix, $last] = self::splitLast($word);
        $lower = strtolower($last);
        if ($lower === '' || in_array($lower, self::UNCOUNTABLE, true)) {
            return $word;
        }
        $irregular = array_search($lower, self::IRREGULAR, true);
        if (is_string($irregular)) {
            return $prefix . self::matchCase($irregular, $last);
        }
        if (preg_match('/[^aeiou]ies$/i', $last) === 1) {
            return $prefix . substr($last, 0, -3) . 'y';
        }
        if (preg_match('/(ss|x|z|ch|sh)es$/i', $last) === 1) {
            return $prefix . substr($last, 0, -2);
        }
        if (preg_match('/[^s]s$/i', $last) === 1) {
            return $prefix . substr($last, 0, -1);
        }

        return $word;
    }

    /**
     * Splits `user_profile` into `user_` and `profile`, `UserProfile` into `User` and `Profile`.
     *
     * @return array{string, string}
     */
    private static function splitLast(string $word): array
    {
        if (preg_match('/^(.*[_\-\s])([^_\-\s]+)$/', $word, $m) === 1 || preg_match('/^(.*[a-z\d])([A-Z][^A-Z]*)$/', $word, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return ['', $word];
    }

    private static function matchCase(string $value, string $like): string
    {
        return $like !== '' && ctype_upper($like[0]) ? ucfirst($value) : $value;
    }
}
