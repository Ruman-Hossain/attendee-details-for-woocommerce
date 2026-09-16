<?php
/**
 * Runs when the plugin is DELETED (not merely deactivated).
 *
 * Deliberately removes only this plugin's own settings row.
 * Attendee answers already saved onto orders are left completely untouched —
 * they belong to the order, not to this plugin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'cq_attendee_settings' );
