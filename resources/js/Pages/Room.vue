<script setup>
import { ref, watch, onMounted, onUnmounted, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import GameTable from '@/Components/GameTable.vue';
import LanguagePicker from '@/Components/LanguagePicker.vue';
import { useLocale } from '@/composables/useLocale';
import ChatBox from '@/Components/ChatBox.vue';
import Scoreboard from '@/Components/Scoreboard.vue';

const { msg } = useLocale();

const props = defineProps({
    room: Object,
    players: Array,
    currentPlayer: Object,
    isOwner: Boolean,
    game: { type: Object, default: null },
});

const page = usePage();
const playerList = ref([...props.players]);
const roomData = ref({ ...props.room });
const ownerIsMe = ref(props.isOwner);
const activeGame = ref(props.game);
const lastWinner = ref(page.props.lastWinner || null);
const gameTableRef = ref(null);
const errorMessage = ref('');
const copied = ref(false);
const nextGameSecondsLeft = ref(0);
const showCompletedGameView = ref(false);
const completedGameSnapshot = ref(null);
const FINAL_REVEAL_DELAY_MS = 5000;
let channel = null;
let statePollTimer = null;
let timeoutClaimInFlight = false;
let timeoutRetryTimer = null;
let nextGameCountdownTimer = null;

// Sync reactive state when Inertia re-renders with new props
watch(() => props.players, (val) => { playerList.value = [...val]; });
watch(() => props.room, (val) => { roomData.value = { ...val }; });
watch(() => props.isOwner, (val) => { ownerIsMe.value = val; });
watch(() => props.game, (val) => { activeGame.value = val; if (val) { completedGameSnapshot.value = null; showCompletedGameView.value = false; } });
watch(() => page.props.lastWinner, (val) => { lastWinner.value = val || null; });

const isPlaying = computed(() => roomData.value.status === 'playing' && activeGame.value);
const displayGame = computed(() => activeGame.value || completedGameSnapshot.value);
const showGameTable = computed(() => Boolean((isPlaying.value && activeGame.value) || showCompletedGameView.value));
const currentRoomPlayer = computed(() => playerList.value.find(p => p.id === props.currentPlayer?.id) || null);
const isNextGameReadyPhase = computed(() => Boolean(roomData.value.next_game_deadline_at));
const isCurrentPlayerReady = computed(() => Boolean(currentRoomPlayer.value?.ready_for_next_game));
const readyPlayersCount = computed(() => playerList.value.filter(p => p.ready_for_next_game).length);
const nextGameLeaderName = computed(() => lastWinner.value?.name || 'previous winner');

onMounted(() => {
    document.addEventListener('keydown', handleModalKeydown);

    channel = window.Echo.join(`room.${props.room.id}`)
        .here(() => {})
        .joining(() => {})
        .leaving((member) => {
            const player = playerList.value.find(p => p.id === member.id);
            if (player) player.status = 'disconnected';
        })
        .listen('PlayerJoined', (e) => {
            const existing = playerList.value.find(p => p.id === e.player.id);
            if (existing) Object.assign(existing, e.player);
            else playerList.value.push(e.player);
            refreshRoomState();
        })
        .listen('PlayerLeft', (e) => {
            playerList.value = playerList.value.filter(p => p.id !== e.player_id);
            if (e.new_owner_id) {
                roomData.value.owner_player_id = e.new_owner_id;
                ownerIsMe.value = e.new_owner_id === props.currentPlayer?.id;
            }
        })
        .listen('RoomUpdated', (e) => {
            Object.assign(roomData.value, e.room);
            ownerIsMe.value = e.room.owner_player_id === props.currentPlayer?.id;
            refreshRoomState();
        })
        .listen('PlayerKicked', (e) => {
            const player = playerList.value.find(p => p.id === e.player_id);
            if (player) player.status = 'kicked';
        })
        .listen('GameStarting', () => {
            showCompletedGameView.value = false;
            completedGameSnapshot.value = null;
            refreshRoomState();
        })
        .listen('GameEnded', () => {
            completedGameSnapshot.value = activeGame.value ? { ...activeGame.value } : completedGameSnapshot.value;
            showCompletedGameView.value = true;
            window.setTimeout(() => {
                refreshRoomState();
            }, FINAL_REVEAL_DELAY_MS);
        });
    refreshRoomState();
    statePollTimer = window.setInterval(() => {
        if (!isPlaying.value) refreshRoomState();
    }, 1500);
});

onUnmounted(() => {
    if (channel) window.Echo.leave(`room.${props.room.id}`);
    if (statePollTimer) window.clearInterval(statePollTimer);
    if (timeoutRetryTimer) window.clearTimeout(timeoutRetryTimer);
    if (nextGameCountdownTimer) window.clearInterval(nextGameCountdownTimer);
    document.removeEventListener('keydown', handleModalKeydown);
});

watch(() => roomData.value.next_game_deadline_at, (deadline) => {
    if (nextGameCountdownTimer) {
        window.clearInterval(nextGameCountdownTimer);
        nextGameCountdownTimer = null;
    }

    if (!deadline) {
        nextGameSecondsLeft.value = 0;
        return;
    }

    const updateCountdown = () => {
        const remaining = Math.max(0, Math.ceil((new Date(deadline).getTime() - Date.now()) / 1000));
        nextGameSecondsLeft.value = remaining;
    };

    updateCountdown();
    nextGameCountdownTimer = window.setInterval(updateCountdown, 500);
});

function handleModalKeydown(event) {
    if (event.key === 'Escape' && errorMessage.value) {
        closeError();
    }
}

function showError(message) {
    errorMessage.value = message || msg('room.error');
}

function closeError() {
    errorMessage.value = '';
}

async function readErrorResponse(response, fallback = msg('room.error')) {
    try {
        const data = await response.json();
        return data.error || data.message || fallback;
    } catch {
        return fallback;
    }
}

function applyRoomState(state) {
    roomData.value = { ...state.room };
    playerList.value = [...state.players];
    ownerIsMe.value = state.isOwner;
    activeGame.value = state.game;
    lastWinner.value = state.lastWinner || null;

    if (state.game) {
        completedGameSnapshot.value = null;
        showCompletedGameView.value = false;
    }
}

async function refreshRoomState() {
    try {
        const res = await fetch(`/api/rooms/${props.room.id}/state`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });

        if (res.status === 403) {
            router.visit('/lobby');
            return;
        }

        if (!res.ok) return;

        applyRoomState(await res.json());
    } catch (e) {
        console.error('Refresh room state error:', e);
    }
}

