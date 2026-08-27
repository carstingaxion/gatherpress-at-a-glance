<?php
/**
 * Adds GatherPress counts to the WordPress "At a Glance" dashboard widget.
 *
 * @package GatherPress_At_A_Glance
 */

declare(strict_types=1);

namespace GatherPress_At_A_Glance;

use GatherPress\Core;
use GatherPress\Core\Rsvp\Query;
use GatherPress\Core\Rsvp\Rsvp;
use GatherPress\Core\Rsvp\Response\Status;
use WP_Comment;
use WP_Post;
use WP_Query;


/**
 * Hooks into `dashboard_glance_items` and appends one line per post type
 * for each GatherPress post-type-support group:
 *
 *  - gatherpress-event-date  → upcoming + past counts, per post type
 *  - gatherpress-venue-information → total published, per post type
 *  - gatherpress-rsvp        → attending + waiting_list counts, per post type
 *
 * Topics (gatherpress_topic taxonomy) are appended as a single term count.
 *
 * Counts link to the relevant admin screen when the current user has the
 * required capability; otherwise the plain number is shown without a link.
 *
 * @since 0.1.0
 */
class Dashboard {

	use Core\Traits\Singleton;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	protected function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Transient TTL in seconds.
	 *
	 * Counts are stored in wp_options-backed transients so they survive
	 * across requests on shared hosting where no persistent object cache
	 * (Redis, Memcached) is available. Five minutes is a reasonable balance
	 * between freshness and avoiding a DB query on every dashboard load.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Object-cache group used for within-request deduplication.
	 *
	 * On sites with a persistent object cache backend this group is also
	 * persistent, so the transient layer is effectively bypassed after the
	 * first warm-up. On sites without one it acts only as a per-request
	 * dedup to avoid firing the same query twice in a single page load.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const CACHE_GROUP = 'gatherpress_at_a_glance';

	/**
	 * Register hooks.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	protected function setup_hooks(): void {
		add_filter( 'dashboard_glance_items', array( $this, 'add_glance_items' ) );
		add_action( 'admin_head-index.php', array( $this, 'print_glance_styles' ) );

		// Invalidate cached counts when event-date or venue posts change status
		// (publish, trash, untrash, future → publish, etc.).
		add_action( 'transition_post_status', array( $this, 'invalidate_on_post_change' ), 10, 3 );

		// Invalidate RSVP counts whenever a status term is assigned
		// (covers every Rsvp::save() path that ends in wp_set_object_terms).
		add_action( 'set_object_terms', array( $this, 'invalidate_on_rsvp_terms' ), 10, 4 );

		// Invalidate RSVP counts when a comment is permanently deleted
		// (covers the no_status path in Rsvp::save() which calls wp_delete_comment).
		add_action( 'deleted_comment', array( $this, 'invalidate_on_rsvp_delete' ), 10, 2 );
	}

	/**
	 * Append GatherPress items to the "At a Glance" list.
	 *
	 * Each item is an HTML string — either an <a> tag (when the user can
	 * access the target screen) or a plain <span> (when they cannot).
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $items Existing glance items.
	 * @return string[] Extended list.
	 */
	public function add_glance_items( array $items ): array {
		// Build our items independently first so we know their count.
		$ours = array();

		// ── 1. Event-date post types: upcoming + past ────────────────────
		foreach ( get_post_types_by_support( 'gatherpress-event-date' ) as $post_type ) {
			$ours = array_merge( $ours, $this->event_date_items( $post_type ) );
		}

		// ── 2. RSVP-supporting post types: attending + waiting_list ──────
		foreach ( get_post_types_by_support( 'gatherpress-rsvp' ) as $post_type ) {
			$ours = array_merge( $ours, $this->rsvp_items( $post_type ) );
		}

		// ── 3. Venue post types: total published ─────────────────────────
		$venue_post_types = get_post_types_by_support( 'gatherpress-venue-information' );
		foreach ( $venue_post_types as $post_type ) {
			$ours[] = $this->venue_item( $post_type );
			if ( 1 === count( $venue_post_types ) % 2 ) {
				// Add a spacer after the last venue item if the total count
				// of glance items is odd, so that the next item starts on the
				// left column. See the comment in add_glance_items() for details.
				$ours[] = '<span class="gp-glance-spacer" aria-hidden="true"></span>';
			}
		}

		// ── 4. Topic taxonomy ────────────────────────────────────────────
		// Commented out until a dashicon is chosen for topics.
		// $topic_item = $this->topic_item();
		// if ( null !== $topic_item ) {
		// $ours[] = $topic_item;
		// }

		if ( empty( $ours ) ) {
			return $items;
		}

		// ── 5. Column-parity spacer ──────────────────────────────────────
		// The widget renders all <li> items in a two-column float layout
		// (each li is width:50%; float:left). An odd total item count means
		// the last item spans the full width alone. To keep our block
		// visually paired, prepend a single invisible spacer item so that
		// our first real item always starts on the left column.
		//
		// Total = WP-core items (computed) + items already in $items from
		// other plugins + our own items.
		$total = $this->count_core_glance_items() + count( $items );
		if ( 1 === $total % 2 ) {
			array_unshift( $ours, '<span class="gp-glance-spacer" aria-hidden="true"></span>' );
		}

		return array_merge( $items, $ours );
	}

