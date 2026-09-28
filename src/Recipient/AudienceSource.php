<?php
/**
 * File: src/Recipient/AudienceSource.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Recipient;

/**
 * Source that contributed a typed contact to audience resolution.
 */
enum AudienceSource: string {
	case Role      = 'role';
	case NamedList = 'named_list';
	case Explicit  = 'explicit';
}

// EOF: src/Recipient/AudienceSource.php.
