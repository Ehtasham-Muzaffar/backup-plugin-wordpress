<?php
/**
 * Database import: reads SQL file chunk-by-chunk and executes statements.
 * Also handles prefix renaming and URL replacement.
 *
 * @package OneClickBackup
 */

defined( 'ABSPATH' ) || exit;

class OCB_DB_Import {

    // ── State ─────────────────────────────────────────────────────────────────

    private static function state_key( string $session_id ): string {
        return 'ocb_db_import_state_' . $session_id;
    }

    public static function init_state( string $session_id, string $sql_file ): void {
        $state = [
            'sql_file'   => $sql_file,
            'byte_offset' => 0,
            'done'        => false,
        ];
        set_transient( self::state_key( $session_id ), $state, HOUR_IN_SECONDS * 3 );
    }

    public static function get_state( string $session_id ): ?array {
        $s = get_transient( self::state_key( $session_id ) );
        return is_array( $s ) ? $s : null;
    }

    public static function save_state( string $session_id, array $state ): void {
        set_transient( self::state_key( $session_id ), $state, HOUR_IN_SECONDS * 3 );
    }

    public static function clear_state( string $session_id ): void {
        delete_transient( self::state_key( $session_id ) );
    }

    // ── Import a chunk ────────────────────────────────────────────────────────

    /**
     * Execute the next chunk of SQL from the file.
     * Returns progress information.
     */
    public static function import_chunk( string $session_id, string $src_prefix, string $dst_prefix ): array {
        global $wpdb;

        $state = self::get_state( $session_id );
        if ( null === $state ) {
            throw new RuntimeException( 'DB import state not found.' );
        }
        if ( $state['done'] ) {
            return [ 'done' => true, 'progress' => 100 ];
        }

        $sql_file    = $state['sql_file'];
        $byte_offset = (int) $state['byte_offset'];

        if ( ! file_exists( $sql_file ) ) {
            throw new RuntimeException( 'SQL file not found: ' . $sql_file );
        }

        $file_size = filesize( $sql_file );
        $fh        = fopen( $sql_file, 'rb' );
        if ( ! $fh ) {
            throw new RuntimeException( 'Cannot open SQL file.' );
        }

        fseek( $fh, $byte_offset );

        $statements_run = 0;
        $buffer         = '';

        // We read the file and split on statement boundaries (`;` at end of line).
        while ( ! feof( $fh ) && $statements_run < OCB_CHUNK_SQL ) {
            $line = fgets( $fh, 65536 );
            if ( false === $line ) {
                break;
            }

            // Skip comments and empty lines.
            $trimmed = ltrim( $line );
            if ( '' === $trimmed || '--' === substr( $trimmed, 0, 2 ) || '#' === $trimmed[0] ) {
                continue;
            }

            $buffer .= $line;

            if ( self::statement_ends( $buffer ) ) {
                $sql = trim( $buffer );
                $buffer = '';

                if ( '' === $sql ) {
                    continue;
                }

                // Rename prefix in CREATE TABLE / INSERT INTO / ALTER TABLE.
                if ( $src_prefix !== $dst_prefix ) {
                    $sql = self::rename_prefix_in_sql( $sql, $src_prefix, $dst_prefix );
                }

                // Suppress errors — let wpdb handle them.
                $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $statements_run++;
            }
        }

        // Flush remaining buffer.
        if ( '' !== trim( $buffer ) ) {
            $sql = trim( $buffer );
            if ( $src_prefix !== $dst_prefix ) {
                $sql = self::rename_prefix_in_sql( $sql, $src_prefix, $dst_prefix );
            }
            $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $new_offset = ftell( $fh );
        fclose( $fh );

        $done = ( $new_offset >= $file_size );

        $state['byte_offset'] = $new_offset;
        $state['done']        = $done;
        self::save_state( $session_id, $state );

        $progress = $file_size > 0 ? (int) round( ( $new_offset / $file_size ) * 100 ) : 100;

        return [
            'done'             => $done,
            'progress'         => $progress,
            'statements_run'   => $statements_run,
            'bytes_processed'  => $new_offset,
            'total_bytes'      => $file_size,
        ];
    }

    /**
     * Detect end of a SQL statement (`;` at end, accounting for strings).
     * Simplified: works for standard mysqldump-style output.
     */
    private static function statement_ends( string $buffer ): bool {
        $buffer = rtrim( $buffer );
        return substr( $buffer, -1 ) === ';';
    }

    // ── Prefix renaming ───────────────────────────────────────────────────────

    private static function rename_prefix_in_sql( string $sql, string $src, string $dst ): string {
        if ( $src === $dst ) {
            return $sql;
        }
        $src_esc = preg_quote( $src, '/' );

        // CREATE TABLE `prefix_tablename` → `dst_tablename`.
        $sql = preg_replace(
            '/`' . $src_esc . '([^`]*)`/',
            '`' . $dst . '$1`',
            $sql
        );

        return $sql;
    }

    // ── Prefix rename in live DB (post-import) ────────────────────────────────

    /**
     * After SQL is imported with old prefix, rename special option keys
     * (usermeta capabilities, user_roles, etc.) to use the new prefix.
     */
    public static function rename_prefix_in_options( string $src_prefix, string $dst_prefix ): void {
        global $wpdb;

        if ( $src_prefix === $dst_prefix ) {
            return;
        }

        // Rename option names in wp_options that start with old prefix.
        $options_table = $dst_prefix . 'options';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_id, option_name FROM `{$options_table}` WHERE option_name LIKE %s",
                $wpdb->esc_like( $src_prefix ) . '%'
            ),
            ARRAY_A
        );
        foreach ( $rows as $row ) {
            $new_name = $dst_prefix . substr( $row['option_name'], strlen( $src_prefix ) );
            $wpdb->update(
                $options_table,
                [ 'option_name' => $new_name ],
                [ 'option_id' => $row['option_id'] ],
                [ '%s' ],
                [ '%d' ]
            );
        }

