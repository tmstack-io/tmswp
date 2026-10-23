<?php

declare(strict_types=1);

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;

$form = new Form(FormDefinition::create('plugin_contact')->text('name', 'お名前'));
echo $form->field('name')->input(['class' => 'plugin-form-name']);
