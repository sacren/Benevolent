<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Supporter;
use App\Supporters\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supporter>
 */
class SupporterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default is the row a real import usually produces: a source that
     * supplied the name already split, so all three name columns agree and the
     * display string is the join of the parts rather than a guess about them.
     * The states below are the other shapes a genuine list contains.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $givenName = fake()->firstName();
        $familyName = fake()->lastName();

        return [
            'name' => $givenName.' '.$familyName,
            'given_name' => $givenName,
            'family_name' => $familyName,
            'email' => fake()->unique()->safeEmail(),
            'postcode' => fake()->postcode(),
            'subscription_status' => SubscriptionStatus::default(),
        ];
    }

    /**
     * Indicate that the source supplied the name as a single string.
     *
     * Both parts stay null, which is the truthful record that we were never
     * told where the boundary falls — not an invitation to guess one.
     */
    public function fromSingleStringSource(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => fake()->name(),
            'given_name' => null,
            'family_name' => null,
        ]);
    }

    /**
     * Indicate that the source supplied an address and no name at all.
     *
     * A petition widget that asked only for an email produces exactly this, and
     * the person is perfectly contactable, which is why nothing here is
     * required except the address itself.
     */
    public function withoutName(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => null,
            'given_name' => null,
            'family_name' => null,
        ]);
    }

    /**
     * Indicate that the supporter has asked not to be contacted.
     */
    public function unsubscribed(): static
    {
        return $this->state(fn (array $attributes) => [
            'subscription_status' => SubscriptionStatus::Unsubscribed,
        ]);
    }
}
