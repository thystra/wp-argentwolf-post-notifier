<?php
/**
 * File: src/Campaign/CampaignContentSnapshotStore.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Content\EmailContentSelector;
use ArgentWolf\PostNotifier\Content\EmailNamedTemplateLibrary;
use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Editor\ContentMode;
use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;
use UnexpectedValueException;
use wpdb;

/**
 * Store immutable, recipient-neutral campaign rendering inputs.
 *
 * This is deliberately NOT a rendered or deliverable email. Per-recipient
 * unsubscribe and management links are absent, and subject/html/text remain
 * empty until a separately qualified rendering/finalization operation exists.
 */
final class CampaignContentSnapshotStore {
	/** Stored JSON contract version. */
	public const VERSION = 1;

	/** Bound stored JSON to avoid unexpectedly large publication-time writes. */
	private const MAX_JSON_BYTES = 8388608;

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Site-prefix-scoped campaigns table.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Use the active WordPress database unless one is explicitly injected.
	 *
	 * @param wpdb|null $database Database for this site.
	 * @throws LogicException When WordPress has no database connection.
	 */
	public function __construct( ?wpdb $database = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb ?? null;
		}
		if ( ! $database instanceof wpdb ) {
			throw new LogicException( 'WordPress database connection is unavailable.' );
		}
		$this->database = $database;
		$this->table    = TableNames::from_database( $database )->campaigns();
	}

	/**
	 * Atomically freeze one validated input document in an empty building shell.
	 *
	 * Duplicate attempts do not overwrite the first document, even if the later
	 * values differ. A false result means the row is absent, ineligible or was
	 * already frozen; callers must not interpret it as a send-ready campaign.
	 *
	 * @param int                 $campaign_id Existing campaign ID.
	 * @param array<string,mixed> $input       Complete recipient-neutral rendering inputs.
	 * @return bool True only for the process that performed the initial write.
	 * @throws InvalidArgumentException When input violates the snapshot contract.
	 * @throws RuntimeException When the database write fails.
	 */
	public function freeze( int $campaign_id, array $input ): bool {
		if ( $campaign_id < 1 ) {
			throw new InvalidArgumentException( 'Invalid campaign identifier.' );
		}
		$checked = $this->validate( $input );
		try {
			$json = wp_json_encode( $checked, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		} catch ( JsonException $exception ) {
			// Exception chaining is diagnostic, not output to a browser.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( 'Invalid snapshot encoding.', 0, $exception );
		}
		if ( ! is_string( $json ) ) {
			throw new InvalidArgumentException( 'Invalid snapshot encoding.' );
		}
		if ( strlen( $json ) > self::MAX_JSON_BYTES ) {
			throw new InvalidArgumentException( 'Campaign input snapshot is too large.' );
		}

		$query = $this->database->prepare(
			'UPDATE %i SET template_snapshot_json = %s
			WHERE id = %d AND post_id = %d AND content_mode = %s
			AND status = %s AND template_snapshot_json IS NULL
			AND subject = %s AND html_body = %s AND text_body = %s',
			$this->table,
			$json,
			$campaign_id,
			$checked['post_id'],
			$checked['content_mode'],
			CampaignRepository::STATUS_BUILDING,
			'',
			'',
			''
		);

		// Direct query is required for the write-once compare-and-set predicate.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable
		if ( false === $result ) {
			throw new RuntimeException( 'Campaign snapshot persistence failed.' );
		}
		return 1 === $result;
	}

	/**
	 * Read one frozen source document, or null if none has been persisted.
	 *
	 * Invalid JSON and unsupported schemas fail closed. Do not silently derive
	 * a replacement from the current post or changed template options.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string,mixed>|null
	 * @throws UnexpectedValueException When stored snapshot data is corrupt.
	 * @throws InvalidArgumentException When validation detects malformed data (caught and wrapped).
	 */
	public function read( int $campaign_id ): ?array {
		if ( $campaign_id < 1 ) {
			return null;
		}
		$query = $this->database->prepare(
			'SELECT template_snapshot_json FROM %i WHERE id = %d LIMIT 1',
			$this->table,
			$campaign_id
		);
		// Plugin-owned persistence is intentionally queried without an object cache.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->database->get_row( $query, ARRAY_A );
		// phpcs:enable
		if ( null === $row || null === $row['template_snapshot_json'] ) {
			return null;
		}
		if ( strlen( (string) $row['template_snapshot_json'] ) > self::MAX_JSON_BYTES ) {
			throw new UnexpectedValueException(
				'Stored campaign snapshot exceeds its size bound.'
			);
		}
		try {
			$decoded = json_decode(
				(string) $row['template_snapshot_json'],
				true,
				512,
				JSON_THROW_ON_ERROR
			);
			if ( ! is_array( $decoded ) ) {
				throw new InvalidArgumentException( 'Invalid stored campaign snapshot.' );
			}
			$checked = $this->validate( $decoded );
			if ( $checked !== $decoded ) {
				throw new InvalidArgumentException( 'Malformed stored campaign snapshot.' );
			}
			return $checked;
		} catch ( JsonException | InvalidArgumentException $exception ) {
			throw new UnexpectedValueException(
				'Invalid stored campaign snapshot.',
				0,
				// Previous exception is diagnostic, not browser output.
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				$exception
			);
		}
	}

	/**
	 * Validate the closed snapshot schema and canonical text customizations.
	 *
	 * @param array<string,mixed> $input Candidate snapshot.
	 * @return array<string,mixed> Validated, stable-order snapshot.
	 * @throws InvalidArgumentException When a field is missing or unsafe.
	 */
	private function validate( array $input ): array {
		$keys        = array(
			'version',
			'site_id',
			'post_id',
			'template_id',
			'site_name',
			'post_title',
			'post_content',
			'post_excerpt',
			'post_url',
			'content_mode',
			'more_enabled',
			'excerpt_words',
			'template_fields',
		);
		$actual_keys = array_keys( $input );
		sort( $keys );
		sort( $actual_keys );
		if ( $keys !== $actual_keys ) {
			throw new InvalidArgumentException( 'Incomplete campaign input snapshot.' );
		}
		if (
			self::VERSION !== $input['version']
			|| ! is_int( $input['site_id'] )
			|| get_current_blog_id() !== $input['site_id']
			|| ! is_int( $input['post_id'] )
			|| $input['post_id'] < 1
			|| ! is_int( $input['template_id'] )
			|| ! $this->valid_template_id( $input['template_id'] )
			|| ! is_string( $input['content_mode'] )
			|| ! in_array( $input['content_mode'], ContentMode::values(), true )
			|| ! is_bool( $input['more_enabled'] )
			|| ! is_int( $input['excerpt_words'] )
			|| $input['excerpt_words'] < 1
			|| $input['excerpt_words'] > EmailContentSelector::MAX_EXCERPT_WORDS
			|| ! is_array( $input['template_fields'] )
		) {
			throw new InvalidArgumentException( 'Invalid campaign snapshot metadata.' );
		}
		$lengths = array(
			'site_name'    => 1024,
			'post_title'   => 65536,
			'post_content' => 6291456,
			'post_excerpt' => 262144,
			'post_url'     => 4096,
		);
		foreach ( $lengths as $field => $maximum ) {
			if ( ! is_string( $input[ $field ] ) || strlen( $input[ $field ] ) > $maximum ) {
				throw new InvalidArgumentException( 'Invalid campaign snapshot text.' );
			}
		}
		$fields = ( new EmailTemplateSettings() )->validate( $input['template_fields'] );
		if ( $fields !== $input['template_fields'] ) {
			throw new InvalidArgumentException( 'Template snapshot requires canonical text.' );
		}
		if ( ! $this->is_local_url( $input['post_url'] ) ) {
			throw new InvalidArgumentException( 'Snapshot permalink must be local.' );
		}
		return array(
			'version'         => $input['version'],
			'site_id'         => $input['site_id'],
			'post_id'         => $input['post_id'],
			'template_id'     => $input['template_id'],
			'site_name'       => $input['site_name'],
			'post_title'      => $input['post_title'],
			'post_content'    => $input['post_content'],
			'post_excerpt'    => $input['post_excerpt'],
			'post_url'        => $input['post_url'],
			'content_mode'    => $input['content_mode'],
			'more_enabled'    => $input['more_enabled'],
			'excerpt_words'   => $input['excerpt_words'],
			'template_fields' => $fields,
		);
	}

	/**
	 * Recognize only the site default and locally persisted named ID range.
	 *
	 * @param int $id Selected template identifier.
	 * @return bool
	 */
	private function valid_template_id( int $id ): bool {
		return 0 === $id || (
			$id >= EmailNamedTemplateLibrary::FIRST_ID
			&& $id <= EmailNamedTemplateLibrary::LAST_ID
		);
	}

	/**
	 * Require a same-origin HTTP(S) permalink without credentials.
	 *
	 * @param string $url URL to validate.
	 * @return bool
	 */
	private function is_local_url( string $url ): bool {
		if ( '' === $url || preg_match( '/[\x00-\x20\\\\]/', $url ) ) {
			return false;
		}
		$parsed = wp_parse_url( $url );
		$origin = wp_parse_url( home_url( '/' ) );
		return is_array( $parsed )
			&& is_array( $origin )
			&& in_array( strtolower( $parsed['scheme'] ?? '' ), array( 'http', 'https' ), true )
			&& strtolower( $parsed['scheme'] ?? '' ) === strtolower( $origin['scheme'] ?? '' )
			&& strtolower( $parsed['host'] ?? '' ) === strtolower( $origin['host'] ?? '' )
			&& ( $parsed['port'] ?? null ) === ( $origin['port'] ?? null )
			&& ! isset( $parsed['user'] )
			&& ! isset( $parsed['pass'] );
	}
}

// EOF: src/Campaign/CampaignContentSnapshotStore.php.
