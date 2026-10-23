<?php
/**
 * Compact text change summary.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a short word summary and a bounded line diff.
 *
 * Full post text is not stored. The line diff keeps nearby context only.
 */
class Diff {

	const MAX_INPUT = 20000;
	const MAX_WORDS = 12;
	const MAX_LINES = 180;
	const MAX_CELLS = 36000;
	const MAX_HUNKS = 26;
	const CONTEXT  = 2;
	const LINE_WIDTH = 240;

	/**
	 * Summarize a text change.
	 *
	 * @param string $before Previous text.
	 * @param string $after  New text.
	 * @return array{changed:bool,before_chars:int,after_chars:int,added:array,removed:array}
	 */
	public static function summarize( $before, $after ) {
		$before = self::normalize( $before );
		$after  = self::normalize( $after );

		$result = array(
			'changed'      => $before !== $after,
			'before_chars' => function_exists( 'mb_strlen' ) ? mb_strlen( $before ) : strlen( $before ),
			'after_chars'  => function_exists( 'mb_strlen' ) ? mb_strlen( $after ) : strlen( $after ),
			'added'        => array(),
			'removed'      => array(),
		);

		if ( ! $result['changed'] ) {
			return $result;
		}

		if ( strlen( $before ) > self::MAX_INPUT || strlen( $after ) > self::MAX_INPUT ) {
			return $result;
		}

		$before_counts = array_count_values( self::words( $before ) );
		$after_counts  = array_count_values( self::words( $after ) );

		$result['removed'] = self::difference( $before_counts, $after_counts );
		$result['added']   = self::difference( $after_counts, $before_counts );

		return $result;
	}

	/**
	 * Line diff marked for a GitHub-style view.
	 *
	 * Each item is `add:`, `del:`, `ctx:`, or `note:` plus the line.
	 * `note: truncated` and `note: gap` are display markers, not post text.
	 *
	 * @param string $before Previous text.
	 * @param string $after  New text.
	 * @return array
	 */
	public static function hunks( $before, $after ) {
		$before_lines = self::plain_lines( $before );
		$after_lines  = self::plain_lines( $after );

		if ( $before_lines === $after_lines ) {
			return array();
		}

		if ( count( $before_lines ) > self::MAX_LINES ) {
			$before_lines = array_slice( $before_lines, 0, self::MAX_LINES );
		}
		if ( count( $after_lines ) > self::MAX_LINES ) {
			$after_lines = array_slice( $after_lines, 0, self::MAX_LINES );
		}

		$ops = self::script( $before_lines, $after_lines );
		return self::pack( $ops );
	}

	/**
	 * Read a stored diff line.
	 *
	 * @param string $stored Encoded line.
	 * @return array{0:string,1:string}
	 */
	public static function decode( $stored ) {
		$stored = (string) $stored;
		foreach ( array( 'add', 'del', 'ctx', 'note' ) as $op ) {
			$prefix = $op . ':';
			if ( 0 !== strpos( $stored, $prefix ) ) {
				continue;
			}
			$text = substr( $stored, strlen( $prefix ) );
			if ( '' !== $text && ' ' === $text[0] ) {
				$text = substr( $text, 1 );
			}
			return array( $op, $text );
		}
		return array( 'ctx', $stored );
	}

	/**
	 * Paragraphs and line breaks, without markup.
	 *
	 * @param string $html Raw post content.
	 * @return array
	 */
	private static function plain_lines( $html ) {
		$text = (string) $html;
		$text = preg_replace( '/<\s*br\s*\/?\s*>/i', "\n", $text );
		$text = preg_replace( '/<\/(p|div|h[1-6]|li|tr|blockquote|pre|figcaption)>/i', "\n", $text );
		$text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$parts = explode( "\n", $text );
		$lines = array();

		foreach ( $parts as $part ) {
			$part = preg_replace( '/[ \t]+/', ' ', trim( (string) $part ) );
			$part = trim( (string) $part );
			if ( '' === $part ) {
				continue;
			}
			foreach ( self::chunks( $part ) as $chunk ) {
				$lines[] = $chunk;
				if ( count( $lines ) >= self::MAX_LINES ) {
					return $lines;
				}
			}
		}

		return $lines;
	}

