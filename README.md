# Hostlinks WordPress Plugin

**Version:** 2.6.2 | **Author:** Digital Solution | **License:** GPL v2

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
| **Plugin Info** | Version info, GitHub update status, database schema version, and shortcode reference. |

---

## Settings Tabs

| Tab | Description |
|---|---|
| **General** | Page URL overrides (Upcoming, Past Events, Reports, Public List, Roster, Event Request Form, Marketing Hub), Google Maps API key. |
| **Build Request Form** | Configuration for the front-end event request form (header text, fields, "+ Event" button visibility). |
| **Roster** | Upload/select a company logo (via WordPress Media Library) displayed in printed rosters. |
| **Alerts** | Registration alert thresholds, colors, and badge/tooltip settings for the event calendar. |
| **Marketing Ops** | Controls visibility of the "📊 Marketing Ops" button on the calendar and Reports pages. |
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
1. Bump `HOSTLINKS_VERSION` in `hostlinks.php` and the `* Version:` header comment.
2. Bump `HOSTLINKS_DB_VERSION` if any DB migrations were added.
3. Commit and push to GitHub.
4. Create a new GitHub Release tagged with the version (e.g. `v2.6.1`).
5. Build and attach `hostlinks.zip` (must contain a root `hostlinks/` folder).

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
│   ├── class-page-urls.php              Front-end page URL resolver & cache
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

## License

GPL v2 or later.
