<?php
/**
 * Plugin Name:       Orphan Page Detector
 * Plugin URI:        https://github.com/LoveYokado/orphan_page_detector
 * Description:       Detects "orphan pages" that are not linked from any other page on the site.
 * Version:           0.9.0
 * Author:            LoveYokado
 * Author URI:        https://github.com/LoveYokado/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the plugin's admin page under the "Tools" menu.
 */
function opd_add_admin_menu() {
	add_management_page(
		__( 'Orphan Page Detector', 'orphan-page-detector' ), // Page title
		__( 'Orphan Pages', 'orphan-page-detector' ),         // Menu title
		'manage_options',                                     // Capability required
		'orphan-page-detector',                               // Menu slug
		'opd_display_page'                                    // Callback function
	);
}
add_action( 'admin_menu', 'opd_add_admin_menu' );

/**
 * Handles the form submission for CSV download.
 *
 * This function is hooked to 'admin_post_opd_download_csv' and handles security checks before triggering the download.
 */
function opd_handle_csv_download() {
	// Security check: Verify nonce and user capabilities.
	if ( ! isset( $_POST['opd_nonce_field'] ) || ! wp_verify_nonce( sanitize_key( $_POST['opd_nonce_field'] ), 'opd_download_csv' ) ) {
		wp_die( 'Invalid nonce.' );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You do not have sufficient permissions to access this page.' );
	}
	opd_detect_orphan_pages( true ); // Trigger CSV download.
}
add_action('admin_post_opd_download_csv', 'opd_handle_csv_download');

/**
 * Handles the bulk action form submission (e.g., move to draft).
 *
 * This function is hooked to 'admin_post_opd_bulk_action' and processes the selected bulk action on the checked items.
 */
