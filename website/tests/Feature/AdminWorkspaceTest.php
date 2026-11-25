<?php

namespace Tests\Feature;

use App\Filament\Resources\GalleryImages\GalleryImageResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Models\User;
use Database\Seeders\WebsiteSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_uses_the_replacement_theme_and_keeps_management_private(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('data-filament-shadcn-theme=', false)
            ->assertSee('admin.css')
            ->assertSee('admin-business-brand')
            ->assertSee('Sign in')
            ->assertDontSee('Your website workspace');
        foreach (['', '/products', '/pages', '/gallery-images', '/submissions'] as $path) {
            $this->get('/admin'.$path)->assertRedirect('/admin/login');
        }
    }

    public function test_branded_workspace_and_all_management_screens_use_the_new_theme(): void
    {
        $this->seed(WebsiteSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $response = $this->get('/admin')->assertOk()
            ->assertSee('data-filament-shadcn-theme=', false)
            ->assertSee('admin.css')
            ->assertSee('Your website workspace')
            ->assertSee('Pages &amp; banners', false)
            ->assertSee('Enquiries &amp; applications', false);
        $this->assertSame(1, substr_count($response->getContent(), 'class="workspace-shortcuts"'));
        foreach ([ProductResource::class, PageResource::class, GalleryImageResource::class, SubmissionResource::class] as $resource) {
            $response->assertSee($resource::getUrl());
            $this->get($resource::getUrl())->assertOk()->assertSee('data-filament-shadcn-theme=', false);
        }
    }

    public function test_the_new_theme_does_not_grant_panel_access_to_non_admins(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        foreach (['', '/products', '/pages', '/gallery-images', '/submissions'] as $path) {
            $this->get('/admin'.$path)->assertForbidden();
        }
    }
}
