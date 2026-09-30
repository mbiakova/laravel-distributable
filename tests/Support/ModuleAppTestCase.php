<?php

declare(strict_types=1);

namespace Modulith\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Modulith\Tests\TestCase;

/** Boots the app against the fixture module tree, as a consumer application would. */
abstract class ModuleAppTestCase extends TestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);

        $config->set('modulith.modules_path', dirname(__DIR__).'/Fixtures/modules');
        $config->set('modulith.status_route', '/');

        $config->set('streamer.transport', 'array');
        $config->set('rpc.secret', 'test-secret');
        $config->set('rpc.hosts', ['iam' => 'http://iam.test']);

        // Root config the iam module's config/iam.php fragment merges over.
        $config->set('iam', [
            'items' => ['from-root'],
            'nested' => ['kept' => 'root', 'override' => 'root'],
        ]);

    }
}
