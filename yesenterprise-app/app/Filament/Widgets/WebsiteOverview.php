<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Models\Product;
use App\Models\Submission;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WebsiteOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $pending = Submission::where('delivery_status', 'pending')->count();

        return [
            Stat::make('Catalogue products', Product::count())
                ->description(Product::published()->count().' published products')
                ->icon('heroicon-o-cube')
                ->url(ProductResource::getUrl()),
            Stat::make('Enquiries', Submission::where('type', 'enquiry')->count())
                ->description('Customer product requests')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->url(SubmissionResource::getUrl(parameters: ['tableFilters' => ['type' => ['value' => 'enquiry']]])),
            Stat::make('Applications', Submission::where('type', 'application')->count())
                ->description('Career applications received')
                ->icon('heroicon-o-briefcase')
                ->url(SubmissionResource::getUrl(parameters: ['tableFilters' => ['type' => ['value' => 'application']]])),
            Stat::make('Email delivery pending', $pending)
                ->description('Saved requests awaiting email delivery')
                ->icon('heroicon-o-envelope')
                ->color($pending > 0 ? 'warning' : 'success')
                ->url(SubmissionResource::getUrl(parameters: ['tableFilters' => ['delivery_status' => ['value' => 'pending']]])),
        ];
    }
}
