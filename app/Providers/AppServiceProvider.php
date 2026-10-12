<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Agents only reach conversations of the pages assigned to them.
        Gate::define('access-conversation', fn (User $user, Conversation $conversation) => $user->canAccessPage($conversation->page_id));

        Gate::define('access-order', fn (User $user, Order $order) => $user->canAccessPage($order->page_id));

        // Admins manage every product; agents manage products of their own pages (not shared ones).
        Gate::define('manage-product', fn (User $user, Product $product) => $user->isAdmin()
            || ($product->page_id !== null && $user->canAccessPage($product->page_id)));
    }
}
