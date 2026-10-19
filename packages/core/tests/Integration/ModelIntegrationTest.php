<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use ReflectionProperty;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Tests\Support\UsesTempDirectories;
use WP_UnitTestCase;
use wpdb;

final class ModelIntegrationTest extends WP_UnitTestCase
{
    use UsesTempDirectories;

    /** @var list<callable(string): void> */
    private array $autoloaders = [];

    /** @var list<string> */
    private array $tables = [];

    public function tear_down(): void
    {
        $wpdb = $this->wpdb();

        foreach ($this->tables as $table) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table);
        }

        delete_option('tmswp_schema_hashes');

        foreach ($this->autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        $this->autoloaders = [];
        $this->tables = [];
        Kernel::reset();
        $this->cleanupTempDirectories();

        parent::tear_down();
    }

    public function testKernelRegisteredModelMigratesAndRunsCrud(): void
    {
        $wpdb = $this->wpdb();

        $namespace = $this->runtimeNamespace('ModelApp');
        $appDir = $this->createTempDirectory('integration-model-app');
        $cacheDir = $this->createTempDirectory('integration-model-cache');
        $table = $this->trackTable('m6_contacts_' . substr(md5(uniqid('', true)), 0, 8));

        $this->writeContactModel($namespace, $appDir, $table);
        $this->writeContactV2Model($namespace, $appDir, $table);
        $this->registerAppAutoloader($namespace, $appDir);

        $kernel = Kernel::boot(KernelConfig::theme($appDir, $namespace, $cacheDir, false, false));
        if (did_action('after_setup_theme') === 0) {
            do_action('after_setup_theme');
        }

        do_action('tmswp/activated', $kernel);

        $connection = new Connection($wpdb);
        self::assertSame($wpdb->prefix . $table, $connection->selectVar('SHOW TABLES LIKE %s', [$wpdb->prefix . $table]));

        $contact = $this->modelClass($namespace, 'Contact');
        $id = $contact::insert([
            'email' => 'first@example.test',
            'active' => true,
            'meta' => '{"tags":["news"]}',
            'created_at' => '2026-06-13 08:30:00',
            'status' => 'published',
        ]);

        self::assertGreaterThan(0, $id);
        self::assertSame(1, $contact::count());

        $found = $contact::find($id);
        self::assertIsObject($found);
        self::assertSame('first@example.test', $this->objectProperty($found, 'email'));
        self::assertSame(['tags' => ['news']], $this->objectProperty($found, 'meta'));

        self::assertSame(1, $contact::update($id, ['email' => 'updated@example.test']));
        $updated = $contact::find($id);
        self::assertIsObject($updated);
        self::assertSame('updated@example.test', $this->objectProperty($updated, 'email'));

        $v2 = $this->modelClass($namespace, 'ContactV2');
        $migrator = new Migrator($connection);
        $migrator->register($v2);
        $migrator->migrateAll(force: true);

        self::assertSame('phone', $connection->selectVar('SHOW COLUMNS FROM ' . $wpdb->prefix . $table . ' LIKE %s', ['phone']));
        $phoneIndex = $connection->selectRow('SHOW INDEX FROM ' . $wpdb->prefix . $table . ' WHERE Key_name = %s', ['phone_idx']);
        self::assertIsArray($phoneIndex);
        self::assertSame('phone_idx', $phoneIndex['Key_name'] ?? null);

        self::assertSame(1, $contact::delete($id));
        self::assertSame(0, $contact::count());
    }

    private function runtimeNamespace(string $prefix): string
    {
        return 'Example\\Tests\\Integration\\' . $prefix . uniqid();
    }

    private function wpdb(): wpdb
    {
        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            self::fail('wpdb is not available.');
        }

        return $wpdb;
    }

    private function trackTable(string $table): string
    {
        $this->tables[] = $table;

        return $table;
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(string $namespace, string $name): string
    {
        $class = $namespace . '\\Models\\' . $name;

        if (!is_subclass_of($class, Model::class)) {
            self::fail("model class was not loaded: {$class}");
        }

        return $class;
    }

    private function objectProperty(object $object, string $name): mixed
    {
        if (!property_exists($object, $name)) {
            self::fail("object property was not found: {$name}");
        }

        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    private function registerAppAutoloader(string $namespace, string $appDir): void
    {
        $prefix = $namespace . '\\';
        $loader = static function (string $class) use ($appDir, $prefix): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, \strlen($prefix))) . '.php';
            $file = $appDir . DIRECTORY_SEPARATOR . $relative;

            if (!is_file($file)) {
                return;
            }

            require $file;
        };

        spl_autoload_register($loader);
        $this->autoloaders[] = $loader;
    }

    private function writeContactModel(string $namespace, string $appDir, string $table): void
    {
        $this->writeFile($appDir . '/Models/Contact.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Models;

use DateTimeImmutable;
use TMSWP\\Core\\Attribute\\AsModel;
use TMSWP\\Core\\Model\\Model;
use TMSWP\\Core\\Model\\TableDefinition;

enum ContactStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

#[AsModel]
final class Contact extends Model
{
    public function __construct(
        public int \$id,
        public string \$email,
        public bool \$active,
        public array \$meta,
        public DateTimeImmutable \$createdAt,
        public ContactStatus \$status,
    ) {}

    public static function table(): TableDefinition
    {
        return TableDefinition::create('{$table}')
            ->id()
            ->string('email')
            ->bool('active')
            ->json('meta')
            ->datetime('created_at')
            ->string('status')
            ->unique('email', 'email_unique');
    }
}
PHP);
    }

    private function writeContactV2Model(string $namespace, string $appDir, string $table): void
    {
        $this->writeFile($appDir . '/Models/ContactV2.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Models;

use TMSWP\\Core\\Model\\Model;
use TMSWP\\Core\\Model\\TableDefinition;

final class ContactV2 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('{$table}')
            ->id()
            ->string('email')
            ->bool('active')
            ->json('meta')
            ->datetime('created_at')
            ->string('status')
            ->string('phone', nullable: true)
            ->unique('email', 'email_unique')
            ->index('phone', 'phone_idx');
    }

    public static function rowClass(): ?string
    {
        return null;
    }
}
PHP);
    }

    private function writeFile(string $file, string $contents): string
    {
        $dir = \dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("fixture ディレクトリを作成できません: {$dir}");
        }

        $bytes = file_put_contents($file, $contents);

        if ($bytes === false) {
            self::fail("fixture ファイルを書き込めません: {$file}");
        }

        return $file;
    }
}
