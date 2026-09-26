<?php
/**
 * File: src/Admin/SubscriberCsvExporter.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;

/**
 * Stream privacy-conscious subscriber CSV rows in limited database batches.
 */
final class SubscriberCsvExporter {
	/**
	 * Rows fetched from the database at one time.
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Construct the exporter.
	 *
	 * @param SubscriberAdminRepository $repository Administration persistence.
	 */
	public function __construct( private SubscriberAdminRepository $repository ) {
	}

	/**
	 * Yield matching subscriber CSV lines without bearer-token hashes.
	 *
	 * @param SubscriberStatus|null $status Optional status filter.
	 * @param string                $search Optional email/name search text.
	 * @return iterable<string>
	 */
	public function lines( ?SubscriberStatus $status, string $search ): iterable {
		yield $this->csv_line(
			array(
				'email',
				'display_name',
				'status',
				'created_at_gmt',
				'confirmed_at_gmt',
				'unsubscribed_at_gmt',
				'consent_text_snapshot',
				'signup_source',
				'source_post_id',
				'updated_at_gmt',
			)
		);

		$after_id = 0;
		do {
			$rows = $this->repository->export_batch(
				$status,
				$search,
				$after_id,
				self::BATCH_SIZE
			);

			foreach ( $rows as $row ) {
				$after_id = max( $after_id, (int) ( $row['id'] ?? 0 ) );
				yield $this->csv_line(
					array(
						$this->safe_cell( $row['email'] ?? '' ),
						$this->safe_cell( $row['display_name'] ?? '' ),
						$this->safe_cell( $row['status'] ?? '' ),
						$this->safe_cell( $row['created_at_gmt'] ?? '' ),
						$this->safe_cell( $row['confirmed_at_gmt'] ?? '' ),
						$this->safe_cell( $row['unsubscribed_at_gmt'] ?? '' ),
						$this->safe_cell( $row['consent_text_snapshot'] ?? '' ),
						$this->safe_cell( $row['signup_source'] ?? '' ),
						$this->safe_cell( $row['source_post_id'] ?? '' ),
						$this->safe_cell( $row['updated_at_gmt'] ?? '' ),
					)
				);
			}
		} while ( self::BATCH_SIZE === count( $rows ) );
	}

	/**
	 * Prefix spreadsheet-formula-looking values before CSV serialization.
	 *
	 * @param mixed $value Exported scalar/null value.
	 * @return string
	 */
	private function safe_cell( mixed $value ): string {
		$text = null === $value ? '' : (string) $value;
		if ( 1 === preg_match( '/\A[\x00-\x20]*[=+\-@]/D', $text ) ) {
			return "'" . $text;
		}

		return $text;
	}

	/**
	 * Serialize one CSV row using RFC-4180 quoting rules and CRLF endings.
	 *
	 * @param array<mixed> $row Row values.
	 * @return string
	 */
	private function csv_line( array $row ): string {
		$cells = array_map(
			static function ( mixed $value ): string {
				$text = (string) $value;
				if ( false === strpbrk( $text, ",\"\r\n" ) ) {
					return $text;
				}

				return '"' . str_replace( '"', '""', $text ) . '"';
			},
			$row
		);

		return implode( ',', $cells ) . "\r\n";
	}
}

// EOF: src/Admin/SubscriberCsvExporter.php.
