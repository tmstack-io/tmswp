<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

use TMSWP\Core\Entity\EntityReference;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Label\LabelGenerator;
use TMSWP\Core\Label\LabelLanguage;

final class TaxonomyDefinition
{
    /** null は label() が呼ばれていないこと */
    private ?string $singularLabel = null;

    private ?string $pluralLabel = null;

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

    /**
     * labels を生成する元の表示名を指定する。
     *
     * 英語(LabelLanguage::En)では複数形が必須。日本語(LabelLanguage::Ja)では省略でき、省略時は単数形を複数形にも使う。
     */
    public function label(string $singular, ?string $plural = null): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->singularLabel = $singular;
        $this->pluralLabel = $plural;

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

    /** @return array<string, string|null> */
    private function labelsForArgs(): array
    {
        if ($this->singularLabel === null && !\array_key_exists('name', $this->labelOverrides)) {
            throw new EntityDefinitionException("taxonomy '{$this->slug}' requires label() or labels(['name' => ...])");
        }

        if ($this->singularLabel === null) {
            return $this->labelOverrides;
        }

        // 空文字のラベルは、複数形や labels() の有無・言語に関係なく設定の誤りとして拒否する
        if ($this->singularLabel === '') {
            throw new EntityDefinitionException("taxonomy '{$this->slug}' got an empty singular label: pass a non-empty singular to label()");
        }

        // 複数形の省略は null(第2引数を渡さない)で表すため、空文字の複数形も拒否する
        if ($this->pluralLabel === '') {
            throw new EntityDefinitionException("taxonomy '{$this->slug}' got an empty plural label: pass a non-empty plural, or omit the second argument of label() (null) to omit it");
        }

        // 日本語には複数形が無いため、Ja に限り省略した複数形を単数形で補う。En の省略は設定の誤りとして常に拒否する。
        $plural = $this->pluralLabel ?? match ($this->labelLanguage) {
            LabelLanguage::Ja => $this->singularLabel,
            LabelLanguage::En => throw new EntityDefinitionException("taxonomy '{$this->slug}' requires a plural label for LabelLanguage::En: pass it as label('{$this->singularLabel}', '<plural>')"),
        };

        return array_merge(
            LabelGenerator::forTaxonomy($this->singularLabel, $plural, $this->labelLanguage, (bool) ($this->args['hierarchical'] ?? false)),
            $this->labelOverrides,
        );
    }
}
