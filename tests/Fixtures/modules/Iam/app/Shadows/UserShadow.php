<?php

declare(strict_types=1);

namespace Modules\Iam\Shadows;

use Modules\Iam\Models\User;
use Modulith\Models\ShadowModel;

/** The shape of a copy of iam's users, declared once by iam for every module that keeps one. */
abstract class UserShadow extends ShadowModel
{
    public static function source(): string
    {
        return User::class;
    }
}
