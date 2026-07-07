<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import CardHand from './CardHand.vue';
import { useLocale } from '@/composables/useLocale';
import PlayerSeat from './PlayerSeat.vue';
import CountdownTimer from './CountdownTimer.vue';

const props = defineProps({
    game: { type: Object, required: true },
    players: { type: Array, required: true },
    currentPlayer: { type: Object, required: true },
    roomId: { type: Number, required: true },
    nextGameDeadlineAt: { type: String, default: null },
    nextGameSecondsLeft: { type: Number, default: 0 },
    isCurrentPlayerReady: { type: Boolean, default: false },
    readyPlayersCount: { type: Number, default: 0 },
});

const emit = defineEmits(['play-card', 'claim-timeout', 'leave-room', 'ready-next-game']);

const { msg } = useLocale();

const myHand = ref([]);
const selectedCard = ref(null);
const tableRounds = ref([]);
const currentRoundPlayCount = ref(0);
let showGameResultTimer = null;
const roundInfo = ref({ round: 1, phase: 'playing', leadSuit: null });
const currentTurnPlayerId = ref(props.game.current_player_id);
const turnStartedAt = ref(props.game.turn_started_at);
const tonCounts = ref({});
const eliminatedPlayers = ref([]);
const timedOutPlayers = ref([]);
const kickedPlayers = ref([]);
const gameEnded = ref(false);
const gameResult = ref(null);
const actionNotice = ref('');
const timeoutDurationSeconds = 30;
let timeoutWatchdog = null;
let lastTimeoutClaimBucket = '';
let actionNoticeTimer = null;

// Compute my position as 'bottom', arrange others around
const otherPlayers = computed(() => {
    return props.players.filter(p => p.id !== props.currentPlayer.id);
});

const isMyTurn = computed(() => {
    return currentTurnPlayerId.value === props.currentPlayer.id && !gameResult.value;
});

const currentTurnPlayer = computed(() => {
    return props.players.find(p => p.id === currentTurnPlayerId.value) || null;
});

const turnText = computed(() => {
    if (!currentTurnPlayerId.value) return msg('game.turnStatus.waiting');
    if (isMyTurn.value) return msg('game.turnStatus.yourTurn');
    return msg('game.turnStatus.playerTurn', { name: currentTurnPlayer.value?.name || 'player' });
});

const isChungRound = computed(() => roundInfo.value.round >= 5);
const isLeadPlay = computed(() => currentRoundPlayCount.value === 0);
const canPlayFaceUp = computed(() => true);
const canPlayFaceDown = computed(() => !isLeadPlay.value);
const visibleTableRounds = computed(() => tableRounds.value.filter(group => group.plays.length > 0));
const hasTableCards = computed(() => visibleTableRounds.value.length > 0);
const faceDownButtonText = computed(() => isChungRound.value ? msg('game.actions.chungFaceDown') : msg('game.actions.faceDown'));
const playHintText = computed(() => {
    if (isChungRound.value && isLeadPlay.value) return msg('game.hints.chungLeadFaceUp');
    if (isChungRound.value) return msg('game.hints.chungFaceUpOrDown');
    if (isLeadPlay.value) return msg('game.hints.leadCard');
    return msg('game.hints.followOrFold');
});

const scoreDeltas = computed(() => {
    const scores = gameResult.value?.scores || {};

    return Object.entries(scores)
        .map(([playerId, delta]) => ({
            player_id: Number(playerId),
            name: getPlayerName(playerId),
            delta: Number(delta),
            isWinner: Number(playerId) === Number(gameResult.value?.winner_id),
            isMe: Number(playerId) === Number(props.currentPlayer.id),
        }))
        .sort((left, right) => right.delta - left.delta);
});

const resultTitle = computed(() => {
    if (!gameResult.value) return msg('game.result.gameOver');
    return gameResult.value.winner_id === props.currentPlayer.id
        ? msg('game.result.youWin')
        : msg('game.result.youLose');
});

const resultDescription = computed(() => {
    if (!gameResult.value) return '';

    if (gameResult.value.win_type === 'instant_win') {
        return msg('game.result.instantWin') + (gameResult.value.instant_win_type ? ': ' + gameResult.value.instant_win_type : '');
    }

    if (gameResult.value.win_type === 'thang_tung') {
        return msg('game.result.thangTung');
    }

    if (gameResult.value.win_type === 'normal') {
    return msg('game.result.gameOver');
    }

    return msg('game.result.gameOver');
});

const hasNextGameCountdown = computed(() => Boolean(props.nextGameDeadlineAt));
const nextGameStatusText = computed(() => {
    if (!hasNextGameCountdown.value) {
        return 'Waiting for next game...';
    }

    return `${props.readyPlayersCount} / ${props.players.length} san sang`;
});

const positions = ['top', 'left', 'right', 'top-left', 'top-right'];

