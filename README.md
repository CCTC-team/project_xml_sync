### Project XML Sync ###

[![Project XML Sync EM Cypress Tests](https://github.com/CCTC-team/project_xml_sync/actions/workflows/cypress-tests.yml/badge.svg)](https://github.com/CCTC-team/project_xml_sync/actions/workflows/cypress-tests.yml)

Trials are usually built and tested on a TEST server and moved to the PROD server by creating a new project from the
Project XML. REDCap can only do that for a *new* project. When a running trial is amended, the changes have to be
copied across by hand: the data dictionary, then data quality rules, events, the instrument-event mapping, repeating
setup, surveys, ASIs, alerts and so on. It is easy to miss something.

Project XML Sync is enabled on the PROD project. You upload the Project XML exported from the TEST copy and the module:

1. **Compares** it with the live project and lists every difference, component by component (fields, instruments,
   events, mapping, repeating setup, DQ rules, surveys, ASIs, survey queue, alerts, form display logic, reports,
   dashboards, DAGs, user roles, project settings). Nothing changes at this point.
2. **Applies** only the items you tick:
   - **Fields and instruments go into Draft Mode.** REDCap's own review, critical-change warnings and approval still
     apply; you submit the draft in the Online Designer as usual.
   - **Everything else is applied directly**, each component in its own database transaction.
3. **Remembers the XML**, so after the draft is approved you re-open the page and apply what depended on the new
   instruments (their event mapping, repeating setup and survey settings).

Items are matched by name (field name, unique event name, rule name, alert title, role name, report title, ...),
never by database id, because ids differ between servers. Event, survey, DAG and attachment references are
translated to this project's own ids when applied.

#### Safety ####

- Removals (items in this project that are not in the XML) are listed but **never ticked by default**.
- Events and arms holding data, roles/DAGs with users or records, and surveys are never deleted. Removed alerts and
  ASIs are **deactivated**, not deleted, so their history is kept.
- Core REDCap production rules are kept for non-administrators (removing event mappings, editing events or the
  repeating setup in production, and the DQ / alerts / user rights / DAG / reports user rights).
- A backup of the project's design (its own Project XML) is stored before every run and can be downloaded with the
  run report from the module page.
- Every applied item is written to the project's Logging page (`Project XML Sync: ...`).

#### Not carried by the Project XML ####

Users and their rights, DAG assignments, External Module settings and the randomization allocation table are not in
the XML and must be checked manually. MyCap, e-Consent / PDF snapshots, report and dashboard folders, randomization
setup, multi-language settings, DDP/Data Mart settings and the protected-email logo are **compared and reported**, but
not applied by this version.

#### Exporting the XML on the TEST server ####

Project Setup > Other Functionality > *Copy or Back Up the Project* (or Project Home > *Download metadata only (XML)*),
choose **metadata only** and tick **every** optional component. Components that were not ticked cannot be compared.

#### System set up ####

Nothing is changed in REDCap core. Enabling the module needs no system set-up.

The single project setting, *Only allow administrators to apply changes*, can be set by administrators. When it is
unchecked, any user with **Project Design & Setup** rights can use the module.

See [docs/DOCUMENTATION.md](docs/DOCUMENTATION.md) for the technical documentation.
