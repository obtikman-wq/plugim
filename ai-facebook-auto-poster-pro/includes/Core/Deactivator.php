<?php

namespace AIFB\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {

	public static function deactivate(): void {
		Queue\Processor::clear_events();
		Schedule\ScheduleManager::clear_tick();
		Assistants\Comments::clear();

		global $wpdb;
		$queue = DB::table_queue();
		$wpdb->query( "UPDATE {$queue} SET status = 'pending' WHERE status = 'processing'" );
	}
}
