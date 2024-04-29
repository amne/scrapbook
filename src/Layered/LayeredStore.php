<?php

declare(strict_types=1);

namespace MatthiasMullie\Scrapbook\Layered;

use MatthiasMullie\Scrapbook\KeyValueStore;

/**
 * This class will serve as a layered cache adapter that fallbacks to lower
 * key-value stores to fetch the value. Unlike buffered store which is by
 * design "layering" only memory with an additional kv-store, this store
 * can layer an arbitrary number of kv-stores. Arbitrary but use common sense.
 * An interesting usecase would be if you have precomputed data in a
 * file (flysystem?) or database and want to "lift" it to a faster store,
 * like redis or memcached.
 *
 * @author Cornel Cruceru <cornel@cruceru.cc>
 * @copyright Copyright (c) 2024, Cornel Cruceru. All rights reserved
 * @license LICENSE MIT
 */
class LayeredStore implements KeyValueStore
{
    /**
     * Adapter in order of use.
     *
     * @var KeyValueStore[]
     */
    protected array $adapters = [];

    /**
     * Array of CAS tokens for each adapter
     *
     */
    protected array $adapterTokens = [];

    /**
     * @var LayeredStore[]
     */
    private array $collections = [];

    /**
     * For each valid adapter you can configure a max lifetime
     *
     * @var int[]
     */
    private array $maxLifetimes = [];
 



    /**
     * @param KeyValueStore[] $adapters
     * @param int[] $maxLifetimes
     */
    public function __construct(array $adapters, array $maxLifetimes = [])
    {
        foreach ($adapters as $i => $adapter) {
            if ($adapter instanceof KeyValueStore) {
                $this->adapters[] = $adapter;
                $this->maxLifetimes[] = $maxLifetimes[$i] ?? PHP_INT_MAX;
            }
        }
    }

    /**
     * In addition to all writes being stored to $local, we'll also
     * keep get() values around ;).
     *
     * {@inheritdoc}
     */
    public function get(string $key, mixed &$token = null): mixed
    {
        // Start with the first adapter
        $adapterIndex = 0;
        $token = uniqid('', false);
        $this->adapterTokens[$token] = [];
        $value = false;
        while (false === $value && $adapterIndex < count($this->adapters)) {
            $value = $this->adapters[$adapterIndex]->get($key, $adapterToken);
            // if this adapter doesn't have the key go to the next adapter
            $adapterIndex++;
        }

        if (false === $value) {
            return false;
        }

        $this->adapterTokens[$adapterIndex][$token] = $adapterToken;
        // write the missing value in the previous adapters
        while ($adapterIndex) {
            $adapterIndex--;
            $this->adapters[$adapterIndex]->set($key, $value);
        }

        return $value;
    }

    /**
     * Get multiple keys at once
     *
     * We could of course "foreach -> this->get" this but good storages
     * actually have a batch get capability (getMultiple in PSR16)
     *
     * {@inheritdoc}
     */
    public function getMulti(array $keys, ?array &$tokens = null): array
    {
        $adapterIndex = 0;
        $adapterMissing = [];
        $adapterTokens = [];
        $adapterValues = [];
        $values = [];
        $missing = $keys;
        // stop when we've found all the values or we're out of adapters
        while (!empty($missing) && $adapterIndex < count($this->adapters)) {
            $adapterValues[$adapterIndex] = $this->adapters[$adapterIndex]->getMulti($missing);
            $values = array_merge($values, $adapterValues[$adapterIndex]);
            // keep track of missing values in each adapter
            $missing = $adapterMissing[$adapterIndex] = array_diff($missing, array_keys($adapterValues[$adapterIndex]));
            if (!empty($missing)) {
                $adapterIndex++;
            }
        }

        // walk back and update missing values
        if (count($missing) > 0 && count($values) > 0) {
            $missingValues = [];
            while ($adapterIndex > 0) {
                $adapterIndex--;
                foreach ($adapterMissing[$adapterIndex] as $missingKey) {
                    $missingValues = $adapterValues[$adapterIndex+1][$missingKey];
                }
                if (empty($missingValues)) {
                    continue;
                }
                // $missingValues = array_merge($missingValues, $adapterValues[$adapterIndex+1]);
                // write the values found in the next adapter
                // because those were the ones we didn't find in this one
                // RC: between reads values could have expired in the next adapter
                // which means we might not have all the missing values
                $this->adapters[$adapterIndex]->setMulti($missingValues);
            }
        }

        $tokens = $values;
        $tokens = array_walk($tokens, fn($e) =>  serialize($e));

        return $values;
    }

