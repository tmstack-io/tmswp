<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use BackedEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Support\Str;

final class SimpleLogger implements LoggerInterface
{
    use LoggerTrait;

    private readonly string $dir;

    public function __construct(
        ?string $dir = null,
        private readonly string $fileNameFormat = '{date}.log',
        private readonly string $dateFormat = 'Y-m-d H:i:s',
        private readonly int $maxFiles = 30,
    ) {
        if ($maxFiles < 0) {
            throw new InvalidArgumentException('maxFiles must not be negative');
        }

        if ($dir === null) {
            if (!\defined('WP_CONTENT_DIR')) {
                throw new InvalidArgumentException('SimpleLogger requires WP_CONTENT_DIR when dir is omitted');
            }

            $dir = WP_CONTENT_DIR . '/tmswp-logs';
        }

        $this->dir = rtrim($dir, '/\\');
    }

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!\is_string($level) || !$this->isKnownLevel($level)) {
            throw new \Psr\Log\InvalidArgumentException('unknown log level: ' . get_debug_type($level));
        }

        $this->ensureDirectory($this->dir);
        $this->ensureProtectionFiles();

        $relative = Str::replace($this->fileNameFormat, [
            'date' => wp_date('Ymd'),
            'level' => strtolower($level),
        ]);

        if ($this->containsParentSegment($relative)) {
            return;
        }

        $path = $this->dir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        $isNewFile = !is_file($path);
        $this->ensureDirectory(\dirname($path));
        error_log($this->formatLine($level, $message, $context), 3, $path);

        if ($isNewFile && $this->maxFiles > 0) {
            $this->rotate(\dirname($path));
        }
    }

    private function isKnownLevel(mixed $level): bool
    {
        return \in_array($level, [
            LogLevel::DEBUG,
            LogLevel::INFO,
            LogLevel::NOTICE,
            LogLevel::WARNING,
            LogLevel::ERROR,
            LogLevel::CRITICAL,
            LogLevel::ALERT,
            LogLevel::EMERGENCY,
        ], true);
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        wp_mkdir_p($dir);
    }

    private function ensureProtectionFiles(): void
    {
        $htaccess = $this->dir . DIRECTORY_SEPARATOR . '.htaccess';
        $index = $this->dir . DIRECTORY_SEPARATOR . 'index.php';

        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "Require all denied\n");
        }

        if (!is_file($index)) {
            file_put_contents($index, '<?php // Silence is golden.');
        }
    }

    /** @param array<mixed> $context */
    private function formatLine(string $level, string|Stringable $message, array $context): string
    {
        $line = wp_date($this->dateFormat) . ' [' . strtoupper($level) . '] ' . $this->interpolate((string) $message, $context);
        $contextJson = $this->contextJson($context);

        if ($contextJson !== '') {
            $line .= ' ' . $contextJson;
        }

        $line .= "\n";

        if (($context['exception'] ?? null) instanceof Throwable) {
            $exception = $context['exception'];
            $line .= $exception::class . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine() . "\n";
            $line .= $exception->getTraceAsString() . "\n";
        }

        return $line;
    }

    /** @param array<mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        return preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', static function (array $matches) use ($context): string {
            $key = $matches[1];

            if (!\array_key_exists($key, $context)) {
                return $matches[0];
            }

            $value = $context[$key];

            if ($value === null || \is_scalar($value) || $value instanceof Stringable || $value instanceof BackedEnum) {
                return Str::stringify($value);
            }

            return $matches[0];
        }, $message) ?? $message;
    }

    /** @param array<mixed> $context */
    private function contextJson(array $context): string
    {
        unset($context['exception']);

        if ($context === []) {
            return '';
        }

        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return \is_string($json) ? $json : '';
    }

    private function containsParentSegment(string $path): bool
    {
        $parts = preg_split('#[\\\\/]+#', $path);

        if ($parts === false) {
            return false;
        }

        return \in_array('..', $parts, true);
    }

    private function rotate(string $dir): void
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.log');

        if (!\is_array($files) || \count($files) <= $this->maxFiles) {
            return;
        }

        usort($files, static function (string $a, string $b): int {
            $aMtime = filemtime($a);
            $bMtime = filemtime($b);

            return ($aMtime !== false ? $aMtime : 0) <=> ($bMtime !== false ? $bMtime : 0);
        });

        foreach (\array_slice($files, 0, \count($files) - $this->maxFiles) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