        // Rename usermeta keys in wp_usermeta.
        $usermeta_table = $dst_prefix . 'usermeta';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$usermeta_table}` SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s",
                $dst_prefix,
                strlen( $src_prefix ) + 1,
                $wpdb->esc_like( $src_prefix ) . '%'
            )
        );
    }

    // ── Safety snapshot ───────────────────────────────────────────────────────

    /**
     * Create a quick DB snapshot to allow rollback if import fails.
     * Saves to a .sql file in the backup temp dir.
     */
    public static function create_safety_snapshot( string $snapshot_file ): bool {
        global $wpdb;

        OCB_DB_Export::write_file_header( $snapshot_file );

        $tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );
        foreach ( $tables as $table ) {
            // Write create.
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( ! $create ) {
                continue;
            }
            $fh = fopen( $snapshot_file, 'ab' );
            if ( ! $fh ) {
                return false;
            }
            fwrite( $fh, "\nDROP TABLE IF EXISTS `{$table}`;\n" );
            fwrite( $fh, $create[1] . ";\n\n" );

            // Write rows.
            $offset = 0;
            while ( true ) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT 500 OFFSET %d", $offset ),
                    ARRAY_A
                );
                if ( empty( $rows ) ) {
                    break;
                }
                foreach ( $rows as $row ) {
                    $columns = array_map( fn( $c ) => "`{$c}`", array_keys( $row ) );
                    $values  = array_map( function ( $v ) use ( $wpdb ) {
                        return null === $v ? 'NULL' : "'" . $wpdb->_real_escape( $v ) . "'";
                    }, array_values( $row ) );
                    fwrite( $fh, 'INSERT INTO `' . $table . '` (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ");\n" );
                }
                $offset += count( $rows );
            }
            fclose( $fh );
        }

        OCB_DB_Export::write_file_footer( $snapshot_file );

        return true;
    }

    /**
     * Restore the safety snapshot (rollback).
     */
    public static function restore_snapshot( string $snapshot_file ): bool {
        global $wpdb;

        if ( ! file_exists( $snapshot_file ) ) {
            return false;
        }

        // Drop all current tables with matching prefix.
        $tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );
        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore
        }

        // Re-import snapshot SQL.
        $fh     = fopen( $snapshot_file, 'rb' );
        $buffer = '';
        while ( ! feof( $fh ) ) {
            $line = fgets( $fh, 65536 );
            if ( false === $line ) {
                break;
            }
            $trimmed = ltrim( $line );
            if ( '' === $trimmed || '--' === substr( $trimmed, 0, 2 ) ) {
                continue;
            }
            $buffer .= $line;
            if ( rtrim( $buffer, " \t\r\n" )[ strlen( rtrim( $buffer, " \t\r\n" ) ) - 1 ] === ';' ) {
                $wpdb->query( trim( $buffer ) ); // phpcs:ignore
                $buffer = '';
            }
        }
        fclose( $fh );

        return true;
    }
}
