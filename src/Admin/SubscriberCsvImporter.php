<?php
/**
 * File: src/Admin/SubscriberCsvImporter.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationLinkFactory;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use InvalidArgumentException;

/**
 * Bounded administrator CSV intake that preserves standalone double opt-in.
 */
final class SubscriberCsvImporter {
	/**
	 * Maximum nonblank data rows accepted in one synchronous import.
	 */
	public const MAX_ROWS = 250;

	/**
	 * Source marker persisted on pending rows created by CSV intake.
	 */
	public const SIGNUP_SOURCE = 'csv_import';

	/**
	 * Construct the importer.
	 *
	 * @param SubscriberService         $service  Standalone subscriber service.
	 * @param EmailIdentity             $identity Canonical email identity helper.
	 * @param ConfirmationLinkFactory   $links    Local confirmation-link factory.
	 * @param SubscriberCsvImportMailer $mailer   CSV-intake invitation mailer.
	 */
	public function __construct(
		private SubscriberService $service,
		private EmailIdentity $identity,
		private ConfirmationLinkFactory $links,
		private SubscriberCsvImportMailer $mailer
	) {
	}

	/**
	 * Import one already-opened CSV stream.
	 *
	 * The complete bounded row set is parsed before any subscriber mutation or
	 * mail submission. Imported status-like columns are intentionally ignored.
	 * Subscriber persistence failures may propagate as RuntimeException.
	 *
	 * @param resource $stream Readable CSV stream.
	 * @return SubscriberCsvImportResult
	 * @throws InvalidArgumentException When the CSV shape or row bound is invalid.
	 */
	public function import( $stream ): SubscriberCsvImportResult {
		if ( ! is_resource( $stream ) ) {
			throw new InvalidArgumentException( 'A readable CSV stream is required.' );
		}

		$header = fgetcsv( $stream, 0, ',', '"', '' );
		if ( false === $header || null === $header ) {
			throw new InvalidArgumentException( 'The CSV file requires a header row.' );
		}

		$columns = $this->header_columns( $header );
		$rows    = $this->read_rows( $stream );
		$seen    = array();
		$counts  = array(
			'submitted'     => 0,
			'skipped'       => 0,
			'invalid'       => 0,
			'duplicates'    => 0,
			'mail_failures' => 0,
		);

		foreach ( $rows as $row ) {
			$email = $this->cell( $row, $columns['email'] );
			$email = $this->identity->normalize( $email );
			if ( null === $email ) {
				++$counts['invalid'];
				continue;
			}

			if ( isset( $seen[ $email ] ) ) {
				++$counts['duplicates'];
				continue;
			}
			$seen[ $email ] = true;

			$name = null;
			if ( null !== $columns['name'] ) {
				$name = $this->cell( $row, $columns['name'] );
				$name = '' === trim( $name ) ? null : $name;
			}

			$result = $this->service->request_confirmation(
				$email,
				$name,
				$this->consent_text(),
				self::SIGNUP_SOURCE
			);
			$token  = $result->confirmation_token();
			if ( null === $token ) {
				++$counts['skipped'];
				continue;
			}

			$delivery = $this->mailer->send(
				$result->normalized_email(),
				$this->links->create( $token )
			);
			if ( $delivery->is_submitted() ) {
				++$counts['submitted'];
			} else {
				++$counts['mail_failures'];
			}
		}

		return new SubscriberCsvImportResult(
			count( $rows ),
			$counts['submitted'],
			$counts['skipped'],
			$counts['invalid'],
			$counts['duplicates'],
			$counts['mail_failures']
		);
	}

	/**
	 * Return the localized consent text recorded for an imported invitation.
	 *
	 * @return string
	 */
	private function consent_text(): string {
		return __(
			'I agree to receive email notifications when new posts are published.',
			'argentwolf-post-notifier'
		);
	}

	/**
	 * Validate and index supported CSV headings.
	 *
	 * @param array<int,string|null> $header Raw CSV heading row.
	 * @return array{email:int,name:int|null}
	 * @throws InvalidArgumentException When required or ambiguous headings are found.
	 */
	private function header_columns( array $header ): array {
		$normalized = array();
		foreach ( $header as $index => $value ) {
			$value = null === $value ? '' : trim( (string) $value );
			if ( 0 === $index ) {
				$value = preg_replace( '/\A\xEF\xBB\xBF/', '', $value ) ?? $value;
			}
			$normalized[ strtolower( $value ) ] = $index;
		}

		if ( ! isset( $normalized['email'] ) ) {
			throw new InvalidArgumentException( 'The CSV header must contain email.' );
		}
		if ( isset( $normalized['name'], $normalized['display_name'] ) ) {
			throw new InvalidArgumentException(
				'Use either name or display_name, not both.'
			);
		}

		$name = $normalized['name'] ?? $normalized['display_name'] ?? null;
		return array(
			'email' => (int) $normalized['email'],
			'name'  => null === $name ? null : (int) $name,
		);
	}

	/**
	 * Read the bounded nonblank data rows before processing any invitation.
	 *
	 * @param resource $stream Readable CSV stream.
	 * @return array<int,array<int,string|null>>
	 * @throws InvalidArgumentException When the CSV row limit is exceeded.
	 */
	private function read_rows( $stream ): array {
		$rows = array();
		while ( true ) {
			$row = fgetcsv( $stream, 0, ',', '"', '' );
			if ( false === $row ) {
				break;
			}
			if ( array( null ) === $row || $this->row_is_blank( $row ) ) {
				continue;
			}

			$rows[] = $row;
			if ( count( $rows ) > self::MAX_ROWS ) {
				throw new InvalidArgumentException( 'The CSV row limit was exceeded.' );
			}
		}

		return $rows;
	}

	/**
	 * Whether a parsed row contains no non-whitespace text.
	 *
	 * @param array<int,string|null> $row Parsed CSV row.
	 * @return bool
	 */
	private function row_is_blank( array $row ): bool {
		foreach ( $row as $value ) {
			if ( null !== $value && '' !== trim( $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read one optional CSV cell as text.
	 *
	 * @param array<int,string|null> $row   Parsed row.
	 * @param int                    $index Zero-based column index.
	 * @return string
	 */
	private function cell( array $row, int $index ): string {
		$value = $row[ $index ] ?? '';
		return null === $value ? '' : trim( $value );
	}
}

// EOF: src/Admin/SubscriberCsvImporter.php.