function opd_handle_bulk_action() {
	// 1. Security check: Verify nonce and user capabilities.
	if ( ! isset( $_POST['opd_bulk_nonce_field'] ) || ! wp_verify_nonce( sanitize_key( $_POST['opd_bulk_nonce_field'] ), 'opd_bulk_actions' ) ) {
		wp_die( 'Invalid nonce.' );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You do not have sufficient permissions to perform this action.' );
	}

	// 2. Process the action.
	$post_ids = isset( $_POST['opd_post_ids'] ) ? array_map( 'absint', $_POST['opd_post_ids'] ) : [];
	if ( 'move_to_draft' === $_POST['opd_bulk_action'] && ! empty( $post_ids ) ) {
		foreach ( $post_ids as $post_id ) {
			wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
		}

		// 3. Add a query arg for the success message.
		$redirect_url = add_query_arg(
			[
				'opd_message' => 'draft_success',
				'count'       => count( $post_ids ),
			],
			admin_url( 'tools.php?page=orphan-page-detector' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	// 4. If no valid action was performed, redirect back to the plugin page.
	wp_safe_redirect( admin_url( 'tools.php?page=orphan-page-detector' ) );
	exit;
}
add_action( 'admin_post_opd_bulk_action', 'opd_handle_bulk_action' );

/**
 * Clears the orphan page cache when content is modified.
 *
 * @param int $post_id The ID of the post being updated.
 */
function opd_clear_cache_on_update($post_id) {
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	// Get the currently configured redirect key to clear the correct caches.
	$redirect_key = get_option( 'opd_redirect_key', 'redirect_url' );
	$redirect_key_suffix = ! empty( $redirect_key ) ? '_' . sanitize_key( $redirect_key ) : '';

	delete_transient( 'opd_unlinked_pages_pages_only' . $redirect_key_suffix );
	delete_transient( 'opd_unlinked_pages_all' . $redirect_key_suffix );
}
add_action('save_post', 'opd_clear_cache_on_update');
add_action('wp_trash_post', 'opd_clear_cache_on_update');
add_action('delete_post', 'opd_clear_cache_on_update');
add_action('updated_post_meta', 'opd_clear_cache_on_update');
add_action('deleted_post_meta', 'opd_clear_cache_on_update');

// Clear cache when nav menus are updated.
add_action('wp_update_nav_menu', 'opd_clear_cache_on_update');
/**
 * Renders the main plugin page, including filters, results table, and actions.
 */
function opd_display_page() {
	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Orphan Page Detector', 'orphan-page-detector' ) . '</h1>';

	// Display success/error messages.
	if ( isset( $_GET['opd_message'] ) && 'draft_success' === $_GET['opd_message'] ) {
		$count = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
		echo '<div class="notice notice-success is-dismissible"><p>'
			// translators: %d: number of posts.
			. sprintf( esc_html__( '%d pages moved to draft successfully.', 'orphan-page-detector' ), $count )
			. '</p></div>';
	}

	// Get and sanitize filter settings from the URL query parameters.
	// Nonce check for form submission.
	if ( isset( $_GET['opd_filter_nonce'] ) && wp_verify_nonce( sanitize_key( $_GET['opd_filter_nonce'] ), 'opd_filter_settings' ) ) {
		$redirect_key = isset( $_GET['opd_redirect_key'] ) ? sanitize_text_field( wp_unslash( $_GET['opd_redirect_key'] ) ) : 'redirect_url';
		$old_redirect_key = get_option( 'opd_redirect_key', 'redirect_url' );
		if ( $redirect_key !== $old_redirect_key ) {
			$old_redirect_key_suffix = ! empty( $old_redirect_key ) ? '_' . sanitize_key( $old_redirect_key ) : '';
			delete_transient( 'opd_unlinked_pages_pages_only' . $old_redirect_key_suffix );
			delete_transient( 'opd_unlinked_pages_all' . $old_redirect_key_suffix );
			update_option( 'opd_redirect_key', $redirect_key );
		}
	}
	$settings = [
		'exclude_posts'  => isset( $_GET['opd_exclude_posts'] ) && '1' === $_GET['opd_exclude_posts'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'posts_per_page' => isset( $_GET['opd_posts_per_page'] ) ? absint( $_GET['opd_posts_per_page'] ) : 20, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'redirect_key'   => isset( $_GET['opd_redirect_key'] ) ? sanitize_text_field( wp_unslash( $_GET['opd_redirect_key'] ) ) : get_option( 'opd_redirect_key', 'redirect_url' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'paged'          => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	];

	?>
	<form method="get" action="" class="opd-form">
		<input type="hidden" name="page" value="orphan-page-detector"> <?php // Required to stay on the same admin page. ?>
		<?php wp_nonce_field( 'opd_filter_settings', 'opd_filter_nonce' ); ?>
		<div class="opd-form-controls">
			<div>
				<label>
					<input type="checkbox" name="opd_exclude_posts" value="1" <?php checked( $settings['exclude_posts'], true ); ?>>
					<?php esc_html_e( 'Exclude Posts from scan', 'orphan-page-detector' ); ?>
				</label>
			</div>
			<div>
				<label for="opd_posts_per_page"><?php esc_html_e( 'Items per page:', 'orphan-page-detector' ); ?></label>
				<select name="opd_posts_per_page" id="opd_posts_per_page">
					<option value="20" <?php selected( $settings['posts_per_page'], 20 ); ?>>20</option>
					<option value="50" <?php selected( $settings['posts_per_page'], 50 ); ?>>50</option>
					<option value="100" <?php selected( $settings['posts_per_page'], 100 ); ?>>100</option>
				</select>
			</div>
			<div>
				<label for="opd_redirect_key">
					<?php esc_html_e( 'Redirect Custom Field Key:', 'orphan-page-detector' ); ?>
				</label>
				<input type="text" id="opd_redirect_key" name="opd_redirect_key" value="<?php echo esc_attr( $settings['redirect_key'] ); ?>" placeholder="e.g., redirect_url">
				<p class="description"><?php esc_html_e( 'If you use a custom field for redirects, enter its key here.', 'orphan-page-detector' ); ?></p>
			</div>
			<?php submit_button( __( 'Apply', 'orphan-page-detector' ), 'secondary' ); ?>
		</div>
	</form>
	<?php

	// Get orphan pages. For large sites, consider caching this result using the Transients API.
	$unlinked_pages = opd_get_unlinked_pages( $settings['exclude_posts'], $settings['redirect_key'] );

	opd_display_results_table( $unlinked_pages, $settings ); // Display the results.

	echo '</div>';
}

/**
 * Normalizes a URL for consistent comparison.
 *
 * This function removes the port, query string, and fragment from a URL. It also
 * adds a trailing slash to paths that do not appear to be files (i.e., no extension).
 * @param string|null $url The URL to normalize.
 * @return string|null The normalized URL, or null if the input is invalid.
 */
function opd_normalize_url($url) {
	if (empty($url)) {
		return null;
	}

	$parts = parse_url($url);
	if (!isset($parts['scheme']) || !isset($parts['host'])) {
		return null;
	}

	// Rebuild the URL without the port, query, or fragment.
	$normalized = $parts['scheme'] . '://' . $parts['host'];

	if (isset($parts['path'])) {
		// Ensure the path has a trailing slash.
		$path = urldecode($parts['path']);
		// Only add a trailing slash if the path doesn't look like it's pointing to a file.
		if ( ! pathinfo( $path, PATHINFO_EXTENSION ) ) {
			$path = trailingslashit( $path );
		}
		$normalized .= $path;
	}
	
	return $normalized;
}

/**
 * Retrieves a list of all published, public posts and/or pages.
 *
 * @param bool $exclude_posts Whether to exclude posts from the scan.
 * @return array An associative array mapping normalized URL to post ID.
 */
function opd_get_base_posts( $exclude_posts ) {
	$post_types_to_scan = ['post', 'page'];
	if ($exclude_posts) {
		$post_types_to_scan = ['page'];
	}
	$all_posts_query = new WP_Query([
		'post_type'      => $post_types_to_scan,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true, // Performance optimization.
	]);
	
	$base_posts = [];
	if ($all_posts_query->have_posts()) {
		foreach ($all_posts_query->posts as $post_id) {
			$base_posts[opd_normalize_url(get_permalink($post_id))] = $post_id;
		}
	}
	wp_reset_postdata();
	return $base_posts;
}

/**
 * Retrieves detailed information for a given post ID.
 * This is a helper function to avoid code duplication in the results table and CSV export.
 *
 * @param int $post_id The post ID.
 * @return array An associative array of post details (title, author, dates, etc.).
 */
function opd_get_post_details( $post_id ) {
	$details = [
		'id'         => $post_id,
		'type'       => 'N/A',
		'title'      => 'N/A',
		'published'  => 'N/A',
		'modified'   => 'N/A',
		'categories' => '',
		'tags'       => '',
		'author'     => 'N/A',
	];

	if ( $post_id > 0 && ( $post = get_post( $post_id ) ) ) {
		$post_type_obj    = get_post_type_object( $post->post_type );
		$details['type']  = $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type;
		$details['title'] = $post->post_title;
		$details['published'] = get_the_date( 'Y-m-d H:i', $post );
		$details['modified']  = get_the_modified_date( 'Y-m-d H:i', $post );
		$details['author']    = get_the_author_meta( 'display_name', $post->post_author );

		$categories = get_the_category( $post_id );
		$details['categories'] = ! empty( $categories ) ? implode( ', ', array_map( fn( $c ) => $c->name, $categories ) ) : '';

		$tags = get_the_tags( $post_id );
		$details['tags'] = ! empty( $tags ) ? implode( ', ', array_map( fn( $t ) => $t->name, $tags ) ) : '';
	} elseif ( $post_id > 0 ) {
		// Post ID exists but post object could not be retrieved (e.g., deleted).
		$details['type']  = 'Deleted';
		$details['title'] = 'Post Not Found';
	}

	return $details;
}

/**
 * Scans all site content to find every internal link.
 *
 * @param string $redirect_key The custom field key for redirect URLs.
 * @return string[] An array of unique, normalized internal URLs found on the site.
 */
function opd_get_linked_urls( $redirect_key = 'redirect_url' ) {
	$redirect_key = ! empty( $redirect_key ) ? $redirect_key : 'redirect_url'; // Fallback
	set_time_limit(300); // 5 minutes
	$content_query = new WP_Query([
		'post_type'      => ['post', 'page'], // Always scan from all posts and pages.
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true, // Performance optimization.
	]);

	$linked_urls = [];
	if ($content_query->have_posts()) {
		while ($content_query->have_posts()) {
			$content_query->the_post();
			$content = get_the_content();
			
			// Extract URLs from href, src, and srcset attributes.
			preg_match_all('/(?:href|src|srcset)=["\'](https?:\/\/[^"\']+?|[^:"\']+?)(?:#.*?)?["\']/i', $content, $matches);
			$urls_from_content = $matches[1];
			
			foreach ($urls_from_content as $url) {
				// Convert relative URLs to absolute URLs.
				$absolute_url = opd_relative_to_absolute_url( $url, get_permalink() );
				
				// If the URL is internal, add it to our list of linked URLs.
				if (strpos(opd_normalize_url($absolute_url), opd_normalize_url(get_home_url())) === 0) {
					$linked_urls[] = opd_normalize_url($absolute_url);
				}
			}

			// Also check for a redirect custom field and process it.
			$redirect_url_value = get_post_meta(get_the_ID(), $redirect_key, true);
			if (!empty($redirect_url_value)) {
				$absolute_redirect_url = opd_relative_to_absolute_url( $redirect_url_value, get_permalink() );
				$linked_urls[] = opd_normalize_url($absolute_redirect_url);
			}
		}
	}
	wp_reset_postdata();

	// Add links from navigation menus.
	$menus = wp_get_nav_menus();
	foreach ( $menus as $menu ) {
		$menu_items = wp_get_nav_menu_items( $menu->term_id );
		foreach ( $menu_items as $item ) {
			$url = opd_normalize_url( $item->url );
			if ( $url && strpos( $url, opd_normalize_url( get_home_url() ) ) === 0 ) {
				$linked_urls[] = $url;
			}
		}
	}

	return array_unique( $linked_urls );
}

/**
 * Converts a relative URL to an absolute URL.
 *
 * @param string $url The URL to convert.
 * @param string $base_url The base URL of the current page.
 * @return string The fully qualified, absolute URL.
 */
function opd_relative_to_absolute_url( $url, $base_url ) {
	if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return $url; // It's already an absolute URL.
	}

	if ( strpos( $url, '//' ) === 0 ) {
		// Protocol-relative URL (e.g., //example.com/path).
		return parse_url( $base_url, PHP_URL_SCHEME ) . ':' . $url;
	}

	if ( strpos( $url, '/' ) === 0 ) {
		// Root-relative URL (e.g., /path/to/page).
		return get_home_url( null, $url );
	}

	// Path-relative URL (e.g., path/to/page or ../path).
	return trailingslashit( dirname( $base_url ) ) . $url;
}

/**
 * Detects orphan pages by comparing all posts/pages against all found internal links.
 * Results are cached in a transient to improve performance on subsequent loads.
 *
 * @param bool $exclude_posts Whether to exclude posts from the scan.
 * @param string $redirect_key The custom field key for redirect URLs.
 * @return array An associative array of orphan pages, mapping URL to post ID.
 */
function opd_get_unlinked_pages( $exclude_posts, $redirect_key = 'redirect_url' ) {
	$redirect_key_suffix = ! empty( $redirect_key ) ? '_' . sanitize_key( $redirect_key ) : '';
	$transient_key = 'opd_unlinked_pages_' . ($exclude_posts ? 'pages_only' : 'all') . $redirect_key_suffix;
	$unlinked_pages = get_transient($transient_key);

	if (false === $unlinked_pages) {
		$base_posts     = opd_get_base_posts( $exclude_posts );
		$linked_urls    = opd_get_linked_urls( $redirect_key );
		$base_urls_keys = array_keys($base_posts);
		$unlinked_urls  = array_diff($base_urls_keys, $linked_urls);

		$unlinked_pages = [];
		foreach ( $unlinked_urls as $url ) {
			if ( isset( $base_posts[ $url ] ) ) {
				$unlinked_pages[ $url ] = $base_posts[ $url ];
			}
		}
		// Cache the results for 12 hours.
		set_transient($transient_key, $unlinked_pages, HOUR_IN_SECONDS * 12);
	}
	return $unlinked_pages;
}

/**
 * Generates and outputs a CSV file of orphan pages.
 *
 * @param array $unlinked_pages An associative array of orphan pages [url => id].
 */
function opd_generate_csv( $unlinked_pages ) {
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="orphan-pages-' . date('Y-m-d') . '.csv"');

		$output = fopen('php://output', 'w');
		
		// Header row.
		fputcsv(
			$output,
			[
				__( 'ID', 'orphan-page-detector' ),
				__( 'Type', 'orphan-page-detector' ),
				__( 'URL', 'orphan-page-detector' ),
				__( 'Title', 'orphan-page-detector' ),
				__( 'Published Date', 'orphan-page-detector' ),
				__( 'Modified Date', 'orphan-page-detector' ),
				__( 'Categories', 'orphan-page-detector' ),
				__( 'Tags', 'orphan-page-detector' ),
				__( 'Author', 'orphan-page-detector' ),
			]
		);

		// Data rows.
		foreach ($unlinked_pages as $url => $post_id) {
			$details = opd_get_post_details( $post_id );
			$row = $details;
			$row['url'] = $url; // Add the URL to the row.
			// Ensure the order matches the header.
			$row = [ $row['id'], $row['type'], $row['url'], $row['title'], $row['published'], $row['modified'], $row['categories'], $row['tags'], $row['author'] ];
			fputcsv( $output, array_values( $row ) );
		}
		fclose($output);
		exit;
}

/**
 * Main controller for orphan page detection, primarily for CSV export.
 *
 * @param bool $is_csv_download If true, triggers a CSV download.
 */
function opd_detect_orphan_pages( $is_csv_download = false ) {
	// Get and sanitize filter settings.
	$settings = [
		'exclude_posts'  => isset( $_REQUEST['opd_exclude_posts'] ) && '1' === $_REQUEST['opd_exclude_posts'],
		'posts_per_page' => isset( $_REQUEST['opd_posts_per_page'] ) ? absint( $_REQUEST['opd_posts_per_page'] ) : 20,
		'redirect_key'   => isset( $_REQUEST['opd_redirect_key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['opd_redirect_key'] ) ) : 'redirect_url',
	];

	// Get orphan pages. For large sites, consider caching this result using the Transients API.
	$unlinked_pages = opd_get_unlinked_pages( $settings['exclude_posts'], $settings['redirect_key'] );

	if ( $is_csv_download ) {
		opd_generate_csv( $unlinked_pages );
	}
	// This function is now only for CSV download. The HTML display is handled by opd_display_page.
}

/**
 * Renders the results table with pagination and bulk action controls.
 *
 * @param array $unlinked_pages An associative array of orphan pages [url => id].
 * @param array $settings       An array of display settings (posts_per_page, paged, etc.).
 */
function opd_display_results_table( $unlinked_pages, $settings ) {
	$total_items  = count( $unlinked_pages );
	$current_page = $settings['paged'];
	$total_pages  = ceil( $total_items / $settings['posts_per_page'] );

	$unlinked_pages_paginated = array_slice( $unlinked_pages, ( $current_page - 1 ) * $settings['posts_per_page'], $settings['posts_per_page'], true );

	// translators: %d: number of pages.
	echo '<p>' . sprintf( esc_html__( '%d orphan pages detected.', 'orphan-page-detector' ), $total_items ) . '</p>';

	if ( empty( $unlinked_pages ) ) {
		echo '<p>' . esc_html__( 'No orphan pages found.', 'orphan-page-detector' ) . '</p>';
		return;
	}

	// Only show table and actions if there are results.
	if ( ! empty( $unlinked_pages_paginated ) ) {
		?>
		<style>
			.opd-form-controls {
				display: flex;
				flex-wrap: wrap;
				gap: 20px;
				margin-bottom: 1em;
				align-items: flex-start;
				background: #f6f7f7;
				padding: 15px;
				border-radius: 4px;
				border: 1px solid #c3c4c7;
			}
			.opd-form-controls > div { margin-bottom: 10px; }
			.opd-form-controls .description { font-size: 12px; color: #646970; margin-top: 4px; }
			.opd-form-controls .button-secondary { align-self: center; }
			.opd-pagination { margin-top: 20px; }
			.opd-pagination .page-numbers {
				display: inline-block;
				padding: 5px 10px;
				margin: 0 2px;
				border: 1px solid #ccc;
				text-decoration: none;
			}
			.opd-pagination .page-numbers:hover {
				background: #007cba;
				color: #fff;
				border-color: #007cba;
			}
			.opd-pagination .current { font-weight: bold; background-color: #f0f0f1; }
            .orphan-pages-table { width: 100%; border-collapse: collapse; margin-top: 20px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .orphan-pages-table th, .orphan-pages-table td { border: 1px solid #c3c4c7; padding: 8px 12px; text-align: left; vertical-align: top; }
			.orphan-pages-table .col-type { width: 8em; }
			.orphan-pages-table .col-id { width: 5em; }
			.orphan-pages-table .col-date { width: 12em; }
        </style>
		<div class="opd-action-forms" style="display: flex; justify-content: flex-end; align-items: flex-start;">
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="opd-csv-form">
				<?php wp_nonce_field( 'opd_download_csv', 'opd_nonce_field' ); ?>
				<input type="hidden" name="action" value="opd_download_csv">
				<input type="hidden" name="opd_exclude_posts" value="<?php echo $settings['exclude_posts'] ? '1' : '0'; ?>">
				<input type="hidden" name="opd_redirect_key" value="<?php echo esc_attr( $settings['redirect_key'] ); ?>">
				<?php submit_button( __( 'Download Results as CSV', 'orphan-page-detector' ), 'secondary' ); ?>
			</form>
		</div>

		<?php // Bulk actions form wraps the entire table ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="opd-bulk-action-form">
			<?php wp_nonce_field( 'opd_bulk_actions', 'opd_bulk_nonce_field' ); ?>
			<input type="hidden" name="action" value="opd_bulk_action">
			<div class="opd-form-controls" style="margin-top: 0; margin-bottom: 1em;">
				<select name="opd_bulk_action">
					<option value="-1"><?php esc_html_e( 'Bulk actions', 'orphan-page-detector' ); ?></option>
					<option value="move_to_draft"><?php esc_html_e( 'Move to Draft', 'orphan-page-detector' ); ?></option>
				</select>
				<?php submit_button( __( 'Apply', 'orphan-page-detector' ), 'primary', 'opd_bulk_apply', false ); ?>
			</div>




        <table class="orphan-pages-table">
            <thead>
                <tr>
					<th class="col-checkbox" style="width: 3em;"><input type="checkbox" id="opd-select-all"></th>
                    <th class="col-id" style="width: 5em;"><?php esc_html_e( 'ID', 'orphan-page-detector' ); ?></th>
                    <th class="col-type"><?php esc_html_e( 'Type', 'orphan-page-detector' ); ?></th>
                    <th class="col-url"><?php esc_html_e( 'Orphan Page URL', 'orphan-page-detector' ); ?></th>
                    <th class="col-title"><?php esc_html_e( 'Title', 'orphan-page-detector' ); ?></th>
                    <th class="col-date"><?php esc_html_e( 'Published', 'orphan-page-detector' ); ?></th>
                    <th class="col-date"><?php esc_html_e( 'Modified', 'orphan-page-detector' ); ?></th>
                    <th class="col-category"><?php esc_html_e( 'Categories', 'orphan-page-detector' ); ?></th>
                    <th class="col-tag"><?php esc_html_e( 'Tags', 'orphan-page-detector' ); ?></th>
                    <th class="col-author"><?php esc_html_e( 'Author', 'orphan-page-detector' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unlinked_pages_paginated as $url => $post_id) : ?>
                    <?php $details = opd_get_post_details( $post_id ); ?>
                    <tr>
						<td class="col-checkbox"><input type="checkbox" name="opd_post_ids[]" value="<?php echo esc_attr( $post_id ); ?>" class="opd-item-checkbox"></td>
                        <td class="col-id"><?php echo esc_html($post_id); ?></td>
                        <td class="col-type"><?php echo esc_html( $details['type'] ); ?></td>
                        <td class="col-url"><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_url($url); ?></a></td>
                        <td class="col-title"><?php echo esc_html( $details['title'] ); ?></td>
                        <td class="col-date"><?php echo esc_html( $details['published'] ); ?></td>
                        <td class="col-date"><?php echo esc_html( $details['modified'] ); ?></td>
                        <td class="col-category"><?php echo esc_html( $details['categories'] ); ?></td>
                        <td class="col-tag"><?php echo esc_html( $details['tags'] ); ?></td>
                        <td class="col-author"><?php echo esc_html( $details['author'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
		</form> 
		<div class="opd-pagination">
			<?php
				$base_url = admin_url( 'tools.php' );
				echo paginate_links([
					'base' => add_query_arg( [
						'page'               => 'orphan-page-detector',
						'paged'              => '%#%',
						'opd_exclude_posts'  => $settings['exclude_posts'] ? '1' : '0',
						'opd_posts_per_page' => $settings['posts_per_page'],
						'opd_redirect_key'   => $settings['redirect_key'], // This might be redundant if we use the option.
						'opd_filter_nonce'   => wp_create_nonce( 'opd_filter_settings' ),
					], $base_url ),
					'format' => '',
					'prev_text' => __('&laquo;'),
					'next_text' => __('&raquo;'),
					'total' => $total_pages,
					'current' => $current_page,
				]);
			?>
		</div>
		<script>
			document.addEventListener('DOMContentLoaded', function() {
				const selectAll = document.getElementById('opd-select-all');
				const checkboxes = document.querySelectorAll('.opd-item-checkbox');

				if (selectAll) {
					selectAll.addEventListener('change', function() {
						checkboxes.forEach(function(checkbox) {
							checkbox.checked = selectAll.checked;
						});
					});
				}
			});
		</script>
		<?php
	}
}