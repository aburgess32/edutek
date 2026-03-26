# EduTek Global — EduPak Application

[![EduPak CI](https://github.com/edutek-global/edupak/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/edutek-global/edupak/actions/workflows/ci.yml)
[![PHP 8.1](https://img.shields.io/badge/PHP-8.1-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL 8.0](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

## Overview
Web application for the EduPak offline education server. Serves learning content to 60+ devices over local WiFi with zero internet dependency.

## Stack
- **Server:** Apache (XAMPP)
- **Backend:** PHP
- **Database:** MySQL
- **Frontend:** HTML/CSS/JS (vanilla — no heavy frameworks)
- **Deployment:** Solar-powered EduPak device, 4TB content library

## Project Structure
```
edutek/
├── htdocs/              # Apache document root (the web app)
│   ├── css/             # Stylesheets
│   ├── js/              # Client-side JavaScript
│   ├── img/             # UI images (tiles, icons, avatars)
│   │   ├── tiles/       # Home screen tile images
│   │   ├── icons/       # Navigation & UI icons
│   │   └── avatars/     # User profile avatars (Simple Name Login)
│   ├── includes/        # PHP includes (header, footer, db connection)
│   ├── api/             # Internal API endpoints
│   └── index.php        # Main entry point
├── db/                  # Database schemas & migrations
├── config/              # Server configuration files
├── docs/                # Sprint specs & architecture docs
└── tests/               # Test files
```

## Target Devices
- Low-spec Android tablets (1-2GB RAM)
- Feature phones with browsers
- Shared screens / projectors
- Raspberry Pi kiosks

## Development Priorities (Lyndon-approved)
1. Visual Home Tiles (Kid/Teen/Adult/Teacher)
2. Continue Watching Row
3. Simple Name Login (A-Z, no password)
4. Breadcrumb Path
5. Teacher Content Finder
6. Mode Switch (kid/group/class/projector)

## Project Structure
```
edutek/
├── .github/workflows/   # CI/CD — GitHub Actions
│   └── ci.yml           # Lint → Test → Lighthouse pipeline
├── config/
│   └── apache/          # Apache virtual host config
├── db/
│   └── schema.sql       # Database schema (auto-imported in Docker)
├── dist/                # Built archives (gitignored)
├── docker/
│   └── Dockerfile       # PHP 8.1 + Apache + extensions
├── docs/                # Sprint specs & architecture docs
├── htdocs/              # Apache document root (the web app)
│   ├── api/             # Internal API endpoints
│   ├── css/             # Stylesheets
│   ├── img/             # UI images (tiles, icons, avatars)
│   ├── includes/        # PHP includes (header, footer, db config)
│   ├── js/              # Client-side JavaScript
│   └── index.php        # Main entry point
├── scripts/
│   ├── deploy.sh              # Package app for EduPak deployment
│   └── deploy-to-device.sh    # Push archive to physical EduPak device
├── tests/               # PHPUnit test suite
├── .dockerignore        # Docker build exclusions
├── docker-compose.yml   # Local dev sandbox
└── README.md
```

## Setup — Docker (Recommended for Development)

### Prerequisites
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) or Docker Engine + Compose v2

### Start the sandbox
```bash
docker compose up -d
```

This starts three services:
| Service | URL | Purpose |
|---|---|---|
| PHP + Apache | http://localhost:8080 | The EduPak web app |
| phpMyAdmin | http://localhost:8081 | Database admin UI |
| MySQL 8.0 | localhost:3306 | Database (auto-imports `db/schema.sql`) |

```bash
# View logs
docker compose logs -f app

# Stop everything
docker compose down

# Wipe database and start fresh
docker compose down -v && docker compose up -d
```

## Setup — XAMPP (Production / EduPak Device)
1. Install XAMPP
2. Clone this repo
3. Copy `htdocs/` contents to XAMPP's htdocs directory
4. Import `db/schema.sql` into MySQL
5. Update `htdocs/includes/config.php` with local credentials
6. Copy `config/apache/edupak.conf` to XAMPP vhosts config
7. Start Apache + MySQL via XAMPP control panel

## Deployment

### Build a deployment archive
```bash
./scripts/deploy.sh
# Output: dist/edupak-YYYYMMDD-HHMMSS.tar.gz
```

### Push to a physical EduPak device
```bash
./scripts/deploy-to-device.sh dist/edupak-YYYYMMDD-HHMMSS.tar.gz 192.168.1.100
```

See `scripts/deploy-to-device.sh` for full documentation of the manual USB/SSH transfer process.

## Team
- **Lyndon Jones** — Original developer, Africa Dev Ops
- **Alexander Burgess** — Lead developer (current)
- **Anne Prinzhorn** — Executive Director
