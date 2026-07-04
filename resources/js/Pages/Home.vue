<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const name = ref('');
const loading = ref(false);

function submit() {
    if (!name.value.trim() || name.value.trim().length < 2) return;
    loading.value = true;
    router.post('/guest', { name: name.value.trim() }, {
        onFinish: () => loading.value = false,
    });
}
</script>

<template>
    <div class="min-h-screen bg-slate-950 relative overflow-hidden flex items-center justify-center p-4 font-sans select-none">
        <!-- Radial Ambient Glows -->
        <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-emerald-500/10 rounded-full blur-[100px] pointer-events-none"></div>
        <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-amber-500/10 rounded-full blur-[100px] pointer-events-none"></div>

        <!-- Floating Card Suits Background Animation -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none opacity-20">
            <div class="suit-float spade top-[15%] left-[10%] text-6xl text-emerald-400/30">♠</div>
            <div class="suit-float heart top-[65%] left-[20%] text-6xl text-rose-500/30">♥</div>
            <div class="suit-float club top-[25%] right-[15%] text-6xl text-emerald-400/30">♣</div>
            <div class="suit-float diamond top-[75%] right-[25%] text-6xl text-rose-500/30">♦</div>
        </div>

        <!-- Card Container -->
        <div class="relative w-full max-w-md bg-slate-900/60 backdrop-blur-xl border border-slate-800 rounded-3xl shadow-[0_0_50px_rgba(16,185,129,0.08)] p-8 md:p-10 transition-all duration-300 hover:border-emerald-500/30">
            <!-- Decorative top line -->
            <div class="absolute top-0 left-1/2 -translate-x-1/2 w-32 h-[3px] bg-gradient-to-r from-transparent via-emerald-500 to-transparent"></div>

            <div class="text-center mb-8">
                <!-- Glowing Poker Chip / Card Logo -->
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-br from-emerald-500/20 to-emerald-600/10 border border-emerald-500/30 shadow-[0_0_20px_rgba(16,185,129,0.2)] mb-4 animate-pulse">
                    <span class="text-4xl">🃏</span>
                </div>
                <h1 class="text-3xl font-extrabold tracking-tight text-white mb-2 bg-gradient-to-r from-emerald-400 via-teal-300 to-amber-300 bg-clip-text text-transparent">
                    CATTE ONLINE
                </h1>
                <p class="text-slate-400 text-sm font-medium">Đây chỉ là dự án học tập về lập trình.</p>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <div class="space-y-2">
                    <label for="name" class="block text-xs font-semibold uppercase tracking-wider text-slate-400">
                        Biệt danh của bạn
                    </label>
                    <div class="relative">
                        <!-- Input icon -->
                        <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-500">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 text-emerald-500/70">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                            </svg>
                        </span>
                        <input
                            id="name"
                            v-model="name"
                            type="text"
                            maxlength="20"
                            placeholder="Nhập tên hiển thị..."
                            class="w-full pl-11 pr-4 py-3.5 bg-slate-950/80 border border-slate-800 rounded-xl text-white placeholder-slate-600 outline-none transition duration-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"
                            autofocus
                        />
                    </div>
                    <p class="text-[11px] text-slate-500">Tên tối thiểu 2 ký tự, tối đa 20 ký tự.</p>
                </div>

                <button
                    type="submit"
                    :disabled="loading || name.trim().length < 2"
                    class="relative w-full py-4 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold rounded-xl shadow-lg shadow-emerald-500/10 hover:shadow-emerald-500/20 transition-all duration-300 hover:from-emerald-400 hover:to-teal-500 disabled:opacity-30 disabled:pointer-events-none transform active:scale-[0.98]"
                >
                    <span v-if="loading" class="flex items-center justify-center gap-2">
                        <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Đang kết nối...
                    </span>
                    <span v-else class="tracking-wide">BẮT ĐẦU CHƠI</span>
                </button>
            </form>

            <div class="mt-8 text-center border-t border-slate-800/80 pt-6">
                <div class="flex justify-center gap-4 text-xs text-slate-500 font-medium">
                    <span>⚡ Nhanh chóng</span>
                    <span>•</span>
                    <span>🤝 Trực tuyến</span>
                    <span>•</span>
                    <span>🎮 Kịch tính</span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes float {
    0%, 100% { transform: translateY(0) rotate(0deg); }
    50% { transform: translateY(-20px) rotate(10deg); }
}

.suit-float {
    position: absolute;
    animation: float 8s ease-in-out infinite;
}

.suit-float:nth-child(even) {
    animation-delay: 2s;
    animation-duration: 10s;
}

.suit-float:nth-child(3) {
    animation-delay: 4s;
    animation-duration: 9s;
}

.suit-float:nth-child(4) {
    animation-delay: 6s;
    animation-duration: 11s;
}
</style>
