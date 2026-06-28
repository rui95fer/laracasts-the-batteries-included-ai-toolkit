<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Search, Sparkles } from '@lucide/vue';
import KnowledgeSearchController from '@/actions/App/Http/Controllers/KnowledgeSearchController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { index as ticketsIndex } from '@/routes/tickets';
import { knowledgeSearch } from '@/routes/ai';

type DocumentResult = {
    id: number;
    title: string;
    body: string;
    excerpt: string;
};

defineProps<{
    query: string;
    documents: DocumentResult[];
    minSimilarity: number;
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

function similarityLabel(score: number): string {
    return `${Math.round(score * 100)}%`;
}
</script>

<template>
    <Head title="AI knowledge search" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                <Sparkles class="size-6 text-primary" />
                AI knowledge search
            </h1>
            <p class="text-sm text-muted-foreground">
                Search your knowledge base by meaning. Embeddings are generated
                on first use and cached for the next search.
            </p>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Ask the knowledge base</CardTitle>
                <CardDescription>
                    Minimum similarity is {{ Math.round(minSimilarity * 100) }}%.
                    Lower scores are hidden to reduce noise.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="KnowledgeSearchController.form()"
                    method="get"
                    class="flex flex-col gap-3 sm:flex-row"
                >
                    <div class="relative flex-1">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            name="q"
                            :default-value="query"
                            placeholder="How do refunds work?"
                            class="pl-9"
                            autocomplete="off"
                        />
                    </div>
                    <Button type="submit">Search</Button>
                </Form>
            </CardContent>
        </Card>

        <div v-if="query.trim() === ''" class="rounded-xl border border-dashed p-8 text-center">
            <p class="font-medium">Start by typing a question</p>
            <p class="mt-1 text-sm text-muted-foreground">
                For example, "How do I export project data?" or
                "What is the API rate limit?".
            </p>
        </div>

        <div
            v-else-if="documents.length === 0"
            class="rounded-xl border border-dashed p-8 text-center"
        >
            <p class="font-medium">No matching documents</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Try rephrasing your question or browse open tickets for recent
                conversations.
            </p>
            <Button class="mt-4" variant="outline" as-child>
                <Link :href="ticketsIndex()">View tickets</Link>
            </Button>
        </div>

        <div v-else class="grid gap-4">
            <Card v-for="document in documents" :key="document.id">
                <CardHeader>
                    <div class="flex items-start justify-between gap-3">
                        <CardTitle class="text-base">{{ document.title }}</CardTitle>
                        <Badge variant="secondary">
                            {{ similarityLabel(0) }}
                        </Badge>
                    </div>
                </CardHeader>
                <CardContent>
                    <p class="text-sm text-muted-foreground">
                        {{ document.excerpt }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <p class="text-xs text-muted-foreground">
            Tip: open
            <Link :href="knowledgeSearch()" class="underline">
                the search page
            </Link>
            from the sidebar whenever you need a refresher on a policy.
        </p>
    </div>
</template>
