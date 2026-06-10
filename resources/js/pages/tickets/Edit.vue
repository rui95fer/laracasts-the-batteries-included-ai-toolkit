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
import { index as ticketsIndex, show } from '@/routes/tickets';
import type { Ticket, TicketOptions, TicketTag } from '@/types';

const props = defineProps<{
    ticket: Ticket;
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
        ],
    },
});

const selectedTags = ref<string[]>(props.ticket.tags.map((tag) => tag.name));
</script>

<template>
    <Head :title="`Edit ${ticket.number}`" />

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Edit {{ ticket.number }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    Update ticket metadata. Messages are managed from the ticket
                    timeline.
                </p>
            </div>

            <Button variant="outline" as-child>
                <Link :href="show(ticket.id)">Back to ticket</Link>
            </Button>
        </div>

        <Form
            v-bind="TicketController.update.form(ticket.id)"
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
                        :default-value="ticket.subject"
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
                        :default-value="ticket.customer_name"
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
                        :default-value="ticket.customer_email"
                    />
                    <InputError :message="errors.customer_email" />
                </div>

                <div class="grid gap-2">
                    <Label for="status">Status</Label>
                    <FormSelect
                        id="status"
                        name="status"
                        :options="options.statuses"
                        :default-value="ticket.status"
                    />
                    <InputError :message="errors.status" />
                </div>

                <div class="grid gap-2">
                    <Label for="priority">Priority</Label>
                    <FormSelect
                        id="priority"
                        name="priority"
                        :options="options.priorities"
                        :default-value="ticket.priority"
                    />
                    <InputError :message="errors.priority" />
                </div>

                <div class="grid gap-2">
                    <Label for="department">Department</Label>
                    <FormSelect
                        id="department"
                        name="department"
                        :options="options.departments"
                        :default-value="ticket.department"
                    />
                    <InputError :message="errors.department" />
                </div>

                <div class="grid gap-2">
                    <Label for="sentiment">Sentiment</Label>
                    <FormSelect
                        id="sentiment"
                        name="sentiment"
                        :options="options.sentiments"
                        :default-value="ticket.sentiment"
                    />
                    <InputError :message="errors.sentiment" />
                </div>

                <div class="grid gap-2 md:col-span-2">
                    <Label>Tags</Label>
                    <TagInput v-model="selectedTags" :suggestions="tags" />
                    <InputError :message="errors.tags" />
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="outline" as-child>
                    <Link :href="show(ticket.id)">Cancel</Link>
                </Button>
                <Button type="submit" :disabled="processing"
                    >Save changes</Button
                >
            </div>
        </Form>
    </div>
</template>
