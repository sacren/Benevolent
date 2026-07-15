<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Gives each campaign a password broker connected to its own database.
 *
 * `auth.password` is a container singleton, and the manager behind it caches
 * every broker it builds. A broker holds a DatabaseTokenRepository, and that
 * repository is handed a Connection object once, at the moment it is first
 * built -- `$this->app['db']->connection(null)`, resolved against whichever
 * database was default right then. Nothing ever revisits it.
 *
 * Under one campaign per process that is invisible: php-fpm resolves the broker
 * inside the request that needs it, against the campaign that request already
 * switched to. It becomes a defect the moment one process serves two campaigns,
 * which is what `tenants:run` does, and what a queue worker taking successive
 * jobs for different campaigns does.
 *
 * Measured before this class existed, with three campaigns in the registry:
 * `tenants:run auth:clear-resets` reported "Expired reset tokens cleared
 * successfully" three times, and had cleared the *first* campaign's tokens three
 * times while never touching the other two. Reading the repository's connection
 * while campaign context said Beta returned Alpha's database. A wrong answer,
 * reported as a clean run -- and the reason the retention schedule in
 * routes/console.php cannot work without this.
 *
 * The cure is to stop the singleton outliving the context that gave it meaning:
 * forget the instance whenever campaign context changes, and let the next
 * caller resolve one against the database that is actually current. Resolution
 * is lazy, so a request that never asks for a broker pays nothing.
 *
 * Deliberately named for the one binding with a measured defect rather than
 * generalized into a list of singletons to forget. Other singletons hold a
 * connection the same way -- the queue manager and the session manager both do
 * -- but those are pinned to the central connection on purpose (L-7, L-15) and
 * forgetting them would undo the pin. There is no general rule to apply here,
 * only a shape to recognize: a singleton that captured a connection is wrong
 * for every campaign after the first. Should a second one surface, it earns its
 * own bootstrapper beside this one, and the naming keeps that visible.
 */
class CampaignPasswordBrokerTenancyBootstrapper implements TenancyBootstrapper
{
    public function __construct(private readonly Application $app) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->forgetPasswordBroker();
    }

    public function revert(): void
    {
        // Symmetric on purpose. Leaving a campaign's broker cached after tenancy
        // ends would hand the next central caller a connection to a campaign's
        // database -- the same defect pointing the other way.
        $this->forgetPasswordBroker();
    }

    /**
     * Drop the cached broker manager so the next resolve builds a fresh one.
     *
     * Only `auth.password` is forgotten. `auth.password.broker` is a plain bind
     * rather than a singleton and resolves through the manager on every call, so
     * it follows automatically.
     */
    private function forgetPasswordBroker(): void
    {
        $this->app->forgetInstance('auth.password');
    }
}
