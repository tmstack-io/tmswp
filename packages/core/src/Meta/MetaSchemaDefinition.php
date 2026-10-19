<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Exception\InvalidSchemaException;

final readonly class MetaSchemaDefinition
{
    /**
     * @param class-string $ownerClass
     * @param bool|array<string, mixed> $showInRest
     * @param string|array{class-string, string}|null $sanitize
     */
    public function __construct(
        public string $ownerClass,
        public string $constantName,
        public string $key,
        public MetaValueType $type,
        public mixed $default,
        public bool $single,
        public bool|array $showInRest,
        public string $description,
        public string|array|null $sanitize,
    ) {}

    /**
     * @param class-string $ownerClass
     */
    public static function fromAttribute(
        string $ownerClass,
        string $constantName,
        string $key,
        MetaSchema $attribute,
    ): self {
        if ($key === '') {
            throw new InvalidSchemaException("meta key cannot be empty: {$ownerClass}::{$constantName}");
        }

        if (!$attribute->single && $attribute->default !== null) {
            throw new InvalidSchemaException("single=false meta cannot declare default: {$ownerClass}::{$constantName}");
        }

        if ($attribute->default !== null && !ValueCaster::accepts($attribute->type, $attribute->default)) {
            throw new InvalidSchemaException("meta default type mismatch: {$ownerClass}::{$constantName}");
        }

        self::assertSanitizeShape($attribute->sanitize, $ownerClass, $constantName);

        return new self(
            $ownerClass,
            $constantName,
            $key,
            $attribute->type,
            $attribute->default,
            $attribute->single,
            $attribute->showInRest,
            $attribute->description,
            $attribute->sanitize,
        );
    }

    public function hasDefault(): bool
    {
        return $this->default !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ownerClass' => $this->ownerClass,
            'constantName' => $this->constantName,
            'key' => $this->key,
            'type' => $this->type->value,
            'default' => $this->default,
            'single' => $this->single,
            'showInRest' => $this->showInRest,
            'description' => $this->description,
            'sanitize' => $this->sanitize,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['ownerClass', 'constantName', 'key', 'type', 'single', 'showInRest', 'description', 'sanitize'] as $field) {
            if (!\array_key_exists($field, $data)) {
                throw new InvalidSchemaException("meta schema cache payload is missing {$field}");
            }
        }

        if (!\array_key_exists('default', $data)) {
            throw new InvalidSchemaException('meta schema cache payload is missing default');
        }

        if (!\is_string($data['ownerClass']) || !class_exists($data['ownerClass'])) {
            throw new InvalidSchemaException('invalid meta schema owner class in cache payload');
        }

        if (
            !\is_string($data['constantName'])
            || !\is_string($data['key'])
            || !\is_string($data['type'])
            || !\is_bool($data['single'])
            || !\is_string($data['description'])
        ) {
            throw new InvalidSchemaException('invalid meta schema cache payload');
        }

        $showInRest = self::normalizeShowInRest($data['showInRest']);
        $sanitize = self::normalizeSanitize($data['sanitize'], $data['ownerClass'], $data['constantName']);

        return new self(
            $data['ownerClass'],
            $data['constantName'],
            $data['key'],
            MetaValueType::from($data['type']),
            $data['default'],
            $data['single'],
            $showInRest,
            $data['description'],
            $sanitize,
        );
    }

    private static function assertSanitizeShape(mixed $sanitize, string $ownerClass, string $constantName): void
    {
        self::normalizeSanitize($sanitize, $ownerClass, $constantName);
    }

    /** @return bool|array<string, mixed> */
    private static function normalizeShowInRest(mixed $showInRest): bool|array
    {
        if (\is_bool($showInRest)) {
            return $showInRest;
        }

        if (!\is_array($showInRest)) {
            throw new InvalidSchemaException('invalid showInRest value in meta schema cache payload');
        }

        $normalized = [];

        foreach ($showInRest as $key => $value) {
            if (!\is_string($key)) {
                throw new InvalidSchemaException('showInRest array keys must be strings in meta schema cache payload');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @return string|array{class-string, string}|null */
    private static function normalizeSanitize(mixed $sanitize, string $ownerClass, string $constantName): string|array|null
    {
        if ($sanitize === null) {
            return null;
        }

        if (\is_string($sanitize) && $sanitize !== '') {
            return $sanitize;
        }

        if (
            \is_array($sanitize)
            && \array_key_exists(0, $sanitize)
            && \array_key_exists(1, $sanitize)
            && \count($sanitize) === 2
            && \is_string($sanitize[0])
            && \is_string($sanitize[1])
            && $sanitize[0] !== ''
            && $sanitize[1] !== ''
        ) {
            if (!class_exists($sanitize[0])) {
                throw new InvalidSchemaException("sanitize callback class does not exist: {$ownerClass}::{$constantName}");
            }

            return [$sanitize[0], $sanitize[1]];
        }

        throw new InvalidSchemaException("invalid sanitize callback declaration: {$ownerClass}::{$constantName}");
    }
}
