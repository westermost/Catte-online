<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    roomId: { type: Number, required: true },
    roomCode: { type: String, required: true },
});

const scores = ref([]);
const show = ref(false);
let channel = null;

async function fetchScores() {
    try {
        const res = await fetch(`/api/rooms/${props.roomId}/scores`, { credentials: 'same-origin' });
        scores.value = await res.json();
    } catch (e) {
        console.error('Failed to fetch scores:', e);
    }
}

function downloadCSV() {
    window.location.href = `/api/rooms/${props.roomId}/scores/csv`;
}

async function downloadPNG() {
    const table = document.getElementById('scoreboard-table');
    if (!table) return;
    
    try {
        const { default: html2canvas } = await import('html2canvas');
        const canvas = await html2canvas(table);
        const link = document.createElement('a');
        link.download = `scores-${props.roomCode}.png`;
        link.href = canvas.toDataURL();
        link.click();
    } catch (e) {
        downloadCSV();
    }
}

watch(show, (isOpen) => {
    if (isOpen) fetchScores();
});

onMounted(() => {
    fetchScores();
    channel = window.Echo?.join?.(`room.${props.roomId}`);
    channel?.listen?.('GameEnded', fetchScores);
});

onUnmounted(() => {
    if (channel) window.Echo?.leave?.(`room.${props.roomId}`);
});
</script>

<template>
    <div>
        <!-- Scoreboard toggle button -->
        <button @click="show = !show" class="scoreboard-toggle">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 text-amber-400">
                <path fill-rule="evenodd" d="M12.5 5a1 1 0 0 1 1-1h1.5a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-1.5a1 1 0 0 1-1-1V5Zm-5 5a1 1 0 0 1 1-1h1.5a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1h-1.5a1 1 0 0 1-1-1v-5Zm-5 3a1 1 0 0 1 1-1H5a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H3.5a1 1 0 0 1-1-1v-2Z" clip-rule="evenodd" />
            </svg>
            Bảng điểm
        </button>

        <!-- Scoreboard sliding modal panel -->
        <Transition name="panel-fade">
            <div v-if="show" class="scoreboard-panel">
                <div class="scoreboard-header">
                    <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-1.5">
                        <span>📊</span> Bảng xếp hạng
                    </h3>
                    <div class="flex items-center gap-1.5">
                        <button @click="downloadCSV" class="download-btn" title="Tải file CSV">
                            📄 CSV
                        </button>
                        <button @click="downloadPNG" class="download-btn" title="Tải ảnh PNG">
                            📸 PNG
                        </button>
                        <button @click="show = false" class="close-btn" title="Đóng">
                            ✕
                        </button>
                    </div>
                </div>

                <!-- Custom Table -->
                <div class="table-container">
                    <table id="scoreboard-table" class="scoreboard-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tên</th>
                                <th class="text-center">Điểm</th>
                                <th class="text-center" title="Số trận thắng">W</th>
                                <th class="text-center" title="Số trận thua">L</th>
                                <th class="text-center" title="Số lần gục tùng">💀</th>
                                <th class="text-center" title="Số lần thối ách">🃏</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(s, i) in scores" :key="s.id" :class="{ 'rank-first': i === 0 }">
                                <td class="rank-col">
                                    <span v-if="i === 0" class="trophy">🏆</span>
                                    <span v-else-if="i === 1" class="silver-medal">🥈</span>
                                    <span v-else>{{ i + 1 }}</span>
                                </td>
                                <td class="name-col font-bold">
                                    {{ s.player_name }}
                                </td>
                                <td class="score-col text-center font-extrabold" :class="s.total_points >= 0 ? 'text-emerald-400' : 'text-rose-500'">
                                    {{ s.total_points > 0 ? '+' : '' }}{{ s.total_points }}
                                </td>
                                <td class="text-center text-slate-300 font-medium">{{ s.games_won }}</td>
                                <td class="text-center text-slate-450 font-medium">{{ s.games_lost }}</td>
                                <td class="text-center text-slate-500">{{ s.tung_deaths }}</td>
                                <td class="text-center text-slate-500">{{ s.thoi_ach_count }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="scores.length === 0" class="empty-scoreboard">
                        🎮 Chưa có lượt thi đấu nào được ghi nhận.
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.scoreboard-toggle {
    position: fixed;
    top: 16px;
    right: 16px;
    background: rgba(15, 23, 42, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #f8fafc;
    padding: 8px 16px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 800;
    z-index: 40;
    backdrop-filter: blur(8px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.scoreboard-toggle:hover {
    background: rgba(15, 23, 42, 0.95);
    transform: translateY(-1px);
}
.scoreboard-toggle:active {
    transform: scale(0.95);
}

.scoreboard-panel {
    position: fixed;
    top: 64px;
    right: 16px;
    width: 370px;
    max-height: 420px;
    background: rgba(15, 23, 42, 0.92);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 18px;
    padding: 16px;
    z-index: 45;
    display: flex;
    flex-direction: column;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(12px);
}

.scoreboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.download-btn {
    font-size: 10px;
    font-weight: 800;
    padding: 4px 10px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #e2e8f0;
    border-radius: 8px;
    transition: all 0.2s;
}
.download-btn:hover {
    background: rgba(255, 255, 255, 0.12);
    border-color: rgba(255, 255, 255, 0.2);
}

.close-btn {
    color: #94a3b8;
    font-size: 16px;
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.03);
    transition: all 0.2s;
    margin-left: 4px;
}
.close-btn:hover {
    color: white;
    background: rgba(255, 255, 255, 0.1);
}

.table-container {
    flex: 1;
    overflow-y: auto;
}

/* Custom scrollbar */
.table-container::-webkit-scrollbar {
    width: 4px;
}
.table-container::-webkit-scrollbar-track {
    background: transparent;
}
.table-container::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 2px;
}

.scoreboard-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    color: #e2e8f0;
}

.scoreboard-table th {
    text-align: left;
    padding: 6px 8px;
    border-bottom: 1.5px solid rgba(255, 255, 255, 0.08);
    font-size: 10px;
    font-weight: 800;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.scoreboard-table td {
    padding: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.03);
}

/* Highlight Rank 1 */
.rank-first {
    background: rgba(245, 158, 11, 0.06);
}
.rank-first td {
    border-bottom-color: rgba(245, 158, 11, 0.1);
}
.rank-first .name-col {
    color: #fbbf24; /* yellow/gold name */
}

.trophy {
    font-size: 14px;
}
.silver-medal {
    font-size: 13px;
}

.rank-col {
    font-weight: 800;
    color: #94a3b8;
    width: 28px;
}

.name-col {
    max-width: 110px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.empty-scoreboard {
    text-align: center;
    font-size: 11px;
    color: #64748b;
    padding: 32px 0;
}

/* Transition Animations */
.panel-fade-enter-active, .panel-fade-leave-active {
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
}
.panel-fade-enter-from, .panel-fade-leave-to {
    opacity: 0;
    transform: translateY(20px) scale(0.95);
}

@media (max-width: 767px) {
    .scoreboard-toggle {
        top: 16px;
        right: 16px;
    }
    
    .scoreboard-panel {
        position: fixed;
        top: 68px;
        right: 16px;
        left: 16px;
        width: auto;
        max-width: none;
        max-height: 75vh;
    }
}
</style>
