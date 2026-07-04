<script setup>
import { computed } from 'vue';

const props = defineProps({
    card: { type: String, default: null }, // e.g. 'AH', '10D', null for face-down
    faceDown: { type: Boolean, default: false },
    selected: { type: Boolean, default: false },
    playable: { type: Boolean, default: false },
    small: { type: Boolean, default: false },
});

const emit = defineEmits(['click']);

const parsed = computed(() => {
    if (!props.card || props.faceDown) return null;
    const suit = props.card.slice(-1);
    const rank = props.card.slice(0, -1);
    return { rank, suit };
});

const suitSymbol = computed(() => {
    if (!parsed.value) return '';
    const map = { H: '♥', D: '♦', C: '♣', S: '♠' };
    return map[parsed.value.suit] || '';
});

const isRed = computed(() => {
    if (!parsed.value) return false;
    return ['H', 'D'].includes(parsed.value.suit);
});
</script>

<template>
    <div
        class="card-wrapper"
        :class="{
            'card--selected': selected,
            'card--playable': playable,
            'card--small': small,
        }"
        @click="emit('click')"
    >
        <!-- Card Back -->
        <div v-if="faceDown || !card" class="card card--back">
            <div class="card__back-inner">
                <div class="card__back-center">
                    <span class="card__back-logo">♠</span>
                </div>
            </div>
        </div>

        <!-- Card Front -->
        <div v-else class="card card--front" :class="{ 'card--red': isRed }">
            <!-- Decorative inner border -->
            <div class="card__inner-border"></div>

            <div class="card__corner card__corner--top">
                <span class="card__rank">{{ parsed?.rank }}</span>
                <span class="card__suit">{{ suitSymbol }}</span>
            </div>
            
            <div class="card__center">
                <span class="card__suit-large">{{ suitSymbol }}</span>
            </div>
            
            <div class="card__corner card__corner--bottom">
                <span class="card__rank">{{ parsed?.rank }}</span>
                <span class="card__suit">{{ suitSymbol }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.card-wrapper {
    display: inline-block;
    cursor: default;
    transition: all 0.25s cubic-bezier(0.25, 0.8, 0.25, 1);
    user-select: none;
    filter: drop-shadow(0 4px 6px rgba(0,0,0,0.25));
}
.card-wrapper.card--playable {
    cursor: pointer;
}
.card-wrapper.card--playable:hover {
    transform: translateY(-10px) scale(1.05);
    filter: drop-shadow(0 10px 15px rgba(16, 185, 129, 0.3));
}
.card-wrapper.card--selected {
    transform: translateY(-16px) scale(1.05);
    filter: drop-shadow(0 12px 20px rgba(245, 158, 11, 0.4));
}

.card {
    width: 88px;
    height: 124px;
    border-radius: 10px;
    position: relative;
    font-family: 'Outfit', 'Georgia', serif;
    user-select: none;
    box-sizing: border-box;
}

.card--small .card {
    width: 60px;
    height: 84px;
    border-radius: 6px;
}

/* Card Front Styling */
.card--front {
    background: #f8fafc;
    color: #0f172a;
    border: 1px solid #e2e8f0;
}
.card--front.card--red {
    color: #e11d48; /* Crimson red */
}

.card__inner-border {
    position: absolute;
    inset: 4px;
    border: 1px solid rgba(0, 0, 0, 0.03);
    border-radius: 6px;
    pointer-events: none;
}
.card--small .card__inner-border {
    inset: 3px;
    border-radius: 4px;
}

.card--front.card--red .card__inner-border {
    border-color: rgba(225, 29, 72, 0.05);
}

/* Card Back Styling (Realistic with White Margin and Royal Blue Lattice Pattern) */
.card--back {
    background: #ffffff; /* represent paper card border */
    border: 1px solid #d1d5db;
    padding: 0;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.25);
    overflow: hidden;
}

.card__back-inner {
    position: absolute;
    inset: 5px; /* White margin border */
    border: 1.5px solid #1e3a8a;
    border-radius: 5px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #1e3a8a; /* Royal blue base */
    /* Intricate repeating classic diamond pattern */
    background-image: 
        linear-gradient(45deg, rgba(255, 255, 255, 0.08) 25%, transparent 25%), 
        linear-gradient(-45deg, rgba(255, 255, 255, 0.08) 25%, transparent 25%), 
        linear-gradient(45deg, transparent 75%, rgba(255, 255, 255, 0.08) 75%), 
        linear-gradient(-45deg, transparent 75%, rgba(255, 255, 255, 0.08) 75%);
    background-size: 8px 8px;
    background-position: 0 0, 0 4px, 4px -4px, -4px 0px;
    box-shadow: inset 0 0 6px rgba(0,0,0,0.4);
}

.card--small .card--back {
    border-width: 0.5px;
}

.card--small .card__back-inner {
    inset: 3px;
    border-width: 1px;
    border-radius: 3px;
    background-size: 6px 6px;
    background-position: 0 0, 0 3px, 3px -3px, -3px 0px;
}

/* Center Medallion circle */
.card__back-center {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #ffffff;
    border: 1px solid #b45309; /* gold rim */
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.3);
}

.card--small .card__back-center {
    width: 22px;
    height: 22px;
    border-width: 0.5px;
}

.card__back-logo {
    font-size: 18px;
    font-weight: 900;
    color: #1e3a8a; /* deep blue spade */
    line-height: 1;
}

.card--small .card__back-logo {
    font-size: 12px;
}

@keyframes pulse-logo {
    0%, 100% { transform: scale(1); opacity: 0.8; }
    50% { transform: scale(1.1); opacity: 1; }
}

/* Corners rank/suit */
.card__corner {
    position: absolute;
    display: flex;
    flex-direction: column;
    align-items: center;
    line-height: 0.95;
    font-weight: 800;
}
.card__corner--top {
    top: 6px;
    left: 8px;
}
.card__corner--bottom {
    bottom: 6px;
    right: 8px;
    transform: rotate(180deg);
}
.card__rank {
    font-size: 19px;
    letter-spacing: -1px;
}
.card__suit {
    font-size: 14px;
}

.card--small .card__corner--top {
    top: 4px;
    left: 5px;
}
.card--small .card__corner--bottom {
    bottom: 4px;
    right: 5px;
}
.card--small .card__rank {
    font-size: 13px;
}
.card--small .card__suit {
    font-size: 10px;
}

/* Large Suit in Center */
.card__center {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}
.card__suit-large {
    font-size: 42px;
    filter: drop-shadow(0 1px 1px rgba(0,0,0,0.05));
}
.card--small .card__suit-large {
    font-size: 24px;
}

@media (max-width: 480px) {
    .card {
        width: 58px;
        height: 82px;
        border-radius: 6px;
    }
    .card__rank {
        font-size: 13px;
    }
    .card__suit {
        font-size: 10px;
    }
    .card__suit-large {
        font-size: 24px;
    }
    .card__inner-border {
        inset: 2px;
        border-radius: 4px;
    }
    .card__back-inner {
        inset: 3px;
    }
    .card__back-center {
        width: 20px;
        height: 20px;
    }
    .card__back-logo {
        font-size: 11px;
    }
}
</style>
