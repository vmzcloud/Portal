# Portal — Bookmark Start Page + Team Calendar

Dark-themed personal portal built with **PHP + SQLite**.

- **UI language:** English  
- **Content:** any UTF-8 text (including Chinese titles / labels)  
- **Stack:** plain PHP (no Composer / Node build), SQLite file DBs  
- **Apps:** bookmark start page + optional **Team Calendar** + optional **Notes** + optional **Todo**

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

**Timezone:** container `TZ` defaults to `Asia/Hong_Kong` (override with `TZ=...` in `.env` or the environment). DB timestamps from SQLite `datetime('now')` are **UTC**; the Notes UI converts them to the browser’s local time.

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
Notes uses a separate DB (`data/notes.db`), created on first access, **disabled by default**.  
Todo uses a separate DB (`data/todo.db`), created on first access, **disabled by default**.

**Change default passwords before any real deployment.**

### Reset databases (re-seed)

```bash
docker compose down
rm -f data/portal.db data/teamcal.db data/notes.db data/todo.db
# optional: reset type/location lists
# rm -rf data/teamcal
docker compose up -d --build
```

Existing portal DBs pick up schema updates automatically (e.g. `is_active`, `must_change_password`).  
Team Calendar tables are ensured on connect via `TeamCalDatabase`.  
Notes tables are ensured on connect via `NotesDatabase`.  
Todo tables are ensured on connect via `TodoDatabase`.

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
- Toolbar: previous / next week, **This week**, and **Go to date** (date picker jumps to that week)  
- Day columns list event chips (no hour timeline)  
- Each chip shows: **type**, **title**, **start** (or All day / AM / PM), **location** (if set), **people** (if set)  
- Click empty day → create; click event → edit  

**Event fields**

- Type (dropdown; list from admin JSON)  
- Title  
- People (multi-select from portal users)  
- Location (dropdown from admin JSON, or free text “Other…”)  
- Description, start/end, all-day / AM / PM  
- **Color:** 7 fixed swatches (not a free color picker); changeable on create and edit  
- Visibility: public / share / private  
- **Period ranges** (admin-configurable; defaults below) applied when saving All day / AM / PM:

  | Mode | Default start | Default end |
  |------|---------------|-------------|
  | All day | 09:00 | 18:00 |
  | AM | 09:00 | 13:00 |
  | PM | 14:00 | 18:00 |


**Visibility & who can write**

| Mode | Read | Create |
|------|------|--------|
| **public** | Anyone (including guests) | Anyone (including guests) |
| **share** | Owner, group members, admin | Logged-in users |
| **private** | Owner, admin | Logged-in users |

Edit / delete: **owner** or **admin**. Guest-created public events have no owner → **admin only** can edit/delete later.

**Admin config**

- Enable / disable feature  
- **Period time ranges** for All day / AM / PM  
- **Event types** JSON array editor → `data/teamcal/event_types.json`  
- **Locations** JSON array editor → `data/teamcal/locations.json`  
- **Holiday ICS** upload (Admin or Calendar page) → `data/teamcal/holidays.json`  
  - Sundays and holiday dates show in **red** on the week view  

**ICS import** (admin only, calendar enabled)

- **Import ICS** on the calendar page → creates public events (`type: Imported`)  
- Re-import skips events with the same `UID` (`ics_uid`)  
- Single instances only (RRULE not expanded)  
- Max file size 2MB  

**Storage**

- Events & settings: `data/teamcal.db` (separate from portal bookmarks DB)  
- Holidays map: `data/teamcal/holidays.json`  
- Schema reference: `sql/teamcal_schema.sql`  

### Notes

Optional personal/shared notes (`/notes.php`). **Off by default.** **Login users only** (no guests).

**Enable**

1. Log in as admin → **Admin** → **Notes**  
2. Check **Enable Notes** → **Save setting**  
3. Logged-in users see **Notes** in the header, or open `/notes.php`

**UI**

- **List** view (default): sidebar of notes + main editor  
- **Cards** view: card grid with its own search bar; click a card opens the editor overlay  
- **Search** (list and cards): multi-condition `AND` / `OR` (same syntax as Todo), `#tag`, `"phrase"`, parentheses  
- **Tag cloud** under the search bar (list + cards): sized by usage among notes you can see; click to filter `#tag`  
- Click a hashtag chip on a note to filter by that tag  
- Search query stays in sync when switching list ↔ cards  
- View mode remembered in the browser  

**Note fields**

