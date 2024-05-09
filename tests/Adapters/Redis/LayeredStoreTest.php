<?php

declare(strict_types=1);

namespace MatthiasMullie\Scrapbook\Tests\Adapters\Redis;

use MatthiasMullie\Scrapbook\Tests\Layered\AbstractLayeredStoreTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('layered')]
class LayeredStoreTest extends AbstractLayeredStoreTestCase
{
    use AdapterProviderTrait;
}
