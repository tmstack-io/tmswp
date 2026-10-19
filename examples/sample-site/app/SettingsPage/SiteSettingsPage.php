<?php

declare(strict_types=1);

namespace App\SettingsPage;

use App\Option\SiteOption;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormAction;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\SettingsPage\Attribute\AsSettingsPage;
use TMSWP\SettingsPage\OptionFormBridge;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPageDefinition;

#[AsSettingsPage]
final class SiteSettingsPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(
            slug: 'sample-site-settings',
            pageTitle: 'サンプルサイト設定',
            menuTitle: 'サンプル設定',
            icon: 'dashicons-admin-generic',
        );
    }

    protected function createForm(): Form
    {
        $form = new Form(
            FormDefinition::create('sample_settings')
                ->text('contact_email', '通知先メールアドレス', [new Required(), new Email()])
                ->textarea('lead_text', 'トップページ説明文', [new Required()])
        );

        OptionFormBridge::applyDefaults($form, SiteOption::class);

        return $form;
    }

    protected function onSubmit(FormResult $result): void
    {
        OptionFormBridge::persist($result, SiteOption::class);
    }

    protected function render(?Form $form): void
    {
        if (!$form instanceof Form) {
            return;
        }

        echo $form->open();
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . $form->field('contact_email')->labelTag() . '</th><td>';
        echo $form->field('contact_email')->input(['type' => 'email', 'class' => 'regular-text']);
        echo $form->field('contact_email')->errors();
        echo '</td></tr>';
        echo '<tr><th scope="row">' . $form->field('lead_text')->labelTag() . '</th><td>';
        echo $form->field('lead_text')->textarea(['class' => 'large-text', 'rows' => 4]);
        echo $form->field('lead_text')->errors();
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '<input type="hidden" name="' . esc_attr(Form::FIELD_STEP) . '" value="' . esc_attr(FormAction::Submit->value) . '">';
        submit_button('保存');
        echo $form->close();
    }
}
