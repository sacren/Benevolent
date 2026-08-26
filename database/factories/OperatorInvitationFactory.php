<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Authorization\OperatorRole;
use App\Models\OperatorInvitation;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperatorInvitation>
 */
class OperatorInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A live invitation to Staff, made on nobody's recorded authority. **No
     * token is set, deliberately**: the column mints it (D-54), and a factory
     * inventing its own would make every test pass against a table whose
     * default had been removed. Read it back with `fresh()`, since Eloquent does
     * not return a value the database defaulted.
     *
     * `role` is named rather than left to the column default so that the model
     * carries it after `create()` -- for the reason OperatorAuditObserver
     * documents, a model created without one holds no role attribute at all.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'role' => OperatorRole::default(),
        ];
    }

    /**
     * An invitation granting governance of the campaign.
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => OperatorRole::Owner,
        ]);
    }

    /**
     * Record who made the invitation, the way the writer records it.
     */
    public function invitedBy(User $operator): static
    {
        return $this->state(fn (array $attributes) => [
            'invited_by_id' => $operator->getKey(),
            'invited_by_label' => $operator->email,
        ]);
    }

    /**
     * An invitation sent this long before now -- a minute past its lifetime by
     * default, so it still holds a credential that no longer opens anything
     * (D-59). Pass a shorter age for one still inside it.
     */
    public function sentAgo(?CarbonInterval $age = null): static
    {
        $sent = now()->sub($age ?? CarbonInterval::minutes(OperatorInvitation::LIFETIME_DAYS * 24 * 60 + 1));

        return $this->state(fn (array $attributes) => [
            'created_at' => $sent,
            'updated_at' => $sent,
        ]);
    }

    /**
     * An invitation somebody has already used: spent, and holding no
     * credential, which is the only accepted state the schema allows.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'token' => null,
            'accepted_at' => now(),
        ]);
    }
}
