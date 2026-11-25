<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\GalleryImages\GalleryImageResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Submissions\SubmissionResource;
use Filament\Widgets\Widget;

class Workspace extends Widget
{
    protected string $view = 'filament.widgets.workspace';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    public function shortcuts(): array
    {
        return [
            ['title' => 'Products', 'description' => 'Manage materials, photographs and popular picks.', 'icon' => 'heroicon-o-cube', 'url' => ProductResource::getUrl()],
            ['title' => 'Pages & banners', 'description' => 'Update homepage sliders, business pages and SEO.', 'icon' => 'heroicon-o-document-text', 'url' => PageResource::getUrl()],
            ['title' => 'Photo gallery', 'description' => 'Curate material photos and awning installations.', 'icon' => 'heroicon-o-photo', 'url' => GalleryImageResource::getUrl()],
            ['title' => 'Enquiries & applications', 'description' => 'Read requests, download resumes and retry emails.', 'icon' => 'heroicon-o-inbox-stack', 'url' => SubmissionResource::getUrl()],
        ];
    }
}