function leaveRoom() {
    router.post(`/rooms/${props.room.code}/leave`);
}

function leaveToHome() {
    router.post(`/rooms/${props.room.code}/leave`, {}, {
        onSuccess: () => {
            router.post('/logout');
        }
    });
}

function startGame() {
    router.post(`/rooms/${props.room.code}/start`, {}, {
        onSuccess: () => refreshRoomState(),
    });
}

async function readyNextGame() {
    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const res = await fetch(`/rooms/${props.room.code}/ready-next`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            credentials: 'same-origin',
        });

        if (!res.ok) {
            showError(await readErrorResponse(res, msg('room.errorConfirm')));
            return;
        }

        await refreshRoomState();
    } catch (e) {
        console.error('Ready next game error:', e);
        showError(msg('room.errorConnect'));
    }
}

async function handlePlayCard({ card, face_down }) {
    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const res = await fetch(`/api/game/${activeGame.value.id}/play`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ card, face_down }),
        });
        if (!res.ok) {
            showError(await readErrorResponse(res, msg('room.errorPlay')));
        } else {
            const data = await res.json();
            if (!data.card_played) {
                await refreshAfterTimeout();
                return;
            }

            // Update hand from server response (ensures face-down plays remove the card)
            if (data.hand) {
                gameTableRef.value?.updateHand(data.hand);
            }
        }
    } catch (e) {
        console.error('Play card error:', e);
        showError(msg('room.errorConnect'));
    }
}

