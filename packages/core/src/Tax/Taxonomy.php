<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

use TMSWP\Core\Entity\WpBridge;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\EntityPersistenceException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Log\LoggerRegistry;
use TMSWP\Core\Meta\MetaBag;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use WP_Error;
use WP_Taxonomy;
use WP_Term;

abstract class Taxonomy
{
    /** @var array<class-string<self>, TaxonomyDefinition> */
    private static array $definitions = [];

    abstract public static function definition(): TaxonomyDefinition;

    final public static function slug(): string
    {
        return self::definitionFor(static::class)->slug();
    }

    /**
     * タクソノミーを登録する。TaxonomyInitializer が、このクラスを探索した Kernel を渡して呼ぶ。
     */
    final public static function register(Kernel $kernel): void
    {
        $definition = self::definitionFor(static::class);
        $slug = $definition->slug();

        if ($definition->isBuiltin()) {
            self::registerBuiltinObjectTypes($definition, $kernel->logger());

            return;
        }

        if (taxonomy_exists($slug)) {
            throw new EntityDefinitionException("taxonomy is already registered: {$slug}");
        }

        $registered = WpBridge::call('register_taxonomy', $slug, $definition->resolvedObjectTypes(), $definition->toArgs($kernel->config()->labelLanguage));

        if ($registered instanceof WP_Error) {
            throw new EntityDefinitionException($registered->get_error_message());
        }
    }

    final public static function get(WP_Term|int $term): ?WP_Term
    {
        if ($term instanceof WP_Term) {
            return $term->taxonomy === static::slug() ? $term : null;
        }

        if ($term <= 0) {
            return null;
        }

        $resolved = get_term($term, static::slug());

        if (!$resolved instanceof WP_Term || $resolved->taxonomy !== static::slug()) {
            return null;
        }

        return $resolved;
    }

    final public static function findBySlug(string $slug): ?WP_Term
    {
        $term = get_term_by('slug', $slug, static::slug());

        return $term instanceof WP_Term ? $term : null;
    }

    final public static function findByName(string $name): ?WP_Term
    {
        $term = get_term_by('name', $name, static::slug());

        return $term instanceof WP_Term ? $term : null;
    }

    final public static function is(WP_Term|int|null $term): bool
    {
        if ($term === null) {
            return false;
        }

        return static::get($term) instanceof WP_Term;
    }

    public static function query(): TermQuery
    {
        return TermQuery::of(static::class);
    }

    /** @return list<WP_Term> */
    final public static function all(bool $hideEmpty = false): array
    {
        return static::query()->hideEmpty($hideEmpty)->get();
    }

    /** @return MetaBag<static> */
    final public static function meta(WP_Term|int $term): MetaBag
    {
        $resolved = static::get($term);

        if (!$resolved instanceof WP_Term) {
            throw new EntityDefinitionException('cannot create MetaBag for missing or mismatched term');
        }

        $registry = Kernel::forClass(static::class)->container()->get(MetaSchemaRegistry::class);

        return $registry->bagFor(static::class, $resolved);
    }

    final public static function url(WP_Term|int|string $term): ?string
    {
        $resolved = \is_string($term) ? static::findBySlug($term) : static::get($term);

        if (!$resolved instanceof WP_Term) {
            return null;
        }

        $url = get_term_link($resolved);

        return $url instanceof WP_Error ? null : $url;
    }

    /** @return list<WP_Term> */
    final public static function parents(WP_Term|int $term, bool $includeSelf = false): array
    {
        $resolved = static::get($term);

        if (!$resolved instanceof WP_Term) {
            return [];
        }

        $parents = $includeSelf ? [$resolved] : [];
        $current = $resolved;

        for ($depth = 0; $depth < 100; ++$depth) {
            if ((int) $current->parent <= 0) {
                return array_reverse($parents);
            }

            $parent = static::get((int) $current->parent);

            if (!$parent instanceof WP_Term) {
                return array_reverse($parents);
            }

            $parents[] = $parent;
            $current = $parent;
        }

        throw new EntityDefinitionException('term parent depth exceeded 100');
    }

    /** @param array<string, mixed> $args */
    final public static function create(string $name, array $args = []): int
    {
        $result = WpBridge::call('wp_insert_term', $name, static::slug(), $args);

        if ($result instanceof WP_Error) {
            throw EntityPersistenceException::fromWpError($result);
        }

        if (!\is_array($result) || !\array_key_exists('term_id', $result) || !\is_int($result['term_id'])) {
            throw new EntityPersistenceException('wp_insert_term did not return an integer term_id');
        }

        return $result['term_id'];
    }

    final public static function taxonomyObject(): ?WP_Taxonomy
    {
        $object = get_taxonomy(static::slug());

        return $object instanceof WP_Taxonomy ? $object : null;
    }

    final public static function resetRuntimeCache(): void
    {
        unset(self::$definitions[static::class]);
    }

    /**
     * @param class-string<self> $class
     */
    private static function definitionFor(string $class): TaxonomyDefinition
    {
        if (!\array_key_exists($class, self::$definitions)) {
            self::$definitions[$class] = $class::definition();
        }

        return self::$definitions[$class];
    }

    private static function registerBuiltinObjectTypes(TaxonomyDefinition $definition, LoggerRegistry $logger): void
    {
        $retry = [];

        foreach ($definition->resolvedObjectTypes() as $objectType) {
            if (!register_taxonomy_for_object_type($definition->slug(), $objectType)) {
                $retry[] = $objectType;
            }
        }

        if ($retry === []) {
            return;
        }

        add_action('init', static function () use ($definition, $retry, $logger): void {
            foreach ($retry as $objectType) {
                if (!register_taxonomy_for_object_type($definition->slug(), $objectType)) {
                    $logger->warning("failed to attach taxonomy '{$definition->slug()}' to post type '{$objectType}'");
                }
            }
        }, 11, 0);
    }
}
