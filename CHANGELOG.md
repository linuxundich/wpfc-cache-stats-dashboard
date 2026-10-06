# Changelog

All notable changes to this plugin. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Earlier versions
are only documented in the Git history.

## [Unreleased]

## [4.1] – 2026-10-06

### Added
- With the plugin LUI Admin UI (linuxundich.de) the cache state is a pill
  “Cache ✓/–” in its shared “Status” column instead of an own column.
- Filter `wpfcs_cache_note` to add text to that pill’s tooltip (used by
  lui-cache-expiry for the expiry time).
- Action `wpfcs_post_cleared` after the row action “Cache leeren”.
