<?php

declare(strict_types=1);

/*
 * サンプルのブロックの動的描画。WordPressがblock.jsonのrenderから読み込み、$attributes・$content・$blockを渡す。
 */
?>
<p <?php echo get_block_wrapper_attributes(); ?>><?php echo esc_html__('This is an example block.', '{{project_slug}}'); ?></p>
