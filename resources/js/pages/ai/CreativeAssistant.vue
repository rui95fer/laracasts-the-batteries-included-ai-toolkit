<script setup lang="ts">
import { Form, Head, usePoll } from '@inertiajs/vue3';
import { AlertTriangle, ShieldCheck, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import CreativeAssistantController from '@/actions/App/Http/Controllers/CreativeAssistantController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';

const props = defineProps<{
    lastPrompt: string | null;
    lastAnswer: string | null;
    lastStatus: string | null;
    pendingAiRun: {
        id: number;
        status: string;
    } | null;
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

const storeAction = CreativeAssistantController.store.form();

const isPending = computed(
    () =>
        props.pendingAiRun !== null ||
        props.lastStatus === 'queued' ||
        props.lastStatus === 'running',
);

const isBlocked = computed(() => props.lastStatus === 'blocked');

const initialValues = {
    prompt: '',
};

usePoll(3000, {}, { autoStart: props.pendingAiRun !== null });
</script>

<template>
    <Head title="Creative assistant" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-2">
            <h1
                class="flex items-center gap-2 text-2xl font-semibold tracking-tight"
            >
                <Sparkles class="size-6 text-primary" />
                Creative assistant
            </h1>
            <p class="text-sm text-muted-foreground">
                Draft stories, taglines, and other creative content. Input is
                screened for sensitive data and responses are filtered before
                they reach you.
            </p>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <ShieldCheck class="size-4 text-primary" />
                    Safety guardrails
                </CardTitle>
                <CardDescription>
                    Prompts containing sensitive terms never reach the model
                    and the agent is limited to a small set of trusted domains
                    when it searches the web.
                </CardDescription>
            </CardHeader>
        </Card>

        <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
            <Card>
                <CardHeader>
                    <CardTitle>Ask the assistant</CardTitle>
                    <CardDescription>
                        Submit a short creative request. The prompt is hashed
                        for the audit log; the original text is not stored.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Form
                        v-bind="storeAction"
                        method="post"
                        :initial-values="initialValues"
                        class="flex flex-col gap-3"
                        #default="{ errors, processing }"
                    >
                        <div class="space-y-2">
                            <Label for="prompt">Prompt</Label>
                            <Textarea
                                id="prompt"
                                name="prompt"
                                :rows="5"
                                placeholder="Write a friendly tagline for a Laravel SaaS landing page."
                                :default-value="lastPrompt ?? ''"
                            />
                            <InputError :message="errors.prompt" />
                        </div>

                        <Button type="submit" :disabled="processing">
                            <Sparkles class="size-4" />
                            {{ processing ? 'Sending...' : 'Generate' }}
                        </Button>
                    </Form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Response</CardTitle>
                    <CardDescription>
                        Output is sanitized through the safety filter before
                        display.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="isPending"
                        class="flex items-center gap-2 rounded-md border border-dashed p-4 text-sm text-muted-foreground"
                        role="status"
                    >
                        <span
                            class="inline-block size-2 animate-pulse rounded-full bg-current"
                        />
                        AI is processing this request in the background.
                    </div>
                    <div
                        v-else-if="isBlocked"
                        class="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
                        role="alert"
                    >
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        <div>
                            <p class="font-medium">
                                Input blocked by safety filter.
                            </p>
                            <p class="text-xs text-destructive/80">
                                Remove any sensitive terms and try again.
                            </p>
                        </div>
                    </div>
                    <div
                        v-else-if="!lastAnswer"
                        class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        Submit a prompt to see the response here.
                    </div>
                    <div v-else class="space-y-3">
                        <p
                            v-if="lastPrompt"
                            class="text-sm font-medium text-muted-foreground"
                        >
                            {{ lastPrompt }}
                        </p>
                        <p
                            class="text-sm leading-6 whitespace-pre-wrap"
                            data-testid="creative-assistant-answer"
                        >
                            {{ lastAnswer }}
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Card v-if="lastStatus && !isPending && !isBlocked">
            <CardHeader>
                <CardTitle>Run status</CardTitle>
                <CardDescription>
                    Recorded against the
                    <Badge variant="outline">creative-assistant</Badge>
                    feature for auditing and usage tracking.
                </CardDescription>
            </CardHeader>
        </Card>
    </div>
</template>
