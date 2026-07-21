<?php

namespace App\Http\Middleware;

use App\Authorization\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * What the operator signed in to this campaign may do, as permission
     * strings the front end can test against.
     *
     * **Resolved permissions rather than the role, and that distinction is the
     * whole point.** A component that branches on `auth.user.role === 'owner'`
     * is a second copy of the role-to-permission map, written in another
     * language, with no test over it -- and it is silently wrong the day a
     * third role appears or a grant moves between roles. Asking what someone
     * may *do* survives both. The raw role is withheld from the serialized user
     * for the same reason, so the branch is not merely discouraged but absent.
     *
     * Answered through the gate rather than by reading OperatorRole's list
     * directly. The policy answers through $operator->can(), so a permission
     * that was never registered as a gate denies on the server; taking the
     * role's list here instead would render a control the server then refuses,
     * and the disagreement would show up as a 403 on a button that should not
     * have been there. Iterating the enum means a new permission is shared for
     * free, exactly as it is registered for free.
     *
     * @return list<string>
     */
    private function permissionsFor(mixed $operator): array
    {
        if (! $operator instanceof User) {
            // Central pages have no campaign and no operator. An empty list is
            // the honest answer, and it makes every `can` check on such a page
            // false rather than undefined.
            return [];
        }

        return array_values(array_map(
            fn (Permission $permission): string => $permission->value,
            array_filter(
                Permission::cases(),
                fn (Permission $permission): bool => Gate::forUser($operator)->allows($permission->value),
            ),
        ));
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'permissions' => $this->permissionsFor($request->user()),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
