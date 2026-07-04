<script setup>
import { computed } from 'vue';
import Card from './Card.vue';

const props = defineProps({
    player: { type: Object, required: true },
    isCurrentTurn: { type: Boolean, default: false },
    isEliminated: { type: Boolean, default: false },
    isTimedOut: { type: Boolean, default: false },
    isKicked: { type: Boolean, default: false },
    tonCount: { type: Number, default: 0 },
    cardCount: { type: Number, default: 0 },
    position: { type: String, default: 'bottom' },
});

const statusText = computed(() => {
    if (props.isEliminated || props.player.status === 'eliminated') return 'Gục tùng';
    if (props.isKicked || props.player.status === 'kicked') return 'Hết giờ';
    if (props.isTimedOut) return 'Vừa hết giờ';
    if (props.player.status === 'disconnected') return 'Offline';
    if (props.isCurrentTurn) return 'Đang lượt';
    return '';
});
</script>

<template>
    <div
        class="player-seat"
        :class="{
            'player-seat--active': isCurrentTurn,
            'player-seat--eliminated': isEliminated || player.status === 'eliminated',
            [`player-seat--${position}`]: true,
        }"
    >
        <!-- Tồn Card Count Badge (Floating top-right) -->
        <div v-if="tonCount > 0" class="ton-badge" title="Số quân bài tồn">
            ⭐ Tồn: {{ tonCount }}
        </div>

        <div class="player-info">
            <!-- Avatar with optional turn glowing ring -->
            <div
                class="player-avatar"
                :class="{
                    'player-avatar--active': isCurrentTurn,
                    'opacity-40 grayscale': isEliminated || player.status === 'eliminated',
                }"
            >
                {{ player.name?.charAt(0)?.toUpperCase() }}
            </div>
            
            <!-- Name -->
            <div class="player-name truncate" :title="player.name">{{ player.name }}</div>
            
            <!-- Status Badge -->
            <div
                v-if="statusText"
                class="status-pill"
                :class="{
                    'status-pill--turn': isCurrentTurn,
                    'status-pill--timeout': isTimedOut || isKicked || player.status === 'kicked',
                    'status-pill--offline': player.status === 'disconnected',
                    'status-pill--eliminated': isEliminated || player.status === 'eliminated',
                }"
            >
                {{ statusText }}
            </div>
        </div>

        <!-- Hidden Hand Cards of Opponent -->
        <div v-if="cardCount > 0 && !isEliminated && player.status !== 'eliminated'" class="player-cards">
            <Card v-for="i in cardCount" :key="i" face-down small />
        </div>
    </div>
</template>

<style scoped>
.player-seat {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 12px;
    background: rgba(15, 23, 42, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: 16px;
    min-width: 104px;
    backdrop-filter: blur(8px);
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    position: relative;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
}

/* Turn active styling */
.player-seat--active {
    background: rgba(245, 158, 11, 0.08);
    border-color: rgba(245, 158, 11, 0.4);
    box-shadow: 0 0 15px rgba(245, 158, 11, 0.15), 0 4px 10px rgba(0,0,0,0.2);
}

.player-seat--eliminated {
    opacity: 0.45;
}

.player-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    width: 100%;
}

.player-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: 16px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: inset 0 2px 4px rgba(255, 255, 255, 0.2), 0 2px 4px rgba(0,0,0,0.2);
    transition: all 0.3s ease;
}

.player-avatar--active {
    border-color: #facc15;
    ring: 2px solid #facc15;
    box-shadow: 0 0 12px rgba(250, 204, 21, 0.7);
    animation: avatar-pulse 2s infinite alternate;
}

@keyframes avatar-pulse {
    0% { transform: scale(1); box-shadow: 0 0 8px rgba(250, 204, 21, 0.5); }
    100% { transform: scale(1.05); box-shadow: 0 0 16px rgba(250, 204, 21, 0.8); }
}

.player-name {
    font-size: 12px;
    font-weight: 800;
    color: #f1f5f9;
    max-width: 90px;
    text-align: center;
}

/* Floating Tồn count Badge */
.ton-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
    color: white;
    font-size: 9px;
    font-weight: 900;
    padding: 3px 7px;
    border-radius: 20px;
    border: 1px solid #f59e0b;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    z-index: 10;
    white-space: nowrap;
}

/* Styled status badges */
.status-pill {
    font-size: 9px;
    font-weight: 800;
    padding: 2.5px 7px;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.status-pill--turn {
    background: rgba(250, 204, 21, 0.15);
    border: 1px solid rgba(250, 204, 21, 0.3);
    color: #facc15;
}

.status-pill--timeout {
    background: rgba(249, 115, 22, 0.15);
    border: 1px solid rgba(249, 115, 22, 0.3);
    color: #ff9d43;
}

.status-pill--offline {
    background: rgba(148, 163, 184, 0.15);
    border: 1px solid rgba(148, 163, 184, 0.3);
    color: #cbd5e1;
}

.status-pill--eliminated {
    background: rgba(244, 63, 94, 0.15);
    border: 1px solid rgba(244, 63, 94, 0.3);
    color: #f43f5e;
}

.player-cards {
    display: flex;
    margin-top: 4px;
    justify-content: center;
    width: 100%;
}

.player-cards > :deep(.card-wrapper) {
    margin-left: -16px;
    transition: transform 0.2s ease;
}

.player-cards > :deep(.card-wrapper:first-child) {
    margin-left: 0;
}

.player-cards:hover > :deep(.card-wrapper) {
    transform: translateY(-4px) rotate(1deg);
}

@media (max-width: 767px) {
    .player-seat {
        min-width: 76px;
        padding: 8px 6px;
        border-radius: 12px;
        gap: 4px;
    }
    .player-avatar {
        width: 32px;
        height: 32px;
        font-size: 13px;
    }
    .player-name {
        max-width: 68px;
        font-size: 10px;
    }
    .ton-badge {
        font-size: 8px;
        padding: 1.5px 4px;
        top: -6px;
        right: -6px;
    }
    .status-pill {
        font-size: 8px;
        padding: 1.5px 4px;
    }
    .player-cards > :deep(.card-wrapper) {
        margin-left: -12px;
    }
}
</style>
