<?php

namespace Database\Seeders;

use App\Models\GalleryImage;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Lang;

class WebsiteSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/data/catalogue.json'), true, flags: JSON_THROW_ON_ERROR);
        $merchandising = json_decode(file_get_contents(__DIR__.'/data/merchandising.json'), true, flags: JSON_THROW_ON_ERROR);
        $images = json_decode(file_get_contents(__DIR__.'/data/presentation-images.json'), true, flags: JSON_THROW_ON_ERROR);
        $galleries = json_decode(file_get_contents(__DIR__.'/data/product-galleries.json'), true, flags: JSON_THROW_ON_ERROR);
        $banners = json_decode(file_get_contents(__DIR__.'/data/hero-banners.json'), true, flags: JSON_THROW_ON_ERROR);
        $photoUpgrades = json_decode(file_get_contents(__DIR__.'/data/reviewed-photo-upgrades.json'), true, flags: JSON_THROW_ON_ERROR)['products'];
        foreach ($data['products'] as $item) {
            foreach ($galleries as $slug => $photos) {
                Product::where('slug', $slug)->whereNull('additional_images')->first()?->update(['additional_images' => $photos]);
            }
            $translations = [];
            foreach (config('website.locales') as $locale) {
                $description = Lang::get(in_array($item['desc'], ['vinylDesc', 'boardDesc', 'tarpDesc', 'awnDesc'], true) ? 'merchandising.descriptions.'.$item['category'] : $item['desc'], [], $locale);
                $translations[$locale] = [
                    'title' => $item['name'] ?: Lang::get($item['category'], [], $locale),
                    'description' => $description,
                    'seo_description' => $description,
                ];
            }
            $media = $data['media'][$item['id']];
            Product::firstOrCreate(['slug' => $item['id']], [
                'category' => $item['category'],
                'brand' => $item['brand'] ?: null,
                'translations' => $translations,
                'specifications' => array_map(fn (array $spec) => ['label' => $spec[0], 'value' => $spec[1]], $item['specs']),
                'variants' => array_values(array_filter($item['variants'], fn (string $variant) => ! str_starts_with($variant, '@'))),
                'image_path' => $images[$item['id']]['image_path'] ?? 'assets/'.$media['file'],
                'image_notice' => $images[$item['id']]['image_notice'] ?? $media['notice'],
                'source_url' => $item['source'],
                'additional_images' => $galleries[$item['id']] ?? [],
                'image_source_url' => $images[$item['id']]['image_source_url'] ?? $media['source'],
                'is_popular' => in_array($item['id'], $merchandising['popular_slugs'], true),
                'popular_order' => array_search($item['id'], $merchandising['popular_slugs'], true) ?: 0,
                'published' => true,
                ...($photoUpgrades[$item['id']]['replacement'] ?? []),
            ]);
        }
        $supplierData = json_decode(file_get_contents(__DIR__.'/data/supplier-products.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($supplierData['products'] as $item) {
            $translations = [];
            foreach (config('website.locales') as $locale) {
                $description = Lang::get('merchandising.descriptions.'.$item['category'], [], $locale);
                $translations[$locale] = [
                    'title' => $item['title'],
                    'description' => $description,
                    'seo_description' => $item['title'].'. '.$description,
                    'source_note' => Lang::get('site.sourceNotes.'.$item['note'], [], $locale),
                ];
            }
            Product::firstOrCreate(['slug' => $item['slug']], [
                'category' => $item['category'],
                'brand' => $item['brand'],
                'translations' => $translations,
                'specifications' => $item['specifications'],
                'variants' => $item['variants'],
                'image_path' => $item['image_path'] ?: ($images[$item['slug']]['image_path'] ?? null),
                'image_notice' => $item['image_path'] ? $item['image_notice'] : ($images[$item['slug']]['image_notice'] ?? $item['image_notice']),
                'image_source_url' => $item['image_path'] ? $item['source_url'] : ($images[$item['slug']]['image_source_url'] ?? null),
                'source_url' => $item['source_url'],
                'additional_images' => $galleries[$item['slug']] ?? [],
                'is_popular' => in_array($item['slug'], $merchandising['popular_slugs'], true),
                'popular_order' => array_search($item['slug'], $merchandising['popular_slugs'], true) ?: 0,
                'published' => true,
                ...($photoUpgrades[$item['slug']]['replacement'] ?? []),
            ]);
        }
        $this->upgradeGeneratedPhotos($photoUpgrades);
        $translations = [];
        foreach (config('website.locales') as $locale) {
            $translations[$locale] = [
                'title' => trim(preg_replace('/<[^>]+>/', ' ', Lang::get('heroTitle', [], $locale))),
                'description' => Lang::get('heroBody', [], $locale),
                'seo_description' => Lang::get('footerIntro', [], $locale),
                'awning_title' => Lang::get('awningTitle', [], $locale),
                'awning_body' => Lang::get('awningBody', [], $locale),
            ];
        }
        $overview = $banners['photos'][$banners['overview']];
        $home = Page::firstOrCreate(['slug' => 'home'], [
            'translations' => $translations,
            'hero_image' => $overview['image_path'],
            'hero_source_url' => $overview['source_url'],
        ]);
        if ($home->hero_image === 'assets/srf-fabawn.webp') {
            $home->update([
                'hero_image' => $overview['image_path'],
                'hero_source_url' => $overview['source_url'],
                'media_approved' => false,
            ]);
        }
        if ($home->hero_banners === null) {
            $home->update(['hero_banners' => collect($banners['categories'])->map(function (string $photo, string $category) use ($banners): array {
                return [
                    'category' => $category,
                    'image_path' => $banners['photos'][$photo]['image_path'],
                    'source_url' => $banners['photos'][$photo]['source_url'],
                    'media_approved' => false,
                ];
            })->values()->all()]);
        }
        foreach (['about', 'contact'] as $slug) {
            $translations = [];
            foreach (config('website.locales') as $locale) {
                $translations[$locale] = [
                    'title' => Lang::get('business.'.$slug.'Title', [], $locale),
                    'description' => Lang::get('business.'.$slug.'Summary', [], $locale),
                    'seo_description' => Lang::get('business.'.$slug.'Seo', [], $locale),
                ];
            }
            Page::firstOrCreate(['slug' => $slug], [
                'translations' => $translations,
                'hero_image' => $slug === 'about' ? 'assets/team.jpeg' : null,
            ]);
        }
        $translations = [];
        foreach (config('website.locales') as $locale) {
            $translations[$locale] = [
                'title' => Lang::get('awning.title', [], $locale),
                'description' => Lang::get('awning.summary', [], $locale),
                'seo_description' => Lang::get('awning.seo', [], $locale),
            ];
        }
        Page::firstOrCreate(['slug' => 'awning'], [
            'translations' => $translations,
            'hero_image' => 'customer-awnings/a07.webp',
            'media_approved' => true,
        ]);
        foreach ($merchandising['customer_awning_photos'] as $code) {
            $translations = [];
            foreach (config('website.locales') as $locale) {
                $translations[$locale] = ['title' => Lang::get('awning.photos.'.$code, [], $locale)];
            }
            GalleryImage::firstOrCreate(['image_path' => 'customer-awnings/'.$code.'.webp'], [
                'category' => 'awnings',
                'translations' => $translations,
                'notice' => 'awning.customerPhoto',
                'published' => true,
                'media_approved' => true,
            ]);
        }
        foreach ($data['photographs'] as $photo) {
            $translations = [];
            foreach (config('website.locales') as $locale) {
                $translations[$locale] = ['title' => Lang::get($photo['title'], [], $locale)];
            }
            GalleryImage::firstOrCreate(['image_path' => 'assets/'.$photo['file']], [
                'category' => $photo['group'],
                'translations' => $translations,
                'notice' => $photo['notice'],
                'published' => true,
            ]);
        }

    }

    private function upgradeGeneratedPhotos(array $upgrades): void
    {
        foreach ($upgrades as $slug => $upgrade) {
            $product = Product::where('slug', $slug)->first();
            if (! $product || $product->media_approved || $product->image_fit !== 'cover'
                || $product->brand !== $upgrade['brand'] || $product->category !== $upgrade['category']) {
                continue;
            }
            foreach ($upgrade['previous'] as $previous) {
                // Match the complete generated baseline, never a filename alone.
                if ($product->only(array_keys($previous)) === $previous) {
                    $product->update([...$upgrade['replacement'], 'media_approved' => false]);
                    break;
                }
            }
        }
    }
}
