<?php

declare(strict_types=1);

use Distributable\Exceptions\ModuleException;
use Distributable\Services\Modules\ModuleRegistry;
use Distributable\Tests\Support\WithoutIamTestCase;
use Foundation\Iam\Contracts\IamService;
use Foundation\Iam\Services\IamRpcService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(WithoutIamTestCase::class);

it('does not register a module this process does not boot', function () {
    $this->get('/iam/api/v1/ping')->assertNotFound();

    expect(config('iam.flag'))->toBeNull();
});

it('binds the foundation RpcService of a module running elsewhere, and caches its reads', function () {
    Http::fake(['iam.test/*' => Http::response(['id' => 1, 'name' => 'ada'])]);

    $iam = app(IamService::class);

    expect($iam)->toBeInstanceOf(IamRpcService::class)
        ->and($iam->findUser(1))->toBe(['id' => 1, 'name' => 'ada'])
        ->and($iam->findUser(1))->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertSentCount(1);
});

it('refuses to load a class of a module running elsewhere, whatever is on disk', function () {
    class_exists('Apps\Iam\Support\AnyClass');
})->throws(ModuleException::class, 'belongs to module [iam], which this process does not run');

it('deletes the folder of the modules this process does not run, which config still declares', function () {
    $root = sys_get_temp_dir().'/distributable-purge-'.uniqid();
    File::copyDirectory(dirname(__DIR__).'/Fixtures/apps', $root);
    config()->set('distributable.paths.modules', $root);
    $this->app->forgetInstance(ModuleRegistry::class);

    // The purge deletes files: it must only ever see the copy.
    expect($this->app->make(ModuleRegistry::class)->get('iam')->path())->toBe($root.'/Iam');

    try {
        $this->artisan('distributable:purge --force')->expectsOutput('→ analytics purged')->expectsOutput('→ iam purged')->assertSuccessful();

        $this->app->forgetInstance(ModuleRegistry::class);

        expect(is_dir($root.'/Iam'))->toBeFalse()
            ->and(is_dir($root.'/Analytics'))->toBeFalse()
            ->and(is_dir($root.'/Gateway'))->toBeTrue()
            ->and($this->app->make(ModuleRegistry::class)->find('iam'))->not->toBeNull();
    } finally {
        File::deleteDirectory($root);
    }
});

it('lists the packages only the modules running elsewhere require', function () {
    $root = sys_get_temp_dir().'/distributable-packages-'.uniqid();
    File::copyDirectory(dirname(__DIR__).'/Fixtures/apps', $root);
    config()->set('distributable.paths.modules', $root);
    $this->app->forgetInstance(ModuleRegistry::class);

    File::put($root.'/Iam/composer.json', json_encode(['require' => ['php' => '^8.4', 'ext-json' => '*', 'acme/only-iam' => '^1.0', 'acme/shared' => '^1.0']]));
    File::put($root.'/Gateway/composer.json', json_encode(['require' => ['acme/shared' => '^1.0', 'acme/only-gateway' => '^1.0']]));

    try {
        $this->artisan('distributable:unused-packages')->expectsOutput('acme/only-iam')->assertSuccessful();
    } finally {
        File::deleteDirectory($root);
    }
});
