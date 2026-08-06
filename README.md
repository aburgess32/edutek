# EduTek Global — EduPak Application

[![EduPak CI](https://github.com/edutek-global/edupak/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/edutek-global/edupak/actions/workflows/ci.yml)
[![PHP 8.1](https://img.shields.io/badge/PHP-8.1-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL 8.0](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

## Overview

Web application for the EduPak offline education server. Serves learning content to 60+ devices over local WiFi with zero internet dependency. The app is designed for low-spec hardware, intermittent power, and fully offline operation, with a large local content library (up to 4TB).

## Stack

- **Server (Dev):** Apache in Docker (Docker Desktop + Compose)
- **Server (Prod):** Apache via XAMPP on EduPak devices
- **Backend:** PHP
- **Database:** MySQL 8.0
- **Frontend:** HTML/CSS/JS (vanilla — no heavy frameworks)
- **Deployment:** Solar-powered EduPak device, offline content library

## Project Structure

```text
edutek/
├── .github/workflows/   # CI/CD — GitHub Actions
│   └── ci.yml           # Lint → Test → Lighthouse pipeline
├── config/
│   └── apache/          # Apache virtual host config (EduPak vhost)
├── db/
│   └── schema.sql       # Database schema (auto-imported in Docker, imported in XAMPP)
├── dist/                # Built deployment archives (gitignored)
├── docker/
│   └── Dockerfile       # PHP 8.1 + Apache + extensions
├── docs/                # Sprint specs & architecture docs
├── htdocs/              # Apache document root (the web app)
│   ├── api/             # Internal API endpoints
│   ├── css/             # Stylesheets
│   ├── img/             # UI images (tiles, icons, avatars)
│   │   ├── tiles/       # Home screen tile images
│   │   ├── icons/       # Navigation & UI icons
│   │   └── avatars/     # User profile avatars (Simple Name Login)
│   ├── includes/        # PHP includes (header, footer, db config, helpers)
│   ├── js/              # Client-side JavaScript
│   └── index.php        # Main entry point
├── logs/                # Application and web server logs
├── reports/             # Test reports, Lighthouse reports (local dev)
├── scripts/
│   ├── deploy.sh              # Package app for EduPak deployment
│   └── deploy-to-device.sh    # Push archive to physical EduPak device (USB/SSH/network)
├── tests/               # PHPUnit test suite, Playwright e2e tests
├── vendor/              # Composer dependencies
├── .dockerignore        # Docker build exclusions
├── docker-compose.yml   # Local dev sandbox (Docker Desktop)
├── .env.example         # Environment variable template
└── README.md
```

## Target Devices

- Low-spec Android tablets 
- Feature phones with browsers
- Shared screens / projectors


---

## Local Development Setup (Windows + Docker on D:)

These instructions assume a **Windows 10/11 PC** with Docker Desktop installed and the EduPak content library located on the **D: drive**.

### Prerequisites

- Windows 10/11
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (includes Docker Engine + Compose v2)
- Git
- Offline content library at:

  ```text
  D:\xampp\htdocs\Edutek\videos
  ```

  This folder should contain the course/video subdirectories (e.g. Accounting and Bookkeeping, Adobe Photoshop, Adobe XD, Audiobooks, etc.). This path is bind-mounted into the app container as `/content`. [2]

### Clone the repository to D:

Open **PowerShell** and run:

```powershell
cd D:\
git clone https://github.com/aburgess32/edutek.git
cd D:\edutek
```

`D:\edutek` is the working project root for all development and Docker operations.

### Create your `.env` file

The repository includes `.env.example`, which documents all available configuration variables (DB connection, logging, content path, cipher key/IV, teacher passphrase). [3]

Create a local `.env` from the template:

```powershell
cd D:\edutek
Copy-Item .env.example .env
```

You can edit `.env` to adjust `APP_ENV`, `APP_DEBUG`, logging level, teacher passphrase, etc. The Docker Compose file injects DB connection values for the app via environment variables: [2]

```text
DB_HOST=db
DB_PORT=3306
DB_NAME=edupak
DB_USER=edupak
DB_PASS=edupak_dev
CONTENT_PATH=/content/
```

### Verify the content library path

The Docker stack bind-mounts the offline content library from:

```text
D:\xampp\htdocs\Edutek\videos
```

Verify this path exists and contains your video/course directories:

```powershell
Test-Path "D:\xampp\htdocs\Edutek\videos"
ls "D:\xampp\htdocs\Edutek\videos"
```

If you move the library to a different location on `D:`, update the volume mapping in `docker-compose.yml`:

```yaml
- D:/new/path/to/videos:/content
```

Note the use of **forward slashes** for Windows paths inside Docker Compose. [2]

---

## Running the EduPak Stack with Docker Compose (Windows Dev)

The `docker-compose.yml` file defines a three-service local stack for development: [2]

- `app` (`edupak-app`): Apache + PHP 8.1 (EduPak web app)
- `db` (`edupak-db`): MySQL 8.0
- `phpmyadmin` (`edupak-phpmyadmin`): Database admin UI

### Start the stack

From `D:\edutek` in PowerShell:

```powershell
cd D:\edutek
docker compose up -d --build
docker compose ps
```

On success, you should see something like:

```text
NAME                IMAGE               STATUS                        PORTS
edupak-app          edutek-app          Up                            0.0.0.0:8080->80/tcp
edupak-db           mysql:8.0           Up (healthy)                  0.0.0.0:3307->3306/tcp
edupak-phpmyadmin   phpmyadmin:latest   Up                            0.0.0.0:8081->80/tcp
```

### Access the app and database

- EduPak web app: `http://localhost:8080`
- phpMyAdmin: `http://localhost:8081` [2]

Default phpMyAdmin credentials (from `docker-compose.yml`): [2]

- Server: `db`
- Username: `edupak`
- Password: `edupak_dev`

### Stop and restart services

Stop the stack and remove containers:

```powershell
cd D:\edutek
docker compose down
```

Restart the app after code changes:

```powershell
cd D:\edutek
docker compose restart app
```

Most PHP and asset changes under `D:\edutek\htdocs` take effect immediately because `htdocs` is bind-mounted into `/var/www/html` in the app container. [2]

### Useful Docker commands

```powershell
# View app logs
cd D:\edutek
docker compose logs -f app

# View database logs
docker compose logs -f db

# Wipe database volume and start fresh
docker compose down -v
docker compose up -d --build
```

---

## Troubleshooting (Windows + Docker)

### Container name `edupak-db` already in use

If `docker compose up` reports:

```text
Error response from daemon: Conflict. The container name "/edupak-db" is already in use…
You have to remove (or rename) that container to be able to reuse that name.
```

Remove the old containers and restart:

```powershell
docker rm -f edupak-db edupak-app edupak-phpmyadmin
cd D:\edutek
docker compose up -d --build
```

Docker requires unique container names; removing old containers frees the names for the current stack. [87][89]

### No containers listed for this project

If `docker compose ps` shows an empty table for this project:

```text
NAME  IMAGE  COMMAND  SERVICE  CREATED  STATUS  PORTS
```

Start the stack:

```powershell
cd D:\edutek
docker compose up -d
docker compose ps
```

### App or DB connection issues

Check logs:

```powershell
cd D:\edutek
docker compose logs app
docker compose logs db
```

Fix reported PHP/DB issues, then restart:

```powershell
docker compose restart app
```

### Content library not visible in the app

Confirm the source path still exists and is correctly mapped:

```powershell
Test-Path "D:\xampp\htdocs\Edutek\videos"
ls "D:\xampp\htdocs\Edutek\videos"
```

If the path changes on D:, update the `D:/...:/content` volume mapping in `docker-compose.yml` to match the new location. [2]

---

## Deployment Pipeline: Docker Dev → XAMPP Production

This section explains how to move from a **Docker Desktop development build on a Windows PC (D: drive)** to a **XAMPP-based production deployment on an EduPak device**.

### 1. Develop and verify using Docker Desktop (Windows PC)

On your development machine (Windows):

1. Follow the **Local Development Setup (Windows + Docker on D:)** instructions above.
2. Confirm the local Docker stack runs successfully:

   ```powershell
   cd D:\edutek
   docker compose up -d --build
   docker compose ps
   ```

3. Test locally:

   - EduPak app: `http://localhost:8080`
   - phpMyAdmin: `http://localhost:8081`

   Confirm that:
   - Home tiles, login, content browsing work as expected.
   - Database interactions and progress tracking work.

Once the app behaves correctly under Docker on your PC, you’re ready to package and deploy to an EduPak device.

### 2. Build a deployment archive from the Docker dev workspace

From the same `D:\edutek` workspace, run the deployment script to create an archive of the app code and related assets:

```bash
./scripts/deploy.sh
# Output: dist/edupak-YYYYMMDD-HHMMSS.tar.gz
```

This script packages:

- `htdocs/` — main web application code.
- `config/apache/` — Apache virtual host configuration.
- `db/schema.sql` and any other required DB artifacts.
- Any additional files required by the EduPak runtime.

The resulting `.tar.gz` archive in `dist/` is what you transfer to the EduPak device for XAMPP-based deployment.

### 3. Transfer the archive to the EduPak device

There are two main options:

#### Option A — Network (SSH/FTP) transfer

If the EduPak device is reachable on the local network:

```bash
./scripts/deploy-to-device.sh dist/edupak-YYYYMMDD-HHMMSS.tar.gz 192.168.1.100
```

- `192.168.1.100` is the EduPak device’s IP address.
- `deploy-to-device.sh` handles copying the archive and placing it in the correct directory on the device.

See `scripts/deploy-to-device.sh` for detailed options and manual USB/SSH transfer instructions.

#### Option B — USB/manual copy

If you cannot reach the device over the network:

1. Copy `dist/edupak-YYYYMMDD-HHMMSS.tar.gz` to a USB drive.
2. Plug the USB into the EduPak device.
3. Manually copy the archive to a suitable folder (e.g. `/home/edupak/deploy`).
4. SSH into the device and extract the archive (see next step).

### 4. Install into XAMPP on the EduPak device

On the **EduPak device** (production), XAMPP is used as the runtime stack (Apache + MySQL). Docker Desktop is **not** required on the device.

1. **Extract the archive** on the device:

   ```bash
   cd /path/to/deploy-folder
   tar -xzf edupak-YYYYMMDD-HHMMSS.tar.gz
   # This should produce an edutek/ folder structure similar to the repo
   ```

2. **Copy the web app into XAMPP’s htdocs**:

   - Locate XAMPP’s `htdocs` directory on the device (for example `C:\xampp\htdocs` on Windows or `/opt/lampp/htdocs` on Linux).
   - Copy the contents of the extracted `htdocs/` into the XAMPP htdocs directory, e.g.:

     ```bash
     # Example on a Linux-based EduPak device:
     cp -r edutek/htdocs/* /opt/lampp/htdocs/edutek/
     ```

     Adjust paths as needed for the device’s XAMPP installation.

3. **Import the database schema into XAMPP MySQL**:

   - Use phpMyAdmin or the `mysql` CLI to import `db/schema.sql`:

     ```bash
     mysql -u root -p edupak < edutek/db/schema.sql
     ```

     Or:

     - Open phpMyAdmin on the device (typically `http://localhost/phpmyadmin`).
     - Create a database named `edupak`.
     - Import `edutek/db/schema.sql` into that database.

4. **Configure database credentials in the app**:

   - Edit `htdocs/includes/config.php` (on the device’s XAMPP htdocs) to match the XAMPP MySQL credentials:

     ```php
     // Example: adjust host, user, password, db
     $db_host = 'localhost';
     $db_port = 3306;
     $db_name = 'edupak';
     $db_user = 'root';           // or a non-root user if configured
     $db_pass = '';               // set to the actual password
     ```

   - Ensure these values match the actual XAMPP MySQL configuration on the EduPak device.

5. **Copy Apache virtual host config**:

   - Copy `config/apache/edupak.conf` from the extracted archive into XAMPP’s Apache vhost directory.

     For example (Linux-based XAMPP):

     ```bash
     cp edutek/config/apache/edupak.conf /opt/lampp/apache/conf/extra/edupak.conf
     ```

   - Include this vhost file from the main Apache config (e.g., in `httpd.conf`):

     ```apache
     Include conf/extra/edupak.conf
     ```

   - Ensure the vhost points to the correct document root (e.g. `/opt/lampp/htdocs/edutek`).

6. **Restart XAMPP services**:

   - Use the XAMPP control panel or CLI to restart Apache and MySQL:

     ```bash
     /opt/lampp/lampp restart
     ```

   - On Windows, use the XAMPP Control Panel to stop and start Apache and MySQL.

### 5. Verify the production deployment on the EduPak device

On the EduPak device (or a client connected to its local network):

1. Open the configured EduPak URL (e.g. `http://edupak.local/` or `http://192.168.1.100/edutek`), depending on the vhost configuration.
2. Confirm:
   - Home tiles load correctly.
   - Simple Name Login works.
   - Content browsing and video playback work using the device’s offline content library (4TB drive).
   - Progress tracking and database writes succeed.

This completes the pipeline:

- **Dev environment** → Docker Desktop + Compose on your Windows PC (`D:\edutek`).
- **Production environment** → XAMPP (Apache + MySQL) on EduPak devices, using the deployment archive produced from the dev workspace.

---

## Git Workflow

All changes for the EduPak Windows/Docker setup should be made in the local clone at `D:\edutek`:

```powershell
cd D:\edutek
git pull origin master
# make changes
git add .
git commit -m "Describe your change"
git push origin master
```

On macOS or Linux, contributors can edit and commit code and documentation (including this README) while still targeting the Windows runtime environment. The repo’s CI (GitHub Actions) continues to lint, test, and run Lighthouse audits on each push.

---

## Team

- **Lyndon Jones** — Original developer, Africa Dev Ops
- **Alexander Burgess** — Lead developer (current)
- **Anne Prinzhorn** — Executive Director