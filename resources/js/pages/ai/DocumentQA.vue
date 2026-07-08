<script setup lang="ts">
import { Form, Head, Link, usePoll } from '@inertiajs/vue3';
import { FileText, Sparkles, Trash2, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import AIDocumentQAController from '@/actions/App/Http/Controllers/AIDocumentQAController';
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
import { knowledgeSearch } from '@/routes/ai';

type DocumentItem = {
    id: number;
    file_name: string;
    created_at: string | null;
};

const props = defineProps<{
    documents: DocumentItem[];
    storeReady: boolean;
    lastQuestion: string | null;
    lastAnswer: string | null;
    lastDocumentId: number | null;
    lastRunStatus: string | null;
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

const selectedDocumentId = ref<string>(
    props.lastDocumentId !== null ? String(props.lastDocumentId) : 'all',
);

const uploadAction = AIDocumentQAController.store.form();

const askAction = AIDocumentQAController.ask.form();

const hasDocuments = computed(() => props.documents.length > 0);

usePoll(3000, {}, { autoStart: props.pendingAiRun !== null });

const isPending = computed(
    () => props.pendingAiRun !== null || props.lastRunStatus === 'queued',
);

const askFormInitialValues = {
    question: '',
    document_id: 'all',
};

function formatDate(value: string | null): string {
    if (!value) {
        return 'Unknown';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function confirmDelete(event: Event): void {
    if (
        !window.confirm(
            'Delete this document and remove it from the vector store?',
        )
    ) {
        event.preventDefault();
    }
}
</script>

<template>
    <Head title="AI document Q&amp;A" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-2">
            <h1
                class="flex items-center gap-2 text-2xl font-semibold tracking-tight"
            >
                <Sparkles class="size-6 text-primary" />
                AI document Q&amp;A
            </h1>
            <p class="text-sm text-muted-foreground">
                Upload PDF, text, or Markdown files and ask questions. Documents
                are stored on the provider and indexed in a per-user vector
                store.
            </p>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Upload a document</CardTitle>
                <CardDescription>
                    Allowed: PDF, TXT, MD. Max 20 MB. Provider file IDs and the
                    vector store ID are saved with each document.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="uploadAction"
                    method="post"
                    enctype="multipart/form-data"
                    class="flex flex-col gap-3 sm:flex-row sm:items-end"
                    #default="{ errors, processing, progress }"
                >
                    <div class="flex-1 space-y-2">
                        <Label for="document">File</Label>
                        <input
                            id="document"
                            name="document"
                            type="file"
                            accept=".pdf,.txt,.md,.markdown"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1 file:text-primary-foreground"
                            :disabled="processing"
                        />
                        <InputError :message="errors.document" />
                        <progress
                            v-if="progress && processing"
                            :value="progress.percentage"
                            max="100"
                            class="h-2 w-full overflow-hidden rounded bg-secondary"
                        >
                            {{ progress.percentage }}%
                        </progress>
                    </div>
                    <Button type="submit" :disabled="processing">
                        <Upload class="size-4" />
                        {{ processing ? 'Uploading...' : 'Upload' }}
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <Card v-if="!storeReady && !hasDocuments">
            <CardHeader>
                <CardTitle>No documents yet</CardTitle>
                <CardDescription>
                    Upload your first document to create a vector store.
                </CardDescription>
            </CardHeader>
        </Card>

        <div v-else class="grid gap-4 lg:grid-cols-[2fr_1fr]">
            <Card>
                <CardHeader>
                    <CardTitle>Ask a question</CardTitle>
                    <CardDescription>
                        Ask across all uploaded documents or scope the search to
                        a specific file.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Form
                        v-bind="askAction"
                        method="post"
                        :initial-values="askFormInitialValues"
                        class="flex flex-col gap-3"
                        #default="{ errors }"
                    >
                        <div class="space-y-2">
                            <Label for="document_id">Document</Label>
                            <select
                                id="document_id"
                                name="document_id"
                                v-model="selectedDocumentId"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm"
                            >
                                <option value="all">
                                    All uploaded documents
                                </option>
                                <option
                                    v-for="document in documents"
                                    :key="document.id"
                                    :value="document.id"
                                >
                                    {{ document.file_name }}
                                </option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <Label for="question">Question</Label>
                            <Textarea
                                id="question"
                                name="question"
                                :rows="4"
                                placeholder="What is the refund policy?"
                                :default-value="lastQuestion ?? ''"
                            />
                            <InputError :message="errors.question" />
                        </div>

                        <Button type="submit">
                            <Sparkles class="size-4" />
                            Ask
                        </Button>
                    </Form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Answer</CardTitle>
                    <CardDescription>
                        Powered by file search over the provider's vector store.
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
                        AI is processing this question in the background.
                    </div>
                    <div
                        v-else-if="!lastAnswer"
                        class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        Ask a question to see the answer here.
                    </div>
                    <div v-else class="space-y-3">
                        <p
                            v-if="lastQuestion"
                            class="text-sm font-medium text-muted-foreground"
                        >
                            {{ lastQuestion }}
                        </p>
                        <p class="text-sm leading-6 whitespace-pre-wrap">
                            {{ lastAnswer }}
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Card v-if="hasDocuments">
            <CardHeader>
                <CardTitle>Uploaded documents</CardTitle>
                <CardDescription>
                    Provider IDs are stored for each upload so we can fetch or
                    delete the file later.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-2">
                <div
                    v-for="document in documents"
                    :key="document.id"
                    class="flex items-center justify-between gap-3 rounded-md border p-3"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <FileText
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ document.file_name }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Uploaded {{ formatDate(document.created_at) }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge variant="outline">#{{ document.id }}</Badge>
                        <Form
                            :action="`/ai/documents/${document.id}`"
                            method="delete"
                            #default="{ processing }"
                        >
                            <Button
                                type="submit"
                                variant="ghost"
                                size="icon"
                                :disabled="processing"
                                @click="confirmDelete($event)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </Form>
                    </div>
                </div>
            </CardContent>
        </Card>

        <p class="text-xs text-muted-foreground">
            Tip: the
            <Link :href="knowledgeSearch()" class="underline">AI search</Link>
            feature still uses your hand-curated documents and embeddings.
        </p>
    </div>
</template>
