<?php

declare(strict_types=1);

final class Request {
    public const INT = 'int';
    public const STRING = 'string';
    public const BOOL = 'bool';

    public static function get(
        string $name,
        string $type = self::STRING,
        int|string|bool|null $default = null
    ): int|string|bool|null {
        return self::read($_GET, $name, $type, $default);
    }

    public static function post(
        string $name,
        string $type = self::STRING,
        int|string|bool|null $default = null
    ): int|string|bool|null {
        return self::read($_POST, $name, $type, $default);
    }

    private static function read(
        array $source,
        string $name,
        string $type,
        int|string|bool|null $default
    ): int|string|bool|null {
        if (!array_key_exists($name, $source)) {
            return $default;
        }

        $value = $source[$name];

        return match ($type) {
            self::INT => self::toInt($value, $default),
            self::STRING => self::toString($value, $default),
            self::BOOL => self::toBool($value, $default),
            default => throw new InvalidArgumentException('Unsupported request type: ' . $type),
        };
    }

    private static function toInt(mixed $value, int|string|bool|null $default): int|string|bool|null {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_INT);
        return $filtered === false ? $default : $filtered;
    }

    private static function toString(mixed $value, int|string|bool|null $default): int|string|bool|null {
        return is_string($value) ? $value : $default;
    }

    private static function toBool(mixed $value, int|string|bool|null $default): int|string|bool|null {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_string($value) && !is_int($value)) {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $filtered === null ? $default : $filtered;
    }
}
