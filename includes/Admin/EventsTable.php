<?php
namespace SceneCalendar\Admin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use SceneCalendar\Db;
use SceneCalendar\Events;

/**
 * Events list: views (upcoming/past/hidden/duplicates/deleted), search,
 * venue/genre filters, sorting, bulk actions.
 */
final class EventsTable extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'event',
				'plural'   => 'events',
				'ajax'     => false,
			)
		);
	}

	private function view(): string {
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'upcoming'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $view, array( 'upcoming', 'past', 'pending', 'hidden', 'duplicate', 'deleted', 'all' ), true ) ? $view : 'upcoming';
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'title'      => __( 'Title', 'scene-calendar' ),
			'event_date' => __( 'Date', 'scene-calendar' ),
			'venue_name' => __( 'Venue', 'scene-calendar' ),
			'genre'      => __( 'Genre', 'scene-calendar' ),
			'price'      => __( 'Price', 'scene-calendar' ),
			'source'     => __( 'Source', 'scene-calendar' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'title'      => array( 'title', false ),
			'event_date' => array( 'event_date', false ),
			'venue_name' => array( 'venue_name', false ),
			'genre'      => array( 'genre', false ),
		);
	}

	protected function get_views() {
		global $wpdb;
		$table  = Db::events();
		$today  = wp_date( 'Y-m-d' );
		$counts = array(
			'upcoming'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = 'publish' AND event_date >= %s", $today ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'past'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = 'publish' AND event_date < %s", $today ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		) + Events::count_by_status();
		$labels = array(
			'upcoming'  => __( 'Upcoming', 'scene-calendar' ),
			'past'      => __( 'Past', 'scene-calendar' ),
			'pending'   => __( 'Pending review', 'scene-calendar' ),
			'hidden'    => __( 'Hidden', 'scene-calendar' ),
			'duplicate' => __( 'Duplicates', 'scene-calendar' ),
			'deleted'   => __( 'Deleted', 'scene-calendar' ),
		);
		$current = $this->view();
		$views   = array();
		foreach ( $labels as $view => $label ) {
			$views[ $view ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( Menu::url( '', array( 'view' => $view ) ) ),
				$view === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$counts[ $view ] ?? 0
			);
		}
		return $views;
	}

	protected function get_bulk_actions() {
		$view    = $this->view();
		$actions = array();
		if ( in_array( $view, array( 'upcoming', 'past', 'all' ), true ) ) {
			$actions['hide'] = __( 'Hide', 'scene-calendar' );
		}
		if ( in_array( $view, array( 'pending', 'hidden', 'duplicate', 'deleted' ), true ) ) {
			$actions['publish'] = __( 'Publish', 'scene-calendar' );
		}
		if ( 'deleted' !== $view ) {
			$actions['delete'] = __( 'Delete', 'scene-calendar' );
		} else {
			$actions['purge'] = __( 'Delete permanently (may be re-added by the next run)', 'scene-calendar' );
		}
		$actions['reclassify'] = __( 'Re-classify genre on next run', 'scene-calendar' );
		return $actions;
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		global $wpdb;
		$table  = Db::events();
		$venues = $wpdb->get_col( "SELECT DISTINCT venue_name FROM $table ORDER BY venue_name" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$genres = \SceneCalendar\Pipeline\Normalize::GENRES;
		$venue  = isset( $_GET['venue'] ) ? sanitize_text_field( wp_unslash( $_GET['venue'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$genre  = isset( $_GET['genre'] ) ? sanitize_text_field( wp_unslash( $_GET['genre'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="alignleft actions">';
		echo '<select name="venue"><option value="">' . esc_html__( 'All venues', 'scene-calendar' ) . '</option>';
		foreach ( $venues as $v ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( $venue, $v, false ), esc_html( $v ) );
		}
		echo '</select><select name="genre"><option value="">' . esc_html__( 'All genres', 'scene-calendar' ) . '</option>';
		printf( '<option value="__none"%s>%s</option>', selected( $genre, '__none', false ), esc_html__( '(not classified)', 'scene-calendar' ) );
		foreach ( $genres as $g ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $g ), selected( $genre, $g, false ), esc_html( $g ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'scene-calendar' ), '', 'filter_action', false );
		echo '</div>';
	}

	public function prepare_items() {
		global $wpdb;
		$table    = Db::events();
		$per_page = $this->get_items_per_page( 'scenecal_events_per_page', 50 );
		$paged    = $this->get_pagenum();
		$today    = wp_date( 'Y-m-d' );
		$where    = array();
		$params   = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		switch ( $this->view() ) {
			case 'upcoming':
				$where[]  = "status = 'publish' AND event_date >= %s";
				$params[] = $today;
				break;
			case 'past':
				$where[]  = "status = 'publish' AND event_date < %s";
				$params[] = $today;
				break;
			case 'all':
				$where[] = '1=1';
				break;
			default:
				$where[]  = 'status = %s';
				$params[] = $this->view();
		}
		if ( ! empty( $_GET['s'] ) ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) . '%';
			$where[]  = '(title LIKE %s OR venue_name LIKE %s OR lineup LIKE %s OR description LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		if ( ! empty( $_GET['venue'] ) ) {
			$where[]  = 'venue_name = %s';
			$params[] = sanitize_text_field( wp_unslash( $_GET['venue'] ) );
		}
		if ( ! empty( $_GET['genre'] ) ) {
			$genre    = sanitize_text_field( wp_unslash( $_GET['genre'] ) );
			$where[]  = 'genre = %s';
			$params[] = '__none' === $genre ? '' : $genre;
		}
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'event_date';
		$orderby = in_array( $orderby, array( 'title', 'event_date', 'venue_name', 'genre' ), true ) ? $orderby : 'event_date';
		$default = 'past' === $this->view() ? 'DESC' : 'ASC';
		$order   = isset( $_GET['order'] ) && in_array( strtoupper( sanitize_key( $_GET['order'] ) ), array( 'ASC', 'DESC' ), true ) ? strtoupper( sanitize_key( $_GET['order'] ) ) : $default;
		// phpcs:enable

		$sql_where = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $sql_where"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows      = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE $sql_where ORDER BY $orderby $order, start_time ASC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $paged - 1 ) * $per_page ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		$this->items = array_map( array( Events::class, 'hydrate' ), $rows ?: array() );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	public function no_items() {
		esc_html_e( 'No events found.', 'scene-calendar' );
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', $item['id'] );
	}

	private function action_url( string $action, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'scenecal_event_action',
					'do'     => $action,
					'ids[]'  => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'scenecal_event_action'
		);
	}

	protected function column_title( $item ) {
		$edit  = Menu::url( 'event', array( 'id' => $item['id'] ) );
		$title = sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $edit ), esc_html( $item['title'] ) );
		if ( $item['locked'] ) {
			$title .= ' <span class="dashicons dashicons-lock" title="' . esc_attr__( 'Edited by an admin; the scraper will not change it.', 'scene-calendar' ) . '"></span>';
		}
		if ( $item['lineup'] ) {
			$title .= '<div class="scenecal-lineup">' . esc_html( implode( ', ', $item['lineup'] ) ) . '</div>';
		}
		if ( 'duplicate' === $item['status'] && $item['duplicate_of'] ) {
			$title .= '<div class="scenecal-muted">' . sprintf(
				/* translators: %s: link to the kept event */
				esc_html__( 'Duplicate of %s', 'scene-calendar' ),
				'<a href="' . esc_url( Menu::url( 'event', array( 'id' => $item['duplicate_of'] ) ) ) . '">#' . (int) $item['duplicate_of'] . '</a>'
			) . '</div>';
		}

		$actions = array( 'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'scene-calendar' ) ) );
		if ( 'publish' === $item['status'] ) {
			$actions['hide'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->action_url( 'hide', $item['id'] ) ), esc_html__( 'Hide', 'scene-calendar' ) );
		} else {
			$actions['publish'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->action_url( 'publish', $item['id'] ) ), esc_html__( 'Publish', 'scene-calendar' ) );
		}
		if ( 'deleted' !== $item['status'] ) {
			$actions['delete'] = sprintf( '<a href="%s" class="scenecal-confirm-delete">%s</a>', esc_url( $this->action_url( 'delete', $item['id'] ) ), esc_html__( 'Delete', 'scene-calendar' ) );
		}
		if ( $item['ticket_link'] ) {
			$actions['tickets'] = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $item['ticket_link'] ), esc_html__( 'Tickets', 'scene-calendar' ) );
		}
		return $title . $this->row_actions( $actions );
	}

	protected function column_event_date( $item ) {
		$date = wp_date( get_option( 'date_format' ), strtotime( $item['date'] . ' 12:00:00' ), new \DateTimeZone( 'UTC' ) );
		$time = $item['start_time'] ? ' · ' . esc_html( $item['start_time'] ) . ( $item['end_time'] ? '–' . esc_html( $item['end_time'] ) : '' ) : '';
		return esc_html( $date ) . $time;
	}

	protected function column_venue_name( $item ) {
		return esc_html( $item['venue_name'] );
	}

	protected function column_genre( $item ) {
		return '' === $item['genre'] ? '<span class="scenecal-muted">—</span>' : esc_html( $item['genre'] );
	}

	protected function column_price( $item ) {
		return esc_html( $item['ticket_price'] );
	}

	protected function column_source( $item ) {
		$out = esc_html( $item['source'] );
		if ( $item['found_by'] ) {
			$out .= '<div class="scenecal-muted">' . esc_html( $item['found_by'] ) . '</div>';
		}
		return $out;
	}
}
