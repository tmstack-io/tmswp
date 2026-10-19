<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Container\Container;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;
use TMSWP\Core\Option\OptionRepositoryFactory;
use TMSWP\Core\Option\OptionStorageInterface;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class OptionRepositoryTest extends UnitTestCase
{
    public function testRepositoryAppliesPrefixSchemaDefaultAndAutoload(): void
    {
        $storage = new ArrayOptionStorage();
        $factory = $this->factory($storage);
        $repository = $factory->for(SiteOptionFixture::class);

        self::assertSame('site_contact_email', $repository->name('contact_email'));
        self::assertSame([
            'contact_email' => 'info@example.com',
            'maintenance_mode' => false,
        ], $repository->all());
        self::assertSame('info@example.com', $repository->string('contact_email'));
        self::assertFalse($repository->bool('maintenance_mode'));

        self::assertTrue($repository->set('maintenance_mode', true));
        self::assertTrue($repository->bool('maintenance_mode'));
        self::assertFalse($storage->autoloads['site_maintenance_mode']);
    }

    public function testRepositoryAllowsUndeclaredKeysThroughDirectAccess(): void
    {
        $storage = new ArrayOptionStorage();
        $repository = $this->factory($storage)->for(SiteOptionFixture::class);

        self::assertFalse($repository->has('legacy_key'));
        self::assertNull($repository->get('legacy_key'));
        self::assertTrue($repository->set('legacy_key', 'legacy'));

        self::assertSame('legacy', $repository->string('legacy_key'));
        self::assertArrayHasKey('site_legacy_key', $storage->values);
    }

    public function testRepositoryDeletesAllDeclaredOptions(): void
    {
        $storage = new ArrayOptionStorage();
        $repository = $this->factory($storage)->for(SiteOptionFixture::class);

        self::assertTrue($repository->set('contact_email', 'changed@example.com'));
        self::assertTrue($repository->set('maintenance_mode', true));

        self::assertSame(['site_contact_email', 'site_maintenance_mode'], $repository->deleteAll());
        self::assertFalse($repository->has('contact_email'));
        self::assertFalse($repository->has('maintenance_mode'));
    }

    public function testFactoryRejectsInvalidOptionGroup(): void
    {
        $this->expectException(InvalidSchemaException::class);

        $this->factory(new ArrayOptionStorage())->for(NoOptionGroupAttributeFixture::class);
    }

    public function testFactoryRejectsNonStringBackedEnum(): void
    {
        $this->expectException(InvalidSchemaException::class);

        $this->factory(new ArrayOptionStorage())->for(IntBackedOptionFixture::class);
    }

    private function factory(ArrayOptionStorage $storage): OptionRepositoryFactory
    {
        $container = new Container();
        $container->set(ArrayOptionStorage::class, $storage);

        return new OptionRepositoryFactory($container);
    }
}

#[AsOptionGroup(prefix: 'site_', storage: ArrayOptionStorage::class)]
enum SiteOptionFixture: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: 'info@example.com')]
    case ContactEmail = 'contact_email';

    #[OptionSchema(type: MetaValueType::Boolean, default: false, autoload: false)]
    case Maintenance = 'maintenance_mode';
}

enum NoOptionGroupAttributeFixture: string
{
    case Key = 'key';
}

#[AsOptionGroup(prefix: 'int_', storage: ArrayOptionStorage::class)]
enum IntBackedOptionFixture: int
{
    #[OptionSchema(type: MetaValueType::Integer, default: 1)]
    case Key = 1;
}

final class ArrayOptionStorage implements OptionStorageInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, bool|null> */
    public array $autoloads = [];

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->values);
    }

    public function get(string $name): mixed
    {
        if (!\array_key_exists($name, $this->values)) {
            return null;
        }

        return $this->values[$name];
    }

    public function set(string $name, mixed $value, ?bool $autoload = null): bool
    {
        $this->values[$name] = $value;
        $this->autoloads[$name] = $autoload;

        return true;
    }

    public function delete(string $name): bool
    {
        unset($this->values[$name], $this->autoloads[$name]);

        return true;
    }
}
