<?php

declare(strict_types=1);

namespace Distributable\Support;

use Distributable\Services\Modules\ModuleRegistry;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/** A module's factories are {Namespace}\Database\Factories, in {module}/database/factories; a class outside a module keeps Laravel's rule. */
final class ModuleFactories
{
    public static function register(): void
    {
        Factory::guessFactoryNamesUsing(self::factoryName(...));
        Factory::guessModelNamesUsing(self::modelName(...));
    }

    /**
     * @param  class-string<Model>  $model
     * @return class-string<Factory<Model>>
     */
    public static function factoryName(string $model): string
    {
        [$root, $factories] = self::roots($model);

        $relative = Str::startsWith($model, $root.'Models\\') ? Str::after($model, $root.'Models\\') : Str::after($model, $root);

        /** @var class-string<Factory<Model>> */
        return $factories.$relative.'Factory';
    }

    /**
     * @param  Factory<Model>  $factory
     * @return class-string<Model>
     */
    public static function modelName(Factory $factory): string
    {
        [$root, $factories] = self::roots($factory::class);

        $relative = Str::replaceLast('Factory', '', Str::replaceFirst($factories, '', $factory::class));

        /** @var class-string<Model> */
        return class_exists($root.'Models\\'.$relative) ? $root.'Models\\'.$relative : $root.class_basename($relative);
    }

    /** @return array{string, string} the namespace the class is rooted in, and the namespace of its factories */
    private static function roots(string $class): array
    {
        $module = Container::getInstance()->make(ModuleRegistry::class)->forClass($class);

        if ($module !== null) {
            return [$module->namespace.'\\', $module->namespace.'\\Database\\Factories\\'];
        }

        try {
            $application = Container::getInstance()->make(Application::class)->getNamespace();
        } catch (Throwable) {
            $application = 'App\\';
        }

        return [$application, Factory::$namespace];
    }
}
