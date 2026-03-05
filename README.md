# Hostlinks WordPress Plugin

A standalone WordPress plugin for managing hosted events, marketers, instructors, and event types. Originally built as a theme-specific tool and refactored into a portable plugin.

## Features

- **Event management** – Create, edit, and soft-delete events with dates, location, paid/free seats, Zoom links, and associated URLs.
- **Event types** – Configure event categories linked to events.
- **Marketers & Instructors** – Maintain separate rosters referenced by events.
- **Frontend shortcodes**
  - `[eventlisto]` – Displays active/upcoming events.
  - `[oldeventlisto]` – Displays past events.
- **Import / Export** – Export all data as JSON (full backup) or CSV (events only). Import from either format with duplicate detection.
- **Auto-updates** – Checks GitHub Releases for new versions; updates appear in the standard WordPress "Plugins" update flow.

## Author

Digital Solution

## Installation

1. Upload the `hostlinks` folder to `wp-content/plugins/`.
2. Activate the plugin from **Plugins → Installed Plugins**.
3. The required database tables are created automatically on activation.

## Releasing a New Version

1. Bump `HOSTLINKS_VERSION` in `hostlinks.php` (e.g. `2.1.0`).
2. Commit and push to GitHub.
3. Create a new **GitHub Release** with a tag matching the version number (`2.1.0`).  
   WordPress will detect the new version on the next update check and show the standard update prompt.

## Directory Structure

```
hostlinks/
├── hostlinks.php               Main plugin file
├── admin/
│   ├── booking.php             Events admin page
│   ├── type-menu.php           Event types admin page
│   ├── marketer-menu.php       Marketers admin page
│   ├── instructor-menu.php     Instructors admin page
│   └── import-export.php       Import / Export admin page
├── assets/
│   ├── css/                    Frontend & admin stylesheets
│   ├── js/                     Admin JavaScript (daterangepicker)
│   └── images/                 Menu icons and UI images
├── includes/
│   ├── class-admin-menus.php   Registers WP admin menu
│   ├── class-assets.php        Enqueues CSS/JS
│   ├── class-db.php            Database table creation (activation)
│   ├── class-import-export.php Import/Export logic
│   ├── class-shortcodes.php    Shortcode registration
│   └── class-updater.php       GitHub auto-update checker
└── shortcode/
    ├── initial_eventlisto.php  Active events shortcode template
    └── old_eventlisto.php      Past events shortcode template
```

## Status Conventions

| Value | Meaning  |
|-------|----------|
| `1`   | Active   |
| `2`   | Deleted  |

## License

GPL v2 or later.
