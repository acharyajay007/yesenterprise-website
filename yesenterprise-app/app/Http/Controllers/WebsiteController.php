<?php

namespace App\Http\Controllers;

use App\Models\GalleryImage;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class WebsiteController extends Controller
{
    public function home(): View
    {
        $page = Page::where('slug', 'home')->firstOrFail();
        $awningPage = Page::where('slug', 'awning')->firstOrFail();
        $locale = app()->getLocale();
        $heroSlides = collect([[
            'category' => null,
            'image' => $page->hero_image && (! config('website.indexable') || $page->media_approved) ? Storage::disk('media')->url($page->hero_image) : null,
            'alt' => __('heroEyebrow'),
            'eyebrow' => __('heroEyebrow'),
            'title' => $page->text('title'),
            'description' => $page->text('description'),
            'href' => route('catalogue', ['locale' => $locale]),
            'label' => __('viewCatalogue'),
        ]]);
        $publishedCategories = Product::published()->distinct()->pluck('category');
        foreach ($page->hero_banners ?? [] as $banner) {
            $category = $banner['category'];
            if (! in_array($category, config('website.categories'), true)
                || ! $publishedCategories->contains($category)
                || empty($banner['image_path'])
                || (config('website.indexable') && ! ($banner['media_approved'] ?? false))) {
                continue;
            }
            $heroSlides->push([
                'category' => $category,
                'image' => Storage::disk('media')->url($banner['image_path']),
                'alt' => __($category),
                'eyebrow' => $category === 'awnings' ? __('awning.eyebrow') : __('photos.browse'),
                'title' => $category === 'awnings' ? $awningPage->text('title') : __($category),
                'description' => $category === 'awnings' ? $awningPage->text('description') : __('merchandising.descriptions.'.$category),
                'href' => $category === 'awnings' ? route('awning', ['locale' => $locale]) : route('catalogue', ['locale' => $locale, 'category' => $category]),
                'label' => $category === 'awnings' ? __('awning.explore') : __('viewCatalogue'),
            ]);
        }

        return view('public.home', [
            'page' => $page,
            'heroSlides' => $heroSlides,
            'products' => Product::published()->where('is_popular', true)->orderBy('popular_order')->orderBy('id')->limit(8)->get(),
            'awning' => Product::published()->where('category', 'awnings')->first(),
            'awningPage' => $awningPage,
            'awningPhotos' => $this->awningPhotos()->reject(fn (GalleryImage $image) => $image->image_path === $awningPage->hero_image)->take(2),
        ]);
    }

    public function catalogue(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'in:'.implode(',', config('website.categories'))],
            'brand' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'popular' => ['nullable', 'in:1'],
            'sort' => ['nullable', 'in:popular,name-asc,name-desc,newest'],
        ]);
        $products = Product::published()
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['brand'] ?? null, fn ($query, $brand) => $query->where('brand', $brand))
            ->when($filters['popular'] ?? null, fn ($query) => $query->where('is_popular', true))
            ->orderBy('id')
            ->get()
            ->filter(function (Product $product) use ($filters) {
                $search = mb_strtolower(trim($filters['q'] ?? ''));

                return $search === '' || str_contains(mb_strtolower($product->text('title').' '.$product->text('description').' '.$product->brand.' '.__($product->category)), $search);
            });

        $sort = $filters['sort'] ?? 'popular';
        $products = match ($sort) {
            'name-asc' => $products->sortBy(fn (Product $product) => mb_strtolower($product->text('title')), SORT_NATURAL),
            'name-desc' => $products->sortByDesc(fn (Product $product) => mb_strtolower($product->text('title')), SORT_NATURAL),
            'newest' => $products->sortBy([['created_at', 'desc'], ['id', 'desc']]),
            default => $products->sortBy([['is_popular', 'desc'], ['popular_order', 'asc'], ['id', 'asc']]),
        };

        $page = (int) ($filters['page'] ?? 1);
        $paginated = new LengthAwarePaginator($products->forPage($page, 24)->values(), $products->count(), 24, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('public.catalogue', ['products' => $paginated, 'filters' => $filters, 'brands' => Product::published()->whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand')]);
    }

    public function product(string $locale, Product $product): View
    {
        abort_unless($product->published, 404);

        return view('public.product', [
            'product' => $product,
            'images' => $product->galleryImages(),
            'relatedProducts' => Product::published()->where('category', $product->category)->where('id', '!=', $product->id)->orderByDesc('is_popular')->orderBy('popular_order')->orderBy('id')->limit(4)->get(),
        ]);
    }

    public function gallery(Request $request): View
    {
        $category = $request->validate(['category' => ['nullable', 'in:materials,awnings,inspiration']])['category'] ?? null;

        return view('public.gallery', [
            'images' => GalleryImage::where('published', true)->when($category, fn ($query) => $query->where('category', $category))->get(),
        ]);
    }

    public function contact(Request $request): View
    {
        $product = Product::published()->where('slug', $request->query('product'))->first();
        $variant = in_array($request->query('variant'), $product?->variants ?? [], true) ? $request->query('variant') : null;

        return view('public.form', ['career' => false, 'page' => Page::where('slug', 'contact')->firstOrFail(), 'products' => Product::published()->get(), 'selectedProduct' => $product, 'selectedVariant' => $variant]);
    }

    public function careers(): View
    {
        return view('public.form', ['career' => true, 'products' => collect(), 'selectedProduct' => null, 'selectedVariant' => null]);
    }

    public function about(): View
    {
        return view('public.about', ['page' => Page::where('slug', 'about')->firstOrFail()]);
    }

    public function awning(): View
    {
        return view('public.awning', [
            'page' => Page::where('slug', 'awning')->firstOrFail(),
            'images' => $this->awningPhotos(),
            'products' => Product::published()->where('category', 'awnings')->orderBy('popular_order')->orderBy('id')->get(),
        ]);
    }

    private function awningPhotos(): Collection
    {
        return GalleryImage::where('published', true)->where('category', 'awnings')
            ->where('notice', 'awning.customerPhoto')
            ->when(config('website.indexable'), fn ($query) => $query->where('media_approved', true))
            ->orderBy('id')->get();
    }

    public function privacy(): View
    {
        return view('public.privacy');
    }

    public function sitemap(): Response
    {
        $urls = [];
        foreach (config('website.locales') as $locale) {
            foreach (['home', 'catalogue', 'awning', 'gallery', 'about', 'contact', 'careers', 'privacy'] as $route) {
                $urls[] = route($route, ['locale' => $locale]);
            }
            foreach (Product::published()->pluck('slug') as $slug) {
                $urls[] = route('product', ['locale' => $locale, 'product' => $slug]);
            }
        }

        return response()->view('public.sitemap', ['urls' => $urls], 200, ['Content-Type' => 'application/xml']);
    }
}
