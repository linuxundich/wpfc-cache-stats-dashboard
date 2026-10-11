# Changelog

All notable changes to this plugin. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versions before
3.0 are only documented in the Git history.

## [Unreleased]

### Changed
- README translated to English; the version notes for 3.0 and 4.0 moved
  from the README into this file.

## [4.1] – 2026-10-06

### Added
- With the plugin LUI Admin UI (linuxundich.de) the cache state is a pill
  “Cache ✓/–” in its shared “Status” column instead of an own column.
- Filter `wpfcs_cache_note` to add text to that pill’s tooltip (used by
  lui-cache-expiry for the expiry time).
- Action `wpfcs_post_cleared` after the row action “Cache leeren”.

## [4.0]

### Added
- "Most read, but not cached" section with throttled warm-up via cron.
- Cache column and row actions (clear cache, warm up) in the post list.
- Coverage for pages, categories, tags, front page and paginated archives.
- History of post coverage over 30 days.
- Warning about full cache purges within the last 24 hours.
- Optional display of PHP slot errors from the error log.

### Changed
- Details collapsed by default, code split into several files,
  `uninstall.php` added.

## [3.0]

### Added
- Post cache coverage, sizes, ages, preload status and the last cache purges.

### Changed
- Statistics load asynchronously and are cached instead of being built on
  every dashboard view; one directory scan instead of five.
- Clearing the cache uses the WPFC interface instead of `shell_exec` and
  WP-CLI, with a confirmation prompt.
- Prefixed function names, jQuery removed.

### Fixed
- After clearing, the whole widget frame was replaced and the button stopped
  working.
- AJAX refresh had no nonce or capability check; the widget showed up (empty)
  for all roles.
