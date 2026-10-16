<script>
export default {
    name: 'ToggleSwitch',
    props: {
        modelValue: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
        label: { type: String, default: '' },
    },
    emits: ['update:modelValue'],
};
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :aria-label="label"
        :disabled="disabled"
        class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors duration-200 disabled:cursor-not-allowed disabled:opacity-50"
        :class="modelValue ? 'bg-ok' : 'bg-input'"
        @click="$emit('update:modelValue', !modelValue)"
    >
        <span
            class="inline-block size-4 rounded-full bg-white shadow transition-transform duration-200"
            :class="modelValue ? 'translate-x-[18px]' : 'translate-x-0.5'"
        />
    </button>
</template>
