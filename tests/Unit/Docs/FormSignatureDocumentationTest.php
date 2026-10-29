<?php

declare(strict_types=1);

namespace TMSWP\Tests\Unit\Docs;

use PhpToken;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\Form\TempFileException;
use TMSWP\Core\Exception\Form\UnknownFieldException;
use TMSWP\Testing\UnitTestCase;
use UnitEnum;

/**
 * docs/05の受け入れ基準「本文書の全クラス・enum・例外と全メソッドがシグネチャ通りに存在する」を、docs/05のクラス仕様の
 * コードブロックと実装のリフレクションの照合で確かめる。設計書はmonorepoにだけあり、分割した読み取り専用のリポジトリ
 * (tmswp/core)には無いため、packages/core/testsではなくmonorepoのルートのテストに置く。docs/05が無ければ、読み込みの
 * アサーションで失敗する(スキップしない)。
 *
 * 文書と実装の書き方の違いは、次の規則で同じとみなす。
 * - 型の宣言の`self`は、そのクラスの名前と同じとみなす(実装は`: Field`等の明示の名前で書く)。
 * - 親は、文書の親を継承していればよい(例外の`extends \LogicException`等。間にFWの基底を挟んでよい)。
 * - 同梱ルール一覧の表の13種は、「RuleInterfaceを実装するfinal class」として照合する(基底のRuleを継承するかは
 *   ルールによる。Callback・RetypeはRuleInterfaceを直接実装する)。
 * - 照合は文書から実装への向きだけで、文書に無い実装のメソッドは照合しない。
 * - 引数の既定値は、文書の式(bool・null・整数・小数・文字列・配列・定数とenumのケースの参照・`new`)を解釈した値と、
 *   Reflectionの実装の既定値を、同じ表記にして比べる。`self::`はそのクラスの名前とみなす。`new`は生成するクラスだけを
 *   比べ、コンストラクタの引数は比べない。解釈できない式は、文書の誤りとして失敗にする。
 * - 「app側カスタムルールの規約」の例(UniqueEmail)と、表の「private(下記ファクトリ)」の引数(RequiredIfは可視性だけを
 *   照合する)は照合しない。
 *
 * @phpstan-type DocParam array{name: string, type: string|null, default: string|null, variadic: bool, promoted: string}
 * @phpstan-type DocMethod array{modifiers: list<string>, params: list<DocParam>|null, return: string|null}
 * @phpstan-type DocType array{kind: string, modifiers: list<string>, extends: string|null, implements: list<string>, backing: string|null, cases: array<string, int|string|null>, constants: array<string, int|string>, methods: array<string, DocMethod>}
 * @phpstan-type NameContext array{namespace: string, uses: array<string, string>}
 */
final class FormSignatureDocumentationTest extends UnitTestCase
{
    /** 型の宣言で、名前の解決をしない型の名前 */
    private const array BUILTIN_TYPES = [
        'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'parent',
        'self', 'static', 'string', 'true', 'void',
    ];

    /**
     * docs/05の「クラス仕様」のPHPのコードブロックと「同梱ルール一覧」の表のコンストラクタの列から、型ごとの宣言を
     * 取り出し、実装とリフレクションで照合する(受け入れ基準「本文書の全クラス・enum・例外と全メソッドがシグネチャ通りに
     * 存在する」)。照合するのは、型の種類・final等の修飾子・親(継承していればよい)・interface・enumのbackingの型と
     * ケース・公開の定数の値・メソッド(可視性・static・final・abstract、引数の名前・型・既定値の有無・可変長・publicの
     * プロモーション、戻り値の型)である。文書から実装への向きだけを照合する(文書に無い実装のメソッドは照合しない)。
     *
     * 「app側カスタムルールの規約」の例(UniqueEmail)はFWの型ではないため除く。
     */
    public function testDocumentedSignaturesMatchTheImplementation(): void
    {
        $types = self::documentedTypes(self::read(\dirname(__DIR__, 3) . '/docs/05-form.md'));
        // TokenSignerは @internalで、クラス仕様のコードブロックを持たない(§トークン形式と署名手順が手順を定める)
        $expected = array_map(
            static fn(string $relative): string => "TMSWP\\Core\\Form\\{$relative}",
            array_values(array_diff(array_keys(self::formTypes()), ['TokenSigner'])),
        );
        $expected = [...$expected, FormConfigException::class, TempFileException::class, UnknownFieldException::class];
        sort($expected);
        $documented = array_keys($types);
        sort($documented);

        self::assertSame($expected, $documented);

        $mismatches = [];

        foreach ($types as $class => $type) {
            array_push($mismatches, ...self::signatureMismatches($class, $type));
        }

        self::assertSame([], $mismatches);
    }

