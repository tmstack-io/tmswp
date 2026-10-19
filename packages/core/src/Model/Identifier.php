<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Exception\SchemaException;

final class Identifier
{
    private function __construct() {}

    public static function simple(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new InvalidQueryException("invalid SQL identifier: {$value}");
        }

        return $value;
    }

    public static function qualified(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $value) !== 1) {
            throw new InvalidQueryException("invalid SQL identifier: {$value}");
        }

        return $value;
    }

    public static function selectable(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?(\s+(?i:AS)\s+[A-Za-z_][A-Za-z0-9_]*)?$/', $value) !== 1) {
            throw new InvalidQueryException("invalid SELECT identifier: {$value}");
        }

        return $value;
    }

    public static function bareTable(string $value): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $value) !== 1) {
            throw new InvalidQueryException("invalid bare table name: {$value}");
        }

        return $value;
    }

    public static function table(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new InvalidQueryException("invalid table name: {$value}");
        }

        return $value;
    }

    public static function schemaSimple(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new SchemaException("invalid schema identifier: {$value}");
        }

        return $value;
    }

    public static function schemaBareTable(string $value): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $value) !== 1) {
            throw new SchemaException("invalid table name: {$value}");
        }

        return $value;
    }
}
