<?php
/**
 * File: src/Admin/SubscriberCsvImportResult.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

/**
 * Aggregate result of one bounded subscriber CSV intake.
 */
final class SubscriberCsvImportResult {
	/**
	 * Construct an immutable import result.
	 *
	 * @param int $rows_total    Nonblank data rows inspected.
	 * @param int $submitted     Confirmation invitations submitted to the transport.
	 * @param int $skipped       Existing/cooldown/suppressed rows not invited.
	 * @param int $invalid       Rows whose email could not be normalized.
	 * @param int $duplicates    Duplicate normalized emails within the CSV.
	 * @param int $mail_failures Confirmation invitations rejected by the transport.
	 */
	public function __construct(
		private int $rows_total,
		private int $submitted,
		private int $skipped,
		private int $invalid,
		private int $duplicates,
		private int $mail_failures
	) {
	}

	/**
	 * Return inspected nonblank row count.
	 *
	 * @return int
	 */
	public function rows_total(): int {
		return $this->rows_total;
	}

	/**
	 * Return confirmation invitations accepted by the transport.
	 *
	 * @return int
	 */
	public function submitted(): int {
		return $this->submitted;
	}

	/**
	 * Return valid rows that did not produce a new invitation.
	 *
	 * @return int
	 */
	public function skipped(): int {
		return $this->skipped;
	}

	/**
	 * Return invalid-email row count.
	 *
	 * @return int
	 */
	public function invalid(): int {
		return $this->invalid;
	}

	/**
	 * Return duplicate normalized-email row count.
	 *
	 * @return int
	 */
	public function duplicates(): int {
		return $this->duplicates;
	}

	/**
	 * Return confirmation invitations rejected by the transport.
	 *
	 * @return int
	 */
	public function mail_failures(): int {
		return $this->mail_failures;
	}
}

// EOF: src/Admin/SubscriberCsvImportResult.php.
