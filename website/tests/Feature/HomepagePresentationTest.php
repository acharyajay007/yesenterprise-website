<?php

namespace Tests\Feature;

use App\Filament\Resources\Pages\Pages\ManagePages;
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

class HomepagePresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteSeeder::class);
    }

    public function test_hero_covers_every_material_category_with_localized_copy_and_relevant_links(): void
    {
        foreach (config('website.locales') as $locale) {
            $response = $this->get('/'.$locale)->assertOk();
            $slides = $response->viewData('heroSlides');
            $this->assertSame(config('website.categories'), $slides->pluck('category')->filter()->values()->all());
            $this->assertSame(Page::where('slug', 'home')->firstOrFail()->text('title'), $slides->first()['title']);
            foreach ($slides->skip(1) as $slide) {
                $this->assertNotEmpty($slide['image']);
                $this->assertSame(__($slide['category']), $slide['alt']);
                $this->assertNotEmpty($slide['description']);
                $this->assertStringNotContainsString('merchandising.descriptions.', $slide['description']);
                $this->assertSame(
                    $slide['category'] === 'awnings'
                        ? route('awning', ['locale' => $locale])
                        : route('catalogue', ['locale' => $locale, 'category' => $slide['category']]),
                    $slide['href'],
                );
            }
            $response->assertSee(__('merchandising.brandsTitle'))->assertSee('id="brands-title"', false);
        }
    }

    public function test_hero_uses_dedicated_banners_and_respects_publication_and_individual_approval(): void
    {
        $home = Page::where('slug', 'home')->firstOrFail();
        $banners = $home->hero_banners;
        $board = array_search('board', array_column($banners, 'category'), true);
        $banners[$board]['image_path'] = 'uploads/owner-reviewed-hd-board.webp';
        $banners[$board]['source_url'] = 'https://example.invalid/private-banner-source';
        $banners[$board]['media_approved'] = true;
        $home->update(['hero_banners' => $banners]);
        $product = Product::where('slug', 'sheets')->firstOrFail();
        $product->update(['image_path' => 'uploads/owner-reviewed-board.webp', 'media_approved' => true]);
        $response = $this->get('/en')->assertOk()->assertDontSee('private-banner-source');
        $slides = $response->viewData('heroSlides');
        $this->assertSame(Storage::disk('media')->url($banners[$board]['image_path']), $slides->firstWhere('category', 'board')['image']);
        config(['website.indexable' => true]);
        $slides = $this->get('/en')->assertOk()->viewData('heroSlides');
        $this->assertSame(['board'], $slides->pluck('category')->filter()->values()->all());
        $this->assertNull($slides->first()['image']);
        $product->update(['published' => false]);
        $this->assertNull($this->get('/en')->viewData('heroSlides')->firstWhere('category', 'board'));
        $response = $this->get('/en')->assertOk()->assertDontSee('splide__toggle');
        $this->assertCount(1, $response->viewData('heroSlides'));
    }

    public function test_downloaded_banners_are_full_hd_without_upscaling_and_sources_stay_private(): void
    {
        $manifest = json_decode(file_get_contents(database_path('seeders/data/hero-banners.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(config('website.categories'), array_keys($manifest['categories']));
        foreach ($manifest['photos'] as $photo) {
            $size = getimagesize(Storage::disk('media')->path($photo['image_path']));
            $this->assertSame([1920, 1080], [$size[0], $size[1]]);
            $this->assertSame('image/webp', $size['mime']);
            $this->assertGreaterThanOrEqual($size[0], $photo['width']);
            $this->assertGreaterThanOrEqual($size[1], $photo['height']);
            $this->assertLessThan(600 * 1024, Storage::disk('media')->size($photo['image_path']));
            $this->assertStringStartsWith('https://', $photo['source_url']);
        }
        $this->get('/en')->assertOk()->assertDontSee('VIZUON%20Catalog.pdf')
            ->assertDontSee('coated-fabrics-business-b-img')
            ->assertDontSee('Review publication rights')
            ->assertDontSee('Supplier application photography');
    }

    public function test_admin_can_reorder_edit_and_clear_banners_and_seeding_preserves_owner_changes(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $home = Page::where('slug', 'home')->firstOrFail();
        $banners = array_reverse($home->hero_banners);
        $banners[0]['source_url'] = 'https://example.invalid/owner-banner-source';
        $banners[0]['media_approved'] = true;
        $formBanners = array_map(fn (array $banner): array => [...$banner, 'image_path' => [$banner['image_path']]], $banners);
        Livewire::test(ManagePages::class)
            ->callAction(TestAction::make('edit')->table($home), ['hero_banners' => $formBanners])
            ->assertHasNoActionErrors();
        $this->assertSame($banners, $home->refresh()->hero_banners);
        $this->assertSame(array_column($banners, 'category'), $this->get('/en')->viewData('heroSlides')->pluck('category')->filter()->values()->all());
        $home->update(['hero_image' => 'uploads/owner-home.webp', 'hero_source_url' => 'https://example.invalid/owner-home', 'media_approved' => true]);
        $this->seed(WebsiteSeeder::class);
        $this->assertSame($banners, $home->refresh()->hero_banners);
        $this->assertSame('uploads/owner-home.webp', $home->hero_image);
        $this->assertSame('https://example.invalid/owner-home', $home->hero_source_url);
        $this->assertTrue($home->media_approved);
        Livewire::test(ManagePages::class)
            ->callAction(TestAction::make('edit')->table($home), ['hero_banners' => []])
            ->assertHasNoActionErrors();
        $this->seed(WebsiteSeeder::class);
        $this->assertSame([], $home->refresh()->hero_banners);
        $home->update(['hero_banners' => null, 'hero_image' => 'assets/srf-fabawn.webp']);
        $this->seed(WebsiteSeeder::class);
        $this->assertCount(count(config('website.categories')), $home->refresh()->hero_banners);
        $this->assertSame('hero/storefront.webp', $home->hero_image);
        $this->assertFalse($home->media_approved);
    }

    public function test_confirmed_phone_and_whatsapp_are_available_on_all_localized_public_pages(): void
    {
        foreach (config('website.locales') as $locale) {
            foreach (['', '/contact', '/awnings'] as $path) {
                $this->get('/'.$locale.$path)->assertOk()
                    ->assertSee('class="topbar-phone"', false)
                    ->assertSee('tel:+919429550666', false)
                    ->assertSee(config('website.phone'))
                    ->assertSee('class="whatsapp-chat"', false)
                    ->assertSee('https://wa.me/919429550666', false)
                    ->assertSee(__('business.whatsapp'))
                    ->assertSee('"telephone":"+919429550666"', false);
            }
        }

    }

    public function test_footer_uses_business_copyright_without_template_credits(): void
    {
        foreach (config('website.locales') as $locale) {
            $this->get('/'.$locale)->assertOk()
                ->assertDontSee('Adapted from Industro.')
                ->assertDontSee('Designed by ')
                ->assertDontSee('htmlcodex.com')
                ->assertSee('Yes Enterprise Rajkot');
        }
    }
}
