<?php
/**
 * File: src/Audit/AuditEventType.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Audit;

/**
 * Stable audit-event identifiers for administrative list and suppression changes.
 */
enum AuditEventType: string {
	case ListCreated        = 'list_created';
	case ListUpdated        = 'list_updated';
	case ListDeleted        = 'list_deleted';
	case ListMemberAdded    = 'list_member_added';
	case ListMemberRemoved  = 'list_member_removed';
	case SuppressionSet     = 'suppression_set';
	case SuppressionRemoved = 'suppression_removed';
}

// EOF: src/Audit/AuditEventType.php.
