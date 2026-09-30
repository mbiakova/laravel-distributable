<?php

declare(strict_types=1);

namespace Apps\Iam\Models;

use Illuminate\Database\Eloquent\Model;
use Modulith\Contracts\Shadows\Shadowed;
use Modulith\Traits\ShadowSource;

final class User extends Model implements Shadowed
{
    use ShadowSource;

    protected $table = 'iam_users';

    protected $guarded = ['id'];

    /** @var list<string> the fields other modules' copies carry */
    protected array $shadowed = ['name'];
}
