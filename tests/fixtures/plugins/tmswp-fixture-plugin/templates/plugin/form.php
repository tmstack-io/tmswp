<?php

declare(strict_types=1);

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;

$form = new Form(FormDefinition::create('plugin_contact')->text('full_name', 'お名前'));
echo $form->field('full_name')->input(['class' => 'plugin-form-name']);
