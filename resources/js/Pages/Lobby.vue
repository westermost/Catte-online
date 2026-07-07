<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import LanguagePicker from '@/Components/LanguagePicker.vue';

const props = defineProps({
    rooms: Array,
});

const page = usePage();
const roomList = ref([...props.rooms]);
const showCreate = ref(false);
const joinCode = ref('');
const form = ref({
    name: '',
    max_players: 4,
    is_private: false,
    thoi_ach_enabled: false,
});

let lobbyChannel = null;

onMounted(() => {
    lobbyChannel = window.Echo.channel('lobby')
        .listen('RoomCreated', (e) => {
            // Add new room if not private and not already in list
            if (!e.room.is_private && !roomList.value.find(r => r.id === e.room.id)) {
                roomList.value.unshift(e.room);
            }
        })
        .listen('RoomRemoved', (e) => {
            roomList.value = roomList.value.filter(r => r.id !== e.room_id);
        });
});

onUnmounted(() => {
    if (lobbyChannel) window.Echo.leave('lobby');
});

function createRoom() {
    router.post('/rooms', form.value, {
        onSuccess: () => {
            showCreate.value = false;
            form.value = {
                name: '',
                max_players: 4,
                is_private: false,
                thoi_ach_enabled: false,
            };
        },
    });
}

function joinByCode() {
    if (!joinCode.value.trim()) return;
    router.post(`/rooms/${joinCode.value.trim().toUpperCase()}/join`);
}

function joinRoom(code) {
    router.post(`/rooms/${code}/join`);
}
</script>

