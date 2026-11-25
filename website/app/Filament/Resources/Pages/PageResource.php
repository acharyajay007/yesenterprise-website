<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\Pages\Pages\ManagePages;
use App\Filament\Schemas\LocalizedFields;
use App\Models\Page;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Pages & banners';

    protected static string|\UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slug')->disabled()->dehydrated(false),
                FileUpload::make('hero_image')->disk('media')->directory('uploads')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(4096)->label('Page photograph')->visible(fn (?Page $record) => $record?->slug !== 'contact'),
                TextInput::make('hero_source_url')->label('Photograph source URL (private)')->url()->maxLength(2048)->visible(fn (?Page $record) => $record?->slug !== 'contact'),
                Toggle::make('media_approved')->helperText('Confirm publication rights before enabling.'),
                Repeater::make('hero_banners')
                    ->label('Homepage category banners')
                    ->helperText('Use original HD images of at least 1920 x 1080. Supplier scenes illustrate applications, not Yes Enterprise customer work or an exact product model. Sources and approval notices stay private. Confirm rights and category suitability before approval.')
                    ->visible(fn (?Page $record) => $record?->slug === 'home')
                    ->maxItems(count(config('website.categories')))
                    ->collapsed()
                    ->itemLabel(fn (array $state): string => __($state['category'] ?? 'photos.featured'))
                    ->schema([
                        Select::make('category')->options(collect(config('website.categories'))->mapWithKeys(fn (string $category): array => [$category => __($category)])->all())->required()->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                        FileUpload::make('image_path')->label('HD banner photograph')->disk('media')->directory('uploads/banners')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(4096)->required(),
                        TextInput::make('source_url')->label('Source URL (private)')->url()->maxLength(2048),
                        Toggle::make('media_approved')->helperText('Confirm publication rights and category suitability before enabling.'),
                    ]),
                ...LocalizedFields::sections(['title', 'description', 'seo_description']),
                ...array_map(fn ($section) => $section->visible(fn (?Page $record) => $record?->slug === 'home'), LocalizedFields::sections(['awning_title', 'awning_body'])),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slug')->label('Page')->formatStateUsing(fn (string $state): string => ucfirst($state))->searchable(),
                TextColumn::make('updated_at')->label('Last updated')->dateTime('d M Y, H:i')->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()->slideOver()->modalWidth('5xl'),
            ]);
    }

    public static function canCreate(): bool
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
            'index' => ManagePages::route('/'),
        ];
    }
}