	/**
	 * Count the "At a Glance" items that WordPress core will print directly
	 * (before the dashboard_glance_items filter runs).
	 *
	 * WP core prints its own <li> elements — for posts, pages, and comments —
	 * outside the filter, so they are invisible to us inside the filter.
	 * We reproduce the same conditional logic here to get an accurate count.
	 *
	 * The rule: only count items that actually participate in the float layout.
	 * An item with class "hidden" (= display:none) takes no space, so it must
	 * not be counted even though its <li> is present in the DOM.
	 *
	 * Items counted:
	 *  - Published posts  ('post'):  1 when publish > 0  (skipped entirely otherwise)
	 *  - Published pages  ('page'):  1 when publish > 0  (skipped entirely otherwise)
	 *  - Approved comments:          1 when approved > 0 OR moderated > 0
	 *  - Comments in moderation:     1 when moderated > 0
	 *    → WP adds class="hidden" when moderated = 0; display:none removes it
	 *      from the float layout, so we do NOT count it in that case.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of core glance items visible in the float layout.
	 */
	protected function count_core_glance_items(): int {
		$count = 0;

		// Posts and pages: WP skips the <li> entirely when publish count is 0.
		foreach ( array( 'post', 'page' ) as $post_type ) {
			$counts = wp_count_posts( $post_type );
			if ( $counts->publish ) {
				++$count;
			}
		}

		// Comments: WP only prints both items when approved > 0 OR moderated > 0.
		$num_comm = wp_count_comments();
		if ( $num_comm->approved || $num_comm->moderated ) {
			++$count; // comment-count <li> — always visible when this block runs.

			// comment-mod-count <li> gets class="hidden" (display:none) when
			// moderated = 0, so it occupies no space in the float layout.
			// Only count it when it is actually visible.
			if ( $num_comm->moderated ) {
				++$count;
			}
		}

		return $count;
	}

	// -------------------------------------------------------------------------
	// Icon styles
	// -------------------------------------------------------------------------

