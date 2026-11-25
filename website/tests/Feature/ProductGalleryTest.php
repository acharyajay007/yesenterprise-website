<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\WebsiteSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProductGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteSeeder::class);
    }

    public function test_seeded_galleries_are_relevant_ordered_and_deduplicated_with_localized_captions(): void
    {
        $product = Product::where('slug', 'fabawn')->firstOrFail();
        $photos = $product->additional_images;
        $this->assertCount(3, $photos);
        $product->update(['additional_images' => [$photos[1], $photos[0], $photos[1], ['image_path' => $product->image_path], $photos[2]]]);

        foreach (['en', 'gu', 'hi'] as $locale) {
            app()->setLocale($locale);
            $images = $product->galleryImages();
            $this->assertCount(4, $images);
            $this->assertSame(Storage::disk('media')->url($product->image_path), $images[0]['url']);
            $this->assertSame($photos[1]['captions'][$locale], $images[1]['alt']);
            $this->assertSame($photos[0]['captions'][$locale], $images[2]['alt']);
            foreach ($images as $image) {
                $this->assertSame(['url', 'alt'], array_keys($image));
            }
            $response = $this->get('/'.$locale.'/products/fabawn')->assertOk()
                ->assertSee('data-photo-group="product-fabawn"', false)
                ->assertDontSee('fabric model not established');
            $this->assertSame($images, $response->viewData('images'));
        }
    }

    public function test_each_photo_requires_its_own_approval_without_leaking_hidden_urls(): void
    {
        config(['website.indexable' => true]);
        $product = Product::where('slug', 'fabawn')->firstOrFail();
        $approved = $product->additional_images[0];
        $hidden = $approved;
        $hidden['image_path'] = 'gallery-hidden-additional.webp';
        $hidden['media_approved'] = false;
        $hidden['source_url'] = 'https://example.invalid/private-photo-provenance';
        $product->update([
            'image_path' => 'gallery-hidden-primary.webp',
            'media_approved' => false,
            'additional_images' => [$hidden, $approved],
        ]);
        $response = $this->get('/en/products/fabawn')->assertOk()
            ->assertDontSee('gallery-hidden-primary.webp')
            ->assertDontSee('gallery-hidden-additional.webp')
            ->assertDontSee('private-photo-provenance');
        $this->assertSame([['url' => Storage::disk('media')->url($approved['image_path']), 'alt' => $approved['captions']['en']]], $response->viewData('images'));
        $product->update(['media_approved' => true]);
        $this->assertCount(2, $product->galleryImages());
        $product->update(['media_approved' => false, 'additional_images' => [$hidden]]);
        $response = $this->get('/en/products/fabawn')->assertOk();
        $this->assertSame([], $response->viewData('images'));
        $response->assertSee('photo-placeholder')->assertDontSee('gallery-hidden-');
    }

    public function test_blank_or_missing_localized_captions_fall_back_to_the_material_category(): void
    {
        $product = Product::where('slug', 'fabawn')->firstOrFail();
        $photo = $product->additional_images[0];
        $photo['captions'] = ['en' => '   ', 'gu' => ''];
        $product->update(['image_path' => null, 'additional_images' => [$photo, ['image_path' => '']]]);
        foreach (['en', 'gu', 'hi'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame(__($product->category), $product->galleryImages()[0]['alt']);
            $this->assertCount(1, $product->galleryImages());
        }
    }

    public function test_admin_can_reorder_edit_and_clear_additional_images_without_changing_primary(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $product = Product::where('slug', 'fabawn')->firstOrFail();
        $primary = $product->image_path;
        $photos = array_reverse($product->additional_images);
        $photos[0]['captions']['en'] = 'Owner-reviewed installation detail';
        $photos[0]['media_approved'] = false;
        $formPhotos = array_map(fn (array $photo) => [...$photo, 'image_path' => [$photo['image_path']]], $photos);
        Livewire::test(ManageProducts::class)
            ->callAction(TestAction::make('edit')->table($product), ['additional_images' => $formPhotos])
            ->assertHasNoActionErrors();
        $product->refresh();
        $this->assertSame(array_column($photos, 'image_path'), array_column($product->additional_images, 'image_path'));
        $this->assertSame($photos[0]['captions'], $product->additional_images[0]['captions']);
        $this->assertFalse($product->additional_images[0]['media_approved']);
        $this->assertSame($primary, $product->image_path);
        Livewire::test(ManageProducts::class)
            ->callAction(TestAction::make('edit')->table($product), ['additional_images' => []])
            ->assertHasNoActionErrors();
        $this->assertSame([], $product->refresh()->additional_images);
        $this->seed(WebsiteSeeder::class);
        $this->assertSame([], $product->refresh()->additional_images);
        $this->assertSame($primary, $product->image_path);
    }

    public function test_reseeding_initializes_only_null_galleries_and_preserves_owner_edits(): void
    {
        $product = Product::where('slug', 'fabawn')->firstOrFail();
        $product->update(['additional_images' => null]);
        $range = Product::where('slug', 'signage-film')->firstOrFail();
        $range->update(['additional_images' => []]);
        $custom = Product::where('slug', 'sheets')->firstOrFail();
        $photos = [['image_path' => 'assets/team.jpeg', 'captions' => ['en' => 'Owner caption'], 'media_approved' => false]];
        $custom->update(['additional_images' => $photos, 'is_popular' => false]);
        $this->seed(WebsiteSeeder::class);
        $this->assertCount(3, $product->refresh()->additional_images);
        $this->assertSame([], $range->refresh()->additional_images);
        $this->assertSame($photos, $custom->refresh()->additional_images);
        $this->assertFalse($custom->is_popular);
    }

    public function test_related_products_exclude_unpublished_and_current_products(): void
    {
        $product = Product::where('slug', 'srf-frontlit')->firstOrFail();
        $hidden = Product::where('category', $product->category)->where('id', '!=', $product->id)->firstOrFail();
        $hidden->update(['published' => false, 'is_popular' => true, 'popular_order' => 0]);
        $related = $this->get('/en/products/'.$product->slug)->assertOk()->viewData('relatedProducts');
        $this->assertCount(4, $related);
        $this->assertTrue($related->every(fn (Product $item) => $item->published && $item->category === $product->category && $item->id !== $product->id && $item->id !== $hidden->id));
    }

    public function test_hero_uses_splide_without_photo_links_and_preserves_single_slide_fallback(): void
    {
        foreach (['en', 'gu', 'hi'] as $locale) {
            $response = $this->get('/'.$locale)->assertOk()->assertSee('splide__track')->assertSee('splide__toggle');
            $this->assertCount(count(config('website.categories')) + 1, $response->viewData('heroSlides'));
            preg_match('/<section class="storefront-hero.*?<\/section>/s', $response->getContent(), $hero);
            $this->assertStringNotContainsString('data-photo-group', $hero[0]);
            $this->assertStringNotContainsString('data-hero-next', $hero[0]);
            $this->assertSame(1, substr_count($hero[0], '<h1>'));
        }
        config(['website.indexable' => true]);
        Page::where('slug', 'awning')->update(['media_approved' => false]);
        $response = $this->get('/en')->assertOk()->assertDontSee('splide__toggle');
        $this->assertCount(1, $response->viewData('heroSlides'));
        preg_match('/<section class="storefront-hero.*?<\/section>/s', $response->getContent(), $hero);
        $this->assertStringNotContainsString('srf-fabawn.webp', $hero[0]);
        $this->assertStringContainsString('<h1>', $hero[0]);
    }
}
