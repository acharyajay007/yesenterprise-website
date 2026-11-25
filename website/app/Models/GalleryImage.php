<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable(['image_path', 'category', 'translations', 'notice', 'published', 'media_approved'])]
class GalleryImage extends Model
{
    protected function casts(): array
    {
        return ['translations' => 'array', 'published' => 'boolean', 'media_approved' => 'boolean'];
    }

    public function title(): string
    {
        return $this->translations[app()->getLocale()]['title'] ?? '';
    }

    public function imageUrl(): ?string
    {
        if (config('website.indexable') && ! $this->media_approved) {
            return null;
        }

        return Storage::disk('media')->url($this->image_path);
    }
}
