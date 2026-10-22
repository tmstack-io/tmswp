<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use ArrayObject;
use Brain\Monkey\Functions;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\Form\UnknownFieldException;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Callback;
use TMSWP\Core\Form\Rule\Choice;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\FileExtension;
use TMSWP\Core\Form\Rule\MaxFileSize;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\MinLength;
use TMSWP\Core\Form\Rule\Range;
use TMSWP\Core\Form\Rule\Regex;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\Rule\RequiredIf;
use TMSWP\Core\Form\Rule\Retype;
use TMSWP\Core\Form\Rule\RuleInterface;
use TMSWP\Core\Form\Rule\Url;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TypeError;

/**
 * 同梱の規則の単位の仕様。旧 iwf2b の規則のテスト(tests/TestCase/Field/Rule/ と UtilTest の is_empty)の検証の値を、
 * 新しい規則の仕様(docs/05 §Rule群・§空値スルーセマンティクス)に合わせて移植する(docs/10 §8.1 の 5・9〜16)。
 * 旧 FW と結果が変わる値には、旧 FW の結果をコメントで残す。
 */
final class FormRuleTest extends UnitTestCase
{
    /**
     * 旧 Util::is_empty・RuleTrait::is_empty の境界値。旧 FW は false・空白だけの文字列・要素数0の Countable も空と
     * したが、新しい空値は null・''・[] だけ(チェックボックスの false が検証をすり抜けた罠の修正)。
     *
     * @return iterable<string, array{mixed, bool}>
     */
    public static function emptinessBoundaries(): iterable
    {
        yield 'null' => [null, true];
        yield 'empty string' => ['', true];
        yield 'empty array' => [[], true];
        yield 'false' => [false, false]; // 旧: 空
        yield 'space only' => [' ', false]; // 旧: 空(trim する指定のとき)
        yield 'Countable with no element' => [new ArrayObject(), false]; // 旧: 空
        yield 'zero' => [0, false];
        yield 'string zero' => ['0', false];
        yield 'true' => [true, false];
        yield 'text' => ['test', false];
        yield 'array with an empty string' => [[''], false];
        yield 'Countable with an element' => [new ArrayObject(['a']), false];
    }

    #[DataProvider('emptinessBoundaries')]
    public function testOnlyNullTheEmptyStringAndTheEmptyArrayAreEmpty(mixed $value, bool $empty): void
    {
        self::assertSame($empty, Validator::isEmpty($value));
    }

    /**
     * Required 系以外の全規則と、空でないが違反する値。
     *
     * @return iterable<string, array{RuleInterface, string}>
     */
    public static function rulesSkippingEmptyValues(): iterable
    {
        yield 'MaxLength' => [new MaxLength(1), '00'];
        yield 'MinLength' => [new MinLength(2), '0'];
        yield 'Email' => [new Email(), '0'];
        yield 'Url' => [new Url(), '0'];
        yield 'Regex' => [new Regex('/\A[a-z]+\z/'), '0'];
        yield 'Choice' => [new Choice(['a']), '0'];
        yield 'Callback' => [new Callback(static fn(mixed $value): bool => false), '0'];
        yield 'Retype' => [new Retype('other'), '0'];
        yield 'Range' => [new Range(1, 2), '0'];
        yield 'FileExtension' => [new FileExtension(['pdf']), '0'];
        yield 'MaxFileSize' => [new MaxFileSize(1), '0'];
    }

    /**
     * 空値スルー(旧 RuleTrait の through_if_empty)。Required 系以外の規則は空値(''・null・[])を検証せずに通し、
     * '0' のような空でない値は検証する。
     */
    #[DataProvider('rulesSkippingEmptyValues')]
    public function testRulesOtherThanTheRequiredOnesSkipEmptyValues(RuleInterface $rule, string $violation): void
    {
        Functions\when('size_format')->justReturn('1 B');
        $definition = FormDefinition::create('form')->text('value', 'Value', [$rule])->text('other', 'Other');
        $validator = new Validator();

        self::assertTrue($rule->skipIfEmpty());

        foreach (['', null, []] as $empty) {
            self::assertSame([], $validator->validate($definition, ['value' => $empty, 'other' => 'x']), var_export($empty, true));
        }

        self::assertArrayHasKey('value', $validator->validate($definition, ['value' => $violation, 'other' => 'x']));
    }

