<?php

declare(strict_types=1);

namespace TMSWP\Core\Query;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final readonly class PageResult implements IteratorAggregate, Countable
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
    ) {}

    public function totalPages(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return (int) ceil($this->total / $this->perPage);
    }

    public function hasPrev(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNext(): bool
    {
        return $this->currentPage < $this->totalPages();
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return ArrayIterator<int, T> */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
