<?php

declare(strict_types=1);

namespace Apps\Iam\Actions;

use Apps\Iam\Models\User;
use Illuminate\Support\Facades\DB;

final class RegisterUser
{
    public function execute(string $name): User
    {
        return DB::transaction(fn (): User => User::query()->create(['name' => $name]));
    }
}