    /** Required・RequiredIf だけが空値を検証する。'' と一緒に並べた形式の規則は実行されず、Required のエラーだけが出る */
    public function testOnlyTheRequiredRulesCheckEmptyValues(): void
    {
        $definition = FormDefinition::create('form')
            ->text('email', 'Email', [new Required(), new Email(), new MaxLength(3)])
            ->text('optional', 'Optional', [new Email(), new MaxLength(3)])
            ->text('trigger', 'Trigger')
            ->text('conditional', 'Conditional', [RequiredIf::filled('trigger'), new Email()]);

        self::assertFalse((new Required())->skipIfEmpty());
        self::assertFalse(RequiredIf::filled('trigger')->skipIfEmpty());
        self::assertSame(
            ['email' => ['Email is required.'], 'conditional' => ['Conditional is required.']],
            (new Validator())->validate($definition, ['email' => '', 'optional' => '', 'trigger' => 'x', 'conditional' => '']),
        );
        // '0' は空ではない
        self::assertSame(
            ['email' => ['Please enter a valid email address for Email.']],
            (new Validator())->validate($definition, ['email' => '0', 'optional' => '', 'trigger' => '', 'conditional' => '']),
        );
    }

    /**
     * 旧 RuleTrait の必須パラメータの欠落・型の違いの実行時の検査は、コンストラクタの型宣言(strict_types)に置き換えた。
     * 残すのは TypeError になることの確認だけ(欠落は TypeError の子の ArgumentCountError)。
     *
     * @return iterable<string, array{Closure(): mixed}>
     */
    public static function constructorsWithWrongArguments(): iterable
    {
        $constructors = require __DIR__ . '/../fixtures/form-validation/rules-with-wrong-arguments.php';
        self::assertIsArray($constructors);

        foreach ($constructors as $name => $construct) {
            self::assertIsString($name);
            self::assertInstanceOf(Closure::class, $construct);

            yield $name => [$construct];
        }
    }

    /** @param Closure(): mixed $construct */
    #[DataProvider('constructorsWithWrongArguments')]
    public function testMissingOrWronglyTypedRuleArgumentsAreTypeErrors(Closure $construct): void
    {
        $this->expectException(TypeError::class);

        $construct();
    }

    /**
     * 旧 ChoiceRuleTest。選べる値と、文字列にした値を厳密に比べる(旧 strict の指定は無く、常に文字列で比べる)。
     *
     * @return iterable<string, array{mixed, bool}>
     */
    public static function choiceValues(): iterable
    {
        yield 'choice_1' => ['choice_1', true];
        yield 'choice_2' => ['choice_2', true];
        yield 'string 10 for the int choice 10' => ['10', true];
        yield 'int 10' => [10, true];
        yield 'string 0.01 for the float choice 0.01' => ['0.01', true]; // 旧 strict: 不合格
        yield 'float 0.01' => [0.01, true];
        yield 'invalid choice' => ['invalid_choice', false];
        yield 'list in one string' => ['choice_1 choice_2, 10', false];
        yield 'numeric string of another form' => ['10.0', false];
        yield 'leading space' => [' 10', false];
        yield 'array' => [['choice_1'], false];
    }

    #[DataProvider('choiceValues')]
    public function testChoiceAcceptsOnlyTheChoicesComparedAsStrings(mixed $value, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('value', 'Value', [new Choice(['choice_1', 'choice_2', 10, 0.01])]);

        self::assertSame($valid ? [] : ['value' => ['Value has an invalid choice.']], (new Validator())->validate($definition, ['value' => $value]));
    }

    /** 旧 ChoiceRuleTest の multiple。複数選択の項目は要素ごとに確かめ、1つでも選べない値があれば違反 */
    public function testChoiceChecksEachElementOfAMultipleField(): void
    {
        $definition = FormDefinition::create('form')
            ->checkboxes('topics', 'Topics', ['choice' => 'Choice', '10' => 'Ten', '0.01' => 'Hundredth'], [new Choice(['choice', 10, 0.01])]);
        $validator = new Validator();

        self::assertSame([], $validator->validate($definition, ['topics' => ['choice']]));
        self::assertSame([], $validator->validate($definition, ['topics' => ['choice', '10', '0.01']]));
        self::assertSame(['topics' => ['Topics has an invalid choice.']], $validator->validate($definition, ['topics' => ['choice', 'invalid_choice']]));
        // 旧 FW は multiple の指定で配列でない値を例外にした。新しい FW は1つの要素として確かめる
        self::assertSame([], $validator->validate($definition, ['topics' => 'choice']));
        self::assertSame(['topics' => ['Topics has an invalid choice.']], $validator->validate($definition, ['topics' => 'invalid_choice']));
    }

