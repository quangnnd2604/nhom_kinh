<?php
require 'wp-load.php';

echo "Bắt đầu Test Google Translate...\n";

$text = "Xin chào, đây là một bài kiểm tra hệ thống tự dịch.";
$source = "vi";
$target = "en";

$url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=' . $source . '&tl=' . $target . '&dt=t&q=' . urlencode( $text );

echo "URL Gọi: " . $url . "\n";

$response = wp_remote_get( $url, [
    'timeout'   => 15,
    'sslverify' => false,
] );

if ( is_wp_error( $response ) ) {
    echo "Lỗi WP Error: " . $response->get_error_message() . "\n";
} else {
    $body = wp_remote_retrieve_body( $response );
    echo "Phản hồi Body: \n";
    var_dump($body);
    
    $data = json_decode( $body, true );
    if ( is_array( $data ) && isset( $data[0] ) ) {
        $translated_text = '';
        foreach ( $data[0] as $sentence ) {
            if ( isset( $sentence[0] ) ) {
                $translated_text .= $sentence[0];
            }
        }
        echo "Dịch Thành Công: " . $translated_text . "\n";
    } else {
        echo "Không đúng cấu trúc JSON trả về.\n";
    }
}
