<?php

/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'nhom_kinh');

/** Database username */
define('DB_USER', 'root');

/** Database password */
define('DB_PASSWORD', '');

/** Database hostname */
define('DB_HOST', 'localhost');

/** Database charset to use in creating database tables. */
define('DB_CHARSET', 'utf8mb4');

/** The database collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         '.BDYolJ.khj_<?rwFZ,_5Yr~kTfNOvbK#ul`ch]1NYj+n:bK[hxyZI[CjqB0?U-.');
define('SECURE_AUTH_KEY',  ' 56*,tcityt]>zMLK[qy!7wjkT:/p{!F8mc,/3_l>u~jW+.!;.@R+*9!/STXoGPE');
define('LOGGED_IN_KEY',    '&Lfp9!8w_!lo|3o5?31r]f~^w`7-L4h%EFHKRmQtAfgYBYelr=joc b|z.=Q@z.,');
define('NONCE_KEY',        ']m>nM*RWqmpoJ281m)0<r%e.H{T5K#F[T)f:_`mCW|B^.}j0VZb?rPkQ+OZ+@-7-');
define('AUTH_SALT',        '-9ZL2=)#_c-!Qh=WWZg*:kMLEN)D>*_^>c&+!Mq/3Z~:M?YIXSz{:$n*4|m~Dxn(');
define('SECURE_AUTH_SALT', '=zh}KesM5Y]NsM]7k}PZ&]+[`&vDC[yDDlK^_b.o(N}5>>1vBKjZfO6&7nML%1{!');
define('LOGGED_IN_SALT',   '(4Npt(hx#nM%|b3KPG~Lye$2oA=U>1h>CZr-eI<WiI`|Q7@FH<#tS1X%reXvr>$#');
define('NONCE_SALT',       '= (zc?`z&-X4#a,G8ea}M`Y>b}XR](Adth;&YQG^6_2KPCej*V<}>0gm*%+4Ql<?');
define('FS_METHOD', 'direct');
define('WP_CACHE', true);

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define('WP_DEBUG', false);
define('ALLOW_UNFILTERED_UPLOADS', true);
ini_set('display_errors', 'Off');
ini_set('error_reporting', E_ALL);

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if (! defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
