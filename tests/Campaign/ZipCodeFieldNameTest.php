<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\User;
use App\Supporters\NameColumnMode;
use Illuminate\Support\Facades\Queue;
use Tests\Support\StagedImport;

/*
 * What the framework's own refusals call the ZIP field, on every form that has
 * one.
 *
 * **D-41 kept the identifiers and changed the labels, and the framework reads
 * the identifier.** Its built-in messages fill `:attribute` from the field's
 * name, so until a request says otherwise an operator submitting an empty
 * segment was told "The postcode prefixes field is required." beneath a field
 * labelled "ZIP codes". D-41 named exactly that message as the trigger for
 * renaming the identifiers, and it had already fired when D-41 was written.
 * The remedy it named was the wrong one: a request's attributes() is a label,
 * which is what was wrong, and it costs no rename, no migration and no change
 * to the import mappings already stored under `postcode`.
 *
 * Each test drives the real form over HTTP and pins the whole sentence, so a
 * request that loses its attributes() -- or a new form that never had one --
 * shows the identifier here rather than to an operator.
 */

test('adding a supporter by hand calls the field a ZIP code', function (): void {
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters'), [
            'email' => 'long-zip@example.test',
            'postcode' => str_repeat('9', 256),
        ])
        ->assertInvalid(['postcode' => 'The ZIP code field must not be greater than 255 characters.']);
});

test('correcting a supporter calls the field a ZIP code', function (): void {
    $supporter = Supporter::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch($this->campaignUrl('/supporters/'.$supporter->getKey()), [
            'email' => $supporter->email,
            'postcode' => str_repeat('9', 256),
            'subscription_status' => $supporter->subscription_status->value,
        ])
        ->assertInvalid(['postcode' => 'The ZIP code field must not be greater than 255 characters.']);
});

test('naming a segment calls its prefixes ZIP codes', function (): void {
    // `required` is the refusal an operator meets first, by submitting the form
    // before typing anything into it.
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/segments'), [
            'name' => 'Not yet aimed',
            'postcode_prefixes' => '',
        ])
        ->assertInvalid(['postcode_prefixes' => 'The ZIP codes field is required.']);
});

test('composing a blast calls its prefixes ZIP codes', function (): void {
    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/blasts'), [
            'subject' => 'Aimed at too many',
            'body' => 'Refused.',
            'postcode_prefixes' => str_repeat('9', 1001),
        ])
        ->assertInvalid(['postcode_prefixes' => 'The ZIP codes field must not be greater than 1000 characters.']);
});

test('mapping an import calls the column a ZIP code column', function (): void {
    Queue::fake();

    $import = StagedImport::of("Email,ZIP\nama.boateng@example.test,90210\n");

    $this->actingAs(User::factory()->create())
        ->post($this->campaignUrl('/supporters/imports/'.$import->getKey()), [
            'email' => 'Email',
            'name_mode' => NameColumnMode::None->value,
            'postcode' => 'Postal code',
        ])
        ->assertInvalid(['postcode' => 'The selected ZIP code column is invalid.']);

    Queue::assertNothingPushed();
});
