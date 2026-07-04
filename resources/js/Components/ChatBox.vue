<script setup>
import { ref, onMounted, nextTick } from 'vue';

const props = defineProps({
    roomId: { type: Number, required: true },
    playerName: { type: String, required: true },
});

const messages = ref([]);
const newMessage = ref('');
const chatContainer = ref(null);
const reactions = ['👍', '😂', '😡', '😢', '🎉', '🤔'];
const showReactions = ref(false);
const floatingReactions = ref([]);

const isCollapsed = ref(true); // Default to collapsed (minimize bubble) on load
const unreadCount = ref(0);

onMounted(() => {
    const channel = window.Echo.join(`room.${props.roomId}`);

    channel.listen('ChatMessage', (e) => {
        addMessage(e);
    });

    // Reactions via client events (whisper) - no server round-trip
    channel.listenForWhisper('reaction', (e) => {
        showFloatingReaction(e.emoji, e.player_name);
    });
});

function addMessage(msg) {
    messages.value.push(msg);
    if (messages.value.length > 50) messages.value.shift();
    
    if (isCollapsed.value) {
        unreadCount.value++;
    }

    nextTick(() => {
        if (chatContainer.value) {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }
    });
}

async function sendMessage() {
    if (!newMessage.value.trim()) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    try {
        await fetch(`/api/rooms/${props.roomId}/chat`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
            credentials: 'same-origin',
            body: JSON.stringify({ message: newMessage.value.trim() }),
        });
        newMessage.value = '';
    } catch (e) { /* throttled */ }
}

function sendReaction(emoji) {
    showReactions.value = false;
    // Send via client event (whisper) - instant, no server needed
    window.Echo.join(`room.${props.roomId}`).whisper('reaction', {
        emoji,
        player_name: props.playerName,
    });
    // Show locally for self
    showFloatingReaction(emoji, props.playerName);
}

function showFloatingReaction(emoji, name) {
    const id = Date.now() + Math.random();
    floatingReactions.value.push({ id, emoji, name });
    setTimeout(() => {
        floatingReactions.value = floatingReactions.value.filter(r => r.id !== id);
    }, 2000);
}

function openChat() {
    isCollapsed.value = false;
    unreadCount.value = 0;
    nextTick(() => {
        if (chatContainer.value) {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }
    });
}
</script>

