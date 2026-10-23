<?php
/**
 * Database export: streams SQL using $wpdb, chunk-by-chunk.
 *
 * @package OneClickBackup
 */

defined( 'ABSPATH' ) || exit;

class OCB_DB_Export {

    /** @var resource|false */
    private $fh;
    private string $output_file;

    // ── State keys ────────────────────────────────────────────────────────────

    private static function state_key( string $session_id ): string {
        return 'ocb_db_export_state_' . $session_id;
    }

    public static function init_state( string $session_id ): void {
        global $wpdb;

        $tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );
        // Filter only tables belonging to this WP install prefix.
        $tables = array_filter( $tables, fn( $t ) => strpos( $t, $wpdb->prefix ) === 0 );
        $tables = array_values( $tables );

        $state = [
            'tables'       => $tables,
            'table_index'  => 0,
            'row_offset'   => 0,
            'done'         => false,
        ];
        set_transient( self::state_key( $session_id ), $state, HOUR_IN_SECONDS * 2 );
    }

    public static function get_state( string $session_id ): ?array {
        $state = get_transient( self::state_key( $session_id ) );
        return is_array( $state ) ? $state : null;
    }

    public static function save_state( string $session_id, array $state ): void {
        set_transient( self::state_key( $session_id ), $state, HOUR_IN_SECONDS * 2 );
    }

    public static function clear_state( string $session_id ): void {
        delete_transient( self::state_key( $session_id ) );
    }

    // ── Export a single chunk ─────────────────────────────────────────────────

    /**
     * Appends one chunk of SQL to $output_file.
     * Returns progress info.
     */
    public static function export_chunk( string $session_id, string $output_file ): array {
        global $wpdb;

        $state = self::get_state( $session_id );
        if ( null === $state ) {
            throw new RuntimeException( 'DB export state not found.' );
        }
        if ( $state['done'] ) {
            return [ 'done' => true, 'progress' => 100 ];
        }

        $tables      = $state['tables'];
        $table_index = (int) $state['table_index'];
        $row_offset  = (int) $state['row_offset'];
        $total       = count( $tables );

        if ( $table_index >= $total ) {
            $state['done'] = true;
            self::save_state( $session_id, $state );
            return [ 'done' => true, 'progress' => 100 ];
        }

        $table = $tables[ $table_index ];
        $fh    = fopen( $output_file, 'ab' );
        if ( ! $fh ) {
            throw new RuntimeException( "Cannot open SQL file for writing: {$output_file}" );
        }

        // Write table header once (when offset = 0).
        if ( 0 === $row_offset ) {
            self::write_table_header( $fh, $table );
        }

        // Fetch a batch of rows.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
                OCB_CHUNK_DB_ROWS,
                $row_offset
            ),
            ARRAY_A
        );

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                fwrite( $fh, self::row_to_insert( $table, $row ) );
            }
            $row_offset += count( $rows );
        }

        // Check if this table is complete.
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
        if ( $row_offset >= $count ) {
            fwrite( $fh, "\n" );
            $table_index++;
            $row_offset = 0;
        }

        fclose( $fh );

        $state['table_index'] = $table_index;
        $state['row_offset']  = $row_offset;
        $done                 = ( $table_index >= $total );
        $state['done']        = $done;
        self::save_state( $session_id, $state );

        $progress = $total > 0 ? (int) round( ( $table_index / $total ) * 100 ) : 100;

        return [
            'done'         => $done,
            'progress'     => $progress,
            'table'        => $table,
            'table_index'  => $table_index,
            'total_tables' => $total,
        ];
    }

    // ── SQL generation helpers ────────────────────────────────────────────────

    private static function write_table_header( $fh, string $table ): void {
        global $wpdb;

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create ) {
            return;
        }
        $create_sql = $create[1] ?? '';

        fwrite( $fh, "\n-- Table: `{$table}`\n" );
        fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" );
        fwrite( $fh, $create_sql . ";\n\n" );
    }

    private static function row_to_insert( string $table, array $row ): string {
        global $wpdb;

        $columns = array_map( fn( $c ) => "`{$c}`", array_keys( $row ) );
        $values  = array_map( function ( $v ) use ( $wpdb ) {
            if ( null === $v ) {
                return 'NULL';
            }
            return "'" . $wpdb->_real_escape( $v ) . "'";
        }, array_values( $row ) );

        return 'INSERT INTO `' . $table . '` (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ");\n";
    }

    // ── Write SQL file header / footer ────────────────────────────────────────

    public static function write_file_header( string $output_file ): void {
        global $wpdb;

        $charset   = defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8mb4';
        $collation = defined( 'DB_COLLATE' ) ? DB_COLLATE : '';

        $header  = "-- OneClick Backup SQL Export\n";
        $header .= '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
        $header .= '-- WordPress version: ' . get_bloginfo( 'version' ) . "\n";
        $header .= '-- Site URL: ' . get_option( 'siteurl' ) . "\n";
        $header .= '-- Table prefix: ' . $wpdb->prefix . "\n\n";
        $header .= "SET NAMES '{$charset}';\n";
        $header .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $header .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
        $header .= "SET time_zone='+00:00';\n\n";

        file_put_contents( $output_file, $header );
    }

    public static function write_file_footer( string $output_file ): void {
        file_put_contents( $output_file, "\nSET FOREIGN_KEY_CHECKS=1;\n", FILE_APPEND );
    }
}
