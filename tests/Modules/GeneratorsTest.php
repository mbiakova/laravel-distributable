<?php

declare(strict_types=1);

use Distributable\Exceptions\ModuleException;
use Distributable\Services\Modules\ModuleRegistry;
use Distributable\Tests\Support\ModuleAppTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/distributable-generators-'.uniqid();
    File::ensureDirectoryExists($this->root.'/apps/Billing/app/Providers');
    config()->set('distributable.modules', ['billing' => []]);
    config()->set('distributable.paths.modules', $this->root.'/apps');
    $this->app->forgetInstance(ModuleRegistry::class);
    $this->module = $this->root.'/apps/Billing';
});

afterEach(fn () => File::deleteDirectory($this->root));

it('generates a class in the module and under its namespace with --module, as the plain command does in app/', function () {
    $this->artisan('make:controller InvoiceController --module=billing')->assertSuccessful();
    $this->artisan('make:request StoreInvoiceRequest --module=billing')->assertSuccessful();
    $this->artisan('make:command SendInvoices --module=billing')->assertSuccessful();

    expect(file_get_contents($this->module.'/app/Http/Controllers/InvoiceController.php'))->toContain('namespace Apps\Billing\Http\Controllers;')
        ->and(file_get_contents($this->module.'/app/Http/Requests/StoreInvoiceRequest.php'))->toContain('namespace Apps\Billing\Http\Requests;')
        ->and(file_get_contents($this->module.'/app/Console/Commands/SendInvoices.php'))->toContain('namespace Apps\Billing\Console\Commands;');
});

it('generates a model with its migration and its factory in the module, the factory under the module namespace', function () {
    $this->artisan('make:model Invoice -mf --module=billing')->assertSuccessful();

    expect(file_get_contents($this->module.'/app/Models/Invoice.php'))->toContain('namespace Apps\Billing\Models;')
        ->and(file_get_contents($this->module.'/database/factories/InvoiceFactory.php'))
        ->toContain('namespace Apps\Billing\Database\Factories;')
        ->toContain('use Apps\Billing\Models\Invoice;')
        ->and(glob($this->module.'/database/migrations/*_create_invoices_table.php'))->toHaveCount(1);
});

it('generates a seeder, a migration and a config file in the module', function () {
    $this->artisan('make:seeder InvoiceSeeder --module=billing')->assertSuccessful();
    $this->artisan('make:migration add_paid_at_to_invoices --module=billing')->assertSuccessful();
    $this->artisan('make:config billing --module=billing')->assertSuccessful();

    expect(file_get_contents($this->module.'/database/seeders/InvoiceSeeder.php'))->toContain('namespace Apps\Billing\Database\Seeders;')
        ->and(glob($this->module.'/database/migrations/*_add_paid_at_to_invoices.php'))->toHaveCount(1)
        ->and(is_file($this->module.'/config/billing.php'))->toBeTrue();
});

it('generates a view in the module, and names it with the module in the class that renders it', function () {
    $this->artisan('make:view invoices.index --module=billing')->assertSuccessful();
    $this->artisan('make:component Alert --module=billing')->assertSuccessful();
    $this->artisan('make:mail InvoiceSent --markdown=mail.invoice-sent --module=billing')->assertSuccessful();

    expect(is_file($this->module.'/resources/views/invoices/index.blade.php'))->toBeTrue()
        ->and(is_file($this->module.'/resources/views/components/alert.blade.php'))->toBeTrue()
        ->and(file_get_contents($this->module.'/app/View/Components/Alert.php'))
        ->toContain('namespace Apps\Billing\View\Components;')
        ->toContain("view('billing::components.alert')")
        ->and(is_file($this->module.'/resources/views/mail/invoice-sent.blade.php'))->toBeTrue()
        ->and(file_get_contents($this->module.'/app/Mail/InvoiceSent.php'))->toContain("'billing::mail.invoice-sent'");
});

it('generates a test in the module tests, under the module namespace, and leaves none in the application tests', function () {
    $this->artisan('make:test InvoiceTest --phpunit --module=billing')->assertSuccessful();
    $this->artisan('make:test TotalTest --unit --phpunit --module=billing')->assertSuccessful();

    expect(file_get_contents($this->module.'/tests/Feature/InvoiceTest.php'))->toContain('namespace Apps\Billing\Tests\Feature;')
        ->and(file_get_contents($this->module.'/tests/Unit/TotalTest.php'))->toContain('namespace Apps\Billing\Tests\Unit;')
        ->and(is_file(base_path('tests/Feature/InvoiceTest.php')))->toBeFalse()
        ->and(is_file(base_path('tests/Unit/TotalTest.php')))->toBeFalse();
});

it('gives the application its paths and its namespace back once the command ends', function () {
    $paths = [app_path(), database_path(), config_path(), config('view.paths'), $this->app->getNamespace()];

    $this->artisan('make:model Invoice -mf --module=billing')->assertSuccessful();

    expect([app_path(), database_path(), config_path(), config('view.paths'), $this->app->getNamespace()])->toBe($paths);
});

it('offers --module on every generator but make:provider, a module having one provider', function () {
    $offers = fn (string $command): bool => Artisan::all()[$command]->getDefinition()->hasOption('module');

    expect($offers('make:model'))->toBeTrue()
        ->and($offers('make:migration'))->toBeTrue()
        ->and($offers('make:factory'))->toBeTrue()
        ->and($offers('make:test'))->toBeTrue()
        ->and($offers('make:view'))->toBeTrue()
        ->and($offers('make:component'))->toBeTrue()
        ->and($offers('make:provider'))->toBeFalse();
});

it('refuses a module nobody declared', function () {
    $this->artisan('make:model Invoice --module=ghost');
})->throws(ModuleException::class, 'Unknown module [ghost]');
