<?php
/**
 * Auto-Translate Engine using Google Translate Free API.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MML_Auto_Translate {

    /**
     * Translates a string from source language to target language.
     *
     * @param string $text   Text to translate.
     * @param string $source Source language code (e.g., 'vi').
     * @param string $target Target language code (e.g., 'en').
     * @return string Translated text or original text on failure.
     */
    public static function translate( string $text, string $source, string $target ): string {
        if ( empty( trim( $text ) ) ) {
            return $text;
        }

        // Map WP language codes to Google Translate codes if necessary (e.g., zh-hant)
        $target = self::map_language_code( $target );
        $source = self::map_language_code( $source );

        $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=' . $source . '&tl=' . $target . '&dt=t&q=' . urlencode( $text );

        $response = wp_remote_get( $url, [
            'timeout'   => 15,
            'sslverify' => false, // Fix for local XAMPP cURL SSL certificate issues
        ] );

        $body = '';

        if ( is_wp_error( $response ) ) {
            // FALLBACK FOR BROKEN PHP ENVIRONMENT (Missing php_curl & allow_url_fopen)
            // User's XAMPP returns "No working transports found". We must force shell curl.exe
            if ( function_exists( 'shell_exec' ) ) {
                $cmd = 'curl.exe -s -k -m 15 "' . $url . '"';
                $shell_output = shell_exec( $cmd );
                if ( $shell_output ) {
                    $body = $shell_output;
                } else {
                    return $text;
                }
            } else {
                 return $text;
            }
        } else {
            $body = wp_remote_retrieve_body( $response );
        }

        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || ! isset( $data[0] ) ) {
            return $text;
        }

        $translated_text = '';
        foreach ( $data[0] as $sentence ) {
            if ( isset( $sentence[0] ) ) {
                $translated_text .= $sentence[0];
            }
        }

        return $translated_text ?: $text;
    }

    /**
     * Translate post_content preserving Flatsome UX Builder shortcodes and HTML tags.
     *
     * Strategy:
     *  1. Split content on shortcode tokens `[...]` and HTML tags `<...>` using
     *     PREG_SPLIT_DELIM_CAPTURE so both separators and text nodes are returned.
     *  2. For HTML tags  → keep verbatim (structure is language-neutral).
     *  3. For shortcodes → translate human-readable attribute values only
     *     (text, title, heading, sub_heading, caption, label, …).
     *  4. For plain text nodes between tags → translate via Google Translate.
     *  5. Reassemble in the original order.
     *
     * @param string $content Raw post_content.
     * @param string $source  Source language code (e.g. 'vi').
     * @param string $target  Target language code (e.g. 'en').
     * @return string Translated content with shortcode/HTML structure intact.
     */
    public static function translate_content( string $content, string $source, string $target ): string {
        if ( empty( trim( $content ) ) ) {
            return $content;
        }

        // Flatsome UX Builder attribute names that carry human-readable text
        $text_attr_names = 'text|title|heading|sub_heading|caption|label|description'
            . '|button_text|btn_text|link_text|alt|placeholder';

        $text_attr_pattern = '/\b(' . $text_attr_names . ')="([^"]+)"/';

        // Split on [shortcode_tokens] OR <html_tags>
        // PREG_SPLIT_DELIM_CAPTURE keeps the matched delimiters in the result array.
        $parts = preg_split(
            '/(\[[^\]]*\]|<[^>]+>)/s',
            $content,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ( ! $parts ) {
            // Fallback: translate as plain text (may mangle complex content)
            return self::translate( $content, $source, $target );
        }

        $result = [];

        foreach ( $parts as $part ) {
            if ( $part === '' ) {
                $result[] = $part;
                continue;
            }

            // ── HTML tag ──────────────────────────────────────────────────
            if ( $part[0] === '<' ) {
                $result[] = $part; // preserve verbatim
                continue;
            }

            // ── Shortcode token ──────────────────────────────────────────
            if ( $part[0] === '[' ) {
                // Translate only the values of human-readable attributes
                $translated_sc = preg_replace_callback(
                    $text_attr_pattern,
                    function ( $m ) use ( $source, $target ) {
                        $attr_value = $m[2];
                        // Skip purely numeric / very short values (e.g. span="6")
                        if ( is_numeric( trim( $attr_value ) ) || mb_strlen( trim( $attr_value ) ) < 2 ) {
                            return $m[0];
                        }
                        usleep( 150000 ); // 0.15 s – rate-limit guard
                        $translated = self::translate( $attr_value, $source, $target );
                        // Prevent double-quotes inside attribute value from breaking shortcode
                        $translated = str_replace( '"', "'", $translated );
                        return $m[1] . '="' . $translated . '"';
                    },
                    $part
                );
                $result[] = $translated_sc ?? $part;
                continue;
            }

            // ── Plain text node ──────────────────────────────────────────
            // Translate only if the chunk contains at least one word character
            if ( preg_match( '/\w/u', $part ) ) {
                usleep( 150000 ); // 0.15 s – rate-limit guard
                $result[] = self::translate( $part, $source, $target );
            } else {
                $result[] = $part; // whitespace / line-breaks only
            }
        }

        return implode( '', $result );
    }

    private static function map_language_code( string $code ): string {
        // Explicit overrides take priority — handles compound tags like zh-cn/zh-tw.
        $map = [
            'zh-cn' => 'zh-CN',
            'zh-tw' => 'zh-TW',
            'zh'    => 'zh-CN', // unqualified 'zh' → Simplified
        ];

        $lower = strtolower( $code );
        if ( isset( $map[ $lower ] ) ) {
            return $map[ $lower ];
        }

        // Default: use the first 2-character subtag (e.g. 'en', 'ko', 'th', 'ru').
        return strtolower( substr( $lower, 0, 2 ) );
    }
}
