<?php

declare(strict_types=1);

$form = $args['form'];
if (!$form instanceof TMSWP\Core\Form\Form) {
    throw new RuntimeException('Form instance is required.');
}

echo $form->open();
echo $form->field('full_name')->input();
echo $form->close();