    private function _getAdapterExpire(int $adapterIndex, int $expire = 0)
    {
        return min($expire, $this->maxLifetimes[$adapterIndex]);
    }

    public function set(string $key, mixed $value, int $expire = 0): bool
    {
        $adapterIndex = count($this->adapters);
        $result = true;
        while ($result && $adapterIndex > 0) {
            $adapterIndex--;
            $result = $result && $this->adapters[$adapterIndex]->set($key, $value, $this->_getAdapterExpire($adapterIndex, $expire));
        }

        if (!$result) {
            // best effort rollback
            while ($adapterIndex < count($this->adapters)) {
                $this->adapters[$adapterIndex]->delete($key);
            }
        }

        return $result;
    }

    public function setMulti(array $items, int $expire = 0): array
    {
        $adapterIndex = count($this->adapters);
        $result = true;
        while ($result && $adapterIndex > 0) {
            $adapterIndex--;
            $result = $result && $this->adapters[$adapterIndex]->setMulti($items, $this->_getAdapterExpire($adapterIndex, $expire));
        }

        if (!$result) {
            // best effort rollback
            while ($adapterIndex < count($this->adapters)) {
                $this->adapters[$adapterIndex]->deleteMulti(array_keys($items));
            }
        }

        return $result;
    }

    public function delete(string $key): bool
    {
        $adapterIndex = count($this->adapters);
        $result = true;
        while ($adapterIndex > 0) {
            $adapterIndex--;
            $result = $result && $this->adapters[$adapterIndex]->delete($key, $value, $this->_getAdapterExpire($adapterIndex, $expire));
        }

        return $result;
    }

    public function deleteMulti(array $keys): array
    {
        $adapterIndex = count($this->adapters);
        $result = true;
        while ($adapterIndex > 0) {
            $adapterIndex--;
            $result = $result && $this->adapters[$adapterIndex]->deleteMulti($keys, $this->_getAdapterExpire($adapterIndex, $expire));
        }

        return $result;
    }

    public function add(string $key, mixed $value, int $expire = 0): bool
    {
        throw new \Exception("method not implemented " . __FUNCTION__ , -1);
    }

    public function replace(string $key, mixed $value, int $expire = 0): bool
    {
        $adapterIndex = count($this->adapters);
        $result = true;
        while ($adapterIndex > 0) {
            $adapterIndex--;
            $result = $result && $this->adapters[$adapterIndex]->replace($key, $value, $this->_getAdapterExpire($adapterIndex, $expire));
        }

        return $result;
    }

    public function cas(mixed $token, string $key, mixed $value, int $expire = 0): bool
    {
        throw new \Exception("method not implemented " . __FUNCTION__ , -1);
    }

    public function increment(string $key, int $offset = 1, int $initial = 0, int $expire = 0): int|false
    {
        throw new \Exception("method not implemented " . __FUNCTION__ , -1);
    }

    public function decrement(string $key, int $offset = 1, int $initial = 0, int $expire = 0): int|false
    {
        throw new \Exception("method not implemented " . __FUNCTION__ , -1);
    }

    public function touch(string $key, int $expire): bool
    {
        $adapterIndex = count($this->adapters);
        $result = true;
        while ($adapterIndex > 0) {
            $adapterIndex--;
            $result = $result && $this->adapters[$adapterIndex]->replace($key, $this->_getAdapterExpire($adapterIndex, $expire));
        }

        return $result;
    }

    public function flush(): bool
    {
        foreach ($this->collections as $collection) {
            $collection->flush();
        }

        $adapterIndex = count($this->adapters);
        $result = true;
        while ($adapterIndex > 0) {
            $adapterIndex--;
            $result = $result && $this->adapters[$adapterIndex]->flush();
        }

        return $result;
    }

    public function getCollection(string $name): KeyValueStore
    {
        $adapterCollections = [];
        if (!isset($this->collections[$name])) {
            $adapterIndex = 0; 
            while ($adapterIndex < count($this->adapters)) {
                $adapterIndex--;
                $adapterCollections[$adapterIndex] = $this->adapters[$adapterIndex]->getCollection($name);
            }

            $this->collections[$name] = new static($adapterCollections, $this->maxLifetimes);
        }

        return $this->collections[$name];
    }
}