	/**
	 * Break a long paragraph into comparable pieces.
	 *
	 * @param string $line Plain line.
	 * @return array
	 */
	private static function chunks( $line ) {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $line ) : strlen( $line );
		if ( $length <= self::LINE_WIDTH ) {
			return array( $line );
		}

		$words  = preg_split( '/\s+/u', $line );
		$chunks = array();
		$current = '';
		if ( ! is_array( $words ) ) {
			return array( self::clip( $line ) );
		}

		foreach ( $words as $word ) {
			$word = self::clip( (string) $word );
			if ( '' === $word ) {
				continue;
			}
			$next = '' === $current ? $word : $current . ' ' . $word;
			$next_length = function_exists( 'mb_strlen' ) ? mb_strlen( $next ) : strlen( $next );
			if ( '' !== $current && $next_length > self::LINE_WIDTH ) {
				$chunks[] = $current;
				$current  = $word;
				continue;
			}
			$current = $next;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks ? $chunks : array( self::clip( $line ) );
	}

	/**
	 * Limit one stored line.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function clip( $text ) {
		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) && mb_strlen( $text ) > self::LINE_WIDTH ) {
			return mb_substr( $text, 0, self::LINE_WIDTH );
		}
		if ( strlen( $text ) > self::LINE_WIDTH ) {
			return substr( $text, 0, self::LINE_WIDTH );
		}
		return $text;
	}

	/**
	 * Edit script of equal, added, and removed lines.
	 *
	 * @param array $before Old lines.
	 * @param array $after  New lines.
	 * @return array
	 */
	private static function script( array $before, array $after ) {
		$n = count( $before );
		$m = count( $after );
		if ( 0 === $n && 0 === $m ) {
			return array();
		}
		if ( ( $n * $m ) > self::MAX_CELLS ) {
			return self::unaligned( $before, $after );
		}

		$dp = array();
		for ( $i = $n; $i >= 0; $i-- ) {
			$dp[ $i ] = array();
			for ( $j = $m; $j >= 0; $j-- ) {
				if ( $i === $n || $j === $m ) {
					$dp[ $i ][ $j ] = 0;
					continue;
				}
				if ( $before[ $i ] === $after[ $j ] ) {
					$dp[ $i ][ $j ] = $dp[ $i + 1 ][ $j + 1 ] + 1;
					continue;
				}
				$down = $dp[ $i + 1 ][ $j ];
				$right = $dp[ $i ][ $j + 1 ];
				$dp[ $i ][ $j ] = $down >= $right ? $down : $right;
			}
		}

		$ops = array();
		$i   = 0;
		$j   = 0;
		while ( $i < $n && $j < $m ) {
			if ( $before[ $i ] === $after[ $j ] ) {
				$ops[] = array( 'ctx', $before[ $i ] );
				++$i;
				++$j;
				continue;
			}
			if ( $dp[ $i + 1 ][ $j ] >= $dp[ $i ][ $j + 1 ] ) {
				$ops[] = array( 'del', $before[ $i ] );
				++$i;
				continue;
			}
			$ops[] = array( 'add', $after[ $j ] );
			++$j;
		}
		while ( $i < $n ) {
			$ops[] = array( 'del', $before[ $i ] );
			++$i;
		}
		while ( $j < $m ) {
			$ops[] = array( 'add', $after[ $j ] );
			++$j;
		}

		return $ops;
	}

