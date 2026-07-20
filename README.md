# Portal — Personal Bookmark Start Page

Dark-themed bookmark portal (browser start-page style) built with **PHP + SQLite**.

- **UI language:** English  
- **Content:** any UTF-8 text (including Chinese bookmark / tab / category names)  
- **Stack:** plain PHP (no Composer / Node build), SQLite file DB  

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

### Docker run

```bash
cd Portal
docker build -t portal:local .

# Ephemeral
docker run --rm -p 8080:80 portal:local

# Persist database + uploaded icons
mkdir -p data public/uploads
docker run --rm -p 8080:80 \
  -v "$PWD/data:/var/www/html/data" \
  -v "$PWD/public/uploads:/var/www/html/public/uploads" \
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

**Change default passwords before any real deployment.**

### Reset database (re-seed)

```bash
docker compose down
rm -f data/portal.db
docker compose up -d --build
```

Existing DBs pick up schema updates automatically (e.g. `is_active`, `must_change_password` columns).

## Features

### Bookmarks
- Add / edit / delete  
- Titles and labels support any language (e.g. `Google 地圖`)  
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

### Visibility
| Mode | Who can see |
|------|-------------|
| **public** | Everyone (including guests) |
| **share** | Members of selected groups only |
| **private** | Owner only |

Admin can view/edit all bookmarks.

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
- Group management: create/edit/delete groups, assign members (for **share** bookmarks)  
- Admin cannot deactivate or delete their own account  

### UI chrome
- Dark theme start page  
- Sticky tabs, category sections, icon cards  
- Footer: **date** and **time** only  

## Permissions (summary)

| Action | Guest | User | Admin |
|--------|-------|------|-------|
| View public bookmarks | ✓ | ✓ | ✓ |
| View share/private | — | if allowed | all |
| CRUD own bookmarks / icons | — | ✓ | ✓ |
| Edit others’ bookmarks | — | — | ✓ |
| Personal tabs/categories | — | ✓ | ✓ |
| Global tabs/categories | — | — | ✓ |
| Users & groups | — | — | ✓ |

## Data & files

| Path | Purpose |
|------|---------|
| `data/portal.db` | SQLite database (auto-created) |
| `public/uploads/icons/` | Uploaded bookmark icons |
| `sql/schema.sql` | Base schema for new installs |

Compose mounts `./data` and `./public/uploads` so data survives container rebuilds.

## Project layout

```
Portal/
├── public/                 # Apache / PHP document root
│   ├── api/                # JSON API
│   │   ├── bookmarks.php
│   │   ├── categories.php
│   │   ├── groups.php
│   │   ├── icons.php
│   │   ├── me.php
│   │   ├── password.php
│   │   ├── tabs.php
│   │   └── users.php
│   ├── assets/css|js/
│   ├── uploads/icons/
│   ├── index.php           # main portal
│   ├── login.php
│   ├── logout.php
│   ├── change-password.php # forced / optional password change
│   ├── admin.php
│   └── router.php          # PHP built-in server router
├── src/                    # Auth, Database, helpers
├── sql/schema.sql
├── data/                   # SQLite (runtime)
├── Dockerfile
├── docker-compose.yml
├── docker-entrypoint.sh
└── README.md
```

## Security notes

- Change default account passwords immediately in production  
- `data/` stays outside the web document root (`public/`)  
- Uploads: type allowlist, size limit, random filenames; no PHP execution under uploads  
- Mutating APIs require login + CSRF token  
- Prefer reverse proxy TLS (HTTPS) in production  

## License

Use and modify freely for your own projects.
