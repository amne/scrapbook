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
        $value = false;
        $adapterTokens = [];
        $token = null;
        while (null === $token && false === $value && $adapterIndex < count($this->adapters)) {
            $value = $this->adapters[$adapterIndex]->get($key, $token);
            $adapterIndex++;
        }

        if (null === $token && false === $value) {
            return false;
        }

        $token = md5(serialize($value)); 
        // write the missing value in the previous adapters
        while (--$adapterIndex) {
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
        $syncback = [];
        // stop when we've found all the values or we're out of adapters
        while (!empty($missing) && $adapterIndex < count($this->adapters)) {
            $adapterValues[$adapterIndex] = $this->adapters[$adapterIndex]->getMulti($missing) ?? [];
            $values = array_merge($values, $adapterValues[$adapterIndex]);
            // keep track of missing values in each adapter
            $syncback[$adapterIndex] = array_intersect($missing, array_keys($adapterValues[$adapterIndex]));
            $missing = $adapterMissing[$adapterIndex] = array_diff($missing, array_keys($adapterValues[$adapterIndex]));
            if (!empty($missing)) {
                $adapterIndex++;
            }
        }

        // walk back and sync missing values
        while ($adapterIndex > 1) {
            $adapterIndex--;
            if (!count($syncback[$adapterIndex])) {
                continue;
            }
            $this->adapters[$adapterIndex-1]->setMulti($syncback[$adapterIndex]);
        }

        $tokens = $values;
        array_walk($tokens, fn(&$e) => $e = md5(serialize($e)));

        return $values;
    }

    /**
     * If expire > 30 days then just leave it alone is its most likely an absolute
     * unix timestamp.
     * If expire <= 30 days then it's a relative TTL (lifetime) so we'll clamp it
     * to max lifetime for the adapter 
     */
    private function _getAdapterExpire(int $adapterIndex, int $expire = 0)
    {
        return $expire > 2592000 ? $expire : min($expire, $this->maxLifetimes[$adapterIndex]);
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
                $this->adapters[$adapterIndex++]->delete($key);
            }
        }

        return $result;
    }

    public function setMulti(array $items, int $expire = 0): array
    {
        $adapterIndex = count($this->adapters);
        $results = array_fill_keys(array_keys($items), true); 
        $rollback = [];
        $result = true;
        while ($result && $adapterIndex > 0) {
            $adapterIndex--;
            $adapterResults = $this->adapters[$adapterIndex]->setMulti($items, $this->_getAdapterExpire($adapterIndex, $expire));
            array_walk($results, fn(&$r, $k) => $r = $r && $adapterResults[$k]);
            $result = array_reduce($results, fn($reduced, $r) => $reduced && $r, true);
        }

        if (!$result) {
            // best effort rollback
            while ($adapterIndex < count($this->adapters)) {
                $this->adapters[$adapterIndex++]->deleteMulti(array_keys($items));
            }
        }

        return $results;
    }

    public function delete(string $key): bool
    {
        $adapterIndex = count($this->adapters);
        $result = true;
        while ($adapterIndex > 0) {
            $adapterIndex--;
            $result = $result && $this->adapters[$adapterIndex]->delete($key);
        }

        return $result;
    }

    public function deleteMulti(array $keys): array
    {
        $adapterIndex = count($this->adapters);
        $results = array_fill_keys($keys, true); 
        while ($adapterIndex > 0) {
            $adapterIndex--;
            $adapterResults = $this->adapters[$adapterIndex]->deleteMulti($keys);
            array_walk($results, fn(&$r, $k) => $r = $r && $adapterResults[$k]);
            // $result = array_reduce($results, fn($reduced, $r) => $reduced && $r, true);
        }

        return $results;
    }

    public function add(string $key, mixed $value, int $expire = 0): bool
    {
        $adapterIndex = count($this->adapters);
        $result = true;
        while ($result && $adapterIndex > 0) {
            $adapterIndex--;
            $result = $result && $this->adapters[$adapterIndex]->add($key, $value, $this->_getAdapterExpire($adapterIndex, $expire));
        }

        if (!$result) {
            // best effort rollback
            while ($adapterIndex < count($this->adapters)) {
                $this->adapters[$adapterIndex++]->delete($key);
            }
        }

        return $result;
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
        $this->get($key, $currentToken);
        if ($token !== $currentToken || null === $token) {
            return false;
        }

        return $this->set($key, $value, $expire);
    }

    /**
     * Incrementing a value in a layered cache needs to compromise on two things:
     * - we'll always do the increment on the last layer and sync the value to
     *   the higher layers
     * - we need to reset the expiration
     */
    public function increment(string $key, int $offset = 1, int $initial = 0, int $expire = 0): int|false
    {
        $adapterIndex = count($this->adapters) - 1;
        $result = $this->adapters[$adapterIndex]->increment($key, $offset, $initial, $expire);
        if (false !== $result) {
            while ($adapterIndex) {
                $adapterIndex--;
                $this->adapters[$adapterIndex]->set($key, $result, $expire);
            }
        }

        return $result;
    }

    /**
     * See increment() for notes about sync and expire
     */
    public function decrement(string $key, int $offset = 1, int $initial = 0, int $expire = 0): int|false
    {
        $adapterIndex = count($this->adapters) - 1;
        $result = $this->adapters[$adapterIndex]->decrement($key, $offset, $initial, $expire);
        if (false !== $result) {
            while ($adapterIndex) {
                $adapterIndex--;
                $this->adapters[$adapterIndex]->set($key, $result, $expire);
            }
        }

        return $result;
    }

    public function touch(string $key, int $expire): bool
    {
        $adapterIndex = count($this->adapters);
        $result = true;
        while ($adapterIndex > 0) {
            $adapterIndex--;
            $result = $result && $this->adapters[$adapterIndex]->touch($key, $this->_getAdapterExpire($adapterIndex, $expire));
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
                $adapterCollections[$adapterIndex] = $this->adapters[$adapterIndex]->getCollection($name);
                $adapterIndex++;
            }

            $this->collections[$name] = new static($adapterCollections, $this->maxLifetimes);
        }

        return $this->collections[$name];
    }
}
