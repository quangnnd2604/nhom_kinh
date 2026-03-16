<?php
/**
 * Test Google Translate
 */
add_action('init', function() {
    if ( ! isset( $_GET['test_gg'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    $text = "Xin chào, đây là một bài kiểm tra hệ thống tự dịch rất dài và có xuống dòng.\nTest OK";
    $source = "vi";
    $target = "en";

    $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=' . $source . '&tl=' . $target . '&dt=t&q=' . urlencode( $text );

    $response = wp_remote_get( $url, [
        'timeout'   => 15,
        'sslverify' => false,
    ] );

    $body = '';

    echo '<h2>Google Translate API Test</h2>';
    echo '<strong>URL:</strong> ' . esc_html($url) . '<br><br>';

    if ( is_wp_error( $response ) ) {
        echo '<div style="color:red">WP Error: ' . esc_html( $response->get_error_message() ) . '</div>';
        echo '<div style="color:blue">Using Shell Exec Fallback...</div>';
        if ( function_exists( 'shell_exec' ) ) {
            $cmd = 'curl.exe -s -k -m 15 "' . $url . '"';
            $shell_output = shell_exec( $cmd );
            if ( $shell_output ) {
                $body = $shell_output;
            } else {
                 echo '<div style="color:red">Shell Exec also failed!</div>';
            }
        }
    } else {
        $body = wp_remote_retrieve_body( $response );
    }

    if ($body) {
        echo '<strong>Raw Body:</strong><pre>' . esc_html( $body ) . '</pre>';
        
        $data = json_decode( $body, true );
        if ( is_array( $data ) && isset( $data[0] ) ) {
            $translated_text = '';
            foreach ( $data[0] as $sentence ) {
                if ( isset( $sentence[0] ) ) {
                    $translated_text .= $sentence[0];
                }
            }
            echo '<div style="color:green; font-weight:bold; font-size:20px;">Translated: ' . esc_html( $translated_text ) . '</div>';
        } else {
            echo '<div style="color:orange">Invalid JSON structure.</div>';
        }
    }
    exit;
});
