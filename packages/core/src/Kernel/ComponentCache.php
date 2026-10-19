<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use Throwable;

final class ComponentCache
{
    public const SCHEMA_VERSION = 2;

    public function __construct(
        private readonly string $file,
        private readonly bool $validateMtimes,
    ) {}

    public function load(): ?DiscoveryResult
    {
        if (!is_file($this->file)) {
            return null;
        }

        try {
            $payload = include $this->file;
        } catch (Throwable) {
            return null;
        }

        if (!\is_array($payload)) {
            return null;
        }

        if (!\array_key_exists('schema', $payload) || $payload['schema'] !== self::SCHEMA_VERSION) {
            return null;
        }

        if (!\array_key_exists('files', $payload) || !\array_key_exists('dirs', $payload)) {
            return null;
        }

        if (!\array_key_exists('appDir', $payload) || !\is_string($payload['appDir'])) {
            return null;
        }

        $files = $this->stringIntMap($payload['files']);
        $dirs = $this->stringIntMap($payload['dirs']);

        if ($files === null || $dirs === null) {
            return null;
        }

        if ($payload['appDir'] !== array_key_first($dirs)) {
            return null;
        }

        if ($this->validateMtimes && !$this->mtimesMatch($files, $dirs)) {
            return null;
        }

        if (!\array_key_exists('components', $payload) || !\array_key_exists('hookMethods', $payload)) {
            return null;
        }

        $componentPayloads = $this->listOfArrays($payload['components']);
        $hookMethodPayloads = $this->listOfArrays($payload['hookMethods']);

        if ($componentPayloads === null || $hookMethodPayloads === null) {
            return null;
        }

        $components = [];

        foreach ($componentPayloads as $component) {
            $class = $this->nonEmptyString($component, 'class');
            $attributeClass = $this->nonEmptyString($component, 'attributeClass');

            if ($class === null || $attributeClass === null) {
                return null;
            }

            if (
                !\array_key_exists('attributeArguments', $component)
                || !\is_array($component['attributeArguments'])
                || !\array_key_exists('file', $component)
                || !\is_string($component['file'])
                || !\array_key_exists('fileMtime', $component)
                || !\is_int($component['fileMtime'])
            ) {
                return null;
            }

            $components[] = new DiscoveredComponent(
                $class,
                $attributeClass,
                $component['attributeArguments'],
                $component['file'],
                $component['fileMtime'],
            );
        }

        $hookMethods = [];

        foreach ($hookMethodPayloads as $hookMethod) {
            $class = $this->nonEmptyString($hookMethod, 'class');

            if ($class === null) {
                return null;
            }

            if (
                !\array_key_exists('method', $hookMethod)
                || !\is_string($hookMethod['method'])
                || !\array_key_exists('hook', $hookMethod)
                || !\is_string($hookMethod['hook'])
                || !\array_key_exists('priority', $hookMethod)
                || !\is_int($hookMethod['priority'])
                || !\array_key_exists('acceptedArgs', $hookMethod)
                || !\is_int($hookMethod['acceptedArgs'])
            ) {
                return null;
            }

            $hookMethods[] = new DiscoveredHookMethod(
                $class,
                $hookMethod['method'],
                $hookMethod['hook'],
                $hookMethod['priority'],
                $hookMethod['acceptedArgs'],
            );
        }

        return new DiscoveryResult($components, $hookMethods, $files, $dirs);
    }

    public function store(DiscoveryResult $result): bool
    {
        $dir = \dirname($this->file);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $payload = [
            'schema' => self::SCHEMA_VERSION,
            'appDir' => array_key_first($result->dirs),
            'generated' => time(),
            'dirs' => $result->dirs,
            'files' => $result->files,
            'components' => array_map(
                static fn(DiscoveredComponent $component): array => [
                    'class' => $component->class,
                    'attributeClass' => $component->attributeClass,
                    'attributeArguments' => $component->attributeArguments,
                    'file' => $component->file,
                    'fileMtime' => $component->fileMtime,
                ],
                $result->components,
            ),
            'hookMethods' => array_map(
                static fn(DiscoveredHookMethod $hookMethod): array => [
                    'class' => $hookMethod->class,
                    'method' => $hookMethod->method,
                    'hook' => $hookMethod->hook,
                    'priority' => $hookMethod->priority,
                    'acceptedArgs' => $hookMethod->acceptedArgs,
                ],
                $result->hookMethods,
            ),
        ];

        $php = '<?php return ' . var_export($payload, true) . ';';
        $tmp = $this->file . '.' . uniqid('', true) . '.tmp';
        $bytes = file_put_contents($tmp, $php);

        if ($bytes !== \strlen($php)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }

            return false;
        }

        if (!rename($tmp, $this->file)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }

            return false;
        }

        if (\function_exists('opcache_invalidate')) {
            opcache_invalidate($this->file, true);
        }

        return true;
    }

    public function clear(): bool
    {
        if (!is_file($this->file)) {
            return true;
        }

        return unlink($this->file);
    }

    public function file(): string
    {
        return $this->file;
    }

    /** @return array<string, int>|null */
    private function stringIntMap(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $map = [];

        foreach ($value as $key => $entry) {
            if (!\is_string($key) || !\is_int($entry)) {
                return null;
            }

            $map[$key] = $entry;
        }

        return $map;
    }

    /** @return list<array<string, mixed>>|null */
    private function listOfArrays(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $list = [];

        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                return null;
            }

            $typedEntry = [];

            foreach ($entry as $key => $entryValue) {
                if (!\is_string($key)) {
                    return null;
                }

                $typedEntry[$key] = $entryValue;
            }

            $list[] = $typedEntry;
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return non-empty-string|null
     */
    private function nonEmptyString(array $payload, string $key): ?string
    {
        if (!\array_key_exists($key, $payload) || !\is_string($payload[$key])) {
            return null;
        }

        if ($payload[$key] === '') {
            return null;
        }

        return $payload[$key];
    }

    /**
     * @param array<string, int> $files
     * @param array<string, int> $dirs
     */
    private function mtimesMatch(array $files, array $dirs): bool
    {
        foreach ($dirs as $dir => $mtime) {
            if (!is_dir($dir) || filemtime($dir) !== $mtime) {
                return false;
            }
        }

        foreach ($files as $file => $mtime) {
            if (!is_file($file) || filemtime($file) !== $mtime) {
                return false;
            }
        }

        return true;
    }
}