async function handleClaimTimeout({ retried = false } = {}) {
    if (timeoutClaimInFlight || !activeGame.value?.id) return;

    timeoutClaimInFlight = true;
    const controller = new AbortController();
    const requestTimer = window.setTimeout(() => controller.abort(), 8000);

    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const res = await fetch(`/api/game/${activeGame.value.id}/claim-timeout`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        });

        if (!res.ok) {
            const message = await readErrorResponse(res, msg('room.errorTimeout'));

            if (message === 'Too early') {
                if (!retried) {
                    timeoutRetryTimer = window.setTimeout(() => {
                        handleClaimTimeout({ retried: true });
                    }, 1200);
                }
                return;
            }

            if (message === 'Already processed' || message === 'Game ended') {
                await refreshAfterTimeout();
                return;
            }

            showError(message);
        } else {
            await refreshAfterTimeout();
        }
    } catch (e) {
        console.error('Claim timeout error:', e);
        if (e.name === 'AbortError') return;
        showError(msg('room.errorConnect'));
    } finally {
        window.clearTimeout(requestTimer);
        timeoutClaimInFlight = false;
    }
}

async function refreshAfterTimeout() {
    await refreshRoomState();
    await gameTableRef.value?.refreshFromServer?.();
}

async function handleReadyNextGame() {
    await readyNextGame();
}

function handleLeaveRoom() {
    leaveRoom();
}

function copyCode() {
    if (!roomData.value.code) return;
    navigator.clipboard.writeText(roomData.value.code);
    copied.value = true;
    setTimeout(() => copied.value = false, 2000);
}
</script>

