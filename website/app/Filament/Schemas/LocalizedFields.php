<?php

namespace App\Filament\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

class LocalizedFields
{
    public static function sections(array $fields): array
    {
        return array_map(fn (string $locale) => Section::make(strtoupper($locale))
            ->description($locale === 'en' ? 'English' : 'Draft translation: review before publication')
            ->schema(array_map(function (string $field) use ($locale) {
                $name = 'translations.'.$locale.'.'.$field;

                return str_contains($field, 'description') || in_array($field, ['awning_body', 'source_note'], true)
                    ? Textarea::make($name)->label(ucwords(str_replace('_', ' ', $field)))->helperText($field === 'source_note' ? 'Internal source/review note. Not displayed on the public website.' : null)->required($field !== 'source_note')->maxLength(2000)->rows(3)
                    : TextInput::make($name)->label(ucwords(str_replace('_', ' ', $field)))->required()->maxLength(150);
            }, $fields))->columnSpanFull(), config('website.locales'));
    }
}
