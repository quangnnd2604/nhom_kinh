<?php
/**
 * Plugin Name:  My CF7 Honeypot
 * Description:  A lightweight, custom honeypot anti-spam module for Contact Form 7.
 * Version:      1.0.0
 * Author:       Nhóm Kính Dev
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class My_CF7_Honeypot {

    public static function init(): void {
        add_filter( 'wpcf7_spam', [ self::class, 'check_honeypot' ], 10, 2 );
        add_filter( 'wpcf7_form_elements', [ self::class, 'inject_honeypot' ] );
    }

    /**
     * Checks if the hidden honeypot field was filled out by a bot.
     *
     * @param bool   $spam       Is already marked as spam?
     * @param object $submission WPCF7_Submission object
     * @return bool
     */
    public static function check_honeypot( $spam, $submission ): bool {
        if ( $spam ) {
            return $spam;
        }

        $data = $submission->get_posted_data();

        // 'mml-pooh' is our trap field. If it has any value, a bot filled it.
        if ( ! empty( $data['mml-pooh'] ) ) {
            return true;
        }

        return $spam;
    }

    /**
     * Automatically injects the honeypot HTML field into all CF7 forms.
     *
     * @param string $form The HTML content of the form
     * @return string
     */
    public static function inject_honeypot( string $form ): string {
        // The container is moved off-screen so real users never see or tab into it.
        $html  = '<span class="my-trap-wrapper" style="position:absolute; left:-9999px; top:-9999px; opacity:0; z-index:-1;">';
        $html .= '<label>Please leave this field empty: <input type="text" name="mml-pooh" value="" tabindex="-1" autocomplete="new-password" /></label>';
        $html .= '</span>';
        
        // Try to inject it right before the submit button
        if ( preg_match( '/(?:<input[^>]+type="submit"|<button[^>]+type="submit")/i', $form ) ) {
            $form = preg_replace( '/(<input[^>]+type="submit"|<button[^>]+type="submit")/i', $html . "\n" . '$1', $form );
        } else {
            // Fallback to appending at the bottom
            $form .= "\n" . $html;
        }

        return $form;
    }
}

add_action( 'plugins_loaded', [ 'My_CF7_Honeypot', 'init' ] );
