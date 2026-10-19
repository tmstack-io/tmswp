<?php

declare(strict_types=1);

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Required;

$form = new Form(FormDefinition::create('contact')->text('name', 'お名前', [new Required()]));

return ['contactForm' => $form];
