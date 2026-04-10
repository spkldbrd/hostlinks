# Hostlinks WordPress Plugin

**Version:** 2.6.9 | **Author:** Digital Solution | **License:** GPL v2

A private WordPress plugin built for Grant Writing USA to manage the full lifecycle of hosted training events. It centralizes event tracking, registration data, instructor/marketer management, CVENT API integration, and front-end display — all in one standalone plugin.

---

## Installation

1. Upload the `hostlinks` folder to `wp-content/plugins/`.
2. Activate the plugin from **Plugins → Installed Plugins**.
3. Required database tables are created automatically on activation.
4. Add `define('FS_METHOD', 'direct');` to `wp-config.php` to allow auto-updates without FTP prompts.

---

## Admin Menu Structure

All plugin pages live under the **Hostlinks** top-level menu in the WordPress admin.

| Menu Item | Description |
|---|---|
| **Events** | The main event list. View, inline-edit, and manage all training events. Each row has an **Edit** button opening the full-page Edit Event form. Add new events manually via the inline form at the top. |
| **Add New Event** | Shortcut to the "Add" form on the Events page. |
| **Marketers** | Manage marketers (sales representatives) including full name, company, phone, and email contact details. |
| **Event Requests** | Review incoming event booking requests submitted via the front-end form. Shows a badge count for unread/new requests. |
| **Instructors** | Manage the instructor list. |
| **CVENT Sync** | Manually trigger a CVENT sync, view sync results, and see today's API call count. |
| **New CVENT Events** | Review CVENT events detected in the API that don't yet exist in Hostlinks. Accept, ignore, or link them. Shows a badge count when new events are waiting. |
| **Settings** | Tabbed settings panel (see below). |
| **Plugin Info** | Version info, GitHub update status, shortcode reference, companion plugin installer (Marketing Ops one-click install), and related plugin download links. |

---

## Settings Tabs

| Tab | Description |
|---|---|
| **General** | Page URL overrides (Upcoming, Past Events, Reports, Public List, Roster, Event Request Form, Marketing Hub), Google Maps API key. |
| **Build Request Form** | Configuration for the front-end event request form (header text, fields, "+ Event" button visibility: Disabled / Admin only / Admin + selected users / All Hostlinks users). |
| **Roster** | Upload/select a company logo (via WordPress Media Library) displayed in printed rosters. |
| **Alerts** | Registration alert thresholds, colors, and badge/tooltip settings for the event calendar. |
| **Marketing Ops** | Controls visibility of the "📊 Marketing Ops" button (Disabled / Admins only / Admins & Marketing Admins / All users). Shows hub page detection status with a **Re-scan Now** button to force a fresh page scan. |
| **CVENT** *(hidden)* | CVENT API credentials (Client ID, Client Secret, environment). |
| **User Access** *(hidden)* | Per-shortcode access mode (Public / Logged In / Approved Viewers) and the approved viewer user list. |
| **Type Settings** *(hidden)* | Add, edit, and delete event type labels (e.g., Management, Writing, Subaward). |
| **Import / Export** *(hidden)* | Bulk import/export event data, with a Reset (truncate) option. |

---

## Front-End Shortcodes

| Shortcode | Page | Access | Description |
|---|---|---|---|
| `[eventlisto]` | Upcoming Events | Configurable | Calendar of upcoming training events. Shows location, dates, registration links, marketer, and roster link. Includes optional registration alert badges, a "+ Event" button, and a "📊 Marketing Ops" button in the nav bar. |
| `[oldeventlisto]` | Past Events | Configurable | Same layout for completed events. |
| `[hostlinks_reports]` | Reports | Configurable | Registration statistics dashboard with Marketer Summary, Year-over-Year (last 4 years), and date-range filtering. Powered by Chart.js. Includes Marketing Ops nav button. |
| `[public_event_list]` | Public Event List | Always public | Simplified publicly accessible event listing with no access control. |
| `[hostlinks_roster]` | Roster | Configurable | Print-ready attendee roster for a specific event (`?eve_id=`). Loads via AJAX with spinner. Admin-only Refresh button. |
| `[hostlinks_event_request_form]` | Event Request | Configurable | Multi-event booking request form for hosts. Includes shipping details section and Google Places Autocomplete. |

