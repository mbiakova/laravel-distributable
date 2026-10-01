<?php

declare(strict_types=1);

use Apps\Analytics\Models\UserShadow;
use Apps\Iam\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    $this->artisan('migrate')->assertSuccessful();
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
    $user = $this->inModule('iam', fn () => User::query()->create(['name' => 'ada']));
    $this->artisan('modulith:events:consume --module=analytics')->assertSuccessful();

    expect(copies()->where('id', $user->id)->value('name'))->toBe('ada');

    $user->update(['name' => 'grace']);
    $this->artisan('modulith:events:consume --module=analytics')->assertSuccessful();

    expect(copies()->where('id', $user->id)->value('name'))->toBe('grace');

    $user->delete();
    $this->artisan('modulith:events:consume --module=analytics')->assertSuccessful();

    expect(copies()->where('id', $user->id)->value('deleted_at'))->not->toBeNull();
});

it('leaves the copies of analytics to the analytics consumer, when one process runs both modules', function () {
    $user = $this->inModule('iam', fn () => User::query()->create(['name' => 'ada']));

    $this->artisan('modulith:events:consume --module=iam')->assertSuccessful();

    expect(copies()->count())->toBe(0);

    $this->artisan('modulith:events:consume --module=analytics')->assertSuccessful();

    expect(copies()->where('id', $user->id)->value('name'))->toBe('ada');
});

it('refuses any write to a copy that does not come from the source', function () {
    expect((new UserShadow)->forceFill(['id' => 1, 'name' => 'forged'])->save())->toBeFalse()
        ->and(copies()->count())->toBe(0);
});

it('leaves a copy alone when the announcement is addressed to another keeper', function () {
    $user = $this->inModule('iam', fn () => User::query()->create(['name' => 'ada']));
    $this->artisan('modulith:events:consume --module=analytics')->assertSuccessful();
    DB::connection('iam')->table('iam_users')->where('id', $user->id)->update(['name' => 'grace']);

    $this->artisan('modulith:shadows:announce iam_users --keepers=gateway')->assertSuccessful();
    $this->artisan('modulith:events:consume --module=analytics')->assertSuccessful();

    expect(copies()->where('id', $user->id)->value('name'))->toBe('ada');

    $this->artisan('modulith:shadows:announce iam_users --keepers=analytics')->assertSuccessful();
    $this->artisan('modulith:events:consume --module=analytics')->assertSuccessful();

    expect(copies()->where('id', $user->id)->value('name'))->toBe('grace');
});

it('rebuilds a copy on demand: the keeper asks, the owner announces again', function () {
    $user = $this->inModule('iam', fn () => User::query()->create(['name' => 'ada']));
    copies()->delete();

    $this->artisan('modulith:shadows:want')->assertSuccessful();
    $this->artisan('modulith:events:consume --module=iam')->assertSuccessful();
    $this->artisan('modulith:events:consume --module=analytics')->assertSuccessful();

    expect(copies()->where('id', $user->id)->value('name'))->toBe('ada');
});

it('asks only for the keepers and sources named', function () {
    $this->artisan('modulith:shadows:want --keepers=gateway')->doesntExpectOutputToContain('wants')->assertSuccessful();

    $this->artisan('modulith:shadows:want --keepers=analytics --sources=iam_users')
        ->expectsOutput('→ analytics wants iam_users')
        ->assertSuccessful();

    $this->artisan('modulith:shadows:want --sources=other_table')->doesntExpectOutputToContain('wants')->assertSuccessful();
});
