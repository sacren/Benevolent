<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import SegmentController from '@/actions/App/Http/Controllers/SegmentController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index } from '@/routes/segments';

const { seat, congress } = defineProps<{
    seat: string | null;
    congress: string;
}>();

/**
 * Which rule the segment will hold: ZIP code prefixes or one congressional
 * district (D-37), never both.
 *
 * Only the chosen field is rendered, so only it is submitted — a hidden field
 * still carrying text from before the choice changed would arrive as a second
 * rule. The server decides the kind from `narrow_by` alone and ignores the
 * other field regardless, so this is tidiness for the operator rather than the
 * guard.
 */
const narrowBy = ref<'postcodes' | 'district'>('postcodes');

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Segments', href: index() },
            { title: 'Name a segment', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="Name a segment" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Name a segment"
            description="A group of ZIP codes, or a congressional district, saved under a name, so the supporter list can be narrowed to it without typing it again."
        />

        <Form
            v-bind="SegmentController.store.form()"
            class="max-w-2xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    autocomplete="off"
                    placeholder="What this campaign calls this group"
                />
                <p class="text-sm text-muted-foreground">
                    Names are unique within this campaign, so two segments
                    cannot be confused at the moment somebody picks one.
                </p>
                <InputError :message="errors.name" />
            </div>

            <!--
                Chosen once, when the segment is named: its edit form keeps the
                kind it was named with. A blast may be aimed at a segment of ZIP
                codes and not yet at a district one, so turning one into the
                other would re-aim every draft pointing at it.
            -->
            <fieldset class="grid gap-2">
                <legend class="mb-2 text-sm font-medium">Narrows by</legend>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="narrowBy"
                        type="radio"
                        name="narrow_by"
                        value="postcodes"
                        data-test="narrow-by-postcodes"
                    />
                    ZIP codes
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="narrowBy"
                        type="radio"
                        name="narrow_by"
                        value="district"
                        data-test="narrow-by-district"
                    />
                    A congressional district
                </label>
                <InputError :message="errors.narrow_by" />
            </fieldset>

            <div v-if="narrowBy === 'postcodes'" class="grid gap-2">
                <!--
                    Required here where the same field on a blast is optional,
                    and the label says so rather than leaving it to the
                    validation error. An empty rule on a blast means everyone;
                    a segment that narrows to nothing is not a segment.
                -->
                <Label for="postcode_prefixes">ZIP codes</Label>
                <Input
                    id="postcode_prefixes"
                    name="postcode_prefixes"
                    required
                    autocomplete="off"
                    placeholder="902, 021"
                />
                <p class="text-sm text-muted-foreground">
                    Supporters whose ZIP code starts with one of these. Spacing
                    does not matter.
                </p>
                <InputError :message="errors.postcode_prefixes" />
            </div>

            <!--
                The campaign's own seat is filled in, because it is the district
                a candidate committee most often narrows to — and it is only
                filled in: what is saved is the district typed here, so the
                segment does not follow the seat if the seat is recorded again.

                The sentence under the field is the part that must not be
                softened (D-32, D-43). A district segment reaches only
                supporters whose ZIP code lies wholly inside the district, as
                the named Congress drew it; in a dense district that leaves out
                more ZIP codes than it keeps.
            -->
            <div v-else class="grid gap-2">
                <Label for="district">District</Label>
                <Input
                    id="district"
                    name="district"
                    required
                    autocomplete="off"
                    :default-value="seat ?? ''"
                    placeholder="MA-07"
                />
                <p class="text-sm text-muted-foreground">
                    A state and a district number, like MA-07, as the
                    {{ congress }} Congress drew it. The segment reaches only
                    supporters whose ZIP code lies wholly inside the district;
                    anyone in a ZIP code crossing its boundary is left out,
                    because their ZIP code cannot say which side they live on.
                </p>
                <InputError :message="errors.district" />
            </div>

            <!--
                Said on the page the segment is named on, because this is the
                one place the two consumers of a rule differ and the difference
                is easy to assume the wrong way round.
            -->
            <p class="text-sm text-muted-foreground">
                A segment says where, not who may be written to. The supporter
                list shows everyone it names, including anyone who has
                unsubscribed; a blast never writes to them. A blast cannot yet
                be aimed at a segment that narrows by district.
            </p>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing"
                    >Save segment</Button
                >
                <Button as-child variant="outline">
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