	/**
	 * Changed lines when a full alignment would be too large.
	 *
	 * @param array $before Old lines.
	 * @param array $after  New lines.
	 * @return array
	 */
	private static function unaligned( array $before, array $after ) {
		$after_set  = array_fill_keys( $after, true );
		$before_set = array_fill_keys( $before, true );
		$ops        = array();

		foreach ( $before as $line ) {
			if ( isset( $after_set[ $line ] ) ) {
				continue;
			}
			$ops[] = array( 'del', $line );
			if ( count( $ops ) >= 12 ) {
				break;
			}
		}
		$added = 0;
		foreach ( $after as $line ) {
			if ( isset( $before_set[ $line ] ) ) {
				continue;
			}
			$ops[] = array( 'add', $line );
			++$added;
			if ( $added >= 12 ) {
				break;
			}
		}
		$ops[] = array( 'note', 'truncated' );
		return $ops;
	}

	/**
	 * Keep a little context around each change and stop at the storage cap.
	 *
	 * @param array $ops Edit script.
	 * @return array
	 */
	private static function pack( array $ops ) {
		$out       = array();
		$count     = count( $ops );
		$index     = 0;
		$last_stop = 0;
		$cut       = false;

		while ( $index < $count ) {
			if ( 'note' === $ops[ $index ][0] ) {
				$out[] = self::encode( 'note', $ops[ $index ][1] );
				++$index;
				continue;
			}
			if ( 'ctx' === $ops[ $index ][0] ) {
				++$index;
				continue;
			}

			$start = $index - self::CONTEXT;
			if ( $start < $last_stop ) {
				$start = $last_stop;
			}
			$end = $index;
			while ( $end < $count && 'ctx' !== $ops[ $end ][0] && 'note' !== $ops[ $end ][0] ) {
				++$end;
			}
			$stop  = $end;
			$extra = 0;
			while ( $stop < $count && $extra < self::CONTEXT && 'ctx' === $ops[ $stop ][0] ) {
				++$stop;
				++$extra;
			}

			if ( $out && $start > $last_stop ) {
				if ( count( $out ) >= self::MAX_HUNKS ) {
					$cut = true;
					break;
				}
				$out[] = self::encode( 'note', 'gap' );
			}

			for ( $cursor = $start; $cursor < $stop; $cursor++ ) {
				if ( count( $out ) >= self::MAX_HUNKS ) {
					$cut = true;
					break 2;
				}
				$out[] = self::encode( $ops[ $cursor ][0], $ops[ $cursor ][1] );
			}

			$last_stop = $stop;
			$index     = $stop;
		}

		if ( $cut ) {
			$out[] = self::encode( 'note', 'truncated' );
		}

		return $out;
	}

	/**
	 * Encode one diff row so later whitespace cleanup keeps the marker.
	 *
	 * @param string $op   add, del, ctx, or note.
	 * @param string $text Line text.
	 * @return string
	 */
	private static function encode( $op, $text ) {
		$text = self::clip( (string) $text );
		if ( '' === $text ) {
			return $op . ':';
		}
		return $op . ': ' . $text;
	}

	/**
	 * Strip tags and collapse whitespace.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function normalize( $text ) {
		$text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( (string) $text );
	}

	/**
	 * Split text into comparable words.
	 *
	 * @param string $text Normalized text.
	 * @return array
	 */
	private static function words( $text ) {
		if ( '' === $text ) {
			return array();
		}
		$parts = preg_split( '/\s+/u', $text );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$words = array();
		foreach ( $parts as $part ) {
			$part = function_exists( 'mb_substr' ) ? mb_substr( $part, 0, 40 ) : substr( $part, 0, 40 );
			if ( '' !== $part ) {
				$words[] = $part;
			}
		}
		return $words;
	}

	/**
	 * Words whose count dropped between two bags.
	 *
	 * @param array $from Source counts.
	 * @param array $to   Comparison counts.
	 * @return array
	 */
	private static function difference( $from, $to ) {
		$diff = array();
		foreach ( $from as $word => $count ) {
			$left = $count - ( isset( $to[ $word ] ) ? $to[ $word ] : 0 );
			while ( $left > 0 && count( $diff ) < self::MAX_WORDS ) {
				$diff[] = (string) $word;
				--$left;
			}
			if ( count( $diff ) >= self::MAX_WORDS ) {
				break;
			}
		}
		return $diff;
	}
}
