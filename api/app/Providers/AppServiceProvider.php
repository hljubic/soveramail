<?php

namespace App\Providers;

use App\Services\Ai\Retrieval\DatabaseVectorStore;
use App\Services\Ai\Retrieval\VectorStore;
use App\Services\Imap\SmtpSender;
use App\Services\Imap\SymfonySmtpSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmtpSender::class, SymfonySmtpSender::class);
        $this->app->bind(VectorStore::class, DatabaseVectorStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
