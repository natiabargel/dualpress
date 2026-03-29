<?php
/**
 * Conflict resolution — Last Write Wins strategy.
 *
 * When the same row exists locally and an incoming change arrives for it,
 * this class compares timestamps and decides which version wins.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Conflict
 */
class DualPress_Conflict {

	/**
	 * Map table suffix → timestamp column used for conflict resolution.
	 *
	 * @var array<string,string>
	 */
	const TIMESTAMP_MAP = array(
		'posts'    => 'post_modified_gmt',
		'comments' => 'comment_date_gmt',
	);

	/**
	 * Resolve a conflict between a local row and an incoming change.
	 *
	 * Returns 'apply_incoming' or 'keep_local'.
	 *
	 * @param string     $table_suffix Table name without DB prefix.
	 * @param array      $local_row    Current local row (ARRAY_A).
	 * @param array|null $incoming_row Incoming row data from remote batch.
	 * @param string     $action       Batch action: INSERT, UPDATE, DELETE.
	 * @return string 'apply_incoming' or 'keep_local'
	 */
	public static function resolve( $table_suffix, $local_row, $incoming_row, $action ) {
		// Deletions always win — if the other side deleted it, we apply.
		if ( 'DELETE' === $action ) {
			self::log_conflict( $table_suffix, $local_row, $incoming_row, 'applied_incoming' );
			return 'apply_incoming';
		}

		// If no timestamp column is available, default to applying incoming.
		$ts_column = self::get_timestamp_column( $table_suffix );

		if ( ! $ts_column ) {
			self::log_conflict( $table_suffix, $local_row, $incoming_row, 'applied_incoming' );
			return 'apply_incoming';
		}

		$local_ts    = isset( $local_row[ $ts_column ] ) ? strtotime( $local_row[ $ts_column ] ) : 0;
		$incoming_ts = isset( $incoming_row[ $ts_column ] ) ? strtotime( $incoming_row[ $ts_column ] ) : 0;

		if ( $incoming_ts >= $local_ts ) {
			self::log_conflict( $table_suffix, $local_row, $incoming_row, 'applied_incoming' );
			return 'apply_incoming';
		}

		self::log_conflict( $table_suffix, $local_row, $incoming_row, 'kept_local' );
		return 'keep_local';
	}

	/**
	 * Determine the timestamp column for a table.
	 *
	 * Returns null when no reliable timestamp column is available.
	 *
	 * @param string $table_suffix Table suffix without DB prefix.
	 * @return string|null
	 */
	public static function get_timestamp_column( $table_suffix ) {
		$map = apply_filters( 'dualpress_conflict_timestamp_map', self::TIMESTAMP_MAP );
		return isset( $map[ $table_suffix ] ) ? $map[ $table_suffix ] : null;
	}

	/**
	 * Log a resolved conflict to the conflict log table.
	 *
	 * @param string     $table_suffix Table name without DB prefix.
	 * @param array|null $local_row    Local row snapshot.
	 * @param array|null $incoming_row Incoming row snapshot.
	 * @param string     $resolution   'applied_incoming' or 'kept_local'.
	 */
	public static function log_conflict( $table_suffix, $local_row, $incoming_row, $resolution ) {
		global $wpdb;

		// Derive primary key data from local (or incoming) row.
		$pk_data = self::extract_pk( $table_suffix, $local_row ?? $incoming_row );

		$wpdb->insert(
			DualPress_Database::conflict_table(),
			array(
				'table_name'      => sanitize_text_field( $table_suffix ),
				'primary_key_data'=> wp_json_encode( $pk_data ),
				'local_data'      => $local_row ? wp_json_encode( $local_row ) : null,
				'incoming_data'   => $incoming_row ? wp_json_encode( $incoming_row ) : null,
				'resolution'      => $resolution,
				'resolved_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		DualPress_Logger::warning(
			'conflict_resolved',
			sprintf(
				'Conflict in %s: %s.',
				$table_suffix,
				$resolution
			),
			array(
				'table'      => $table_suffix,
				'pk'         => $pk_data,
				'resolution' => $resolution,
			)
		);
	}

	/**
	 * Extract the primary key columns and values for a given table row.
	 *
	 * @param string $table_suffix Table name without DB prefix.
	 * @param array  $row          Row data.
	 * @return array Associative array of PK column → value.
	 */
	private static function extract_pk( $table_suffix, $row ) {
		$pk_map = array(
			'posts'              => 'ID',
			'postmeta'           => 'meta_id',
			'users'              => 'ID',
			'usermeta'           => 'umeta_id',
			'comments'           => 'comment_ID',
			'commentmeta'        => 'meta_id',
			'options'            => 'option_id',
			'terms'              => 'term_id',
			'term_taxonomy'      => 'term_taxonomy_id',
			'term_relationships' => 'object_id',
		);

		$pk_col = isset( $pk_map[ $table_suffix ] ) ? $pk_map[ $table_suffix ] : 'id';

		if ( isset( $row[ $pk_col ] ) ) {
			return array( $pk_col => $row[ $pk_col ] );
		}

		return array();
	}
}