function getPlayerPosition(index) {
    return positions[index % positions.length];
}

function getCardRank(card) {
    return card ? card.slice(0, -1) : '';
}

function getCardSuit(card) {
    if (!card) return '';

    return { H: '♥', D: '♦', C: '♣', S: '♠' }[card.slice(-1)] || '';
}

function isRedCard(card) {
    return card ? ['H', 'D'].includes(card.slice(-1)) : false;
}

function getTableCardStyle(play) {
    if (play.is_face_down || !play.card) {
        return { zIndex: 0 };
    }

    if (play.is_winner) {
        return { zIndex: 20 };
    }

    return { zIndex: Math.max(1, Number(play.play_order || 1)) };
}

function getPlayerName(playerId) {
    if (String(playerId) === String(props.currentPlayer.id)) {
        return 'You';
    }
    const p = props.players.find(player => String(player.id) === String(playerId));
    return p ? p.name : 'Player';
}

// Fetch hand on mount
onMounted(async () => {
    syncGameState(props.game);
    await fetchHand();
    listenEvents();
    startTimeoutWatchdog();
});

onUnmounted(() => {
    if (showGameResultTimer) clearTimeout(showGameResultTimer);
    if (timeoutWatchdog) clearInterval(timeoutWatchdog);
    if (actionNoticeTimer) clearTimeout(actionNoticeTimer);
});

watch(() => props.game, (game) => {
    syncGameState(game);
}, { deep: true });

watch(() => props.game.id, async (gameId, previousGameId) => {
    if (!previousGameId || gameId === previousGameId) return;

    resetTableState();
    syncGameState(props.game);
    await fetchHand();
});

function syncGameState(game) {
    if (!game) return;

    if (game.current_round) roundInfo.value.round = game.current_round;
    if (game.phase) roundInfo.value.phase = game.phase;
    if (Object.prototype.hasOwnProperty.call(game, 'current_player_id')) {
        currentTurnPlayerId.value = game.current_player_id;
    }
    if (Object.prototype.hasOwnProperty.call(game, 'turn_started_at')) {
        turnStartedAt.value = game.turn_started_at;
    }
}

async function fetchHand() {
    try {
        const res = await fetch(`/api/game/${props.game.id}/my-hand`, {
            credentials: 'same-origin',
        });
        const data = await res.json();
        myHand.value = data.hand || [];
        if (Array.isArray(data.table_rounds)) {
            tableRounds.value = [];
            data.table_rounds.forEach(round => {
                upsertRoundPlays(round.round_number, round.plays || [], false);
            });
            currentRoundPlayCount.value = getRoundPlayCount(data.current_round);
        }
        roundInfo.value.round = data.current_round;
        roundInfo.value.phase = data.phase;
        currentTurnPlayerId.value = data.current_player_id;
        turnStartedAt.value = data.turn_started_at;
    } catch (e) {
        console.error('Failed to fetch hand:', e);
    }
}

function listenEvents() {
    const channel = window.Echo.join(`room.${props.roomId}`);

    channel.listen('TurnStarted', (e) => {
        currentTurnPlayerId.value = e.player_id;
        turnStartedAt.value = e.turn_started_at;
        lastTimeoutClaimBucket = '';
    });

    channel.listen('TurnTimeout', (e) => {
        if (!timedOutPlayers.value.includes(e.player_id)) {
            timedOutPlayers.value.push(e.player_id);
        }

        setTimeout(() => {
            timedOutPlayers.value = timedOutPlayers.value.filter(id => id !== e.player_id);
        }, 2500);
    });

    channel.listen('CardPlayed', (e) => {
        upsertTablePlay(e.round_number, {
            player_id: e.player_id,
            card: e.card,
            is_face_down: e.is_face_down,
            play_order: e.play_order,
        });
        currentRoundPlayCount.value = Math.max(currentRoundPlayCount.value, e.play_order || 0);

        // Remove from my hand if it's my card
        if (e.player_id === props.currentPlayer.id) {
            if (e.card) {
                myHand.value = myHand.value.filter(c => c !== e.card);
            }
            selectedCard.value = null;
        } else if (e.is_face_down) {
            showActionNotice(
                `${getPlayerName(e.player_id)} ${Number(e.round_number) >= 5 ? 'played chung' : 'folded'}`
            );
        }
    });

    channel.listen('RoundEnded', (e) => {
        upsertWinningPlay(e.round_number, e.plays, e.winner_id, e.round_number >= 5);

        roundInfo.value.round = e.round_number + 1;
        currentRoundPlayCount.value = 0;
        if (e.winner_id) {
            tonCounts.value[e.winner_id] = (tonCounts.value[e.winner_id] || 0) + 1;
        }
    });

    channel.listen('PlayerEliminated', (e) => {
        eliminatedPlayers.value.push(e.player_id);
    });

    channel.listen('PlayerKicked', (e) => {
        if (!kickedPlayers.value.includes(e.player_id)) {
            kickedPlayers.value.push(e.player_id);
        }
    });

    channel.listen('GameEnded', (e) => {
        if (Array.isArray(e.table_plays) && e.table_plays.length > 0 && e.final_round_number) {
            if (Number(e.final_round_number) >= 6) {
                upsertRoundPlays(e.final_round_number, e.table_plays, true, e.winner_id);
            } else {
                upsertWinningPlay(e.final_round_number, e.table_plays, e.winner_id, true);
            }
        }

        gameResult.value = e;
        currentTurnPlayerId.value = null;

        if (showGameResultTimer) clearTimeout(showGameResultTimer);
        const shouldDelayResult = Number(e.final_round_number) >= 6 || (Array.isArray(e.table_plays) && e.table_plays.length > 0);
        showGameResultTimer = setTimeout(() => {
            gameEnded.value = true;
            showGameResultTimer = null;
        }, shouldDelayResult ? 5000 : 0);
    });

    // Private channel for hand updates
    window.Echo.private(`player.${props.currentPlayer.id}`)
        .listen('YourHand', (e) => {
            myHand.value = e.cards;
        });
}

