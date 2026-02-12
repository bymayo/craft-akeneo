<?php

namespace bymayo\akeneo\jobs;

use craft\base\Batchable;

class ArrayBatchable implements Batchable
{
    public function __construct(private array $items)
    {
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getSlice(int $offset, int $limit): iterable
    {
        return array_slice($this->items, $offset, $limit);
    }
}
