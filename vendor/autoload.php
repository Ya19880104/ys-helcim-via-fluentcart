<?php
/**
 * Vendor autoloader.
 *
 * Loads the YS Plugin Hub Client (and any other vendored packages).
 *
 * @package YangSheep\Helcim\FluentCart
 */

if ( file_exists( __DIR__ . '/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php' ) ) {
	require_once __DIR__ . '/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php';
}
