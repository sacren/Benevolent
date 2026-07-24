<?php

declare(strict_types=1);

namespace App\Authorization;

/**
 * What an operator is within their campaign.
 *
 * Roles are defined in code rather than stored as campaign data, and that is a
 * deliberate choice rather than a shortcut. A campaign's operators live in its
 * own database (D-1), so data-defined roles would need a per-campaign cache to
 * avoid a query per authorization check — and the cache is where this goes
 * wrong: Stancl's CacheManager tags cache calls per campaign only when they
 * arrive through __call, so anything reaching for a store directly is shared
 * across every campaign. A role set that lives in code needs no cache at all,
 * so that whole failure mode cannot arise here.
 *
 * The cost is that a campaign cannot invent its own roles. Nothing has asked
 * to. The trigger to revisit is the first campaign needing a role we do not
 * ship, or permissions that must be editable at runtime; at that point these
 * cases become rows and this enum becomes their seed.
 */
enum OperatorRole: string
{
    /**
     * Governs the campaign: everything Staff may do, plus authority over who
     * else may act in it.
     */
    case Owner = 'owner';

    /**
     * Does the campaign's work without governing it. The role every operator
     * gets unless something deliberately grants more.
     */
    case Staff = 'staff';

    /**
     * The role an operator gets when nothing grants them more.
     *
     * Named here rather than left as a literal at each call site so that the
     * database default, the factory and registration cannot drift apart.
     */
    public static function default(): self
    {
        return self::Staff;
    }

    /**
     * Everything this role may do.
     *
     * Listed in full per role rather than by inheriting a lesser role's set. A
     * hierarchy reads well right up to the first permission that belongs to a
     * middle role and not the top one, and by then it has to be unwound; an
     * explicit list never does. It also reads directly as the answer to "what
     * may Staff do", which is the question this file exists to answer.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => [
                Permission::ManageOperators,
                Permission::ViewSupporters,
                Permission::EditSupporters,
                Permission::ExportSupporters,
                Permission::DeleteSupporters,
            ],
            self::Staff => [
                Permission::ViewSupporters,
                Permission::EditSupporters,
            ],
        };
    }

    /**
     * Whether this role carries the given permission.
     */
    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), strict: true);
    }
}
