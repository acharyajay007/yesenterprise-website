<?php

use App\Http\Controllers\ResumeController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\WebsiteController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/'.config('website.default_locale'), 302));
Route::get('/sitemap.xml', [WebsiteController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', fn () => response(
    config('website.indexable')
        ? "User-agent: *\nDisallow: /admin\nDisallow: /private\nSitemap: ".route('sitemap')."\n"
        : "User-agent: *\nDisallow: /\n",
    200, ['Content-Type' => 'text/plain'],
));
Route::get('/private/resumes/{submission}', ResumeController::class)->middleware('auth')->name('resume.download');
Route::prefix('{locale}')->whereIn('locale', config('website.locales'))->middleware('locale')->group(function () {
    Route::get('/', [WebsiteController::class, 'home'])->name('home');
    Route::get('/catalogue', [WebsiteController::class, 'catalogue'])->name('catalogue');
    Route::get('/awnings', [WebsiteController::class, 'awning'])->name('awning');
    Route::get('/products/{product:slug}', [WebsiteController::class, 'product'])->name('product');
    Route::get('/gallery', [WebsiteController::class, 'gallery'])->name('gallery');
    Route::get('/about', [WebsiteController::class, 'about'])->name('about');
    Route::get('/contact', [WebsiteController::class, 'contact'])->name('contact');
    Route::get('/careers', [WebsiteController::class, 'careers'])->name('careers');
    Route::get('/privacy', [WebsiteController::class, 'privacy'])->name('privacy');
    Route::post('/contact', [SubmissionController::class, 'store'])->middleware('throttle:submissions')->name('contact.submit');
    Route::post('/careers', [SubmissionController::class, 'store'])->middleware('throttle:submissions')->name('careers.submit');
});
