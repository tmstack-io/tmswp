<?php

declare(strict_types=1);

namespace TMSWP\Core\Query;

use TMSWP\Core\Exception\QueryException;

/**
 * @internal
 */
final class QueryValidator
{
    private function __construct() {}

    public static function direction(string $direction): string
    {
        $normalized = strtoupper($direction);

        if ($normalized !== 'ASC' && $normalized !== 'DESC') {
            throw new QueryException("invalid query direction: {$direction}");
        }

        return $normalized;
    }

    public static function compare(string $compare): string
    {
        $normalized = strtoupper($compare);
        $allowed = ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS', 'REGEXP', 'NOT REGEXP', 'RLIKE'];

        if (!\in_array($normalized, $allowed, true)) {
            throw new QueryException("invalid meta compare: {$compare}");
        }

        return $normalized;
    }

    public static function metaType(string $type): string
    {
        $normalized = strtoupper($type);
        $allowed = ['NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED'];

        if (!\in_array($normalized, $allowed, true)) {
            throw new QueryException("invalid meta type: {$type}");
        }

        return $normalized;
    }
}
