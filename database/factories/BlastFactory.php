<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Blasts\BlastStatus;
use App\Models\Blast;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Blast>
 */
class BlastFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A draft addressed to every supporter the campaign may contact, which is
     * the row composing a blast actually produces and the only state a blast
     * can be edited from. `queued_at` is null because it must be: the table's
     * check constraint refuses a draft that carries one, so every state below
     * that moves the status has to move the timestamp with it.
     *
     * `operator_id` is null by default rather than conjuring an operator,
     * because null is a state the column exists to hold — the record of what a
     * campaign sent outlives whoever sent it. Tests that care about the author
     * say so with writtenBy().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operator_id' => null,
            'subject' => fake()->sentence(6),
            'body' => fake()->paragraphs(3, asText: true),
            'segment_id' => null,
            'postcode_prefixes' => null,
            'status' => BlastStatus::default(),
            'queued_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * Indicate which operator wrote the blast.
     */
    public function writtenBy(User $operator): static
    {
        return $this->state(fn (array $attributes) => [
            'operator_id' => $operator->getKey(),
        ]);
    }

    /**
     * Indicate that the blast is aimed at particular postcodes.
     *
     * Given exactly as an operator would type them, which is to say
     * inconsistently — the column they are matched against is stored as the
     * source gave it, so a factory producing tidy uniform prefixes would make
     * every test of the matching easier than the data.
     *
     * @param  list<string>  $prefixes
     */
    public function narrowedToPostcodes(array $prefixes): static
    {
        return $this->state(fn (array $attributes) => [
            'postcode_prefixes' => $prefixes,
        ]);
    }

    /**
     * Indicate that the blast is aimed at a segment the campaign has named.
     *
     * Leaves `postcode_prefixes` at the default null, and it has to: the two
     * are mutually exclusive by check constraint, so a state that set both
     * would be refused by the database rather than produce a blast with two
     * aims. That is deliberate -- a factory able to build the bad row is a
     * factory that makes the constraint look optional.
     */
    public function aimedAtSegment(Segment $segment): static
    {
        return $this->state(fn (array $attributes) => [
            'segment_id' => $segment->getKey(),
            'postcode_prefixes' => null,
        ]);
    }

    /**
     * Indicate that the campaign has committed the blast to sending.
     *
     * The status and the timestamp move together, and they have to: this is the
     * pairing the check constraint holds, so a state that set one without the
     * other would be refused by the database rather than produce a bad row.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlastStatus::Queued,
            'queued_at' => now(),
        ]);
    }

    /**
     * Indicate that messages are going out now.
     */
    public function sending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlastStatus::Sending,
            'queued_at' => now()->subMinute(),
        ]);
    }

    /**
     * Indicate that the send ran to the end of its audience.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlastStatus::Sent,
            'queued_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);
    }

    /**
     * Indicate that the send stopped before the end of its audience.
     *
     * Whatever went out stays gone, which is why this is a state of its own
     * rather than a way back to a draft.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BlastStatus::Failed,
            'queued_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);
    }
}
