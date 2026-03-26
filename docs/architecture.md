# EduPak System Architecture

## Overview
EduPak is a palm-sized, solar-powered offline server that creates a local WiFi hotspot. Devices connect to this hotspot and access a web application served by Apache.

## Stack
```
┌─────────────────────────────────────────────┐
│  Client Devices (60+)                        │
│  Android tablets, phones, Chromium kiosks,   │
│  projectors, shared screens                  │
│  ↕ Local WiFi (no internet)                  │
├─────────────────────────────────────────────┤
│  EduPak Hardware                             │
│  ┌─────────┐  ┌───────┐  ┌──────────────┐  │
│  │ Apache   │→│  PHP   │→│   MySQL      │  │
│  │ (XAMPP)  │  │ 7.x+  │  │  (MariaDB)  │  │
│  └─────────┘  └───────┘  └──────────────┘  │
│       ↕                                      │
│  ┌──────────────────────────────────────┐   │
│  │  4TB Content Library                  │   │
│  │  Khan Academy, Wikipedia, eBooks,     │   │
│  │  150k+ videos, vocational training,   │   │
│  │  health resources, AI tools           │   │
│  └──────────────────────────────────────┘   │
│       ↕                                      │
│  Solar Power + Battery                       │
└─────────────────────────────────────────────┘
```

## Key Constraints
| Constraint | Detail |
|-----------|--------|
| **No internet** | Everything served locally. No CDN, no external API calls. |
| **Low-spec devices** | 1-2GB RAM Android tablets, feature phones, Raspberry Pi |
| **Power** | Solar + battery. Server may restart unexpectedly. |
| **Storage** | 4TB total. RAM costs doubled — must minimize bloat. |
| **Environment** | Blazing heat, torrential rain, temperamental power grids |
| **Users** | Low-literacy learners (icons > text), teachers, kids through adults |
| **Updates** | No OTA. Currently manual via site visits. ~20 deployed units. |

## Performance Budget
| Metric | Target | Rationale |
|--------|--------|-----------|
| First paint | <1s | Low-spec device, local network latency is negligible |
| Full page load | <2s | Including images, thumbnails |
| JS bundle | <50KB gzipped | Feature phones choke on large bundles |
| CSS | <20KB gzipped | Single stylesheet, no framework |
| Image tiles | WebP <100KB each | JPG fallback for old WebViews |
| Search response | <1s | Pre-built JSON index, not live DB query |

## Development Principles
1. **Progressive enhancement** — HTML-first, CSS for layout, JS only for interactivity
2. **No frameworks** — Vanilla HTML/CSS/JS. No React, no jQuery, no Bootstrap.
3. **Server-rendered** — PHP generates HTML. Minimal client-side rendering.
4. **Offline-first** — No external dependencies. All assets local.
5. **Resilient** — Graceful degradation if JS fails, images missing, or power cuts mid-session.

## Database
- MySQL/MariaDB via XAMPP
- phpMyAdmin available for admin access
- Key tables: `users`, `watch_history`, `lesson_plans`, `content_meta` (TBD)
- See `db/schema.sql` for current schema

## Shared Infrastructure (build first)
These components are referenced across multiple feature specs:
- **`tiles.json`** — Configuration file for home tile layout (P1, P6)
- **`init.php`** — Session + mode resolver, loaded on every page (P3, P6)
- **`breadcrumb.php`** — Server-side breadcrumb builder (P4)
- **`content_meta` table** — Content metadata for search index (P5)

## Deployment Notes
- **[UNKNOWN]** Exact EduPak hardware specs (CPU, RAM)
- **[UNKNOWN]** Apache/PHP version on deployed units
- **[UNKNOWN]** How content is organized on the 4TB drive (file structure)
- **[UNKNOWN]** Existing MySQL database schema (if any)
- **[ASSUMPTION]** XAMPP default ports (Apache :80, MySQL :3306)
- **[ASSUMPTION]** htdocs is the web root

## File Structure (repo)
```
edutek/
├── htdocs/              # Web root (Lyndon's code goes here)
│   ├── css/
│   ├── js/
│   ├── img/tiles/icons/avatars/
│   ├── includes/        # PHP includes (config, header, footer, init)
│   ├── api/             # Internal AJAX endpoints
│   └── index.php
├── db/                  # Schema & migrations
├── config/              # Server config templates
├── docs/                # Sprint specs (this folder)
└── tests/
```
