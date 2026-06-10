<script setup lang="ts">
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import type { TicketTag } from '@/types';

const props = withDefaults(
    defineProps<{
        modelValue: string[];
        suggestions: TicketTag[];
        name?: string;
        placeholder?: string;
    }>(),
    {
        name: 'tags',
        placeholder: 'Type a tag and press Enter',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string[]];
}>();

const query = ref('');

const normalizedSelectedTags = computed(() =>
    props.modelValue.map((tag) => tag.toLowerCase()),
);

const filteredSuggestions = computed(() => {
    const search = query.value.trim().toLowerCase();

    return props.suggestions
        .filter(
            (tag) =>
                !normalizedSelectedTags.value.includes(tag.name.toLowerCase()),
        )
        .filter((tag) => tag.name.toLowerCase().includes(search))
        .slice(0, 8);
});

function addTag(value: string): void {
    const tag = value.trim();

    if (!tag) {
        return;
    }

    if (normalizedSelectedTags.value.includes(tag.toLowerCase())) {
        query.value = '';

        return;
    }

    emit('update:modelValue', [...props.modelValue, tag]);
    query.value = '';
}

function removeTag(tag: string): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((value) => value !== tag),
    );
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        addTag(query.value);
    }

    if (
        event.key === 'Backspace' &&
        query.value === '' &&
        props.modelValue.length > 0
    ) {
        removeTag(props.modelValue[props.modelValue.length - 1]);
    }
}
</script>

<template>
    <div class="space-y-2">
        <div
            class="flex min-h-9 flex-wrap items-center gap-2 rounded-md border border-input bg-transparent px-3 py-2 shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30"
        >
            <input
                v-for="tag in modelValue"
                :key="tag"
                type="hidden"
                :name="`${name}[]`"
                :value="tag"
            />

            <Badge
                v-for="tag in modelValue"
                :key="tag"
                variant="secondary"
                class="gap-1"
            >
                {{ tag }}
                <button
                    type="button"
                    class="rounded-full px-1 text-muted-foreground hover:text-foreground"
                    :aria-label="`Remove ${tag}`"
                    @click="removeTag(tag)"
                >
                    x
                </button>
            </Badge>

            <Input
                v-model="query"
                class="h-7 min-w-36 flex-1 border-0 px-0 py-0 shadow-none focus-visible:ring-0"
                :placeholder="modelValue.length === 0 ? placeholder : ''"
                @keydown="handleKeydown"
            />
        </div>

        <div
            v-if="query && filteredSuggestions.length"
            class="flex flex-wrap gap-2"
        >
            <button
                v-for="tag in filteredSuggestions"
                :key="tag.slug"
                type="button"
                class="rounded-full border border-border px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                @click="addTag(tag.name)"
            >
                {{ tag.name }}
            </button>
        </div>
    </div>
</template>
