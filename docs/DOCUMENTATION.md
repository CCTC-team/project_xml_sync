# Project XML Sync External Module Documentation

## Overview

Compares a REDCap project with a Project XML (CDISC ODM with `redcap:` extensions) exported from another server and
applies the selected design differences to the project. Built for copying amendments from the TEST copy of a trial
to the production project.

### Authors

- Richard Hardy — University of Cambridge, Cambridge Cancer Trials Centre
- Mintoo Xavier — Cambridge University Hospital, Cambridge Cancer Trials Centre

### Compatibility

| | Min | Max |
|---|---|---|
| PHP | 8.0.27 | 8.2.99 |
| REDCap | 14.7.0 | 15.9.1 |
| EM framework | 16 | |

Instrument custom CSS needs REDCap 15+ (on 14.x a CSS change is reported and fails with a clear message).

## Purpose

REDCap can create a *new* project from a Project XML, but cannot import one into an existing project. Core
`ODM::parseOdm()` parses and commits in one pass, and on an existing project it would delete every arm and event
and insert duplicates of every other component. This module instead:

1. parses both the uploaded XML and the project's own freshly-exported XML with one side-effect-free parser,
2. diffs them on natural keys,
3. applies only the items the user selects, through REDCap's own save functions where they exist.

## Architecture

### File Structure

```
project_xml_sync_v1.0.0/
├── config.json
├── ProjectXmlSyncModule.php    # hooks, access checks, upload/backup/run storage, buildDiff()
├── classes/
│   ├── OdmDesignParser.php     # side-effect-free Project XML reader
│   ├── ProdSnapshot.php        # this project's design in the parser's shape + db row ids
│   ├── DesignDiff.php          # comparison, natural keys, gates
│   └── Applier.php             # applies selected items, per-component transactions, logging
├── Rendering.php               # HTML builders (all output escaped here)
├── index.php                   # upload + diff review page (menu link)
├── apply.php                   # POST: re-computes the diff server-side and applies
├── report_csv.php              # run report download
├── download.php                # uploaded XML / design backup download
├── js/sync.js, css/sync.css
├── docs/DOCUMENTATION.md
└── automated_tests/            # E.130.* Cypress features, fixtures, URS
```

### Core Classes

#### OdmDesignParser

`parse(string $xml): array` returns:

| Key | Content |
|---|---|
| `recordIdField`, `longitudinal`, `title`, `hasClinicalData` | basics |
| `projectAttrs` | `redcap_projects` column => value (from `ODM::getProjectAttrMappings()`; purpose/notes ignored) |
| `arms`, `events`, `mapping` | arm number => name; unique event name => event attributes; unique event name => forms |
| `forms` | form => `label`, `css` |
| `fields` | field => data-dictionary row (front-end values, as `MetaData::getDataDictionary()` returns) |
| `fieldExtra` | stop actions, video, inline image, attachment hash |
| `repeating` | unique event name => `'WHOLE'` or form => custom label |
| `vendor` | `redcap_<table>` => rows, from every `<redcap:XxxGroup>` |
| `groupsPresent`, `attachments` | which groups the XML carries; `OdmAttachment` files by id |

Newlines inside attribute values are preserved with the same pre-processing as `ODM::parseOdm()`. Export
pseudo-fields (DAG, survey identifier, survey timestamps) are skipped. Multi-language settings are hashed without
server-specific values (project id, status, version, alert/ASI id maps).

#### ProdSnapshot

`build(int $pid)` exports the project's own Project XML (all components, alerts/ASIs with their real state) with
`ODM::getOdmMetadata()` and parses it with `OdmDesignParser`, so both sides of the diff went through identical export
conversions. In Draft Mode the drafted fields/instruments (`metadata_temp` / `forms_temp`) are exported instead, so
field changes are compared with the draft. It also returns the database primary key of every component row (keyed by
natural key), the events/instruments holding data, and the live instrument list.

#### DesignDiff

`compute($src, $tgt, $ctx)` returns items `{id, component, key, status (added|changed|removed|manual), changes,
before, after, default, blocked, note, approval}`.

Natural keys: fields/forms by name, arms by number, events by unique event name, DQ rules by rule name, surveys by
form, ASIs and survey queue by form + event, alerts / reports / dashboards by title, DAGs by group name, roles by role
name, form display logic by condition, descriptive popups by link text. Blank or duplicated keys become *manual* items.

Columns that are server-specific are ignored (ids, unique role/report names, hashes, short URLs, user access, ASI
`active`, alert `email_deleted`/sent state). Attachments compare by content hash. A column missing on either side is
treated as a REDCap version difference, not a change.

Gates (an item with `blocked` set cannot be selected):

- project in Analysis/Cleanup or Completed status; drafted changes awaiting approval (fields/instruments/order);
  record ID field differs
- item depends on an instrument that only exists in the draft (`approval`)
- non-administrators in production: arms/events (unless `enable_edit_prod_events`), removing mappings, repeating setup
  (unless `enable_edit_prod_repeating_setup`), deleting arms/events