	/**
	 * Print the inline <style> block that maps each glance item class to its
	 * dashicon unicode codepoint.
	 *
	 * WordPress wraps every `dashboard_glance_items` string in a bare <li>
	 * with no class, so the core pattern of `.post-count a:before` is not
	 * available to us. Instead we put a CSS class directly on the <a> / <span>
	 * element and target it here.
	 *
	 * Icon sources (from wp-includes/css/dashicons.css):
	 *  - Each post type’s `menu_icon` is read at runtime via get_post_type_object()
	 *    and resolved to its dashicon unicode codepoint.
	 *  - RSVPs use \f101 (dashicons-admin-comments), the standard WP comment icon.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function print_glance_styles(): void {
		$rules = array();

		// Event-date and venue post types: derive icon from menu_icon.
		$icon_types = array_merge(
			array_values( get_post_types_by_support( 'gatherpress-event-date' ) ),
			array_values( get_post_types_by_support( 'gatherpress-venue-information' ) )
		);

		foreach ( array_unique( $icon_types ) as $post_type ) {
			$pt_obj = get_post_type_object( $post_type );
			if ( ! $pt_obj ) {
				continue;
			}

			$codepoint = $this->dashicon_codepoint( $pt_obj->menu_icon );
			if ( null === $codepoint ) {
				continue;
			}

			$class   = 'gp-glance-' . sanitize_html_class( $post_type );
			$rules[] = sprintf(
				'#dashboard_right_now li a.%1$s:before,#dashboard_right_now li span.%1$s:before{content:"%2$s";}',
				$class,
				$codepoint
			);
		}

		// RSVPs: always use the standard WP comment icon (\f101).
		foreach ( get_post_types_by_support( 'gatherpress-rsvp' ) as $post_type ) {
			$class   = 'gp-glance-rsvp-' . sanitize_html_class( $post_type );
			$rules[] = sprintf(
				'#dashboard_right_now li a.%1$s:before,#dashboard_right_now li span.%1$s:before{content:"\\f101";}',
				$class
			);
		}

		// Spacer item: suppress the generic dashicon pseudo-element so it
		// occupies its grid cell silently.
		$rules[] = '#dashboard_right_now li:has(span.gp-glance-spacer){visibility:hidden;}';

		echo '<style>' . implode( '', $rules ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Resolve a `menu_icon` value to its dashicon unicode codepoint string.
	 *
	 * Handles the three formats WordPress accepts for `menu_icon`:
	 *  - `'dashicons-<slug>'`  → looks up the slug in the known map.
	 *  - `'data:image/...'`    → SVG data URI; no dashicon, returns null.
	 *  - `'http...'`           → external image URL; returns null.
	 *  - `''` / `null`         → falls back to the generic dashicons-menu icon.
	 *
	 * Only dashicon slugs used by GatherPress core and companion plugins are
	 * listed. Unknown slugs fall through to null (the generic bullet from WP
	 * core CSS will be used instead).
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $menu_icon The `menu_icon` value from the post type object.
	 * @return string|null Unicode codepoint string (e.g. '"\\f484"'), or null.
	 */
	protected function dashicon_codepoint( ?string $menu_icon ): ?string {
		// Known dashicon slug → unicode codepoint map.
		// Source: wp-includes/css/dashicons.css .
		$map = array(
			'dashicons-nametag'        => '\\f484', // gatherpress_event default.
			'dashicons-location'       => '\\f230', // gatherpress_venue default.
			'dashicons-art'            => '\\f309', // gatherpress_play (Productions).
			'dashicons-clock'          => '\\f469', // gatherpress_season (Seasons).
			'dashicons-id'             => '\\f336', // gatherpress_group (Groups).
			'dashicons-calendar-alt'   => '\\f508',
			'dashicons-calendar'       => '\\f145',
			'dashicons-groups'         => '\\f307',
			'dashicons-admin-comments' => '\\f101',
			'dashicons-tag'            => '\\f323',
			'dashicons-category'       => '\\f318',
			'dashicons-admin-post'     => '\\f109',
			'dashicons-admin-page'     => '\\f105',
		);

		if ( empty( $menu_icon ) ) {
			return null;
		}

		// SVG data URIs and external image URLs cannot be used as :before content.
		if ( str_starts_with( $menu_icon, 'data:' ) || str_starts_with( $menu_icon, 'http' ) ) {
			return null;
		}

		return $map[ $menu_icon ] ?? null;
	}

	// -------------------------------------------------------------------------
	// Transient cache helpers
	// -------------------------------------------------------------------------

	/**
	 * Return an integer count from the cache, or false on a miss.
	 *
	 * Checks the in-memory object cache first (free on sites with a
	 * persistent backend; per-request dedup otherwise), then falls back to
	 * the transient (wp_options row, survives across requests on all hosts).
	 * On a transient hit the value is also written back into the object cache
	 * so subsequent calls within the same request skip even the options read.
	 *
	 * @since 0.1.0
	 *
	 * @param string $transient_key Unique transient key for this count.
	 * @return int|false Cached count, or false on a full miss.
	 */
	protected function get_cached_count( string $transient_key ) {
		// 1. Object cache (within-request or persistent backend).
		$cached = wp_cache_get( $transient_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return is_int( $cached ) ? $cached : 0;
		}

		// 2. Transient (wp_options, survives request boundaries).
		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			$cached = is_int( $cached ) ? $cached : 0;
			// Backfill the object cache so the next call in this request is free.
			wp_cache_set( $transient_key, $cached, self::CACHE_GROUP, self::TRANSIENT_TTL ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined
			return $cached;
		}

		return false;
	}