<template>
    <LanguagePicker />
    <div class="min-h-screen bg-slate-950 relative overflow-hidden p-4 md:p-6 font-sans text-slate-100 select-none">
        <!-- Radial Ambient Glows -->
        <div class="absolute top-0 right-1/4 w-96 h-96 bg-emerald-500/5 rounded-full blur-[120px] pointer-events-none"></div>
        <div class="absolute bottom-0 left-1/4 w-96 h-96 bg-amber-500/5 rounded-full blur-[120px] pointer-events-none"></div>

        <div class="max-w-5xl mx-auto relative z-10">
            <!-- Header Status Bar -->
            <header class="flex flex-col md:flex-row items-center justify-between gap-4 bg-slate-900/60 backdrop-blur-xl border border-slate-800/80 rounded-2xl p-4 mb-6">
                <div class="flex items-center gap-3">
                    <div class="text-3xl">🃏</div>
                    <div>
                        <h1 class="text-xl font-black bg-gradient-to-r from-emerald-400 to-amber-300 bg-clip-text text-transparent tracking-wider">
                            CATTE ONLINE
                        </h1>
                        <p class="text-[10px] text-slate-500 font-semibold tracking-widest uppercase">Sảnh chờ trò chơi</p>
                    </div>
                </div>
                
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2 px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="text-xs text-slate-400 font-medium">Đang trực tuyến:</span>
                        <span class="text-xs text-emerald-400 font-bold">{{ page.props.guest?.name }}</span>
                    </div>
                </div>
            </header>

            <!-- Actions Bar -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-4 mb-6">
                <button
                    @click="showCreate = !showCreate"
                    class="px-5 py-3 bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-600 hover:to-yellow-500 text-slate-950 font-bold rounded-xl shadow-lg shadow-amber-500/10 hover:shadow-amber-500/20 transition-all duration-300 transform active:scale-95 flex items-center justify-center gap-2"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Tạo phòng mới
                </button>

                <div class="flex items-center gap-2 bg-slate-900/60 backdrop-blur-md p-1 border border-slate-800 rounded-xl">
                    <input
                        v-model="joinCode"
                        placeholder="MÃ PHÒNG..."
                        maxlength="6"
                        class="px-4 py-2 bg-transparent text-white font-mono font-extrabold uppercase placeholder-slate-600 text-center tracking-widest outline-none w-36 border-0 focus:ring-0"
                        @keyup.enter="joinByCode"
                    />
                    <button
                        @click="joinByCode"
                        :disabled="!joinCode.trim()"
                        class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-30 disabled:pointer-events-none text-white font-bold rounded-lg shadow-md transition-all duration-200"
                    >
                        Vào bàn
                    </button>
                </div>
            </div>

            <!-- Create Room Form (Modal-like Collapse Card) -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="transform scale-95 opacity-0"
                enter-to-class="transform scale-100 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="transform scale-100 opacity-100"
                leave-to-class="transform scale-95 opacity-0"
            >
                <div v-if="showCreate" class="bg-slate-900/90 border border-slate-800 backdrop-blur-xl rounded-3xl p-6 mb-6 shadow-2xl relative">
                    <!-- Close button -->
                    <button @click="showCreate = false" class="absolute top-4 right-4 text-slate-500 hover:text-slate-300 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>

                    <h2 class="text-lg font-bold text-white mb-5 flex items-center gap-2">
                        <span>⚙️</span> Cấu hình bàn chơi mới
                    </h2>
                    
                    <form @submit.prevent="createRoom" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Left: Room name & player select -->
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Tên phòng chơi</label>
                                    <input
                                        v-model="form.name"
                                        type="text"
                                        maxlength="30"
                                        required
                                        placeholder="Ví dụ: Bàn đấu của tôi..."
                                        class="w-full px-4 py-3 bg-slate-950/80 border border-slate-800 rounded-xl text-white placeholder-slate-600 outline-none transition focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20"
                                    />
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Số lượng người chơi</label>
                                    <div class="flex gap-2">
                                        <button
                                            v-for="num in [2, 3, 4, 5, 6]"
                                            :key="num"
                                            type="button"
                                            @click="form.max_players = num"
                                            class="w-11 h-11 rounded-xl border font-bold text-sm transition-all duration-200 flex items-center justify-center"
                                            :class="form.max_players === num
                                                ? 'bg-amber-500 text-slate-950 border-amber-400 shadow-md shadow-amber-500/10'
                                                : 'bg-slate-950 border-slate-800 text-slate-400 hover:border-slate-700 hover:text-slate-200'"
                                        >
                                            {{ num }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Right: Options/Toggles -->
                            <div class="space-y-4">
                                <!-- Option Toggle: Private -->
                                <div class="flex items-center justify-between p-3.5 bg-slate-950/50 rounded-xl border border-slate-800/80 hover:border-slate-800 transition">
                                    <div class="flex flex-col">
                                        <span class="text-sm font-bold text-slate-200 flex items-center gap-1.5">
                                            🔑 Phòng riêng tư
                                        </span>
                                        <span class="text-[11px] text-slate-500 mt-0.5">Chỉ người có mã phòng mới có thể tham gia</span>
                                    </div>
                                    <button
                                        type="button"
                                        @click="form.is_private = !form.is_private"
                                        class="relative inline-flex h-6.5 w-12 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out outline-none"
                                        :class="form.is_private ? 'bg-emerald-500' : 'bg-slate-800'"
                                    >
                                        <span
                                            class="pointer-events-none inline-block h-5.5 w-5.5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                            :class="form.is_private ? 'translate-x-5.5' : 'translate-x-0'"
                                        />
                                    </button>
                                </div>

                                <!-- Option Toggle: Thổi Ách -->
                                <div class="flex items-center justify-between p-3.5 bg-slate-950/50 rounded-xl border border-slate-800/80 hover:border-slate-800 transition">
                                    <div class="flex flex-col">
                                        <span class="text-sm font-bold text-slate-200 flex items-center gap-1.5">
                                            🃏 Luật thối Ách
                                        </span>
                                        <span class="text-[11px] text-slate-500 mt-0.5">Người chơi giữ quân Ách ở cuối ván sẽ bị xử phạt nặng</span>
                                    </div>
                                    <button
                                        type="button"
                                        @click="form.thoi_ach_enabled = !form.thoi_ach_enabled"
                                        class="relative inline-flex h-6.5 w-12 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out outline-none"
                                        :class="form.thoi_ach_enabled ? 'bg-emerald-500' : 'bg-slate-800'"
                                    >
                                        <span
                                            class="pointer-events-none inline-block h-5.5 w-5.5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                            :class="form.thoi_ach_enabled ? 'translate-x-5.5' : 'translate-x-0'"
                                        />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end pt-2 border-t border-slate-850">
                            <button
                                type="submit"
                                class="px-6 py-3 bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-white font-bold rounded-xl shadow-lg transition duration-200"
                            >
                                Xác nhận tạo
                            </button>
                        </div>
                    </form>
                </div>
            </Transition>

            <!-- Room List Title -->
            <div class="flex items-center gap-2 mb-4">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Danh sách phòng công khai</span>
                <span class="text-xs text-slate-600">({{ roomList.length }})</span>
            </div>

            <!-- Room List Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div v-if="roomList.length === 0" class="col-span-1 md:col-span-2 py-16 flex flex-col items-center justify-center bg-slate-900/40 border border-dashed border-slate-800 rounded-3xl">
                    <span class="text-5xl mb-4 opacity-40">🎴</span>
                    <p class="text-slate-400 font-semibold text-sm">Chưa có phòng chơi nào hoạt động.</p>
                    <p class="text-slate-600 text-xs mt-1">Hãy tạo phòng mới hoặc gia nhập bằng mã phòng ở trên!</p>
                </div>
                
                <div
                    v-for="room in roomList"
                    :key="room.id"
                    class="bg-slate-900/70 border border-slate-800/80 hover:border-slate-700/80 backdrop-blur-sm rounded-2xl p-5 flex flex-col justify-between gap-4 transition-all duration-300 shadow-md group"
                >
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="font-bold text-white text-base group-hover:text-emerald-400 transition duration-200">
                                {{ room.name }}
                            </h3>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <span class="px-2 py-0.5 bg-slate-950 border border-slate-800 text-[10px] font-bold text-slate-400 rounded-md flex items-center gap-1">
                                    👤 {{ room.player_count }} / {{ room.max_players }}
                                </span>
                                <span v-if="room.thoi_ach_enabled" class="px-2 py-0.5 bg-rose-500/10 border border-rose-500/20 text-[10px] font-bold text-rose-400 rounded-md">
                                    🃏 Thối Ách
                                </span>
                                <span v-else class="px-2 py-0.5 bg-slate-800 text-[10px] font-bold text-slate-400 rounded-md">
                                    Thường
                                </span>
                                <span v-if="room.is_private" class="px-2 py-0.5 bg-amber-500/10 border border-amber-500/20 text-[10px] font-bold text-amber-400 rounded-md">
                                    🔒 Khóa
                                </span>
                            </div>
                        </div>
                        <span class="text-xs font-mono font-bold bg-slate-950 border border-slate-800/80 px-2 py-1 rounded text-slate-400">
                            #{{ room.code }}
                        </span>
                    </div>

                    <div class="flex items-center justify-between border-t border-slate-850 pt-3">
                        <span class="text-xs text-slate-500 font-medium">Bấm để tham gia bàn đấu</span>
                        <button
                            @click="joinRoom(room.code)"
                            :disabled="room.player_count >= room.max_players"
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:bg-slate-800 disabled:text-slate-600 disabled:cursor-not-allowed text-white text-xs font-extrabold rounded-xl transition duration-200 tracking-wider shadow-sm"
                        >
                            {{ room.player_count >= room.max_players ? 'ĐẦY' : 'VÀO CHƠI' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
