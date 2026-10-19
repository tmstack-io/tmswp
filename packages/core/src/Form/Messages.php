<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use TMSWP\Core\Form\Rule\Rule;

final class Messages
{
    private function __construct() {}

    public static function resolve(Rule $rule, ValidationContext $context): string
    {
        $message = $rule->customMessage() ?? $rule->defaultMessage();

        if (\function_exists('apply_filters')) {
            $filtered = apply_filters('tmswp/form/rule_message', $message, $rule, $context);

            if (\is_string($filtered)) {
                $message = $filtered;
            }
        }

        $params = ['label' => $context->label($context->currentField), ...$rule->messageParams()];
        $replace = [];

        foreach ($params as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }

        return strtr($message, $replace);
    }
}
