<?php

namespace App\Filament\Resources\Submissions;

use App\Filament\Resources\Submissions\Pages\ManageSubmissions;
use App\Models\Submission;
use App\Services\DeliverSubmission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'Enquiries & applications';

    protected static string|\UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...array_map(fn (string $field) => TextInput::make($field)->disabled()->dehydrated(false), ['type', 'name', 'email', 'phone', 'company', 'product', 'variant', 'quantity', 'role', 'locale', 'delivery_status']),
                Textarea::make('message')->disabled()->dehydrated(false)->columnSpanFull(),
                Textarea::make('delivery_error')->disabled()->dehydrated(false)->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Received')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state): string => $state === 'application' ? 'Career application' : 'Product enquiry'),
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('product'),
                TextColumn::make('delivery_status')->badge()->color(fn (string $state): string => $state === 'delivered' ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('type')->options(['enquiry' => 'Enquiries', 'application' => 'Career applications']),
                SelectFilter::make('delivery_status')->options(['pending' => 'Pending delivery', 'delivered' => 'Delivered']),
            ])
            ->recordActions([
                ViewAction::make()->slideOver()->modalWidth('3xl'),
                Action::make('resume')->label('Download private resume')->url(fn (Submission $record) => route('resume.download', $record))->visible(fn (Submission $record) => (bool) $record->resume_path),
                Action::make('retry')->label('Retry email')->requiresConfirmation()->visible(fn (Submission $record) => $record->delivery_status === 'pending')->action(function (Submission $record) {
                    $delivered = app(DeliverSubmission::class)->deliver($record);
                    $notification = Notification::make()->title($delivered ? 'Notification email sent' : 'Saved; email still pending');
                    ($delivered ? $notification->success() : $notification->warning())->send();
                }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSubmissions::route('/'),
        ];
    }
}
