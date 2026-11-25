<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Rules\ResumeDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $career = $this->routeIs('careers.submit');
        $product = Product::published()->where('slug', $this->input('product'))->first();

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:150'],
            'product' => ['nullable', Rule::exists('products', 'slug')->where('published', true)],
            'variant' => ['nullable', Rule::in($product?->variants ?? [])],
            'quantity' => ['nullable', 'string', 'max:150'],
            'role' => [$career ? 'required' : 'nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
            'consent' => ['required', 'accepted'],
            'website' => ['nullable', 'string', 'max:0'],
            'resume' => $career
                ? ['required', 'file', 'max:5120', new ResumeDocument]
                : ['prohibited'],
        ];
    }
}
