<?php

namespace App\Models\Concerns;

use App\Support\PublicSlug;

trait HasPublicSlug
{
    protected static function bootHasPublicSlug(): void
    {
        static::creating(function ($model) {
            $model->slug ??= PublicSlug::make($model->getTable(), $model->getAttributes());
        });
        static::replicating(function ($model) {
            $model->slug = null;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getRouteKey(): mixed
    {
        // Keep records inserted through legacy SQL imports routable as well.
        if (! $this->slug && $this->exists) {
            $this->slug = PublicSlug::make($this->getTable(), $this->getAttributes());
            static::query()->whereKey($this->getKey())->update(['slug' => $this->slug]);
        }
        return $this->slug;
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if (($field === null || $field === 'slug') && ctype_digit((string) $value)) {
            return $this->whereKey($value)->first();
        }
        return $this->where($field ?? 'slug', $value)->first();
    }
}
