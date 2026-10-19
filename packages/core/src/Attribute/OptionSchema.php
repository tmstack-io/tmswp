<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Meta\MetaValueType;

#[Attribute]
final readonly class OptionSchema
{
    public function __construct(
        public MetaValueType $type = MetaValueType::String,
        public mixed $default = null,
        public bool $autoload = true,
    ) {}
}
