<?php
/**
 * File: src/Recipient/AudienceResolution.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Recipient;

/**
 * Deterministic result from the audience-policy layer.
 */
final readonly class AudienceResolution {
	/**
	 * Construct one resolution.
	 *
	 * @param array<int,ResolvedAudienceRecipient> $recipients  Eligible recipients.
	 * @param array<string,int>                    $skip_counts Aggregate policy skips.
	 */
	public function __construct(
		private array $recipients,
		private array $skip_counts
	) {
	}

	/**
	 * Return eligible recipients.
	 *
	 * @return array<int,ResolvedAudienceRecipient>
	 */
	public function recipients(): array {
		return $this->recipients;
	}

	/**
	 * Return aggregate skip counts.
	 *
	 * @return array<string,int>
	 */
	public function skip_counts(): array {
		return $this->skip_counts;
	}

	/**
	 * Return one aggregate skip count.
	 *
	 * @param string $reason Stable skip reason.
	 * @return int
	 */
	public function skipped( string $reason ): int {
		return $this->skip_counts[ $reason ] ?? 0;
	}
}

// EOF: src/Recipient/AudienceResolution.php.
