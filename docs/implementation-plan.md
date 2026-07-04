# Implementation Plan - Game Catte Online

## Problem Statement

Xây dựng game đánh bài Cát Tê online realtime, chơi vui, chạy trên shared hosting (Hostinger), hỗ trợ 2-6 người chơi với hệ thống phòng, animation đẹp, và giao tiếp realtime qua Pusher.

## Requirements

| Hạng mục | Quyết định |
|----------|-----------|
| Frontend | Vue 3 SPA + Inertia.js |
| Backend | Laravel 13 + MySQL |
| Realtime | Pusher (free tier: 200k msg/day, 100 concurrent — as of July 2026, verify before launch) |
| Hosting | Shared hosting Hostinger |
| Số người chơi | Mặc định 4, cho phép 2-6 |
| User system | Guest (nhập tên vào chơi), lưu bảng điểm phòng, cho download |
| Hệ thống phòng | Lobby công khai + phòng riêng bằng mã |
| UX | Animation đẹp (bài lật, bay, sound effects) |
| Timeout | 30s/lượt, auto-kick sau 2 lần hết giờ liên tiếp |
| Chat | Text chat + quick reactions/emoji |
| Luật thối Ách | Chủ phòng toggle on/off |
| Luật thắng trắng | Tứ quý > 6 lá cùng chất > 6 lá < 6 |

## Architecture

```
Browser --HTTP POST--> Laravel + MySQL (Hostinger)
                           |
                           +--outgoing API--> Pusher Cloud
                           |                      |
                           |                      v
                           |              WebSocket push to Browser
                           |
                           +--Game state--> MySQL
```

## Technology Stack

| Package (Composer) | Purpose |
|---------|---------|
| `inertiajs/inertia-laravel` | Server-side Inertia adapter |
| `pusher/pusher-php-server` | Pusher PHP SDK |

| Package (NPM) | Purpose |
|---------|---------|
| `vue` (^3.x) | Frontend framework |
| `@inertiajs/vue3` | Inertia client adapter |
| `@vitejs/plugin-vue` | Vite Vue plugin |
| `pusher-js` | Pusher client SDK |
| `laravel-echo` | Broadcasting client wrapper |
| `gsap` | Card animations |
| `howler` | Sound effects |

**Broadcasting:** Config qua `config/broadcasting.php` + env keys. Laravel framework có sẵn Broadcasting support, không cần package `laravel/broadcasting` riêng.

**Shared hosting constraints:**
- Queue driver: `sync` (no daemon)
- Session driver: `database`
- No WebSocket server — Pusher handles outbound
- Timer: client-initiated request pattern (xem Task 8)

## Guest Session + Channel Authorization

### Guest Identity
1. Nhập tên → server tạo `guest_token` (UUID), lưu vào session VÀ set cookie riêng `catte_guest_token` (httpOnly, TTL 30 ngày — dài hơn session cookie)
2. Join phòng → tạo record `players` với `session_id = session()->getId()`
3. Reconnect: nếu session mất (tab đóng, session cookie hết hạn), cookie `catte_guest_token` vẫn còn (TTL dài hơn):
   - Client gọi `POST /guest/restore` (không cần body, browser tự gửi cookie httpOnly)
   - Server đọc `guest_token` từ cookie `catte_guest_token`
   - Server tìm player record theo guest_token
   - Server cập nhật `players.session_id = session()->getId()` (session mới)
   - Server set lại `session(['guest_token' => $guestToken])` để channel auth hoạt động
   - Trả về player info + room info để client rejoin

### Channel Auth (`POST /broadcasting/auth`)
- Kiểm tra session có `guest_token` hợp lệ
- Player record tồn tại với matching `session_id` (đã được update sau restore)
- Player thuộc room/game được subscribe
- Channel name dùng `player.id` (DB primary key), KHÔNG dùng session ID

### Channel Strategy (Bảo mật bài riêng)

| Channel | Type | Subscriber | Data |
|---------|------|-----------|------|
| `presence-room.{roomId}` | Presence | Tất cả trong phòng | Player list, room status, chat, public game state |
| `private-player.{playerId}` | Private | Chỉ 1 player | Hand riêng, thông báo cá nhân |
| `presence-lobby` | Presence | Ai ở lobby | Danh sách phòng |

### Nguyên tắc bảo mật payload
- **KHÔNG BAO GIỜ** broadcast hand qua channel chung
- Public game state (vòng, lượt, bài đã đánh ngửa) → `presence-room`
- Hand riêng → `private-player.{playerId}` hoặc `GET /api/game/{id}/my-hand`
- Bài úp của người khác chỉ hiển thị mặt sau, không gửi giá trị

