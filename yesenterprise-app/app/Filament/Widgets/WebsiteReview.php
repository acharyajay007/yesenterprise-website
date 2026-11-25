<?php

namespace App\Filament\Widgets;

use App\Models\Page;
use App\Models\Product;
use Filament\Widgets\Widget;

class WebsiteReview extends Widget
{
    protected string $view = 'filament.widgets.website-review';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 5;

    public function coverage(): array
    {
        return json_decode(file_get_contents(database_path('seeders/data/supplier-products.json')), true, flags: JSON_THROW_ON_ERROR)['coverage'];
    }

    public function reviewCounts(): array
    {
        return [
            'Missing photographs' => Product::where(fn ($query) => $query->whereNull('image_path')->orWhere('image_path', ''))->count(),
            'Exact-model photographs to review' => Product::whereNotNull('image_path')->where('image_notice', '!=', 'supplierProduct')->count(),
            'Photograph publication approvals pending' => Product::whereNotNull('image_path')->where('media_approved', false)->count(),
            'Homepage banner approvals pending' => collect(Page::where('slug', 'home')->first()?->hero_banners ?? [])
                ->where('media_approved', false)->count()
                + Page::where('slug', 'home')->whereNotNull('hero_image')->where('media_approved', false)->count(),
        ];
    }
}
