<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

final class Url extends Rule
{
    /** @param list<string> $protocols */
    public function __construct(private readonly array $protocols = ['http', 'https'], ?string $message = null)
    {
        if ($protocols === []) {
            throw new FormConfigException('Url protocols must not be empty');
        }

        parent::__construct($message);
    }

    protected function passes(mixed $value, ValidationContext $context): bool
    {
        $url = Validator::stringValue($value);

        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return \is_string($scheme) && \in_array(strtolower($scheme), $this->protocols, true);
    }

    public function defaultMessage(): string
    {
        return $this->translate('Please enter a valid URL for {label}.');
    }
}
