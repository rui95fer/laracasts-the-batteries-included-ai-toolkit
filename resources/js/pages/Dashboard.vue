<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2, Clock, Flame } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as ticketsIndex } from '@/routes/tickets';

defineProps<{
    ticketStats: {
        open: number;
        pending: number;
        closed: number;
        urgent: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
                <p class="text-sm text-muted-foreground">
                    A quick snapshot of your support queue.
                </p>
            </div>

            <Button as-child>
                <Link :href="ticketsIndex()">View tickets</Link>
            </Button>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm text-muted-foreground">Open</p>
                        <p class="mt-2 text-3xl font-semibold">
                            {{ ticketStats.open }}
                        </p>
                    </div>
                    <AlertCircle class="size-8 text-primary" />
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm text-muted-foreground">Pending</p>
                        <p class="mt-2 text-3xl font-semibold">
                            {{ ticketStats.pending }}
                        </p>
                    </div>
                    <Clock class="size-8 text-muted-foreground" />
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm text-muted-foreground">Closed</p>
                        <p class="mt-2 text-3xl font-semibold">
                            {{ ticketStats.closed }}
                        </p>
                    </div>
                    <CheckCircle2 class="size-8 text-muted-foreground" />
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm text-muted-foreground">Urgent</p>
                        <p class="mt-2 text-3xl font-semibold">
                            {{ ticketStats.urgent }}
                        </p>
                    </div>
                    <Flame class="size-8 text-destructive" />
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-6">
            <h2 class="font-medium">Active work</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Start from the ticket list to triage open and pending
                conversations.
            </p>
            <Button class="mt-4" variant="outline" as-child>
                <Link :href="ticketsIndex()">Open ticket queue</Link>
            </Button>
        </div>
    </div>
</template>
