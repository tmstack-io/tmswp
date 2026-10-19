<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!class_exists('wpdb', false)) {
    abstract class wpdb
    {
        public string $prefix = 'wp_';

        public string $options = 'wp_options';

        public int $insert_id = 0;

        public int $rows_affected = 0;

        public string $last_error = '';

        /** @var list<array<string, mixed>> */
        public array $insertCalls = [];

        /** @var list<array<string, mixed>> */
        public array $updateCalls = [];

        /** @var list<array<string, mixed>> */
        public array $deleteCalls = [];

        /** @var list<string> */
        public array $queries = [];

        /** @var list<list<array<string, mixed>>> */
        public array $resultSets = [];

        /** @var list<array<string, mixed>|null> */
        public array $rowResults = [];

        /** @var list<mixed> */
        public array $varResults = [];

        /** @var list<list<mixed>> */
        public array $colResults = [];

        /** @var array<string, string> */
        private array $claimed = [];

        private string $lastQuery = '';

        /** @var list<mixed> */
        private array $lastArgs = [];

        public function __construct(mixed ...$args) {}

        public function prepare($query, ...$args): string
        {
            if (!is_string($query)) {
                throw new InvalidArgumentException('query must be string');
            }

            if (count($args) === 1 && is_array($args[0])) {
                $args = array_values($args[0]);
            }

            $this->lastQuery = $query;
            $this->lastArgs = $args;
            $index = 0;

            return (string) preg_replace_callback(
                '/(?<!%)%(?:\d+\$)?[dfsi]/',
                function (array $matches) use (&$index, $args): string {
                    $placeholder = $matches[0];
                    $value = $args[$index];
                    ++$index;

                    return match ($placeholder[strlen($placeholder) - 1]) {
                        'd' => (string) (int) $value,
                        'f' => (string) (float) $value,
                        'i' => '`' . str_replace('`', '``', (string) $value) . '`',
                        default => "'" . str_replace("'", "''", (string) $value) . "'",
                    };
                },
                $query,
            );
        }

        public function get_results($query = null, $output = \OBJECT)
        {
            if (!is_string($query)) {
                throw new InvalidArgumentException('query must be string');
            }

            $this->queries[] = $query;

            return array_shift($this->resultSets) ?? [];
        }

        public function get_row($query = null, $output = \OBJECT, $y = 0)
        {
            if (!is_string($query)) {
                throw new InvalidArgumentException('query must be string');
            }

            $this->queries[] = $query;

            return array_shift($this->rowResults) ?? null;
        }

        public function get_var($query = null, $x = 0, $y = 0): mixed
        {
            if (!is_string($query)) {
                throw new InvalidArgumentException('query must be string');
            }

            $this->queries[] = $query;

            return array_shift($this->varResults);
        }

        public function get_col($query = null, $x = 0): array
        {
            if (!is_string($query)) {
                throw new InvalidArgumentException('query must be string');
            }

            $this->queries[] = $query;

            return array_shift($this->colResults) ?? [];
        }

        public function query($query): int|bool
        {
            if (!is_string($query)) {
                throw new InvalidArgumentException('query must be string');
            }

            $this->queries[] = $query;

            if (str_starts_with($this->lastQuery, 'INSERT IGNORE')) {
                $jti = (string) $this->lastArgs[1];

                if (array_key_exists($jti, $this->claimed)) {
                    $this->rows_affected = 0;

                    return 0;
                }

                $this->claimed[$jti] = (string) $this->lastArgs[2];
                $this->rows_affected = 1;

                return 1;
            }

            if (str_starts_with($this->lastQuery, 'DELETE FROM')) {
                return 0;
            }

            $this->rows_affected = 1;

            return 1;
        }

        /**
         * @param array<string, mixed> $data
         * @param list<string>|string|null $format
         */
        public function insert($table, $data, $format = null): int|false
        {
            if (!is_string($table) || !is_array($data)) {
                throw new InvalidArgumentException('invalid insert arguments');
            }

            $this->insertCalls[] = ['table' => $table, 'data' => $data, 'format' => $format];
            ++$this->insert_id;
            $this->rows_affected = 1;

            return 1;
        }

        /**
         * @param array<string, mixed> $data
         * @param array<string, mixed> $where
         * @param list<string>|string|null $format
         * @param list<string>|string|null $whereFormat
         */
        public function update($table, $data, $where, $format = null, $whereFormat = null): int|false
        {
            if (!is_string($table) || !is_array($data) || !is_array($where)) {
                throw new InvalidArgumentException('invalid update arguments');
            }

            $this->updateCalls[] = ['table' => $table, 'data' => $data, 'where' => $where, 'format' => $format, 'where_format' => $whereFormat];
            $this->rows_affected = 1;

            return 1;
        }

        /**
         * @param array<string, mixed> $where
         * @param list<string>|string|null $whereFormat
         */
        public function delete($table, $where, $whereFormat = null): int|false
        {
            if (!is_string($table) || !is_array($where)) {
                throw new InvalidArgumentException('invalid delete arguments');
            }

            $this->deleteCalls[] = ['table' => $table, 'where' => $where, 'where_format' => $whereFormat];
            $this->rows_affected = 1;

            return 1;
        }

        public function esc_like($text): string
        {
            if (!is_string($text)) {
                throw new InvalidArgumentException('text must be string');
            }

            return addcslashes($text, '_%\\');
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
    }
}
