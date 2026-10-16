<?php
/**
 * File: src/Audit/AuditEventType.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Audit;

/**
 * Stable audit-event identifiers for list, suppression, and campaign delivery events.
 */
enum AuditEventType: string {
	case ListCreated        = 'list_created';
	case ListUpdated        = 'list_updated';
	case ListDeleted        = 'list_deleted';
	case ListMemberAdded    = 'list_member_added';
	case ListMemberRemoved  = 'list_member_removed';
	case SuppressionSet     = 'suppression_set';
	case SuppressionRemoved = 'suppression_removed';
	case CampaignQueued     = 'campaign_queued';
	case CampaignFailed     = 'campaign_failed';
	case CampaignCompleted  = 'campaign_completed';
	case RecipientFailed    = 'campaign_recipient_failed';
	case ClickStatsViewed   = 'click_stats_viewed';
	case ClickStatsExported = 'click_stats_exported';
}

// EOF: src/Audit/AuditEventType.php.
