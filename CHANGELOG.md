# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased](https://github.com/carstingaxion/gatherpress-at-a-glance/compare/0.1.0...HEAD)

## [0.1.0](https://github.com/carstingaxion/gatherpress-at-a-glance/compare/0.1.0...0.1.0) - 2026-07-07

- Registers `Dashboard` singleton that hooks `dashboard_glance_items`
- Adds upcoming and past counts for every post type declaring `gatherpress-event-date` support, linked to the filtered admin list when the user has `edit_posts`
- Adds a published count for every post type declaring `gatherpress-venue-information` support, linked to the admin list when the user has `edit_posts`
- Adds a term count for the `gatherpress_topic` taxonomy, linked to the taxonomy admin screen when the user has `manage_terms`
- Adds attending and waiting-list RSVP counts for every post type declaring `gatherpress-rsvp` support via `Rsvp\Query::get_rsvps()` with a `tax_query` on `_gatherpress_rsvp_status`, linked to the RSVP submenu when the user has `moderate_comments`
- All counts cached via `wp_cache_get/set` with a 5-minute TTL to avoid a DB query on every dashboard load
- Labels use `_n()` for singular/plural and `number_format_i18n()` for locale-aware formatting
