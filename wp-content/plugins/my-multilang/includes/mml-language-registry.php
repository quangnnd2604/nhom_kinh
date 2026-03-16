<?php
/**
 * Language registry: pre-defined list of supported languages.
 *
 * Each entry:
 *   name     – Display name in Vietnamese.
 *   code     – IETF language tag used throughout the plugin (stored in DB).
 *   ai_name  – English name passed to AI prompts / shown in confirmation UIs.
 *   example  – A single greeting word used as a preview in the Magic Sync modal.
 *
 * Priority group (en, zh-cn, ko, ru) appears first in all selectors.
 * Everything else is sorted alphabetically by Vietnamese display name.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Return the prioritized language registry.
 *
 * @return array<int, array{name: string, code: string, ai_name: string, example: string}>
 */
function mml_get_language_registry(): array {

    // ── Priority languages (top 4 in selector) ────────────────────────────
    $priority = [
        [ 'name' => 'Tiếng Anh',               'code' => 'en',    'ai_name' => 'English',             'example' => 'Hello' ],
        [ 'name' => 'Tiếng Trung (Giản thể)',   'code' => 'zh-cn', 'ai_name' => 'Chinese Simplified',  'example' => '你好' ],
        [ 'name' => 'Tiếng Hàn',                'code' => 'ko',    'ai_name' => 'Korean',              'example' => '안녕하세요' ],
        [ 'name' => 'Tiếng Nga',                'code' => 'ru',    'ai_name' => 'Russian',             'example' => 'Привет' ],
    ];

    // ── All other supported languages ─────────────────────────────────────
    $others = [
        [ 'name' => 'Tiếng Ả Rập',              'code' => 'ar',    'ai_name' => 'Arabic',              'example' => 'مرحبا' ],
        [ 'name' => 'Tiếng Ba Lan',             'code' => 'pl',    'ai_name' => 'Polish',              'example' => 'Cześć' ],
        [ 'name' => 'Tiếng Bồ Đào Nha',         'code' => 'pt',    'ai_name' => 'Portuguese',          'example' => 'Olá' ],
        [ 'name' => 'Tiếng Đan Mạch',            'code' => 'da',    'ai_name' => 'Danish',              'example' => 'Hej' ],
        [ 'name' => 'Tiếng Đức',                'code' => 'de',    'ai_name' => 'German',              'example' => 'Hallo' ],
        [ 'name' => 'Tiếng Hà Lan',             'code' => 'nl',    'ai_name' => 'Dutch',               'example' => 'Hallo' ],
        [ 'name' => 'Tiếng Hindi',              'code' => 'hi',    'ai_name' => 'Hindi',               'example' => 'नमस्ते' ],
        [ 'name' => 'Tiếng Hungary',            'code' => 'hu',    'ai_name' => 'Hungarian',           'example' => 'Helló' ],
        [ 'name' => 'Tiếng Indonesia',          'code' => 'id',    'ai_name' => 'Indonesian',          'example' => 'Halo' ],
        [ 'name' => 'Tiếng Ý',                  'code' => 'it',    'ai_name' => 'Italian',             'example' => 'Ciao' ],
        [ 'name' => 'Tiếng Mã Lai',             'code' => 'ms',    'ai_name' => 'Malay',               'example' => 'Helo' ],
        [ 'name' => 'Tiếng Na Uy',              'code' => 'no',    'ai_name' => 'Norwegian',           'example' => 'Hei' ],
        [ 'name' => 'Tiếng Nhật',               'code' => 'ja',    'ai_name' => 'Japanese',            'example' => 'こんにちは' ],
        [ 'name' => 'Tiếng Phần Lan',           'code' => 'fi',    'ai_name' => 'Finnish',             'example' => 'Hei' ],
        [ 'name' => 'Tiếng Pháp',               'code' => 'fr',    'ai_name' => 'French',              'example' => 'Bonjour' ],
        [ 'name' => 'Tiếng Romania',            'code' => 'ro',    'ai_name' => 'Romanian',            'example' => 'Salut' ],
        [ 'name' => 'Tiếng Séc',                'code' => 'cs',    'ai_name' => 'Czech',               'example' => 'Ahoj' ],
        [ 'name' => 'Tiếng Tây Ban Nha',        'code' => 'es',    'ai_name' => 'Spanish',             'example' => 'Hola' ],
        [ 'name' => 'Tiếng Thái',               'code' => 'th',    'ai_name' => 'Thai',                'example' => 'สวัสดี' ],
        [ 'name' => 'Tiếng Thổ Nhĩ Kỳ',         'code' => 'tr',    'ai_name' => 'Turkish',             'example' => 'Merhaba' ],
        [ 'name' => 'Tiếng Thụy Điển',          'code' => 'sv',    'ai_name' => 'Swedish',             'example' => 'Hej' ],
        [ 'name' => 'Tiếng Trung (Phồn thể)',   'code' => 'zh-tw', 'ai_name' => 'Chinese Traditional', 'example' => '你好' ],
        [ 'name' => 'Tiếng Việt',               'code' => 'vi',    'ai_name' => 'Vietnamese',          'example' => 'Xin chào' ],
    ];

    // Sort the non-priority group by Vietnamese display name
    usort( $others, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

    return array_merge( $priority, $others );
}

/**
 * Return a code-indexed map for quick lookups.
 *
 * @return array<string, array{name: string, code: string, ai_name: string, example: string}>
 */
function mml_language_registry_by_code(): array {
    static $map = null;
    if ( $map === null ) {
        $map = [];
        foreach ( mml_get_language_registry() as $entry ) {
            $map[ $entry['code'] ] = $entry;
        }
    }
    return $map;
}