    /**
     * choices を持つ項目(select・radios・checkboxes)は、Choice を書かなくても選択肢の外の値と、単一の値の項目への
     * 配列を、例外ではなく項目のエラーにする。
     */
    public function testChoiceFieldsRejectValuesOutsideTheirChoicesAndArraysAsFieldErrors(): void
    {
        $definition = FormDefinition::create('form')
            ->select('plan', 'Plan', ['basic' => 'Basic'])
            ->radios('color', 'Color', ['red' => 'Red'])
            ->checkboxes('topics', 'Topics', ['news' => 'News']);
        $validator = new Validator();

        self::assertSame([], $validator->validate($definition, ['plan' => 'basic', 'color' => 'red', 'topics' => ['news']]));
        self::assertSame(
            ['plan' => ['Plan has an invalid choice.'], 'color' => ['Color has an invalid choice.'], 'topics' => ['Topics has an invalid choice.']],
            $validator->validate($definition, ['plan' => 'premium', 'color' => 'blue', 'topics' => ['news', 'sports']]),
        );
        self::assertSame(
            ['plan' => ['Plan has an invalid choice.'], 'color' => ['Color has an invalid choice.'], 'topics' => ['Topics has an invalid choice.']],
            $validator->validate($definition, ['plan' => ['basic'], 'color' => ['red'], 'topics' => [['news']]]),
        );
    }

    /** @return iterable<array{string, bool}> 旧 EmailRuleTest の valid_emails と invalid_emails の全件 */
    public static function emailAddresses(): iterable
    {
        return [
            ['email@example.com', true],
            ['firstname.lastname@example.com', true],
            ['email@subdomain.example.com', true],
            ['firstname+lastname@example.com', true],
            ['"email"@example.com', true],
            ['1234567890@example.com', true],
            ['email@example-one.com', true],
            ['_______@example.com', true],
            ['email@example.name', true],
            ['email@example.museum', true],
            ['email@example.co.jp', true],
            ['firstname-lastname@example.com', true],
            ['much."more\\ unusual"@example.com', true],
            ['very.unusual."@".unusual.com@example.com', true],
            ['very."(),:;<>[]".VERY."very@\\\\\\ \\"very".unusual@strange.example.com', true],
            ['plainaddress', false],
            ['#@%^%#$@#$@#.com', false],
            ['@example.com', false],
            ['Joe Smith <email@example.com>', false],
            ['email.example.com', false],
            ['email@example@example.com', false],
            ['.email@example.com', false],
            ['email.@example.com', false],
            ['email..email@example.com', false],
            ['email@example.com (Joe Smith)', false],
            ['email@example', false],
            ['email@-example.com', false],
            ['email@123.123.123.123', false],
            ['email@[123.123.123.123]', true], // 旧: 不合格
            ['email@111.222.333.44444', false],
            ['email@example..com', false],
            ['Abc..123@example.com', false],
            ['"(),:;<>[\\]@example.com', false],
            ['just"not"right@example.com', false],
            ['this\\ is\\"really\\"not\\\\allowed@example.com', false],
        ];
    }

    #[DataProvider('emailAddresses')]
    public function testEmailFollowsFilterValidateEmail(string $address, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('email', 'Email', [new Email()]);

        self::assertSame($valid ? [] : ['email' => ['Please enter a valid email address for Email.']], (new Validator())->validate($definition, ['email' => $address]));
    }

