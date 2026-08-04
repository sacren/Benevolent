<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A narrowing of a campaign's supporter list that the campaign has named.
 *
 * Lives in the tenant migration set for the reason `supporters`, `blasts` and
 * `supporter_imports` do (D-1): a segment names one campaign's own people. The
 * mistake available here has its own flavour and is worth saying out loud -- a
 * *rule* looks like configuration rather than like data, and configuration
 * sounds central. It is not. "Everyone in M15" means a different set of human
 * beings in every campaign, so a central segments table would be one row read
 * by campaigns that share nothing but a postcode.
 *
 * **What a segment is (D-23): a saved, named rule, not a filter typed twice.**
 * Three shapes were on the table -- (a) query-string filtering on the supporter
 * list with nothing stored, (b) a stored named rule, and (c) (b) plus moving a
 * blast's own rule into it. This is (b). (c) is deliberately not here: whether
 * a blast points at a segment is D-26's, owned by a later step, and reaching
 * into `blasts` while thinking about segments is a change to sending that
 * nobody reviewed as one.
 *
 * The deciding fact is present-tense rather than anticipated. `blasts` already
 * stores a narrowing rule -- `postcode_prefixes` -- and three call sites
 * already read one with nobody watching, one of them a queue worker. So
 * "should a rule be stored?" is answered affirmatively by code that runs today.
 * What is not answered is whether the rule gets an identity, and the cost of it
 * not having one is that `postcode_prefixes` is *per blast*, with no
 * duplicate-a-blast action anywhere: a campaign lobbying one councillor over
 * three months types the same aim into every message, independently, with no
 * single place it is written down and no way to correct it once. Ten blasts to
 * one ward are ten independently typed copies of the aim, and an aim that is
 * wrong is wrong ten times, silently, in somebody's inbox. That is the shape
 * D-8 closed for supporters -- the address is the identity, recorded once,
 * rather than retyped per row.
 *
 * **What a segment may narrow on (D-24): postcodes, and nothing else.** Two
 * exclusions carry the correctness of this module and neither is a matter of
 * scope.
 *
 * A segment holds **no subscription predicate**, and there is no column here
 * that could carry one. The supporter list may legitimately show people who
 * unsubscribed -- an operator correcting a record has to find them -- while
 * `App\Blasts\BlastAudience` enforces subscribed-only by the shape of the
 * class, with no parameter that turns it off. One stored rule would otherwise
 * mean "these people" to a list and "these people, and never the ones who left"
 * to a send, and a rule object carrying a status predicate is one refactor away
 * from a message reaching somebody who asked not to be contacted. So a segment
 * says *where*, never *whether they may be contacted*: subscription state is
 * contactability, which is the sending path's invariant and, on the list, a
 * separate control that is no part of any stored rule.
 *
 * A segment holds **no string a campaign typed about a person** -- no name
 * fragment, no email fragment. Searching and segmenting are two jobs that the
 * word "filter" hides: searching is transient, aimed at one person, and may
 * touch a name; segmenting is stored, aimed at a group, and may not.
 * `SupporterController::destroy()` is the whole of this platform's erasure path
 * and it deletes a row, so a stored "okonkwo" would outlive the person named
 * Okonkwo with nothing that could ever reach it -- a sixth home for supporter
 * PII, added while the fifth's expiry never runs for want of a scheduler.
 * A postcode prefix is not that: it names a place rather than a person, and
 * `blasts.postcode_prefixes` already stores exactly this class of value, reached
 * by no erasure path, which D-10 did not count as a home. This table is
 * therefore not a new category. Had D-24 admitted a name fragment it would have
 * been, and the question would have been settled by running a deletion rather
 * than by this paragraph.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->id();

            // Who named it. Nullable and nulled on delete rather than
            // cascading, verbatim from `blasts` and `supporter_imports`: a
            // shared object other operators' work points at must outlive
            // whoever created it, and a segment that vanished when its author
            // left the campaign would take the aim of every message built on it
            // with it.
            //
            // This is authorship on the segment's own record, not the audit
            // trail. It does not reopen D-7 or pre-empt D-27, which owns
            // whether an edit or a deletion is an event the trail records.
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();

            // What the campaign calls this group. The whole point of (b) over
            // (a): a narrowing an operator can recognise and come back to.
            //
            // Unique, because the failure it prevents is aiming at the wrong
            // group -- two segments called "Chorlton" are indistinguishable at
            // the moment somebody picks one, and the message has gone by the
            // time anybody notices which was picked.
            //
            // **A plain unique index rather than the case-insensitive form on
            // lower(name), and the difference from `supporters` is deliberate
            // rather than an inconsistency.** D-8 took an expression index --
            // and this project's first raw DDL with it -- because case variation
            // is the commonest variation in imported *addresses* and the
            // duplicate it admits is silent: nobody reads a supporter list
            // looking for near-duplicates. A segment name is typed by one of a
            // campaign's handful of operators, and both rows appear in a list a
            // person is reading at the moment they choose. That duplicate is
            // visible and correctable, which is not worth a fourth use of a
            // mechanism reserved for forms the schema builder genuinely cannot
            // compile.
            $table->string('name')->unique();

            // The rule, in the same spelling `blasts` uses for the same thing,
            // so that the question of whether these two columns should become
            // one is visible rather than buried under two names (D-26, a later
            // step's).
            //
            // **NOT NULL, and that is where this column parts company with the
            // blast column it mirrors.** There, null is the audience rule
            // "everybody this campaign may contact" -- the one branch in
            // BlastAudience that widens rather than narrows -- and it is right
            // there, because a blast to the whole list is an ordinary thing to
            // want. A segment that narrows nothing is not a segment. Refusing
            // null here means the widening branch cannot be reached through a
            // segment at all, which costs nothing and removes a state that
            // would have no meaning.
            //
            // **No check constraint on emptiness, and the reason is that the
            // one on `blasts` was earned rather than stylistic.** That
            // constraint exists because a queued blast is already unrecallable:
            // a worker may claim it at any instant, so the irreversibility had
            // to be a fact about the row rather than a convention. A segment has
            // no unrecallable act -- nothing leaves the platform when one is
            // saved -- and an empty rule is *safe* here rather than dangerous,
            // because a rule naming nothing usable already matches nobody by the
            // fail-closed reading BlastAudience established. Safe-but-useless is
            // a form's job. Importing the check-constraint shape by analogy
            // would be taking the mechanism without the measurement that bought
            // it.
            $table->json('postcode_prefixes');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};