function selectCard(card) {
    if (!isMyTurn.value) return;
    selectedCard.value = selectedCard.value === card ? null : card;
}

function playFaceUp() {
    if (!selectedCard.value || !isMyTurn.value) return;
    emit('play-card', { card: selectedCard.value, face_down: false });
}

function playFaceDown() {
    if (!selectedCard.value || !isMyTurn.value || !canPlayFaceDown.value) return;
    emit('play-card', { card: selectedCard.value, face_down: true });
}

function handleTimeout() {
    claimTimeoutIfDue(true);
}

function startTimeoutWatchdog() {
    if (timeoutWatchdog) clearInterval(timeoutWatchdog);

    timeoutWatchdog = setInterval(() => {
        claimTimeoutIfDue(false);
    }, 1000);
}

function claimTimeoutIfDue(fromTimerEvent = false) {
    if (!currentTurnPlayerId.value || !turnStartedAt.value || gameEnded.value || gameResult.value) return;

    const startedAt = new Date(turnStartedAt.value).getTime();
    if (!Number.isFinite(startedAt)) return;

    const elapsed = (Date.now() - startedAt) / 1000;
    if (!fromTimerEvent && elapsed < timeoutDurationSeconds) return;

    const retryBucket = Math.floor(Math.max(timeoutDurationSeconds, elapsed) / 2);
    const claimBucket = `${currentTurnPlayerId.value}-${turnStartedAt.value}-${retryBucket}`;
    if (claimBucket === lastTimeoutClaimBucket) return;

    lastTimeoutClaimBucket = claimBucket;
    emit('claim-timeout');
}

function updateHand(newHand) {
    myHand.value = newHand;
    selectedCard.value = null;
}

function showActionNotice(message) {
    actionNotice.value = message;

    if (actionNoticeTimer) {
        clearTimeout(actionNoticeTimer);
    }

    actionNoticeTimer = setTimeout(() => {
        actionNotice.value = '';
        actionNoticeTimer = null;
    }, 2200);
}

function addPlayedCard(playerId, cardData) {
    upsertTablePlay(roundInfo.value.round, {
        player_id: playerId,
        card: cardData.card,
        is_face_down: cardData.is_face_down,
        play_order: currentRoundPlayCount.value + 1,
    });
    currentRoundPlayCount.value += 1;
}

function upsertTablePlay(roundNumber, play) {
    const numericRound = Number(roundNumber || roundInfo.value.round);
    const group = ensureRoundGroup(numericRound);
    const normalized = normalizePlay(numericRound, play, group.plays.length, false);
    const existingIndex = group.plays.findIndex(existing => (
        Number(existing.player_id) === Number(normalized.player_id)
        || Number(existing.play_order) === Number(normalized.play_order)
    ));

    if (existingIndex >= 0) {
        group.plays.splice(existingIndex, 1, normalized);
    } else {
        group.plays.push(normalized);
    }

    group.plays.sort((left, right) => left.play_order - right.play_order);
    tableRounds.value = [...tableRounds.value].sort((left, right) => left.roundNumber - right.roundNumber);
}

function upsertRoundPlays(roundNumber, plays, revealCards = false, winnerId = null) {
    const numericRound = Number(roundNumber);
    const group = ensureRoundGroup(numericRound);
    group.winnerId = winnerId !== null ? Number(winnerId) : group.winnerId;
    group.plays = plays
        .map((play, index) => normalizePlay(numericRound, play, index, revealCards, group.winnerId))
        .sort((left, right) => left.play_order - right.play_order);

    tableRounds.value = [...tableRounds.value].sort((left, right) => left.roundNumber - right.roundNumber);
}