    /**
     * 旧 ExactLengthRuleTest の境界値(ASCII とマルチバイトの10文字)。ExactLength は同梱しない(docs/05 §同梱ルール一覧)
     * ため、同じ長さの MinLength と MaxLength の組で確かめる。
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function exactLengthBoundaries(): iterable
    {
        yield '9 characters' => ['abcdefghi', false];
        yield '10 characters' => ['abcdefghij', true];
        yield '11 characters' => ['abcdefghijk', false];
        yield '9 multibyte characters' => ['あいうえおかきくけ', false];
        yield '10 multibyte characters' => ['あいうえおかきくけこ', true];
        yield '11 multibyte characters' => ['あいうえおかきくけこさ', false];
    }

    #[DataProvider('exactLengthBoundaries')]
    public function testMinLengthAndMaxLengthCountMultibyteCharacters(string $value, bool $valid): void
    {
        $definition = FormDefinition::create('form')
            ->text('min', 'Min', [new MinLength(10)])
            ->text('max', 'Max', [new MaxLength(10)])
            ->text('exact', 'Exact', [new MinLength(10), new MaxLength(10)]);
        $errors = (new Validator())->validate($definition, ['min' => $value, 'max' => $value, 'exact' => $value]);

        self::assertSame(mb_strlen($value) >= 10, !\array_key_exists('min', $errors), 'MinLength');
        self::assertSame(mb_strlen($value) <= 10, !\array_key_exists('max', $errors), 'MaxLength');
        self::assertSame($valid, !\array_key_exists('exact', $errors), 'MinLength and MaxLength');
    }

    /**
     * 旧 RequiredIfRuleTest の真理値表(参照する項目の空・空でない × 自身の値の空・空でない、期待値の一致・不一致)。
     * 旧 RequiredUnlessRuleTest の裏条件は、RequiredUnless を同梱しない(docs/05 §同梱ルール一覧)ため移植しない。
     * 参照する項目は ValidationContext 経由で引く。
     *
     * @return iterable<string, array{RuleInterface, string, string, bool}>
     */
    public static function requiredIfTruthTable(): iterable
    {
        yield 'filled: empty other, empty value' => [RequiredIf::filled('other'), '', '', true];
        yield 'filled: empty other, value' => [RequiredIf::filled('other'), '', 'value', true];
        yield 'filled: other, empty value' => [RequiredIf::filled('other'), 'field value', '', false];
        yield 'filled: other, value' => [RequiredIf::filled('other'), 'field value', 'value', true];
        yield 'filled: other of 0, empty value' => [RequiredIf::filled('other'), '0', '', false];
        yield 'equals: not matched, empty value' => [RequiredIf::equals('other', 'invalid value'), 'field value', '', true];
        yield 'equals: matched, empty value' => [RequiredIf::equals('other', 'field value'), 'field value', '', false];
        yield 'equals: matched, value' => [RequiredIf::equals('other', 'field value'), 'field value', 'value', true];
        // 旧 strict の指定は無く、常に文字列として厳密に比べる
        yield 'equals: 10 and 10' => [RequiredIf::equals('other', '10'), '10', '', false];
        yield 'equals: 10 and 010' => [RequiredIf::equals('other', '10'), '010', '', true];
        yield 'equals: 10 and 10.0' => [RequiredIf::equals('other', '10'), '10.0', '', true];
    }

    #[DataProvider('requiredIfTruthTable')]
    public function testRequiredIfRequiresTheValueOnlyWhenTheConditionHolds(RuleInterface $rule, string $other, string $value, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('other', 'Other')->text('value', 'Value', [$rule]);

        self::assertSame($valid ? [] : ['value' => ['Value is required.']], (new Validator())->validate($definition, ['other' => $other, 'value' => $value]));
    }

    /**
     * 旧 RetypeRuleTest。もう一方の項目の値と、文字列として厳密に比べる(旧 strict の指定は無い)。
     *
     * @return iterable<string, array{mixed, mixed, bool}>
     */
    public static function retypeValues(): iterable
    {
        yield 'same value' => ['value', 'value', true];
        yield 'different value' => ['value', 'other value', false];
        yield 'int 10 and string 10' => ['10', 10, true]; // 旧 strict: 不合格
        yield '10 and 010' => ['10', '010', false];
        yield 'trailing space' => ['value ', 'value', false];
        yield 'case' => ['Value', 'value', false];
    }

    #[DataProvider('retypeValues')]
    public function testRetypeComparesWithTheOtherFieldAsStrings(mixed $value, mixed $other, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('email', 'Email')->text('email_confirmation', 'Email confirmation', [new Retype('email')]);

        self::assertSame(
            $valid ? [] : ['email_confirmation' => ['Email confirmation must match Email.']],
            (new Validator())->validate($definition, ['email' => $other, 'email_confirmation' => $value]),
        );
    }

