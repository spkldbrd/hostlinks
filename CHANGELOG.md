# Hostlinks Plugin — Changelog

---

## v2.6.2 — 2026-03-05

### Enhancement: Marketing Ops button — "Admins & Marketing Managers" visibility mode
- Added a fourth visibility option (`admin_plus_mgr`) to the Marketing Ops button dropdown in **Settings → Marketing Ops**.
- When selected, the "📊 Marketing Ops" button is shown to WordPress admins **and** users recognised as Marketing Managers by the Marketing Ops plugin (`HMO_Access_Service::current_user_is_marketing_admin()`).
- Applied consistently to both the `[eventlisto]` calendar and the `[hostlinks_reports]` Reports page.
- Dropdown labels updated: "Admin only" → "WordPress Admins only" for clarity.
- Settings description updated to note that the Marketing Ops plugin must be active for the manager role to resolve.

---

## v2.6.1 — 2026-03-05

### New: Email URL field
- Added `eve_email_url` column to `event_details_list` (DB migration v2.2).
- **Events list (admin):** New "EMAIL URL" column in the event table with inline input in both the quick-edit form and the bulk-update form.
- **Add New Event form:** EMAIL URL field added.
- **Edit Event page:** EMAIL URL field added to the "URLs & Links" card.

### New: Edit Event page (full-page admin editor)
- Replaced inline-only editing with a dedicated full-page edit form accessible via an "Edit" button on each event row in the Events list.
- Card-based layout matching the Event Request form style.
- Covers all event fields: dates, location, type, marketer, instructor, CVENT link, all URL fields (REG, WEB, EMAIL, Roster, Parking, Short URL), shipping details, host & venue info, additional details, host contacts (repeatable JSON rows), hotel recommendations (repeatable JSON rows), and max attendees.
- Google Places Autocomplete for venue and shipping address fields.
- Phone number auto-formatting.
- Label consistency: "TRAINER URL" renamed to "REG URL" to match the event list column label.

### New: Shipping Details
- Added optional "Shipping Details" collapsible card to the Event Request form and the Edit Event page.
- 10 new columns added to both `event_details_list` and `hostlinks_event_requests`: `ship_attn`, `ship_org`, `ship_street_1/2/3`, `ship_city`, `ship_state`, `ship_zip`, `ship_workbooks`, `ship_notes` (DB migration v1.9).
- Shipping details visible on the Event Request detail (admin review) page.

### New: Marketer Contact Details
- Added Full Name, Company, Phone, and Email fields to Marketer records (DB migration v2.0).
- Editable on the Marketers admin page and displayed in the marketer list table.

### Fix: "TRAINER URL" → "REG URL" label consistency
- The Edit Event page previously labelled the `eve_trainer_url` field as "Trainer URL". Renamed to "REG URL" to match the Events list column header.

---

## v2.6.0 — 2026-02 (approx.)

### Expanded Edit Event page
- Added all fields from the Event Request form that were previously missing from the admin Edit Event page: `host_name`, `displayed_as`, `location_name`, full address fields, `special_instructions`, `parking_file_url`, `custom_email_intro`, `host_contacts` (JSON), `hotels` (JSON), `max_attendees` (DB migration v2.1).

---

## v2.5.99 — 2026-01 (approx.)

### New: Marketer Contact Details (initial)
- Added full name, company, phone, and email fields to the Marketers table and admin UI.

---

## v2.5.95 — 2025-12 (approx.)

### New: Marketing Ops integration
- One-time dismissible admin notice when the "Hostlinks Marketing Ops" companion plugin is detected but the integration is not yet configured.
- New "Marketing Ops" tab in Hostlinks Settings to control the "📊 Marketing Ops" button visibility (Disabled / Admin only / All Hostlinks users).
- "📊 Marketing Ops" button added to the `[eventlisto]` calendar navigation bar and the Reports page navigation bar.
- `get_event_request_form()` and `get_mktops_hub()` added to `class-page-urls.php`; corresponding URL override inputs added to General settings.

---

## v2.5.94 — 2025-12 (approx.)

### New: "+ Event" button on calendar
- Configurable "+ Event" button added to the `[eventlisto]` navigation bar, linking to the Event Request Form page.
- Visibility: Disabled / Admin only / All Hostlinks users — configurable in Settings → Build Request Form.

---

## v2.5.93 — 2025-11 (approx.)

### New: Event creation timestamp
- Added `eve_created_at` column to `event_details_list` (auto-populated with current timestamp on INSERT).
- All event-creation paths updated: manual add, quick-edit, CVENT import.

### Edit Event routing
- Added `?edit_event={id}` routing on `booking.php` to open the new dedicated Edit Event page.
- "Edit" button added to each row in the Events list table.

