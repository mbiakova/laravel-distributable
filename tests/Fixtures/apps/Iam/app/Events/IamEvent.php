<?php

declare(strict_types=1);

namespace Apps\Iam\Events;

enum IamEvent: string
{
    case UserRegistered = 'iam.user.registered';
}
