# Hostlinks WordPress Plugin

**Version:** 2.5.86 | **Author:** Digital Solution | **License:** GPL v2

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
| **Events** | The main event list. View, edit, and manage all training events. Add new events manually. |
| **Add New Event** | Shortcut to the "Add" form on the Events page. |
| **Marketers** | Manage the list of marketers (sales representatives) associated with events. |
| **Event Requests** | Review incoming event booking requests submitted via the front-end form. Shows a badge count for unread/new requests. |
| **Instructors** | Manage the instructor list. |
| **CVENT Sync** | Manually trigger a CVENT sync, view sync results, and see today's API call count. |
| **New CVENT Events** | Review CVENT events detected in the API that don't yet exist in Hostlinks. Accept, ignore, or link them. Shows a badge count when new events are waiting. |
| **Settings** | Tabbed settings panel (see below). |
| **Plugin Info** | Version info, GitHub update status, and database schema version. |

---

## Settings Tabs

| Tab | Description |
|---|---|
| **General** | Page URL overrides (Upcoming, Past Events, Reports, Public List, Roster), Google Maps API key. |
| **Build Request Form** | Configuration for the front-end event request submission form (header text, fields, etc.). |
| **Roster** | Upload/select a company logo (via WordPress Media Library) to display in the top-right corner of printed rosters. |
| **CVENT** *(hidden)* | CVENT API credentials (Client ID, Client Secret, environment). |
| **User Access** *(hidden)* | Per-shortcode access mode (Public / Logged In / Approved Viewers) and the approved viewer user list. |
| **Type Settings** *(hidden)* | Add, edit, and delete event type labels (e.g., Management, Writing, Subaward). |
| **Import / Export** *(hidden)* | Bulk import/export event data. |

---

## Front-End Shortcodes

| Shortcode | Page | Description |
|---|---|---|
| `[eventlisto]` | Upcoming Events | Calendar of upcoming training events. Each row shows location, dates, registration links, marketer, and — if set — a Roster link. |
| `[oldeventlisto]` | Past Events | Same layout for completed events. |
| `[hostlinks_reports]` | Reports | Registration statistics dashboard. Includes a Marketer Summary card, a Year-over-Year card (last 4 calendar years), and date-range filtering (This Month, Last Month, Last 3 Months, Current Year, Custom Range). Powered by Chart.js. |
| `[public_event_list]` | Public Event List | A simplified, publicly accessible event listing (no access control). |
| `[hostlinks_roster]` | Roster | Print-ready attendee roster for a specific event, passed via `?eve_id=`. Loads via AJAX with a spinner so the browser never blocks waiting for the CVENT API. |

All shortcodes except `[public_event_list]` are access-controlled. Administrators always pass. Access modes are configurable per shortcode in **Settings → User Access**.

---

## CVENT Integration

Hostlinks connects to the CVENT REST API (OAuth 2.0 Client Credentials flow) to automatically sync registration data.

**Sync flow (per event):**
1. If a `cvent_event_id` is stored, verify it still exists in CVENT.
2. If no ID is stored, run a matching algorithm using title similarity, date proximity, and geographic proximity scoring.
   - **Auto-match** threshold: score ≥ 90 with a gap ≥ 20 vs. the next candidate.
   - Below threshold: flagged as "needs review" on the New CVENT Events page.
3. Once matched (auto or manually confirmed), fetch attendees and count PAID vs. FREE registrations based on discount strings.
4. Update `eve_paid` and `eve_free` counts in the database.

**Daily sync** runs automatically via WordPress cron.

**New event detection** scans CVENT for events with a 60-day lookback window that don't yet exist in Hostlinks. Results are cached for 1 hour.

**Subaward detection:** Events with "Subaward", "Sub-Award", "Sub Award", or similar variants in the CVENT title are automatically assigned the Subaward event type and tagged `| SUB` in the location field.

**Roster URL auto-population:** When a new event is created (via admin form, booking form, or CVENT sync) and the Roster URL field is blank, Hostlinks automatically generates and saves the URL (`{roster-page-url}/?eve_id={id}`).

---

## Roster Report

The roster feature provides a real-time, print-ready attendee list for any event linked to a CVENT ID.

**How it works:**
- The front-end page uses the `[hostlinks_roster]` shortcode with `?eve_id=X` in the URL.
- On load, JavaScript fetches roster HTML asynchronously from `wp-admin/admin-ajax.php`. A spinner with a 600ms fade-in delay gives feedback during API calls without flashing on fast cached loads.
- Roster data is fetched via CVENT order items + `expand=attendee` (1–2 API calls total). Falls back to individual attendee lookups if the expand parameter is unsupported.

**Caching strategy:**
- **Upcoming/current events:** cached for 24 hours.
- **Past events:** cached permanently.
- **Auto-finalize:** when a roster for a recently-ended event (0–5 days ago) is first viewed, a WordPress cron job re-fetches and permanently caches it exactly 5 days after the event end date — capturing final CVENT registration numbers.

