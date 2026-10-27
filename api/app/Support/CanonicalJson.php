<?php

namespace App\Support;

/**
 * Canonical JSON for command signing: object keys sorted recursively, no
 * whitespace, unescaped slashes/unicode. Lists keep their order. An empty
 * top-level payload is the empty object "{}".
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function normalize(mixed $value, bool $top = false): mixed
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if (is_object($value)) {
            $value = (array) $value;
            if ($value === []) {
                return new \stdClass;
            }
        }
        if (is_array($value)) {
            if ($value === []) {
                return $top ? new \stdClass : [];
            }
            if (array_is_list($value)) {
                return array_map(fn ($v) => self::normalize($v), $value);
            }
            ksort($value, SORT_STRING);
            $out = [];
            foreach ($value as $k => $v) {
                $out[(string) $k] = self::normalize($v);
            }

            return (object) $out;
        }

        return $value;
    }
}