Access modes are configurable per shortcode in **Settings → User Access**. Administrators always pass. `[public_event_list]` is always public.

### "+ Event" Button Visibility Options

Configured in **Settings → Build Request Form**:

| Mode | Who sees the button |
|---|---|
| Disabled | Nobody |
| Admin only | `manage_options` users only |
| Admin + selected users | Admins plus specific WP users chosen via a searchable multi-select picker. User IDs stored in `hostlinks_add_event_btn_users` option. |
| All Hostlinks users | Anyone with approved viewer access |

---

## Companion Plugins

Managed from **Plugin Info** admin page:

| Plugin | Install | Purpose |
|---|---|---|
| **Hostlinks Marketing Ops** | One-click install from GitHub via `Plugin_Upgrader` | Marketing analytics hub, manager-level access roles, 📊 Marketing Ops calendar button |
| **GWU Event Pages** | Download link → `github.com/spkldbrd/gwu-event-pages/releases/latest` | Standalone event pages for a separate WordPress install |

The Plugin Info page shows live status (Active / Installed-not-active / Not installed) for Marketing Ops, with an Activate button when installed but inactive.

---

## Action Hooks for Companion Plugins

Hostlinks fires the following `do_action` hooks that companion plugins can hook into:

### `hostlinks_event_created`

```php
do_action( 'hostlinks_event_created', int $new_eve_id, string $eve_start );
```

Fired immediately after a new event row is inserted in `event_details_list`. Triggered by:
- **Manual add** via the admin Events form (`booking.php`)
- **CVENT importer** when accepting a new CVENT event (`cvent-new-events.php`)

**Use case:** The Marketing Ops plugin hooks here to auto-provision checklist tasks for new events without requiring someone to open the event detail page first.

---

## Event URL Fields

Each event stores five URL fields:

| Field | Label in Admin | Description |
|---|---|---|
| `eve_trainer_url` | REG URL | CVENT registration link. Auto-populated by CVENT sync for matched events. |
| `eve_web_url` | WEB URL / HOST URL | Main event web page or host info page. Used as the primary match key for slug-based imports. |
| `eve_email_url` | EMAIL URL | Email campaign page for the event. |
| `eve_roster_url` | Roster URL | Front-end roster page link. Auto-populated on event creation/sync if blank. |
| `eve_zoom_url` | SHORT URL / Zoom URL | Short URL or Zoom join link. |

---

## CVENT Integration

Hostlinks connects to the CVENT REST API (OAuth 2.0 Client Credentials flow) to automatically sync registration data.

**Sync flow (per event):**
1. If a `cvent_event_id` is stored, verify it still exists in CVENT.
2. If no ID is stored, run a matching algorithm using title similarity, date proximity, and geographic proximity scoring.
   - **Auto-match** threshold: score ≥ 90 with a gap ≥ 20 vs. the next candidate.
   - Below threshold: flagged as "needs review" on the New CVENT Events page.
3. Once matched (auto or manually confirmed), fetch attendees and count PAID vs. FREE registrations based on discount strings.
4. Update `eve_paid`, `eve_free`, and — if blank — `eve_trainer_url` (REG URL) in the database.

**Daily sync** runs automatically via WordPress cron. Covers all events ending within the last 60 days or in the future.

**New event detection** scans CVENT for events with a 60-day lookback that don't yet exist in Hostlinks. Results are cached for 1 hour.

**Subaward detection:** Events with "Subaward" / "Sub-Award" variants in the CVENT title are auto-assigned the Subaward event type and tagged `| SUB` in the location field.

**ZOOM/Webinar auto-assignment:** Events with "ZOOM" or webinar markers in the CVENT title are automatically assigned the Zoom marketer and Ericka as instructor.

**Roster URL auto-population:** On first CVENT match (and on manual event creation), if `eve_roster_url` is blank it is automatically set to `{roster-page-url}/?eve_id={id}`.

---

## Roster Report

The roster feature provides a real-time, print-ready attendee list for any event linked to a CVENT ID.

