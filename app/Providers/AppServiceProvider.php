<?php

namespace App\Providers;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Setting;
use App\Models\User;
use App\Services\Extraction\ExtractionManager;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        Gate::define('manage-settings', fn (User $user) => $user->isAdmin());

        View::composer('layouts.app', function ($view) {
            $provider = app(ExtractionManager::class)->active();
            $view->with([
                'navAwaiting' => Document::whereIn('status', [DocumentStatus::NeedsReview->value, DocumentStatus::Ready->value])->count(),
                'navProvider' => $provider,
                'brandName' => Setting::workspaceName(),
                'brandLogo' => (bool) Setting::get('logo_path'),
            ]);
        });
    }
}
