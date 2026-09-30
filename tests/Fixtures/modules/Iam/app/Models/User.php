<?php

declare(strict_types=1);

namespace Modules\Iam\Models;

use Modulith\Contracts\Shadowed;
use Modulith\Models\Model;
use Modulith\Traits\ShadowSource;

final class User extends Model implements Shadowed
{
    use ShadowSource;

    /** @var list<string> the fields other modules' copies carry */
    protected array $shadowed = ['name'];
}
