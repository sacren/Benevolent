<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/blasts';
import type { Blast, BlastStatus } from '@/types';

const props = defineProps<{
    blasts: Blast[];

    /**
     * The campaign's withdrawals that name no message at all, which is why they
     * arrive beside the list rather than inside a row (D-48).
     */
    unattributedWithdrawals: number;
}>();

/**
 * What each state is called on screen, and what it looks like.
 *
 * A record keyed by the union rather than a chain of comparisons, so that a
 * case added to App\Blasts\BlastStatus and mirrored into the type is a
 * compile-time error here instead of a blast silently rendering as a blank
 * badge. That is the same reason the import page asks the server whether it has
 * finished rather than listing the terminal states itself.
 */
const statusLabels: Record<BlastStatus, string> = {
    draft: 'Draft',
    queued: 'Queued',
    sending: 'Sending',
    sent: 'Sent',
    failed: 'Failed',
};

const statusVariants: Record<
    BlastStatus,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    draft: 'outline',
    queued: 'secondary',
    sending: 'secondary',
    sent: 'default',
    failed: 'destructive',
};

/**
 * What has actually happened to this blast, in the campaign's own terms.
 *
 * **Keyed by the status union rather than written as a chain of conditions**,
 * for the same reason the labels above are: a case added to
 * App\Blasts\BlastStatus and mirrored into the type becomes a compile-time
 * error here instead of a blast silently reporting nothing.
 *
 * **The `queued` line is the one that matters, and it is Finding A's obligation
 * paid on screen.** No queue worker runs anywhere yet. An import stuck at
 * "Queued" costs an operator some time; a *blast* queued and never sent is a
 * campaign believing it has contacted its supporters when it has not -- a
 * failure that looks like success from the only place anyone can see it. So the
 * page says the thing that is true rather than the thing that is reassuring, and
 * says it in words rather than leaving it to be inferred from a badge.
 */
const progressSummaries: Record<BlastStatus, (blast: Blast) => string> = {
    draft: () => 'Not sent',
    queued: () => 'Waiting — no worker has picked this up yet',
    sending: (blast) => `${blast.reached_count} sent so far`,
    sent: (blast) =>
        blast.failed_count === 0
            ? `${blast.reached_count} reached`
            : `${blast.reached_count} reached, ${blast.failed_count} refused`,
    failed: (blast) => `${blast.reached_count} reached before it stopped`,
};

function progressSummary(blast: Blast): string {
    return progressSummaries[blast.status](blast);
}

/**
 * What came back after this message went out, in the campaign's own terms.
 *
 * **Keyed by the status union for the same reason the two lookups above are**,
 * and the `draft` and `queued` arms are not padding: a message nobody has sent
 * has no outcome to report, and a page that answered "Nobody" for it would be
 * making a claim about inboxes it has never reached. The em dash says there is
 * nothing to say yet, which is a different statement from "nobody left".
 */
const outcomeSummaries: Record<BlastStatus, (blast: Blast) => string> = {
    draft: () => '—',
    queued: () => '—',
    sending: (blast) => outcomeOf(blast),
    sent: (blast) => outcomeOf(blast),
    failed: (blast) => outcomeOf(blast),
};

/**
 * The four answers a message that has actually gone out can give.
 *
 * **The order of these branches is the whole honesty of this function, in the
 * same way the audience summary's order is its correctness.** `withdrawn_count`
 * is meaningless on its own: it is a count of people who used a link that could
 * name this message, and a blast whose copies carried no such link has a zero
 * that means *nobody could have been counted* rather than *nobody left*. So the
 * basis is asked about before the count, every time.
 *
 * **This is D-42's lesson arriving where §5 predicted it would.** That decision
 * found four unrelated reasons a supporter's district could be unknown, all
 * rendered as one word, and recorded that a page reporting the same word for
 * unrelated reasons teaches an operator to ignore the word. The same trap is
 * here with three reasons for a zero -- nobody left, nothing could be recorded,
 * and only some of the copies could speak -- and each one gets its own sentence
 * rather than a shared one.
 *
 * `reached_count === 0` comes first of all, because a send that reached nobody
 * has no basis for any of the three. A blast whose every copy was refused is
 * that case, and it is not "not recorded": nothing was mailed to be recorded
 * about.
 */
