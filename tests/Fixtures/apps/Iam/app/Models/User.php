<?php

declare(strict_types=1);

namespace Apps\Iam\Models;

use Apps\Iam\Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Microservices\Contracts\Shadows\Shadowed;
use Microservices\Traits\ShadowSource;

final class User extends Model implements Shadowed
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use ShadowSource;

    protected $table = 'iam_users';

    protected $guarded = ['id'];

    /** @var list<string> the fields other modules' copies carry */
    protected array $shadowed = ['name'];
}
