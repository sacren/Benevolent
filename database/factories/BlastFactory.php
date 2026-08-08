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
            'committed_prefixes' => null,
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
     *
     * **It freezes the aim when the blast is already committed**, because
     * `blasts_committed_aim_is_frozen` requires it and because a factory able
     * to build a committed segment-aimed blast with no frozen rule would build
     * exactly the row D-27 exists to prevent. Both orderings are covered: this
     * reads the status a committing state has already set, and the four states
     * below read the segment this one has already set.
     */
    public function aimedAtSegment(Segment $segment): static
    {
        return $this->state(fn (array $attributes) => [
            'segment_id' => $segment->getKey(),
            'postcode_prefixes' => null,
            'committed_prefixes' => self::statusOf($attributes)->isCommitted()
                ? $segment->postcode_prefixes
                : null,
        ]);
    }

    /**
     * The frozen rule a blast in a committing state owes, if it points at one.
     *
     * Reads the segment rather than taking it as an argument, because the four
     * states below are reached as `aimedAtSegment($s)->queued()` and have only
     * the id by then. A query in a factory state is worth it here: the
     * alternative is four states that quietly build rows the database refuses.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string>|null
     */
    private static function frozenAimFor(array $attributes): ?array
    {
        $segmentId = $attributes['segment_id'] ?? null;

        if ($segmentId === null) {
            return null;
        }

        // whereKey()->firstOrFail() rather than findOrFail(), which takes an
        // array as readily as a key and is therefore typed as returning a model
        // *or* a collection -- a distinction static analysis is right to insist
        // on and that would reach this list as an array of segments.
        return Segment::query()->whereKey($segmentId)->firstOrFail()->postcode_prefixes;
    }

    /**
     * The status a blast is being built with, whichever way it was given.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function statusOf(array $attributes): BlastStatus
    {
        $status = $attributes['status'] ?? BlastStatus::default();

        return $status instanceof BlastStatus
            ? $status
            : BlastStatus::from((string) $status);
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
            'committed_prefixes' => self::frozenAimFor($attributes),
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
            'committed_prefixes' => self::frozenAimFor($attributes),
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
            'committed_prefixes' => self::frozenAimFor($attributes),
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
            'committed_prefixes' => self::frozenAimFor($attributes),
        ]);
    }
}
