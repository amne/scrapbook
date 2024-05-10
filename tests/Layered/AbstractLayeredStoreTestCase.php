<?php

declare(strict_types=1);

namespace MatthiasMullie\Scrapbook\Tests\Layered;

use MatthiasMullie\Scrapbook\Adapters\MemoryStore;
use MatthiasMullie\Scrapbook\Layered\LayeredStore;
use MatthiasMullie\Scrapbook\KeyValueStore;
use MatthiasMullie\Scrapbook\Tests\AbstractKeyValueStoreTestCase;

abstract class AbstractLayeredStoreTestCase extends AbstractKeyValueStoreTestCase
{
    protected $subjectKeyValueStores = [];
    protected $expectedMaxLifetimes = [];

    public function getTestKeyValueStore(KeyValueStore $keyValueStore): KeyValueStore
    {
        $this->subjectKeyValueStores = [
            new MemoryStore(),
            new MemoryStore(),
            $keyValueStore
        ];

        $this->expectedMaxLifetimes = [
            2,
            10,
            60
        ];
        return new LayeredStore($this->subjectKeyValueStores, $this->expectedMaxLifetimes);
    }

    public function testLayeredGetSyncBack(): void
    {
        $this->testKeyValueStore->set('key', 'value');

        // check if value set via buffered cache can be located
        // in all cache layers
        array_walk(
            $this->subjectKeyValueStores,
            fn($kvStore) => $this->assertEquals('value', $kvStore->get('key'))
        );
    }

    public function testLayeredGetMultiSyncBack(): void
    {
        $this->subjectKeyValueStores[1]->set('key_1', 'value_1');
        $this->subjectKeyValueStores[2]->set('key_2', 'value_2');

        $this->testKeyValueStore->getMulti(['key_1', 'key_2']);

        // test getMulti brings both keys to kvStore[0]
        $this->assertEquals('value_1', $this->subjectKeyValueStores[0]->get('key_1'));
        $this->assertEquals('value_2', $this->subjectKeyValueStores[0]->get('key_2'));
    }

    public function testFirstLayerExpires(): void
    {
        $this->testKeyValueStore->set('k1', 'v1');
        $this->assertEquals('v1', $this->subjectKeyValueStores[0]->get('k1'));
        sleep(3);
        $this->assertEquals(false, $this->subjectKeyValueStores[0]->get('k1'));

        // refresh it
        $this->testKeyValueStore->get('k1');
        $this->assertEquals('v1', $this->subjectKeyValueStores[0]->get('k1'));
    }
}
