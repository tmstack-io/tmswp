<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\FieldView;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormHtml;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormStep;
use TMSWP\Testing\UnitTestCase;
use WP;

/**
 * FormHtml(純関数のレンダラ)と FieldView の出力のスナップショット。旧 iwf2b の FormTest・FormChoiceTest の
 * checkbox・radio・複数の選択肢の出力の仕様(docs/10 §8.1 の 17・18)と、利用者の入力と動的な選択肢のエスケープを確かめる。
 */
final class FormHtmlTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp']);

        parent::tearDown();
    }

    /**
     * 旧 FormTest の checkbox・radio の value 属性と checked の境界の10件。FormHtml は value を必ず受け取るため、
     * 旧 FW と違い value="" も出力する。checked は引数どおりで、value が '0' でも '' でも変わらない。
     */
    public function testCheckboxAndRadioRenderTheValueAttributeAndCheckedAsGiven(): void
    {
        $attrs = ['id' => 'form_id', 'class' => 'form_class'];

        foreach (['checkbox', 'radio'] as $type) {
            $render = static fn(string $value, bool $checked): string => $type === 'checkbox'
                ? FormHtml::checkbox('test', $value, $checked, $attrs)
                : FormHtml::radio('test', $value, $checked, $attrs);

            self::assertSame("<input type=\"{$type}\" name=\"test\" value=\"\" id=\"form_id\" class=\"form_class\">", $render('', false));
            self::assertSame("<input type=\"{$type}\" name=\"test\" value=\"\" checked id=\"form_id\" class=\"form_class\">", $render('', true));
            self::assertSame("<input type=\"{$type}\" name=\"test\" value=\"test_value\" id=\"form_id\" class=\"form_class\">", $render('test_value', false));
            // '0' は空ではないため、value 属性を落とさない
            self::assertSame("<input type=\"{$type}\" name=\"test\" value=\"0\" id=\"form_id\" class=\"form_class\">", $render('0', false));
            self::assertSame("<input type=\"{$type}\" name=\"test\" value=\"0\" checked id=\"form_id\" class=\"form_class\">", $render('0', true));
        }
    }

    /**
     * 旧 FormTest の「value 属性と同じ値を設定すると checked になる」。FieldView は現在の値と選択肢の値を文字列として
     * 厳密に比べる('0' も空として扱わない)。
     */
    public function testControlsAreCheckedWhenTheCurrentValueEqualsTheirValue(): void
    {
        $attrs = ['id' => 'form_id', 'class' => 'form_class'];
        $checkbox = Field::checkbox('test', 'Test', 'test_value');
        $zeroCheckbox = Field::checkbox('test', 'Test', '0');
        $radios = Field::radios('test', 'Test', ['' => 'None', '0' => 'Zero', 'test_value' => 'Test']);

        self::assertSame('<input type="checkbox" name="test" value="test_value" checked id="form_id" class="form_class">', (new FieldView($checkbox, 'test_value', [], 'form', '%s'))->checkbox($attrs));
        self::assertSame('<input type="checkbox" name="test" value="test_value" id="form_id" class="form_class">', (new FieldView($checkbox, '', [], 'form', '%s'))->checkbox($attrs));
        self::assertSame('<input type="checkbox" name="test" value="0" checked id="form_id" class="form_class">', (new FieldView($zeroCheckbox, '0', [], 'form', '%s'))->checkbox($attrs));
        self::assertSame('<input type="checkbox" name="test" value="0" id="form_id" class="form_class">', (new FieldView($zeroCheckbox, '', [], 'form', '%s'))->checkbox($attrs));

        $checked = static fn(string $current): array => array_map(
            static fn($option): bool => $option->checked,
            (new FieldView($radios, $current, [], 'form', '%s'))->options(),
        );

        self::assertSame([false, false, true], $checked('test_value'));
        self::assertSame([false, true, false], $checked('0'));
        // 旧 FW は value が '' の選択肢をチェックしなかった。新しい FW は '' の選択肢(「選ばない」)もほかと同じく比べる
        self::assertSame([true, false, false], $checked(''));
        self::assertSame([false, false, false], $checked('invalid_value'));
    }

    /**
     * 旧 FormChoiceTest の checkboxes。選択肢は「値 => ラベル」で、list の選択肢はキー(0, 1)が値になる。空値の hidden は
     * 出さない(キーの無い POST を '' と [] に取り込むため。FormRuntimeTest)。
     */
    public function testCheckboxesRenderEveryChoiceWithItsLabelAndCheckTheCurrentValues(): void
    {
        $choices = ['value_1' => 'label_1', 'value_2' => 'label_2'];
        $render = static fn(Field $field, mixed $value): string => (new FieldView($field, $value, [], 'form', '%s'))->checkboxes();
        $option = static fn(string $name, int $index, string $value, bool $checked, string $label): string => "<label for=\"form-{$name}-{$index}\"><input type=\"checkbox\" name=\"{$name}[]\" value=\"{$value}\"" . ($checked ? ' checked' : '') . " id=\"form-{$name}-{$index}\"> {$label}</label>";

        self::assertSame(
            $option('test_1', 0, 'value_1', false, 'label_1') . $option('test_1', 1, 'value_2', false, 'label_2'),
            $render(Field::checkboxes('test_1', 'Test', $choices), []),
        );
        self::assertSame(
            $option('test_2', 0, '0', false, 'value_3') . $option('test_2', 1, '1', false, 'value_4'),
            $render(Field::checkboxes('test_2', 'Test', ['value_3', 'value_4']), []),
        );
        self::assertSame(
            $option('test_3', 0, 'value_1', false, 'label_1') . $option('test_3', 1, 'value_2', false, 'label_2'),
            $render(Field::checkboxes('test_3', 'Test', $choices), ['invalid_value']),
        );
        self::assertSame(
            $option('test_4', 0, 'value_1', true, 'label_1') . $option('test_4', 1, 'value_2', false, 'label_2'),
            $render(Field::checkboxes('test_4', 'Test', $choices), ['value_1']),
        );
        self::assertSame(
            $option('test_5', 0, 'value_1', true, 'label_1') . $option('test_5', 1, 'value_2', true, 'label_2'),
            $render(Field::checkboxes('test_5', 'Test', $choices), ['value_1', 'value_2']),
        );
    }

    /** 旧 FormChoiceTest の radios。配列の値(複数の値)はどの選択肢もチェックしない */
    public function testRadiosRenderEveryChoiceAndCheckOnlyTheSingleCurrentValue(): void
    {
        $choices = ['value_1' => 'label_1', 'value_2' => 'label_2'];
        $render = static fn(mixed $value): string => (new FieldView(Field::radios('test', 'Test', $choices), $value, [], 'form', '%s'))->radios();
        $option = static fn(int $index, string $value, bool $checked, string $label): string => "<label for=\"form-test-{$index}\"><input type=\"radio\" name=\"test\" value=\"{$value}\"" . ($checked ? ' checked' : '') . " id=\"form-test-{$index}\"> {$label}</label>";

        self::assertSame($option(0, 'value_1', false, 'label_1') . $option(1, 'value_2', false, 'label_2'), $render(''));
        self::assertSame($option(0, 'value_1', false, 'label_1') . $option(1, 'value_2', false, 'label_2'), $render('invalid_value'));
        self::assertSame($option(0, 'value_1', true, 'label_1') . $option(1, 'value_2', false, 'label_2'), $render('value_1'));
        self::assertSame($option(0, 'value_1', false, 'label_1') . $option(1, 'value_2', false, 'label_2'), $render(['value_1', 'value_2']));
        self::assertSame(
            '<label for="form-test-0"><input type="radio" name="test" value="0" id="form-test-0"> value_3</label>',
            (new FieldView(Field::radios('test', 'Test', ['value_3']), '', [], 'form', '%s'))->radios(),
        );
    }

    /**
     * 旧 FormChoiceTest の custom_render(3層のコールバックの合成)は廃止した(DR-07)。同じ出力の手直しは、options() の
     * OptionView を1つずつ描画して行う。
     */
    public function testOptionsCanBeRenderedOneByOneWithCustomMarkup(): void
    {
        $view = new FieldView(Field::radios('test_1', 'Test', ['value_1' => 'label_1', 'value_2' => 'label_2']), 'value_2', [], 'form', '%s');
        $html = '';

        foreach ($view->options() as $option) {
            $html .= '<label>' . $option->input(['data-label' => $option->label]) . ' - ' . esc_html($option->label) . '</label>';
        }

        self::assertSame(
            '<label><input type="radio" name="test_1" value="value_1" id="form-test_1-0" data-label="label_1"> - label_1</label>'
            . '<label><input type="radio" name="test_1" value="value_2" checked id="form-test_1-1" data-label="label_2"> - label_2</label>',
            $html,
        );
    }

    /**
     * 利用者の入力の `<script>` が、取り込みから描画(再描画・hidden・確認画面の表示・エラー)のどこでもエスケープされる。
     * 実際の POST を handle() で取り込んだ値を描画する。
     */
    public function testUserInputContainingScriptIsEscapedInEveryOutput(): void
    {
        // フロントの要求。Formはhandle()・open()で、POSTで送る名前を公開のクエリ変数と照らす(照合はFormRuntimeTest)
        Functions\when('is_admin')->justReturn(false);
        $wp = Mockery::mock(WP::class);
        self::assertInstanceOf(WP::class, $wp);
        $wp->public_query_vars = [];
        $GLOBALS['wp'] = $wp;
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('nocache_headers')->justReturn();
        $script = '"><script>alert(1)</script>';
        $escaped = '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;';
        $form = new Form(FormDefinition::create('contact')
            ->text('name', 'Name')
            ->textarea('message', 'Message')
            ->hidden('ref')
            ->select('plan', 'Plan', ['basic' => 'Basic'])
            ->radios('color', 'Color', ['red' => 'Red'])
            ->checkboxes('topics', 'Topics', ['news' => 'News'])
            ->confirm());

        $result = $form->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => $script,
            'message' => "</textarea>{$script}\nline 2",
            'ref' => $script,
            'plan' => $script,
            'color' => $script,
            'topics' => [$script],
        ]));

        // 選択肢に無い値は項目のエラーになり、入力画面に再描画する
        self::assertSame(FormStep::Input, $result->step);
        self::assertSame($script, $result->values['name']);

        $outputs = [
            'input' => [$form->field('name')->input(), "<input type=\"text\" name=\"name\" value=\"{$escaped}\" id=\"contact-name\">"],
            'display' => [$form->field('name')->display(), $escaped],
            'hidden' => [$form->field('ref')->hidden(), "<input type=\"hidden\" name=\"ref\" value=\"{$escaped}\">"],
            'textarea' => [$form->field('message')->textarea(), "<textarea name=\"message\" id=\"contact-message\">&lt;/textarea&gt;{$escaped}\nline 2</textarea>"],
            'textarea display' => [$form->field('message')->display(), "&lt;/textarea&gt;{$escaped}<br>\nline 2"],
            'select' => [$form->field('plan')->select(), '<select name="plan" id="contact-plan"><option value="basic">Basic</option></select>'],
            'select display' => [$form->field('plan')->display(), $escaped],
            'radios' => [$form->field('color')->radios(), '<label for="contact-color-0"><input type="radio" name="color" value="red" id="contact-color-0"> Red</label>'],
            'checkboxes display' => [$form->field('topics')->display(), $escaped],
            'errors' => [$form->field('plan')->errors('<p>%s</p>'), '<p>Plan has an invalid choice.</p>'],
        ];

        foreach ($outputs as $name => [$actual, $expected]) {
            self::assertSame($expected, $actual, $name);
            self::assertStringNotContainsString('<script', $actual, $name);
        }
    }

    /**
     * 動的な選択肢(DB 等から作ったラベルと値)の `<script>`。select() の `<option>` のラベルは esc_html()、value は
     * esc_attr() を通る(どちらのエスケープを通ったかを、印を付けたスタブで確かめる)。
     */
    public function testDynamicChoicesOfSelectAreEscapedWithEscHtmlAndEscAttr(): void
    {
        Functions\when('esc_attr')->alias(static fn(string $value): string => 'A(' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . ')');
        Functions\when('esc_html')->alias(static fn(string $value): string => 'H(' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . ')');
        $choices = [
            '"><script>alert("value")</script>' => '</option><script>alert("label")</script>',
            'plain' => 'Plain & simple',
        ];
        $view = new FieldView(Field::select('category', 'Category', $choices), 'plain', [], 'form', '%s');

        self::assertSame(
            '<select name="A(category)" id="A(form-category)">'
            . '<option value="A(&quot;&gt;&lt;script&gt;alert(&quot;value&quot;)&lt;/script&gt;)">H(&lt;/option&gt;&lt;script&gt;alert(&quot;label&quot;)&lt;/script&gt;)</option>'
            . '<option value="A(plain)" selected>H(Plain &amp; simple)</option>'
            . '</select>',
            $view->select(),
        );
        self::assertSame('H(Plain &amp; simple)', $view->display());
    }
}