    /** 旧 FW の「登録されていない項目の名前」の例外は、ValidationContext の UnknownFieldException になる */
    public function testRulesReferringToAnUnknownFieldThrow(): void
    {
        $definition = FormDefinition::create('form')->text('value', 'Value');

        foreach ([new Retype('unregistered_field'), RequiredIf::filled('unregistered_field'), RequiredIf::equals('unregistered_field', 'x')] as $rule) {
            try {
                $rule->validate('value', new ValidationContext($definition, ['value' => 'value'], 'value'));
                self::fail($rule::class . ' did not throw');
            } catch (UnknownFieldException $exception) {
                self::assertSame('unknown form field: unregistered_field', $exception->getMessage());
            }
        }
    }

    /** @return iterable<array{string, bool}> 旧 UrlRuleTest の valid_urls・invalid_urls の全件(既定の http・https) */
    public static function urls(): iterable
    {
        return [
            ['http://a.pl', true],
            ['http://www.example.com', true],
            ['http://www.example.com.', true],
            ['http://www.example.museum', true],
            ['https://example.com/', true],
            ['https://example.com:80/', true],
            ['http://examp_le.com', false], // 旧: 合格
            ['http://www.sub_domain.examp_le.com', false], // 旧: 合格
            ['http://www.example.coop/', true],
            ['http://www.test-example.com/', true],
            ['http://www.symfony.com/', true],
            ['http://symfony.fake/blog/', true],
            ['http://symfony.com/?', true],
            ['http://symfony.com/search?type=&q=url+validator', true],
            ['http://symfony.com/#', true],
            ['http://symfony.com/#?', true],
            ['http://www.symfony.com/doc/current/book/validation.html#supported-constraints', true],
            ['http://very.long.domain.name.com/', true],
            ['http://localhost/', true],
            ['http://myhost123/', true],
            ['http://127.0.0.1/', true],
            ['http://127.0.0.1:80/', true],
            ['http://[::1]/', true],
            ['http://[::1]:80/', true],
            ['http://[1:2:3::4:5:6:7]/', true],
            ['http://sãopaulo.com/', true],
            ['http://xn--sopaulo-xwa.com/', true],
            ['http://sãopaulo.com.br/', true],
            ['http://xn--sopaulo-xwa.com.br/', true],
            ['http://пример.испытание/', true],
            ['http://xn--e1afmkfd.xn--80akhbyknj4f/', true],
            ['http://مثال.إختبار/', true],
            ['http://xn--mgbh0fb.xn--kgbechtv/', true],
            ['http://例子.测试/', true],
            ['http://xn--fsqu00a.xn--0zwm56d/', true],
            ['http://例子.測試/', true],
            ['http://xn--fsqu00a.xn--g6w251d/', true],
            ['http://例え.テスト/', true],
            ['http://xn--r8jz45g.xn--zckzah/', true],
            ['http://مثال.آزمایشی/', true],
            ['http://xn--mgbh0fb.xn--hgbk6aj7f53bba/', true],
            ['http://실례.테스트/', true],
            ['http://xn--9n2bp8q.xn--9t4b11yi5a/', true],
            ['http://العربية.idn.icann.org/', true],
            ['http://xn--ogb.idn.icann.org/', true],
            ['http://xn--e1afmkfd.xn--80akhbyknj4f.xn--e1afmkfd/', true],
            ['http://xn--espaa-rta.xn--ca-ol-fsay5a/', true],
            ['http://xn--d1abbgf6aiiy.xn--p1ai/', true],
            ['http://☎.com/', true],
            ['http://username:password@symfony.com', true],
            ['http://user.name:password@symfony.com', true],
            ['http://user_name:pass_word@symfony.com', true],
            ['http://username:pass.word@symfony.com', true],
            ['http://user.name:pass.word@symfony.com', true],
            ['http://user-name@symfony.com', true],
            ['http://user_name@symfony.com', true],
            ['http://symfony.com?', true],
            ['http://symfony.com?query=1', true],
            ['http://symfony.com/?query=1', true],
            ['http://symfony.com#', true],
            ['http://symfony.com#fragment', true],
            ['http://symfony.com/#fragment', true],
            ['http://symfony.com/#one_more%20test', true],
            ['http://example.com/exploit.html?hello[0]=test', true],
            ['example.com', false],
            ['://example.com', false],
            ['http ://example.com', false],
            ['http:/example.com', false],
            ['http://example.com::aa', false],
            ['http://example.com:aa', false],
            ['ftp://example.fr', false],
            ['faked://example.fr', false],
            ['http://127.0.0.1:aa/', false],
            ['ftp://[::1]/', false],
            ['http://[::1', false],
            ['http://hello.☎/', true], // 旧: 不合格
            ['http://:password@symfony.com', true], // 旧: 不合格
            ['http://:password@@symfony.com', false],
            ['http://username:passwordsymfony.com', false],
            ['http://usern@me:password@symfony.com', false],
            ['http://example.com/exploit.html?<script>alert(1);</script>', true], // 旧: 不合格
            ['http://example.com/exploit.html?hel lo', false],
            ['http://example.com/exploit.html?not_a%hex', true], // 旧: 不合格
            ['http://', false],
        ];
    }

