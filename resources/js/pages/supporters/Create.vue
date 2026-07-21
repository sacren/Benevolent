<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import SupporterController from '@/actions/App/Http/Controllers/SupporterController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index } from '@/routes/supporters';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Supporters', href: index() },
            { title: 'Add a supporter', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="Add a supporter" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Add a supporter"
            description="Only an email address is required. Record the rest only if you actually know it."
        />

        <Form
            v-bind="SupporterController.store.form()"
            class="max-w-xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autocomplete="off"
                    placeholder="someone@example.org"
                />
                <p class="text-sm text-muted-foreground">
                    This is how the campaign tells one supporter from another,
                    so it is the one thing that cannot be left out.
                </p>
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    autocomplete="off"
                    placeholder="As you would address them"
                />
                <InputError :message="errors.name" />
            </div>

            <!--
                Offered separately, and clearly optional, because an operator
                who has just spoken to someone knows where the boundary between
                the parts falls -- and that is information nothing can recover
                afterwards. An operator who does not know leaves them blank and
                nothing is invented, which is the same rule the importer follows
                and the reason the schema has three name columns rather than one.
            -->
            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 text-sm text-muted-foreground">
                    If you know how their name splits, record it. Leave these
                    blank rather than guessing.
                </legend>

                <div class="grid gap-2">
                    <Label for="given_name">Given name</Label>
                    <Input
                        id="given_name"
                        name="given_name"
                        autocomplete="off"
                    />
                    <InputError :message="errors.given_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="family_name">Family name</Label>
                    <Input
                        id="family_name"
                        name="family_name"
                        autocomplete="off"
                    />
                    <InputError :message="errors.family_name" />
                </div>
            </fieldset>

            <div class="grid gap-2">
                <Label for="postcode">Postcode</Label>
                <Input
                    id="postcode"
                    name="postcode"
                    autocomplete="off"
                    placeholder="Recorded exactly as given"
                />
                <InputError :message="errors.postcode" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="add-supporter-button">
                    Add supporter
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
