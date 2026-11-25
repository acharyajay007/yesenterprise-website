<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['slug', 'translations', 'hero_image', 'hero_source_url', 'hero_banners', 'media_approved'])]
class Page extends Model
{
    protected function casts(): array
    {
        return ['translations' => 'array', 'hero_banners' => 'array', 'media_approved' => 'boolean'];
    }

    public function text(string $field): string
    {
        return $this->translations[app()->getLocale()][$field] ?? '';
    }
}
