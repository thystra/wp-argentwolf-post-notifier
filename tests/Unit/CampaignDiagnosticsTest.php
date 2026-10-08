<?php
/**
 * File: tests/Unit/CampaignDiagnosticsTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Campaign\CampaignDiagnostics;
use PHPUnit\Framework\TestCase;

final class CampaignDiagnosticsTest extends TestCase {
	public function test_persistence_failure_uses_only_stable_codes_and_integer_identifiers(): void {
		self::assertSame(
			'[ArgentWolf Post Notifier] event=campaign_reservation_failed site_id=2 post_id=51 code=persistence_error',
			CampaignDiagnostics::failure_line( 2, 51, 'persistence_error' )
		);

		$unsafe = "recipient=person@example.test\nToken=secret error trace";
		$line   = CampaignDiagnostics::failure_line( 2, 51, $unsafe );

		self::assertSame(
			'[ArgentWolf Post Notifier] event=campaign_reservation_failed site_id=2 post_id=51 code=unknown_error',
			$line
		);
		self::assertStringNotContainsString( 'person@example.test', $line );
		self::assertStringNotContainsString( 'secret', $line );
	}

	public function test_success_log_is_an_id_only_reservation_resolution(): void {
		self::assertSame(
			'[ArgentWolf Post Notifier] event=campaign_reservation_resolved site_id=2 post_id=51 campaign_id=11 status=building',
			CampaignDiagnostics::resolved_line( 2, 51, 11 )
		);
	}
}

// EOF: tests/Unit/CampaignDiagnosticsTest.php.
