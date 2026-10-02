<?php

declare(strict_types=1);

namespace Apps\Iam\Database\Factories;

use Apps\Iam\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => 'ada'];
    }
}