function getRoundPlayCount(roundNumber) {
    return tableRounds.value.find(group => group.roundNumber === Number(roundNumber))?.plays.length || 0;
}

function upsertWinningPlay(roundNumber, plays, winnerId, revealCards = false) {
    const winningPlay = (plays || []).find(play => Number(play.player_id) === Number(winnerId));

    if (!winningPlay) {
        upsertRoundPlays(roundNumber, [], revealCards, winnerId);
        return;
    }

    upsertRoundPlays(roundNumber, [winningPlay], revealCards, winnerId);
}

function ensureRoundGroup(roundNumber) {
    let group = tableRounds.value.find(existing => existing.roundNumber === roundNumber);

    if (!group) {
        group = {
            id: `round-${roundNumber}`,
            roundNumber,
            label: `Round ${roundNumber}`,
            plays: [],
            winnerId: null,
        };
        tableRounds.value.push(group);
    }

    return group;
}

function normalizePlay(roundNumber, play, index, revealCards, winnerId = null) {
    const playOrder = Number(play.play_order || index + 1);
    const isFaceDown = revealCards ? false : Boolean(play.is_face_down);

    return {
        id: `round-${roundNumber}-${play.player_id}-${playOrder}`,
        player_id: Number(play.player_id),
        card: play.card,
        is_face_down: isFaceDown,
        play_order: playOrder,
        is_winner: winnerId !== null && Number(play.player_id) === Number(winnerId),
    };
}

function resetTableState() {
    if (showGameResultTimer) {
        clearTimeout(showGameResultTimer);
        showGameResultTimer = null;
    }

    selectedCard.value = null;
    tableRounds.value = [];
    currentRoundPlayCount.value = 0;
    tonCounts.value = {};
    eliminatedPlayers.value = [];
    timedOutPlayers.value = [];
    kickedPlayers.value = [];
    gameEnded.value = false;
    gameResult.value = null;
    actionNotice.value = '';
    lastTimeoutClaimBucket = '';

    if (actionNoticeTimer) {
        clearTimeout(actionNoticeTimer);
        actionNoticeTimer = null;
    }
}

function readyNextGame() {
    emit('ready-next-game');
}

function leaveRoom() {
    emit('leave-room');
}

async function refreshFromServer() {
    await fetchHand();
}

defineExpose({ updateHand, addPlayedCard, refreshFromServer });
</script>

