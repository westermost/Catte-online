<script setup>
import { useLocale } from '@/composables/useLocale';

const { locale, toggleLocale } = useLocale();
</script>

<template>
    <button
        @click="toggleLocale"
        class="language-picker"
        :title="locale === 'en' ? 'Switch to Vietnamese' : 'Switch to English'"
    >
        {{ locale === 'en' ? 'VI' : 'EN' }}
    </button>
</template>

<style scoped>
.language-picker {
    position: fixed;
    top: 12px;
    right: 12px;
    z-index: 100;
    padding: 6px 12px;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    color: white;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    letter-spacing: 0.05em;
}
.language-picker:hover {
    background: rgba(0, 0, 0, 0.8);
    border-color: rgba(255, 255, 255, 0.3);
}
</style>