    /**
     * Url は国際化ドメイン名のホスト名を idn_to_ascii(UTS #46)で ASCII に変換してから、filter_var(FILTER_VALIDATE_URL)と
     * スキームで確かめる(旧 FW の Symfony 由来の正規表現は廃止。docs/05)。そのため、旧 FW と結果の違う値がある
     * (ホスト名の _ は不合格、UTS #46 で変換できる記号のホスト名は合格、クエリの未エスケープの文字は合格)。
     */
    #[DataProvider('urls')]
    public function testUrlFollowsFilterValidateUrlAndTheSchemes(string $url, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('url', 'URL', [new Url()]);

        self::assertSame($valid ? [] : ['url' => ['Please enter a valid URL for URL.']], (new Validator())->validate($definition, ['url' => $url]));
    }

    /**
     * 旧 UrlRuleTest の valid_relative_urls・invalid_relative_urls の全件。Url はスキームの無い URL を受け付けない
     * (旧 relative の指定は無い)。
     *
     * @return iterable<array{string, bool}>
     */
    public static function relativeUrls(): iterable
    {
        return [
            ['//example.com', false], // 旧: 合格
            ['//examp_le.com', false], // 旧: 合格
            ['//symfony.fake/blog/', false], // 旧: 合格
            ['//symfony.com/search?type=&q=url+validator', false], // 旧: 合格
            ['/example.com', false],
            ['//example.com::aa', false],
            ['//example.com:aa', false],
            ['//127.0.0.1:aa/', false],
            ['//[::1', false],
            ['//hello.☎/', false],
            ['//:password@symfony.com', false],
            ['//:password@@symfony.com', false],
            ['//username:passwordsymfony.com', false],
            ['//usern@me:password@symfony.com', false],
            ['//example.com/exploit.html?<script>alert(1);</script>', false],
            ['//example.com/exploit.html?hel lo', false],
            ['//example.com/exploit.html?not_a%hex', false],
            ['//', false],
        ];
    }

    #[DataProvider('relativeUrls')]
    public function testUrlRejectsUrlsWithoutAScheme(string $url, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('url', 'URL', [new Url()]);

        self::assertSame($valid ? [] : ['url' => ['Please enter a valid URL for URL.']], (new Validator())->validate($definition, ['url' => $url]));
    }

    /** @return iterable<array{string, bool}> 旧 UrlRuleTest の valid_custom_protocol_urls の全件 */
    public static function customProtocolUrls(): iterable
    {
        return [
            ['ftp://example.com', true],
            ['file://127.0.0.1', true],
            ['git://[::1]/', true],
        ];
    }

    #[DataProvider('customProtocolUrls')]
    public function testUrlAcceptsTheGivenProtocolsOnly(string $url, bool $valid): void
    {
        $definition = FormDefinition::create('form')
            ->text('custom', 'Custom', [new Url(['ftp', 'file', 'git'])])
            ->text('web', 'Web', [new Url()]);

        $errors = (new Validator())->validate($definition, ['custom' => $url, 'web' => $url]);

        self::assertSame($valid, !\array_key_exists('custom', $errors));
        // 既定(http・https)では不合格
        self::assertSame(['Please enter a valid URL for Web.'], $errors['web'] ?? null);
    }

