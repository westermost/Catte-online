# Catte Online - Steering Document

## Project Overview

Game đánh bài Cát Tê online realtime, 2-6 người chơi, chạy trên shared hosting (Hostinger).

## Tech Stack

- **Backend:** Laravel 13 + PHP 8.4 + SQLite (dev) / MySQL (prod)
- **Frontend:** Vue 3 + Inertia.js + Tailwind CSS 4 + Vite
- **Realtime:** Pusher (outgoing only) + Laravel Echo + Client Events (whisper)
- **Animations:** GSAP
- **Sound:** Howler.js

## Architecture Principles

### Realtime Strategy: "Optimistic Realtime + Polling Safety Net"

Không phụ thuộc 100% vào Pusher. Pattern chuẩn:
1. **Pusher events** cho UX nhanh (instant feedback)
2. **Polling nhẹ (1.5s)** làm fallback khi event bị miss
3. **Refresh state on event** — khi nhận event, fetch state endpoint ngay (không đợi poll cycle)
4. **Lightweight state endpoint** (`/api/rooms/{room}/state`) — trả payload nhẹ, sanitize, dùng chung logic

Lý do: Shared hosting + Pusher free tier có thể không ổn định 100%. Polling đảm bảo consistency.

### Guest Broadcasting Auth

Laravel Broadcasting yêu cầu authenticated user cho presence/private channels. Vì dùng guest system:
- `GuestBroadcastUser` implements `Authenticatable` — minimal fake user
- `SetGuestBroadcastUser` middleware — set user resolver từ session guest_token
- Đăng ký middleware trên broadcasting routes trong `bootstrap/app.php`
- Channel auth callback nhận `GuestBroadcastUser` thay vì `$user` nullable

### Channel Strategy

| Channel | Type | Purpose |
|---------|------|---------|
| `lobby` | Public | Phòng mới/xóa cho lobby |
| `presence-room.{roomId}` | Presence | Player list, game state, chat |
| `private-player.{playerId}` | Private | Hand riêng |

**Không bao giờ broadcast hand qua channel chung.**

### Client Events (Whisper)

Dùng cho data không cần validate server-side:
- Quick reactions (emoji) — instant, không qua server
- Typing indicators

Giữ HTTP cho: text chat (rate-limit), game actions (validate).

## Security Rules

### Sensitive Fields Never Exposed to Client

- `Player.session_id` → `$hidden`
- `Player.guest_token` → `$hidden`
- `Score.guest_token` → `$hidden`
- `Game.hands` → never in Inertia props, only via `private-player` or `/api/game/{id}/my-hand`
- `Room` → always use `only()` in Inertia response

### Session/Cookie Design

- `guest_token`: UUID, stored in **separate httpOnly cookie** (`catte_guest_token`, TTL 30 days)
- Session cookie: shorter TTL, standard Laravel session
- Reconnect: `POST /guest/restore` — browser sends cookie, server reads it, updates `players.session_id`, re-sets session

### Channel Names

- Use `playerId` (DB primary key) in channel names, NEVER `sessionId`
- Auth verifies guest_token → player ownership

## Game Logic Patterns

### Round Completion

- `rounds.participant_count` — set at round creation, persisted
- All completion checks (`playCard`, `claimTimeout`, `forceTimeout`) compare `totalPlays >= $round->participant_count`
- Never use live `count(activePlayers)` for completion — kicks mid-trick would corrupt it

### Player Status Lifecycle

```
connected → disconnected (Pusher member_removed)
connected → eliminated (gục tùng after round 4)
connected → kicked (2 consecutive timeouts)
connected → left (voluntary leave)

eliminated → connected (endGame restores)
kicked → connected (endGame restores)
disconnected → connected (reconnect/restore)
```

### Timeout Design (Shared Hosting)

No daemon → client-initiated timeout claim:
1. Server sets `games.turn_started_at` on each turn
2. All clients run 30s countdown locally
3. Any client can `POST /api/game/{id}/claim-timeout` (window: 30-60s)
4. DB transaction + `SELECT FOR UPDATE` prevents race conditions
5. Cron (`schedule:run` every minute) as backup for stale turns > 60s
6. `timeout_count` scope: within same game, reset on successful play or new game

### Eliminated Players

- Marked `status = 'eliminated'` after round 4
- Excluded from `getActivePlayersForGame()` (only queries connected/disconnected)
- Restored to `connected` in `endGame()`
- Their IDs queried from DB and passed to `endGame()` for penalty scoring

## Database Notes

### Migration Order Matters

- `rooms` → `players` → `scores` → `games` → `rounds` → `plays`
- plays references rounds FK — must run after

### `games.hands` Column

- Use `TEXT` not `JSON` — Laravel `encrypted:array` cast writes base64 string, MySQL JSON rejects it
- SQLite doesn't enforce but MySQL does

## Frontend Patterns

### Inertia Prop Sync

```js
// Always watch props for Inertia re-renders
watch(() => props.players, (val) => { playerList.value = [...val]; });
watch(() => props.game, (val) => { activeGame.value = val; });
```

Without this, reactive refs initialized from props won't update on Inertia navigation.

### Actor's Own Play

`CardPlayed` broadcast uses `toOthers()` → playing client never receives it. Fix:
1. `playCard` response returns `{ hand, card_played, face_down }`
2. Parent component (Room.vue) calls `gameTableRef.addPlayedCard()` from response
3. GameTable exposes methods via `defineExpose()`

### TurnStarted vs RoundEnded

- `TurnStarted` — fires after each play (next player's turn). Do NOT clear trick cards here.
- `RoundEnded` — fires when all players played. Clear trick cards here (after animation delay).

## Deployment (Hostinger Shared Hosting)

- Queue: `sync` (no daemon)
- Session: `database`
- Broadcast: `pusher` (outgoing HTTP only)
- Cron: `* * * * * php artisan schedule:run`
- Build: `npm run build` → `public/build/`
- No WebSocket server on host — Pusher handles all client push

## Known Gotchas

### diffInSeconds tính ngược
`now()->diffInSeconds($game->turn_started_at)` trả về giá trị tuyệt đối nhưng trong một số case Carbon tính ngược (tương lai - quá khứ). Đã fix bằng cách đảm bảo thứ tự đúng tại `GameController.php:160`.

### getNextSeatPosition phải check tất cả status
UNIQUE constraint `(room_id, seat_position)` áp dụng cho TẤT CẢ rows. Query chỉ check `connected`/`disconnected` sẽ miss seats bị giữ bởi `eliminated`/`kicked` players. Fix: exclude chỉ `left`.

### Client watchdog cho timeout
Client có watchdog tự claim timeout lại nếu turn bị stale (`GameTable.vue:294`). Đảm bảo game không bị stuck nếu claim đầu tiên fail.

## Remaining Work / TODO

1. **Manual QA realtime** — test 2-4 browser tabs: join, start, play, timeout, leave, reconnect
2. **Verify cron production** — `forceTimeout` chỉ chạy nếu scheduler mỗi phút hoạt động
3. **Verify Pusher production** — app key, cluster, private channel auth, HTTPS/cookie
4. **Commit/push** — working tree có nhiều file modified sau fixes
5. **Thêm tests** — forceTimeout cron, rời phòng giữa ván, round 5/6 reveal, tính điểm UI
6. **QA responsive** — mobile + màn hình nhiều người chơi sau đại tu bàn bài
