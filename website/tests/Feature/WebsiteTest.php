<?php

namespace Tests\Feature;

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\ManagePages;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Mail\SubmissionReceived;
use App\Models\Page;
use App\Models\Product;
use App\Models\Submission;
use App\Models\User;
use App\Services\DeliverSubmission;
use Database\Seeders\WebsiteSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;
use ZipArchive;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteSeeder::class);
        config(['website.enquiry_recipient' => 'preview@example.invalid']);
    }

    private function enquiry(): array
    {
        return ['name' => 'Preview sample', 'email' => 'sample@example.invalid', 'message' => 'A fictional enquiry.', 'consent' => '1', 'product' => 'fabawn'];
    }

    private function pdf(int $kilobytes = 1): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('resume.pdf', '%PDF-1.7'."\n".str_repeat('a', max(0, $kilobytes * 1024 - 9)));
    }

    public function test_all_public_pages_are_server_rendered_in_three_languages(): void
    {
        foreach (['en', 'gu', 'hi'] as $locale) {
            foreach (['', '/catalogue', '/products/fabawn', '/awnings', '/gallery', '/about', '/contact', '/careers', '/privacy'] as $path) {
                $this->get('/'.$locale.$path)->assertOk()->assertSee('lang="'.$locale.'"', false)->assertSee('rel="canonical"', false)->assertSee('hreflang="hi"', false)->assertDontSee('HTML Codex');
            }
        }
        $this->get('/en')->assertSee('home-awnings')->assertSee('srf-fabawn.webp')->assertDontSee('lx-corporate-branding.jpg');
        $this->get('/fr')->assertNotFound();
        $this->get('/en/products/missing')->assertNotFound();
    }

    public function test_navigation_uses_consistent_localized_menu_titles(): void
    {
        $labels = [
            'en' => ['Home', 'Products', 'Awnings', 'Gallery', 'About Us', 'Careers', 'Contact Us'],
            'gu' => ['મુખ્ય પૃષ્ઠ', 'ઉત્પાદનો', 'ઓનિંગ', 'ગેલેરી', 'અમારા વિશે', 'કારકિર્દી', 'સંપર્ક કરો'],
            'hi' => ['मुखपृष्ठ', 'उत्पाद', 'ऑनिंग', 'गैलरी', 'हमारे बारे में', 'करियर', 'संपर्क करें'],
        ];
        foreach ($labels as $locale => $expected) {
            $response = $this->get('/'.$locale.'/about')->assertOk();
            $this->assertSame(1, preg_match('/<nav id="nav"[^>]*>(.*?)<\/nav>/s', $response->getContent(), $navigation));
            preg_match_all('/<a\b[^>]*>(.*?)<\/a>/s', $navigation[1], $links);
            $this->assertSame($expected, array_map(fn (string $label) => trim(strip_tags($label)), $links[1]));
            $this->assertMatchesRegularExpression('/aria-current="page"\s+href="'.preg_quote(route('about', ['locale' => $locale]), '/').'"/', $navigation[1]);
        }
    }

    public function test_filters_and_publication_controls(): void
    {
        $this->get('/en/catalogue?category=awnings')->assertOk()->assertSee('FABAWN')->assertDontSee('Sunstar G8 / M8')
            ->assertSee(route('catalogue', ['locale' => 'gu', 'category' => 'awnings']));
        $this->get('/en/contact?product=fabawn')->assertOk()
            ->assertSee(route('contact', ['locale' => 'hi', 'product' => 'fabawn']));
        $this->get('/en/catalogue?q=LP2812')->assertOk()->assertSee('LP2812')->assertDontSee('FABAWN');
        Product::where('slug', 'fabawn')->update(['published' => false]);
        $this->get('/en/products/fabawn')->assertNotFound();
        Product::where('category', 'awnings')->update(['published' => false]);
        $this->get('/en/catalogue?category=awnings')->assertSee(__('site.noResults'));
    }

    public function test_unapproved_media_is_hidden_when_indexing_is_enabled(): void
    {
        config(['website.indexable' => true]);
        $this->get('/en')->assertDontSee('srf-fabawn.webp');
        $response = $this->get('/en/products/fabawn')->assertDontSee('srf-fabawn.webp')->assertDontSee(__('site.unavailableImage'));
        $this->assertSame(Storage::disk('media')->url('customer-awnings/a07.webp'), $response->viewData('images')[0]['url']);
        Product::where('slug', 'fabawn')->update(['media_approved' => true]);
        $this->get('/en/products/fabawn')->assertSee('srf-fabawn.webp');
    }

    public function test_sitemap_and_robots_do_not_expose_admin_or_private_records(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml')->assertSee('/gu/products/fabawn')->assertDontSee('/admin')->assertDontSee('/private');
        $this->get('/robots.txt')->assertSee('Disallow: /');
    }

    public function test_enquiry_is_persisted_and_notification_sent(): void
    {
        Mail::fake();
        $response = $this->post('/en/contact', $this->enquiry());
        $response->assertRedirect('/en/contact')->assertSessionHas('status', __('site.submissionDelivered'));
        $this->assertDatabaseHas('submissions', ['email' => 'sample@example.invalid', 'type' => 'enquiry', 'delivery_status' => 'delivered']);
        Mail::assertSent(SubmissionReceived::class, 1);
    }

    public function test_pending_email_is_saved_and_retry_is_idempotent(): void
    {
        Mail::fake();
        config(['website.enquiry_recipient' => null]);
        $this->post('/hi/contact', $this->enquiry())->assertSessionHas('status', __('site.submissionPending'));
        $record = Submission::firstOrFail();
        $this->assertSame('pending', $record->delivery_status);
        Mail::assertNothingSent();
        config(['website.enquiry_recipient' => 'preview@example.invalid']);
        $this->assertTrue(app(DeliverSubmission::class)->deliver($record));
        $this->assertTrue(app(DeliverSubmission::class)->deliver($record));
        Mail::assertSent(SubmissionReceived::class, 1);
    }

    public function test_smtp_failure_preserves_submission_and_returns_pending(): void
    {
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new TransportException('Preview SMTP failure'));
        $this->post('/en/contact', $this->enquiry())->assertRedirect('/en/contact')->assertSessionHas('status', __('site.submissionPending'));
        $this->assertDatabaseHas('submissions', ['delivery_status' => 'pending', 'delivery_error' => 'SMTP delivery failed. See server logs and retry.']);
    }

    public function test_invalid_data_never_creates_records_or_sends_email(): void
    {
        Mail::fake();
        $this->post('/en/contact', array_replace($this->enquiry(), ['email' => 'invalid']))->assertSessionHasErrors('email');
        $this->post('/en/contact', array_replace($this->enquiry(), ['product' => 'missing']))->assertSessionHasErrors('product');
        $this->post('/en/contact', array_replace($this->enquiry(), ['variant' => 'invented']))->assertSessionHasErrors('variant');
        $this->post('/en/contact', array_replace($this->enquiry(), ['website' => 'spam']))->assertSessionHasErrors('website');
        $this->assertDatabaseCount('submissions', 0);
        Mail::assertNothingSent();
    }

    public function test_shared_limit_is_exactly_five_per_fifteen_minutes(): void
    {
        Mail::fake();
        for ($i = 0; $i < 3; $i++) {
            $this->post('/en/contact', $this->enquiry())->assertRedirect('/en/contact');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->post('/en/careers', [])->assertSessionHasErrors('resume');
        }
        $this->post('/en/contact', $this->enquiry())->assertStatus(429)->assertHeader('Retry-After')->assertSee(__('site.rateLimit'));
        $this->travel(15)->minutes();
        $this->post('/en/contact', $this->enquiry())->assertRedirect('/en/contact');
        $this->assertDatabaseCount('submissions', 4);
    }

    public function test_resume_is_private_and_download_requires_an_admin(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->post('/en/careers', array_replace($this->enquiry(), ['role' => 'General application', 'resume' => $this->pdf(5120)]))->assertRedirect('/en/careers');
        $record = Submission::firstOrFail();
        $this->assertSame('application', $record->type);
        Storage::disk('local')->assertExists($record->resume_path);
        $this->get('/private/resumes/'.$record->id)->assertRedirect('/admin/login');
        $user = User::factory()->create();
        $this->actingAs($user)->get('/private/resumes/'.$record->id)->assertForbidden();
        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user)->get('/private/resumes/'.$record->id)->assertOk()->assertDownload('application-'.$record->id.'.pdf');
        $this->get('/storage/'.$record->resume_path)->assertNotFound();
    }

    public function test_invalid_oversize_and_disguised_resumes_are_rejected(): void
    {
        Storage::fake('local');
        Mail::fake();
        foreach ([$this->pdf(5121), UploadedFile::fake()->createWithContent('resume.pdf', 'not a PDF'), UploadedFile::fake()->createWithContent('resume.docx', 'not a ZIP')] as $file) {
            $this->post('/en/careers', array_replace($this->enquiry(), ['role' => 'General application', 'resume' => $file]))->assertSessionHasErrors('resume');
        }
        $this->assertDatabaseCount('submissions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_real_docx_is_accepted_without_exposing_the_original_name(): void
    {
        Storage::fake('local');
        Mail::fake();
        $file = tempnam(sys_get_temp_dir(), 'resume');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types><Override ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<document>Preview application</document>');
        $zip->close();
        try {
            $this->post('/en/careers', array_replace($this->enquiry(), ['role' => 'General application', 'resume' => new UploadedFile($file, 'original-name.docx', test: true)]))->assertRedirect('/en/careers');
            $record = Submission::firstOrFail();
            $this->assertStringNotContainsString('original-name', $record->resume_path);
            $this->assertSame('docx', $record->resume_extension);
        } finally {
            unlink($file);
        }
    }

    public function test_admin_access_and_persisted_product_edits(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $ordinaryUser = User::factory()->create();
        $this->actingAs($ordinaryUser)->get('/admin')->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin')->assertOk();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (['products', 'pages', 'gallery-images', 'submissions'] as $resource) {
            $this->get('/admin/'.$resource)->assertOk();
        }
        $product = Product::where('slug', 'fabawn')->firstOrFail();
        $translations = $product->translations;
        $translations['en']['title'] = 'Updated awning fabric';
        Livewire::test(ManageProducts::class)
            ->callAction(TestAction::make('edit')->table($product), ['translations' => $translations])
            ->assertHasNoActionErrors();
        $this->assertSame('Updated awning fabric', $product->refresh()->text('title'));
        $this->get('/en/products/fabawn')->assertSee('Updated awning fabric');
        $this->get('/gu/products/fabawn')->assertSee('FABAWN');
        $this->assertFalse(SubmissionResource::canCreate());
        $this->assertFalse(PageResource::canCreate());
    }

    public function test_page_edits_reach_the_homepage_and_are_escaped(): void
    {
        $page = Page::where('slug', 'home')->firstOrFail();
        $translations = $page->translations;
        $translations['en']['awning_title'] = '<script>unsafe()</script>';
        $page->update(['translations' => $translations]);
        $this->get('/en')->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false)->assertDontSee('<script>unsafe()</script>', false);
    }

    public function test_seed_is_repeatable_and_preserves_admin_edits(): void
    {
        $product = Product::where('slug', 'sunstar-wallpaper-wp-01')->firstOrFail();
        $translations = $product->translations;
        $translations['en']['title'] = 'Owner-reviewed wallpaper';
        $product->update(['translations' => $translations, 'published' => false]);
        $before = $product->refresh()->getAttributes();
        $this->seed(WebsiteSeeder::class);
        $this->assertDatabaseCount('products', 507);
        $this->assertSame($before, $product->refresh()->getAttributes());
    }

    public function test_supplied_logo_and_business_name_are_used_in_public_and_admin_branding(): void
    {
        foreach (['en', 'gu', 'hi'] as $locale) {
            $this->get('/'.$locale)->assertOk()->assertSee('assets/yes-enterprise-logo.png')
                ->assertSee('class="business-name"', false)->assertSee('class="business-city"', false)
                ->assertSee('Yes Enterprise')->assertSee('Rajkot')->assertDontSee('assets/logo.jpeg');
        }
        $this->get('/admin/login')->assertOk()->assertSee('assets/yes-enterprise-logo.png')
            ->assertSee('Yes Enterprise')->assertSee('Rajkot');
    }

    public function test_every_audited_supplier_entry_is_persisted_without_invented_specifications(): void
    {
        $data = json_decode(file_get_contents(database_path('seeders/data/supplier-products.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(524, $data['inventory']);
        $this->assertCount(504, $data['products']);
        $this->assertDatabaseCount('products', 507);
        $definitions = collect($data['products'])->keyBy('slug');
        $records = Product::all()->keyBy('slug');
        foreach ($data['inventory'] as $entry) {
            $this->assertTrue($records->has($entry['slug']), $entry['title']);
            $this->assertContains($entry['source'], $definitions[$entry['slug']]['sources']);
        }
        foreach ($data['products'] as $entry) {
            $this->assertContains($entry['category'], config('website.categories'));
            $this->assertContains($entry['brand'], config('website.brands'));
            if ($entry['image_path']) {
                $this->assertFileExists(public_path('media/'.$entry['image_path']));
            }
            foreach (['en', 'gu', 'hi'] as $locale) {
                $translation = $records[$entry['slug']]->translations[$locale];
                $this->assertNotEmpty($translation['title']);
                $this->assertNotEmpty($translation['description']);
                $this->assertStringNotContainsString('site.catalogueDescriptions.', $translation['description']);
            }
        }
        foreach (['jindal-bb260', 'jindal-tar460', 'jindal-tar510'] as $slug) {
            $this->assertCount(1, $records[$slug]->specifications);
            $this->assertSame('model', $records[$slug]->specifications[0]['label']);
            $this->assertStringContainsString('conflicting', $records[$slug]->translations['en']['source_note']);
        }
        $this->assertSame([], $records['3m-180mc']->specifications);
        $coverage = collect($data['coverage'])->keyBy('supplier');
        $this->assertSame(176, $coverage['Sun Sign & Technologies']['checked']);
        $this->assertSame(26, $coverage['Jindal / Jinflex']['checked']);
        $this->assertSame('documented', $coverage['LX Hausys / VIZUON']['status']);
        $this->assertCount(206, $data['lx_current_catalogue']['model_codes']);
        $this->assertSame(362, $data['lx_current_catalogue']['document_index_entries_checked']);
        foreach ($data['lx_current_catalogue']['model_codes'] as $code) {
            $this->assertTrue(collect($data['products'])->contains(fn (array $product) => in_array(['label' => 'model', 'value' => $code], $product['specifications'], true)), $code);
        }
        $this->assertContains(['label' => 'site.supplierThickness', 'value' => '90 microns'], $records['sunstar-lamination-g9-gloss-m9-matt-dm9-dull-matt-lamination']->specifications);
        $this->assertSame('indexed', $coverage['3M']['status']);
        $this->assertSame('unavailable', $coverage['Ultra Sign']['status']);
        $this->assertSame('unavailable', $coverage['HiSign / NEO']['status']);
    }

    public function test_large_catalogue_paginates_and_preserves_filters_and_enquiry_context(): void
    {
        $response = $this->get('/en/catalogue?brand=3M')->assertOk()
            ->assertSee('Showing 1–24 of 73 products')->assertSee('brand=3M&amp;page=2', false);
        $this->assertCount(24, $response->viewData('products'));
        $this->assertSame(73, $response->viewData('products')->total());
        $second = $this->get('/en/catalogue?brand=3M&page=2')->assertOk()
            ->assertSee('Showing 25–48 of 73 products')->assertDontSee('Official search-index listings only');
        $this->assertTrue($second->viewData('products')->every(fn (Product $product) => $product->brand === '3M'));
        $this->get('/en/catalogue?q=SH2BKST')->assertOk()->assertSee('Shutie Black')
            ->assertDontSee('class="catalogue-pagination"', false);
        $this->get('/gu/contact?product=3m-sh2bkst')->assertOk()
            ->assertSee('value="3m-sh2bkst" selected', false);
        $this->get('/en/products/jindal-tar460')->assertOk()
            ->assertDontSee('Disputed values are omitted')->assertDontSee('510 gsm');
        $this->get('/en/catalogue?page=-1')->assertSessionHasErrors('page');
    }

    public function test_professional_about_and_contact_pages_have_localized_summaries_and_business_facts(): void
    {
        foreach (['en', 'gu', 'hi'] as $locale) {
            app()->setLocale($locale);
            $this->get('/'.$locale.'/about')->assertOk()
                ->assertSee(__('business.aboutTitle'))->assertSee(__('business.aboutSummary'))
                ->assertSee(__('business.storyTitle'))->assertSee('assets/team.jpeg')->assertSee(__('business.teamPhoto'))
                ->assertSee(config('website.address'))->assertSee(config('website.maps_url'), false);
            $this->get('/'.$locale.'/contact?product=lx-ln95st')->assertOk()
                ->assertSee(__('business.contactTitle'))->assertSee(__('business.contactSummary'))
                ->assertSee(__('business.hoursTitle'))->assertSee(__('business.enquiryTitle'))
                ->assertSee('value="lx-ln95st" selected', false)
                ->assertSee('id="enquiry-form"', false)->assertSee('name="_token"', false)
                ->assertSee('tel:'.str_replace(' ', '', config('website.phone')))->assertDontSee('mailto:');
        }
        config(['website.indexable' => true]);
        $this->get('/en/about')->assertDontSee('assets/team.jpeg')->assertSee('business-photo-pending')->assertDontSee(__('site.unavailableImage'));
        Page::where('slug', 'about')->update(['media_approved' => true]);
        $this->get('/en/about')->assertSee('assets/team.jpeg');
    }

    public function test_admin_can_edit_business_page_summaries_without_homepage_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (['about', 'contact'] as $slug) {
            $page = Page::where('slug', $slug)->firstOrFail();
            $translations = $page->translations;
            $translations['en']['description'] = 'Owner-reviewed '.$slug.' summary';
            Livewire::test(ManagePages::class)
                ->callAction(TestAction::make('edit')->table($page), ['translations' => $translations])
                ->assertHasNoActionErrors();
            $this->get('/en/'.$slug)->assertSee('Owner-reviewed '.$slug.' summary');
            $before = $page->refresh()->getAttributes();
            $this->seed(WebsiteSeeder::class);
            $this->assertSame($before, $page->refresh()->getAttributes());
        }
        $this->assertDatabaseCount('pages', 4);
    }
}
