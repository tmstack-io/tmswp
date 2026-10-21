<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Tests\Support\MemoryMetaStorage;
use TMSWP\Core\Tests\Support\MetaStorageContract;
use TMSWP\Core\Tests\Support\UnitTestCase;

/** テストで既定のストレージと差し替えるMemoryMetaStorageが、MetaStorageInterfaceの契約を満たすことを確かめる。 */
final class MemoryMetaStorageContractTest extends UnitTestCase
{
    use MetaStorageContract;

    private ?MemoryMetaStorage $storage = null;

    private int $lastObjectId = 0;

    protected function storage(): MetaStorageInterface
    {
        return $this->storage ??= new MemoryMetaStorage();
    }

    protected function objectType(): ObjectType
    {
        return ObjectType::Post;
    }

    protected function createObject(): int
    {
        return ++$this->lastObjectId;
    }
}
