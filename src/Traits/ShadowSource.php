<?php

declare(strict_types=1);

namespace Modulith\Traits;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Modulith\Contracts\Bus;
use Modulith\Data\Module;
use Modulith\Events\ShadowChanged;

/**
 * Put on a model other modules keep a copy of. Every write announces the row as it now stands;
 * only the $shadowed fields travel, so a field left out never leaves its module.
 *
 * Its host is a Modulith\Models\Model, which already knows its module.
 *
 * @property-read list<string> $shadowed
 * @property-read Module $module
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 * @method static void saved(\Closure $callback)
 * @method static void deleted(\Closure $callback)
 *
 * @mixin Model
 */
trait ShadowSource
{
    public static function bootShadowSource(): void
    {
        static::saved(static function (self $model): void {
            if ($model->wasRecentlyCreated || $model->wasChanged($model->shadowedFields())) {
                $model->announceShadow();
            }
        });

        static::deleted(static fn (self $model) => $model->announceShadow(deleted: true));
    }

    /** A source row deleted for good still reaches the copies, as a soft delete of theirs. */
    public function announceShadow(bool $deleted = false): void
    {
        $attributes = $this->shadowedAttributes();

        if ($deleted && ($attributes['deleted_at'] ?? null) === null) {
            $attributes['deleted_at'] = now()->format('Y-m-d H:i:s');
        }

        app(Bus::class)->emit(new ShadowChanged(
            sourceModule: $this->module->name,
            source: $this->getTable(),
            key: $this->getKey(),
            attributes: $attributes,
        ));
    }

    /** Re-announces every row, soft-deleted ones included; a copy already in step is rewritten with itself. */
    public static function announceAll(int $chunk = 500): int
    {
        $announced = 0;

        static::query()->withoutGlobalScopes()->chunkById($chunk, function ($rows) use (&$announced): void {
            foreach ($rows as $row) {
                $row->announceShadow();
                $announced++;
            }
        });

        return $announced;
    }

    /** @return list<string> */
    public function shadowedFields(): array
    {
        $dates = array_values(array_filter(
            ['created_at', 'updated_at', 'deleted_at'],
            fn (string $column): bool => array_key_exists($column, $this->getAttributes()),
        ));

        return [...$this->shadowed, ...$dates];
    }

    /** @return array<string, mixed> */
    private function shadowedAttributes(): array
    {
        $attributes = [];

        foreach ($this->shadowedFields() as $field) {
            $value = $this->getAttribute($field);

            $attributes[$field] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                default => $value,
            };
        }

        return $attributes;
    }
}