    /**
     * 国際化ドメイン名のホスト名はUTS #46でASCIIに変換して確かめる。変換できないホスト名(_を含む・空のラベル・
     * 63オクテットを超えるラベル等)は不合格。パス・クエリの非ASCIIは変換しないため、filter_varの判定どおり不合格。
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function internationalizedDomainNameUrls(): iterable
    {
        yield 'Japanese host' => ['https://例え.jp/', true];
        yield 'Japanese host with a path and a query' => ['https://日本語.jp/path?q=1', true];
        yield 'userinfo and port' => ['https://user:pass@例え.jp:8080/', true];
        yield 'full-width and upper-case letters mapped by UTS #46' => ['https://ＥＸＡＭＰＬＥ.例え.JP/', true];
        yield 'underscore in an ASCII label' => ['https://exa_mple.例え.jp/', false];
        yield 'underscore in a non-ASCII label' => ['https://例_え.jp/', false];
        yield 'empty label' => ['https://a..例え.jp/', false];
        yield 'label starting with a hyphen' => ['https://-例え.jp/', false];
        yield 'label longer than 63 octets' => ['https://' . str_repeat('あ', 64) . '.jp/', false];
        yield 'invalid punycode label' => ['https://xn--a.例え.jp/', false];
        yield 'non-ASCII query' => ['https://例え.jp/?q=例え', false];
        yield 'percent-encoded path and query' => ['https://例え.jp/%E4%BE%8B?q=%E4%BE%8B', true];
    }

    #[DataProvider('internationalizedDomainNameUrls')]
    public function testUrlConvertsInternationalizedDomainNamesWithUts46(string $url, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('url', 'URL', [new Url()]);

        self::assertSame($valid ? [] : ['url' => ['Please enter a valid URL for URL.']], (new Validator())->validate($definition, ['url' => $url]));
    }

    /**
     * 変換するのはホスト名の部分だけ。パスやuserinfoに同じ文字列があっても置き換えないため、非ASCIIが残って不合格
     * (URLの全体を置き換えると、どちらも合格になる)。値そのものは書き換えない。
     */
    public function testUrlConvertsOnlyTheHostPart(): void
    {
        $definition = FormDefinition::create('form')
            ->text('path', 'Path', [new Url()])
            ->text('userinfo', 'Userinfo', [new Url()])
            ->text('converted', 'Converted', [new Url()]);
        $values = ['path' => 'https://例え.jp/例え.jp', 'userinfo' => 'https://例え.jp@例え.jp/', 'converted' => 'https://例え.jp/xn--r8jz45g.jp'];

        self::assertSame(
            ['path' => ['Please enter a valid URL for Path.'], 'userinfo' => ['Please enter a valid URL for Userinfo.']],
            (new Validator())->validate($definition, $values),
        );
    }

    /** 国際化ドメイン名のURLでも、スキームが$protocolsに無ければ不合格 */
    public function testUrlChecksTheSchemeOfInternationalizedDomainNameUrls(): void
    {
        $definition = FormDefinition::create('form')
            ->text('custom', 'Custom', [new Url(['ftp'])])
            ->text('web', 'Web', [new Url()]);

        self::assertSame(
            ['web' => ['Please enter a valid URL for Web.']],
            (new Validator())->validate($definition, ['custom' => 'ftp://例え.jp/', 'web' => 'ftp://例え.jp/']),
        );
    }

    /**
     * ext-intlが無ければ、規則を作る時点でFormConfigException(入力値に応じた実行時の例外にしない)。
     * idn_to_asciiをdisable_functionsで無くした別のPHPのプロセスで確かめる。このテストで消えるのは関数だけで、intlの定数は
     * 残るため、クラスの読み込みやコンストラクタの検査より前にintlの定数を評価する不具合は検出できない。
     */
    public function testUrlRequiresTheIntlExtension(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            try {
                new TMSWP\Core\Form\Rule\Url();
                echo 'no exception';
            } catch (TMSWP\Core\Exception\Form\FormConfigException $exception) {
                echo $exception->getMessage();
            }
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=idn_to_ascii', '-r', $code, \dirname(__DIR__, 4) . '/vendor/autoload.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), "script failed: {$stderr}");
        self::assertSame('Url requires the intl extension (ext-intl) to validate internationalized domain names', $stdout);
    }
}
