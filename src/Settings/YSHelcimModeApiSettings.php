<?php
/**
 * Mode-bound API credential contract shared by Helcim payment runtimes.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface YSHelcimModeApiSettings {
	public function getApiTokenForMode( string $mode ): string;
}
