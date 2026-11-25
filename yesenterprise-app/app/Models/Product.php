<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable(['slug', 'category', 'brand', 'translations', 'specifications', 'variants', 'image_path', 'image_notice', 'image_source_url', 'image_fit', 'additional_images', 'source_url', 'published', 'media_approved', 'is_popular', 'popular_order'])]
class Product extends Model
{
    protected function casts(): array
    {
        return ['translations' => 'array', 'specifications' => 'array', 'variants' => 'array', 'additional_images' => 'array', 'published' => 'boolean', 'media_approved' => 'boolean', 'is_popular' => 'boolean', 'popular_order' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    public function text(string $field): string
    {
        return $this->translations[app()->getLocale()][$field] ?? '';
    }

    public function imageUrl(): ?string
    {
        if (! $this->image_path || (config('website.indexable') && ! $this->media_approved)) {
            return null;
        }

        return Storage::disk('media')->url($this->image_path);
    }

    public function brandLogoUrl(): ?string
    {
        $brand = config('website.logo_aliases')[$this->brand] ?? $this->brand;
        $logo = config('website.logos')[$brand] ?? null;

        return $logo ? asset($logo) : null;
    }

    public function imageAlt(): string
    {
        return $this->image_notice === 'supplierProduct' ? $this->text('title') : __($this->category);
    }

    public function publicSpecifications(): array
    {
        return array_values(array_filter($this->specifications ?? [], fn (array $spec) => ! in_array($spec['label'], ['site.supplierThickness', 'site.catalogueReference'], true)));
    }

    public function galleryImages(): array
    {
        $images = [];
        if ($url = $this->imageUrl()) {
            $images[$this->image_path] = ['url' => $url, 'alt' => $this->imageAlt()];
        }
        foreach ($this->additional_images ?? [] as $image) {
            $path = $image['image_path'] ?? null;
            if (! $path || isset($images[$path]) || (config('website.indexable') && ! ($image['media_approved'] ?? false))) {
                continue;
            }
            $images[$path] = [
                'url' => Storage::disk('media')->url($path),
                'alt' => trim($image['captions'][app()->getLocale()] ?? '') ?: __($this->category),
            ];
        }

        return array_values($images);
    }
}
