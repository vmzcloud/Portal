# Portal — Bookmark Start Page + Team Calendar

Dark-themed personal portal built with **PHP + SQLite**.

- **UI language:** English  
- **Content:** any UTF-8 text (including Chinese titles / labels)  
- **Stack:** plain PHP (no Composer / Node build), SQLite file DBs  
- **Apps:** bookmark start page + optional **Team Calendar**

## Requirements

- **Docker** (recommended), or  
- **PHP 8.0+** with extensions: `pdo_sqlite`, `fileinfo`

## Quick start

### Docker Compose (recommended)

```bash
cd Portal
docker compose up -d --build
```

Open: **http://localhost:8080**

```bash
docker compose logs -f    # logs
docker compose down       # stop
```

Compose mounts (data + live code):

| Host | Container |
|------|-----------|
| `./data` | `/var/www/html/data` |
| `./public` | `/var/www/html/public` |
| `./src` | `/var/www/html/src` |

PHP/JS/CSS edits under `public/` and `src/` apply without rebuilding. Rebuild when you change `Dockerfile`, `docker-entrypoint.sh`, or image-only files.

### Docker run

```bash
cd Portal
docker build -t portal:local .

# Ephemeral
docker run --rm -p 8080:80 portal:local

# Persist databases + uploads (+ optional live code mounts)
mkdir -p data public/uploads
docker run --rm -p 8080:80 \
  -v "$PWD/data:/var/www/html/data" \
  -v "$PWD/public:/var/www/html/public" \
  -v "$PWD/src:/var/www/html/src" \
  --name portal \
  portal:local
```

- Base image: `php:8.4.22-apache`  
- Document root: `public/`  
- Host port **8080** → container **80**  
- Extensions: `pdo_sqlite`, `mod_rewrite`

### Local PHP (no Docker)

```bash
cd Portal
php -S localhost:8080 -t public public/router.php
```

Open: **http://localhost:8080**

## Default accounts

| User  | Password | Role  |
|-------|----------|-------|
| admin | admin123 | admin |
| demo  | demo123  | user  |

First start creates `data/portal.db` and seed data (tabs, categories, sample bookmarks).  
Team Calendar uses a separate DB (`data/teamcal.db`), created on first access, **disabled by default**.

**Change default passwords before any real deployment.**

### Reset databases (re-seed)

```bash
docker compose down
rm -f data/portal.db data/teamcal.db
# optional: reset type/location lists
# rm -rf data/teamcal
docker compose up -d --build
```

Existing portal DBs pick up schema updates automatically (e.g. `is_active`, `must_change_password`).  
Team Calendar tables are ensured on connect via `TeamCalDatabase`.

## Features

### Bookmarks

- Add / edit / delete  
- Titles and labels support any language (e.g. `Google 地圖`)  
- **Card layout:** wider horizontal cards — icon on the left, title on the right  
- **Drag and drop**
  - Drop on another **category** → move there  
  - Drop on a **tab** → category picker for that tab → move (switches tab)  
- Only owners (or admin) can edit / drag their editable bookmarks  

### Icons

- Upload / replace / delete per bookmark  
- Formats: PNG, JPG, GIF, WebP, SVG (max 2MB)  
- Stored under `public/uploads/icons/{user_id}/`  
- **Deleting an icon removes the file from disk** (also when replacing, deleting a bookmark, category, or user)  
- Admin can manage icons on **any** bookmark (same edit UI; no separate icon gallery)  
- Missing icon → letter avatar fallback  

### Search

- Default mode: **Bookmarks** (live filter by title, URL, category name)  
- Optional web search: Google, Bing, DuckDuckGo  

### Tabs & categories

- **Global** structure (admin) + **personal** tabs/categories (each user)  
- Categories have accent color and optional tab binding  
- Manage UI for logged-in users; admin can create global items  

| | **Global** tab / category | **Personal** tab / category |
|--|---------------------------|-------------------------------|
| Who creates | Admin only | Any logged-in user |
| Who sees it | Everyone (structure) | Owner only |
| Who edits | Admin only | Owner (or admin) |

### Bookmark visibility

| Mode | Who can see |
|------|-------------|
| **public** | Everyone (including guests) |
| **share** | Members of selected groups only |
| **private** | Owner only |

Admin can view/edit all bookmarks.

### Team Calendar

Optional shared calendar (`/calendar.php`). **Off by default.**

**Enable**

1. Log in as admin → **Admin** → **Team Calendar**  
2. Check **Enable Team Calendar** → **Save setting**  
3. Header shows **Calendar**, or open `/calendar.php`

**UI**

- Default **week view**, week starts **Sunday**  
- Main area uses **~95%** of browser width  
- Day columns list event chips (no hour timeline)  
- Each chip shows: **type**, **title**, **start** (or All day / AM / PM), **location** (if set), **people** (if set)  
- Click empty day → create; click event → edit  

**Event fields**