- Title  
- Rich text body — toolbar: bold, italic, underline, **font color**, lists, heading, quote, **code block**, link, clear formatting  
- **Hashtags:** multiple chips per note (type + Enter or comma); up to 20; letters, numbers, `_`, `-` (optional leading `#`)  
- Visibility: **private** (owner + admin) or **share** (selected groups + owner + admin)  
- **Version history:** last **5** title/body snapshots on save (when content changes); **History** → preview / **Restore** (tags & visibility stay current)  
- No public notes  

**Storage**

- `data/notes.db` (separate from portal and teamcal DBs)  
- Schema reference: `sql/notes_schema.sql` (includes `tags`, `note_tags`, `note_versions`)  
- HTML body is sanitized server-side (allowlisted tags; safe `color` on text only)  

### Todo

Optional task board (`/todo.php`). **Off by default.** **Login users only.**

**Enable**

1. Log in as admin → **Admin** → **Todo**  
2. Check **Enable Todo** → **Save setting**  
3. Logged-in users see **Todo** in the header, or open `/todo.php`

**UI**

- Kanban board: **To do** | **In progress** | **Done**  
- Drag cards between columns to change status (owner, assignee, or admin)  
- Click a card to edit; **+ Task** to create  
- Search + filter: all visible / created by me / assigned to me  
- **Archive:** done tasks can be archived (card button, modal, or **Archive done** bulk); **Archive** view lists them; **Unarchive** restores to Done  

**Task fields**

- Title, description  
- Status: `todo` / `in_progress` / `done`  
- Optional **due date** (overdue highlighted)  
- Optional single **assignee**  
- **Hashtags** (up to 20; same rules as Notes) — click a chip to filter  
- Visibility: **private** (owner + assignee + admin) or **share** (selected groups + owner + assignee + admin)  
- **Archived** flag (separate from status; only done tasks)  

**Search**

- Multi-condition: space or `AND` = all must match; `OR` = either; `AND` tighter than `OR`  
- `#tag` matches that hashtag; plain text matches title/description/people/tags  
- `"exact phrase"`, parentheses: `(api OR graphql) AND #bug`  

**Permissions**

| Action | Who |
|--------|-----|
| View | Owner, assignee, shared group members, admin |
| Edit fields / delete | Owner, admin |
| Change status | Owner, assignee, admin |
| Archive / unarchive | Owner, assignee, admin |
| Task viewer (read all boards) | Users assigned in Admin → Todo |
| Edit others’ tasks as viewer | — (read-only; own tasks still editable) |

**Task viewers**

1. Admin → **Todo** → check users under **Task viewers** → **Save task viewers**  
2. Those users see a **View as** control: Me / All users / pick a user  
3. Viewing another user shows tasks they **own or are assigned to** (read-only except the viewer’s own tasks)

**Storage**

- `data/todo.db` (separate SQLite DB)  
- Schema reference: `sql/todo_schema.sql`  
- Task viewer ids stored in settings key `task_viewers`  

### Notifications

In-app notifications for logged-in users (stored in `portal.db`).

**Triggers**

- **Todo assigned:** when someone assigns a task to you (create or change assignee)  
- **Note shared:** when a note is shared with a group you belong to (new groups only on edit)  
- **Event tomorrow:** calendar events with **Notify day before** checked — people on the event + owner, created lazily when the bell/list loads on the day before start  

**UI**

- Header **bell** with unread badge next to the username (all main pages)  
- Click username → **Notifications** → `/notifications.php`  
- List page: mark one/all read, dismiss, click row to open Todo/Notes  

### Authentication

- Session login + CSRF on mutating requests  
- Self-service **Password** change (header button)  
- **Force password change on next login** (admin checkbox)  
  - User is redirected to `/change-password.php` until they set a new password  
  - Portal / admin APIs blocked until done  
- **Activate / deactivate** users (admin)  
  - Inactive users cannot log in  

### Admin (`/admin.php`)

- **Users:** create, delete, reset password, activate/deactivate, force password change, **change role** (`user` ↔ `admin`)  
  - Cannot demote, deactivate, or delete your own admin account  
  - Role changes apply after the target user logs in again  
  - **Deleting a user** opens a confirmation dialog:  
    | Content | Behavior |
    |---------|----------|
    | Bookmarks | Always deleted |
    | **Notes** | Choose: **Delete all** / **Reassign to me** / **Keep** |
    | **Todo** tasks owned by the user | Choose: **Delete all** / **Reassign to me** / **Keep** |
    | Todo assignee links | Always cleared |
    | **Private** calendar events owned by the user | Always deleted |
    | Public / share events owned by the user | Kept (owner shown as **Deleted user**) |
    | Event people lists | User removed from all events |
  - Kept orphan notes/events/tasks show owner as **Deleted user**; admin can still edit/delete them  