function outcomeOf(blast: Blast): string {
    if (blast.reached_count === 0) {
        return '—';
    }

    if (blast.attributable_count === 0) {
        return 'Not recorded — these copies carried no per-message link';
    }

    if (blast.attributable_count < blast.reached_count) {
        // A floor rather than a total, so the basis is named beside it. This
        // arm exists because a send resumed across the release that added
        // per-message links holds copies of both kinds, which is a state the
        // database permits and no writer prevents.
        const basis =
            blast.attributable_count === 1
                ? '1 copy that carried a link'
                : `${blast.attributable_count} copies that carried a link`;

        return `${blast.withdrawn_count} of ${basis}`;
    }

    return blast.withdrawn_count === 0 ? 'Nobody' : `${blast.withdrawn_count}`;
}

function outcomeSummary(blast: Blast): string {
    return outcomeSummaries[blast.status](blast);
}

/**
 * The withdrawals this campaign cannot attribute to any message.
 *
 * Shown beside the list rather than in it, because there is no row they belong
 * to: they arrived through links that name a person and not a message, so
 * crediting them to any blast -- or spreading them across all of them -- would
 * be the misattribution the module is built to refuse.
 */
const unattributedSummary = computed(() =>
    props.unattributedWithdrawals === 1
        ? '1 withdrawal could not say which message prompted it'
        : `${props.unattributedWithdrawals} withdrawals could not say which message prompted them`,
);

/**
 * How a blast describes who it is aimed at, in a list with no room for a count.
 *
 * **The segment is asked about first, and the order is the whole correctness of
 * this function.** A blast aimed at a segment carries no `postcode_prefixes` of
 * its own -- the database forbids both -- so a version that tested the column
 * first would answer "everyone subscribed" for a blast narrowed to one precinct,
 * which is the exact opposite of who it goes to. Server-side the same ordering
 * is what BlastAudience draws first and for the same reason.
 *
 * **Only naming neither is the whole list**, which mirrors the server rather
 * than paraphrasing it: an aim naming no usable postcode reaches nobody, so
 * saying "everyone subscribed" for it would state the opposite again. Saying
 * what naming neither means in words is still the point -- an empty cell would
 * read as a blast aimed at nobody, which is the other way round.
 *
 * A blast pointing at a segment the server did not load is named by its id, and
 * that branch is deliberately not "everyone": this page always loads it, and
 * the fallback fails in the direction that understates reach rather than
 * overstating it.
 *
 * **A committed blast is described from what it froze, never from its segment
 * (D-27).** A segment stays editable after a blast has gone out -- deliberately,
 * because a named narrowing a campaign cannot correct is worth little -- so the
 * segment on the row is today's rule and today's name. Describing a sent blast
 * from it reports the wrong narrowing as though it were the one that went out,
 * which is the reporting half of the exposure the send path closed.
 *
 * **The status decides, not the presence of a frozen rule**, mirroring
 * BlastAudience exactly: `draft` is the one state that still follows its
 * pointer, and everything past it reads what it kept. Mirroring the server's
 * spelling is what keeps the sentence on this page and the set the send walked
 * the same claim.
 */
function audienceSummary(blast: Blast): string {
    if (blast.segment_id !== null) {
        if (blast.status !== 'draft') {
            return committedAudienceSummary(blast);
        }

        return blast.segment
            ? `Subscribed in ${blast.segment.name}`
            : `Subscribed in segment ${blast.segment_id}`;
    }

    if (blast.postcode_prefixes === null) {
        return 'Everyone subscribed';
    }

    if (blast.postcode_prefixes.length === 0) {
        return 'Nobody: no ZIP codes named';
    }

    return `Subscribed in ${blast.postcode_prefixes.join(', ')}`;
}

/**
 * Whether two stored rules say the same thing.
 *
 * Order-sensitive on purpose. A frozen rule is copied verbatim from the segment
 * it was taken from, so an unchanged segment gives back an identical list in an
 * identical order; anything else is a rule somebody edited, and reordering the
 * prefixes of a narrowing is an edit like any other.
 */
function sameRule(one: string[], other: string[]): boolean {
    return (
        one.length === other.length &&
        one.every((prefix, index) => prefix === other[index])
    );
}

