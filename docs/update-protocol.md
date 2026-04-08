# EduPak Update Protocol — Operator Run-Book

Step-by-step guide for building, deploying, and rolling back updates across the EduPak device fleet. Every step is a copy-paste command.

> **Audience:** Device operators. No programming experience required.
>
> **Last updated:** 2026-04-05

---

## Prerequisites

Before you begin, make sure you have:

- **Windows laptop or PC** with:
  - [Git](https://git-scm.com/download/win) installed
  - PowerShell 5.1+ (built into Windows 10/11)
  - Internet access (for building the update archive)
- **USB drive** (8 GB or larger) for transferring updates to devices
- **Physical or network access** to the target EduPak device

---

## Part 1: Build the Update Archive (Operator Laptop)

This part runs on your laptop — not on the EduPak device.

### Step 1: Clone or pull the latest code

If this is your **first time**, clone the repository:

```powershell
git clone https://github.com/aburgess32/edutek.git
cd edutek
```

If you already have the repo, pull the latest:

```powershell
cd edutek
git pull origin master
```

### Step 2: Build the archive

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\scripts\edupak-build.ps1
```

This creates a file like `dist\edupak-update-20260405-134500.zip`.

**Options:**

| Flag | Purpose |
|------|---------|
| `-SkipPull` | Don't run `git pull` (use code as-is) |
| `-OutputDir "E:\updates"` | Save the archive to a different folder |

### Step 3: Copy to USB

```powershell
Copy-Item "dist\edupak-update-*.zip" -Destination "E:\" -Force
```

Replace `E:\` with your USB drive letter.

Also copy the update and diagnostic scripts to the USB:

```powershell
Copy-Item "scripts\edupak-update.ps1" -Destination "E:\" -Force
Copy-Item "scripts\edupak-rollback.ps1" -Destination "E:\" -Force
Copy-Item "scripts\edupak-diagnose.ps1" -Destination "E:\" -Force
```

---

## Part 2: First-Time Migration (BLink to EduPak)

> **Only needed once per device.** This replaces the legacy BLink software with the new EduPak codebase. After this, use Part 3 for all future updates.

### Step 1: Audit the device

Plug in the USB and open PowerShell on the EduPak device. Run the audit script to understand the device's current state:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
E:\device-audit-template.ps1
```

> If the audit script is not on the USB, copy it from `docs\device-audit-template.ps1` in the repo.

Review the output. Check:
- XAMPP is installed at `D:\xampp`
- MariaDB CLI is WORKING (if not, the update script will repair it automatically)
- Content directories (videos, khan, Wiki, Kiwix) are present with expected file counts

### Step 2: Copy files to device

Copy the archive and scripts from USB to the device:

```powershell
Copy-Item "E:\edupak-update-*.zip" -Destination "D:\" -Force
Copy-Item "E:\edupak-update.ps1" -Destination "D:\" -Force
Copy-Item "E:\edupak-rollback.ps1" -Destination "D:\" -Force
Copy-Item "E:\edupak-diagnose.ps1" -Destination "D:\" -Force
```

### Step 3: Run the migration

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
D:\edupak-update.ps1 -Archive "D:\edupak-update-20260405-134500.zip" -FirstRun
```

Replace the filename with the actual archive name on your USB.

The `-FirstRun` flag tells the script to:
1. Back up the entire existing BLink app (excluding content directories)
2. Deploy the new EduPak code
3. Remove legacy BLink files (Data.php, functions.php)
4. Create the `edupak` database and run all migrations
5. Run diagnostics

**What you'll see:** The script prints each step with `[OK]` or `[FAIL]` status. It takes 2-5 minutes.

### Step 4: Configure .env

After the first run, edit the `.env` file with device-specific settings:

```powershell
notepad D:\xampp\htdocs\Edutek\.env
```

Set these values:

| Variable | What to set | Example |
|----------|------------|---------|
| `DB_HOST` | Leave as-is | `localhost` |
| `DB_NAME` | Leave as-is | `edupak` |
| `DB_USER` | Leave as-is | `root` |
| `DB_PASS` | MariaDB root password (blank on most devices) | *(leave empty)* |
| `APP_ENV` | Set to `prod` | `prod` |
| `APP_DEBUG` | Set to `false` | `false` |
| `DEPLOY_SECRET` | A random string for deploy endpoints | `change-me-to-something-random` |
| `CONTENT_DIRS` | Content folder names (comma-separated) | `videos,khan,Wiki,Kiwix,images` |
| `CONTENT_PATH` | Path to the content root | `D:\xampp\htdocs\Edutek\videos\` |

Save and close Notepad.

### Step 5: Verify

Run the diagnostic suite:

```powershell
D:\edupak-diagnose.ps1
```

All tests should show `PASS`. If any show `FAIL`, see Part 5 (Troubleshooting).

### Step 6: Index existing content

Run the content indexer to scan all existing videos, Khan Academy content, Wikipedia, and Kiwix into the database:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\Edutek\..\scripts\content-indexer.php D:\xampp\htdocs\Edutek
```

Or use the HTTP endpoint (if DEPLOY_SECRET is set in `.env`):

```
http://localhost/Edutek/api/content-index.php?key=YOUR_DEPLOY_SECRET
```

This may take several minutes depending on content library size.

---

## Part 3: Subsequent Updates

After the first-time migration, all future updates follow this simpler process.

### Step 1: Copy archive to device

Copy the new archive from USB to the device:

```powershell
Copy-Item "E:\edupak-update-*.zip" -Destination "D:\" -Force
```

### Step 2: Run the update

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
D:\edupak-update.ps1 -Archive "D:\edupak-update-20260405-134500.zip"
```

Replace the filename with the actual archive name.

The script will:
1. Check MariaDB CLI health (repair if needed)
2. Stop Apache
3. Back up current app files and database
4. Extract and sync new code (content directories are never touched)
5. Restart Apache
6. Run database migrations
7. Run diagnostic tests

**Optional flags:**

| Flag | Purpose |
|------|---------|
| `-DryRun` | Preview what would happen without making changes |
| `-SkipBackup` | Skip backup (**dangerous** — only if you already backed up manually) |
| `-SkipMigrate` | Skip database migrations |
| `-SkipDiagnose` | Skip post-update diagnostics |

### Step 3: Verify

If diagnostics ran during the update, check the results printed at the end. Or run them again:

```powershell
D:\edupak-diagnose.ps1
```

### Step 4: If something is wrong — rollback

One command to restore the previous version:

```powershell
D:\edupak-rollback.ps1
```

This restores both code files and the database from the most recent backup.

To rollback to a specific backup:

```powershell
D:\edupak-rollback.ps1 -Timestamp "20260405-134500"
```

Backups are stored in `D:\edupak-backups\` with timestamp folders.

---

## Part 4: Canary Protocol (Fleet Updates)

When updating multiple devices, always update one device first (the "canary") and verify before continuing.

### Step 1: Pick the canary device

Choose one device you have easy physical access to. Preferably one that is actively used by students/teachers so you can verify real-world usage.

### Step 2: Update the canary

Follow **Part 2** (first-time migration) or **Part 3** (subsequent update) on the canary device.

### Step 3: Full manual verification

After the automated diagnostics pass, manually verify these on the canary:

- [ ] **Play a video** — Pick any video from the library and play it
- [ ] **Search for content** — Search for "math" or "science" and verify results appear
- [ ] **Open Khan Academy** — Navigate to the Khan Academy section
- [ ] **Check teacher dashboard** — Log in as a teacher and verify the dashboard loads
- [ ] **Verify all content categories** — Browse each category (videos, audiobooks, tutorials)
- [ ] **Check content counts** — Verify the content index shows expected numbers in the health check:
  ```
  http://localhost/Edutek/api/health-check.php
  ```

### Step 4: If canary passes — proceed to fleet

Only continue if **all** automated diagnostics and manual checks pass on the canary.

### Step 5: For each device in the fleet

Repeat Part 3 on each remaining device:

1. Copy the archive to the device (via USB or local network)
2. Run the update:
   ```powershell
   Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
   D:\edupak-update.ps1 -Archive "D:\edupak-update-20260405-134500.zip"
   ```
3. Check diagnostic results
4. Log the result (device name, pass/fail, any notes)

### Step 6: Handle failures

If a device fails:

1. **Rollback that device immediately:**
   ```powershell
   D:\edupak-rollback.ps1
   ```
2. **Do not stop the fleet rollout** — continue to the next device
3. **Come back to the failed device** after the fleet is done to diagnose the issue
4. **Never leave a device in a half-updated state** — always rollback if diagnostics fail

---

## Part 5: Troubleshooting

### MariaDB CLI hangs

**Symptom:** `mysql.exe` hangs and never returns.

**Cause:** Corrupted MariaDB privilege table (`mysql.db` Aria file).

**Fix:** The update script repairs this automatically. If you need to repair manually:

```powershell
# Stop MariaDB
Get-Process -Name "mysqld" -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Seconds 3

# Restore privilege tables from XAMPP backup
Copy-Item "D:\xampp\mysql\backup\mysql\db.frm" -Destination "D:\xampp\mysql\data\mysql\db.frm" -Force
Copy-Item "D:\xampp\mysql\backup\mysql\db.MAD" -Destination "D:\xampp\mysql\data\mysql\db.MAD" -Force
Copy-Item "D:\xampp\mysql\backup\mysql\db.MAI" -Destination "D:\xampp\mysql\data\mysql\db.MAI" -Force

# Restart MariaDB via XAMPP Control Panel
```

Then test: `D:\xampp\mysql\bin\mysql.exe -u root -e "SELECT 1"` should return instantly.

### Apache won't start

**Possible causes:**

1. **Port conflict** — Another process is using port 80 or 443. Check in XAMPP Control Panel (click "Netstat").
2. **Config error** — Check the Apache error log at `D:\xampp\apache\logs\error.log`.
3. **XAMPP not running** — Open XAMPP Control Panel and start Apache manually.

### Migration fails

**Symptom:** The update script shows `[FAIL]` during the migration step.

**Fix:**
1. Check the error message in the output
2. Rollback: `D:\edupak-rollback.ps1`
3. Fix the issue (usually a database connectivity problem)
4. Retry the update

If migrations fail via CLI, try the HTTP endpoint:
```
http://localhost/Edutek/api/migrate-runner.php?action=status&key=YOUR_DEPLOY_SECRET
```

### Content missing after update

Content directories (`videos/`, `khan/`, `Wiki/`, `Kiwix/`, `images/`) are **never touched** during updates. If content appears missing:

1. Check the content directories still exist:
   ```powershell
   Get-ChildItem "D:\xampp\htdocs\Edutek\videos" | Measure-Object
   Get-ChildItem "D:\xampp\htdocs\Edutek\khan" | Measure-Object
   ```
2. Check the health endpoint: `http://localhost/Edutek/api/health-check.php`
3. If the `content_meta` table is empty, re-run the content indexer:
   ```powershell
   D:\xampp\php\php.exe D:\xampp\htdocs\Edutek\..\scripts\content-indexer.php D:\xampp\htdocs\Edutek
   ```

### .env was overwritten

The `.env` file is protected and **should never be overwritten** during updates. If it was:

1. Check the backup directory for the previous `.env`:
   ```powershell
   Get-ChildItem "D:\edupak-backups" -Recurse -Filter ".env"
   ```
2. Copy it back:
   ```powershell
   Copy-Item "D:\edupak-backups\TIMESTAMP\app\.env" -Destination "D:\xampp\htdocs\Edutek\.env" -Force
   ```

### PowerShell blocks script execution

**Symptom:** "Running scripts is disabled on this system."

**Fix:** Run this at the start of every PowerShell session:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
```

This only affects the current session and doesn't change system policy.

### Update script says "Archive not found"

Make sure you're using the full path to the archive file:

```powershell
# Correct:
D:\edupak-update.ps1 -Archive "D:\edupak-update-20260405-134500.zip"

# Wrong:
D:\edupak-update.ps1 -Archive "edupak-update-20260405-134500.zip"
```

---

## Appendix: File Reference

### PowerShell Scripts

| Script | Runs on | Purpose | Usage |
|--------|---------|---------|-------|
| `scripts/edupak-build.ps1` | Operator laptop | Build update archive from repo | `.\edupak-build.ps1` |
| `scripts/edupak-update.ps1` | EduPak device | Apply update archive | `.\edupak-update.ps1 -Archive "path.zip"` |
| `scripts/edupak-rollback.ps1` | EduPak device | Rollback to previous backup | `.\edupak-rollback.ps1` |
| `scripts/edupak-diagnose.ps1` | EduPak device | Run diagnostic test suite | `.\edupak-diagnose.ps1` |
| `docs/device-audit-template.ps1` | EduPak device | Audit device before first update | `.\device-audit-template.ps1` |

### PHP Endpoints

| Endpoint | Purpose | Auth |
|----------|---------|------|
| `api/health-check.php` | JSON health status (app, DB, content) | None |
| `api/migrate-runner.php` | Run database migrations via HTTP | DEPLOY_SECRET |
| `api/content-index.php` | Trigger content indexing via HTTP | DEPLOY_SECRET |

### PHP CLI Scripts

| Script | Purpose | Usage |
|--------|---------|-------|
| `scripts/migrate.php` | Database migration runner | `php scripts/migrate.php up` |
| `scripts/content-indexer.php` | Index content into content_meta table | `php scripts/content-indexer.php [path]` |

### Key Directories

| Path | Purpose |
|------|---------|
| `D:\xampp` | XAMPP installation root |
| `D:\xampp\htdocs\Edutek` | Application root |
| `D:\xampp\htdocs\Edutek\videos\` | Video content (protected) |
| `D:\xampp\htdocs\Edutek\khan\` | Khan Academy content (protected) |
| `D:\xampp\htdocs\Edutek\Wiki\` | Wikipedia content (protected) |
| `D:\xampp\htdocs\Edutek\Kiwix\` | Kiwix content (protected) |
| `D:\edupak-backups\` | Backup storage (timestamped folders) |
| `D:\xampp\mysql\bin\` | MariaDB CLI tools |
| `D:\xampp\php\` | PHP executable |
