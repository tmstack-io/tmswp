<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

enum ConfirmTokenStatus
{
    case Valid;
    case Expired;
    case Invalid;
}
