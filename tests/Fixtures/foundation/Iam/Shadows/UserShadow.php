<?php

declare(strict_types=1);

namespace Foundation\Iam\Shadows;

use Modulith\Models\ShadowModel;

/** The shape of a copy of iam's users, declared once by iam for every module that keeps one. */
abstract class UserShadow extends ShadowModel
{
    public static function owner(): string
    {
        return 'iam';
    }

    public static function sourceTable(): string
    {
        return 'iam_users';
    }
}