**How it works:**
- The front-end page uses the `[hostlinks_roster]` shortcode with `?eve_id=X` in the URL.
- On load, JavaScript fetches roster HTML asynchronously via `admin-ajax.php`. A spinner with a 600ms fade-in delay gives feedback without flashing on fast cached loads.
- Data is fetched via CVENT order items + `expand=attendee` (1–2 API calls). Falls back to individual attendee lookups if the expand parameter is unsupported.

**Caching strategy:**
- **Upcoming/current events:** cached 24 hours.
- **Past events:** cached permanently.
- **Auto-finalize:** a WordPress cron job fires 5 days after event end to permanently cache the final roster.

**Display features:**
- Columns: #, Last Name, First Name, Company/Agency, Title, Sign In (wide blank column for printed sign-in sheets).
- Optional toggle columns (hidden by default): Email, Phone (auto-formatted to `XXX-XXX-XXXX`).
- Header: `Roster – {Location} – {Type Label}` where type label is `ZOOM`, `Management`, `Writing`, or blank (Subaward/other).
- Company logo (set in **Settings → Roster**) appears top-right on screen and print.
- Print layout: landscape orientation, site header/footer hidden.
- Admin-only "Refresh Roster" button forces cache bypass re-fetch.

---

## Registration Alerts

Visual alerts on upcoming event cards in `[eventlisto]` warn when registration thresholds are approaching.

- **Border glow** around the event card — color and threshold configurable in **Settings → Alerts**.
- **Triangle badge** (optional) — corner badge with a tooltip showing days remaining and registration count. Tooltip supports two lines of custom text.
- Automatically suppressed for events with the "PRIVATE" marketer.
- Dark mode compatible (`.wp-dark-mode-active` selector, tested with "WP Dark Mode A11y" plugin).

---

## Event Request Form

The `[hostlinks_event_request_form]` shortcode renders a multi-event submission form for hosts wishing to book a training.

- Google Places Autocomplete for address fields (requires Google Maps API key in Settings).
- Multi-event rows — submit multiple event dates in a single request.
- Collapsible **Shipping Details** card (attention name, org, address, workbook count, notes).
- File upload for parking/venue PDFs.
- Admin notification emails on submission.
- Admin review page with full detail view per request, including shipping details.
- Badge count on the "Event Requests" menu item for unread submissions.
- Configurable form header text (Settings → Build Request Form).
- Access mode configurable in Settings → User Access (default: Approved Viewers Only).

---

## Edit Event (Admin Full-Page Form)

The **Edit** button on each row of the Events list opens a dedicated full-page edit form covering all event fields:

- **Core:** dates, location, type, marketer, instructor, event status.
- **URLs & Links:** REG URL, WEB URL, EMAIL URL, Roster URL, Parking File URL, Short URL.
- **CVENT:** CVENT event ID, match status, match score, last synced.
- **Shipping Details:** all 10 shipping fields with Google Places Autocomplete for the shipping address.
- **Host & Venue:** host name, displayed-as name, venue name, full venue address (with Google Places Autocomplete), special instructions.
- **Additional Details:** custom email intro, max attendees.
- **Host Contacts:** repeatable rows (name, title, phone, email) stored as JSON.
- **Hotel Recommendations:** repeatable rows (name, address, phone, URL) stored as JSON.

---

## Auto-Updates

The plugin self-updates from GitHub Releases via `includes/class-updater.php`. WordPress's standard update UI in **Plugins → Updates** works normally.

**Releasing a new version:**
1. Bump `HOSTLINKS_VERSION` in `hostlinks.php` (both the `* Version:` header and the `define()`).
2. Bump `HOSTLINKS_DB_VERSION` if any DB migrations were added.
3. Commit and push to GitHub (`git push origin master`).
4. Create a git tag matching the version number — no `v` prefix: `git tag 2.6.9 && git push origin 2.6.9`.
5. Build `hostlinks.zip` (must contain a root `hostlinks/` folder): `Compress-Archive -Path "hostlinks" -DestinationPath "hostlinks.zip" -Force`.
6. Create a GitHub Release: `gh release create {tag} --title "{tag}" --notes "..." "../hostlinks.zip"`.

The updater checks `https://api.github.com/repos/spkldbrd/hostlinks/releases/latest`. It prefers the uploaded `hostlinks.zip` asset; falls back to the archive URL for older releases. The `fix_source_dir` filter renames GitHub's extracted folder (`hostlinks-{tag}/`) to `hostlinks/` to ensure WordPress recognizes it as an update.

