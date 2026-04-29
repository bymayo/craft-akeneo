# Release Notes for Akeneo

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
