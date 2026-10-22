<?php
/**
 * Safe URL / serialized-data search-and-replace for database import.
 *
 * Properly handles:
 *  - Serialized PHP strings (fixes byte-length after replacement)
 *  - JSON strings
 *  - Plain strings
 *  - http/https and www variations
 *
 * @package OneClickBackup
 */

defined( 'ABSPATH' ) || exit;

class OCB_URL_Replacer {

    /** @var string[] */
    private array $search_urls;

    /** @var string[] */
    private array $replace_urls;

    public function __construct( string $old_url, string $new_url ) {
        $this->search_urls  = self::url_variants( rtrim( $old_url, '/' ) );
        $this->replace_urls = self::url_variants( rtrim( $new_url, '/' ) );
    }

    /**
     * Build http/https + www/non-www variants for a URL.
     * Returns array of 4: [http_nowww, https_nowww, http_www, https_www]
     */
    private static function url_variants( string $url ): array {
        $parsed = wp_parse_url( $url );
        $host   = $parsed['host'] ?? '';
        $path   = $parsed['path'] ?? '';

        $host_nowww = preg_replace( '/^www\./i', '', $host );
        $host_www   = 'www.' . $host_nowww;

        return [
            'http://'  . $host_nowww . $path,
            'https://' . $host_nowww . $path,
            'http://'  . $host_www   . $path,
            'https://' . $host_www   . $path,
        ];
    }

    /**
     * Replace URLs in a single value (string or array or object).
     * Correctly handles serialized PHP data.
     */
    public function replace( $value ) {
        if ( is_array( $value ) ) {
            return array_map( [ $this, 'replace' ], $value );
        }
        if ( is_object( $value ) ) {
            foreach ( get_object_vars( $value ) as $k => $v ) {
                $value->$k = $this->replace( $v );
            }
            return $value;
        }
        if ( ! is_string( $value ) ) {
            return $value;
        }

        return $this->replace_in_string( $value );
    }

    private function replace_in_string( string $value ): string {
        // Check for serialized data.
        if ( $this->is_serialized( $value ) ) {
            $unserialized = @unserialize( $value );
            if ( false !== $unserialized ) {
                $replaced = $this->replace( $unserialized );
                $result   = serialize( $replaced );
                // Fix any string lengths that WordPress might have mangled.
                return $this->fix_serialized_lengths( $result );
            }
        }

        // Plain string replacement.
        for ( $i = 0; $i < count( $this->search_urls ); $i++ ) {
            $search  = $this->search_urls[ $i ];
            $replace = $this->replace_urls[ $i ] ?? $this->replace_urls[0];
            if ( $search !== '' ) {
                $value = str_replace( $search, $replace, $value );
            }
        }
        return $value;
    }

    /**
     * Check if a string is serialized PHP data.
     */
    private function is_serialized( string $data ): bool {
        $data = trim( $data );
        if ( '' === $data ) {
            return false;
        }
        if ( 'N;' === $data ) {
            return true;
        }
        if ( strlen( $data ) < 4 ) {
            return false;
        }
        if ( ':' !== $data[1] ) {
            return false;
        }
        if ( ';' === $data[ strlen( $data ) - 1 ] ) {
            $token = substr( $data, 0, 2 );
            switch ( $token ) {
                case 's:':
                case 'a:':
                case 'O:':
                case 'b:':
                case 'i:':
                case 'd:':
                    return true;
            }
        }
        return false;
    }

    /**
     * After a serialize() call the string lengths inside s:"..." entries
     * should already be correct because serialize() rebuilds them, but we
     * provide this as a safety net for edge-cases where the string came from
     * a partial replacement on already-serialized data.
     */
    private function fix_serialized_lengths( string $data ): string {
        return preg_replace_callback(
            '/s:(\d+):"(.*?)";/s',
            function ( $matches ) {
                $len    = strlen( $matches[2] );
                return 's:' . $len . ':"' . $matches[2] . '";';
            },
            $data
        ) ?? $data;
    }

    // ── Run on entire DB table ─────────────────────────────────────────────────

    /**
     * Perform URL replacement on all text-like columns of a given table.
     * Processes in batches to be memory-safe.
     */
    public function replace_in_table( string $table, int $batch_size = 200 ): void {
        global $wpdb;

        $columns_raw = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );
        if ( ! $columns_raw ) {
            return;
        }

        // Only text-like columns.
        $text_types = [ 'char', 'varchar', 'text', 'mediumtext', 'longtext', 'tinytext', 'blob', 'mediumblob', 'longblob' ];
        $text_cols  = [];
        $pk_col     = null;
        foreach ( $columns_raw as $col ) {
            $type = strtolower( preg_replace( '/\(.*/', '', $col['Type'] ) );
            if ( in_array( $type, $text_types, true ) ) {
                $text_cols[] = $col['Field'];
            }
            if ( 'PRI' === $col['Key'] && null === $pk_col ) {
                $pk_col = $col['Field'];
            }
        }

        if ( empty( $text_cols ) || null === $pk_col ) {
            return;
        }

        $offset = 0;
        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch_size, $offset ),
                ARRAY_A
            );
            if ( empty( $rows ) ) {
                break;
            }
            foreach ( $rows as $row ) {
                $updates = [];
                $changed = false;
                foreach ( $text_cols as $col ) {
                    if ( ! isset( $row[ $col ] ) || null === $row[ $col ] ) {
                        continue;
                    }
                    $new_val = $this->replace_in_string( (string) $row[ $col ] );
                    if ( $new_val !== (string) $row[ $col ] ) {
                        $updates[ $col ] = $new_val;
                        $changed         = true;
                    }
                }
                if ( $changed ) {
                    $wpdb->update( $table, $updates, [ $pk_col => $row[ $pk_col ] ] );
                }
            }
            $offset += count( $rows );
        }
    }
}
