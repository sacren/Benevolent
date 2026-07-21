import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Whether the signed-in operator may do a given thing.
 *
 * The one place a component asks about authority, so that the shape of the
 * shared prop is stated once rather than spelled out at every call site. The
 * values are App\Authorization\Permission's backing strings, resolved on the
 * server through the same gates the policies consult.
 *
 * There is deliberately no helper for asking what *role* somebody has, and no
 * way to get at one: the server does not send it. A control is hidden because
 * of what its viewer may do, never because of what they are called.
 */
export function usePermissions() {
    const page = usePage();

    const permissions = computed<string[]>(
        () => page.props.auth?.permissions ?? [],
    );

    /**
     * Hiding a control is a courtesy, not a defence -- the policy refuses the
     * request whatever the browser rendered. So this returning false wrongly
     * costs an operator a button they should have had, and returning true
     * wrongly costs them a 403 rather than access.
     */
    function can(permission: string): boolean {
        return permissions.value.includes(permission);
    }

    return { permissions, can };
}