## Database Schema

### rooms
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| code | string UNIQUE | 6 ký tự uppercase |
| name | string | |
| max_players | int | default 4, range 2-6 |
| is_private | boolean | default false |
| thoi_ach_enabled | boolean | default false |
| status | enum | waiting, playing, finished |
| owner_player_id | FK players.id nullable | null trước khi có player join |
| created_at | timestamp | |
| updated_at | timestamp | |

### players
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| room_id | FK rooms.id | |
| session_id | string | Laravel session ID |
| guest_token | string | UUID, for reconnect |
| name | string | |
| seat_position | int | |
| status | enum | connected, disconnected, kicked, left |
| timeout_count | int | reset khi play thành công |
| last_active_at | timestamp | |
| created_at | timestamp | |
| updated_at | timestamp | |

### games
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| room_id | FK rooms.id | |
| game_number | int | |
| phase | enum | instant_win_check, playing, chung, finished |
| current_round | int | 1-6 |
| current_player_id | FK players.id | |
| hands | json encrypted | {player_id: [cards]} |
| instant_winner_id | bigint nullable | |
| instant_win_type | string nullable | four_of_a_kind, flush_6, low_6 |
| winner_id | bigint nullable | |
| turn_started_at | timestamp | Khi current_player bắt đầu lượt |
| started_at | timestamp | |
| updated_at | timestamp | |

### rounds
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| game_id | FK games.id | |
| round_number | int | 1-6 |
| lead_player_id | FK players.id | |
| lead_suit | char(1) | H, D, C, S |
| winner_id | FK players.id nullable | |
| started_at | timestamp | |
| completed_at | timestamp nullable | |

### plays
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| round_id | FK rounds.id | |
| player_id | FK players.id | |
| card | string | e.g. AH, KS, 10D |
| is_face_down | boolean | true = thiệp |
| play_order | int | 1-based |
| created_at | timestamp | |

### scores
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| room_id | FK rooms.id | |
| guest_token | string | UUID, stable across reconnects |
| player_name | string | |
| total_points | int | default 0 |
| games_won | int | default 0 |
| games_lost | int | default 0 |
| tung_deaths | int | default 0 |
| thoi_ach_count | int | default 0 |
| instant_wins | int | default 0 |
| updated_at | timestamp | |

### Indexes & Constraints
- `rooms`: UNIQUE(code)
- `players`: UNIQUE(room_id, seat_position), INDEX(session_id), INDEX(guest_token)
- `games`: INDEX(room_id), INDEX(current_player_id)
- `rounds`: UNIQUE(game_id, round_number)
- `plays`: UNIQUE(round_id, play_order), INDEX(player_id)
- `scores`: UNIQUE(room_id, guest_token)

## Game Rules - Đặc tả chi tiết (Cát Tê 6 lá)

### Bộ bài
- 52 lá, không Joker
- Mỗi người chia 6 lá

### Thứ tự lá bài (cao → thấp)
A > K > Q > J > 10 > 9 > 8 > 7 > 6 > 5 > 4 > 3 > 2

**Ace luôn cao nhất** (không có luật Ace thấp).

### Thứ tự chất (dùng khi tie-break)
Cơ (H) > Rô (D) > Chuồn (C) > Bích (S)

### Card notation
- Rank: 2,3,4,5,6,7,8,9,10,J,Q,K,A
- Suit: H(hearts), D(diamonds), C(clubs), S(spades)
- Ví dụ: AH = Ace Cơ, 10D = 10 Rô, KS = King Bích

### Thắng trắng (Instant Win)
Kiểm tra ngay sau khi chia bài, trước vòng 1.

**Thứ tự ưu tiên (cao → thấp):**
1. **Tứ quý (Four of a Kind):** 4 lá cùng rank. Nếu nhiều người có tứ quý → so rank tứ quý (A > K > ... > 2)
2. **6 lá cùng chất (Flush 6):** Tất cả 6 lá cùng suit. Nếu nhiều người → so chất (H > D > C > S)
3. **6 lá nhỏ hơn 6 (Low 6):** Tất cả 6 lá có rank <= 5 (2,3,4,5). Nếu nhiều người → so lá cao nhất, nếu bằng → so chất lá cao nhất

**Precedence:** Tứ quý thắng Flush 6, Flush 6 thắng Low 6. Nếu cùng loại thì so theo rule trên. Chỉ 1 người thắng trắng/ván.

### Vòng 1-4: Trick-taking

**Bắt đầu vòng:** Người dẫn đánh ra 1 lá ngửa (lead card). Chất của lá này = lead_suit.

