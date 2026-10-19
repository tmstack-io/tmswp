<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Exception\MigrationException;
use TMSWP\Core\Kernel\Kernel;
use WP_CLI;

final class MigrateCommand
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered || !\defined('WP_CLI') || WP_CLI !== true || !class_exists('WP_CLI')) {
            return;
        }

        WP_CLI::add_command('tmswp migrate', new self());
        self::$registered = true;
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$registered = false;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (!class_exists('WP_CLI')) {
            throw new KernelException('WP_CLI is not available');
        }

        if ($args !== []) {
            WP_CLI::error('Usage: wp tmswp migrate [--dry-run] [--force]');
        }

        $dryRun = \array_key_exists('dry-run', $assocArgs);
        $force = \array_key_exists('force', $assocArgs);
        $migrated = 0;

        try {
            foreach (Kernel::all() as $kernel) {
                $migrator = $kernel->container()->get(Migrator::class);

                if (!$migrator instanceof Migrator) {
                    throw new KernelException('container entry Migrator must resolve to Migrator');
                }

                foreach ($migrator->migrateAll($force, $dryRun) as $result) {
                    WP_CLI::line("{$kernel->config()->appNamespace}: {$result->table} {$result->action}");

                    if ($result->action === 'migrated') {
                        ++$migrated;
                    }
                }
            }
        } catch (MigrationException $exception) {
            WP_CLI::error($exception->getMessage());
        }

        WP_CLI::success($dryRun ? 'schema migration dry-run completed' : "schema migration completed; migrated {$migrated} table(s)");
    }
}