**One-click install of companion plugins** uses the same pattern in `includes/class-mktops-installer.php` — fetches the latest release ZIP from the companion's GitHub repo and installs via `Plugin_Upgrader`.

---

## Database

Hostlinks maintains its own custom tables alongside the standard WordPress tables:

| Table | Purpose |
|---|---|
| `wp_event_details_list` | Core event records (location, dates, type, marketer, instructor, registration counts, CVENT link, all URL fields, shipping details, host/venue info, host contacts JSON, hotels JSON, etc.) |
| `wp_event_marketer` | Marketer records including contact details (name, company, phone, email) |
| `wp_event_type` | Event type labels |
| `wp_event_instructor` | Instructor records |
| `wp_hostlinks_event_requests` | Front-end booking request submissions including shipping details |

Schema upgrades run automatically on every page load via `maybe_upgrade()` using `dbDelta` — safe to run repeatedly. Current DB version: **2.3**.

> **Important:** Never skip a DB version number, even for no-schema-change releases. The `version_compare` check in `maybe_upgrade()` uses the stored `hostlinks_db_version` option; skipping a version means that migration block will never run on sites already past that number.

### DB Migration History

| Version | Change |
|---|---|
| 1.1 | Renamed `eve_trainner_url` → `eve_trainer_url` (typo fix) |
| 1.5 | Renamed `eve_sign_in_url` → `eve_web_url` |
| 1.6 | Added `eve_public_hide`, `eve_zoom_time` |
| 1.7 | Added `eve_zoom_url` |
| 1.8 | Added `eve_created_at` (event creation timestamp) |
| 1.9 | Added 10 `ship_*` columns to `event_details_list` and `event_requests` |
| 2.0 | Added marketer contact detail columns to `event_marketer` |
| 2.1 | Added 15 host/venue/contacts/hotels columns to `event_details_list` |
| 2.2 | Added `eve_email_url` to `event_details_list` |
| 2.3 | Version stamp (no schema change) |

### Event Status Conventions

| Value | Meaning |
|---|---|
| `1` | Active |
| `2` | Deleted |

---

## Directory Structure

```
hostlinks/
├── hostlinks.php                        Main plugin file (version, DB version, hooks)
├── CHANGELOG.md                         Version history
├── admin/
│   ├── booking.php                      Events list, quick-edit, add-new form
│   ├── cvent-new-events.php             New CVENT events review & import
│   ├── cvent-settings.php               CVENT API credentials tab
│   ├── cvent-sync.php                   Manual sync trigger & API call log
│   ├── edit-event.php                   Full-page Edit Event form
│   ├── event-request-detail.php         Single event request detail view
│   ├── event-request-settings.php       Event request form configuration tab
│   ├── event-requests.php               Event requests list
│   ├── import-export.php                Import / Export / Reset UI
│   ├── instructor-menu.php              Instructors CRUD
│   ├── marketer-menu.php                Marketers CRUD (with contact details)
│   ├── plugin-info.php                  Version, update status, shortcode reference
│   ├── roster.php                       Admin roster report page
│   ├── settings.php                     Tabbed settings shell
│   ├── settings-alerts.php              Registration alerts configuration
│   ├── settings-general.php             General settings (page URLs, Maps API key)
│   ├── settings-marketing-ops.php       Marketing Ops button settings
│   ├── settings-roster.php              Roster branding (logo upload)
│   ├── type-menu.php                    Event type management
│   └── user-access.php                  Per-shortcode access control
├── assets/
│   ├── css/
│   │   ├── hostlinks-calendar.css       Front-end calendar & alert styles
│   │   └── hostlinks-event-request.css  Event request form styles
│   ├── js/                              Admin JavaScript
│   └── images/                          Menu icons and UI assets
├── includes/
│   ├── class-access.php                 Front-end access control logic
│   ├── class-activation.php             Plugin activation hook handler
│   ├── class-admin-menus.php            Admin menu registration
│   ├── class-assets.php                 CSS/JS enqueuing (admin + front-end)
│   ├── class-cvent-api.php              CVENT REST API client (OAuth 2.0)
│   ├── class-cvent-matcher.php          Event matching algorithm
│   ├── class-cvent-scheduler.php        Daily sync cron scheduler
│   ├── class-cvent-sync.php             Sync orchestration logic
│   ├── class-db.php                     DB schema creation & migrations
│   ├── class-event-request.php          Request validation & normalization
│   ├── class-event-request-shortcode.php  Front-end request form shortcode
│   ├── class-event-request-storage.php  Event request DB operations
│   ├── class-import-export.php          Import/export/reset logic
│   ├── class-mktops-installer.php       One-click installer for Marketing Ops companion plugin
│   ├── class-page-urls.php              Front-end page URL resolver & cache (24h transient)
│   ├── class-shortcodes.php             Shortcode registration & AJAX handlers
│   └── class-updater.php                GitHub Releases auto-update checker
└── shortcode/
    ├── initial_eventlisto.php           Upcoming events template
    ├── old_eventlisto.php               Past events template
    ├── reports.php                      Reports dashboard template
    ├── roster.php                       Roster AJAX shell (spinner + loader)
    ├── roster-content.php               Roster HTML renderer (AJAX target)
    └── public-event-list.php            Public event list template
```

