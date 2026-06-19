<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Dream-physique / living-goal renders go to the configured provider. Default is OpenAI
        // (gpt-image-1) because Gemini refuses to make a real, identifiable person more muscular.
        $this->app->bind(\App\Services\Ai\ImageGenerator::class, function ($app) {
            return config('services.image.provider') === 'gemini'
                ? $app->make(\App\Services\Ai\NanoBananaClient::class)
                : $app->make(\App\Services\Ai\OpenAiImageClient::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
