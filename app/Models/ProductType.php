<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductType extends Model
{
    protected $fillable = ['slug', 'name', 'sort_order', 'translations'];

    protected $casts = ['translations' => 'array'];

    public function label(string $locale = null): string
    {
        $locale ??= app()->getLocale();
        return $this->translations[$locale] ?? $this->name;
    }
}
