<?php

declare(strict_types=1);

namespace SampleSite\SettingsPage;

use SampleSite\Option\SiteOption;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\SettingsPage\Attribute\AsSettingsPage;
use TMSWP\SettingsPage\OptionFormBridge;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPageDefinition;

/**
 * 管理画面の「設定 > Sample Site」。SiteOptionの値を検証して保存する。
 * フォームの開始タグ・nonce・保存ボタン・エラーの通知はsettings-pageが出すため、render()は項目だけを出す。
 */
#[AsSettingsPage]
final class SiteSettingsPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(
            slug: 'sample-site-settings',
            pageTitle: __('Sample Site settings', 'sample-site'),
            menuTitle: __('Sample Site', 'sample-site'),
            parent: 'options-general.php',
        );
    }

    protected function createForm(): Form
    {
        $form = new Form(
            FormDefinition::create('sample_site_settings')
                ->text('contact_email', __('Notification email for inquiries', 'sample-site'), [new Email(), new MaxLength(254)])
                ->textarea('lead_text', __('Lead text on the front page', 'sample-site'), [new MaxLength(200)]),
        );
        // 保存済みの値(無ければスキーマの既定値)を、フォームの初期値にする
        OptionFormBridge::applyDefaults($form, SiteOption::class);

        return $form;
    }

    protected function onSubmit(FormResult $result): void
    {
        OptionFormBridge::persist($result, SiteOption::class);
    }

    protected function render(?Form $form): void
    {
        if ($form === null) {
            return;
        }

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . $form->field('contact_email')->labelTag() . '</th><td>';
        echo $form->field('contact_email')->input(['type' => 'email', 'class' => 'regular-text']);
        echo $form->field('contact_email')->errors();
        echo '<p class="description">' . esc_html__('Leave empty to send to the administration email address.', 'sample-site') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row">' . $form->field('lead_text')->labelTag() . '</th><td>';
        echo $form->field('lead_text')->textarea(['class' => 'large-text', 'rows' => 3]);
        echo $form->field('lead_text')->errors();
        echo '</td></tr>';
        echo '</tbody></table>';
    }
}
