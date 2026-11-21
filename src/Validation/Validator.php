<?php

declare(strict_types=1);

namespace Omnitrix\Validation;

use InvalidArgumentException;
use Omnitrix\Http\UploadedFile;

use function array_key_exists;
use function array_map;
use function count;
use function explode;
use function filter_var;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function mb_strlen;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const FILTER_VALIDATE_EMAIL;
use const FILTER_VALIDATE_URL;

/**
 * Small rule-string validator for request input.
 *
 * Rules (pipe-separated): required, nullable, sometimes, string, int, numeric, bool, array,
 * email, url, uuid, min:N, max:N (length for strings, count for arrays, value for numbers),
 * between:A,B, in:a,b,c, regex:/pattern/; for uploads file, image (not SVG), mimes:jpg,png (by
 * the detected type) and mimetypes:image/*,application/pdf, with min/max in kilobytes. Only fields named in the rules are returned:
 * unknown input never reaches the handler ("mass assignment" protection). Rule sets are parsed
 * once per instance and can be reused across requests.
 */
final class Validator
{
    private const array KNOWN = ['required', 'nullable', 'sometimes', 'string', 'int', 'integer', 'numeric', 'bool', 'boolean', 'array', 'email', 'url', 'uuid', 'min', 'max', 'between', 'in', 'regex', 'file', 'image', 'mimes', 'mimetypes'];

    /** @var array<string, list<array{string, string}>> field => [[rule, argument]] */
    private array $rules = [];

    /** @param array<string, string> $rules */
    public function __construct(array $rules)
    {
        foreach ($rules as $field => $definition) {
            $parsed = [];
            foreach (explode('|', $definition) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                [$name, $argument] = str_contains($part, ':') ? explode(':', $part, 2) : [$part, ''];
                if (!in_array($name, self::KNOWN, true)) {
                    throw new InvalidArgumentException("Unknown validation rule '{$name}' for field '{$field}'");
                }
                $parsed[] = [$name, $argument];
            }
            $this->rules[$field] = $parsed;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> validated fields only
     *
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        $errors = [];
        $validated = [];
        foreach ($this->rules as $field => $rules) {
            $names = array_map(static fn(array $r): string => $r[0], $rules);
            $present = array_key_exists($field, $input);
            $value = $present ? $input[$field] : null;
            if (!$present || $value === '' || $value === null) {
                if (in_array('required', $names, true) && !($present && $value === null && in_array('nullable', $names, true))) {
                    $errors[$field][] = "The {$field} field is required.";
                } elseif ($present && $value === null) {
                    $validated[$field] = null;
                }
                continue;
            }
            foreach ($rules as [$rule, $argument]) {
                $message = $this->check($field, $rule, $argument, $value);
                if ($message !== null) {
                    $errors[$field][] = $message;
                }
            }
            if (!isset($errors[$field])) {
                $validated[$field] = $this->cast($value, $names);
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    private function check(string $field, string $rule, string $argument, mixed $value): ?string
    {
        return match ($rule) {
            'required', 'nullable', 'sometimes' => null,
            'string' => is_string($value) ? null : "The {$field} must be a string.",
            'int', 'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? null : "The {$field} must be an integer.",
            'numeric' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? null : "The {$field} must be a number.",
            'bool', 'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true) ? null : "The {$field} must be true or false.",
            'array' => is_array($value) ? null : "The {$field} must be an array.",
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : "The {$field} must be a valid email address.",
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value) === 1 ? null : "The {$field} must be a valid http(s) URL.",
            'uuid' => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? null : "The {$field} must be a valid UUID.",
            'min' => self::size($value) >= (float) $argument ? null : "The {$field} must be at least {$argument}.",
            'max' => self::size($value) <= (float) $argument ? null : "The {$field} may not be greater than {$argument}.",
            'between' => $this->between($field, $value, $argument),
            'in' => in_array(is_scalar($value) ? (string) $value : null, explode(',', $argument), true) ? null : "The selected {$field} is invalid.",
            'regex' => is_string($value) && @preg_match($argument, $value) === 1 ? null : "The {$field} format is invalid.",
            'file' => $value instanceof UploadedFile && $value->isValid() ? null : ($value instanceof UploadedFile ? "The {$field} failed to upload: " . $value->errorMessage() : "The {$field} must be a file."),
            'image' => $value instanceof UploadedFile && self::isImage($value) ? null : "The {$field} must be an image (jpg, png, gif, webp, avif, bmp).",
            'mimes' => $value instanceof UploadedFile && self::hasExtension($value, $argument) ? null : "The {$field} must be a file of type: " . str_replace(',', ', ', $argument) . '.',
            'mimetypes' => $value instanceof UploadedFile && self::hasType($value, $argument) ? null : "The {$field} must be a file of type: " . str_replace(',', ', ', $argument) . '.',
            default => "Unknown rule {$rule}.",
        };
    }

    private function between(string $field, mixed $value, string $argument): ?string
    {
        $bounds = explode(',', $argument, 2);
        $size = self::size($value);

        return count($bounds) === 2 && $size >= (float) $bounds[0] && $size <= (float) $bounds[1] ? null : "The {$field} must be between " . implode(' and ', $bounds) . '.';
    }

    private static function size(mixed $value): float
    {
        return match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            is_string($value) => (float) mb_strlen($value),
            $value instanceof UploadedFile => $value->size() / 1024, // kilobytes
            is_array($value) => (float) count($value),
            default => 0.0,
        };
    }

    private static function isImage(UploadedFile $file): bool
    {
        $type = $file->mimeType();

        return $file->isValid() && $type !== null && str_starts_with($type, 'image/') && $type !== 'image/svg+xml';
    }

    private static function hasExtension(UploadedFile $file, string $list): bool
    {
        if (!$file->isValid() || $file->mimeType() === null) {
            return false;
        }
        $allowed = array_map(static fn(string $e): string => strtolower(trim($e)) === 'jpeg' ? 'jpg' : strtolower(trim($e)), explode(',', $list));

        return in_array($file->extension(), $allowed, true);
    }

    private static function hasType(UploadedFile $file, string $list): bool
    {
        $type = $file->isValid() ? $file->mimeType() : null;
        if ($type === null) {
            return false;
        }
        foreach (explode(',', $list) as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($allowed === $type || (str_ends_with($allowed, '/*') && str_starts_with($type, substr($allowed, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $rules */
    private function cast(mixed $value, array $rules): mixed
    {
        if ((in_array('int', $rules, true) || in_array('integer', $rules, true)) && is_string($value)) {
            return (int) $value;
        }
        if ((in_array('bool', $rules, true) || in_array('boolean', $rules, true)) && !is_bool($value)) {
            return in_array($value, [1, '1', 'true'], true);
        }
        if (in_array('numeric', $rules, true) && is_string($value) && is_numeric($value)) {
            return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : (float) $value;
        }

        return $value;
    }
}
