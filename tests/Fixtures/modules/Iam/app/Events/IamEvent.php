<?php

declare(strict_types=1);

namespace Modules\Iam\Events;

enum IamEvent: string
{
    case UserRegistered = 'iam.user.registered';
}
