<?php

declare(strict_types=1);

namespace SampleSite\Tests\Unit;

use SampleSite\Form\ContactForm;
use TMSWP\Testing\UnitTestCase;

final class ContactFormTest extends UnitTestCase
{
    public function testFormHasContactFieldsAndConfirmScreen(): void
    {
        $form = ContactForm::create();

        self::assertSame(['full_name', 'kana', 'email', 'message'], array_keys($form->fields()));
        self::assertTrue($form->isConfirmEnabled());
    }
}