    /**
     * src/FormのPHPファイルが宣言する型(TMSWP\Core\Form\ からの相対名 => 型とファイル)。名前の順。
     *
     * @return array<string, array{class: class-string, file: string}>
     */
    private static function formTypes(): array
    {
        $root = \dirname(__DIR__, 3) . '/packages/core/src/Form';
        $paths = glob($root . '/{,*/}*.php', GLOB_BRACE);
        self::assertIsArray($paths);
        $types = [];

        foreach ($paths as $path) {
            $relative = str_replace('/', '\\', substr($path, \strlen($root) + 1, -4));
            $class = 'TMSWP\\Core\\Form\\' . $relative;

            if (!class_exists($class) && !interface_exists($class)) {
                self::fail("{$path} does not declare {$class}");
            }

            $types[$relative] = ['class' => $class, 'file' => $path];
        }

        ksort($types);

        return $types;
    }

    /** @return list<PhpToken> 空白・コメントを除いたトークン */
    private static function tokensOf(string $source): array
    {
        return array_values(array_filter(
            PhpToken::tokenize($source),
            static fn(PhpToken $token): bool => !$token->isIgnorable(),
        ));
    }

    /**
     * docs/05の「## クラス仕様」の節(「## 利用例」の前まで)から、型ごとの宣言を取り出す。PHPのコードブロックは、
     * namespaceを持たなければ前のブロックのnamespaceとuseで名前を解決する(同梱ルール一覧のRequiredIf)。
     *
     * @return array<string, DocType> 完全修飾のクラス名 => 宣言
     */
    private static function documentedTypes(string $markdown): array
    {
        $start = strpos($markdown, "\n## クラス仕様\n");
        $end = strpos($markdown, "\n## 利用例");
        self::assertIsInt($start, 'docs/05 has no section "クラス仕様"');
        self::assertIsInt($end, 'docs/05 has no section "利用例"');
        $types = [];
        $context = ['namespace' => '', 'uses' => []];
        $heading = '';
        $block = null;

        foreach (explode("\n", substr($markdown, $start, $end - $start)) as $line) {
            if ($block !== null) {
                if ($line !== '```') {
                    $block .= $line . "\n";
                    continue;
                }

                // app側の例はFWの型ではない
                if (!str_contains($heading, 'app側カスタムルールの規約')) {
                    $types = self::mergeTypes($types, self::parseDeclarations($block, $context));
                }

                $block = null;
            } elseif (str_starts_with($line, '#')) {
                $heading = $line;
            } elseif ($line === '```php') {
                $block = '';
            } elseif (str_starts_with($heading, '#### 同梱ルール一覧') && str_starts_with($line, '| `')) {
                $types = self::mergeTypes($types, self::bundledRule($line));
            }
        }

        self::assertNull($block, 'docs/05 has an unclosed code block');

        return $types;
    }

    /**
     * 同梱ルール一覧の表の1行(| `クラス` | `(コンストラクタの引数)` + `static 名前(...): 型` | ...)を宣言にする。
     * 表の前の「すべて`TMSWP\Core\Form\Rule\`配下の`final class`」から、RuleInterfaceを実装するfinal classとする
     * (基底のRuleを継承するかはルールによる。Callback・RetypeはRuleInterfaceを直接実装する。§RuleInterfaceと基底Rule)。
     * 「private(下記ファクトリ)」は、コンストラクタの可視性だけを照合する。
     *
     * @return array<string, DocType>
     */
    private static function bundledRule(string $row): array
    {
        $cells = preg_split('/(?<!\\\\)\|/', $row);
        self::assertIsArray($cells);
        $class = trim($cells[1] ?? '', ' `');
        $constructor = str_replace('\\|', '|', trim($cells[2] ?? ''));
        $private = str_starts_with($constructor, 'private');
        $code = "final class {$class} implements RuleInterface\n{\n";

        if (!$private) {
            self::assertSame(1 + substr_count($constructor, '+'), preg_match_all('/`([^`]+)`/', $constructor, $matches), "constructor of {$class}: {$constructor}");

            foreach ($matches[1] as $index => $piece) {
                $code .= $index === 0 ? "public function __construct{$piece} {}\n" : 'public ' . preg_replace('/\A((?:static\s+)?)/', '$1function ', $piece) . ";\n";
            }
        }

        $context = ['namespace' => 'TMSWP\Core\Form\Rule', 'uses' => []];
        $types = self::parseDeclarations($code . "}\n", $context);

        $type = $types["TMSWP\\Core\\Form\\Rule\\{$class}"] ?? self::fail("the row of {$class} was not parsed");

        if ($private) {
            $type['methods']['__construct'] = ['modifiers' => ['private'], 'params' => null, 'return' => null];
            $types["TMSWP\\Core\\Form\\Rule\\{$class}"] = $type;
        }

        return $types;
    }

