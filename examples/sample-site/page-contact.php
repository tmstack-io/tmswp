<?php

declare(strict_types=1);

/*
 * 固定ページcontact(/contact/)のテンプレート。入力画面と確認画面を出す。完了画面は子ページthanks(/contact/thanks/)。
 */

use SampleSite\Page\ContactPage;
use TMSWP\Core\Form\FormStep;

// 出力の前(get_header()より前)に1回だけ呼ぶ。リダイレクトとnocacheのヘッダは出力の後では効かない
$vm = ContactPage::handle();
$form = $vm->form;

get_header();
?>
<main class="contact">
    <h1><?php the_title(); ?></h1>

    <?php if ($vm->result->step === FormStep::Confirm) : ?>
        <p><?php esc_html_e('Please check your entries and press Send.', 'sample-site'); ?></p>
        <?php echo $form->open(['class' => 'contact-form']); ?>
        <dl>
            <?php foreach ($form->confirmFields() as $view) : ?>
                <dt><?php echo $view->label(); ?></dt>
                <dd><?php echo $view->display(); ?></dd>
            <?php endforeach; ?>
        </dl>
        <button type="submit" name="_step" value="back"><?php esc_html_e('Back', 'sample-site'); ?></button>
        <button type="submit" name="_step" value="submit"><?php esc_html_e('Send', 'sample-site'); ?></button>
        <?php echo $form->close(); ?>
    <?php else : ?>
        <?php foreach ($vm->result->formErrors as $error) : ?>
            <p class="form-alert"><?php echo esc_html($error); ?></p>
        <?php endforeach; ?>

        <?php echo $form->open(['class' => 'contact-form']); ?>
        <dl>
            <dt><?php echo $form->field('full_name')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('full_name')->input(['autocomplete' => 'name']); ?>
                <?php echo $form->field('full_name')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('kana')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('kana')->input(); ?>
                <?php echo $form->field('kana')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('email')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('email')->input(['type' => 'email', 'autocomplete' => 'email']); ?>
                <?php echo $form->field('email')->errors(); ?>
            </dd>

            <dt><?php echo $form->field('message')->labelTag(); ?></dt>
            <dd>
                <?php echo $form->field('message')->textarea(['rows' => 6]); ?>
                <?php echo $form->field('message')->errors(); ?>
            </dd>
        </dl>
        <button type="submit" name="_step" value="confirm"><?php esc_html_e('Confirm', 'sample-site'); ?></button>
        <?php echo $form->close(); ?>
    <?php endif; ?>
</main>
<?php
get_footer();