<template>
    <div class="chat-system-wrapper">
        <!-- Floating reactions wrapper (centered relative to player screen) -->
        <div class="floating-reactions">
            <TransitionGroup name="fade-float">
                <div v-for="r in floatingReactions" :key="r.id" class="floating-emoji-item">
                    <span class="emoji">{{ r.emoji }}</span>
                    <span class="sender-name">{{ r.name }}</span>
                </div>
            </TransitionGroup>
        </div>

        <!-- Collapsed Floating Chat Bubble Button -->
        <Transition name="bubble-fade">
            <button 
                v-if="isCollapsed" 
                @click="openChat" 
                class="chat-bubble-btn"
                title="Mở trò chuyện"
            >
                <!-- Unread messages badge -->
                <span v-if="unreadCount > 0" class="unread-badge">
                    {{ unreadCount }}
                </span>
                
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a.75.75 0 0 1-1.074-.765 6 6 0 0 0 1.947-3.479C3.827 15.029 3 13.601 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                </svg>
            </button>
        </Transition>

        <!-- Expanded Chat Box Panel -->
        <Transition name="panel-scale">
            <div v-if="!isCollapsed" class="chat-container">
                <!-- Header -->
                <div class="chat-header">
                    <div class="flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                        <span class="text-xs font-black tracking-wide text-slate-300 uppercase">Trò chuyện</span>
                    </div>
                    
                    <!-- Collapse Button -->
                    <button 
                        @click="isCollapsed = true" 
                        class="minimize-btn" 
                        title="Thu nhỏ"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                            <path fill-rule="evenodd" d="M4 10a.75.75 0 0 1 .75-.75h10.5a.75.75 0 0 1 0 1.5H4.75A.75.75 0 0 1 4 10Z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </div>

                <!-- Messages list -->
                <div ref="chatContainer" class="messages-area">
                    <div v-for="(msg, i) in messages" :key="i" class="message">
                        <span class="message-name">{{ msg.player_name }}</span>
                        <span class="message-text">{{ msg.message }}</span>
                    </div>
                    <div v-if="messages.length === 0" class="empty-chat-placeholder">
                        Bắt đầu trò chuyện với mọi người...
                    </div>
                </div>

                <!-- Input field and emojis -->
                <div class="chat-input-area">
                    <!-- Emoji picker toggle button -->
                    <button @click="showReactions = !showReactions" class="reaction-toggle" title="Gửi cảm xúc">
                        😊
                    </button>
                    
                    <!-- Reaction Picker Popover -->
                    <Transition name="picker-slide">
                        <div v-if="showReactions" class="reaction-picker">
                            <button 
                                v-for="r in reactions" 
                                :key="r" 
                                @click="sendReaction(r)" 
                                class="reaction-btn"
                            >
                                {{ r }}
                            </button>
                        </div>
                    </Transition>

                    <input
                        v-model="newMessage"
                        @keyup.enter="sendMessage"
                        placeholder="Nhắn tin..."
                        maxlength="100"
                        class="chat-input"
                    />
                    
                    <button @click="sendMessage" class="send-btn" :disabled="!newMessage.trim()">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5">
                            <path d="M3.105 2.288a.75.75 0 0 0-.826.95l1.414 4.925A1.5 1.5 0 0 0 5.135 9.25h6.115a.75.75 0 0 1 0 1.5H5.135a1.5 1.5 0 0 0-1.442 1.087l-1.414 4.926a.75.75 0 0 0 .826.95 28.896 28.896 0 0 0 15.293-7.154.75.75 0 0 0 0-1.115A28.897 28.897 0 0 0 3.105 2.288Z" />
                        </svg>
                    </button>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.chat-system-wrapper {
    position: fixed;
    bottom: 16px;
    right: 16px;
    z-index: 50;
    font-family: sans-serif;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}

/* Collapsed Chat Bubble Button */
.chat-bubble-btn {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: rgba(15, 23, 42, 0.85);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #cbd5e1;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(8px);
    transition: all 0.2s cubic-bezier(0.18, 0.89, 0.32, 1.28);
    position: relative;
}
.chat-bubble-btn:hover {
    background: rgba(15, 23, 42, 0.95);
    transform: scale(1.08) translateY(-2px);
    color: white;
}
.chat-bubble-btn:active {
    transform: scale(0.95);
}

.unread-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #ef4444;
    color: white;
    font-size: 10px;
    font-weight: 900;
    min-width: 18px;
    height: 18px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    border: 1.5px solid #0f172a;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
}

/* Expanded Chat Container Panel */
.chat-container {
    width: 280px;
    max-height: 260px;
    background: rgba(15, 23, 42, 0.9);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    overflow: visible;
    backdrop-filter: blur(12px);
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.5);
}