**Lượt tiếp theo (theo chiều kim đồng hồ):** Người chơi PHẢI chọn 1 trong 2:
- **Úp (đánh cùng chất lớn hơn):** Đánh 1 lá cùng lead_suit VÀ rank lớn hơn lá đang thắng trên bàn
- **Thiệp (úp bài):** Úp 1 lá bất kỳ (face down), không cần cùng chất

**Lưu ý quan trọng:** Người chơi KHÔNG bắt buộc phải đánh cùng chất nếu có. Có thể chọn thiệp dù có lá cùng chất lớn hơn (chiến thuật).

**Kết thúc vòng:** Lá ngửa lớn nhất cùng lead_suit thắng vòng. Người thắng có "tồn" và dẫn vòng tiếp.

**Ai dẫn vòng 1:** Ván đầu tiên trong phòng → owner (chủ phòng) dẫn. Các ván sau → người thắng ván trước dẫn. Nếu người đó đã rời phòng → người ngồi kế (clockwise).

### Sau vòng 4: Xét tồn

- Đếm số vòng mỗi người thắng (tồn count)
- **Gục tùng:** Ai không có tồn nào → bị loại
- **Thắng tùng:** Nếu chỉ còn 1 người có tồn → thắng ngay (+2 điểm)
- **Tiếp tục:** Nếu >= 2 người có tồn → vào vòng 5-6

### Vòng 5: Chưng

- Người thắng vòng 4 dẫn đầu, đánh 1 lá ngửa
- Các người còn lại đánh 1 lá (có thể úp trước, lật sau cùng lúc — server reveal đồng thời)
- Quy tắc thắng: giống vòng 1-4 (cùng chất lead + lớn hơn)
- Người thắng vòng 5 dẫn vòng 6

### Vòng 6: Quyết định

- Tất cả lật lá cuối cùng đồng thời
- Người dẫn (thắng vòng 5) chọn lá đánh ra = lead_suit
- Quy tắc thắng: giống các vòng trước
- **Người thắng vòng 6 = thắng ván**

### Tie-breaking
**Trong trick (vòng 1-6):** Không thể xảy ra tie vì mỗi lá unique. Chỉ có 1 lá cùng chất + lớn nhất → thắng.

**Trong thắng trắng (instant win):** Nếu nhiều người cùng loại instant win:
- Tứ quý: so rank của bộ 4 (A > K > ... > 2)
- Flush 6: so chất (H > D > C > S)
- Low 6: so lá cao nhất trong hand; nếu bằng → so chất lá cao nhất (H > D > C > S)

**Suit order (H > D > C > S) chỉ dùng cho instant win tie-break, KHÔNG dùng trong trick.**

### Scoring
| Kết quả | Điểm |
|---------|------|
| Thắng ván (vòng 6) | +1 |
| Thắng tùng | +2 |
| Thắng trắng | +2 |
| Gục tùng | -1 |
| Thối Ách (nếu bật) | -1 mỗi lá Ace còn trên tay khi gục tùng |

**Thối Ách:** Chỉ phạt người bị gục tùng mà còn giữ Ace. Ace đã đánh ra (ngửa hoặc thiệp) không tính.

### Auto-úp khi timeout
Khi hết 30s, server tự chọn lá để thiệp:
- Ưu tiên lá nhỏ nhất không cùng lead_suit
- Nếu tất cả cùng lead_suit → chọn lá nhỏ nhất
- Lá được úp (is_face_down = true)

## Timer & Auto-kick: Shared Hosting Design

### Vấn đề
Shared hosting không có daemon/worker chạy liên tục. Server không thể tự trigger action sau 30s nếu không có HTTP request.

### Giải pháp: Client-initiated timeout claim

```
Client A (đang chờ): countdown 30s
                     |
                     v (hết 30s)
Client A gửi POST /api/game/{id}/claim-timeout
                     |
                     v
Server kiểm tra:
  - turn_started_at + 30s <= now? (grace window: 30-60s)
  - current_player chưa đánh?
  - Requester thuộc game này?
                     |
                     v (valid)
Server auto-úp cho current_player
Broadcast event CardPlayed + TurnTimeout
```

### Flow chi tiết
1. Khi đến lượt player X, server set `games.turn_started_at = now()`
2. Broadcast event `TurnStarted { player_id, turn_started_at }` cho tất cả
3. Tất cả client chạy countdown 30s locally
4. Nếu player X đánh bài trong 30s → bình thường, reset timeout_count = 0
5. Nếu hết 30s:
   - Bất kỳ client nào gửi `POST /api/game/{id}/claim-timeout`
   - Server validate: `now() >= turn_started_at + 30s` AND `now() <= turn_started_at + 60s`
   - Server auto-úp 1 lá cho player X (thiệp)
   - `players.timeout_count += 1`
   - Nếu `timeout_count >= 2` → kick player, broadcast `PlayerKicked`
