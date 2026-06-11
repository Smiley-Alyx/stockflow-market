<script setup lang="ts">
type TextSegment = {
    text: string;
    bold: boolean;
};

const props = defineProps<{
    text: string;
}>();

const segments = computed<TextSegment[]>(() => {
    const result: TextSegment[] = [];
    const pattern = /\*\*(.+?)\*\*/gs;
    let cursor = 0;

    for (const match of props.text.matchAll(pattern)) {
        const index = match.index ?? 0;

        if (index > cursor) {
            result.push({ text: props.text.slice(cursor, index), bold: false });
        }

        result.push({ text: match[1] ?? '', bold: true });
        cursor = index + match[0].length;
    }

    if (cursor < props.text.length) {
        result.push({ text: props.text.slice(cursor), bold: false });
    }

    return result.length ? result : [{ text: props.text, bold: false }];
});
</script>

<template lang="pug">
p.assistant-rich-text
    template(v-for="(segment, index) in segments" :key="index")
        strong(v-if="segment.bold") {{ segment.text }}
        span(v-else) {{ segment.text }}
</template>
