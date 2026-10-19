<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

use TMSWP\Core\Entity\EntityReference;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Label\LabelGenerator;
use TMSWP\Core\Label\LabelLanguage;

final class TaxonomyDefinition
{
    private string $label = '';

    /** @var array<string, string> */
    private array $labelOverrides = [];

    private LabelLanguage $labelLanguage = LabelLanguage::En;

    /** @var array<string, mixed> */
    private array $args = [];

    /** @var list<string> */
    private array $objectTypes = [];

    private function __construct(
        private readonly string $slug,
        private readonly bool $builtin,
    ) {}

    public static function create(string $slug): self
    {
        self::assertSlug($slug, 32);

        return new self($slug, false);
    }

    public static function builtin(string $slug): self
    {
        self::assertSlug($slug, null);

        return new self($slug, true);
    }

    public function objectType(string ...$objectTypes): self
    {
        $this->objectTypes = array_values([...$this->objectTypes, ...$objectTypes]);

        return $this;
    }

    public function label(string $label): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->label = $label;

        return $this;
    }

    /** @param array<string, string> $labels */
    public function labels(array $labels): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->labelOverrides = $labels;

        return $this;
    }

    public function labelLanguage(LabelLanguage $language): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->labelLanguage = $language;

        return $this;
    }

    public function description(string $description): self
    {
        return $this->argForNew('description', $description, __FUNCTION__);
    }

    public function public(bool $public = true): self
    {
        return $this->argForNew('public', $public, __FUNCTION__);
    }

    public function hierarchical(bool $hierarchical = true): self
    {
        return $this->argForNew('hierarchical', $hierarchical, __FUNCTION__);
    }

    public function publiclyQueryable(bool $queryable = true): self
    {
        return $this->argForNew('publicly_queryable', $queryable, __FUNCTION__);
    }

    public function showUi(bool $show = true): self
    {
        return $this->argForNew('show_ui', $show, __FUNCTION__);
    }

    public function showInMenu(bool $show = true): self
    {
        return $this->argForNew('show_in_menu', $show, __FUNCTION__);
    }

    public function showInNavMenus(bool $show = true): self
    {
        return $this->argForNew('show_in_nav_menus', $show, __FUNCTION__);
    }

    public function showTagcloud(bool $show = true): self
    {
        return $this->argForNew('show_tagcloud', $show, __FUNCTION__);
    }

    public function showInQuickEdit(bool $show = true): self
    {
        return $this->argForNew('show_in_quick_edit', $show, __FUNCTION__);
    }

    public function showAdminColumn(bool $show = true): self
    {
        return $this->argForNew('show_admin_column', $show, __FUNCTION__);
    }

    public function showInRest(bool $show = true): self
    {
        return $this->argForNew('show_in_rest', $show, __FUNCTION__);
    }

    public function restBase(string $base): self
    {
        return $this->argForNew('rest_base', $base, __FUNCTION__);
    }

    /** @param array<string, string> $capabilities */
    public function capabilities(array $capabilities): self
    {
        return $this->argForNew('capabilities', $capabilities, __FUNCTION__);
    }

    /** @param bool|array{slug?: string, with_front?: bool, hierarchical?: bool, ep_mask?: int} $rewrite */
    public function rewrite(bool|array $rewrite): self
    {
        return $this->argForNew('rewrite', $rewrite, __FUNCTION__);
    }

    public function rewriteSlug(string $slug, bool $withFront = true, bool $hierarchical = false): self
    {
        return $this->rewrite(['slug' => $slug, 'with_front' => $withFront, 'hierarchical' => $hierarchical]);
    }

    public function queryVar(bool|string $queryVar): self
    {
        return $this->argForNew('query_var', $queryVar, __FUNCTION__);
    }

    /** @param string|array{name: string, slug?: string, description?: string} $term */
    public function defaultTerm(string|array $term): self
    {
        return $this->argForNew('default_term', $term, __FUNCTION__);
    }

    public function sort(bool $sort = true): self
    {
        return $this->argForNew('sort', $sort, __FUNCTION__);
    }

    public function arg(string $key, mixed $value): self
    {
        return $this->argForNew($key, $value, __FUNCTION__);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function isBuiltin(): bool
    {
        return $this->builtin;
    }

    /** @return list<string> */
    public function resolvedObjectTypes(): array
    {
        $resolved = [];

        foreach ($this->objectTypes as $objectType) {
            $resolved[] = EntityReference::postTypeSlug($objectType);
        }

        return array_values(array_unique($resolved));
    }

    /** @return array<string, mixed> */
    public function toArgs(): array
    {
        if ($this->builtin) {
            throw new EntityDefinitionException("builtin taxonomy '{$this->slug}' cannot be converted to register_taxonomy args");
        }

        $args = $this->args;
        $labels = $this->labelsForArgs();
        $args['labels'] = $labels;
        $args['label'] = $labels['name'];

        return $args;
    }

    private static function assertSlug(string $slug, ?int $maxLength): void
    {
        if (preg_match('/^[a-z0-9_\-]+$/', $slug) !== 1) {
            throw new EntityDefinitionException("invalid taxonomy slug: {$slug}");
        }

        if ($maxLength !== null && \strlen($slug) > $maxLength) {
            throw new EntityDefinitionException("taxonomy slug must be {$maxLength} characters or fewer: {$slug}");
        }
    }

    private function assertNotBuiltin(string $method): void
    {
        if ($this->builtin) {
            throw new EntityDefinitionException("TaxonomyDefinition::{$method}() is not allowed for builtin taxonomy '{$this->slug}'");
        }
    }

    private function argForNew(string $key, mixed $value, string $method): self
    {
        $this->assertNotBuiltin($method);
        $this->args[$key] = $value;

        return $this;
    }

    /** @return array<string, string> */
    private function labelsForArgs(): array
    {
        if ($this->label === '' && !\array_key_exists('name', $this->labelOverrides)) {
            throw new EntityDefinitionException("taxonomy '{$this->slug}' requires label() or labels(['name' => ...])");
        }

        if ($this->label === '') {
            return $this->labelOverrides;
        }

        return array_merge(LabelGenerator::forTaxonomy($this->label, $this->labelLanguage), $this->labelOverrides);
    }
}
