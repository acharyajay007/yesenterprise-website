<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\WebsiteSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductPhotographyTest extends TestCase
{
    use RefreshDatabase;

    private function upgrades(): array
    {
        return json_decode(file_get_contents(database_path('seeders/data/reviewed-photo-upgrades.json')), true, flags: JSON_THROW_ON_ERROR)['products'];
    }

    public function test_reviewed_assets_have_native_dimensions_integrity_and_explicit_source_associations(): void
    {
        $upgrades = $this->upgrades();
        $supplierProducts = collect(json_decode(file_get_contents(database_path('seeders/data/supplier-products.json')), true, flags: JSON_THROW_ON_ERROR)['products'])->keyBy('slug');
        $this->assertCount(482, $upgrades);
        $this->assertCount(76, array_filter($upgrades, fn (array $photo) => $photo['replacement']['image_path'] === null));
        $this->assertCount(15, array_filter($upgrades, fn (array $photo) => $photo['evidence']['association'] === 'labelled-native-model-swatch'));
        foreach ($upgrades as $slug => $photo) {
            $replacement = $photo['replacement'];
            $evidence = $photo['evidence'];
            $this->assertNotEmpty($photo['previous'], $slug);
            $this->assertContains($replacement['image_fit'], ['cover', 'contain']);
            if ($photo['category'] === 'lighting') {
                $this->assertSame('contain', $replacement['image_fit']);
                if (str_contains($slug, 'converter') && preg_match('/\b(\d+)W\b/i', $evidence['source_title'], $rating)) {
                    $this->assertStringContainsString('-'.strtolower($rating[0]).'.', $evidence['image_source_url']);
                }
            }
            if ($replacement['image_path'] === null) {
                $this->assertSame('unavailable', $evidence['association']);
                $this->assertNull($replacement['image_source_url']);

                continue;
            }
            $path = public_path('media/'.$replacement['image_path']);
            $this->assertFileExists($path, $slug);
            $size = getimagesize($path);
            $this->assertSame([$evidence['width'], $evidence['height']], [$size[0], $size[1]], $slug);
            $this->assertLessThanOrEqual($evidence['original_width'], $size[0], $slug);
            $this->assertLessThanOrEqual($evidence['original_height'], $size[1], $slug);
            $this->assertSame($evidence['file_sha256'], hash_file('sha256', $path), $slug);
            $this->assertNotEmpty($replacement['image_source_url']);
            if ($evidence['association'] === 'official-model-listing') {
                $this->assertStringStartsWith('https://sunsignandtechnologies.com/product/', $replacement['image_source_url']);
                $this->assertStringStartsWith('https://sunsignandtechnologies.com/wp-content/uploads/', $evidence['image_source_url']);
                $this->assertGreaterThan(0, $evidence['source_product_id']);
                $this->assertContains($replacement['image_source_url'], $supplierProducts[$slug]['sources']);
            } elseif ($photo['brand'] === 'LX Hausys / VIZUON') {
                $this->assertStringStartsWith('https://img.lxhausys.com/', $replacement['image_source_url']);
                $this->assertGreaterThan(0, $evidence['pdf_xref']);
                if ($replacement['image_notice'] === 'supplierProduct') {
                    $this->assertSame(strtoupper(substr($slug, 3)), $evidence['source_model']);
                }
            } elseif ($photo['brand'] === '3M') {
                $this->assertStringStartsWith('https://www.ndgraphics.com/', $replacement['image_source_url']);
                $this->assertNotEmpty($evidence['family']);
            } else {
                $this->fail('Unreviewed source association: '.$slug);
            }
        }
        $this->assertSame(294, $upgrades['lx-lt4000']['evidence']['pdf_xref']);
        $this->assertSame(296, $upgrades['lx-lm5300w']['evidence']['pdf_xref']);
        $this->assertSame(288, $upgrades['lx-ldj90df']['evidence']['pdf_xref']);
    }

    public function test_fresh_catalogue_uses_reviewed_photos_and_clean_placeholders_without_public_warnings(): void
    {
        $this->seed(WebsiteSeeder::class);
        $this->assertDatabaseCount('products', 507);
        foreach ($this->upgrades() as $slug => $photo) {
            $product = Product::where('slug', $slug)->firstOrFail();
            $this->assertSame($photo['replacement'], $product->only(array_keys($photo['replacement'])), $slug);
            $this->assertFalse($product->media_approved);
        }
        foreach (['en', 'gu', 'hi'] as $locale) {
            $this->get('/'.$locale.'/products/3m-3930')->assertOk()
                ->assertSee('photo-placeholder')->assertDontSee('No verified')->assertDontSee('cross-brand');
            $sample = Product::where('slug', 'lx-lc5510')->firstOrFail();
            $this->get('/'.$locale.'/products/lx-lc5510')->assertOk()
                ->assertSee($sample->image_path)
                ->assertDontSee($sample->image_source_url)
                ->assertDontSee('labelled-native-model-swatch');
        }
    }

    public function test_existing_generated_defaults_are_upgraded_without_touching_other_product_data_or_galleries(): void
    {
        $this->seed(WebsiteSeeder::class);
        $photo = $this->upgrades()['lx-lc5510'];
        $product = Product::where('slug', 'lx-lc5510')->firstOrFail();
        $gallery = [['image_path' => 'customer-awnings/a07.webp', 'captions' => ['en' => 'Owner gallery'], 'media_approved' => true]];
        $product->update([...$photo['previous'][0], 'image_fit' => 'cover', 'additional_images' => $gallery, 'is_popular' => true]);
        $before = $product->only(['translations', 'specifications', 'variants', 'published', 'is_popular', 'additional_images']);
        $this->seed(WebsiteSeeder::class);
        $this->assertSame($photo['replacement'], $product->refresh()->only(array_keys($photo['replacement'])));
        $this->assertSame($before, $product->only(array_keys($before)));
        $this->assertFalse($product->media_approved);
        $this->seed(WebsiteSeeder::class);
        $this->assertSame($photo['replacement'], $product->refresh()->only(array_keys($photo['replacement'])));
    }

    public function test_owner_uploads_provenance_approvals_framing_and_empty_galleries_survive_upgrades(): void
    {
        $this->seed(WebsiteSeeder::class);
        $slugs = ['lx-lc5510', 'lx-lc5520', 'lx-lc5521', 'lx-lc5560', 'lx-lc5570'];
        $changes = [
            ['image_path' => 'uploads/owner.webp'],
            ['image_source_url' => 'https://example.invalid/owner-source'],
            ['media_approved' => true],
            ['image_fit' => 'contain'],
            ['image_notice' => 'supplied'],
        ];
        $expected = [];
        foreach ($slugs as $index => $slug) {
            $product = Product::where('slug', $slug)->firstOrFail();
            $product->update([...$this->upgrades()[$slug]['previous'][0], 'image_fit' => 'cover', ...$changes[$index], 'additional_images' => []]);
            $expected[$slug] = $product->only(['image_path', 'image_source_url', 'image_notice', 'source_url', 'media_approved', 'image_fit', 'additional_images']);
        }
        $this->seed(WebsiteSeeder::class);
        foreach ($expected as $slug => $attributes) {
            $this->assertSame($attributes, Product::where('slug', $slug)->firstOrFail()->only(array_keys($attributes)), $slug);
        }
        $default = Product::where('slug', 'lx-lc5580')->firstOrFail();
        $default->update([...$this->upgrades()['lx-lc5580']['previous'][0], 'additional_images' => []]);
        $this->seed(WebsiteSeeder::class);
        $this->assertSame([], $default->refresh()->additional_images);
    }

    public function test_legacy_defaults_with_null_photograph_provenance_are_upgraded(): void
    {
        $this->seed(WebsiteSeeder::class);
        foreach (['sfb-09-gloss-matt', 'sunstar-lamination', 'jindal-fl300'] as $slug) {
            $photo = $this->upgrades()[$slug];
            $product = Product::where('slug', $slug)->firstOrFail();
            $legacy = collect($photo['previous'])->first(fn (array $baseline) => $baseline['image_source_url'] === null);
            $this->assertNotNull($legacy);
            $product->update([...$legacy, 'image_fit' => 'cover']);
        }
        $this->seed(WebsiteSeeder::class);
        foreach (['sfb-09-gloss-matt', 'sunstar-lamination', 'jindal-fl300'] as $slug) {
            $photo = $this->upgrades()[$slug];
            $this->assertSame($photo['replacement'], Product::where('slug', $slug)->firstOrFail()->only(array_keys($photo['replacement'])));
        }
    }

    public function test_rolls_are_not_cropped_and_admin_can_change_framing_without_bypassing_approval(): void
    {
        $this->seed(WebsiteSeeder::class);
        $product = Product::where('image_fit', 'contain')->firstOrFail();
        $this->get('/en/catalogue?q='.urlencode($product->text('title')))->assertOk()->assertSee('card-photo--contain');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ManageProducts::class)
            ->callAction(TestAction::make('edit')->table($product), ['image_fit' => 'cover'])
            ->assertHasNoActionErrors();
        $this->assertSame('cover', $product->refresh()->image_fit);
        $this->assertFalse($product->media_approved);
        config(['website.indexable' => true]);
        $this->assertNull($product->imageUrl());
        $product->update(['media_approved' => true]);
        $this->assertNotNull($product->imageUrl());
    }
}