    /**
     * 同じ型の宣言は、メソッドを合わせる(RequiredIfは表がコンストラクタを、コードブロックがファクトリを定める)。
     *
     * @param array<string, DocType> $types
     * @param array<string, DocType> $more
     *
     * @return array<string, DocType>
     */
    private static function mergeTypes(array $types, array $more): array
    {
        foreach ($more as $class => $type) {
            if (isset($types[$class])) {
                $type['methods'] += $types[$class]['methods'];
            }

            $types[$class] = $type;
        }

        return $types;
    }

    /**
     * PHPの宣言の断片(本体を`;`で略したメソッドを含む)のトークンから、型ごとの宣言を取り出す。
     *
     * @param NameContext $context 名前の解決に使うnamespaceとuse。断片のnamespace・useで更新する
     *
     * @return array<string, DocType>
     */
    private static function parseDeclarations(string $code, array &$context): array
    {
        $tokens = self::tokensOf("<?php\n" . $code);
        $types = [];
        $modifiers = [];

        for ($i = 0, $count = \count($tokens); $i < $count; ++$i) {
            $token = self::at($tokens, $i);

            if ($token->is(T_NAMESPACE)) {
                $context = ['namespace' => self::at($tokens, $i + 1)->text, 'uses' => []];
                $i = self::indexOf($tokens, $i, ';');
            } elseif ($token->is(T_USE)) {
                $name = ltrim(self::at($tokens, $i + 1)->text, '\\');
                $alias = self::at($tokens, $i + 2)->is(T_AS) ? self::at($tokens, $i + 3)->text : basename(str_replace('\\', '/', $name));
                $context['uses'][strtolower($alias)] = $name;
                $i = self::indexOf($tokens, $i, ';');
            } elseif ($token->is([T_FINAL, T_ABSTRACT, T_READONLY])) {
                $modifiers[] = strtolower($token->text);
            } elseif ($token->is([T_CLASS, T_INTERFACE, T_ENUM])) {
                $i = self::parseType($tokens, $i, $modifiers, $context, $types);
                $modifiers = [];
            }
        }

        return $types;
    }

