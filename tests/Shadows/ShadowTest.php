<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Analytics\Models\UserShadow;
use Modules\Iam\Models\User;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    // One file per module, shared by its runtime and owner connections, as in production.
    foreach (['iam', 'analytics'] as $module) {
        $file = tempnam(sys_get_temp_dir(), "modulith-{$module}-");

        foreach ([$module, "{$module}_owner"] as $connection) {
            config()->set("database.connections.{$connection}.database", $file);
            DB::purge($connection);
        }
    }

    $this->artisan('modulith:migrate')->assertSuccessful();
});

function copies(): Builder
{
    return DB::connection('analytics')->table('analytics_iam_users');
}

it('migrates the copy into the keeper database, named after the keeper', function () {
    expect(Schema::connection('analytics')->hasTable('analytics_iam_users'))->toBeTrue()
        ->and(Schema::connection('iam')->hasTable('analytics_iam_users'))->toBeFalse();
});

it('keeps the copy in step with the source, deletion included', function () {
    $user = User::query()->create(['name' => 'ada']);
    $this->artisan('modulith:events:consume --module=analytics')->assertSuccessful();

    expect(copies()->where('id', $user->id)->value('name'))->toBe('ada');

    $user->update(['name' => 'grace']);
    $this->artisan('modulith:events:consume --module=analytics')->assertSuccessful();

    expect(copies()->where('id', $user->id)->value('name'))->toBe('grace');

    $user->delete();
    $this->artisan('modulith:events:consume --module=analytics')->assertSuccessful();

    expect(copies()->where('id', $user->id)->value('deleted_at'))->not->toBeNull();
});

it('refuses any write to a copy that does not come from the source', function () {
    expect((new UserShadow)->forceFill(['id' => 1, 'name' => 'forged'])->save())->toBeFalse()
        ->and(copies()->count())->toBe(0);
});

it('rebuilds a copy on demand: the keeper asks, the owner announces again', function () {
    $user = User::query()->create(['name' => 'ada']);
    copies()->delete();

    $this->artisan('modulith:shadows:want')->assertSuccessful();
    $this->artisan('modulith:events:consume --module=iam')->assertSuccessful();
    $this->artisan('modulith:events:consume --module=analytics')->assertSuccessful();

    expect(copies()->where('id', $user->id)->value('name'))->toBe('ada');
});
