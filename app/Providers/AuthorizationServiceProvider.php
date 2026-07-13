<?php

declare(strict_types=1);

namespace App\Providers;

use App\Authorization\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the campaign authorization spine.
 *
 * Sits here rather than in app/Authorization/ for the same reason
 * TenancyServiceProvider sits beside app/Tenancy/: a service provider is a
 * framework lifecycle object, and this application keeps all of them together
 * where bootstrap/providers.php expects to find them. The spine's own
 * vocabulary — the roles and the permissions — lives in app/Authorization/.
 *
 * There is deliberately no Gate::before here. A `before` callback that waves a
 * super-admin past every check is the obvious next thing to reach for, and it
 * would be premature: no such actor exists, there is no central identity to
 * hang one on, and D-1 defers the cross-campaign identity layer until a real
 * consumer appears. Building the escape hatch before the actor bakes in a
 * guess about who deserves it, and a `before` hook that returns non-null
 * short-circuits every policy beneath it — the hardest kind of authorization
 * bug to see.
 */
class AuthorizationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPermissionGates();
    }

    /**
     * Define one gate per permission, answered by the operator's role.
     *
     * Iterating the enum rather than listing gates by hand is what keeps the
     * two in step: a permission added to the enum is registered here for free,
     * and none can be forgotten. Forgetting one would not raise anything —
     * Laravel denies an ability it has never heard of — so the failure would
     * look exactly like a working guard that happens to say no.
     */
    private function registerPermissionGates(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $operator): bool => $operator->role->allows($permission),
            );
        }
    }
}
