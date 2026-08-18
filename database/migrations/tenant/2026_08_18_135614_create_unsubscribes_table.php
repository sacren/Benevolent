<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One withdrawal the product saw happen: somebody used the link in a message
 * to stop the campaign writing to them.
 *
 * D-47. D-45 took one outcome into scope, the unsubscribe the product serves
 * itself, and this is where it is recorded. **A row is an event, never a
 * state.** `supporters.subscription_status` stays the one answer to "may this
 * person be written to", and it is what `BlastAudience` reads. This table can
 * disagree with it in both directions without either being wrong: a supporter
 * set unsubscribed by an operator, a seeder or before anything recorded
 * withdrawals has no row here, and a supporter who withdrew and was later put
 * back by an operator has a row and is subscribed. That is why there is no
 * status column and no supporter key -- nothing here can be read as whether
 * somebody is subscribed, so nothing can come to rely on it.
 *
 * **Its own table rather than a column on `blast_recipients`, because both
 * questions that decided it came back yes when they were run.** One copy of
 * one message can be followed by more than one withdrawal: through the same
 * link, an unsubscribe, an operator re-subscribing them and a second
 * unsubscribe were measured as two real changes of status. And a withdrawal
 * can name no copy at all: a link mailed before messages carried their own
 * identifier holds only the supporter's token, and a supporter reached by two
 * blasts made that request with nothing to say which. A column on a recipient
 * row can hold neither. Not the audit trail either: its subject is changes to
 * who may act in this campaign (D-4), and the person here is not an operator.
 *
 * Nothing writes this table yet. The unsubscribe request becomes its writer
 * when a message can name itself in its link.
 *
 * **Nothing here needs an erasure to reach it (D-49), measured by running
 * one.** With no supporter key, a row the link could not attribute names
 * nobody from the moment it is written. A row that could points at a
 * recipient row, and an erasure nulls that row's key -- so afterwards it says
 * that a copy of this blast was followed by a withdrawal, and no longer says
 * whose, which is the answer D-10 gave `sent_at` for the same reason: the
 * blast's own record stays true, and the person is gone from it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('unsubscribes', function (Blueprint $table) {
            $table->id();

            // Which copy of which message the link came from, or null when the
            // link could not say.
            //
            // Null is a statement, not a gap: the request carried a token that
            // names a supporter and no message, so the withdrawal is recorded
            // as unattributed rather than credited to the latest blast, which
            // would be telling a campaign that a message did something it may
            // not have done.
            //
            // **Cascading, never nulled, and the difference is the same one.**
            // Nulling would quietly turn a withdrawal attributed to a message
            // into an unattributed one -- a claim that it arrived through a
            // link that could not name its message, which would be false. A
            // recipient row goes only with its blast, and nothing in the
            // application deletes a blast; this says what should happen if
            // anything ever does.
            //
            // Not indexed: nothing reads this table yet, and whether a count
            // per blast needs one is a measurement for the step that shows it.
            $table->foreignId('blast_recipient_id')->nullable()->constrained()->cascadeOnDelete();

            // When the withdrawal happened. There is no updated_at because a
            // row is never revised: what somebody did is not a record that
            // later changes, the same reason audit_entries has none.
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unsubscribes');
    }
};
