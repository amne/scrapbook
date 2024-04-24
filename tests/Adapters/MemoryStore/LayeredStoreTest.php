<?php

declare(strict_types=1);

namespace MatthiasMullie\Scrapbook\Tests\Adapters\MemoryStore;

use MatthiasMullie\Scrapbook\Adapters\MemoryStore;
use MatthiasMullie\Scrapbook\Layered\LayeredStore;
use MatthiasMullie\Scrapbook\KeyValueStore;
use MatthiasMullie\Scrapbook\Tests\AbstractKeyValueStoreTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('layered')]
class LayeredStoreTest extends AbstractKeyValueStoreTestCase
{
    use AdapterProviderTrait;

    protected $subjectAdapters = [];
    protected $expectedMaxLifetimes = [];

    public function getTestKeyValueStore(KeyValueStore $keyValueStore): KeyValueStore
    {
        $this->subjectAdapters = [
            new MemoryStore(),
            new MemoryStore(),
            $keyValueStore
        ];

        $this->expectedMaxLifetimes = [
            10,
            60,
            120
        ];
        return new LayeredStore($this->subjectAdapters, $this->expectedMaxLifetimes);
    }

    public function testLayeredGetFromCache(): void
    {
        // test if value set via buffered cache can be located
        // in all cache layers
        $this->testKeyValueStore->set('key', 'value');
        $this->assertEquals('value', $this->subjectAdapters[0]->get('key'));
        $this->assertEquals('value', $this->subjectAdapters[1]->get('key'));
        $this->assertEquals('value', $this->subjectAdapters[2]->get('key'));
    }

    public function testLayeredSetFromCache(): void
    {
        // test if existing value in cache can be fetched from
        // buffer & real cache
        $this->subjectAdapters[2]->set('key', 'value');
        $this->assertEquals('value', $this->testKeyValueStore->get('key'));
        $this->assertEquals('value', $this->subjectAdapters[1]->get('key'));
    }
}
