# 🃏 Catte Online

Game đánh bài **Cát Tê** online realtime, hỗ trợ 2-6 người chơi.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | Vue 3 + Inertia.js + Tailwind CSS 4 |
| Backend | Laravel 13 + PHP 8.4 |
| Realtime | Pusher (WebSocket) + Laravel Echo |
| Database | SQLite (dev) / MySQL (prod) |
| Animations | GSAP |
| Sound | Howler.js |
| Hosting | Shared hosting (Hostinger) |

## Features

- 🎮 **2-6 người chơi** realtime trong cùng phòng
- 🏠 **Hệ thống phòng** — Lobby công khai + phòng riêng bằng mã 6 ký tự
- 👤 **Guest system** — Không cần đăng ký, chỉ nhập tên vào chơi
- 🔄 **Reconnect** — Cookie-based recovery khi mất kết nối
- 🃏 **Luật Cát Tê đầy đủ** — 6 vòng trick-taking, thắng trắng, gục tùng, thắng tùng, chưng
- ⏱️ **Timer 30s/lượt** — Auto-kick sau 2 lần timeout liên tiếp
- 💬 **Chat + Reactions** — Text chat + emoji nhanh (👍😂😡😢🎉🤔)
- 📊 **Bảng điểm** — Live scoreboard, download CSV/PNG
- 🎴 **Luật thối Ách** — Toggle on/off bởi chủ phòng
- 🎨 **Card UI** — CSS playing cards với animations

## Luật chơi

### Thắng trắng (Instant Win)
Kiểm tra ngay sau chia bài:
1. **Tứ quý** — 4 lá cùng rank
2. **6 lá cùng chất** — Flush 6
3. **6 lá nhỏ hơn 6** — Tất cả rank ≤ 5

### Vòng 1-4: Trick-taking
- Người dẫn đánh 1 lá ngửa (lead card)
- Người tiếp theo: **đánh ngửa** (cùng chất + lớn hơn) hoặc **thiệp** (úp bất kỳ)
- Lá ngửa lớn nhất cùng lead suit thắng → có "tồn"

### Sau vòng 4
- **Gục tùng**: 0 tồn → bị loại (-1 điểm)
- **Thắng tùng**: Chỉ 1 người có tồn → thắng (+2 điểm)
- Còn ≥2 người → vào **Chưng** (vòng 5-6)

### Vòng 6: Quyết định
- Người thắng vòng 6 = thắng ván (+1 điểm)

### Thối Ách
- Khi bật: -1 mỗi Ace còn trên tay khi gục tùng

## Setup Development

### Prerequisites
- PHP 8.3+
- Composer
- Node.js 18+
- NPM

### Installation

```bash
# Clone & install
git clone <repo-url> catte
cd catte
composer install
npm install

# Environment
cp .env.example .env
php artisan key:generate

# Database
php artisan migrate

# Run
composer dev
```

Mở http://localhost:8000

### Pusher Setup

1. Tạo app tại [pusher.com](https://pusher.com)
2. Copy keys vào `.env`:

```env
PUSHER_APP_ID=your-app-id
PUSHER_APP_KEY=your-app-key
PUSHER_APP_SECRET=your-app-secret
PUSHER_APP_CLUSTER=ap1
```

## Production Deployment (Hostinger)

```bash
# Build assets
npm run build

# Optimize Laravel
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Migrate
php artisan migrate --force
```

### Cron (cPanel)
```
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

### .env Production
```env
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=sync
SESSION_DRIVER=database
BROADCAST_CONNECTION=pusher
```

## Project Structure

```
app/
├── Events/              # 14 broadcast events
├── Http/
│   ├── Controllers/     # Guest, Room, Game controllers
│   └── Middleware/      # EnsureGuestSession, HandleInertiaRequests
├── Models/              # Room, Player, Score, Game, Round, Play
└── Services/
    └── CatteGameEngine.php   # Core game logic (545 lines)

resources/js/
├── Components/          # Card, CardHand, PlayerSeat, GameTable,
│                        # CountdownTimer, ChatBox, Scoreboard
├── Pages/               # Home, Lobby, Room
└── Services/
    └── SoundManager.js  # Howler.js wrapper
```

## Testing

```bash
php artisan test
```

22 tests, 49 assertions covering:
- Card deck building & dealing
- Instant win detection (tứ quý, flush 6, low 6)
- Trick-taking validation & round evaluation
- Post-round-4 logic (gục tùng, thắng tùng)
- Scoring (thối Ách, tùng, normal win)
- Auto-play (timeout card selection)

## License

MIT