<template>
    <LanguagePicker />
    <div class="min-h-screen bg-slate-950 font-sans text-slate-100 select-none">
        <!-- Game Active -->
        <GameTable
            v-if="showGameTable && displayGame"
            ref="gameTableRef"
            :game="displayGame"
            :players="playerList"
            :current-player="currentPlayer"
            :room-id="room.id"
            :next-game-deadline-at="roomData.next_game_deadline_at"
            :next-game-seconds-left="nextGameSecondsLeft"
            :is-current-player-ready="isCurrentPlayerReady"
            :ready-players-count="readyPlayersCount"
            @play-card="handlePlayCard"
            @claim-timeout="handleClaimTimeout"
            @ready-next-game="handleReadyNextGame"
            @leave-room="handleLeaveRoom"
        />


        <!-- Waiting Room -->
        <div v-else class="min-h-screen bg-slate-950 relative overflow-hidden p-4 md:p-6 flex items-center justify-center">
            <!-- Radial Ambient Glows -->
            <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-emerald-500/5 rounded-full blur-[100px] pointer-events-none"></div>
            <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-amber-500/5 rounded-full blur-[100px] pointer-events-none"></div>

            <div class="w-full max-w-3xl relative z-10 my-auto">
                <!-- Room Card Info -->
                <div class="bg-slate-900/60 backdrop-blur-xl border border-slate-800/80 rounded-3xl p-6 mb-6 shadow-2xl relative overflow-hidden">
                    <!-- Top accent line -->
                    <div class="absolute top-0 left-0 right-0 h-[3px] bg-gradient-to-r from-emerald-500 via-emerald-600 to-amber-500"></div>
                    
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <h1 class="text-2xl font-black text-white tracking-wide">{{ roomData.name }}</h1>
                                <span class="px-2 py-0.5 bg-emerald-500/10 border border-emerald-500/20 text-[10px] font-bold text-emerald-400 rounded-md uppercase">
                                    {{ isNextGameReadyPhase ? msg('room.readyForNext') : msg('room.players') }}
                                </span>
                                <span v-if="roomData.thoi_ach_enabled" class="px-2 py-0.5 bg-rose-500/10 border border-rose-500/20 text-[10px] font-bold text-rose-400 rounded-md uppercase">
                                    🔄 Change
                                </span>
                            </div>
                            <p class="text-xs text-slate-500 mt-1">
                                {{ isNextGameReadyPhase ? msg('room.nextGameLeader', { name: nextGameLeaderName }) : msg('room.getReady') }}
                            </p>
                        </div>
                        
                        <!-- Copy room code -->
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-slate-400">{{ msg('room.roomCode') }}</span>
                            <button
                                @click="copyCode"
                                class="font-mono font-extrabold text-emerald-400 hover:text-emerald-300 bg-slate-950 px-3.5 py-1.5 rounded-xl border border-slate-800 flex items-center gap-2 transition duration-200 active:scale-95 group"
                                :title="msg('room.clickToCopy')"
                            >
                                <span class="tracking-widest font-black">{{ roomData.code }}</span>
                                <svg v-if="!copied" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-slate-500 group-hover:text-slate-400 transition">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 7.5V6.108c0-1.135.845-2.098 1.976-2.192.373-.03.748-.057 1.123-.08M15.75 18H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08M15.75 18.75v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5A3.375 3.375 0 0 0 6.375 7.5H5.25m11.9-3.664A2.251 2.251 0 0 0 15 2.25h-1.5a2.251 2.251 0 0 0-2.15 1.586m5.8 0c.065.21.1.433.1.664v.75h-6V4.5c0-.231.035-.454.1-.664M6.75 7.5H4.875c-.621 0-1.125.504-1.125 1.125v12c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V16.5a9 9 0 0 0-9-9Z" />
                                </svg>
                                <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4 text-emerald-400">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    v-if="isNextGameReadyPhase"
                    class="bg-slate-900/60 backdrop-blur-xl border border-amber-500/20 rounded-3xl p-6 mb-6 shadow-2xl"
                >
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <h2 class="text-base font-black text-white">{{ msg('room.confirmNextGame') }}</h2>
                            <p class="text-xs text-slate-400 mt-1">
                                {{ msg('room.readyStatus', { count: readyPlayersCount, total: playerList.length }) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="px-4 py-2 rounded-2xl bg-slate-950 border border-slate-800 text-center min-w-[92px]">
                                <div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">{{ msg('room.remaining') }}</div>
                                <div class="text-2xl font-black text-amber-400">{{ nextGameSecondsLeft }}</div>
                            </div>
                            <button
                                type="button"
                                class="px-5 py-3 rounded-2xl font-black transition duration-200 active:scale-95"
                                :class="isCurrentPlayerReady
                                    ? 'bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 cursor-default'
                                    : 'bg-amber-500 text-slate-950 hover:bg-amber-400'"
                                :disabled="isCurrentPlayerReady"
                                @click="readyNextGame"
                            >
                                {{ isCurrentPlayerReady ? 'Ready!' : 'Start Next Game' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Players Grid -->
                <div class="bg-slate-900/60 backdrop-blur-xl border border-slate-800/80 rounded-3xl p-6 mb-6 shadow-2xl">
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="font-extrabold text-white text-base flex items-center gap-2">
                            <span>📋</span> {{ msg('room.playerList') }}
                        </h2>
                        <span class="text-xs font-bold px-2 py-0.5 bg-slate-950 border border-slate-800 rounded-md text-slate-400">
                            {{ playerList.length }} / {{ roomData.max_players }}
                        </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div
                            v-for="player in playerList"
                            :key="player.id"
                            class="bg-slate-950/60 border border-slate-850 hover:border-slate-800 rounded-2xl p-4 flex items-center justify-between gap-3 shadow transition duration-200"
                            :class="player.id === currentPlayer?.id ? 'border-emerald-500/50 bg-gradient-to-r from-emerald-500/10 to-teal-500/5 shadow-[0_0_15px_rgba(16,185,129,0.05)]' : ''"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl flex items-center justify-center font-black text-sm border shadow"
                                    :class="player.status === 'connected'
                                        ? 'bg-gradient-to-br from-emerald-500 to-teal-600 text-white border-emerald-400/20'
                                        : 'bg-slate-800 text-slate-500 border-slate-700/50'">
                                    {{ player.seat_position + 1 }}
                                </div>
                                <div class="flex flex-col">
                                    <span class="font-bold text-white text-sm flex items-center gap-1.5">
                                        {{ player.name }}
                                        <span v-if="player.id === roomData.owner_player_id" class="text-yellow-500 text-sm" title="Room Owner">👑</span>
                                        <span v-if="player.id === currentPlayer?.id" class="px-1.5 py-0.5 bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-400 font-extrabold rounded">YOU</span>
                                    </span>
                                    <span
                                        v-if="isNextGameReadyPhase"
                                        class="mt-1 text-[10px] font-bold uppercase tracking-wider"
                                        :class="player.ready_for_next_game ? 'text-emerald-400' : 'text-slate-500'"
                                    >
                                        {{ player.ready_for_next_game ? msg('room.ready') : msg('room.notReady') }}
                                    </span>
                                </div>
                            </div>
                            
                            <span class="px-2.5 py-0.5 text-[9px] font-black rounded-md tracking-wider"
                                  :class="player.status === 'connected' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-500 border-slate-700/50'">
                                {{ player.status === 'connected' ? 'ONLINE' : 'OFFLINE' }}
                            </span>
                        </div>

                        <!-- Waiting placeholder slots if room not full -->
                        <div
                            v-for="index in Math.max(0, roomData.max_players - playerList.length)"
                            :key="'slot-' + index"
                            class="border border-dashed border-slate-850 bg-slate-900/10 rounded-2xl p-4 flex items-center justify-center min-h-[72px]"
                        >
                            <span class="text-xs text-slate-600 font-medium tracking-wide flex items-center gap-2 select-none">
                                <span class="w-1.5 h-1.5 rounded-full bg-slate-600 animate-ping"></span>
                                {{ msg('room.waitingForPlayers') }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex gap-4 justify-center items-center">
                    <button
                        v-if="ownerIsMe && playerList.length >= 2 && !isNextGameReadyPhase"
                        @click="startGame"
                        class="px-8 py-3.5 bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-600 hover:to-yellow-500 text-slate-950 font-black text-base rounded-xl transition duration-300 transform active:scale-95 shadow-lg shadow-amber-500/10 hover:shadow-amber-500/25"
                    >
                        🎮 {{ msg('room.startGame') }}
                    </button>
                    <button
                        v-if="ownerIsMe && playerList.length < 2 && !isNextGameReadyPhase"
                        disabled
                        class="px-8 py-3.5 bg-slate-900 text-slate-600 font-bold text-sm rounded-xl cursor-not-allowed border border-slate-850"
                    >
                        ⚠️ {{ msg('room.minPlayersNeeded') }}
                    </button>
                    <button
                        @click="leaveRoom"
                        class="px-8 py-3.5 bg-slate-900 border border-slate-850 hover:bg-slate-850 hover:border-slate-800 text-rose-400 font-bold rounded-xl transition duration-200 active:scale-95"
                        :title="msg('room.leaveTitle')"
                    >
                        {{ msg('room.leaveRoom') }}
                    </button>
                    <button
                        @click="leaveToHome"
                        class="px-8 py-3.5 bg-slate-900 border border-slate-850 hover:bg-slate-850 hover:border-slate-800 text-amber-400 font-bold rounded-xl transition duration-200 active:scale-95 flex items-center justify-center gap-1.5"
                        :title="msg('room.homeTitle')"
                    >
                        {{ msg('room.home') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Error Notification Modal -->
        <div
            v-if="errorMessage"
            class="fixed inset-0 z-[200] flex items-center justify-center bg-black/80 backdrop-blur-sm px-4"
            role="dialog"
            aria-modal="true"
            @click.self="closeError"
        >
            <div class="w-full max-w-md rounded-3xl bg-slate-900 border border-red-500/30 p-6 shadow-2xl relative overflow-hidden transition-all transform scale-100">
                <div class="absolute top-0 left-0 right-0 h-[3px] bg-red-500"></div>

                <div class="mb-5">
                    <h2 class="text-lg font-black text-white flex items-center gap-2">
                        <span class="text-red-500">⚠️</span> Error
                    </h2>
                    <p class="mt-3 text-sm leading-relaxed text-slate-300 bg-slate-950/60 p-4 border border-slate-850 rounded-xl font-semibold">
                        {{ errorMessage }}
                    </p>
                </div>
                <div class="flex justify-end">
                    <button
                        type="button"
                        class="rounded-xl bg-slate-800 border border-slate-700 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-750 active:scale-95 outline-none"
                        @click="closeError"
                    >
                        {{ msg('room.close') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ChatBox and Scoreboard mounted globally in the Room -->
        <ChatBox
            v-if="currentPlayer"
            :room-id="room.id"
            :player-name="currentPlayer.name"
        />
        
        <Scoreboard
            :room-id="room.id"
            :room-code="room.code"
        />
    </div>
</template>