---

## v2.5.92 — 2025-11 (approx.)

### Shortcode access modes — consistency pass
- All Hostlinks shortcodes now listed on the User Access settings page, including those with fixed permissions.
- `[hostlinks_event_request_form]` changed from "Always Public" to configurable (default: Approved Viewers Only).

---

## v2.5.91 — 2025-10 (approx.)

### New: Registration Alerts on event calendar
- Visual border-glow alerts on upcoming event cards in `[eventlisto]` based on days remaining and paid registration count.
- Configurable thresholds, colors, and an optional triangle badge with tooltip.
- Suppressed automatically for "PRIVATE" marketer events.
- Dark mode support via `.wp-dark-mode-active` selector (compatible with "WP Dark Mode A11y" plugin).
- New admin settings page: **Settings → Registration Alerts**.

---

## v2.5.90 — 2025-10 (approx.)

### Fixes
- Dark mode CSS selector corrected from `.dark` to `.wp-dark-mode-active` across `hostlinks-calendar.css` and `hostlinks-event-request.css`.
- Red alert glow visibility improved in dark mode: wider `box-shadow` spread and faint red background tint.
- GitHub release workflow corrections (PowerShell `&&` → `;` separator fix).

---

## v2.5.87 — 2025-09 (approx.)

### Fix: Critical error on calendar shortcode
- Fatal parse error caused by unclosed PHP tag in `shortcode/initial_eventlisto.php`. Fixed by adding missing `?>` before `<style>` block.

---

## v2.5.86 — 2025-09 (approx.)

### Fix: Media Library button on Roster settings
- "Choose from Media Library" button on the Roster settings page was not opening the WordPress media picker.
- Fixed by conditionally enqueuing `wp_enqueue_media()` in `class-assets.php` and guarding `wp.media` inside the click handler.

---

## v2.5.85 — 2025-08 (approx.)

### Roster: front-end page and auto-population
- Front-end `[hostlinks_roster]` shortcode page implemented.
- Access-controlled via `Hostlinks_Access` permissions.
- Auto-populates `eve_roster_url` when blank on event save/sync.
- Print-only output with customizable header, "(not for public view)" note, wide Sign In column, and formatted phone numbers.
- Loading spinner with 600ms fade-in delay.
- Admin-only "Refresh Roster" button for cache bypass.
- Company logo displayed via **Settings → Roster** (uses WordPress Media Library).

### Roster: WordPress cron auto-pull
- Cron job fires 5 days after event end to permanently cache the final roster for past events.

---

## v2.5.84 — 2025-08 (approx.)

### Roster: permanent caching for past events
- Completed rosters (past events) are cached permanently; upcoming/current events cached 24 hours.

---

## v2.5.83 — 2025-07 (approx.)

### Roster: optional Email/Phone columns and API efficiency
- Toggle columns added: Email, Phone (hidden by default; phone auto-formatted).
- Roster API calls reduced from per-attendee to batch order-item + `expand=attendee` approach.

---

## v2.5.81 — 2025-07 (approx.)

### New: Admin Roster Report page
- On-demand, print-ready roster accessible from the Events list per event.
- Fetches live CVENT attendee data.

---

## v2.5.80 — 2025-06 (approx.)

### New: Year-over-Year marketer summary card
- New "Year over Year" card on the Reports page for active marketers.
- Displays total and average registrations per class for the last 4 calendar years.

---

## v2.5.x — 2025 (various)

- Reports page: This Month / Last Month / Last 3 Months / Current Year / Custom Range date filters.
- Chart.js registration trend charts.
- Public event list shortcode `[public_event_list]`.
- CVENT import: auto-set Zoom location/marketer/instructor for webinar events.
- CVENT import: `eve_tot_date` format corrected to `YYYY/MM/DD - YYYY/MM/DD`.
- Plugin Info page: added shortcode reference table.
- Import/Export: column rename mapping (`eve_trainner_url` → `eve_trainer_url`, `eve_sign_in_url` → `eve_web_url`).

---

## v2.5.0 — 2025 (initial tracked version)

- Initial migration from theme-based code to standalone plugin.
- Custom DB tables: `event_details_list`, `event_marketer`, `event_type`, `event_instructor`, `hostlinks_event_requests`.
- Admin menu: Events, Marketers, Instructors, Event Types, CVENT Sync, New CVENT Events, Settings, Plugin Info.
- Front-end shortcodes: `[eventlisto]`, `[oldeventlisto]`, `[hostlinks_reports]`.
- GitHub auto-update via `class-updater.php`.
- CVENT OAuth 2.0 integration with auto-match scoring algorithm.