- **Groups:** create/edit/delete groups, assign members (for **share** bookmarks / events / notes / todos)  
- **Events** (Team Calendar list management — admin only):  
  - Table of events with **Edit** / **Delete** and **+ Event** (same modal form as the week view)  
  - **Search** across title, description, location  
  - **Filters:** date range, type, location, visibility, time mode, color, owner, person, group  
  - Default date range: start of current month → end of month +3  
  - Results capped at **500** (narrow filters if truncated)  
  - Admins can list/manage events even when Team Calendar is disabled  
- **Team Calendar** settings: enable toggle, period ranges, types/locations JSON editors, holiday ICS  
- **Notes**: enable / disable toggle only  
- **Todo**: enable / disable toggle; **Task viewers** multi-select  

### UI chrome

- **Dark / light theme** toggle (header ☀/☾; preference saved in the browser)  
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
| Users & groups (incl. change role) | — | — | ✓ |
| Open Team Calendar (when enabled) | ✓ | ✓ | ✓ |
| Create public calendar events | ✓ | ✓ | ✓ |
| Create share/private calendar events | — | ✓ | ✓ |
| Edit/delete own calendar events | — | ✓ | ✓ |
| Edit/delete any calendar event | — | — | ✓ |
| Admin event list (search / filter / manage) | — | — | ✓ |
| Enable Team Calendar / edit type & location lists | — | — | ✓ |
| Open Notes (when enabled) | — | ✓ | ✓ |
| CRUD own notes / view share notes | — | ✓ | ✓ |
| Edit/delete any note | — | — | ✓ |
| Enable Notes | — | — | ✓ |
| Open Todo (when enabled) | — | ✓ | ✓ |
| Create tasks / edit own / status if assignee | — | ✓ | ✓ |
| View all users’ tasks (read-only) | — | if task viewer | ✓ |
| Edit/delete any task | — | — | ✓ |
| Enable Todo / assign task viewers | — | — | ✓ |

## Data & files

| Path | Purpose |
|------|---------|
| `data/portal.db` | Users, groups, tabs, categories, bookmarks |
| `data/teamcal.db` | Team Calendar settings + events |
| `data/notes.db` | Notes settings, notes, tags, versions |
| `data/todo.db` | Todo settings + tasks |
| `data/teamcal/event_types.json` | Event type dropdown list |
| `data/teamcal/locations.json` | Location dropdown list |
| `data/teamcal/holidays.json` | Holiday dates (from holiday ICS) |
| `public/uploads/icons/` | Uploaded bookmark icons |
| `sql/schema.sql` | Portal schema (new installs) |
| `sql/teamcal_schema.sql` | Team Calendar schema reference |
| `sql/notes_schema.sql` | Notes schema reference |
| `sql/todo_schema.sql` | Todo schema reference |

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
│   │   ├── notes/
│   │   │   ├── notes.php
│   │   │   ├── versions.php
│   │   │   ├── meta.php
│   │   │   └── settings.php
│   │   ├── todo/
│   │   │   ├── tasks.php
│   │   │   ├── meta.php
│   │   │   └── settings.php
│   │   └── teamcal/
│   │       ├── events.php
│   │       ├── holidays.php
│   │       ├── import.php
│   │       ├── meta.php
│   │       └── settings.php
│   ├── assets/css|js/      # style.css, theme.js, app.js, admin.js, calendar.js, notes.js, todo.js
│   ├── uploads/icons/
│   ├── index.php           # main bookmark portal
│   ├── calendar.php        # Team Calendar week view
│   ├── notes.php           # Notes (login, when enabled)
│   ├── todo.php            # Todo kanban (login, when enabled)
│   ├── login.php
│   ├── logout.php
│   ├── change-password.php
│   ├── admin.php
│   └── router.php          # PHP built-in server router
├── src/
│   ├── Auth.php
│   ├── Database.php        # portal.db
│   ├── SearchQuery.php     # shared AND/OR search (Notes + Todo)
│   ├── TeamCal.php
│   ├── TeamCalDatabase.php # teamcal.db
│   ├── Notes.php
│   ├── NotesDatabase.php   # notes.db
│   ├── Todo.php
│   ├── TodoDatabase.php    # todo.db
│   ├── IcsParser.php       # .ics import / holidays
│   ├── helpers.php
│   └── bootstrap.php
├── sql/
│   ├── schema.sql
│   ├── teamcal_schema.sql
│   ├── notes_schema.sql
│   └── todo_schema.sql
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
