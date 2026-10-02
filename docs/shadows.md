# Read-only copies (shadows)

When a module needs another module's rows locally, for example to join, filter or sort on them,
it can keep a copy in its own database, kept up to date by events. Only the source module writes
the data.

```php
// apps/Iam/app/Models/User.php, the source: the $shadowed fields are copied
final class User extends \Illuminate\Database\Eloquent\Model implements \Modulith\Contracts\Shadows\Shadowed
{
    use \Modulith\Traits\ShadowSource;

    protected $table = 'iam_users';

    protected array $shadowed = ['name'];
}

// foundation/Iam/Shadows/UserShadow.php, the shape of the copy, declared once by iam
abstract class UserShadow extends \Modulith\Models\ShadowModel
{
    public static function owner(): string { return 'iam'; }

    public static function sourceTable(): string { return 'iam_users'; }
}

// apps/Analytics/app/Models/UserShadow.php, analytics keeps a copy in the analytics_iam_users table
final class UserShadow extends \Foundation\Iam\Shadows\UserShadow {}
```

iam publishes the migration of the copy's table in `foundation/Iam/database/shadows/`, extending
`Modulith\Migrations\ShadowMigration`. `migrate` runs it in the database of every module that keeps
a copy. A copy always writes to the database of the module that keeps it.

```
iam: User saved / deleted ─► ShadowChanged event ─► analytics consumer ─► UserShadow::sync()
```

A copy rejects any write that doesn't come from `sync()`, and a deleted source row becomes a soft
delete in the copy. To fill a copy created after the source already had data:

```bash
php artisan modulith:shadows:want [--keepers=reports] [--sources=iam_users]   # on the keeping module: ask the owners to send their rows again
php artisan modulith:shadows:announce iam_users [--keepers=reports]          # on the owner: send every row again
```

`--keepers` limits the command to the modules that keep the copy, for example a new module with an
empty database. The others don't receive the rows again. Both commands address the event through
its `recipients`, so only those modules update their copy.