6. Grace window: client claim hợp lệ từ 30s đến 60s. Sau 60s → cron xử lý (backup).

### Race condition
- Dùng DB transaction + `SELECT ... FOR UPDATE` trên game record
- Chỉ request đầu tiên claim thành công, các request sau nhận response "already processed"

### Disconnect handling
- Pusher presence `member_removed` webhook → Laravel route nhận webhook
- Mark player status = `disconnected`
- Nếu player đang turn → các client khác thấy "Player X disconnected" + countdown vẫn chạy
- Hết 30s → client claim timeout như bình thường
- Player reconnect trong 30s → tiếp tục bình thường

### Cron (best-effort backup)
- Hostinger hỗ trợ cron tối thiểu 1 phút
- `* * * * * php artisan schedule:run`
- Scheduled command kiểm tra games có `turn_started_at` > 60s chưa xử lý → force timeout
- Đây là backup cho trường hợp tất cả client disconnect

### timeout_count: Scope và Reset Rules
- **Scope:** "2 lần liên tiếp" = trong cùng 1 ván (game), không xuyên ván
- **Reset về 0 khi:** player đánh bài thành công (không timeout)
- **Reset về 0 khi:** ván mới bắt đầu (game_number tăng)
- **KHÔNG reset khi:** reconnect (vẫn giữ count, tránh abuse disconnect/reconnect)
- **KHÔNG tăng khi:** player không còn trong round (đã gục tùng hoặc bị loại)
- **Kick chỉ trong ván đang chơi:** player bị kick khỏi ván hiện tại, vẫn ở trong phòng, có thể chơi ván sau

## Task Breakdown

### Task 1: Project Setup — Vue + Inertia + Broadcasting

**Objective:** Cài đặt Vue 3 + Inertia.js + Pusher broadcasting

**Steps:**
- `composer require inertiajs/inertia-laravel pusher/pusher-php-server`
- `npm install vue @inertiajs/vue3 @vitejs/plugin-vue pusher-js laravel-echo`
- Setup Inertia middleware, root Blade template (`app.blade.php`)
- Config `broadcasting.php` với Pusher driver
- Config `vite.config.js` thêm Vue plugin
- Tạo `resources/js/app.js` với createInertiaApp + Echo setup
- `.env`: thêm PUSHER keys

**Done khi:** Vue component render qua Inertia, Echo connect Pusher thành công trong console.

---

### Task 2: Guest System + Room Creation

**Objective:** Guest nhập tên, tạo/join phòng

**Steps:**
- Migration: rooms, players, scores tables (theo schema trên)
- Models: Room, Player, Score
- GuestController: `POST /guest` lưu name + guest_token vào session
- RoomController: create, join, list, leave
- Broadcasting auth route cho guest session
- Vue pages: Home.vue, Lobby.vue, Room.vue

**Done khi:** 2 browser tab join cùng phòng, thấy nhau trong player list.

---

### Task 3: Realtime Room Events

**Objective:** Join/leave/update phòng realtime qua Pusher

**Steps:**
- Events: PlayerJoined, PlayerLeft, RoomUpdated, GameStarting
- Presence channel `presence-room.{roomId}` auth
- Echo listen trên Vue components
- Pusher webhook route cho disconnect detection

**Done khi:** Tab A join → Tab B thấy ngay. Tab A đóng → Tab B thấy player offline.

---

### Task 4: Game Engine — Core Logic

**Objective:** Toàn bộ logic Cát Tê server-side

**Steps:**
- `App\Services\CatteGameEngine` class
- `dealCards()`: shuffle + deal 6/player
- `checkInstantWin()`: tứ quý, flush 6, low 6 (theo precedence)
- `playCard(playerId, card, faceDown)`: validate + execute
- `evaluateRound()`: xác định winner, track tồn
- `evaluatePostRound4()`: gục tùng / thắng tùng / continue
- `evaluateChung()`: vòng 5-6 logic
- `calculateScores()`: tính điểm cuối ván
- `autoPlay(playerId)`: chọn lá tối ưu cho timeout
- Artisan command `catte:simulate` để test

**Done khi:** Unit tests pass cho mọi scenario. `php artisan catte:simulate` chạy 1 ván đầy đủ.

---

### Task 5: Game Flow Controller + Events

**Objective:** HTTP endpoints + broadcast game state