/**
 * What a blast the campaign has already committed was aimed at.
 *
 * **Two frozen columns, and which one holds the rule is which sentence this
 * writes (D-38).** A blast aimed at a segment of prefixes froze those prefixes
 * and can be compared against the segment as it stands; one aimed at a district
 * froze the ZIP codes its seat claimed, and is counted rather than listed --
 * a seat holds up to 494 of them, and a list of 17 is no more readable than a
 * number.
 *
 * **It names neither the seat nor the Congress, and that is a limit of the row
 * rather than a choice about wording.** `committed_zip_codes` records the ZIP
 * codes and nothing else: not the seat they were claimed for, and not the map
 * they were claimed from. The segment still on the row carries today's seat,
 * and reading it here would describe an act the campaign committed under one
 * map using a value that has since been free to move -- which is the same
 * defect the freeze exists to prevent, arriving through a sentence instead of
 * through an audience. So the seat appears only as the segment's name, which is
 * provenance rather than a claim, and D-43's requirement to name a Congress
 * beside a district does not arise, because no district is named.
 *
 * The null-both case stays unreachable through a stored row -- the check
 * constraint gives every committed segment-aimed blast exactly one frozen rule
 * -- and is still written, in the same direction the server's fallback goes:
 * say the segment rather than name a rule this blast never used.
 */
function committedAudienceSummary(blast: Blast): string {
    const zipCodes = blast.committed_zip_codes;

    if (zipCodes !== null) {
        const where =
            zipCodes.length === 1
                ? '1 ZIP code'
                : `${zipCodes.length} ZIP codes`;

        return blast.segment
            ? `Subscribed in the ${where} frozen from ${blast.segment.name}`
            : `Subscribed in the ${where} frozen when it was sent`;
    }

    const frozen = blast.committed_prefixes;

    if (frozen === null) {
        return `Subscribed in segment ${blast.segment_id}`;
    }

    const where = frozen.length === 0 ? 'no ZIP codes' : frozen.join(', ');

    if (!blast.segment) {
        return `Subscribed in ${where}`;
    }

    // A segment's prefixes are null only when it narrows by district, and a
    // segment keeps the kind it was named with (D-37), so a blast that froze
    // prefixes cannot find its segment narrowing by district: read as a rule
    // this one never used.
    if (sameRule(frozen, blast.segment.postcode_prefixes ?? [])) {
        return `Subscribed in ${blast.segment.name}`;
    }

    return `Subscribed in ${where} — ${blast.segment.name} has changed since`;
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Blasts',
                href: index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Blasts" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Blasts"
                :description="
                    blasts.length === 1
                        ? '1 message this campaign has written'
                        : `${blasts.length} messages this campaign has written`
                "
            />

            <Button as-child>
                <Link :href="create()">Write a blast</Link>
            </Button>
        </div>

        <!--
            Rendered only when there are any, because a campaign with none is
            not owed a sentence about a thing that has not happened. It sits
            above the table rather than in it for the reason the summary
            explains: these withdrawals belong to no blast on this page.
        -->
        <p
            v-if="unattributedWithdrawals > 0"
            class="text-sm text-muted-foreground"
            data-test="unattributed-withdrawals"
        >
            {{ unattributedSummary }}
        </p>

        <div
            v-if="blasts.length === 0"
            class="rounded-xl border border-sidebar-border/70 p-8 text-center dark:border-sidebar-border"
        >
            <p class="text-sm text-muted-foreground">
                No blasts yet. Anything this campaign writes to its supporters
                will appear here.
            </p>
        </div>

        <div
            v-else
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <th scope="col" class="px-4 py-3 font-medium">
                            Subject
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            Aimed at
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            Status
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">Sent</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            Asked to stop
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="blast in blasts"
                        :key="blast.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3">{{ blast.subject }}</td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ audienceSummary(blast) }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="statusVariants[blast.status]">
                                {{ statusLabels[blast.status] }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <span :data-test="`blast-progress-${blast.id}`">{{
                                progressSummary(blast)
                            }}</span>
                            <!--
                                Why a send stopped, where the campaign can read
                                it. Central failed_jobs has no campaign column
                                and no campaign surface reads it, so a failure
                                written only there is one nobody here can see.
                            -->
                            <span
                                v-if="blast.failure_reason"
                                class="mt-1 block text-xs"
                                :data-test="`blast-failure-${blast.id}`"
                                >{{ blast.failure_reason }}</span
                            >
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <!--
                                What came back, which the module exists to be
                                able to say at all. It is a sentence rather than
                                a bare number wherever a bare number would be
                                read as a total it is not.
                            -->
                            <span :data-test="`blast-outcome-${blast.id}`">{{
                                outcomeSummary(blast)
                            }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <!--
                                Only a draft is opened for editing, because only
                                a draft can be changed: everything past it is
                                downstream of a decision the campaign cannot
                                take back. A committed blast gets no link rather
                                than a link that leads to a refusal.
                            -->
                            <Link
                                v-if="blast.status === 'draft'"
                                :href="edit(blast.id)"
                                class="underline underline-offset-4"
                                >Edit</Link
                            >
                            <span v-else class="text-muted-foreground"
                                >&mdash;</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
