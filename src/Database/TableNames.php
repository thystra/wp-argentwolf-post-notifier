<?php
/**
 * File: src/Database/TableNames.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Database;

use wpdb;

/**
 * Resolve plugin-owned table names for the active site prefix.
 */
final class TableNames {
	/**
	 * Construct the table-name resolver.
	 *
	 * @param string $prefix Active WordPress table prefix.
	 */
	public function __construct( private string $prefix ) {
	}

	/**
	 * Build a resolver from the active database connection.
	 *
	 * @param wpdb $database WordPress database connection.
	 * @return self
	 */
	public static function from_database( wpdb $database ): self {
		return new self( $database->prefix );
	}

	/**
	 * Campaign table.
	 *
	 * @return string
	 */
	public function campaigns(): string {
		return $this->prefix . 'argentwolf_pn_campaigns';
	}

	/**
	 * Campaign-recipient table.
	 *
	 * @return string
	 */
	public function campaign_recipients(): string {
		return $this->prefix . 'argentwolf_pn_campaign_recipients';
	}

	/**
	 * Standalone-subscriber table.
	 *
	 * @return string
	 */
	public function subscribers(): string {
		return $this->prefix . 'argentwolf_pn_subscribers';
	}

	/**
	 * Named-list table.
	 *
	 * @return string
	 */
	public function lists(): string {
		return $this->prefix . 'argentwolf_pn_lists';
	}

	/**
	 * Typed list-membership table.
	 *
	 * @return string
	 */
	public function list_members(): string {
		return $this->prefix . 'argentwolf_pn_list_members';
	}

	/**
	 * Global-suppression table.
	 *
	 * @return string
	 */
	public function suppressions(): string {
		return $this->prefix . 'argentwolf_pn_suppressions';
	}

	/**
	 * Click-event table.
	 *
	 * @return string
	 */
	public function clicks(): string {
		return $this->prefix . 'argentwolf_pn_clicks';
	}

	/**
	 * Return all plugin-owned tables keyed by logical name.
	 *
	 * @return array<string,string>
	 */
	public function all(): array {
		return array(
			'campaigns'           => $this->campaigns(),
			'campaign_recipients' => $this->campaign_recipients(),
			'subscribers'         => $this->subscribers(),
			'lists'               => $this->lists(),
			'list_members'        => $this->list_members(),
			'suppressions'        => $this->suppressions(),
			'clicks'              => $this->clicks(),
		);
	}
}

// EOF: src/Database/TableNames.php.
