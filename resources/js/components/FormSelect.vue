<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type FormSelectOption = {
    value: string;
    label: string;
    disabled?: boolean;
};

const props = withDefaults(
    defineProps<{
        id?: string;
        name: string;
        options: FormSelectOption[];
        defaultValue?: string;
        placeholder?: string;
        placeholderValue?: string;
        disabled?: boolean;
        triggerClass?: string;
    }>(),
    {
        defaultValue: '',
        placeholder: 'Select an option',
        placeholderValue: '',
        triggerClass: 'w-full',
    },
);

const firstEnabledValue = computed(
    () => props.options.find((option) => !option.disabled)?.value ?? '',
);

const selectedValue = ref(props.defaultValue || firstEnabledValue.value);

const hiddenValue = computed(() =>
    selectedValue.value === props.placeholderValue ? '' : selectedValue.value,
);

const selectedLabel = computed(
    () =>
        props.options.find((option) => option.value === selectedValue.value)
            ?.label ?? props.placeholder,
);

watch(
    () => props.defaultValue,
    (value) => {
        selectedValue.value = value || firstEnabledValue.value;
    },
);
</script>

<template>
    <input type="hidden" :name="name" :value="hiddenValue" />

    <Select v-model="selectedValue" :disabled="disabled">
        <SelectTrigger :id="id" :class="triggerClass">
            <SelectValue :placeholder="selectedLabel" />
        </SelectTrigger>

        <SelectContent>
            <SelectItem
                v-for="option in options"
                :key="option.value"
                :value="option.value"
                :disabled="option.disabled"
            >
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
