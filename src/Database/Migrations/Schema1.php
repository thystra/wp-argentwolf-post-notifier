<?php
/**
 * File: src/Database/Migrations/Schema1.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Database\Migrations;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\Migration;
use ArgentWolf\PostNotifier\Database\SchemaInspector;
use ArgentWolf\PostNotifier\Database\TableNames;
use RuntimeException;
use wpdb;

/**
 * Initial custom-table schema.
 */
final class Schema1 implements Migration {
	/**
	 * Construct the schema migration.
	 *
	 * @param wpdb $database WordPress database connection.
	 */
	public function __construct( private wpdb $database ) {
	}

	/**
	 * Create the initial plugin-owned tables and email hash key.
	 *
	 * @return void
	 */
	public function up(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$tables          = TableNames::from_database( $this->database );
		$charset_collate = $this->database->get_charset_collate();

		$statements = array(
			$this->campaigns_sql( $tables->campaigns(), $charset_collate ),
			$this->campaign_recipients_sql( $tables->campaign_recipients(), $charset_collate ),
			$this->subscribers_sql( $tables->subscribers(), $charset_collate ),
			$this->lists_sql( $tables->lists(), $charset_collate ),
			$this->list_members_sql( $tables->list_members(), $charset_collate ),
			$this->suppressions_sql( $tables->suppressions(), $charset_collate ),
			$this->clicks_sql( $tables->clicks(), $charset_collate ),
		);

		foreach ( $statements as $statement ) {
			dbDelta( $statement );
		}

		$this->assert_schema( $tables );
		EmailIdentity::ensure_hash_key();
	}

