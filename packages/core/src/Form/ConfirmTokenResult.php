<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

final readonly class ConfirmTokenResult
{
    /**
     * @param array<string, string|list<string>|array{__tmsfile: string}> $values
     */
    public function __construct(
        public ConfirmTokenStatus $status,
        public array $values = [],
        public string $jti = '',
        public int $issuedAt = 0,
    ) {}
}
