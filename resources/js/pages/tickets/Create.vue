<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import TicketController from '@/actions/App/Http/Controllers/TicketController';
import FormSelect from '@/components/FormSelect.vue';
import InputError from '@/components/InputError.vue';
import TagInput from '@/components/TagInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { create, index as ticketsIndex } from '@/routes/tickets';
import type { TicketOptions, TicketTag } from '@/types';

defineProps<{
    options: TicketOptions;
    tags: TicketTag[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Tickets',
                href: ticketsIndex(),
            },
            {
                title: 'Create ticket',
                href: create(),
            },
        ],
    },
});

const selectedTags = ref<string[]>([]);
</script>

<template>
    <Head title="Create ticket" />

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Create ticket</h1>
            <p class="text-sm text-muted-foreground">
                Capture the customer, ticket metadata, tags, and first customer
                message.
            </p>
        </div>

        <Form
            v-bind="TicketController.store.form()"
            class="space-y-6 rounded-xl border border-border bg-card p-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-4 md:grid-cols-2">
                <div class="grid gap-2 md:col-span-2">
                    <Label for="subject">Subject</Label>
                    <Input
                        id="subject"
                        name="subject"
                        required
                        maxlength="150"
                    />
                    <InputError :message="errors.subject" />
                </div>

                <div class="grid gap-2">
                    <Label for="customer_name">Customer name</Label>
                    <Input
                        id="customer_name"
                        name="customer_name"
                        required
                        maxlength="100"
                    />
                    <InputError :message="errors.customer_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="customer_email">Customer email</Label>
                    <Input
                        id="customer_email"
                        type="email"
                        name="customer_email"
                        required
                        maxlength="255"
                    />
                    <InputError :message="errors.customer_email" />
                </div>

                <div class="grid gap-2">
                    <Label for="priority">Priority</Label>
                    <FormSelect
                        id="priority"
                        name="priority"
                        :options="options.priorities"
                        default-value="normal"
                    />
                    <InputError :message="errors.priority" />
                </div>

                <div class="grid gap-2">
                    <Label for="department">Department</Label>
                    <FormSelect
                        id="department"
                        name="department"
                        :options="options.departments"
                        default-value="support"
                    />
                    <InputError :message="errors.department" />
                </div>

                <div class="grid gap-2">
                    <Label for="sentiment">Sentiment</Label>
                    <FormSelect
                        id="sentiment"
                        name="sentiment"
                        :options="options.sentiments"
                        default-value="neutral"
                    />
                    <InputError :message="errors.sentiment" />
                </div>

                <div class="grid gap-2 md:col-span-2">
                    <Label>Tags</Label>
                    <TagInput v-model="selectedTags" :suggestions="tags" />
                    <InputError :message="errors.tags" />
                </div>

                <div class="grid gap-2 md:col-span-2">
                    <Label for="initial_message"
                        >Initial customer message</Label
                    >
                    <Textarea
                        id="initial_message"
                        name="initial_message"
                        required
                        maxlength="10000"
                        :rows="8"
                        class="min-h-40"
                    ></Textarea>
                    <InputError :message="errors.initial_message" />
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="outline" as-child>
                    <Link :href="ticketsIndex()">Cancel</Link>
                </Button>
                <Button type="submit" :disabled="processing"
                    >Create ticket</Button
                >
            </div>
        </Form>
    </div>
</template>
