# EduPak Device Testing Matrix

> Based on 2025-2026 African smartphone market data from [StatCounter](https://gs.statcounter.com/vendor-market-share/mobile/africa), [Omdia](https://omdia.tech.informa.com/), and [GSMArena](https://www.gsmarena.com/).

## Market Context

- **84.4 million** smartphones shipped in Africa in 2025 (+13% YoY)
- **Transsion** (Tecno, Infinix, Itel) holds **48% market share** — 40.5M units
- **Samsung** at **~28%** via affordable Galaxy A/M series
- **Sub-$100 devices** account for the vast majority of volume
- Average selling price in sub-Saharan Africa: **~$120**
- Uganda, Kenya, Zimbabwe (EduPak deployment countries) are dominated by T1/T2 devices

## Device Profiles

### Tier 1 — Ultra-Budget (<$50) 🔴 CRITICAL
> This is where most EduPak users live. If it doesn't work on T1, it doesn't ship.

| Profile | Brand | Model | Screen | Resolution | RAM | CPU | OS | Price |
|---------|-------|-------|--------|------------|-----|-----|-----|-------|
| `t1-itel-a60` | Itel | **A60** ⭐ BASELINE | 6.6" IPS LCD 60Hz | 720×1612 | **2GB** | Spreadtrum SC9832E | Android 11 | <$40 |
| `t1-itel-a70` | Itel | A70 | 6.6" IPS LCD 120Hz | 720×1612 | 4GB | Unisoc T603 | Android 13 Go | <$50 |
| `t1-tecno-pop8` | Tecno | Pop 8 | 6.6" IPS LCD 90Hz | 720×1612 | 4GB | Unisoc T606 | Android 13 Go | <$50 |

**Known constraints:**
- Itel A60 has only **2GB RAM** — browser gets ~300-400MB max
- Android Go edition restricts background processes aggressively
- WebView may be Chrome 95-100 (old, but supports ES6)
- eMMC 5.1 storage = slow disk I/O

### Tier 2 — Budget ($50-$100) 🟡
> High-volume segment. Samsung Galaxy A-series and Infinix/Xiaomi budget kings.

| Profile | Brand | Model | Screen | Resolution | RAM | CPU | OS | Price |
|---------|-------|-------|--------|------------|-----|-----|-----|-------|
| `t2-samsung-a06` | Samsung | Galaxy A06 | 6.7" PLS LCD 60Hz | 720×1600 | 4-6GB | Helio G85 | Android 14 | $60-80 |
| `t2-infinix-smart9` | Infinix | Smart 9 | 6.7" IPS LCD 120Hz | 720×1600 | 4GB | Helio G81 | Android 14 | $60-70 |
| `t2-xiaomi-redmi-a5` | Xiaomi | Redmi A5 | 6.88" IPS LCD 120Hz | 720×1640 | 4GB | Unisoc T615 | Android 15 | $70-90 |
| `t2-tecno-spark10` | Tecno | Spark 10 | 6.6" IPS LCD 90Hz | 720×1612 | 8GB | Helio G37 | Android 13 | $80-100 |

**Notes:**
- All HD+ (720p) — optimize images for 720px width
- 4GB RAM is the sweet spot — browser gets ~1GB
- Most popular segment in Kenya, Uganda, Nigeria

### Tier 3 — Lower-Mid ($100-$200) 🟢
> Aspirational purchases. Teachers and community leaders more likely to have these.

| Profile | Brand | Model | Screen | Resolution | RAM | CPU | OS | Price |
|---------|-------|-------|--------|------------|-----|-----|-----|-------|
| `t3-samsung-a16-5g` | Samsung | Galaxy A16 5G | 6.7" AMOLED 90Hz | **1080×2340** | 4-8GB | Dimensity 6300 | Android 14 | $130-170 |
| `t3-tecno-spark20` | Tecno | Spark 20 | 6.56" IPS LCD 90Hz | 720×1612 | 8GB | Helio G85 | Android 13 | $100-130 |
| `t3-samsung-a05` | Samsung | Galaxy A05 | 6.7" PLS LCD 60Hz | 720×1600 | 4GB | Helio G85 | Android 13 | $100-120 |

**Notes:**
- Samsung A16 is the only FHD+ device — consider serving higher-res images to this tier
- Good Chrome versions (112+), modern CSS/JS support

### Tier 4 — Tablets (Donated/School) 📱
> Donated classroom tablets. Often old hardware with outdated Android.

| Profile | Brand | Model | Screen | Resolution | RAM | OS |
|---------|-------|-------|--------|------------|-----|-----|
| `t4-tablet-7inch` | Generic | 7" Donated | 7" | 600×1024 | 1-2GB | Android 8-10 |
| `t4-tablet-10inch` | Generic | 10" Classroom | 10.1" | 800×1280 | 2-4GB | Android 11-13 |

**Known issues:**
- Old Android means old WebView — test for ES5 fallback
- 7" tablet with 1GB RAM = aggressive memory limits
- Often in landscape orientation for classroom use

### Tier 5 — Projectors & Shared Screens 🖥️
> Teacher-facing classroom displays. No touch input.

| Profile | Model | Resolution | Input | Notes |
|---------|-------|------------|-------|-------|
| `t5-projector-720p` | Projector 720p | 1280×720 | Keyboard/mouse | Most common projector |
| `t5-projector-1080p` | Projector 1080p | 1920×1080 | Keyboard/mouse | Best-case scenario |
| `t5-tv-768p` | Cheap TV | 1366×768 | Remote/basic | Common cheap smart TV |
| `t5-rpi-kiosk` | Raspberry Pi Kiosk | 1024×768 | Keyboard/mouse | Limited GPU, old monitors |

**Requirements:**
- Large text (24px+ minimum)
- High contrast (black bg in projector mode)
- Keyboard navigable (Tab, Enter, arrows)
- No touch-dependent interactions

### Tier 6 — Feature Phones 📟
> Edge case but real in rural areas. KaiOS devices.

| Profile | Model | Resolution | Input | Notes |
|---------|-------|------------|-------|-------|
| `t6-kaios-feature-phone` | KaiOS Phone | 240×320 | D-pad only | Extremely limited browser |

**Requirements:**
- Basic HTML must render (no JS dependency)
- D-pad navigation (no touch events)
- 240px width — linear single-column layout only
- This is a "best effort" tier — full functionality not expected

## Performance Budgets by Tier

| Tier | Page Load | Total Transfer | JS Bundle | CSS | Images |
|------|-----------|---------------|-----------|-----|--------|
| T1 Ultra-Budget | <3s | <300KB | <30KB | <15KB | WebP <50KB each |
| T2 Budget | <2s | <500KB | <50KB | <20KB | WebP <80KB each |
| T3 Lower-Mid | <1.5s | <500KB | <50KB | <20KB | WebP <100KB each |
| T4 Tablet | <2.5s | <500KB | <50KB | <20KB | WebP <80KB each |
| T5 Projector | <1s | <500KB | <50KB | <20KB | WebP <100KB each |
| T6 Feature Phone | <5s | <100KB | 0KB (no JS) | <10KB | JPG <30KB each |

## Testing Priority Order

When time is limited, test in this order:

1. **`t1-itel-a60`** — THE baseline. Fix all issues here first.
2. **`t5-projector-720p`** — Teacher experience is critical for adoption.
3. **`t2-samsung-a06`** — Most popular branded phone in Africa.
4. **`t4-tablet-7inch`** — Worst-case tablet scenario.
5. **`t1-tecno-pop8`** — Transsion is 48% of the market.
6. Everything else.

## Quick Test Commands

```bash
# Baseline device only (fastest feedback loop)
./scripts/test-devices.sh baseline

# All ultra-budget phones (most important tier)
./scripts/test-devices.sh tier1

# All mobile devices
./scripts/test-devices.sh mobile

# Screens and projectors
./scripts/test-devices.sh screens

# Full matrix (all 17 profiles)
./scripts/test-devices.sh all

# Visual inspection mode
./scripts/test-devices.sh baseline --headed
```

## Known Browser Quirks by Device

| Device/Browser | Quirk | Workaround |
|---------------|-------|------------|
| Android Go WebView | Aggressive memory management, kills tabs | Keep DOM lightweight, <500 nodes |
| Older Chrome (<100) | No `aspect-ratio` CSS | Use padding-top hack for aspect ratios |
| KaiOS Firefox 48 | No CSS Grid, no Flexbox gap | Float-based fallback layout |
| Smart TV browsers | No `position: sticky` | Static fallback for nav |
| Raspberry Pi Chromium | Slow GPU compositing | Avoid `transform`, `filter`, heavy shadows |
| Itel/Tecno WebView | Sometimes reflows on scroll | Avoid `resize` event listeners |

## Network Conditions

EduPak serves content over local WiFi from the device itself. No internet involved.

| Condition | Download | Upload | Latency | Scenario |
|-----------|----------|--------|---------|----------|
| `local-wifi-fast` | 50 Mbps | 20 Mbps | 5ms | Same room, few devices |
| `local-wifi-weak` | 5 Mbps | 2 Mbps | 50ms | Through walls / far away |
| `local-wifi-saturated` | 1 Mbps | 500 Kbps | 200ms | 60 devices sharing one EduPak |

T1 devices default to `local-wifi-saturated` in tests (worst case).
All other tiers default to `local-wifi-fast`.
