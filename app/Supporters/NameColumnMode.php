<?php

declare(strict_types=1);

namespace App\Supporters;

/**
 * How the file being imported carries the person's name.
 *
 * **The operator states this; the importer never works it out.** That is the
 * point of the whole enum, and it is the same rule the schema was built on: a
 * supporter's `name` is what the source gave, and `given_name` and
 * `family_name` are provenance -- what we were actually told. Nothing splits a
 * name and nothing infers a part.
 *
 * Reading a header called "Name" and deciding it must be a full name, or seeing
 * "First"/"Last" and deciding to join them, is the same fabrication the three
 * name columns exist to prevent, wearing the header row as a disguise. A guess
 * that is right for most of a list is still a guess about every row in it, and
 * the rows it is wrong about -- mononyms, names with no clean split,
 * family-name-first presentation -- are exactly the people a campaign would
 * then address incorrectly forever.
 *
 * So the file's headers are read and shown, and the operator says what they
 * mean.
 */
enum NameColumnMode: string
{
    /**
     * One column holding the name as it should be displayed.
     *
     * Both name parts stay null, meaning *we were never told* rather than *we
     * guessed*. This is the honest outcome for a single-column source, and it
     * is why `given_name` and `family_name` are nullable.
     */
    case Single = 'single';

    /**
     * Two columns, a given name and a family name, both recorded as given.
     *
     * The display `name` is then the two joined in that order -- which is a
     * presentation decision this application makes and can revisit, because the
     * parts it was told are kept. That is the asymmetry the schema was chosen
     * for: a join is recomputable from the parts, and the parts are not
     * recoverable from a join.
     */
    case Split = 'split';

    /**
     * The file carries no name at all.
     *
     * An ordinary shape rather than a broken one: a petition widget that asked
     * only for an email address produces exactly this, and those people are
     * perfectly contactable. All three name columns stay null.
     */
    case None = 'none';
}
