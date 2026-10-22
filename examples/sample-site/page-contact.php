<?php
/**
 * Template Name: お問い合わせ
 */

declare(strict_types=1);

use App\Option\SiteOption;
use App\Page\ContactPage;
use App\Post\ProductPost;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormAction;
use TMSWP\Core\Form\FormStep;

// 出力の前(get_header()より前)に1回だけ呼ぶ
$vm = ContactPage::handle();
$form = $vm->form;

get_header();
?>
<main class="site-main">
    <h1><?php echo esc_html(get_the_title()); ?></h1>
    <p>通知先: <?php echo esc_html(SiteOption::contactEmail()); ?></p>
    <p>製品一覧: <a href="<?php echo esc_url((string) ProductPost::archiveUrl()); ?>">アーカイブへ</a></p>

    <?php foreach ($form->formErrors() as $error) : ?>
        <p class="form-error"><?php echo esc_html($error); ?></p>
    <?php endforeach; ?>

    <?php if ($vm->result->step === FormStep::Complete) : ?>
        <p class="notice">お問い合わせを受け付けました。</p>
    <?php elseif ($vm->result->step === FormStep::Confirm) : ?>
        <?php echo $form->open(['class' => 'contact-form']); ?>
        <dl class="confirm-list">
            <?php foreach (['customer_name', 'email', 'message', 'attachment'] as $fieldName) : ?>
                <div>
                    <dt><?php echo esc_html($form->field($fieldName)->label()); ?></dt>
                    <dd><?php echo $form->field($fieldName)->display(); ?></dd>
                    <?php echo $form->field($fieldName)->hidden(); ?>
                </div>
            <?php endforeach; ?>
        </dl>
        <button type="submit" name="<?php echo esc_attr(Form::FIELD_STEP); ?>" value="<?php echo esc_attr(FormAction::Back->value); ?>">戻る</button>
        <button type="submit" name="<?php echo esc_attr(Form::FIELD_STEP); ?>" value="<?php echo esc_attr(FormAction::Submit->value); ?>">送信する</button>
        <?php echo $form->close(); ?>
    <?php else : ?>
        <?php echo $form->open(['class' => 'contact-form']); ?>
        <div class="form-row">
            <?php echo $form->field('customer_name')->labelTag(); ?>
            <?php echo $form->field('customer_name')->input(); ?>
            <?php echo $form->field('customer_name')->errors(); ?>
        </div>
        <div class="form-row">
            <?php echo $form->field('email')->labelTag(); ?>
            <?php echo $form->field('email')->input(['type' => 'email']); ?>
            <?php echo $form->field('email')->errors(); ?>
        </div>
        <div class="form-row">
            <?php echo $form->field('message')->labelTag(); ?>
            <?php echo $form->field('message')->textarea(['rows' => 6]); ?>
            <?php echo $form->field('message')->errors(); ?>
        </div>
        <div class="form-row">
            <?php echo $form->field('attachment')->labelTag(); ?>
            <?php echo $form->field('attachment')->input(['accept' => '.txt']); ?>
            <?php echo $form->field('attachment')->errors(); ?>
        </div>
        <button type="submit" name="<?php echo esc_attr(Form::FIELD_STEP); ?>" value="<?php echo esc_attr(FormAction::Confirm->value); ?>">確認する</button>
        <?php echo $form->close(); ?>
    <?php endif; ?>
</main>
<?php
get_footer();
