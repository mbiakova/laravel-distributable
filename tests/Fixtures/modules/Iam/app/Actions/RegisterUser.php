<?php

declare(strict_types=1);

namespace Modules\Iam\Actions;

use Modules\Iam\Models\User;
use Modulith\Traits\TransactsOnModule;

final class RegisterUser
{
    use TransactsOnModule;

    public function execute(string $name): User
    {
        return $this->transaction(fn (): User => User::query()->create(['name' => $name]));
    }
}
