<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Form\ContactForm;
use PHPUnit\Framework\TestCase;

final class ContactFormTest extends TestCase
{
    public function testCreateBuildsContactForm(): void
    {
        $form = ContactForm::create();

        self::assertSame('contact', $form->id());
        self::assertSame(['customer_name', 'email', 'message', 'attachment'], array_keys($form->fields()));
    }
}