<template>
    <div class="game-table-container">
        <!-- Game Result Overlay -->
        <div v-if="gameEnded" class="game-result-overlay">
            <div class="game-result-card" :class="{ 'game-result-card--win': gameResult?.winner_id === currentPlayer.id }">
                <div class="result-banner">
                    <span v-if="gameResult?.winner_id === currentPlayer.id" class="text-6xl animate-bounce mb-2 block">🎉</span>
                    <span v-else class="text-6xl mb-2 block">😢</span>
                </div>
                <h2 class="text-3xl font-black mb-2 tracking-wide">
                    {{ resultTitle }}
                </h2>
                <p class="text-sm text-slate-400 font-semibold mb-6">
                    {{ resultDescription }}
                </p>
                <div v-if="scoreDeltas.length > 0" class="result-score-list">
                    <div
                        v-for="score in scoreDeltas"
                        :key="score.player_id"
                        class="result-score-row"
                        :class="{
                            'result-score-row--winner': score.isWinner,
                            'result-score-row--me': score.isMe,
                        }"
                    >
                        <span class="result-score-name">{{ score.name }}</span>
                        <span
                            class="result-score-delta"
                            :class="score.delta >= 0 ? 'result-score-delta--positive' : 'result-score-delta--negative'"
                        >
                            {{ score.delta > 0 ? '+' : '' }}{{ score.delta }}
                        </span>
                    </div>
                </div>
                <div class="result-next-game-panel">
                    <div class="result-next-game-meta">
                        <div class="result-next-game-label">Van tiep theo</div>
                        <div class="result-next-game-status">{{ nextGameStatusText }}</div>
                    </div>
                    <div
                        class="result-next-game-countdown"
                        :class="{ 'result-next-game-countdown--waiting': !hasNextGameCountdown }"
                    >
                        {{ hasNextGameCountdown ? nextGameSecondsLeft : '...' }}
                    </div>
                </div>
                <div class="result-actions">
                    <button
                        type="button"
                        class="result-action result-action--secondary"
                        @click="leaveRoom"
                    >
                        Thoat phong
                    </button>
                    <button
                        type="button"
                        class="result-action"
                        :class="{ 'result-action--ready': isCurrentPlayerReady }"
                        :disabled="isCurrentPlayerReady || !hasNextGameCountdown"
                        @click="readyNextGame"
                    >
                        {{ isCurrentPlayerReady ? 'Da san sang' : 'Bat dau van tiep theo' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- HUD: Round Info Bar -->
        <div class="round-hud">
            <span class="hud-badge round-badge">
                {{ msg('game.round') }} {{ Math.min(6, roundInfo.round) }} / 6
            </span>
            <span class="hud-badge phase-badge" :class="{ 'phase-badge--chung': roundInfo.phase === 'chung' }">
                {{ roundInfo.phase === 'chung' ? '🔥 CHƯNG' : '🃏 PLAYING' }}
            </span>
        </div>

        <transition name="action-notice">
            <div v-if="actionNotice" class="action-notice">
                {{ actionNotice }}
            </div>
        </transition>

        <!-- Central Table Felt Layout -->
        <div class="table-area">
            <!-- Green Felt Visual Ellipse -->
            <div class="table-felt">
                <div class="felt-inner-ring"></div>
                
                <!-- Felt Center: played cards area -->
                <div class="center-area">
                    <div v-if="hasTableCards" class="winner-strip">
                        <div
                            v-for="group in visibleTableRounds"
                            :key="group.id"
                            class="winner-slot"
                            :class="{ 'winner-slot--current': group.roundNumber === roundInfo.round }"
                        >
                            <div class="winner-slot__round">V{{ group.roundNumber }}</div>
                            <div class="winner-slot__cards">
                                <div
                                    v-for="play in group.plays"
                                    :key="play.id"
                                    class="winner-mini-card"
                                    :style="getTableCardStyle(play)"
                                    :class="{
                                        'winner-mini-card--red': isRedCard(play.card),
                                        'winner-mini-card--back': play.is_face_down || !play.card,
                                        'winner-mini-card--winner': play.is_winner,
                                    }"
                                >
                                    <template v-if="play.is_face_down || !play.card">
                                        <span class="winner-mini-card__back-mark">?</span>
                                    </template>
                                    <template v-else>
                                        <span class="winner-mini-card__rank">{{ getCardRank(play.card) }}</span>
                                        <span class="winner-mini-card__suit">{{ getCardSuit(play.card) }}</span>
                                    </template>
                                    <span class="winner-mini-card__player">
                                        {{ getPlayerName(play.player_id) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Opponents Seats (arranged on desktop) -->
            <div class="opponents">
                <PlayerSeat
                    v-for="(player, index) in otherPlayers"
                    :key="player.id"
                    :player="player"
                    :is-current-turn="currentTurnPlayerId === player.id"
                    :is-eliminated="eliminatedPlayers.includes(player.id)"
                    :is-timed-out="timedOutPlayers.includes(player.id)"
                    :is-kicked="kickedPlayers.includes(player.id)"
                    :ton-count="tonCounts[player.id] || 0"
                    :card-count="Math.max(0, 6 - (roundInfo.round - 1))"
                    :position="getPlayerPosition(index)"
                />
            </div>
        </div>

        <!-- Timer Panel -->
        <div v-if="currentTurnPlayerId && !gameEnded" class="timer-area">
            <div class="turn-panel" :class="{ 'turn-panel--mine': isMyTurn }">
                <div class="turn-label">{{ turnText }}</div>
                <CountdownTimer
                    :turn-started-at="turnStartedAt"
                    :is-my-turn="isMyTurn"
                    @timeout="handleTimeout"
                />
            </div>
        </div>

        <!-- My Hand / Controls Panel -->
        <div class="my-hand-area">
            <CardHand
                :cards="myHand"
                :selected-card="selectedCard"
                :playable="isMyTurn"
                @select="selectCard"
            />

            <!-- Play buttons -->
            <div v-if="isMyTurn && selectedCard" class="play-actions">
                <button 
                    v-if="canPlayFaceUp" 
                    @click="playFaceUp" 
                    class="btn-play btn-face-up"
                >
                    Play Face Up
                </button>
                <button 
                    v-if="canPlayFaceDown" 
                    @click="playFaceDown" 
                    class="btn-play btn-face-down"
                >
                    {{ faceDownButtonText }}
                </button>
            </div>
            <div v-else-if="isMyTurn" class="play-hint-pill">
                {{ playHintText }}
            </div>
        </div>
    </div>
</template>

<style scoped>
.game-table-container {
    position: relative;
    min-height: 100vh;
    background: radial-gradient(ellipse at center, #0b1329 0%, #050814 100%);
    display: flex;
    flex-direction: column;
    padding: 16px;
    overflow-x: hidden;
}

.round-hud {
    display: flex;
    gap: 10px;
    justify-content: center;
    padding: 12px 8px;
    z-index: 20;
}

.hud-badge {
    background: rgba(15, 23, 42, 0.7);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #cbd5e1;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.5px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.25);
    backdrop-filter: blur(8px);
}
.phase-badge--chung {
    border-color: rgba(239, 68, 68, 0.3);
    color: #ef4444; /* red pulse for chung */
    animation: pulse-phase 2s infinite alternate;
}

@keyframes pulse-phase {
    from { opacity: 0.8; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.1); }
    to { opacity: 1; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.25); }
}

.table-area {
    position: relative;
    width: 100%;
    max-width: 900px;
    height: 480px; /* fixed height for oval table layout */
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 1;
}

/* Green felt visual table design */
.table-felt {
    position: absolute;
    inset: 50px 30px;
    background: radial-gradient(ellipse at center, #0f5132 0%, #0a3622 70%, #062115 100%);
    border: 10px solid #3e2723; /* wooden leather-like rim */
    border-radius: 120px;
    box-shadow: inset 0 10px 25px rgba(0,0,0,0.65), 0 15px 30px rgba(0,0,0,0.5);
    z-index: 1;
}

.felt-inner-ring {
    position: absolute;
    inset: 20px;
    border: 1.5px dashed rgba(255, 255, 255, 0.05);
    border-radius: 100px;
    pointer-events: none;
}

/* Opponent Seats positions around felt */
.opponents {
    position: absolute;
    inset: 0;
    z-index: 8;
    pointer-events: none;
}

.opponents :deep(.player-seat) {
    position: absolute;
    pointer-events: auto;
}

/* Desktop coordinate positions */
.opponents :deep(.player-seat--top) {
    top: 0;
    left: 50%;
    transform: translateX(-50%);
}
.opponents :deep(.player-seat--left) {
    left: -20px;
    top: 50%;
    transform: translateY(-50%);
}
.opponents :deep(.player-seat--right) {
    right: -20px;
    top: 50%;
    transform: translateY(-50%);
}
.opponents :deep(.player-seat--top-left) {
    top: 15px;
    left: 12%;
}
.opponents :deep(.player-seat--top-right) {
    top: 15px;
    right: 12%;
}

.center-area {
    position: absolute;
    z-index: 12;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}

.winner-strip {
    width: min(880px, 94vw); /* wider to support large cards */
    display: grid;
    grid-template-columns: repeat(6, minmax(130px, 1fr)); /* 130px columns */
    gap: 8px;
    padding: 12px 10px;
    border-radius: 20px;
    background: rgba(2, 6, 23, 0.55);
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 16px 32px rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(8px);
    pointer-events: auto;
}

.winner-slot {
    min-width: 0;
    min-height: 200px; /* higher to fit 88x124px card overlap stack */
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 10px 8px;
    border-radius: 16px;
    background: rgba(15, 23, 42, 0.65);
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.winner-slot--current {
    border-color: rgba(250, 204, 21, 0.45);
    background: rgba(250, 204, 21, 0.03);
}

.winner-slot__round {
    color: rgba(250, 204, 21, 0.85); /* gold round text */
    font-size: 12px;
    font-weight: 900;
    line-height: 1;
    letter-spacing: 0.5px;
}

.winner-slot__cards {
    position: relative;
    width: 110px; /* fixed relative card stack container width */
    height: 144px; /* fixed height matching card + shifts */
    margin: 8px auto 0 auto;
}

.winner-mini-card {
    position: absolute;
    width: 88px; /* EXACTLY matches regular hand card size */
    height: 124px; /* EXACTLY matches regular hand card size */
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: #f8fafc;
    border: 1.5px solid rgba(226, 232, 240, 0.95);
    color: #0f172a;
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.3);
    font-family: 'Outfit', 'Georgia', serif;
    transition: transform 0.2s ease;
}

/* Beautiful fan stack overlapping on PC */
.winner-mini-card:nth-child(1) { transform: translate(-10px, -5px) rotate(-6deg); z-index: 1; }
.winner-mini-card:nth-child(2) { transform: translate(-2px, 0px) rotate(-2deg); z-index: 2; }
.winner-mini-card:nth-child(3) { transform: translate(6px, 5px) rotate(2deg); z-index: 3; }
.winner-mini-card:nth-child(4) { transform: translate(14px, 10px) rotate(6deg); z-index: 4; }
.winner-mini-card:nth-child(5) { transform: translate(22px, 5px) rotate(4deg); z-index: 5; }
.winner-mini-card:nth-child(6) { transform: translate(30px, 0px) rotate(2deg); z-index: 6; }

.winner-mini-card--red {
    color: #e11d48;
}

.winner-mini-card--back {
    background: radial-gradient(circle at center, #1e293b 0%, #0f172a 100%);
    border-color: rgba(217, 119, 6, 0.75);
    color: #fbbf24;
}

.winner-mini-card--winner {
    border-color: rgba(250, 204, 21, 0.95);
    box-shadow:
        0 0 0 2px rgba(250, 204, 21, 0.45),
        0 12px 24px rgba(250, 204, 21, 0.24),
        0 8px 16px rgba(0, 0, 0, 0.32);
    animation: winner-card-pulse 1.2s ease-in-out infinite alternate;
}

@keyframes winner-card-pulse {
    from {
        filter: brightness(1);
    }
    to {
        filter: brightness(1.08);
    }
}

.winner-mini-card__rank {
    font-size: 19px;
    font-weight: 900;
    line-height: 1;
    position: absolute;
    top: 8px;
    left: 8px;
}

.winner-mini-card__suit {
    font-size: 22px;
    font-weight: 900;
    line-height: 1;
    position: absolute;
    top: 26px;
    left: 8px;
}

.winner-mini-card__back-mark {
    font-size: 26px;
    font-weight: 900;
}

.winner-mini-card__player {
    position: absolute;
    left: 50%;
    bottom: 6px; /* Positioned inside the card at the bottom */
    width: calc(100% - 12px);
    max-width: 80px;
    transform: translateX(-50%);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: rgba(15, 23, 42, 0.75);
    background: rgba(255, 255, 255, 0.85);
    border: 0.5px solid rgba(0,0,0,0.1);
    font-family: ui-sans-serif, system-ui, sans-serif;
    font-size: 9.5px;
    font-weight: 800;
    line-height: 1;
    text-align: center;
    padding: 2.5px 4px;
    border-radius: 4px;
    z-index: 5;
}

.winner-mini-card--back .winner-mini-card__player {
    color: rgba(255, 255, 255, 0.8);
    background: rgba(15, 23, 42, 0.75);
    border-color: rgba(255, 255, 255, 0.1);
}

.timer-area {
    display: flex;
    justify-content: center;
    padding: 8px;
    z-index: 20;
}

.turn-panel {
    display: inline-flex;
    align-items: center;
    gap: 16px;
    min-height: 58px;
    padding: 6px 16px;
    border-radius: 14px;
    background: rgba(15, 23, 42, 0.85);
    border: 1px solid rgba(255,255,255,0.08);
    color: white;
    box-shadow: 0 8px 20px rgba(0,0,0,0.3);
    backdrop-filter: blur(8px);
}

.turn-panel--mine {
    border-color: rgba(245, 158, 11, 0.4);
    background: rgba(120, 53, 4, 0.8);
    box-shadow: 0 0 15px rgba(245, 158, 11, 0.15);
}

.turn-label {
    min-width: 110px;
    max-width: 220px;
    font-size: 13px;
    font-weight: 800;
    line-height: 1.25;
}

.my-hand-area {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    padding-bottom: 12px;
    z-index: 20;
}

.play-actions {
    display: flex;
    gap: 14px;
}

.btn-play {
    padding: 11px 26px;
    border-radius: 14px;
    font-weight: 800;
    font-size: 13px;
    transition: all 0.2s cubic-bezier(0.25, 0.8, 0.25, 1);
    box-shadow: 0 4px 10px rgba(0,0,0,0.25);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.btn-play:active {
    transform: scale(0.97);
}

.btn-face-up {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: 1.5px solid #34d399;
}
.btn-face-up:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 6px 15px rgba(16, 185, 129, 0.35);
}

.btn-face-down {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: white;
    border: 1.5px solid #818cf8;
}
.btn-face-down:hover {
    background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
    box-shadow: 0 6px 15px rgba(99, 102, 241, 0.35);
}

.play-hint-pill {
    color: #facc15;
    background: rgba(15, 23, 42, 0.7);
    border: 1px solid rgba(250, 204, 21, 0.2);
    font-size: 12px;
    font-weight: 700;
    padding: 6px 18px;
    border-radius: 20px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
}

/* Victory/Defeat overlay styles */
.game-result-overlay {
    position: fixed;
    inset: 0;
    background: rgba(10, 15, 30, 0.85);
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100;
}

.game-result-card {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border: 1px solid rgba(255, 255, 255, 0.08);
    padding: 40px 60px;
    border-radius: 28px;
    text-align: center;
    color: white;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    max-width: 440px;
    width: 90%;
    position: relative;
    overflow: hidden;
}

.game-result-card--win {
    border-color: rgba(245, 158, 11, 0.3);
    box-shadow: 0 0 40px rgba(245, 158, 11, 0.15), 0 25px 50px -12px rgba(0, 0, 0, 0.5);
}
.game-result-card--win::before {
    content: '';
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 200px;
    height: 4px;
    background: linear-gradient(90deg, transparent, #fbbf24, transparent);
}

.result-score-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 0 auto 18px;
    width: min(300px, 100%);
}

.result-score-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 10px;
    border-radius: 10px;
    background: rgba(15, 23, 42, 0.72);
    border: 1px solid rgba(255, 255, 255, 0.06);
}

.result-score-row--winner {
    border-color: rgba(251, 191, 36, 0.34);
}

.result-score-row--me {
    background: rgba(6, 78, 59, 0.42);
}

.result-score-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
    font-weight: 800;
    color: #e2e8f0;
}

.result-score-delta {
    flex: 0 0 auto;
    font-size: 13px;
    font-weight: 900;
}

.result-score-delta--positive {
    color: #34d399;
}

.result-score-delta--negative {
    color: #fb7185;
}

.result-action {
    padding: 12px 28px;
    border-radius: 14px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    font-size: 14px;
    font-weight: 800;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
    transition: all 0.2s;
}
.result-action:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
}
.result-action:active {
    transform: scale(0.97);
}
.result-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.result-action:disabled {
    opacity: 0.6;
    cursor: default;
    transform: none;
    box-shadow: none;
}
.result-action--secondary {
    background: rgba(15, 23, 42, 0.92);
    border: 1px solid rgba(148, 163, 184, 0.22);
    box-shadow: none;
}
.result-action--secondary:hover {
    background: rgba(30, 41, 59, 0.96);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(15, 23, 42, 0.2);
}
.result-action--ready {
    background: linear-gradient(135deg, #065f46 0%, #047857 100%);
}
.result-next-game-panel {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 12px 14px;
    margin-bottom: 18px;
    border-radius: 14px;
    background: rgba(15, 23, 42, 0.82);
    border: 1px solid rgba(251, 191, 36, 0.16);
}
.result-next-game-meta {
    min-width: 0;
    text-align: left;
}
.result-next-game-label {
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    color: #fbbf24;
}
.result-next-game-status {
    margin-top: 4px;
    font-size: 12px;
    font-weight: 700;
    color: #cbd5e1;
}
.result-next-game-countdown {
    flex: 0 0 auto;
    min-width: 60px;
    padding: 8px 10px;
    border-radius: 12px;
    background: rgba(2, 6, 23, 0.82);
    border: 1px solid rgba(251, 191, 36, 0.18);
    color: #fbbf24;
    font-size: 24px;
    font-weight: 900;
    line-height: 1;
    text-align: center;
}
.result-next-game-countdown--waiting {
    color: #94a3b8;
    border-color: rgba(148, 163, 184, 0.2);
}
/* Responsive felt adaptations (Intelligent horizontal flow for opponents, relative felt layout) */
@media (max-width: 800px) {
    .table-area {
        display: flex;
        flex-direction: column;
        height: auto;
        min-height: auto;
        flex: 1;
        gap: 12px;
        padding: 0;
        justify-content: flex-start;
        align-items: center;
    }
    
    .opponents {
        position: static;
        width: 100%;
        display: flex;
        flex-wrap: nowrap;
        overflow-x: auto;
        gap: 6px;
        justify-content: flex-start;
        padding: 8px 12px;
        margin-bottom: 0;
        scrollbar-width: none; /* Hide scrollbar for Firefox */
        -webkit-overflow-scrolling: touch;
    }
    
    .opponents::-webkit-scrollbar {
        display: none; /* Hide scrollbar for Chrome/Safari */
    }
    
    .opponents :deep(.player-seat),
    .opponents :deep(.player-seat--top),
    .opponents :deep(.player-seat--left),
    .opponents :deep(.player-seat--right),
    .opponents :deep(.player-seat--top-left),
    .opponents :deep(.player-seat--top-right) {
        position: static !important;
        transform: none !important;
        flex-shrink: 0;
        inset: auto !important;
    }
    
    .table-felt {
        position: relative;
        inset: auto;
        width: 100%;
        height: 180px; /* compact felt height on mobile */
        border-radius: 20px;
        border-width: 4px;
        margin: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .felt-inner-ring {
        inset: 10px;
        border-radius: 16px;
    }
    
    .center-area {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .played-cards-center {
        padding: 8px 12px;
        gap: 8px;
        max-width: 95%;
        border-radius: 16px;
    }
    
    .winner-strip {
        grid-template-columns: repeat(3, minmax(44px, 1fr));
        width: min(280px, 94vw);
        gap: 4px;
        padding: 6px;
    }
    
    .winner-slot {
        min-height: 64px;
        padding: 4px;
    }
    
    .winner-slot__round {
        font-size: 8px;
    }
    
    .winner-mini-card {
        width: 28px;
        height: 38px;
        border-radius: 4px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.25);
    }
    
    .winner-mini-card__rank {
        font-size: 10px;
    }
    
    .winner-mini-card__suit {
        font-size: 11px;
    }
    
    .winner-mini-card__back-mark {
        font-size: 11px;
    }
    
    .winner-mini-card__player {
        font-size: 7.5px;
        bottom: -11px;
        max-width: 44px;
    }
    .result-actions {
        grid-template-columns: 1fr;
    }
}
</style>
