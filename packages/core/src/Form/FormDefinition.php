<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use Closure;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\Form\UnknownFieldException;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\Rule\RuleInterface;

final class FormDefinition
{
    /** @var array<string, Field|CompositeField> */
    private array $fields = [];

    private bool $confirm = false;

    private int $ttl = 1800;

    /** @var null|Closure(array<string, mixed>, Form): void */
    private ?Closure $onComplete = null;

    private string $errorHtml = '<p class="form-error">%s</p>';

    private ?TempFileStore $store = null;

    /** @var list<SpamGuardInterface> */
    private array $spamGuards = [];

    private function __construct(public readonly string $id)
    {
        if (preg_match('/\A[a-z][a-z0-9_-]*\z/', $id) !== 1) {
            throw new FormConfigException("invalid form id: {$id}");
        }
    }

    public static function create(string $id): self
    {
        return new self($id);
    }

    /**
     * @param list<RuleInterface> $rules
     * @param list<Closure(string): string> $filters
     */
    public function text(string $name, string $label, array $rules = [], string $default = '', array $filters = []): self
    {
        return $this->add(Field::text($name, $label, $rules, $default, $filters));
    }

    /**
     * @param list<RuleInterface> $rules
     * @param list<Closure(string): string> $filters
     */
    public function textarea(string $name, string $label, array $rules = [], string $default = '', array $filters = []): self
    {
        return $this->add(Field::textarea($name, $label, $rules, $default, $filters));
    }

    /** @param list<RuleInterface> $rules */
    public function password(string $name, string $label, array $rules = []): self
    {
        return $this->add(Field::password($name, $label, $rules));
    }

    /** @param list<RuleInterface> $rules */
    public function hidden(string $name, string $label = '', array $rules = [], string $default = ''): self
    {
        return $this->add(Field::hidden($name, $label, $rules, $default));
    }

    /**
     * @param array<array-key, string> $choices
     * @param list<RuleInterface> $rules
     */
    public function select(string $name, string $label, array $choices, array $rules = [], string $default = ''): self
    {
        return $this->add(Field::select($name, $label, $choices, $rules, $default));
    }

    /**
     * @param array<array-key, string> $choices
     * @param list<RuleInterface> $rules
     */
    public function radios(string $name, string $label, array $choices, array $rules = [], string $default = ''): self
    {
        return $this->add(Field::radios($name, $label, $choices, $rules, $default));
    }

    /**
     * @param array<array-key, string> $choices
     * @param list<RuleInterface> $rules
     * @param list<string> $default
     */
    public function checkboxes(string $name, string $label, array $choices, array $rules = [], array $default = []): self
    {
        return $this->add(Field::checkboxes($name, $label, $choices, $rules, $default));
    }

    /** @param list<RuleInterface> $rules */
    public function checkbox(string $name, string $label, string $checkedValue = '1', array $rules = []): self
    {
        return $this->add(Field::checkbox($name, $label, $checkedValue, $rules));
    }

    /** @param list<RuleInterface> $rules */
    public function file(string $name, string $label, array $rules = []): self
    {
        return $this->add(Field::file($name, $label, $rules));
    }

    public function add(Field|CompositeField ...$fields): self
    {
        foreach ($fields as $field) {
            if (\array_key_exists($field->name, $this->fields)) {
                throw new FormConfigException("duplicate field name: {$field->name}");
            }

            $this->fields[$field->name] = $field;
        }

        return $this;
    }

    public function confirm(bool $enabled = true): self
    {
        $this->confirm = $enabled;

        return $this;
    }

    public function confirmTtl(int $seconds): self
    {
        if ($seconds < 1) {
            throw new FormConfigException('confirm ttl must be greater than 0');
        }

        $this->ttl = $seconds;

        return $this;
    }

    /** @param Closure(array<string, mixed>, Form): void $handler */
    public function onComplete(Closure $handler): self
    {
        $this->onComplete = $handler;

        return $this;
    }

    public function errorHtml(string $sprintfTemplate): self
    {
        if (!str_contains($sprintfTemplate, '%s')) {
            throw new FormConfigException('error html template must contain %s');
        }

        $this->errorHtml = $sprintfTemplate;

        return $this;
    }

    public function tempFileStore(TempFileStore $store): self
    {
        $this->store = $store;

        return $this;
    }

    /**
     * スパム対策を登録する。複数を登録でき、登録の順に検査する(どれか1つが拒否したら送信を受け付けない)。
     */
    public function spamGuard(SpamGuardInterface $guard): self
    {
        $this->spamGuards[] = $guard;

        return $this;
    }

    /** @return array<string, Field|CompositeField> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $name): Field|CompositeField
    {
        if (!\array_key_exists($name, $this->fields)) {
            throw new UnknownFieldException("unknown form field: {$name}");
        }

        return $this->fields[$name];
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->fields);
    }

    public function isConfirmEnabled(): bool
    {
        return $this->confirm;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /** @return null|Closure(array<string, mixed>, Form): void */
    public function completeHandler(): ?Closure
    {
        return $this->onComplete;
    }

    public function errorTemplate(): string
    {
        return $this->errorHtml;
    }

    public function store(): TempFileStore
    {
        if (!$this->store instanceof TempFileStore) {
            $this->store = TempFileStore::default();
        }

        return $this->store;
    }

    /** @return list<SpamGuardInterface> */
    public function spamGuards(): array
    {
        return $this->spamGuards;
    }

    public function hasFileField(): bool
    {
        foreach ($this->fields as $field) {
            if ($field instanceof Field && $field->type === FieldType::File) {
                return true;
            }
        }

        return false;
    }
}