.chat-header {
    padding: 10px 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.minimize-btn {
    color: #94a3b8;
    background: transparent;
    padding: 2px;
    border-radius: 6px;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.minimize-btn:hover {
    color: white;
    background: rgba(255, 255, 255, 0.06);
}

.messages-area {
    flex: 1;
    overflow-y: auto;
    padding: 8px 12px;
    max-height: 160px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

/* Scrollbar styles */
.messages-area::-webkit-scrollbar {
    width: 4px;
}
.messages-area::-webkit-scrollbar-track {
    background: transparent;
}
.messages-area::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 2px;
}

.message {
    font-size: 11.5px;
    color: #f1f5f9;
    line-height: 1.4;
    word-break: break-word;
    display: flex;
    align-items: flex-start;
    gap: 6px;
}
.message-name {
    font-weight: 800;
    color: #67e8f9;
    flex-shrink: 0;
}
.message-name::after {
    content: ':';
    color: rgba(255, 255, 255, 0.3);
    margin-left: 1px;
}
.message-text {
    color: #cbd5e1;
}

.empty-chat-placeholder {
    text-align: center;
    font-size: 11px;
    color: rgba(255, 255, 255, 0.25);
    padding: 24px 0;
    font-style: italic;
}

.chat-input-area {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    border-top: 1px solid rgba(255, 255, 255, 0.05);
    position: relative;
}

.chat-input {
    flex: 1;
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: 10px;
    padding: 6px 10px;
    color: #f8fafc;
    font-size: 12px;
    outline: none;
    transition: all 0.2s;
}
.chat-input:focus {
    border-color: rgba(16, 185, 129, 0.5);
    background: rgba(0, 0, 0, 0.5);
}

.send-btn {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: #10b981;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    flex-shrink: 0;
}
.send-btn:hover:not(:disabled) {
    background: #059669;
    transform: scale(1.05);
}
.send-btn:disabled {
    background: rgba(255, 255, 255, 0.05);
    color: rgba(255, 255, 255, 0.2);
    cursor: not-allowed;
}

.reaction-toggle {
    width: 28px;
    height: 28px;
    font-size: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 8px;
    transition: background 0.2s;
}
.reaction-toggle:hover {
    background: rgba(255, 255, 255, 0.12);
}

/* Reaction Picker */
.reaction-picker {
    position: absolute;
    bottom: 50px;
    left: 8px;
    background: rgba(15, 23, 42, 0.95);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 6px;
    display: flex;
    gap: 6px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(8px);
}
.reaction-btn {
    font-size: 18px;
    padding: 3px;
    cursor: pointer;
    transition: transform 0.2s ease;
}
.reaction-btn:hover {
    transform: scale(1.3) translateY(-2px);
}

/* Floating reactions area */
.floating-reactions {
    position: absolute;
    bottom: 100%;
    right: 12px;
    pointer-events: none;
    z-index: 100;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.floating-emoji-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 8px;
    background: rgba(15, 23, 42, 0.9);
    border: 1px solid rgba(255, 255, 255, 0.1);
    padding: 4px 10px;
    border-radius: 16px;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
    animation: float-up 2.2s cubic-bezier(0.18, 0.89, 0.32, 1.28) forwards;
}

.floating-emoji-item .emoji {
    font-size: 26px;
}
.floating-emoji-item .sender-name {
    font-size: 9px;
    color: #94a3b8;
    font-weight: 700;
}

/* Animations */
.bubble-fade-enter-active, .bubble-fade-leave-active {
    transition: all 0.2s ease;
}
.bubble-fade-enter-from, .bubble-fade-leave-to {
    opacity: 0;
    transform: scale(0.8);
}

.panel-scale-enter-active, .panel-scale-leave-active {
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.panel-scale-enter-from, .panel-scale-leave-to {
    opacity: 0;
    transform: scale(0.85) translateY(20px);
}

.picker-slide-enter-active, .picker-slide-leave-active {
    transition: all 0.2s ease;
}
.picker-slide-enter-from, .picker-slide-leave-to {
    opacity: 0;
    transform: translateY(10px);
}

@keyframes float-up {
    0% {
        opacity: 0;
        transform: translateY(20px) scale(0.7);
    }
    15% {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
    85% {
        opacity: 1;
    }
    100% {
        opacity: 0;
        transform: translateY(-80px) scale(1.1);
    }
}

@media (max-width: 767px) {
    .chat-system-wrapper {
        top: 16px;
        left: 16px;
        bottom: auto;
        right: auto;
        align-items: flex-start;
    }
    
    .chat-container {
        position: fixed;
        top: 68px;
        left: 16px;
        right: 16px;
        width: auto;
        max-width: 280px;
    }
    
    .floating-reactions {
        bottom: auto;
        top: 100%;
        left: 12px;
        right: auto;
        align-items: flex-start;
    }
}
</style>
