<?php

declare(strict_types=1);

namespace Distributable\Tests\Support;

use Distributable\Testing\InteractsWithModules;
use Distributable\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

/** Boots the app against the fixture module tree, as a consumer application would. */
abstract class ModuleAppTestCase extends TestCase
{
    use InteractsWithModules;

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);

        $config->set('distributable.modules', [
            'analytics' => [],
            'gateway' => [],
            'iam' => ['host' => 'http://iam.test'],
        ]);
        $config->set('distributable.paths.modules', dirname(__DIR__).'/Fixtures/apps');
        $config->set('distributable.paths.foundation', dirname(__DIR__).'/Fixtures/foundation');
        $config->set('distributable.status_route', '/');

        // The group iam's routes/admin.php lands in.
        $app->make('router')->middlewareGroup('admin', []);

        $config->set('microservices.events.streams.default.driver', 'array');
        $config->set('microservices.rpc.secret', 'test-secret');

        // Root config the iam module's config/iam.php fragment merges over.
        $config->set('iam', [
            'items' => ['from-root'],
            'nested' => ['kept' => 'root', 'override' => 'root'],
            'codes' => [403 => 'root forbidden', 404 => 'root not found'],
        ]);

    }
}
