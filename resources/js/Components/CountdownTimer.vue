<script setup>
import { ref, onMounted, onUnmounted, watch, computed } from 'vue';

const props = defineProps({
    turnStartedAt: { type: String, default: null },
    isMyTurn: { type: Boolean, default: false },
    duration: { type: Number, default: 30 },
});

const emit = defineEmits(['timeout']);

const remaining = ref(props.duration);
let interval = null;

const percentage = computed(() => (remaining.value / props.duration) * 100);
const isUrgent = computed(() => remaining.value <= 6);
const isCritical = computed(() => remaining.value <= 3);

function startCountdown() {
    clearInterval(interval);

    if (!props.turnStartedAt) {
        remaining.value = props.duration;
        return;
    }

    const startTime = new Date(props.turnStartedAt).getTime();

    interval = setInterval(() => {
        const elapsed = (Date.now() - startTime) / 1000;
        remaining.value = Math.max(0, Math.ceil(props.duration - elapsed));

        if (remaining.value <= 0) {
            clearInterval(interval);
            emit('timeout');
        }
    }, 200);
}

watch(() => props.turnStartedAt, () => {
    startCountdown();
});

onMounted(() => {
    startCountdown();
});

onUnmounted(() => {
    clearInterval(interval);
});
</script>

<template>
    <div 
        class="countdown" 
        :class="{ 
            'countdown--urgent': isUrgent, 
            'countdown--critical': isCritical, 
            'countdown--my-turn': isMyTurn 
        }"
    >
        <svg class="countdown-ring" viewBox="0 0 36 36">
            <!-- Background circle -->
            <path
                class="countdown-ring__bg"
                d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
            />
            <!-- Progress arc -->
            <path
                class="countdown-ring__progress"
                :stroke-dasharray="`${percentage}, 100`"
                d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
            />
        </svg>
        <span class="countdown-number">{{ remaining }}</span>
    </div>
</template>

<style scoped>
.countdown {
    position: relative;
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.3s cubic-bezier(0.18, 0.89, 0.32, 1.28);
}

.countdown-ring {
    position: absolute;
    inset: 0;
    transform: rotate(-90deg);
}

.countdown-ring__bg {
    fill: none;
    stroke: rgba(255, 255, 255, 0.05);
    stroke-width: 2.5;
}

.countdown-ring__progress {
    fill: none;
    stroke: #10b981; /* Emerald-500 (safe time) */
    stroke-width: 3;
    stroke-linecap: round;
    transition: stroke-dasharray 0.25s linear, stroke 0.3s ease;
    filter: drop-shadow(0 0 2px rgba(16, 185, 129, 0.4));
}

/* Urgent state: < 6 seconds */
.countdown--urgent .countdown-ring__progress {
    stroke: #f59e0b; /* Amber-500 */
    filter: drop-shadow(0 0 3px rgba(245, 158, 11, 0.5));
}

/* Critical state: < 3 seconds */
.countdown--critical .countdown-ring__progress {
    stroke: #ef4444; /* Red-500 */
    filter: drop-shadow(0 0 5px rgba(239, 68, 68, 0.7));
}

.countdown-number {
    font-size: 13px;
    font-weight: 900;
    color: #f8fafc;
    font-family: monospace;
    line-height: 1;
}

.countdown--urgent .countdown-number {
    color: #fbbf24;
}

.countdown--critical .countdown-number {
    color: #fca5a5;
    animation: heart-beat 0.5s infinite alternate;
}

.countdown--my-turn {
    transform: scale(1.15);
}

@keyframes heart-beat {
    from {
        transform: scale(0.9);
        opacity: 0.8;
    }
    to {
        transform: scale(1.15);
        opacity: 1;
    }
}
</style>