---

## Developer Notes & Known Gotchas

### Page URL Detection
`class-page-urls.php` scans `wp_posts.post_content` for shortcode tags using a `LIKE '%[shortcode]%'` query. Results are cached as transients for 24 hours. If a page is published after the cache is set, use the **Re-scan Now** button (Settings → Marketing Ops for the hub page, or wait for cache expiry for other pages). The `clear_cache()` static method deletes all URL transients at once — call it after saving page URL overrides.

### Inline Edit Safety
The Events list (`booking.php`) uses a bulk form where all visible rows are submitted on Update. Only rows with the checkbox ticked are saved (checked against `$_POST['users']` array). The date range field (`evedate[]`) and location field (`eve_location[]`) have `autocomplete="off"` to prevent browser autofill from silently overwriting them. For surgical edits to a single event, use the **Edit** button (full-page form) rather than the inline list edit.

### CVENT Sync — What It Does and Does NOT Write
The sync process **only writes** to these columns: `eve_paid`, `eve_free`, `cvent_*` metadata columns, `eve_trainer_url` (if blank), `eve_roster_url` (if blank). It **never writes** `eve_start`, `eve_end`, or `eve_tot_date`. Date changes on events always originate from manual edits, not sync.

### Zoom Event Matching
Zoom events auto-match using `dates_overlap(25) + type_match(35) + zoom_match(30) = 90`, the minimum auto-match threshold. This means any same-type zoom event within the date window can auto-match. If two zoom events of the same type occur in the same month, they may match each other incorrectly. Review `cvent_match_status = 'auto'` rows with `cvent_match_score = 90` for potential mismatches.

### `hostlinks_event_created` Hook
Only fires on **new inserts**, not on edits. The `$eve_start` parameter is the raw date string from the form (format: `Y-m-d`). The `$new_eve_id` is the integer primary key from `wpdb->insert_id`.

### Marketing Ops Plugin Dependency
The `admin_plus_mgr` visibility mode for both the Marketing Ops button and the "+ Event" button depends on `HMO_Access_Service::current_user_is_marketing_admin()` from the Marketing Ops plugin. If Marketing Ops is inactive, this mode silently falls back to admin-only behavior (the class_exists check prevents fatal errors).

### Dark Mode CSS
All dark mode styles use `.wp-dark-mode-active` as the parent selector (not `.dark`). This is compatible with the "WP Dark Mode A11y" WordPress plugin which adds that class to `<html>`. If switching dark mode plugins, update this selector in `hostlinks-calendar.css` and `hostlinks-event-request.css`.

### Browser Compatibility — Date Fields
The full-page Edit Event form (`edit-event.php`) uses `<input type="date">` native date pickers — these are safe from autofill. The Events list inline edit uses `<input type="text">` for the date range field (jQuery daterangepicker format `YYYY/MM/DD - YYYY/MM/DD`), which is protected by `autocomplete="off"` but is inherently more fragile than a native date picker.

---

## License

GPL v2 or later.
