<?php

declare(strict_types=1);

namespace MatthiasMullie\Scrapbook\Layered;

use MatthiasMullie\Scrapbook\KeyValueStore;

/**
 * This class will server as a layered cache adapter that fallbacks to lower
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
     * @var BufferedStore[]
     */
    private array $collections = [];



    /**
     * @param KeyValueStore[] $adapters
     */
    public function __construct(array $adapters)
    {
        $this->adapters = array_filter(
            $adapters,
            function ($a) {
                return $a instanceof KeyValueStore;
            }
        );
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
        while ($adapterIndex < count($this->adapters)) {
            // leave cas token out because it is not usable with layered cache
            $value = $this->adapters[$adapterIndex]->get($key);
            // if this adapter doesn't have the key go to the next adapter
            if (false === $value) {
                $adapterIndex++;
                continue;
            }
        }

        // write the missing value in the previous adapters
        while ($adapterIndex) {
            $adapterIndex--;
            $this->adapters[$adapterIndex]->set($key, $value);
        }

        return $value;
    }

    /**
     * In addition to all writes being stored to $local, we'll also
     * keep get() values around ;).
     *
     * {@inheritdoc}
     */
    public function getMulti(array $keys, ?array &$tokens = null): array
    {
        $adapterIndex = 0;
        $adapterMissing = [];
        while ($adapterIndex < count($this->adapters)) {
            $values = $this->adapters[$adapterIndex]->getMulti($keys);
            $missing = array_diff($keys, $values);
            if (!empty($missing)) {
                // todo : keep track of resolved vs missing values
            }
        }

        if (!empty($missing)) {
            $this->local->setMulti($missing);
        }

        return $values;
    }

    public function set(string $key, mixed $value, int $expire = 0): bool
    {
        $result = $this->transaction->set($key, $value, $expire);
        $this->transaction->commit();

        return $result;
    }

    public function setMulti(array $items, int $expire = 0): array
    {
        $result = $this->transaction->setMulti($items, $expire);
        $this->transaction->commit();

        return $result;
    }

    public function delete(string $key): bool
    {
        $result = $this->transaction->delete($key);
        $this->transaction->commit();

        return $result;
    }

    public function deleteMulti(array $keys): array
    {
        $result = $this->transaction->deleteMulti($keys);
        $this->transaction->commit();

        return $result;
    }

    public function add(string $key, mixed $value, int $expire = 0): bool
    {
        $result = $this->transaction->add($key, $value, $expire);
        $this->transaction->commit();

        return $result;
    }

    public function replace(string $key, mixed $value, int $expire = 0): bool
    {
        $result = $this->transaction->replace($key, $value, $expire);
        $this->transaction->commit();

        return $result;
    }

    public function cas(mixed $token, string $key, mixed $value, int $expire = 0): bool
    {
        $result = $this->transaction->cas($token, $key, $value, $expire);
        $this->transaction->commit();

        return $result;
    }

    public function increment(string $key, int $offset = 1, int $initial = 0, int $expire = 0): int|false
    {
        $result = $this->transaction->increment($key, $offset, $initial, $expire);
        $this->transaction->commit();

        return $result;
    }

    public function decrement(string $key, int $offset = 1, int $initial = 0, int $expire = 0): int|false
    {
        $result = $this->transaction->decrement($key, $offset, $initial, $expire);
        $this->transaction->commit();

        return $result;
    }

    public function touch(string $key, int $expire): bool
    {
        $result = $this->transaction->touch($key, $expire);
        $this->transaction->commit();

        return $result;
    }

    public function flush(): bool
    {
        foreach ($this->collections as $collection) {
            $collection->flush();
        }

        $result = $this->transaction->flush();
        $this->transaction->commit();

        return $result;
    }

    public function getCollection(string $name): KeyValueStore
    {
        if (!isset($this->collections[$name])) {
            $collection = $this->transaction->getCollection($name);
            $this->collections[$name] = new static($collection);
        }

        return $this->collections[$name];
    }
}
