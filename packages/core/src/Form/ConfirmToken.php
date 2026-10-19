<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use JsonException;
use TMSWP\Core\Exception\Form\FormConfigException;

final class ConfirmToken
{
    public const VERSION = 1;

    private function __construct() {}

    /** @param array<string, string|list<string>|array{__tmsfile: string}> $values */
    public static function issue(string $formId, array $values, ?string $secret = null, ?int $now = null): string
    {
        $payload = [
            'ver' => self::VERSION,
            'f' => $formId,
            'j' => bin2hex(random_bytes(16)),
            't' => $now ?? time(),
            'v' => $values,
        ];
        $body = self::base64UrlEncode(self::canonicalJson($payload));
        $sig = hash_hmac('sha256', $body, self::secret($secret));

        return $body . '.' . $sig;
    }

    public static function verify(string $formId, string $token, int $ttl = 1800, ?string $secret = null, ?int $now = null): ConfirmTokenResult
    {
        $parts = explode('.', $token, 2);

        if (\count($parts) !== 2 || preg_match('/\A[A-Za-z0-9_-]+\z/', $parts[0]) !== 1 || preg_match('/\A[a-f0-9]{64}\z/', $parts[1]) !== 1) {
            return new ConfirmTokenResult(ConfirmTokenStatus::Invalid);
        }

        [$body, $sig] = $parts;
        $expected = hash_hmac('sha256', $body, self::secret($secret));

        if (!hash_equals($expected, $sig)) {
            return new ConfirmTokenResult(ConfirmTokenStatus::Invalid);
        }

        try {
            $json = self::base64UrlDecode($body);
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new ConfirmTokenResult(ConfirmTokenStatus::Invalid);
        }

        if (!\is_array($payload) || ($payload['ver'] ?? null) !== self::VERSION || ($payload['f'] ?? null) !== $formId) {
            return new ConfirmTokenResult(ConfirmTokenStatus::Invalid);
        }

        if (!\is_string($payload['j'] ?? null) || !\is_int($payload['t'] ?? null) || !\is_array($payload['v'] ?? null)) {
            return new ConfirmTokenResult(ConfirmTokenStatus::Invalid);
        }

        $values = self::values($payload['v']);

        if ($values === null) {
            return new ConfirmTokenResult(ConfirmTokenStatus::Invalid);
        }

        $status = ($payload['t'] + $ttl) < ($now ?? time()) ? ConfirmTokenStatus::Expired : ConfirmTokenStatus::Valid;

        return new ConfirmTokenResult($status, $values, $payload['j'], $payload['t']);
    }

    /** @param array<array-key, mixed> $payload */
    public static function canonicalJson(array $payload): string
    {
        return json_encode(self::sortRecursive($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sortRecursive(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => \is_array($item) ? self::sortRecursive($item) : $item, $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::sortRecursive($item);
            }
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return null|array<string, string|list<string>|array{__tmsfile: string}>
     */
    private static function values(array $values): ?array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (!\is_string($key)) {
                return null;
            }

            if (\is_string($value)) {
                $normalized[$key] = $value;
                continue;
            }

            if (\is_array($value) && array_is_list($value)) {
                $items = [];

                foreach ($value as $item) {
                    if (!\is_string($item)) {
                        return null;
                    }

                    $items[] = $item;
                }

                $normalized[$key] = $items;
                continue;
            }

            if (\is_array($value) && \array_key_exists('__tmsfile', $value) && \is_string($value['__tmsfile'])) {
                $normalized[$key] = ['__tmsfile' => $value['__tmsfile']];
                continue;
            }

            return null;
        }

        return $normalized;
    }

    private static function secret(?string $secret): string
    {
        if ($secret !== null) {
            return $secret;
        }

        if (\function_exists('wp_salt')) {
            return wp_salt('auth');
        }

        throw new FormConfigException('ConfirmToken requires a secret outside WordPress');
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $base64 = strtr($value, '-_', '+/');
        $padding = \strlen($base64) % 4;

        if ($padding !== 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($base64, true);

        if (!\is_string($decoded)) {
            throw new JsonException('invalid base64url');
        }

        return $decoded;
    }
}
