<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { MessageSquare, Pencil, Sparkles, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import TicketChatController from '@/actions/App/Http/Controllers/TicketChatController';
import TicketController from '@/actions/App/Http/Controllers/TicketController';
import TicketDraftReplyStreamController from '@/actions/App/Http/Controllers/TicketDraftReplyStreamController';
import TicketMessageController from '@/actions/App/Http/Controllers/TicketMessageController';
import TicketTriageController from '@/actions/App/Http/Controllers/TicketTriageController';
import FormSelect from '@/components/FormSelect.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { edit, index as ticketsIndex, show } from '@/routes/tickets';
import type { Ticket, TicketMessage, TicketOptions } from '@/types';

const props = defineProps<{
    ticket: Ticket;
    options: TicketOptions;
}>();

const messageTypeOptions = computed(() =>
    props.options.messageTypes.map((option) => ({
        ...option,
        disabled:
            props.ticket.status === 'closed' && option.value === 'agent_reply',
    })),
);

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

const editingMessageId = ref<number | null>(null);
const editBody = ref('');

const draft = ref('');
const isStreaming = ref(false);
const streamError = ref<string | null>(null);
const newMessageBody = ref('');
const streamController = ref<AbortController | null>(null);

function formatDate(value: string | null): string {
    if (!value) {
        return 'Never';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function startEditing(message: TicketMessage): void {
    editingMessageId.value = message.id;
    editBody.value = message.body;
}

function stopEditing(): void {
    editingMessageId.value = null;
    editBody.value = '';
}

function confirmMessageDelete(event: Event): void {
    if (!window.confirm('Delete this message permanently?')) {
        event.preventDefault();
    }
}

function messageBadgeVariant(
    message: TicketMessage,
): 'default' | 'secondary' | 'outline' {
    if (message.type === 'internal_note') {
        return 'outline';
    }

    if (message.type === 'agent_reply') {
        return 'secondary';
    }

    return 'default';
}

function csrfToken(): string {
    const meta = document.querySelector('meta[name="csrf-token"]');

    return meta instanceof HTMLMetaElement ? (meta.content ?? '') : '';
}

async function streamDraftReply(): Promise<void> {
    if (isStreaming.value) {
        return;
    }

    streamError.value = null;
    draft.value = '';
    isStreaming.value = true;
    streamController.value = new AbortController();

    try {
        const response = await fetch(
            TicketDraftReplyStreamController.url(props.ticket.id),
            {
                method: 'POST',
                headers: {
                    Accept: 'text/event-stream',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                signal: streamController.value.signal,
            },
        );

        if (!response.ok || !response.body) {
            throw new Error('Failed to stream draft reply.');
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
            const { value, done } = await reader.read();

            if (done) {
                break;
            }

            buffer += decoder.decode(value, { stream: true });

            const parts = buffer.split('\n\n');
            buffer = parts.pop() ?? '';

            for (const part of parts) {
                if (! part.startsWith('data:')) {
                    continue;
                }

                const payload = part.replace(/^data:\s*/, '').trim();

                if (payload === '[DONE]') {
                    return;
                }

                try {
                    const event = JSON.parse(payload) as {
                        type?: string;
                        delta?: string;
                    };

                    if (event.type === 'text_delta' && event.delta) {
                        draft.value += event.delta;
                    }
                } catch {
                    // Ignore malformed chunks and keep reading.
                }
            }
        }
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        streamError.value =
            error instanceof Error
                ? error.message
                : 'Failed to stream draft reply.';
    } finally {
        isStreaming.value = false;
        streamController.value = null;
    }
}

function cancelDraftReply(): void {
    streamController.value?.abort();
}

function insertDraftIntoReply(): void {
    newMessageBody.value = draft.value;
}
</script>

<template>
    <Head :title="ticket.number ?? 'Ticket'" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
        >
            <div class="space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-sm text-muted-foreground">{{
                        ticket.number
                    }}</span>
                    <Badge>{{ ticket.status_label }}</Badge>
                    <Badge v-if="ticket.priority_label" variant="secondary">{{
                        ticket.priority_label
                    }}</Badge>
                    <Badge v-else variant="outline">Untriaged</Badge>
                    <Badge v-if="ticket.department_label" variant="outline">{{
                        ticket.department_label
                    }}</Badge>
                    <Badge v-else variant="outline">Untriaged</Badge>
                    <Badge v-if="ticket.sentiment_label" variant="outline">{{
                        ticket.sentiment_label
                    }}</Badge>
                    <Badge v-else variant="outline">Untriaged</Badge>
                </div>

                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ ticket.subject }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ ticket.customer_name }} · {{ ticket.customer_email }}
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

            <div class="flex flex-wrap gap-2">
                <Form
                    v-bind="TicketTriageController.form(ticket.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" :disabled="processing">
                        <Sparkles class="size-4" />
                        {{ processing ? 'Triaging...' : 'Triage' }}
                    </Button>
                </Form>

                <Button variant="outline" as-child>
                    <Link :href="edit(ticket.id)">
                        <Pencil class="size-4" />
                        Edit
                    </Link>
                </Button>

                <Dialog>
                    <DialogTrigger as-child>
                        <Button variant="destructive">
                            <Trash2 class="size-4" />
                            Delete
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="TicketController.destroy.form(ticket.id)"
                            v-slot="{ processing }"
                            class="space-y-6"
                        >
                            <DialogHeader>
                                <DialogTitle
                                    >Delete {{ ticket.number }}?</DialogTitle
                                >
                                <DialogDescription>
                                    This permanently deletes the ticket and all
                                    messages. This cannot be undone.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    :disabled="processing"
                                >
                                    Permanently delete
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[1fr_22rem]">
            <div class="space-y-4">
                <div class="rounded-xl border border-border bg-card p-4">
                    <h2 class="font-medium">Conversation</h2>
                    <p class="text-sm text-muted-foreground">
                        Customer messages, agent replies, and internal notes.
                    </p>
                </div>

                <div class="space-y-3">
                    <div
                        v-for="message in ticket.messages"
                        :key="message.id"
                        class="rounded-xl border border-border bg-card p-4"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Badge
                                        :variant="messageBadgeVariant(message)"
                                    >
                                        {{ message.type_label }}
                                    </Badge>
                                    <span class="text-sm font-medium">{{
                                        message.author_name
                                    }}</span>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    {{ message.author_email }} ·
                                    {{ formatDate(message.created_at) }}
                                </p>
                            </div>

                            <div class="flex gap-2">
                                <Button
                                    v-if="editingMessageId !== message.id"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="startEditing(message)"
                                >
                                    Edit
                                </Button>
                                <Form
                                    v-bind="
                                        TicketMessageController.destroy.form({
                                            ticket: ticket.id,
                                            message: message.id,
                                        })
                                    "
                                    :options="{ preserveScroll: true }"
                                    @submit="confirmMessageDelete"
                                    v-slot="{ processing }"
                                >
                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="processing"
                                    >
                                        Delete
                                    </Button>
                                </Form>
                            </div>
                        </div>

                        <Form
                            v-if="editingMessageId === message.id"
                            v-bind="
                                TicketMessageController.update.form({
                                    ticket: ticket.id,
                                    message: message.id,
                                })
                            "
                            :options="{ preserveScroll: true }"
                            class="mt-4 space-y-3"
                            v-slot="{ errors, processing }"
                            @success="stopEditing"
                        >
                            <Textarea
                                v-model="editBody"
                                name="body"
                                :rows="5"
                                class="w-full"
                            ></Textarea>
                            <InputError :message="errors.body" />
                            <div class="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="stopEditing"
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" :disabled="processing"
                                    >Save message</Button
                                >
                            </div>
                        </Form>

                        <p
                            v-else
                            class="mt-4 text-sm leading-6 whitespace-pre-wrap"
                        >
                            {{ message.body }}
                        </p>
                    </div>
                </div>

                <div class="rounded-xl border border-border bg-card p-4">
                    <h2 class="font-medium">Add message</h2>
                    <div class="mt-4 space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                :disabled="isStreaming"
                                @click="streamDraftReply"
                            >
                                <Sparkles class="size-4" />
                                {{
                                    isStreaming
                                        ? 'Drafting...'
                                        : 'Draft reply with AI'
                                }}
                            </Button>
                            <Button
                                v-if="isStreaming"
                                type="button"
                                variant="ghost"
                                @click="cancelDraftReply"
                            >
                                Cancel
                            </Button>
                            <Button
                                v-if="draft.length > 0 && !isStreaming"
                                type="button"
                                variant="ghost"
                                @click="insertDraftIntoReply"
                            >
                                Insert into reply
                            </Button>
                        </div>

                        <div
                            v-if="streamError"
                            class="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
                            role="alert"
                        >
                            {{ streamError }}
                        </div>

                        <pre
                            v-if="draft.length > 0"
                            class="max-h-48 overflow-y-auto whitespace-pre-wrap rounded-md border border-border bg-muted/40 p-3 font-sans text-sm"
                        >{{ draft }}</pre>
                    </div>

                    <Form
                        v-bind="TicketMessageController.store.form(ticket.id)"
                        reset-on-success
                        :options="{ preserveScroll: true }"
                        class="mt-4 space-y-4"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label for="type">Message type</Label>
                            <FormSelect
                                id="type"
                                name="type"
                                :options="messageTypeOptions"
                                default-value="internal_note"
                            />
                            <InputError :message="errors.type" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="body">Body</Label>
                            <Textarea
                                id="body"
                                v-model="newMessageBody"
                                name="body"
                                required
                                maxlength="10000"
                                :rows="6"
                                class="min-h-32"
                            ></Textarea>
                            <InputError :message="errors.body" />
                        </div>

                        <Button type="submit" :disabled="processing"
                            >Add message</Button
                        >
                    </Form>
                </div>
            </div>

            <aside class="space-y-4">
                <div class="rounded-xl border border-border bg-card p-4">
                    <h2 class="font-medium">Ticket details</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Last activity</dt>
                            <dd class="text-right">
                                {{ formatDate(ticket.last_message_at) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Created</dt>
                            <dd class="text-right">
                                {{ formatDate(ticket.created_at) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Closed</dt>
                            <dd class="text-right">
                                {{
                                    ticket.closed_at
                                        ? formatDate(ticket.closed_at)
                                        : 'Open'
                                }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <Button variant="outline" as-child class="w-full">
                    <Link :href="show(ticket.id)">Refresh ticket</Link>
                </Button>

                <div class="rounded-xl border border-border bg-card p-4">
                    <h2 class="flex items-center gap-2 font-medium">
                        <MessageSquare class="size-4" />
                        AI assistant
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Ask the AI assistant about this ticket. Conversations stay scoped to the ticket.
                    </p>
                    <Form
                        v-bind="TicketChatController.form(ticket.id)"
                        reset-on-success
                        :options="{ preserveScroll: true }"
                        class="mt-4 space-y-3"
                        v-slot="{ errors, processing }"
                    >
                        <Textarea
                            name="message"
                            required
                            maxlength="10000"
                            :rows="4"
                            class="min-h-24 w-full"
                            placeholder="Ask the assistant about this ticket..."
                        ></Textarea>
                        <InputError :message="errors.message" />
                        <Button type="submit" :disabled="processing" class="w-full">
                            <Sparkles class="size-4" />
                            {{ processing ? 'Thinking...' : 'Ask assistant' }}
                        </Button>
                    </Form>
                </div>
            </aside>
        </div>
    </div>
</template>
