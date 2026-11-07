<?php

declare(strict_types=1);

namespace YakNet\Api;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Iterable collection model for list responses.
 *
 * @implements IteratorAggregate<int, mixed>
 */
class Collection extends Model implements Countable, IteratorAggregate
{
    protected string $collectionKey = 'items';

    public function count(): int
    {
        $items = $this->getCollectionItems();

        return count($items);
    }

    /**
     * @return Traversable<int, mixed>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->getCollectionItems());
    }

    /**
     * @return list<mixed>
     */
    public function getCollectionItems(): array
    {
        $key = $this->collectionKey;
        if (property_exists($this, $key) && is_array($this->{$key})) {
            return array_values($this->{$key});
        }

        if (isset($this->modelData[$key]) && is_array($this->modelData[$key])) {
            return array_values($this->modelData[$key]);
        }

        return [];
    }
}
