<?php
/**
 * File: src/Delivery/QueueCommand.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Delivery;

use ArgentWolf\PostNotifier\Campaign\CampaignPreparation;
use Closure;
use WP_CLI;

/**
 * WP-CLI commands for system-cron delivery and aggregate queue status.
 *
 * Output contains only aggregate counts and campaign IDs; never addresses.
 */
final class QueueCommand {
	/**
	 * Construct the command.
	 *
	 * @param Closure $worker      Returns QueueWorker.
	 * @param Closure $queue       Returns QueueRepository.
	 * @param Closure $preparation Returns CampaignPreparation.
	 */
	public function __construct(
		private Closure $worker,
		private Closure $queue,
		private Closure $preparation
	) {
	}

	/**
	 * Register the command when WP-CLI is loaded.
	 *
	 * @param self $command Command instance.
	 * @return void
	 */
	public static function register_with_cli( self $command ): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'argentwolf-post-notifier queue', $command );
		}
	}

	/**
	 * Run bounded delivery batches.
	 *
	 * ## OPTIONS
	 *
	 * [--batches=<count>]
	 * : Maximum claims to process (1-100). Default 10.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function run( array $args, array $assoc_args ): void {
		unset( $args );
		$batches = max( 1, min( 100, (int) ( $assoc_args['batches'] ?? 10 ) ) );
		$worker  = ( $this->worker )();
		if ( ! $worker instanceof QueueWorker ) {
			WP_CLI::error( 'Queue worker is unavailable.' );
			return;
		}
		$totals = $worker->run( $batches );
		WP_CLI::success(
			sprintf(
				'claimed=%d submitted=%d retried=%d failed=%d skipped=%d lost=%d',
				$totals['claimed'],
				$totals['submitted'],
				$totals['retried'],
				$totals['failed'],
				$totals['skipped'],
				$totals['lost']
			)
		);
	}

	/**
	 * Show aggregate campaign/queue status.
	 *
	 * @return void
	 */
	public function status(): void {
		$queue = ( $this->queue )();
		if ( ! $queue instanceof QueueRepository ) {
			WP_CLI::error( 'Queue repository is unavailable.' );
			return;
		}
		foreach ( $queue->aggregate_status() as $status => $row ) {
			WP_CLI::line(
				sprintf(
					'%s: campaigns=%d recipients=%d submitted=%d failed=%d skipped=%d',
					$status,
					$row['campaigns'],
					$row['recipients'],
					$row['submitted'],
					$row['failed'],
					$row['skipped']
				)
			);
		}
	}

	/**
	 * Prepare (render, gate, resolve audience, queue) one building campaign.
	 *
	 * ## OPTIONS
	 *
	 * <campaign-id>
	 * : Campaign ID.
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @return void
	 */
	public function prepare( array $args ): void {
		$service = ( $this->preparation )();
		if ( ! $service instanceof CampaignPreparation ) {
			WP_CLI::error( 'Campaign preparation is unavailable.' );
			return;
		}
		$outcome = $service->prepare( absint( $args[0] ?? 0 ) );
		if ( CampaignPreparation::RESULT_QUEUED === $outcome['result'] ) {
			DeliveryScheduler::wake();
		}
		WP_CLI::line( sprintf( 'result=%s code=%s', $outcome['result'], $outcome['code'] ) );
	}
}

// EOF: src/Delivery/QueueCommand.php.