**Steps:**
- `GameController`: startGame, playCard, claimTimeout, getMyHand
- Events: GameStarted, TurnStarted, CardPlayed, RoundEnded, PlayerEliminated, GameEnded
- Channel routing: public state qua presence-room, hand qua private-player
- Turn validation: đúng player, đúng thời gian
- Claim-timeout endpoint (theo Timer design)

**Done khi:** 2+ browser chơi 1 ván text-based hoàn chỉnh, state sync realtime.

---

### Task 6: Card UI + Table Layout

**Objective:** Bàn chơi visual với cards, seats

**Steps:**
- Components: GameTable, CardHand, Card, PlayerSeat, RoundInfo
- Card design: CSS/SVG playing cards
- Layout: bàn oval responsive, players quanh bàn
- Display: hand ngửa (mình), úp (người khác), played cards (giữa)
- Status indicators: lượt ai, vòng mấy, tồn count
- `npm install gsap`

**Done khi:** Bàn chơi render đẹp, responsive, hiển thị đúng state.

---

### Task 7: Card Animations + Sound Effects

**Objective:** Animation mượt, sound effects

**Steps:**
- GSAP: deal (bay từ deck), play (bay ra giữa), flip, collect trick
- Howler.js: `npm install howler` — sounds: deal, play, flip, win, lose, tick
- Animation queue: sequential, không overlap
- Special effects: thắng trắng (confetti), gục tùng (fade), thắng ván (glow)
- Vue transition hooks integrate với GSAP

**Done khi:** Chơi 1 ván với animation mượt, sound hoạt động.

---

### Task 8: Timer + Auto-kick

**Objective:** 30s countdown, auto-thiệp, kick sau 2 lần

**Steps:**
- Vue CountdownTimer component
- `POST /api/game/{id}/claim-timeout` endpoint
- Server validate turn_started_at, execute auto-play
- timeout_count tracking, kick logic
- Broadcast: TurnTimeout, PlayerKicked events
- DB transaction for race condition prevention
- Cron backup: `schedule:run` check stale turns > 60s

**Done khi:** Không đánh 30s → auto úp. Lần 2 → bị kick. Không race condition.

---

### Task 9: Chat + Quick Reactions

**Objective:** Text chat + emoji reactions realtime

**Steps:**
- Events: ChatMessage, Reaction (ephemeral, không lưu DB)
- Components: ChatBox, ReactionBar
- Quick reactions: 👍 😂 😡 😢 🎉 🤔
- Floating bubble animation gần avatar
- Rate limit: 5 msg/10s per player (middleware throttle)

**Done khi:** Chat message broadcast realtime. Emoji hiện animation bubble.

---

### Task 10: Scoreboard + Download

**Objective:** Bảng điểm phòng, download CSV/PNG

**Steps:**
- ScoreController: get scores, download CSV, download PNG
- Scoreboard.vue component
- Auto-update sau mỗi ván
- CSV export: tên, thắng, thua, gục tùng, thối Ách, tổng
- PNG: html2canvas client-side capture

**Done khi:** Bảng điểm cập nhật live. Download CSV/PNG hoạt động.

---

### Task 11: Lobby Polish + Room Management

**Objective:** Lobby UX, room lifecycle

**Steps:**
- Lobby realtime: presence-lobby channel
- Filters: waiting/playing/private
- Owner controls: kick, settings, start game
- Auto-cleanup: rooms inactive > 30 phút (lazy check on list + cron)
- Mobile responsive

**Done khi:** Lobby mượt, filter hoạt động, cleanup tự động.

---

### Task 12: Production Deployment

**Objective:** Deploy lên Hostinger shared hosting

**Steps:**
- `npm run build` → public/build
- `.env` production config
- MySQL database setup
- `.htaccess` cho Inertia SPA routing
- Cron: `* * * * * php artisan schedule:run`
- SSL certificate
- Test: multi-player, latency, Pusher limits

**Done khi:** Game chạy production, nhiều người chơi realtime trên Hostinger.

---

## Progress Tracking

- [ ] Task 1: Project Setup
- [ ] Task 2: Guest System + Room Creation
- [ ] Task 3: Realtime Room Events
- [ ] Task 4: Game Engine Core Logic
- [ ] Task 5: Game Flow Controller
- [ ] Task 6: Card UI + Table Layout
- [ ] Task 7: Card Animations + Sound
- [ ] Task 8: Timer + Auto-kick
- [ ] Task 9: Chat + Reactions
- [ ] Task 10: Scoreboard + Download
- [ ] Task 11: Lobby Polish
- [ ] Task 12: Production Deployment
