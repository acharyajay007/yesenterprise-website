<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class ResumeDocument implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(__('site.resumeInvalid'));

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        if ($extension === 'pdf' && $value->getMimeType() === 'application/pdf') {
            $handle = fopen($value->getRealPath(), 'rb');
            if ($handle === false) {
                $fail(__('site.resumeInvalid'));

                return;
            }
            $header = fread($handle, 5);
            fclose($handle);
            if ($header === '%PDF-') {
                return;
            }
        } elseif ($extension === 'docx') {
            $zip = new ZipArchive;
            if ($zip->open($value->getRealPath()) === true) {
                $types = $zip->getFromName('[Content_Types].xml', 65536);
                $valid = $zip->locateName('word/document.xml') !== false
                    && $zip->locateName('word/vbaProject.bin') === false
                    && is_string($types)
                    && str_contains($types, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml');
                $zip->close();
                if ($valid) {
                    return;
                }
            }
        }
        $fail(__('site.resumeInvalid'));
    }
}
