<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ValueCaster;

final readonly class OptionSchemaDefinition
{
    /**
     * @param class-string $ownerClass
     */
    public function __construct(
        public string $ownerClass,
        public string $caseName,
        public string $key,
        public MetaValueType $type,
        public mixed $default,
        public bool $autoload,
    ) {}

    /**
     * @param class-string $ownerClass
     */
    public static function fromAttribute(
        string $ownerClass,
        string $caseName,
        string $key,
        OptionSchema $attribute,
    ): self {
        if ($key === '') {
            throw new InvalidSchemaException("option key cannot be empty: {$ownerClass}::{$caseName}");
        }

        if ($attribute->default !== null && !ValueCaster::accepts($attribute->type, $attribute->default)) {
            throw new InvalidSchemaException("option default type mismatch: {$ownerClass}::{$caseName}");
        }

        return new self($ownerClass, $caseName, $key, $attribute->type, $attribute->default, $attribute->autoload);
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
            'caseName' => $this->caseName,
            'key' => $this->key,
            'type' => $this->type->value,
            'default' => $this->default,
            'autoload' => $this->autoload,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['ownerClass', 'caseName', 'key', 'type', 'default', 'autoload'] as $field) {
            if (!\array_key_exists($field, $data)) {
                throw new InvalidSchemaException("option schema cache payload is missing {$field}");
            }
        }

        if (!\is_string($data['ownerClass']) || !class_exists($data['ownerClass'])) {
            throw new InvalidSchemaException('invalid option schema owner class in cache payload');
        }

        if (
            !\is_string($data['caseName'])
            || !\is_string($data['key'])
            || !\is_string($data['type'])
            || !\is_bool($data['autoload'])
        ) {
            throw new InvalidSchemaException('invalid option schema cache payload');
        }

        return new self(
            $data['ownerClass'],
            $data['caseName'],
            $data['key'],
            MetaValueType::from($data['type']),
            $data['default'],
            $data['autoload'],
        );
    }
}