- user rights: `data_quality_design`, `data_access_groups`, `user_rights`, `alerts`, `reports`
- removals that would lose data (events/arms with data, roles/DAGs in use, surveys)

#### Applier

Applies selected items in dependency order — project settings, arms, events, fields/instruments/order, mapping,
repeating setup, then the other components — each component in its own transaction. A failing component is rolled
back and reported; the others still apply.

| Component | How it is applied |
|---|---|
| Project settings | `UPDATE redcap_projects` (column whitelisted from table columns; survey-login events translated) |
| Arms / events | `Arm::addArms` (no override), `Event::create` / `Event::update` / `Event::delete`, `Arm::delArm` |
| Fields, instruments, order | Enter Draft Mode if needed (port of `Design/draft_mode_enter.php`), merge ticked items into the current draft dictionary, `MetaData::error_checking`, `createDataDictionarySnapshot`, `save_metadata`; labels via `setFormLabel(..., draft)`, CSS via `Design::setFormCustomCSS`; stop actions / video / attachments written to `redcap_metadata_temp` |
| Mapping | Full target set built from the live mapping ± ticked items, `Event::saveEventMapping` |
| Repeating setup | `redcap_events_repeat` per item |
| DQ rules | `DataQuality::addDQRule`; update/delete by rule id |
| Other components | Insert: `ODM::addArrayToTable` (translates events, surveys, attachments, report/FDL child rows). Update: changed columns only, with the same translations; report fields/filters and FDL targets replaced. Delete: alerts and ASIs deactivated, others deleted by primary key |

Field order: when the *order* item is ticked, the XML order is used; otherwise existing fields keep their order and
new fields are placed after their neighbour in the XML. Instruments are kept contiguous and the record ID field first.

## Installation

Copy `project_xml_sync_v1.0.0` into `modules/`, enable it in Control Center > External Modules, then enable it on the
production project(s).

## Project Configuration

### Optional settings

| Key | Type | Description |
|---|---|---|
| `restrict-to-super-users` | checkbox (admin only) | Only administrators can use the module on this project |

### Configuration audit log

Saving the configuration logs `Configuration changed (project)` with `setting`, `old_value`, `new_value` (same block as
the other CCTC modules).

## Workflow

1. Export the TEST project's XML (metadata only, all optional components ticked).
2. In PROD, open **Project XML Sync**, upload the XML, review the differences.
3. Tick/untick, click **Apply selected changes** (a backup of the design is stored first).
4. Review the drafted field changes and submit them in the Online Designer.
5. After approval, re-open Project XML Sync and apply the remaining items (mapping, repeating setup, surveys of new
   instruments). Repeat until only intentional differences remain.

## User Interface

- Summary table with counts per component, then one section per component with a checkbox per item, before → after
  values for changed properties, full properties for added/removed items, notes and lock reasons.
- Confirmation dialog that counts removals.
- Results page with per-item outcome, next steps and a CSV run report.
- Run history with links to the report, the uploaded XML and the design backup.

## Security Features

- **Access control:** `userCanUse()` (administrator, or Design rights unless restricted) is checked by the menu link
  and again by every page (`index.php`, `apply.php`, `report_csv.php`, `download.php`).
- **Server-side re-validation:** `apply.php` recomputes the diff and applies only submitted ids that are still present
  and not blocked; values are never taken from the browser.
- **SQL injection:** parameterised `db_query`; table and column names come only from code or `getTableColumns()`.
- **XSS:** every value is escaped in `Rendering.php`.
- **XML:** parsed with `LIBXML_NONET` (no network access, no external entities).
- **CSRF:** REDCap's token on all POST forms.
- **Downloads:** only edocs recorded by this module for this project.
- **CSV:** values beginning with `= + - @` are prefixed to prevent formula injection.
- **From addresses:** alert/ASI From addresses that do not belong to the applying user are replaced with the user's
  address, as core XML import does.
- **Settings permissions:** `disableUserBasedSettingPermissions()` is used so Design users can store the upload and run
  history; access is enforced by `userCanUse()` instead.

## Database

No tables are created. Module project settings: `last-upload` (uploaded XML edoc id, name, sha1, user, time) and
`run-history` (last 50 runs with report rows and backup edoc id). Uploaded XML files and design backups are REDCap
edocs.

## Logging

- Project Logging: one `Project XML Sync: <action> ...` entry per applied item (`MANAGE`), plus `Enter draft mode`.
- Module log: `XML uploaded`, `Sync applied` (counts, file sha1, backup doc id), `Project XML Sync: component failed`,
  `Diff failed`, configuration changes.

## Hooks Used

- `redcap_module_link_check_display` — show the menu link only to permitted users
- `redcap_module_save_configuration` — configuration audit log

## JavaScript Architecture

`js/sync.js` (no dependencies): per-component select all/none, selected-count, disables Apply when nothing is ticked,
confirmation dialog that highlights removals.
