<?php
/**
 * Retention.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Query;
use SigmaRocks\AdvanceHistory\Retention;

/**
 * Pruning old events.
 */
class Test_Retention extends Srah_Test_Case {

	/**
	 * Insert a row a number of days old.
	 *
	 * @param int $days Age in days.
	 * @return int
	 */
	private function aged( $days ) {
		return $this->insert_row( array( 'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	public function test_prune_deletes_rows_older_than_the_period() {
		$old   = $this->aged( 40 );
		$fresh = $this->aged( 5 );
		$this->assertSame( 1, Retention::prune( 30 ) );
		$this->assertNull( Query::find( $old ) );
		$this->assertIsArray( Query::find( $fresh ) );
	}

	public function test_zero_days_keeps_everything() {
		$old = $this->aged( 4000 );
		$this->assertSame( 0, Retention::prune( 0 ) );
		$this->settings( array( 'retention_days' => 0 ) );
		$this->assertSame( 0, Retention::prune() );
		$this->assertIsArray( Query::find( $old ) );
	}

	/**
	 * Cron passes an empty string to one-argument callbacks. The saved setting must still apply.
	 */
	public function test_the_scheduled_hook_uses_the_saved_period() {
		$this->settings( array( 'retention_days' => 30 ) );
		$old   = $this->aged( 40 );
		$fresh = $this->aged( 5 );
		do_action( Retention::HOOK, '' );
		$this->assertNull( Query::find( $old ) );
		$this->assertIsArray( Query::find( $fresh ) );
	}

	public function test_pruning_fires_the_pruned_action() {
		$this->aged( 40 );
		$count = null;
		$spy   = static function ( $deleted ) use ( &$count ) {
			$count = $deleted;
		};
		add_action( 'srah_pruned', $spy );
		Retention::prune( 30 );
		remove_action( 'srah_pruned', $spy );
		$this->assertSame( 1, (int) $count );
	}

	public function test_the_daily_task_is_scheduled() {
		Retention::unschedule();
		Retention::ensure_scheduled();
		$this->assertNotFalse( wp_next_scheduled( Retention::HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Retention::HOOK ) );
	}
}