- Type (dropdown; list from admin JSON)  
- Title  
- People (multi-select from portal users)  
- Location (dropdown from admin JSON, or free text “Other…”)  
- Description, start/end, all-day / AM / PM, color  
- Visibility: public / share / private  

**Visibility & who can write**

| Mode | Read | Create |
|------|------|--------|
| **public** | Anyone (including guests) | Anyone (including guests) |
| **share** | Owner, group members, admin | Logged-in users |
| **private** | Owner, admin | Logged-in users |

Edit / delete: **owner** or **admin**. Guest-created public events have no owner → **admin only** can edit/delete later.

**Admin config**

- Enable / disable feature  
- **Event types** JSON array editor → `data/teamcal/event_types.json`  
- **Locations** JSON array editor → `data/teamcal/locations.json`  

**Storage**

- Events & settings: `data/teamcal.db` (separate from portal bookmarks DB)  
- Schema reference: `sql/teamcal_schema.sql`  

### Authentication

- Session login + CSRF on mutating requests  
- Self-service **Password** change (header button)  
- **Force password change on next login** (admin checkbox)  
  - User is redirected to `/change-password.php` until they set a new password  
  - Portal / admin APIs blocked until done  
- **Activate / deactivate** users (admin)  
  - Inactive users cannot log in  

### Admin (`/admin.php`)

- User management: create, delete, reset password, activate/deactivate, force password change  
- Group management: create/edit/delete groups, assign members (for **share** bookmarks / events)  
- **Team Calendar**: enable toggle + types/locations JSON editors  
- Admin cannot deactivate or delete their own account  

### UI chrome

- Dark theme  
- Sticky header; bookmark tabs and category sections  
- Asset URLs are cache-busted (`?v=filemtime`)  
- Footer (portal home): **date** and **time** only  

## Permissions (summary)

| Action | Guest | User | Admin |
|--------|-------|------|-------|
| View public bookmarks | ✓ | ✓ | ✓ |
| View share/private bookmarks | — | if allowed | all |
| CRUD own bookmarks / icons | — | ✓ | ✓ |
| Edit others’ bookmarks | — | — | ✓ |
| Personal tabs/categories | — | ✓ | ✓ |
| Global tabs/categories | — | — | ✓ |
| Users & groups | — | — | ✓ |
| Open Team Calendar (when enabled) | ✓ | ✓ | ✓ |
| Create public calendar events | ✓ | ✓ | ✓ |
| Create share/private calendar events | — | ✓ | ✓ |
| Edit/delete own calendar events | — | ✓ | ✓ |
| Edit/delete any calendar event | — | — | ✓ |
| Enable Team Calendar / edit type & location lists | — | — | ✓ |

## Data & files

| Path | Purpose |
|------|---------|
| `data/portal.db` | Users, groups, tabs, categories, bookmarks |
| `data/teamcal.db` | Team Calendar settings + events |
| `data/teamcal/event_types.json` | Event type dropdown list |
| `data/teamcal/locations.json` | Location dropdown list |
| `public/uploads/icons/` | Uploaded bookmark icons |
| `sql/schema.sql` | Portal schema (new installs) |
| `sql/teamcal_schema.sql` | Team Calendar schema reference |

Compose mounts `./data` (and `./public`, `./src`) so databases and uploads survive container rebuilds.

## Project layout

```
Portal/
├── public/                 # Apache / PHP document root
│   ├── api/
│   │   ├── bookmarks.php
│   │   ├── categories.php
│   │   ├── groups.php
│   │   ├── icons.php
│   │   ├── me.php
│   │   ├── password.php
│   │   ├── tabs.php
│   │   ├── users.php
│   │   └── teamcal/
│   │       ├── events.php
│   │       ├── meta.php
│   │       └── settings.php
│   ├── assets/css|js/      # style.css, app.js, admin.js, calendar.js
│   ├── uploads/icons/
│   ├── index.php           # main bookmark portal
│   ├── calendar.php        # Team Calendar week view
│   ├── login.php
│   ├── logout.php
│   ├── change-password.php
│   ├── admin.php
│   └── router.php          # PHP built-in server router
├── src/
│   ├── Auth.php
│   ├── Database.php        # portal.db
│   ├── TeamCal.php
│   ├── TeamCalDatabase.php # teamcal.db
│   ├── helpers.php
│   └── bootstrap.php
├── sql/
│   ├── schema.sql
│   └── teamcal_schema.sql
├── data/                   # runtime DBs + teamcal JSON (not in docroot)
├── Dockerfile
├── docker-compose.yml
├── docker-entrypoint.sh
└── README.md
```

## Security notes

- Change default account passwords immediately in production  
- `data/` stays outside the web document root (`public/`)  
- Uploads: type allowlist, size limit, random filenames; no PHP execution under uploads  
- Mutating APIs require CSRF token (guests included for public calendar creates)  
- Calendar mutations and most bookmark APIs require a usable session except guest **public** event create  
- Prefer reverse proxy TLS (HTTPS) in production  

## License

Use and modify freely for your own projects.
