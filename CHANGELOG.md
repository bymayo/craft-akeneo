# Release Notes for Akeneo

## 1.0.14 - 2026-06-22

### Added
- Categories fields can now map groups of Akeneo attributes to a parent category, so imported categories nest beneath the chosen parent. Enable "Add to parent category" to switch the field to a row-based view.

### Fixed
- Fixed an "Invalid Argument" error on the Field Mapping page when a relational field's saved value wasn't valid JSON.

## 1.0.13 - 2026-06-22

### Changed
- Restyled the Product Filter operator dropdown to use Craft's native select component.
- Product Filter fields now show per-operator placeholder hints (e.g. `min,max` for `BETWEEN`, `value1,value2,…` for `IN`) to clarify the expected value format.

## 1.0.12 - 2026-06-22

### Changed
- Tidied nested Matrix/Table field labels.

### Fixed
- Fixed large field maps hanging the page on load.
- Fixed save staying on the page for existing sources.

## 1.0.11 - 2026-06-01

### Added
- Export/import for sources: export a source (with its filters and field mappings) to a JSON file from the source Action menu, and import it via the dropdown next to "New Source" on the Sources index. Section / product type, site, and field references are re-resolved by handle, so it works across environments without using Project Config.
- Duplicate a source (with its field mappings) from a duplicate icon on each row of the Sources index.
- A warning icon now appears when a source has logged sync failures — next to the date in the "Last Synced" column on the Sources index, on the Fail filter in the Log tab, and beside "Last Synced" on the dashboard widget. Clicking it jumps to the failed log entries.

### Changed
- The "Est. Products" count on the Sources index is now cached indefinitely and only refreshes when the source is updated (not on every page load or sync).

## 1.0.10 - 2026-06-01

### Added
- Required fields are now indicated with an asterisk next to the field label in the field mapping table, matching Craft's own field labels. Title and Slug always show as required.

### Fixed
- Widened the `akeneoAttribute` field mapping column to `text` so large Matrix/Table mappings no longer fail to save with a "Data too long" error.

## 1.0.9 - 2026-05-29

### Added
- CKEditor fields are now supported as field mapping targets.
- Field mapping table now groups fields by their field layout tab, separated by a full-width divider.

### Changed
- Field mapping rows now show bold field names with smaller handles consistently across top-level fields, Table columns, and Matrix nested fields.
- Field mapping table now uses a fixed column layout so the Akeneo Attribute dropdown and Static Value fields stay a consistent width; dropdowns and selects now fill their column.
- Darkened the nested mapping table headers and tidied the row hover styling so headers stay distinct on hover.
- Restyled the row delete button as a white bordered button anchored to the top right.
- Product Filters: the operator dropdown is now a fixed width with the attribute fixed at 33% and the value taking the remaining space, and the remove button stays on the right.

### Fixed
- Product Filters now hide the value field for operators that don't take a value (`EMPTY`, `NOT EMPTY`).

## 1.0.8 - 2026-04-29

### Added
- "View Log" entry in the Action dropdown on the Sources index table (previously only on source edit pages).
- Per-product sync logging in `akeneo.log` showing `SKU - Title - Success` or `SKU - Title - Fail: <reason>` for each imported product.
- Log tab on each source showing per-product success/warning/fail records with status filters and a clear-log action.
- "View Log" option in the source Action dropdown to jump straight to the Log tab.

### Changed
- Refactored the Action dropdown into a shared `_sync-menu.twig` partial reused by the index table and source edit pages.
- Added icons to the Sync Data and Sync Images menu items, and to the Sync widget buttons.

## 1.0.7 - 2026-04-29

### Added
- Minor update

## 1.0.6 - 2026-04-29

### Added
- `akeneo/sync/test` console command with optional `--limit` flag for triggering a Test Sync from the terminal.
- `testSyncLimit` setting to configure the default Test Sync batch size (defaults to 10).

## 1.0.5 - 2026-04-29

### Added
- "Test Sync" option that runs a quick sync without touching existing entries via the Orphaned Entry Action.
- Estimated product count column on the Sources index, cached per source and refreshed when the source is saved.
- Friendly "Add your API settings" prompt on the source edit page when API credentials are missing.
- Friendly "Add your API settings" prompt on the dashboard SyncWidget when API credentials are missing.

### Changed
- Existing-asset lookup now searches all subfolders of the Asset Folder, so re-syncs reuse previously imported assets.

### Fixed
- Asset save failures now check the volume for a matching file and reuse the existing Asset record where possible.
- Field resolution respects Craft 5 layout-level handle overrides, fixing spurious "No volume provided" errors after Craft 4 upgrades.
- Dashboard SyncWidget template no longer throws a Twig error when rendered without its expected variables.

## 1.0.4 - 2026-02-18
### Changed
- Settings tabs to use Craft's own tab system

## 1.0.3 - 2026-02-18
### Added
- Custom queue support to run sync jobs on a dedicated queue
- Job Priority and Job TTR (Time To Reserve) settings
- Source ID column to the sources index table

## 1.0.2 - 2026-02-16
### Changed
- Simplified the attribute resolution logic to use the resolveData method for all attribute types

## 1.0.1 - 2026-02-16
### Changed
- Updated the plugin icon on widgets

## 1.0.0
- Initial release
