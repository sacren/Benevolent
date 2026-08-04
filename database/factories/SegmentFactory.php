<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Segment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A named narrowing aimed at one postcode, which is the row that saving a
     * segment actually produces. Unlike the blast factory's default there is no
     * "aimed at everybody" state to fall back on: `postcode_prefixes` is NOT
     * NULL because a segment that narrows nothing is not a segment, so the
     * default has to name something.
     *
     * The name is drawn uniquely because the column is unique, and it is drawn
     * from place names because that is what a campaign calls a segment -- a
     * factory producing "Segment 1" would make every test read as though the
     * name were a serial number rather than something an operator chose.
     *
     * `operator_id` is null by default rather than conjuring an operator, for
     * the reason BlastFactory's is: null is a state the column exists to hold,
     * because a shared object outlives whoever created it. Tests that care
     * about authorship say so with namedBy().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operator_id' => null,
            'name' => fake()->unique()->city(),
            'postcode_prefixes' => ['M15'],
        ];
    }

    /**
     * Indicate which operator named the segment.
     */
    public function namedBy(User $operator): static
    {
        return $this->state(fn (array $attributes) => [
            'operator_id' => $operator->getKey(),
        ]);
    }

    /**
     * Indicate which postcodes the segment narrows to.
     *
     * Given exactly as an operator would type them, which is to say
     * inconsistently -- the column they will be matched against holds postcodes
     * exactly as their source gave them, so a factory producing tidy uniform
     * prefixes would make every test of the matching easier than the data.
     *
     * @param  list<string>  $prefixes
     */
    public function narrowedToPostcodes(array $prefixes): static
    {
        return $this->state(fn (array $attributes) => [
            'postcode_prefixes' => $prefixes,
        ]);
    }
}
