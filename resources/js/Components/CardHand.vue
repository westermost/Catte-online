<script setup>
import Card from './Card.vue';

const props = defineProps({
    cards: { type: Array, default: () => [] },
    selectedCard: { type: String, default: null },
    playable: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);
</script>

<template>
    <div class="hand">
        <div 
            v-for="(card, i) in cards" 
            :key="card"
            class="hand-card-wrapper"
            :class="{ 'hand-card-wrapper--overlap': i > 0 }"
            :style="{ 
                zIndex: selectedCard === card ? 20 : i + 1
            }"
        >
            <Card
                :card="card"
                :selected="selectedCard === card"
                :playable="playable"
                @click="playable && emit('select', card)"
            />
        </div>
    </div>
</template>

<style scoped>
.hand {
    display: flex;
    justify-content: center;
    align-items: flex-end;
    padding: 24px 16px 8px 16px;
    perspective: 1000px; /* Enable 3D space for fancy lifting effects */
}

.hand-card-wrapper {
    transition: transform 0.25s cubic-bezier(0.25, 0.8, 0.25, 1);
}

.hand-card-wrapper--overlap {
    margin-left: -32px; /* Bigger overlap for wider PC cards */
}

/* Fan hovering effects */
.hand-card-wrapper:hover {
    transform: translateY(-18px) rotate(1deg) scale(1.06);
    z-index: 50 !important;
}

.hand-card-wrapper:hover + .hand-card-wrapper {
    transform: translateX(8px);
}

@media (max-width: 480px) {
    .hand-card-wrapper--overlap {
        margin-left: -22px; /* Compact overlap on mobile */
    }
}
</style>
