<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use TMSWP\Core\Exception\Form\FormConfigException;

final readonly class FormRequest
{
    /**
     * @param array<string, mixed> $post
     * @param array<string, array<string, mixed>> $files
     */
    public function __construct(
        public string $method,
        public array $post = [],
        public array $files = [],
    ) {}

    public static function fromGlobals(): self
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || !\is_string($_SERVER['REQUEST_METHOD'])) {
            throw new FormConfigException('REQUEST_METHOD is not available');
        }

        if (!\function_exists('wp_unslash')) {
            throw new FormConfigException('wp_unslash is not available');
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD']);
        $post = wp_unslash($_POST);

        if (!\is_array($post)) {
            throw new FormConfigException('POST payload must be an array');
        }

        return new self($method, self::stringKeyed($post), self::files());
    }

    public function isPost(): bool
    {
        return strtoupper($this->method) === 'POST';
    }

    public function post(string $key): mixed
    {
        return $this->post[$key] ?? '';
    }

    public function hasPost(string $key): bool
    {
        return \array_key_exists($key, $this->post);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (!\is_string($key)) {
                throw new FormConfigException('form payload keys must be strings');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @return array<string, array<string, mixed>> */
    private static function files(): array
    {
        $files = [];

        foreach ($_FILES as $key => $value) {
            if (!\is_string($key) || !\is_array($value)) {
                throw new FormConfigException('FILES payload must be keyed by strings');
            }

            $files[$key] = self::stringKeyed($value);
        }

        return $files;
    }
}
