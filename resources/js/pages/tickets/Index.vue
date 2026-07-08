<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, index as ticketsIndex, show } from '@/routes/tickets';
import type { PaginatedTickets, Ticket } from '@/types';

defineProps<{
    tickets: PaginatedTickets;
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

function formatDate(value: string | null): string {
    if (!value) {
        return 'No activity';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function statusVariant(ticket: Ticket): 'default' | 'secondary' | 'outline' {
    if (ticket.status === 'closed') {
        return 'secondary';
    }

    if (ticket.status === 'pending') {
        return 'outline';
    }

    return 'default';
}

function priorityVariant(
    ticket: Ticket,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (!ticket.priority) {
        return 'outline';
    }

    if (ticket.priority === 'urgent') {
        return 'destructive';
    }

    if (ticket.priority === 'high') {
        return 'default';
    }

    if (ticket.priority === 'low') {
        return 'outline';
    }

    return 'secondary';
}
</script>

<template>
    <Head title="Tickets" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Tickets</h1>
                <p class="text-sm text-muted-foreground">
                    Track customer issues, messages, tags, and status in one
                    place.
                </p>
            </div>

            <Button as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    New ticket
                </Link>
            </Button>
        </div>

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div v-if="tickets.data.length === 0" class="p-8 text-center">
                <p class="font-medium">No tickets found</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Create a ticket to get started.
                </p>
            </div>

            <div v-else class="divide-y divide-border">
                <Link
                    v-for="ticket in tickets.data"
                    :key="ticket.id"
                    :href="show(ticket.id)"
                    class="block p-4 transition-colors hover:bg-accent/50"
                >
                    <div
                        class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
                    >
                        <div class="min-w-0 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="font-mono text-sm text-muted-foreground"
                                >
                                    {{ ticket.number }}
                                </span>
                                <Badge :variant="statusVariant(ticket)">
                                    {{ ticket.status_label }}
                                </Badge>
                                <Badge
                                    v-if="ticket.priority_label"
                                    :variant="priorityVariant(ticket)"
                                >
                                    {{ ticket.priority_label }}
                                </Badge>
                                <Badge v-else variant="outline"
                                    >Untriaged</Badge
                                >
                            </div>

                            <div>
                                <p class="truncate font-medium">
                                    {{ ticket.subject }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ ticket.customer_name }} ·
                                    {{ ticket.customer_email }}
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-1.5">
                                <Badge
                                    v-for="tag in ticket.tags"
                                    :key="tag.slug"
                                    variant="outline"
                                >
                                    {{ tag.name }}
                                </Badge>
                            </div>
                        </div>

                        <div
                            class="text-sm text-muted-foreground lg:text-right"
                        >
                            <p>
                                {{ ticket.department_label ?? 'Untriaged' }} ·
                                {{ ticket.sentiment_label ?? 'Untriaged' }}
                            </p>
                            <p>{{ formatDate(ticket.last_message_at) }}</p>
                            <p>{{ ticket.messages_count ?? 0 }} messages</p>
                        </div>
                    </div>
                </Link>
            </div>
        </div>

        <div
            v-if="tickets.total > tickets.per_page"
            class="flex items-center justify-between gap-3 text-sm text-muted-foreground"
        >
            <span
                >Showing {{ tickets.from }}-{{ tickets.to }} of
                {{ tickets.total }}</span
            >
            <div class="flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!tickets.prev_page_url"
                    as-child
                >
                    <Link
                        v-if="tickets.prev_page_url"
                        :href="tickets.prev_page_url"
                    >
                        Previous
                    </Link>
                    <span v-else>Previous</span>
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!tickets.next_page_url"
                    as-child
                >
                    <Link
                        v-if="tickets.next_page_url"
                        :href="tickets.next_page_url"
                    >
                        Next
                    </Link>
                    <span v-else>Next</span>
                </Button>
            </div>
        </div>
    </div>
</template>
