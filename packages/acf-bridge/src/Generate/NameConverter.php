<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

final class NameConverter
{
    /** @var list<string> */
    private const RESERVED = [
        'of',
        'instance',
        'value',
        'rawValue',
        'update',
        'delete',
        'has',
        'acfId',
        'class',
        'function',
        'list',
        'match',
        'readonly',
    ];

    public static function methodName(string $fieldName): string
    {
        $studly = self::studly($fieldName);
        $method = lcfirst($studly);

        if (\in_array($method, self::RESERVED, true)) {
            return $method . 'Field';
        }

        return $method;
    }

    public static function studly(string $name): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return '';
        }

        $studly = implode('', array_map(static fn(string $part): string => ucfirst(strtolower($part)), $parts));

        return preg_match('/^[0-9]/', $studly) === 1 ? 'N' . $studly : $studly;
    }

    public static function constName(string $fieldName): ?string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $fieldName) !== 1) {
            return null;
        }

        return strtoupper($fieldName);
    }

    public static function isAccessorEligible(string $fieldName): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $fieldName) === 1;
    }
}
