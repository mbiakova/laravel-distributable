<?php

declare(strict_types=1);

namespace Modulith\Models;

use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A local, read-only copy of another module's rows: same key as the source row, soft-deleted when
 * the source is, written only through sync(). The owning module declares the shape once in an
 * abstract subclass; each module keeping a copy extends it in its own namespace, so the copy is a
 * table of the keeper's database, named {keeper}_{source table}.
 *
 * @phpstan-consistent-constructor
 */
abstract class ShadowModel extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    private bool $syncing = false;

    /** @return class-string<BaseModel> the source model this copy mirrors */
    abstract public static function source(): string;

    public static function sourceTable(): string
    {
        $source = static::source();

        return (new $source)->getTable();
    }

    public function getTable(): string
    {
        return $this->module->name.'_'.static::sourceTable();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(static fn (self $model): bool => $model->syncing);
        static::deleting(static fn (self $model): bool => $model->syncing);
    }

    /** @param array<string, mixed> $attributes */
    public static function sync(int|string $key, array $attributes): void
    {
        $shadow = static::query()->withoutGlobalScopes()->find($key) ?? new static;
        $shadow->syncing = true;

        $shadow->forceFill([...static::beforeSync($attributes, $shadow->exists ? $shadow : null), $shadow->getKeyName() => $key])->save();

        $shadow->syncing = false;
    }

    /**
     * Derives what the copy needs and the source never announced; $previous is the copy as it
     * stood, so a transition can be dated.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected static function beforeSync(array $attributes, ?self $previous = null): array
    {
        return $attributes;
    }
}
