<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Blast;
use App\Models\BlastRecipient;
use App\Models\Supporter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlastRecipient>
 */
class BlastRecipientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * **A claim, not a delivery**, because that is the row the sending path
     * actually writes first and the only state every other one passes through.
     * A factory defaulting to `sent_at` set would make the interesting cases --
     * the claim that was never resolved, the claim that failed -- the ones a
     * test has to ask for, when they are the ones worth writing about.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blast_id' => Blast::factory(),
            'supporter_id' => Supporter::factory(),
            'sent_at' => null,
            'failure_reason' => null,
        ];
    }

    /**
     * Indicate which supporter this is the copy for.
     */
    public function forSupporter(Supporter $supporter): static
    {
        return $this->state(fn (array $attributes) => [
            'supporter_id' => $supporter->getKey(),
        ]);
    }

    /**
     * Indicate which blast this is a recipient of.
     */
    public function ofBlast(Blast $blast): static
    {
        return $this->state(fn (array $attributes) => [
            'blast_id' => $blast->getKey(),
        ]);
    }

    /**
     * Indicate that the message was handed to the mailer.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'sent_at' => now(),
            'failure_reason' => null,
        ]);
    }

    /**
     * Indicate that this one message did not go, and why.
     *
     * `sent_at` is nulled in the same state rather than left to the caller: the
     * table's check constraint refuses a row claiming both, so a state that set
     * a reason on top of a sent row would be refused by the database instead of
     * producing a bad row -- which is the behaviour wanted, and a confusing way
     * to meet it from a factory.
     */
    public function failed(string $reason = 'The address was rejected.'): static
    {
        return $this->state(fn (array $attributes) => [
            'sent_at' => null,
            'failure_reason' => $reason,
        ]);
    }
}