	/**
	 * Store a count in both the transient and the object cache.
	 *
	 * @since 0.1.0
	 *
	 * @param string $transient_key Unique transient key for this count.
	 * @param int    $count         The value to store.
	 * @return void
	 */
	protected function set_cached_count( string $transient_key, int $count ): void {
		set_transient( $transient_key, $count, self::TRANSIENT_TTL );
		wp_cache_set( $transient_key, $count, self::CACHE_GROUP, self::TRANSIENT_TTL ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined
	}

	/**
	 * Return every transient key this plugin writes, derived from the
	 * currently registered post types.
	 *
	 * Enumerated explicitly so invalidation can delete keys by exact name —
	 * wp_options transients have no wildcard delete API.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	protected function transient_keys(): array {
		$keys = array();

		foreach ( get_post_types_by_support( 'gatherpress-event-date' ) as $post_type ) {
			$keys[] = sprintf( 'gp_glance_%s_upcoming', $post_type );
			$keys[] = sprintf( 'gp_glance_%s_past', $post_type );
		}

		foreach ( get_post_types_by_support( 'gatherpress-rsvp' ) as $post_type ) {
			$keys[] = sprintf( 'gp_glance_rsvp_%s_attending', $post_type );
			$keys[] = sprintf( 'gp_glance_rsvp_%s_waiting_list', $post_type );
		}

		return $keys;
	}

	/**
	 * Delete every transient (and object-cache entry) this plugin owns.
	 *
	 * Called from all invalidation hooks. Cheap in practice: it only runs
	 * on actual content changes (post status transitions, RSVP writes),
	 * never on regular page loads.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function delete_transients(): void {
		foreach ( $this->transient_keys() as $key ) {
			delete_transient( $key );
			wp_cache_delete( $key, self::CACHE_GROUP );
		}
	}

	// -------------------------------------------------------------------------
	// Invalidation hooks
	// -------------------------------------------------------------------------

	/**
	 * Invalidate event and venue counts when a post changes status.
	 *
	 * Fires on `transition_post_status`. Only acts when the post type
	 * declares `gatherpress-event-date` or `gatherpress-venue-information`
	 * support, so unrelated post types are ignored at minimal cost.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function invalidate_on_post_change( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $new_status === $old_status ) {
			return;
		}

		$relevant = post_type_supports( $post->post_type, 'gatherpress-event-date' )
			|| post_type_supports( $post->post_type, 'gatherpress-venue-information' );

		if ( ! $relevant ) {
			return;
		}

		$this->delete_transients();
	}

	/**
	 * Invalidate RSVP counts when a status term is assigned to a comment.
	 *
	 * Fires on `set_object_terms`. Guards on the taxonomy being
	 * `_gatherpress_rsvp_status` so term changes on posts or other
	 * comment taxonomies are ignored.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $object_id  Object ID (comment ID for RSVPs).
	 * @param array<int|string>  $terms      Array of term IDs/slugs being set.
	 * @param int[]              $tt_ids     Array of term taxonomy IDs.
	 * @param string             $taxonomy   Taxonomy slug.
	 * @return void
	 */
	public function invalidate_on_rsvp_terms( int $object_id, array $terms, array $tt_ids, string $taxonomy ): void {
		if ( Status::TAXONOMY !== $taxonomy ) {
			return;
		}

		$this->delete_transients();
	}

	/**
	 * Invalidate RSVP counts when a comment is permanently deleted.
	 *
	 * Fires on `deleted_comment`. Guards on the deleted comment being of
	 * type `gatherpress_rsvp` so unrelated comment deletions are ignored.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $comment_id The deleted comment ID.
	 * @param WP_Comment $comment    The deleted comment object.
	 * @return void
	 */
	public function invalidate_on_rsvp_delete( int $comment_id, WP_Comment $comment ): void {
		if ( Rsvp::COMMENT_TYPE !== $comment->comment_type ) {
			return;
		}

		$this->delete_transients();
	}

	// -------------------------------------------------------------------------
	// Event-date items
	// -------------------------------------------------------------------------

	/**
	 * Build upcoming and past glance items for one event-date post type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_type Post type slug.
	 * @return string[] Two HTML strings: upcoming, past.
	 */
	protected function event_date_items( string $post_type ): array {
		$pt_obj = get_post_type_object( $post_type );
		if (
			! $pt_obj ||
			! is_string( $pt_obj->cap->edit_posts ) ||
			! is_string( $pt_obj->labels->name ) ||
			! is_string( $pt_obj->labels->singular_name )
		) {
			return array();
		}

		$can_edit  = current_user_can( $pt_obj->cap->edit_posts );
		$base_url  = admin_url( sprintf( 'edit.php?post_type=%s', $post_type ) );
		$css_class = 'gp-glance-' . sanitize_html_class( $post_type );

		$upcoming_count = $this->count_events( $post_type, 'upcoming' );
		$past_count     = $this->count_events( $post_type, 'past' );

		$plural   = $pt_obj->labels->name;
		$singular = $pt_obj->labels->singular_name;

		$past_text = sprintf(
			/* translators: 1: count, 2: post type label (singular or plural, matching the count) */
			_n(
				'%1$d Past %2$s',
				'%1$d Past %2$s',
				$past_count,
				'gatherpress-at-a-glance'
			),
			number_format_i18n( $past_count ),
			1 === $past_count ? $singular : $plural
		);

		$upcoming_text = sprintf(
			/* translators: 1: count, 2: post type label (singular or plural, matching the count) */
			_n(
				'%1$d Upcoming %2$s',
				'%1$d Upcoming %2$s',
				$upcoming_count,
				'gatherpress-at-a-glance'
			),
			number_format_i18n( $upcoming_count ),
			1 === $upcoming_count ? $singular : $plural
		);

		return array(
			$this->make_item(
				$past_text,
				$can_edit
					? add_query_arg( 'gatherpress_event_query', 'past', $base_url )
					: null,
				$css_class
			),
			$this->make_item(
				$upcoming_text,
				$can_edit
					? add_query_arg( 'gatherpress_event_query', 'upcoming', $base_url )
					: null,
				$css_class
			),
		);
	}

	/**
	 * Count published posts of a given event-date post type split by timing.
	 *
	 * Delegates date filtering to GatherPress core's own pre_get_posts hook
	 * in Event\Query by passing `gatherpress_event_query` as a WP_Query var.
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_type        Post type slug.
	 * @param string $event_query_type 'upcoming' or 'past'.
	 * @return int
	 */
	protected function count_events( string $post_type, string $event_query_type ): int {
		$transient_key = sprintf( 'gp_glance_%s_%s', $post_type, $event_query_type );
		$cached        = $this->get_cached_count( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$query = new WP_Query(
			array(
				'post_type'               => $post_type,
				'post_status'             => 'publish',
				'posts_per_page'          => -1,
				'fields'                  => 'ids',
				'no_found_rows'           => false,
				'update_post_meta_cache'  => false,
				'update_post_term_cache'  => false,
				'gatherpress_event_query' => $event_query_type,
			)
		);

		$count = (int) $query->found_posts;
		$this->set_cached_count( $transient_key, $count );

		return $count;
	}

	// -------------------------------------------------------------------------
	// Venue item
	// -------------------------------------------------------------------------

	/**
	 * Build a published-count glance item for one venue post type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_type Post type slug.
	 * @return string HTML string.
	 */
	protected function venue_item( string $post_type ): string {
		$pt_obj = get_post_type_object( $post_type );
		if (
			! $pt_obj ||
			! is_string( $pt_obj->cap->edit_posts ) ||
			! is_string( $pt_obj->labels->name ) ||
			! is_string( $pt_obj->labels->singular_name )
		) {
			return '';
		}

		$counts    = wp_count_posts( $post_type );
		$count     = is_int( $counts->publish ) ? $counts->publish : 0;
		$can_edit  = current_user_can( $pt_obj->cap->edit_posts );
		$css_class = 'gp-glance-' . sanitize_html_class( $post_type );

		$text = sprintf(
			/* translators: 1: count, 2: post type label (singular or plural, matching the count) */
			_n(
				'%1$d %2$s',
				'%1$d %2$s',
				$count,
				'gatherpress-at-a-glance'
			),
			number_format_i18n( $count ),
			1 === $count ? $pt_obj->labels->singular_name : $pt_obj->labels->name
		);

		return $this->make_item(
			$text,
			$can_edit
				? admin_url( sprintf( 'edit.php?post_type=%s', $post_type ) )
				: null,
			$css_class
		);
	}

	// -------------------------------------------------------------------------
	// RSVP items
	// -------------------------------------------------------------------------

	/**
	 * Build attending and waiting-list glance items for one RSVP post type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_type Post type slug.
	 * @return string[] Two HTML strings: attending, waiting_list.
	 */
	protected function rsvp_items( string $post_type ): array {
		$pt_obj = get_post_type_object( $post_type );
		if (
			! $pt_obj ||
			! is_string( $pt_obj->labels->singular_name )
		) {
			return array();
		}

		$can_moderate = current_user_can( Rsvp::CAPABILITY );
		$rsvp_url     = $can_moderate
			? admin_url( sprintf( 'edit.php?post_type=%s&page=%s', $post_type, Rsvp::COMMENT_TYPE ) )
			: null;
		$css_class    = 'gp-glance-rsvp-' . sanitize_html_class( $post_type );

		$attending    = $this->count_rsvps( $post_type, 'attending' );
		$waiting_list = $this->count_rsvps( $post_type, 'waiting_list' );

		$pt_singular = $pt_obj->labels->singular_name;

		return array(
			$this->make_item(
				sprintf(
					/* translators: 1: count, 2: singular post type label */
					_n(
						'%1$d Attending RSVP (%2$s)',
						'%1$d Attending RSVPs (%2$s)',
						$attending,
						'gatherpress-at-a-glance'
					),
					number_format_i18n( $attending ),
					$pt_singular
				),
				$rsvp_url,
				$css_class
			),
			$this->make_item(
				sprintf(
					/* translators: 1: count, 2: singular post type label */
					_n(
						'%1$d on Waiting List (%2$s)',
						'%1$d on Waiting List (%2$s)',
						$waiting_list,
						'gatherpress-at-a-glance'
					),
					number_format_i18n( $waiting_list ),
					$pt_singular
				),
				$rsvp_url,
				$css_class
			),
		);
	}

	/**
	 * Count RSVPs for one post type filtered by status term.
	 *
	 * Uses Rsvp\Query::get_rsvps() with a tax_query on _gatherpress_rsvp_status
	 * so the exclusion filter is correctly bypassed, matching core behaviour.
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_type    Post type slug.
	 * @param string $status_slug  Term slug: 'attending' or 'waiting_list'.
	 * @return int
	 */
	protected function count_rsvps( string $post_type, string $status_slug ): int {
		$transient_key = sprintf( 'gp_glance_rsvp_%s_%s', $post_type, $status_slug );
		$cached        = $this->get_cached_count( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$rsvp_query = Query::get_instance();
		$count      = $rsvp_query->get_rsvps(
			array(
				'count'     => true,
				'status'    => 'approve',
				'post_type' => $post_type,
				'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => Status::TAXONOMY,
						'field'    => 'slug',
						'terms'    => array( $status_slug ),
					),
				),
			)
		);

		/**
		 * Rsvp\Query::get_rsvps() returns an Array of RSVP comments
		 * or integer count when count parameter is true.
		 * 
		 * @var int $count
		 */
		$this->set_cached_count( $transient_key, $count );

		return $count;
	}

	// -------------------------------------------------------------------------
	// Rendering helper
	// -------------------------------------------------------------------------

	/**
	 * Build a single glance item HTML string.
	 *
	 * WordPress wraps each returned string in a plain <li> with no class.
	 * To get a dashicon ::before, we put $css_class on the inner <a>/<span>
	 * and target it with the inline <style> printed by print_glance_styles().
	 *
	 * When $url is non-null the item is wrapped in an <a> tag; otherwise it
	 * is wrapped in a <span> so WordPress styles it identically but without
	 * an interactive link.
	 *
	 * $text must already be the final, fully formatted string — callers are
	 * responsible for running number_format_i18n()/sprintf() themselves before
	 * calling this method. Keeping formatting at the call site means each
	 * caller's translator string can use as many or as few placeholders as it
	 * actually needs, instead of being constrained to a fixed template shared
	 * by every caller.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $text      Final, already-formatted link/span text.
	 * @param string|null $url       Admin URL, or null to render unlinked.
	 * @param string      $css_class CSS class placed on the <a>/<span> for icon targeting.
	 * @return string HTML string.
	 */
	protected function make_item( string $text, ?string $url, string $css_class = '' ): string {
		$class_attr = $css_class ? sprintf( ' class="%s"', esc_attr( $css_class ) ) : '';

		if ( null !== $url ) {
			return sprintf( '<a href="%s"%s>%s</a>', esc_url( $url ), $class_attr, esc_html( $text ) );
		}

		return sprintf( '<span%s>%s</span>', $class_attr, esc_html( $text ) );
	}
}
