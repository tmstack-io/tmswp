<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\Form\UnknownFieldException;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\SpamGuardInterface;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class FormDefinitionTest extends UnitTestCase
{
    public function testFieldNamesRejectInvalidReservedAndTokenSuffixNames(): void
    {
        $this->expectException(FormConfigException::class);
        Field::text('BadName', '不正');
    }

    public function testReservedFieldNameIsRejected(): void
    {
        $this->expectException(FormConfigException::class);
        Field::hidden(Form::FIELD_FORM_ID);
    }

    public function testTokenSuffixFieldNameIsRejected(): void
    {
        $this->expectException(FormConfigException::class);
        Field::text('upload__token', '不正');
    }

    public function testDuplicateFieldsAreRejected(): void
    {
        $this->expectException(FormConfigException::class);

        FormDefinition::create('contact')
            ->text('name', '名前')
            ->text('name', '氏名');
    }

    public function testChoiceFieldRequiresChoices(): void
    {
        $this->expectException(FormConfigException::class);

        Field::select('pref', '都道府県', []);
    }

    public function testDefinitionRejectsUnknownFieldLookup(): void
    {
        $this->expectException(UnknownFieldException::class);

        FormDefinition::create('contact')->field('missing');
    }

    /** @return iterable<string, array{Closure(): FormDefinition}> */
    public static function passwordFieldsWithConfirmation(): iterable
    {
        yield 'password before confirm()' => [static fn(): FormDefinition => FormDefinition::create('signup')->password('pass', 'Password')->confirm()];
        yield 'password after confirm()' => [static fn(): FormDefinition => FormDefinition::create('signup')->confirm()->password('pass', 'Password')];
        yield 'password part of a composite field' => [static fn(): FormDefinition => FormDefinition::create('signup')->confirm()->add(new DefinitionTestCredentialField('login', 'Login'))];
    }

    /**
     * 確認画面の値は ConfirmToken(base64 で読める)で運ぶため、パスワードを onComplete まで届けるにはトークンに平文で
     * 入れるしかない。定義の時点で拒む。
     *
     * @param Closure(): FormDefinition $define
     */
    #[DataProvider('passwordFieldsWithConfirmation')]
    public function testPasswordFieldCannotBeUsedWithTheConfirmationScreen(Closure $define): void
    {
        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage('the confirm token would carry the password in plain text');

        $define();
    }

    public function testPasswordFieldCanBeUsedWithoutTheConfirmationScreen(): void
    {
        $definition = FormDefinition::create('login')->password('pass', 'Password')->confirm(false);

        self::assertFalse($definition->isConfirmEnabled());
        self::assertTrue($definition->has('pass'));
    }

    public function testHideOnConfirmRejectsFieldsThatAreNotDefinedYet(): void
    {
        $this->expectException(UnknownFieldException::class);

        FormDefinition::create('contact')->hideOnConfirm('email_confirm')->text('email_confirm', 'Email (again)');
    }

    public function testBuilderStoresFieldsAndConfiguration(): void
    {
        $store = new \TMSWP\Core\Form\TempFileStore($this->createTempDirectory('form-store'));
        $definition = FormDefinition::create('entry-form')
            ->text('name', '名前')
            ->checkboxes('topics', '関心', ['1' => 'ニュース', '2' => '資料'], default: ['2'])
            ->confirm()
            ->confirmTtl(90)
            ->errorHtml('<span class="error">%s</span>')
            ->tempFileStore($store);
        $first = new DefinitionTestSpamGuard();
        $second = new DefinitionTestSpamGuard();

        self::assertSame([], $definition->spamGuards());
        self::assertSame($definition, $definition->spamGuard($first)->spamGuard($second));
        self::assertSame([$first, $second], $definition->spamGuards());

        self::assertTrue($definition->has('name'));
        self::assertTrue($definition->isConfirmEnabled());
        self::assertSame(90, $definition->ttl());
        self::assertSame('<span class="error">%s</span>', $definition->errorTemplate());
        self::assertSame($store, $definition->store());

        $topics = $definition->field('topics');
        self::assertInstanceOf(Field::class, $topics);
        self::assertSame(['2'], $topics->default);
    }
}

final class DefinitionTestSpamGuard implements SpamGuardInterface
{
    public function render(string $formId): string
    {
        return '';
    }

    public function passes(FormRequest $request): bool
    {
        return true;
    }
}

final class DefinitionTestCredentialField extends CompositeField
{
    public function parts(): array
    {
        return [
            'user' => Field::text($this->name . '_user', 'User'),
            'pass' => Field::password($this->name . '_pass', 'Password'),
        ];
    }

    public function compose(array $partValues): string
    {
        return ($partValues['user'] ?? '') . ':' . ($partValues['pass'] ?? '');
    }

    public function decompose(string $value): array
    {
        [$user, $pass] = array_pad(explode(':', $value, 2), 2, '');

        return ['user' => $user, 'pass' => $pass];
    }
}
