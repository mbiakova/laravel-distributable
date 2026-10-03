# Read-only copies (shadows)

Copies of another module's rows are [laravel-microservices' copies](https://github.com/mk-josias/laravel-microservices/blob/main/docs/shadows.md):
each module is a service. What a module adds is where each piece lives, and which database the
copy is in.

```php
// apps/Iam/app/Models/User.php, the source: the $shadowed fields are copied
final class User extends \Illuminate\Database\Eloquent\Model implements \Microservices\Contracts\Shadows\Shadowed
{
    use \Microservices\Traits\ShadowSource;

    protected $table = 'iam_users';

    protected array $shadowed = ['name'];
}

// foundation/Iam/Shadows/UserShadow.php, the shape of the copy, declared once by iam
abstract class UserShadow extends \Microservices\Models\ShadowModel
{
    public static function owner(): string { return 'iam'; }

    public static function sourceTable(): string { return 'iam_users'; }
}

// apps/Analytics/app/Models/UserShadow.php, analytics keeps a copy in the analytics_iam_users table
final class UserShadow extends \Foundation\Iam\Shadows\UserShadow {}
```

iam publishes the migration of the copy's table in `foundation/Iam/database/shadows/`, extending
`Microservices\Migrations\ShadowMigration`. `migrate` runs it in the database of every module that
keeps a copy. A copy always writes to the database of the module that keeps it, even when one
consumer serves several keepers.

```bash
php artisan microservices:shadows:want [--keepers=reports] [--sources=iam_users]   # on the keeping module
php artisan microservices:shadows:announce iam_users [--keepers=reports]          # on the owner
```

`distributable:cache` records each module's copies and sources, so a process doesn't scan the module
folders for them at boot.
