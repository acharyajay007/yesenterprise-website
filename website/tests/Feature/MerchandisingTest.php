<?php

namespace Tests\Feature;

use App\Filament\Resources\Pages\Pages\ManagePages;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Widgets\WebsiteReview;
use App\Models\GalleryImage;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\WebsiteSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MerchandisingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteSeeder::class);
    }

    public function test_products_use_verified_photos_or_clean_placeholders_and_metadata_remains_internal(): void
    {
        $this->assertDatabaseCount('products', 507);
        foreach (Product::all() as $product) {
            if ($product->image_path) {
                $this->assertFileExists(public_path('media/'.$product->image_path));
                $this->assertNotNull($product->imageUrl());
            } else {
                $this->assertNull($product->imageUrl());
            }
            foreach (['en', 'gu', 'hi'] as $locale) {
                $this->assertNotSame($product->image_notice, __($product->image_notice, [], $locale));
            }
        }
        $product = Product::where('slug', '3m-180mc')->firstOrFail();
        $this->assertSame('supplierApplication', $product->image_notice);
        $this->assertNotEmpty($product->image_source_url);
        $this->get('/en/products/3m-180mc')->assertOk()->assertDontSee(__('merchandising.rangeImage'))->assertDontSee(__('merchandising.imageSource'));
        $this->assertSame(__('vinyl'), $product->imageAlt());
        config(['website.indexable' => true]);
        $this->assertNull($product->imageUrl());
        $product->update(['media_approved' => true]);
        $this->assertNotNull($product->imageUrl());
    }

    public function test_homepage_uses_only_published_popular_products_in_manual_order(): void
    {
        Product::query()->update(['is_popular' => false]);
        $first = Product::where('slug', 'sheets')->firstOrFail();
        $second = Product::where('slug', 'fabawn')->firstOrFail();
        $first->update(['is_popular' => true, 'popular_order' => 1]);
        $second->update(['is_popular' => true, 'popular_order' => 2]);
        Product::where('slug', 'srf-frontlit')->update(['is_popular' => true, 'popular_order' => 0, 'published' => false]);
        $response = $this->get('/en')->assertOk()->assertSee('id="popular-products"', false);
        $this->assertSame([$first->id, $second->id], $response->viewData('products')->modelKeys());
        $this->assertStringContainsString('popular=1', $response->getContent());
        Product::query()->update(['is_popular' => true]);
        $this->assertCount(8, $this->get('/en')->assertOk()->viewData('products'));
        Product::query()->update(['is_popular' => false]);
        $this->get('/en')->assertOk()->assertDontSee('id="popular-products"', false);
    }

    public function test_popular_filter_and_all_sort_orders_work_with_pagination_and_locale_links(): void
    {
        $response = $this->get('/en/catalogue?popular=1&sort=popular')->assertOk();
        $products = $response->viewData('products');
        $this->assertSame(8, $products->total());
        $this->assertSame('fabawn', $products->first()->slug);
        $this->assertTrue($products->every(fn (Product $product) => $product->is_popular));
        $response->assertSee(route('catalogue', ['locale' => 'gu', 'popular' => 1, 'sort' => 'popular']));
        foreach (['name-asc', 'name-desc'] as $sort) {
            $response = $this->get('/en/catalogue?brand=3M&sort='.$sort.'&q=3M')->assertOk();
            $expected = Product::published()->where('brand', '3M')->orderBy('id')->get();
            $expected = $sort === 'name-asc'
                ? $expected->sortBy(fn (Product $product) => mb_strtolower($product->text('title')), SORT_NATURAL)
                : $expected->sortByDesc(fn (Product $product) => mb_strtolower($product->text('title')), SORT_NATURAL);
            $this->assertSame($expected->take(24)->pluck('slug')->all(), $response->viewData('products')->pluck('slug')->all());
            $response->assertSee('sort='.$sort)->assertSee('brand=3M')->assertSee('page=2');
        }
        $newest = $this->get('/en/catalogue?sort=newest')->assertOk()->viewData('products');
        $this->assertSame(Product::published()->orderByDesc('created_at')->orderByDesc('id')->first()->id, $newest->first()->id);
        $this->get('/en/catalogue?sort=unknown')->assertSessionHasErrors('sort');
        $this->get('/en/catalogue?popular=yes')->assertSessionHasErrors('popular');
        Product::where('slug', 'fabawn')->update(['published' => false]);
        $this->get('/en/catalogue?popular=1&category=awnings')->assertOk()->assertSee(__('site.noResults'));
    }

    public function test_awning_page_uses_all_approved_customer_photos_and_localized_enquiry_links(): void
    {
        $this->assertSame(13, GalleryImage::where('notice', 'awning.customerPhoto')->count());
        foreach (['en', 'gu', 'hi'] as $locale) {
            app()->setLocale($locale);
            $response = $this->get('/'.$locale.'/awnings')->assertOk()
                ->assertSee(__('awning.title'))->assertDontSee(__('awning.scope'))
                ->assertSee(route('contact', ['locale' => $locale, 'product' => 'fabawn']))
                ->assertSee(route('awning', ['locale' => $locale === 'gu' ? 'hi' : 'gu']));
            $this->assertCount(13, $response->viewData('images'));
            foreach ($response->viewData('images') as $image) {
                $this->assertTrue($image->media_approved);
                $this->assertFileExists(public_path('media/'.$image->image_path));
                $response->assertSee($image->image_path)->assertSee($image->title());
            }
            $response->assertDontSee(__('awning.customerPhoto'))->assertSee('class="section awning-portfolio"', false)
                ->assertSee('class="awning-services"', false)->assertSee('class="awning-process-steps"', false);
            $this->get('/'.$locale)->assertOk()->assertSee('customer-awnings/a07.webp')
                ->assertDontSee(__('awning.customerPhoto'));
        }
        $this->get('/sitemap.xml')->assertOk()->assertSee('/en/awnings')->assertSee('/gu/awnings')->assertSee('/hi/awnings');
    }

    public function test_unpublished_and_unapproved_awning_photos_are_hidden(): void
    {
        config(['website.indexable' => true]);
        GalleryImage::where('image_path', 'customer-awnings/a07.webp')->update(['published' => false]);
        GalleryImage::where('image_path', 'customer-awnings/a11.webp')->update(['media_approved' => false]);
        Page::where('slug', 'awning')->update(['media_approved' => false]);
        foreach (['/en/awnings', '/en'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('customer-awnings/a07.webp')->assertDontSee('customer-awnings/a11.webp');
        }
        $this->assertCount(11, $this->get('/en/awnings')->viewData('images'));
    }

    public function test_admin_can_manage_popularity_photo_follow_up_and_awning_copy(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $product = Product::where('slug', 'fabawn')->firstOrFail();
        Livewire::test(ManageProducts::class)
            ->callAction(TestAction::make('edit')->table($product), ['is_popular' => true, 'popular_order' => 10])
            ->assertHasNoActionErrors();
        $this->assertSame(10, $product->refresh()->popular_order);
        $this->assertTrue($product->is_popular);
        $reference = Product::where('slug', '3m-180mc')->firstOrFail();
        Livewire::test(ManageProducts::class)->filterTable('photography', 'reference')
            ->searchTable('3m-180mc')->assertCanSeeTableRecords([$reference]);
        $page = Page::where('slug', 'awning')->firstOrFail();
        $translations = $page->translations;
        $translations['en']['description'] = 'Owner-reviewed awning installation summary';
        Livewire::test(ManagePages::class)
            ->callAction(TestAction::make('edit')->table($page), ['translations' => $translations])
            ->assertHasNoActionErrors();
        $this->get('/en/awnings')->assertSee('Owner-reviewed awning installation summary');
        $product->update(['image_path' => null, 'is_popular' => false]);
        Livewire::test(ManageProducts::class)->filterTable('photography', 'missing')
            ->searchTable('fabawn')->assertCanSeeTableRecords([$product]);
        $before = $product->refresh()->getAttributes();
        $pageBefore = $page->refresh()->getAttributes();
        $this->seed(WebsiteSeeder::class);
        $this->assertSame($before, $product->refresh()->getAttributes());
        $this->assertSame($pageBefore, $page->refresh()->getAttributes());
    }

    public function test_review_warnings_are_absent_from_public_pages_in_every_language(): void
    {
        $notices = [
            'site.development', 'site.catalogueScope', 'site.coverageTitle', 'site.media',
            'merchandising.rangeImage', 'supplierApplication', 'supplierCategory',
            'ownerCategory', 'brandPending', 'stockNote', 'sourceNote', 'supplierThickness',
            'durabilityNote', 'awning.scope', 'pendingSpec', 'vacancyBody', 'application',
            'representedBrands',
        ];
        foreach (['en', 'gu', 'hi'] as $locale) {
            app()->setLocale($locale);
            foreach (['', '/catalogue', '/products/3m-180mc', '/products/jindal-tar460', '/gallery', '/about', '/awnings', '/careers', '/contact'] as $path) {
                $response = $this->get('/'.$locale.$path)->assertOk()
                    ->assertDontSee('class="previewbar"', false)->assertDontSee('class="source-note"', false)
                    ->assertDontSee('class="material-label"', false)->assertDontSee('class="supplier-coverage"', false);
                foreach ($notices as $notice) {
                    $response->assertDontSee(__($notice));
                }
            }
        }
        $this->get('/en/products/3m-180mc')->assertDontSee('Direct technical documentation was inaccessible');
    }

    public function test_admin_keeps_review_notices_and_unconfirmed_specifications(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(WebsiteReview::class)
            ->assertSee('Website review & source warnings')->assertSee(__('site.development'))
            ->assertSee(__('site.coverageTitle'))->assertSee(__('site.coverageNotes.threeMCoverage'))
            ->assertSee('Exact-model photographs to review');
        $product = Product::where('slug', 'sunstar-lamination-g9-gloss-m9-matt-dm9-dull-matt-lamination')->firstOrFail();
        $this->assertContains(['label' => 'site.supplierThickness', 'value' => '90 microns'], $product->specifications);
        $this->assertNotContains(['label' => 'site.supplierThickness', 'value' => '90 microns'], $product->publicSpecifications());
        $this->get('/en/products/'.$product->slug)->assertOk()->assertDontSee('90 microns');
        $this->get('/en/products/lp2812')->assertOk()->assertSee('70 µm')->assertSee('95 µm ±10%');
    }

    public function test_form_errors_submission_status_and_privacy_remain_visible(): void
    {
        $this->withSession(['status' => __('site.submissionDelivered')])->get('/en/contact')->assertOk()
            ->assertSee(__('site.submissionDelivered'))->assertSee('role="status"', false)
            ->assertSee(__('site.consent'))->assertSee(route('privacy', ['locale' => 'en']));
        $this->followingRedirects()->from('/en/contact')->post('/en/contact', [])
            ->assertOk()->assertSee('role="alert"', false)->assertSee(__('site.validation'));
        $this->get('/en/privacy')->assertOk()->assertSee(__('site.privacyBody'));
    }
}
