<?php

declare(strict_types=1);

namespace Apps\Analytics\Models;

use Foundation\Iam\Shadows\UserShadow as IamUserShadow;

/** Analytics keeps its own copy of iam's users, in its own database. */
final class UserShadow extends IamUserShadow {}