	/**
	 * Verify dbDelta produced the required tables and indexes before the schema
	 * option is allowed to advance.
	 *
	 * @param TableNames $tables Plugin table names.
	 * @return void
	 * @throws RuntimeException When a required table or index is missing.
	 */
	private function assert_schema( TableNames $tables ): void {
		$inspector = new SchemaInspector( $this->database );
		$required  = array(
			$tables->campaigns()           => array(
				'PRIMARY',
				'uuid',
				'campaign_key',
				'post_id',
				'status',
				'created_at_gmt',
			),
			$tables->campaign_recipients() => array(
				'PRIMARY',
				'recipient_uuid',
				'campaign_email',
				'campaign_status',
				'queue_ready',
				'lease_expires_at_gmt',
				'user_id',
				'subscriber_id',
			),
			$tables->subscribers()         => array(
				'PRIMARY',
				'uuid',
				'email_hash',
				'status',
				'confirmation_expires_at_gmt',
				'source_post_id',
			),
			$tables->lists()               => array( 'PRIMARY', 'uuid', 'name', 'created_by' ),
			$tables->list_members()        => array(
				'PRIMARY',
				'typed_membership',
				'user_id',
				'subscriber_id',
			),
			$tables->suppressions()        => array(
				'PRIMARY',
				'email_hash',
				'reason',
				'updated_at_gmt',
			),
			$tables->clicks()              => array(
				'PRIMARY',
				'campaign_recipient_id',
				'clicked_at_gmt',
			),
		);

		foreach ( $required as $table => $indexes ) {
			if ( ! $inspector->table_exists( $table ) ) {
				// Internal diagnostic; this exception message is not rendered as HTML.
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new RuntimeException(
					sprintf( 'Required database table is missing: %s.', $table )
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			$actual_indexes = $inspector->indexes( $table );
			foreach ( $indexes as $index ) {
				if ( ! in_array( $index, $actual_indexes, true ) ) {
					// Internal diagnostic; this exception message is not rendered as HTML.
					// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
					throw new RuntimeException(
						sprintf(
							'Required index %s is missing from %s.',
							$index,
							$table
						)
					);
					// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
			}
		}
	}

	/**
	 * Campaign table SQL.
	 *
	 * @param string $table Table name.
	 * @param string $charset_collate Database charset/collation clause.
	 * @return string
	 */
	private function campaigns_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			campaign_key varchar(191) NOT NULL,
			campaign_kind varchar(32) NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			post_modified_gmt_snapshot datetime DEFAULT NULL,
			status varchar(32) NOT NULL,
			subject text NOT NULL,
			html_body longtext NOT NULL,
			text_body longtext NOT NULL,
			template_snapshot_json longtext NULL,
			audience_snapshot_json longtext NULL,
			content_mode varchar(32) NOT NULL,
			created_at_gmt datetime NOT NULL,
			queued_at_gmt datetime DEFAULT NULL,
			started_at_gmt datetime DEFAULT NULL,
			completed_at_gmt datetime DEFAULT NULL,
			cancelled_at_gmt datetime DEFAULT NULL,
			recipient_count bigint(20) unsigned NOT NULL DEFAULT 0,
			submitted_count bigint(20) unsigned NOT NULL DEFAULT 0,
			failed_count bigint(20) unsigned NOT NULL DEFAULT 0,
			skipped_count bigint(20) unsigned NOT NULL DEFAULT 0,
			unique_click_count bigint(20) unsigned NOT NULL DEFAULT 0,
			total_click_count bigint(20) unsigned NOT NULL DEFAULT 0,
			last_error_code varchar(191) DEFAULT NULL,
			last_error_message text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY campaign_key (campaign_key),
			KEY post_id (post_id),
			KEY status (status),
			KEY created_at_gmt (created_at_gmt)
		) {$charset_collate};";
	}

	/**
	 * Campaign-recipient table SQL.
	 *
	 * @param string $table Table name.
	 * @param string $charset_collate Database charset/collation clause.
	 * @return string
	 */
	private function campaign_recipients_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL,
			recipient_uuid char(36) NOT NULL,
			recipient_type varchar(20) NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			subscriber_id bigint(20) unsigned DEFAULT NULL,
			email_snapshot varchar(320) NOT NULL,
			email_hash char(64) NOT NULL,
			display_name_snapshot varchar(191) DEFAULT NULL,
			status varchar(32) NOT NULL,
			skip_reason varchar(64) DEFAULT NULL,
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			next_attempt_at_gmt datetime DEFAULT NULL,
			claimed_at_gmt datetime DEFAULT NULL,
			lease_expires_at_gmt datetime DEFAULT NULL,
			submitted_at_gmt datetime DEFAULT NULL,
			failed_at_gmt datetime DEFAULT NULL,
			first_clicked_at_gmt datetime DEFAULT NULL,
			last_clicked_at_gmt datetime DEFAULT NULL,
			click_count bigint(20) unsigned NOT NULL DEFAULT 0,
			last_error_code varchar(191) DEFAULT NULL,
			last_error_message text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY recipient_uuid (recipient_uuid),
			UNIQUE KEY campaign_email (campaign_id,email_hash),
			KEY campaign_status (campaign_id,status),
			KEY queue_ready (status,next_attempt_at_gmt),
			KEY lease_expires_at_gmt (lease_expires_at_gmt),
			KEY user_id (user_id),
			KEY subscriber_id (subscriber_id)
		) {$charset_collate};";
	}

	/**
	 * Subscriber table SQL.
	 *
	 * @param string $table Table name.
	 * @param string $charset_collate Database charset/collation clause.
	 * @return string
	 */
	private function subscribers_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			email varchar(320) NOT NULL,
			email_hash char(64) NOT NULL,
			display_name varchar(191) DEFAULT NULL,
			status varchar(32) NOT NULL,
			confirmation_token_hash char(64) DEFAULT NULL,
			confirmation_expires_at_gmt datetime DEFAULT NULL,
			manage_token_hash char(64) DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			confirmed_at_gmt datetime DEFAULT NULL,
			unsubscribed_at_gmt datetime DEFAULT NULL,
			last_confirmation_sent_at_gmt datetime DEFAULT NULL,
			consent_text_snapshot text NULL,
			signup_source varchar(64) DEFAULT NULL,
			source_post_id bigint(20) unsigned DEFAULT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY email_hash (email_hash),
			KEY status (status),
			KEY confirmation_expires_at_gmt (confirmation_expires_at_gmt),
			KEY source_post_id (source_post_id)
		) {$charset_collate};";
	}

	/**
	 * Named-list table SQL.
	 *
	 * @param string $table Table name.
	 * @param string $charset_collate Database charset/collation clause.
	 * @return string
	 */
	private function lists_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			name varchar(191) NOT NULL,
			description text NULL,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY name (name),
			KEY created_by (created_by)
		) {$charset_collate};";
	}

	/**
	 * Typed list-membership table SQL.
	 *
	 * The member_key value is a canonical application-generated identity such as
	 * user:123 or subscriber:456. It avoids MySQL NULL semantics weakening the
	 * unique typed-membership constraint.
	 *
	 * @param string $table Table name.
	 * @param string $charset_collate Database charset/collation clause.
	 * @return string
	 */
	private function list_members_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			list_id bigint(20) unsigned NOT NULL,
			member_type varchar(20) NOT NULL,
			member_key varchar(191) NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			subscriber_id bigint(20) unsigned DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY typed_membership (list_id,member_key),
			KEY user_id (user_id),
			KEY subscriber_id (subscriber_id)
		) {$charset_collate};";
	}

	/**
	 * Suppression table SQL.
	 *
	 * @param string $table Table name.
	 * @param string $charset_collate Database charset/collation clause.
	 * @return string
	 */
	private function suppressions_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email_hash char(64) NOT NULL,
			email_snapshot_or_redacted varchar(320) DEFAULT NULL,
			reason varchar(64) NOT NULL,
			source varchar(64) NOT NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email_hash (email_hash),
			KEY reason (reason),
			KEY updated_at_gmt (updated_at_gmt)
		) {$charset_collate};";
	}

	/**
	 * Click-event table SQL.
	 *
	 * @param string $table Table name.
	 * @param string $charset_collate Database charset/collation clause.
	 * @return string
	 */
	private function clicks_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_recipient_id bigint(20) unsigned NOT NULL,
			clicked_at_gmt datetime NOT NULL,
			destination_kind varchar(64) NOT NULL,
			PRIMARY KEY  (id),
			KEY campaign_recipient_id (campaign_recipient_id),
			KEY clicked_at_gmt (clicked_at_gmt)
		) {$charset_collate};";
	}
}

// EOF: src/Database/Migrations/Schema1.php.