    /**
     * $tokens[$i](class・interface・enum)から始まる型の宣言を読み、$typesに足す。
     *
     * @param list<PhpToken> $tokens
     * @param list<string> $modifiers 型の修飾子(final・abstract・readonly)
     * @param NameContext $context
     * @param array<string, DocType> $types
     *
     * @return int 型の本体を閉じる`}`の位置
     */
    private static function parseType(array $tokens, int $i, array $modifiers, array $context, array &$types): int
    {
        $class = $context['namespace'] . '\\' . self::at($tokens, $i + 1)->text;
        sort($modifiers);
        $type = [
            'kind' => strtolower(self::at($tokens, $i)->text),
            'modifiers' => $modifiers,
            'extends' => null,
            'implements' => [],
            'backing' => null,
            'cases' => [],
            'constants' => [],
            'methods' => [],
        ];
        $j = $i + 2;

        if (self::at($tokens, $j)->text === ':') {
            $type['backing'] = self::at($tokens, $j + 1)->text;
            $j += 2;
        }

        if (self::at($tokens, $j)->is(T_EXTENDS)) {
            $type['extends'] = self::resolveName(self::at($tokens, $j + 1)->text, $context);
            $j += 2;
        }

        if (self::at($tokens, $j)->is(T_IMPLEMENTS)) {
            for (++$j; self::at($tokens, $j)->text !== '{'; ++$j) {
                if (self::at($tokens, $j)->text !== ',') {
                    $type['implements'][] = self::resolveName(self::at($tokens, $j)->text, $context);
                }
            }
        }

        self::assertSame('{', self::at($tokens, $j)->text, "unexpected token in the declaration of {$class}");
        $memberModifiers = [];

        for (++$j; ($token = self::at($tokens, $j))->text !== '}'; ++$j) {
            if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY])) {
                $memberModifiers[] = strtolower($token->text);
                continue;
            }

            if ($token->is(T_CASE)) {
                $type['cases'][self::at($tokens, $j + 1)->text] = self::at($tokens, $j + 2)->text === '=' ? self::literal(self::at($tokens, $j + 3)) : null;
                $j = self::indexOf($tokens, $j, ';');
            } elseif ($token->is(T_CONST)) {
                // 型付きの定数(const string NAME = ...)は、名前が1つ後ろにある
                $nameIndex = self::at($tokens, $j + 2)->text === '=' ? $j + 1 : $j + 2;

                if (array_intersect(['private', 'protected'], $memberModifiers) === []) {
                    $type['constants'][self::at($tokens, $nameIndex)->text] = self::literal(self::at($tokens, $nameIndex + 2));
                }

                $j = self::indexOf($tokens, $j, ';');
            } elseif ($token->is(T_FUNCTION)) {
                [$name, $method, $j] = self::parseMethod($tokens, $j, $memberModifiers, $context, \in_array('readonly', $modifiers, true));
                $type['methods'][$name] = $method;
            } elseif ($token->is(T_VARIABLE)) {
                // プロパティ(照合しない)
                $j = self::indexOf($tokens, $j, ';');
            } else {
                // プロパティの型等。修飾子は次の宣言まで持ち越す
                continue;
            }

            $memberModifiers = [];
        }

        $types[$class] = $type;

        return $j;
    }

    /**
     * $tokens[$j](function)から始まるメソッドの宣言を読む。本体は`;`か`{...}`。
     *
     * @param list<PhpToken> $tokens
     * @param list<string> $modifiers
     * @param NameContext $context
     *
     * @return array{string, DocMethod, int} 名前、宣言、宣言の最後のトークンの位置
     */
    private static function parseMethod(array $tokens, int $j, array $modifiers, array $context, bool $readonlyClass): array
    {
        $name = self::at($tokens, $j + 1)->text;
        $open = $j + 2;
        self::assertSame('(', self::at($tokens, $open)->text, "unexpected token after function {$name}");
        $close = self::matching($tokens, $open, '(', ')');
        $params = [];
        $current = [];
        $depth = 0;

        for ($k = $open + 1; $k < $close; ++$k) {
            $token = self::at($tokens, $k);
            $depth += match ($token->text) {
                '(', '[' => 1,
                ')', ']' => -1,
                default => 0,
            };

            if ($depth === 0 && $token->text === ',') {
                $params[] = self::parameter($current, $context, $readonlyClass);
                $current = [];
                continue;
            }

            $current[] = $token;
        }

        // 末尾のカンマの後は空
        if ($current !== []) {
            $params[] = self::parameter($current, $context, $readonlyClass);
        }

        $k = $close + 1;
        $return = null;

        if (self::at($tokens, $k)->text === ':') {
            $typeText = '';

            for (++$k; !\in_array(self::at($tokens, $k)->text, [';', '{'], true); ++$k) {
                $typeText .= self::at($tokens, $k)->text;
            }

            $return = self::documentedType($typeText, $context);
        }

        $end = self::at($tokens, $k)->text === '{' ? self::matching($tokens, $k, '{', '}') : $k;
        sort($modifiers);

        return [$name, ['modifiers' => $modifiers, 'params' => $params, 'return' => $return], $end];
    }

    /**
     * 引数1つのトークンを読む。
     *
     * @param list<PhpToken> $tokens
     * @param NameContext $context
     *
     * @return DocParam
     */
    private static function parameter(array $tokens, array $context, bool $readonlyClass): array
    {
        $promotion = [];
        $typeText = '';
        $variadic = false;
        $name = null;
        $defaultTokens = null;

        foreach ($tokens as $token) {
            if ($name !== null) {
                if ($defaultTokens !== null) {
                    $defaultTokens[] = $token;
                } elseif ($token->text === '=') {
                    $defaultTokens = [];
                }
            } elseif ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY])) {
                $promotion[] = strtolower($token->text);
            } elseif ($token->is(T_ELLIPSIS)) {
                $variadic = true;
            } elseif ($token->is(T_VARIABLE)) {
                $name = substr($token->text, 1);
            } else {
                $typeText .= $token->text;
            }
        }

        if ($name === null) {
            self::fail('a parameter without a name: ' . implode(' ', array_map(static fn(PhpToken $token): string => $token->text, $tokens)));
        }

        $promoted = '';

        if (\in_array('public', $promotion, true)) {
            $promoted = $readonlyClass || \in_array('readonly', $promotion, true) ? 'public readonly' : 'public';
        }

        $default = null;

        if ($defaultTokens !== null) {
            $position = 0;
            $default = self::documentedDefault($defaultTokens, $position, $context);
            self::assertSame(\count($defaultTokens), $position, "unsupported default of \${$name}: " . implode(' ', array_map(static fn(PhpToken $token): string => $token->text, $defaultTokens)));
        }

        return ['name' => $name, 'type' => self::documentedType($typeText, $context), 'default' => $default, 'variadic' => $variadic, 'promoted' => $promoted];
    }

    /**
     * 文書の既定値の式を、$tokens[$position]から1つ読み、canonicalValue()と同じ表記にする。$positionは読んだ後の位置になる。
     *
     * @param list<PhpToken> $tokens
     * @param NameContext $context
     */
    private static function documentedDefault(array $tokens, int &$position, array $context): string
    {
        $token = self::at($tokens, $position);
        ++$position;
        $lower = strtolower($token->text);

        if ($token->is(T_STRING) && \in_array($lower, ['true', 'false', 'null'], true)) {
            return $lower;
        }

        if ($token->text === '-') {
            $number = self::at($tokens, $position);
            ++$position;

            return self::canonicalValue(-self::numberLiteral($number));
        }

        if ($token->is([T_LNUMBER, T_DNUMBER])) {
            return self::canonicalValue(self::numberLiteral($token));
        }

        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            $body = substr($token->text, 1, -1);

            return self::canonicalValue(str_starts_with($token->text, "'") ? strtr($body, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($body));
        }

        if ($token->text === '[') {
            $items = [];
            $index = 0;

            while (self::at($tokens, $position)->text !== ']') {
                $value = self::documentedDefault($tokens, $position, $context);

                if (self::at($tokens, $position)->text === '=>') {
                    ++$position;
                    $key = $value;
                    $value = self::documentedDefault($tokens, $position, $context);
                } else {
                    $key = self::canonicalValue($index++);
                }

                $items[] = "{$key}=>{$value}";

                if (self::at($tokens, $position)->text === ',') {
                    ++$position;
                }
            }

            ++$position;

            return '[' . implode(',', $items) . ']';
        }

        if ($token->is(T_NEW)) {
            $class = self::resolveName(self::at($tokens, $position)->text, $context);
            ++$position;

            if (($tokens[$position] ?? null)?->text === '(') {
                $position = self::matching($tokens, $position, '(', ')') + 1;
            }

            return "new:{$class}";
        }

        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC])) {
            if (($tokens[$position] ?? null)?->is(T_DOUBLE_COLON) === true) {
                $member = self::at($tokens, $position + 1)->text;
                $position += 2;

                return 'const:' . self::resolveName($token->text, $context) . '::' . $member;
            }

            return 'const:' . ltrim($token->text, '\\');
        }

        self::fail("unsupported default expression: {$token->text}");
    }

    /**
     * 数のリテラル(T_LNUMBER・T_DNUMBER)の値を、PHPと同じ規則で求める。`(int)`のキャストは`0x10`を0にする等、PHPの
     * リテラルと違う値になるため使わない。`_`の区切りを除き、整数は10進・16進(`0x`)・8進(`0o`と、0で始まる表記)・
     * 2進(`0b`)を、小数は10進の小数と指数の表記を受け付ける。intに収まらない整数のリテラルは、PHPと同じくfloatになる。
     * それ以外の表記は、別の値に読まずに失敗にする。
     */
    private static function numberLiteral(PhpToken $token): int|float
    {
        $text = str_replace('_', '', $token->text);

        if (preg_match('/\\A0[xX]([0-9a-fA-F]+)\\z/', $text, $matches) === 1) {
            $value = hexdec($matches[1]);
        } elseif (preg_match('/\\A0[bB]([01]+)\\z/', $text, $matches) === 1) {
            $value = bindec($matches[1]);
        } elseif (preg_match('/\\A0[oO]?([0-7]+)\\z/', $text, $matches) === 1) {
            $value = octdec($matches[1]);
        } elseif (preg_match('/\\A(?:0|[1-9][0-9]*)\\z/', $text) === 1) {
            // intに収まらない10進の整数は、PHPと同じくfloatになる
            $value = filter_var($text, FILTER_VALIDATE_INT) !== false ? (int) $text : (float) $text;
        } elseif (preg_match('/\\A(?:[0-9]+\\.[0-9]*|\\.[0-9]+|[0-9]+)(?:[eE][+-]?[0-9]+)?\\z/', $text) === 1 && $token->is(T_DNUMBER)) {
            $value = (float) $text;
        } else {
            self::fail("unsupported number literal: {$token->text}");
        }

        self::assertSame($token->is(T_DNUMBER), \is_float($value), "unexpected type of the number literal: {$token->text}");

        return $value;
    }

    /**
     * 既定値の値を、文書と実装で比べる表記にする。enumのケースは定数の参照と同じ表記にする。
     */
    private static function canonicalValue(mixed $value): string
    {
        if ($value === null || \is_bool($value)) {
            return strtolower(var_export($value, true));
        }

        if (\is_int($value) || \is_float($value) || \is_string($value)) {
            return var_export($value, true);
        }

        if (\is_array($value)) {
            $items = [];

            foreach ($value as $key => $item) {
                $items[] = self::canonicalValue($key) . '=>' . self::canonicalValue($item);
            }

            return '[' . implode(',', $items) . ']';
        }

        if ($value instanceof UnitEnum) {
            return 'const:' . $value::class . '::' . $value->name;
        }

        self::assertIsObject($value);

        return 'new:' . $value::class;
    }

    /** 実装の引数の既定値の表記(canonicalValue())。既定値が無ければnull */
    private static function declaredDefault(ReflectionParameter $parameter): ?string
    {
        if (!$parameter->isDefaultValueAvailable()) {
            return null;
        }

        if ($parameter->isDefaultValueConstant()) {
            return 'const:' . ltrim((string) $parameter->getDefaultValueConstantName(), '\\');
        }

        return self::canonicalValue($parameter->getDefaultValue());
    }

    /**
     * 文書の型の宣言を、完全修飾の名前を名前の順に`|`でつないだ形にする(`?T`は`T|null`)。宣言が無ければnull。
     *
     * @param NameContext $context
     */
    private static function documentedType(string $type, array $context): ?string
    {
        if ($type === '') {
            return null;
        }

        $names = [];

        if (str_starts_with($type, '?')) {
            $names[] = 'null';
            $type = substr($type, 1);
        }

        foreach (explode('|', $type) as $name) {
            $names[] = self::resolveName($name, $context);
        }

        sort($names);

        return implode('|', $names);
    }

    /**
     * 名前をPHPの規則で完全修飾の名前にする。
     *
     * @param NameContext $context
     */
    private static function resolveName(string $name, array $context): string
    {
        if (\in_array(strtolower($name), self::BUILTIN_TYPES, true)) {
            return strtolower($name);
        }

        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $first = explode('\\', $name)[0];
        $imported = $context['uses'][strtolower($first)] ?? null;

        return $imported !== null ? $imported . substr($name, \strlen($first)) : $context['namespace'] . '\\' . $name;
    }

    /** 定数・enumのケースの値(整数か、単一引用符の文字列) */
    private static function literal(PhpToken $token): int|string
    {
        if ($token->is(T_LNUMBER)) {
            $value = self::numberLiteral($token);
            self::assertIsInt($value);

            return $value;
        }

        if ($token->is(T_CONSTANT_ENCAPSED_STRING) && str_starts_with($token->text, "'")) {
            return strtr(substr($token->text, 1, -1), ['\\\\' => '\\', "\\'" => "'"]);
        }

        self::fail("unsupported literal: {$token->text}");
    }

    /**
     * 文書の宣言と実装の食い違い。
     *
     * @param DocType $documented
     *
     * @return list<string>
     */
    private static function signatureMismatches(string $class, array $documented): array
    {
        if (!class_exists($class) && !interface_exists($class)) {
            return ["{$class} is not declared"];
        }

        $reflection = new ReflectionClass($class);
        $mismatches = [];
        $kind = $reflection->isInterface() ? 'interface' : ($reflection->isEnum() ? 'enum' : 'class');

        if ($kind !== $documented['kind']) {
            $mismatches[] = "{$class} is declared as {$kind} but documented as {$documented['kind']}";
        }

        if ($kind === 'class') {
            $declared = array_keys(array_filter(['abstract' => $reflection->isAbstract(), 'final' => $reflection->isFinal(), 'readonly' => $reflection->isReadOnly()]));

            if ($declared !== $documented['modifiers']) {
                $mismatches[] = "{$class} modifiers: documented [" . implode(' ', $documented['modifiers']) . '] but declared [' . implode(' ', $declared) . ']';
            }
        }

        $parent = $documented['extends'];

        if ($parent !== null && !(class_exists($parent) && $reflection->isSubclassOf($parent))) {
            $mismatches[] = "{$class} does not extend {$parent}";
        }

        foreach ($documented['implements'] as $interface) {
            if (!(interface_exists($interface) && $reflection->implementsInterface($interface))) {
                $mismatches[] = "{$class} does not implement {$interface}";
            }
        }

        if ($kind === 'enum' && enum_exists($class)) {
            $enum = new ReflectionEnum($class);
            $backing = $enum->getBackingType();
            $cases = [];

            foreach ($enum->getCases() as $case) {
                $cases[$case->getName()] = $case instanceof ReflectionEnumBackedCase ? $case->getBackingValue() : null;
            }

            if (($backing === null ? null : (string) $backing) !== $documented['backing']) {
                $mismatches[] = "{$class} backing type: documented " . ($documented['backing'] ?? 'none') . ' but declared ' . ($backing ?? 'none');
            }

            if ($cases !== $documented['cases']) {
                $mismatches[] = "{$class} cases: documented " . json_encode($documented['cases'], JSON_THROW_ON_ERROR) . ' but declared ' . json_encode($cases, JSON_THROW_ON_ERROR);
            }
        }

        foreach ($documented['constants'] as $name => $value) {
            $constant = $reflection->getReflectionConstant($name);

            if ($constant === false || !$constant->isPublic() || $constant->getValue() !== $value) {
                $mismatches[] = "{$class}::{$name}: documented public constant " . var_export($value, true) . ' is not declared';
            }
        }

        foreach ($documented['methods'] as $name => $method) {
            array_push($mismatches, ...self::methodMismatches($reflection, $kind, $name, $method));
        }

        return $mismatches;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param DocMethod $documented
     *
     * @return list<string>
     */
    private static function methodMismatches(ReflectionClass $reflection, string $kind, string $name, array $documented): array
    {
        $owner = "{$reflection->getName()}::{$name}()";

        if (!$reflection->hasMethod($name)) {
            return ["{$owner} is not declared"];
        }

        $method = $reflection->getMethod($name);
        $mismatches = [];
        $visibility = array_values(array_intersect($documented['modifiers'], ['public', 'protected', 'private']))[0] ?? 'public';
        $documentedModifiers = [$visibility, ...array_values(array_intersect($documented['modifiers'], ['abstract', 'final', 'static']))];
        $declaredModifiers = [
            $method->isPrivate() ? 'private' : ($method->isProtected() ? 'protected' : 'public'),
            // interfaceのメソッドは暗黙にabstractで、文書はabstractを書かない
            ...array_keys(array_filter(['abstract' => $method->isAbstract() && $kind !== 'interface', 'final' => $method->isFinal(), 'static' => $method->isStatic()])),
        ];

        if ($declaredModifiers !== $documentedModifiers) {
            $mismatches[] = "{$owner} modifiers: documented [" . implode(' ', $documentedModifiers) . '] but declared [' . implode(' ', $declaredModifiers) . ']';
        }

        $class = $reflection->getName();

        if ($documented['params'] !== null) {
            $documentedParams = array_map(
                static fn(array $param): string => self::parameterText($param['promoted'], self::withoutSelf($param['type'], $class), $param['variadic'], $param['name'], self::withoutSelfInDefault($param['default'], $class)),
                $documented['params'],
            );
            $declaredParams = array_map(static fn(ReflectionParameter $parameter): string => self::declaredParameter($parameter, $class), $method->getParameters());

            if ($declaredParams !== $documentedParams) {
                $mismatches[] = "{$owner} parameters: documented (" . implode(', ', $documentedParams) . ') but declared (' . implode(', ', $declaredParams) . ')';
            }
        }

        $return = self::withoutSelf(self::declaredType($method->getReturnType()), $class);
        $documentedReturn = self::withoutSelf($documented['return'], $class);

        if ($return !== $documentedReturn) {
            $mismatches[] = "{$owner} return type: documented " . ($documentedReturn ?? 'none') . ' but declared ' . ($return ?? 'none');
        }

        return $mismatches;
    }

    private static function declaredParameter(ReflectionParameter $parameter, string $class): string
    {
        $promoted = '';
        $property = $parameter->isPromoted() ? $parameter->getDeclaringClass()?->getProperty($parameter->getName()) : null;

        if ($property !== null && $property->isPublic()) {
            $promoted = $property->isReadOnly() ? 'public readonly' : 'public';
        }

        return self::parameterText($promoted, self::withoutSelf(self::declaredType($parameter->getType()), $class), $parameter->isVariadic(), $parameter->getName(), self::withoutSelfInDefault(self::declaredDefault($parameter), $class));
    }

    /**
     * 型の`self`を、そのクラスの名前にする(`: self`と`: Field`は同じ型。文書と実装で書き方が違ってよい)。
     */
    private static function withoutSelf(?string $type, string $class): ?string
    {
        if ($type === null) {
            return null;
        }

        $names = array_map(static fn(string $name): string => $name === 'self' ? $class : $name, explode('|', $type));
        sort($names);

        return implode('|', $names);
    }

    /** 照合に使う引数の表記(既定値はcanonicalValue() の表記) */
    private static function parameterText(string $promoted, ?string $type, bool $variadic, string $name, ?string $default): string
    {
        return ltrim(
            ($promoted === '' ? '' : "{$promoted} ") . ($type === null ? '' : "{$type} ") . ($variadic ? '...' : '') . "\${$name}" . ($default === null ? '' : " = {$default}"),
        );
    }

    /** 既定値の表記の`self::`・`static::`の定数の参照を、そのクラスの名前にする */
    private static function withoutSelfInDefault(?string $default, string $class): ?string
    {
        return $default === null ? null : preg_replace('/(?<=const:|new:)(?:self|static)(?=::|\z)/', $class, $default);
    }

    /** 実装の型の宣言を、documentedType() と同じ形にする */
    private static function declaredType(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $names = [];

        if ($type instanceof ReflectionNamedType) {
            $names[] = $type->getName();

            if ($type->allowsNull() && !\in_array($type->getName(), ['mixed', 'null'], true)) {
                $names[] = 'null';
            }
        } elseif ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                $names[] = $member instanceof ReflectionNamedType ? $member->getName() : (string) $member;
            }
        } else {
            $names[] = (string) $type;
        }

        sort($names);

        return implode('|', $names);
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function at(array $tokens, int $index): PhpToken
    {
        $token = $tokens[$index] ?? null;

        if ($token === null) {
            self::fail("token {$index} is missing in a code block of docs/05");
        }

        return $token;
    }

    /**
     * $fromより後の、最初の $textのトークンの位置。
     *
     * @param list<PhpToken> $tokens
     */
    private static function indexOf(array $tokens, int $from, string $text): int
    {
        $i = $from + 1;

        while (self::at($tokens, $i)->text !== $text) {
            ++$i;
        }

        return $i;
    }

    /**
     * $tokens[$open]($openの括弧)に対応する閉じ括弧の位置。
     *
     * @param list<PhpToken> $tokens
     */
    private static function matching(array $tokens, int $open, string $opening, string $closing): int
    {
        $depth = 0;

        for ($i = $open; ; ++$i) {
            $text = self::at($tokens, $i)->text;
            $depth += $text === $opening ? 1 : ($text === $closing ? -1 : 0);

            if ($depth === 0) {
                return $i;
            }
        }
    }

    private static function read(string $file): string
    {
        $source = file_get_contents($file);
        self::assertIsString($source, $file);

        return $source;
    }
}
