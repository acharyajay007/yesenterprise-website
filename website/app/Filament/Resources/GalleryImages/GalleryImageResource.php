<?php

namespace App\Filament\Resources\GalleryImages;

use App\Filament\Resources\GalleryImages\Pages\ManageGalleryImages;
use App\Filament\Schemas\LocalizedFields;
use App\Models\GalleryImage;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GalleryImageResource extends Resource
{
    protected static ?string $model = GalleryImage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Photo gallery';

    protected static string|\UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('image_path')->disk('media')->directory('uploads')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(4096)->required(),
                Select::make('category')->options(['materials' => 'Materials', 'awnings' => 'Awnings', 'inspiration' => 'Supplier application examples'])->required(),
                Select::make('notice')->options(['supplied' => 'Owner photo (caption pending)', 'ownerCategory' => 'Owner stock (grade/brand pending)', 'supplierApplication' => 'Supplier application example', 'awning.customerPhoto' => 'Actual customer awning installation'])->required(),
                Toggle::make('published'),
                Toggle::make('media_approved')->helperText('Confirm rights and captions before enabling.'),
                ...LocalizedFields::sections(['title']),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')->disk('media')->imageSize(48)->square(),
                TextColumn::make('translations.en.title')->label('Photograph')->searchable()->wrap(),
                TextColumn::make('category'),
                IconColumn::make('published')->boolean(),
                IconColumn::make('media_approved')->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()->slideOver()->modalWidth('5xl'),
                DeleteAction::make(),
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
            'index' => ManageGalleryImages::route('/'),
        ];
    }
}
