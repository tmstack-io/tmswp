<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Entity\EntityReference;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Label\LabelGenerator;
use TMSWP\Core\Label\LabelLanguage;

final class PostTypeDefinition
{
    /** null は label() が呼ばれていないこと */
    private ?string $singularLabel = null;

    private ?string $pluralLabel = null;

    /** @var array<string, string> */
    private array $labelOverrides = [];

    private LabelLanguage $labelLanguage = LabelLanguage::En;

    /** @var array<string, mixed> */
    private array $args = [];

    /** @var list<string>|null */
    private ?array $supports = null;

    /** @var list<string> */
    private array $taxonomies = [];

    private ?bool $blockEditor = null;

    private function __construct(
        private readonly string $slug,
        private readonly bool $builtin,
    ) {}

    public static function create(string $slug): self
    {
        self::assertSlug($slug, 20);
        $definition = new self($slug, false);
        $definition->args['public'] = true;

        return $definition;
    }

    public static function builtin(string $slug): self
    {
        self::assertSlug($slug, null);

        return new self($slug, true);
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

    public function excludeFromSearch(bool $exclude = true): self
    {
        return $this->argForNew('exclude_from_search', $exclude, __FUNCTION__);
    }

    public function publiclyQueryable(bool $queryable = true): self
    {
        return $this->argForNew('publicly_queryable', $queryable, __FUNCTION__);
    }

    public function showUi(bool $show = true): self
    {
        return $this->argForNew('show_ui', $show, __FUNCTION__);
    }

    public function showInMenu(bool|string $show = true): self
    {
        return $this->argForNew('show_in_menu', $show, __FUNCTION__);
    }

    public function showInNavMenus(bool $show = true): self
    {
        return $this->argForNew('show_in_nav_menus', $show, __FUNCTION__);
    }

    public function showInAdminBar(bool $show = true): self
    {
        return $this->argForNew('show_in_admin_bar', $show, __FUNCTION__);
    }

    public function menuPosition(int $position): self
    {
        return $this->argForNew('menu_position', $position, __FUNCTION__);
    }

    public function menuIcon(string $icon): self
    {
        return $this->argForNew('menu_icon', $icon, __FUNCTION__);
    }

    public function showInRest(bool $show = true): self
    {
        return $this->argForNew('show_in_rest', $show, __FUNCTION__);
    }

    public function restBase(string $base): self
    {
        return $this->argForNew('rest_base', $base, __FUNCTION__);
    }

    public function blockEditor(bool $enabled): self
    {
        $this->blockEditor = $enabled;

        return $this;
    }

    /** @param string|array{0: string, 1: string} $type */
    public function capabilityType(string|array $type, bool $mapMetaCap = true): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->args['capability_type'] = $type;
        $this->args['map_meta_cap'] = $mapMetaCap;

        return $this;
    }

    /** @param array<string, string> $capabilities */
    public function capabilities(array $capabilities): self
    {
        return $this->argForNew('capabilities', $capabilities, __FUNCTION__);
    }

    public function supports(string ...$features): self
    {
        $this->supports = array_values(array_unique($features));

        return $this;
    }

    public function taxonomies(string ...$taxonomies): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->taxonomies = array_values([...$this->taxonomies, ...$taxonomies]);

        return $this;
    }

    public function hasArchive(bool|string $archive = true): self
    {
        return $this->argForNew('has_archive', $archive, __FUNCTION__);
    }

    /** @param bool|array{slug?: string, with_front?: bool, feeds?: bool, pages?: bool, ep_mask?: int} $rewrite */
    public function rewrite(bool|array $rewrite): self
    {
        return $this->argForNew('rewrite', $rewrite, __FUNCTION__);
    }

    public function rewriteSlug(string $slug, bool $withFront = true): self
    {
        return $this->rewrite(['slug' => $slug, 'with_front' => $withFront]);
    }

    public function queryVar(bool|string $queryVar): self
    {
        return $this->argForNew('query_var', $queryVar, __FUNCTION__);
    }

    public function canExport(bool $canExport = true): self
    {
        return $this->argForNew('can_export', $canExport, __FUNCTION__);
    }

    public function deleteWithUser(bool $delete = true): self
    {
        return $this->argForNew('delete_with_user', $delete, __FUNCTION__);
    }

    /** @param array<int, array<int, mixed>> $blockTemplate */
    public function template(array $blockTemplate, string|false $templateLock = false): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->args['template'] = $blockTemplate;
        $this->args['template_lock'] = $templateLock;

        return $this;
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

    public function blockEditorPreference(): ?bool
    {
        return $this->blockEditor;
    }

    /** @return list<string> */
    public function supportsList(): array
    {
        if ($this->supports === null) {
            return [];
        }

        return $this->supports;
    }

    /** @return array<string, mixed> */
    public function toArgs(): array
    {
        if ($this->builtin) {
            throw new EntityDefinitionException("builtin post type '{$this->slug}' cannot be converted to register_post_type args");
        }

        $args = $this->args;
        $labels = $this->labelsForArgs();
        $args['labels'] = $labels;
        $args['label'] = $labels['name'];

        if ($this->supports !== null) {
            $args['supports'] = $this->supports;
        }

        if ($this->taxonomies !== []) {
            $resolved = [];

            foreach ($this->taxonomies as $taxonomy) {
                $resolved[] = EntityReference::taxonomySlug($taxonomy);
            }

            $args['taxonomies'] = array_values(array_unique($resolved));
        }

        return $args;
    }

    private static function assertSlug(string $slug, ?int $maxLength): void
    {
        if (preg_match('/^[a-z0-9_\-]+$/', $slug) !== 1) {
            throw new EntityDefinitionException("invalid post type slug: {$slug}");
        }

        if ($maxLength !== null && \strlen($slug) > $maxLength) {
            throw new EntityDefinitionException("post type slug must be {$maxLength} characters or fewer: {$slug}");
        }
    }

    private function assertNotBuiltin(string $method): void
    {
        if ($this->builtin) {
            throw new EntityDefinitionException("PostTypeDefinition::{$method}() is not allowed for builtin post type '{$this->slug}'");
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
            throw new EntityDefinitionException("post type '{$this->slug}' requires label() or labels(['name' => ...])");
        }

        if ($this->singularLabel === null) {
            return $this->labelOverrides;
        }

        // 空文字のラベルは、複数形や labels() の有無・言語に関係なく設定の誤りとして拒否する
        if ($this->singularLabel === '') {
            throw new EntityDefinitionException("post type '{$this->slug}' got an empty singular label: pass a non-empty singular to label()");
        }

        // 複数形の省略は null(第2引数を渡さない)で表すため、空文字の複数形も拒否する
        if ($this->pluralLabel === '') {
            throw new EntityDefinitionException("post type '{$this->slug}' got an empty plural label: pass a non-empty plural, or omit the second argument of label() (null) to omit it");
        }

        // 日本語には複数形が無いため、Ja に限り省略した複数形を単数形で補う。En の省略は設定の誤りとして常に拒否する。
        $plural = $this->pluralLabel ?? match ($this->labelLanguage) {
            LabelLanguage::Ja => $this->singularLabel,
            LabelLanguage::En => throw new EntityDefinitionException("post type '{$this->slug}' requires a plural label for LabelLanguage::En: pass it as label('{$this->singularLabel}', '<plural>')"),
        };

        return array_merge(
            LabelGenerator::forPostType($this->singularLabel, $plural, $this->labelLanguage, (bool) ($this->args['hierarchical'] ?? false)),
            $this->labelOverrides,
        );
    }
}
