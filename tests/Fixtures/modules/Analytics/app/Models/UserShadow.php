<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use Modules\Iam\Shadows\UserShadow as IamUserShadow;

/** Analytics keeps its own copy of iam's users, in its own database. */
final class UserShadow extends IamUserShadow {}
