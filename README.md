# EduTek Global — EduPak Application

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

## Setup
1. Install XAMPP
2. Clone this repo
3. Copy `htdocs/` contents to XAMPP's htdocs directory
4. Import `db/schema.sql` into MySQL
5. Update `config/database.php` with local credentials
6. Start Apache + MySQL via XAMPP control panel

## Team
- **Lyndon Jones** — Original developer, Africa Dev Ops
- **Alexander Burgess** — Lead developer (current)
- **Anne Prinzhorn** — Executive Director