**Display features:**
- Columns: #, Last Name, First Name, Company/Agency, Title, Sign In (wide blank column for printed sign-in sheets).
- Optional toggle columns (not shown by default): Email, Phone. Phone numbers auto-formatted to `XXX-XXX-XXXX`.
- Header title format: `Roster – {Location} – {Type Label}` where type label is `ZOOM`, `Management`, `Writing`, or blank (for Subaward/other).
- Company logo (set in **Settings → Roster**) appears top-right on both screen and print.
- Print layout: landscape orientation, site header/footer hidden.
- Admin-only "Refresh Roster" button forces a cache bypass re-fetch. All permitted users see a "Print" button.

---

## Event Request Form

The `[hostlinks_event_request]` shortcode renders a multi-event submission form for hosts wishing to book a training event.

- Google Places Autocomplete for address fields (requires Google Maps API key in Settings).
- Multi-event rows — submit multiple event dates in a single request.
- File upload for parking/venue PDFs.
- Admin notification emails on submission.
- Admin review page with full detail view per request.
- Badge count on the "Event Requests" menu item for unread submissions.
- Configurable form header text (Settings → Build Request Form).

---

## Auto-Updates

The plugin self-updates from GitHub Releases via the built-in updater (`includes/class-updater.php`). It checks for a `hostlinks.zip` asset attached to the latest GitHub Release. WordPress's standard update UI in **Plugins → Updates** works normally.

**Releasing a new version:**
1. Bump `HOSTLINKS_VERSION` in `hostlinks.php` and the `* Version:` header comment.
2. Commit and push to GitHub.
3. Create a new GitHub Release with a tag matching the version (e.g. `v2.5.87`).
4. Build and attach `hostlinks.zip` to the release (the zip must contain a root `hostlinks/` folder).

---

## Database

Hostlinks maintains its own custom tables alongside the standard WordPress tables:

| Table | Purpose |
|---|---|
| `wp_event_details_list` | Core event records (location, dates, type, marketer, instructor, registration counts, CVENT link, roster URL, etc.) |
| `wp_event_marketer` | Marketer records |
| `wp_event_type` | Event type labels |
| `wp_hostlinks_event_requests` | Front-end booking request submissions |

Schema upgrades run automatically on every page load using `dbDelta` — safe to run repeatedly, only applies changes when needed. Current DB version: **1.7**.

### Event Status Conventions

| Value | Meaning |
|---|---|
| `1` | Active |
| `2` | Deleted |

---

## Directory Structure

```
hostlinks/
├── hostlinks.php                        Main plugin file
├── admin/
│   ├── booking.php                      Events list & edit page
│   ├── cvent-new-events.php             New CVENT events review
│   ├── cvent-settings.php               CVENT API credentials
│   ├── cvent-sync.php                   Manual sync & API call log
│   ├── event-request-detail.php         Single event request view
│   ├── event-request-settings.php       Request form configuration
│   ├── event-requests.php               Event requests list
│   ├── import-export.php                Import / Export
│   ├── instructor-menu.php              Instructors
│   ├── marketer-menu.php                Marketers
│   ├── plugin-info.php                  Version & update info
│   ├── roster.php                       Admin roster report page
│   ├── settings.php                     Tabbed settings shell
│   ├── settings-general.php             General settings tab
│   ├── settings-roster.php              Roster branding tab
│   ├── type-menu.php                    Event type management
│   └── user-access.php                  Shortcode access control
├── assets/
│   ├── css/                             Frontend & admin stylesheets
│   ├── js/                              Admin JavaScript
│   └── images/                          Menu icons and UI images
├── includes/
│   ├── class-access.php                 Front-end access control
│   ├── class-activation.php             Activation hook handler
│   ├── class-admin-menus.php            Admin menu registration
│   ├── class-assets.php                 CSS/JS enqueuing
│   ├── class-cvent-api.php              CVENT REST API client
│   ├── class-cvent-matcher.php          Event matching algorithm
│   ├── class-cvent-scheduler.php        Daily sync cron scheduler
│   ├── class-cvent-sync.php             Sync orchestration logic
│   ├── class-db.php                     Database schema & upgrades
│   ├── class-event-request.php          Request validation & normalization
│   ├── class-event-request-shortcode.php  Request form shortcode handler
│   ├── class-event-request-storage.php  Request DB operations
│   ├── class-import-export.php          Import/export logic
│   ├── class-page-urls.php              Front-end page URL resolver
│   ├── class-shortcodes.php             Shortcode registration & AJAX
│   └── class-updater.php                GitHub auto-update checker
└── shortcode/
    ├── initial_eventlisto.php           Upcoming events template
    ├── old_eventlisto.php               Past events template
    ├── reports.php                      Reports dashboard template
    ├── roster.php                       Roster shell (AJAX loader)
    ├── roster-content.php               Roster HTML renderer (AJAX target)
    └── public-event-list.php            Public event list template
```

---

## License

GPL v2 or later.
