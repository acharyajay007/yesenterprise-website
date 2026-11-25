<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Schemas\LocalizedFields;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'slug';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slug')->required()->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->unique(ignoreRecord: true)->maxLength(150),
                Select::make('category')->options(array_combine(config('website.categories'), config('website.categories')))->required(),
                Select::make('brand')->options(array_combine(config('website.brands'), config('website.brands')))->nullable(),
                Toggle::make('published')->helperText('Show in the catalogue. The entire development site remains noindex.'),
                Toggle::make('is_popular')->label('Popular product')->helperText('Curated selection, not an automatic sales ranking. Published popular products appear on the homepage.'),
                TextInput::make('popular_order')->label('Popular display order')->numeric()->integer()->minValue(0)->maxValue(4294967295)->default(0)->required()->helperText('Lower numbers appear first. The homepage shows up to eight popular products.'),
                FileUpload::make('image_path')->label('Product or application photo')->disk('media')->directory('uploads')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(4096),
                Select::make('image_notice')->options(array_combine(['supplierApplication', 'supplierCategory', 'supplierProduct', 'site.supplierCatalogueImage', 'merchandising.rangeImage', 'ownerCategory', 'supplied'], ['Supplier application (not exact model)', 'Supplier category (not exact model)', 'Exact supplier product image', 'Supplier catalogue image (not confirmed stock)', 'Category/application illustration (exact brand/model photo pending)', 'Owner stock (grade/brand pending)', 'Owner photo (caption pending)']))->required(),
                TextInput::make('image_source_url')->label('Photograph source URL')->url()->maxLength(1000),
                Select::make('image_fit')->label('Catalogue photo framing')->options([
                    'cover' => 'Fill the photograph area (applications / textures)',
                    'contain' => 'Show the complete product (rolls / packaging)',
                ])->default('cover')->required(),
                Toggle::make('media_approved')->helperText('Enable only after publication rights and the factual caption are confirmed.'),
                Repeater::make('additional_images')->label('Additional product photographs')->defaultItems(0)
                    ->helperText('Drag to reorder. The primary photo stays first. Each additional photo has its own publication approval. Source/type fields remain admin-only.')
                    ->schema([
                        FileUpload::make('image_path')->label('Photograph')->disk('media')->directory('uploads')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(4096)->required(),
                        TextInput::make('captions.en')->label('English image description')->maxLength(180),
                        TextInput::make('captions.gu')->label('Gujarati image description')->maxLength(180),
                        TextInput::make('captions.hi')->label('Hindi image description')->maxLength(180),
                        Select::make('notice')->label('Internal photo type')->options([
                            'supplierProduct' => 'Exact supplier product',
                            'supplierCategory' => 'Supplier material / family (not exact model)',
                            'site.supplierCatalogueImage' => 'Supplier catalogue image (not confirmed stock)',
                            'supplierApplication' => 'Supplier application / range',
                            'merchandising.rangeImage' => 'Category reference (not exact brand/model)',
                            'awning.customerPhoto' => 'Customer awning application (fabric model not established)',
                            'supplied' => 'Owner supplied',
                        ])->default('merchandising.rangeImage')->required(),
                        TextInput::make('source_url')->label('Internal photograph source URL')->url()->maxLength(1000),
                        Toggle::make('media_approved')->default(false)->helperText('Confirm publication rights for this photograph.'),
                    ])->collapsible()->collapsed()->columnSpanFull(),
                TextInput::make('source_url')->label('Official supplier source URL')->url()->maxLength(1000),
                TagsInput::make('variants')->helperText('Only confirmed variant names; leave empty when unconfirmed.'),
                Repeater::make('specifications')->schema([
                    TextInput::make('label')->required()->maxLength(150),
                    TextInput::make('value')->required()->maxLength(250),
                ])->columns(2)->columnSpanFull(),
                ...LocalizedFields::sections(['title', 'description', 'seo_description', 'source_note']),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')->disk('media')->label('Photo')->imageSize(44)->square()
                    ->extraImgAttributes(fn (Product $record): array => ['style' => 'object-fit: '.$record->image_fit]),
                TextColumn::make('slug')->label('Product')
                    ->formatStateUsing(fn (Product $record): string => $record->text('title'))
                    ->description(fn (Product $record): string => $record->slug)
                    ->searchable(['slug', 'translations'])->wrap(),
                TextColumn::make('brand')->searchable(),
                TextColumn::make('category')->formatStateUsing(fn (string $state): string => __($state))->badge(),
                IconColumn::make('published')->boolean(),
                IconColumn::make('media_approved')->boolean(),
                IconColumn::make('is_popular')->label('Popular')->boolean()->sortable(),
                TextColumn::make('popular_order')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('image_notice')->label('Photo type')->formatStateUsing(fn (string $state) => __($state))->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')->options(array_combine(config('website.categories'), config('website.categories'))),
                Filter::make('popular')->query(fn (Builder $query) => $query->where('is_popular', true)),
                SelectFilter::make('photography')->label('Photo follow-up')->options([
                    'missing' => 'Missing photograph',
                    'reference' => 'Exact model photograph needed',
                    'approval' => 'Publication approval needed',
                ])->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'missing' => $query->where(fn (Builder $query) => $query->whereNull('image_path')->orWhere('image_path', '')),
                    'reference' => $query->whereNotNull('image_path')->where('image_notice', '!=', 'supplierProduct'),
                    'approval' => $query->whereNotNull('image_path')->where('media_approved', false),
                    default => $query,
                }),
            ])
            ->recordActions([
                EditAction::make()->slideOver()->modalWidth('5xl'),
                DeleteAction::make(),
                Action::make('preview')->url(fn (Product $record) => route('product', ['locale' => 'en', 'product' => $record]))->openUrlInNewTab()->visible(fn (Product $record) => $record->published),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProducts::route('/'),
        ];
    }
}
