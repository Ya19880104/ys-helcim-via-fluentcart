<?php
/**
 * YSMarketplacePage - 市集頁面渲染
 *
 * 頁面先渲染 skeleton loading，再由前端 JS 發 AJAX 取得外掛列表。
 *
 * @package YangSheep\PluginHubClient\Marketplace
 */

namespace YangSheep\PluginHubClient\Marketplace;

use YangSheep\PluginHubClient\Database\YSHubClientSettingsRepo;
use YangSheep\PluginHubClient\Http\YSCircuitBreaker;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 市集頁面控制器
 */
final class YSMarketplacePage {

    /**
     * 渲染市集頁面
     *
     * @return void
     */
    public static function render(): void {
        // Enqueue assets
        self::enqueue_assets();

        // 讀取設定
        $repo       = YSHubClientSettingsRepo::instance();
        $site_key   = $repo->get( 'ys_hub_site_key', '' );
        $auto_check = $repo->get( 'ys_hub_auto_check', 'yes' );

        // Circuit Breaker 狀態
        $cb_state = YSCircuitBreaker::get_state();
        $cb_label = YSCircuitBreaker::get_state_label();

        // 載入模板
        include YS_HUB_CLIENT_DIR . 'templates/marketplace-page.php';
    }

    /**
     * 註冊前端資源
     *
     * @return void
     */
    private static function enqueue_assets(): void {
        // 使用 JS 檔案的修改時間作為版本號，確保更新後 bust cache
        $js_file = YS_HUB_CLIENT_DIR . 'assets/js/ys-marketplace.js';
        $version = YS_HUB_CLIENT_VERSION . '.' . ( file_exists( $js_file ) ? filemtime( $js_file ) : time() );

        wp_enqueue_style(
            'ys-marketplace',
            YS_HUB_CLIENT_URL . 'assets/css/ys-marketplace.css',
            array(),
            $version
        );

        wp_enqueue_script(
            'ys-marketplace',
            YS_HUB_CLIENT_URL . 'assets/js/ys-marketplace.js',
            array( 'jquery' ),
            $version,
            true
        );

        wp_localize_script( 'ys-marketplace', 'ysHubClient', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ys_hub_marketplace_nonce' ),
            'hubUrl'  => YS_HUB_CLIENT_HUB_URL,
            'i18n'    => array(
                'loading'        => __( 'Loading…', 'ys-plugin-hub-client' ),
                'noPlugins'      => __( 'No plugins available', 'ys-plugin-hub-client' ),
                'connectionFail' => __( 'Cannot connect to the Hub right now. Please try again later.', 'ys-plugin-hub-client' ),
                'installed'      => __( 'Installed', 'ys-plugin-hub-client' ),
                'updateAvail'    => __( 'Update available', 'ys-plugin-hub-client' ),
                'install'        => __( 'Install', 'ys-plugin-hub-client' ),
                'update'         => __( 'Update', 'ys-plugin-hub-client' ),
                'installing'     => __( 'Installing…', 'ys-plugin-hub-client' ),
                'updating'       => __( 'Updating…', 'ys-plugin-hub-client' ),
                'success'        => __( 'Success', 'ys-plugin-hub-client' ),
                'failed'         => __( 'Failed', 'ys-plugin-hub-client' ),
                'confirmInstall' => __( 'Are you sure you want to install this plugin?', 'ys-plugin-hub-client' ),
                'confirmUpdate'  => __( 'Are you sure you want to update this plugin?', 'ys-plugin-hub-client' ),
                'saved'          => __( 'Settings saved', 'ys-plugin-hub-client' ),
                'refreshing'     => __( 'Refreshing…', 'ys-plugin-hub-client' ),
                'testingConn'    => __( 'Testing…', 'ys-plugin-hub-client' ),
                'connSuccess'    => __( 'Connection successful', 'ys-plugin-hub-client' ),
                'connFailed'     => __( 'Connection failed', 'ys-plugin-hub-client' ),
                'generating'     => __( 'Generating…', 'ys-plugin-hub-client' ),
                'dismiss'           => __( 'Dismiss', 'ys-plugin-hub-client' ),
                'activate'          => __( 'Activate', 'ys-plugin-hub-client' ),
                'activating'        => __( 'Activating…', 'ys-plugin-hub-client' ),
                'activated'         => __( 'Activated', 'ys-plugin-hub-client' ),
                'active'            => __( 'Active', 'ys-plugin-hub-client' ),
                'deactivate'        => __( 'Deactivate', 'ys-plugin-hub-client' ),
                'deactivating'      => __( 'Deactivating…', 'ys-plugin-hub-client' ),
                'deletePlugin'      => __( 'Delete', 'ys-plugin-hub-client' ),
                'deleting'          => __( 'Deleting…', 'ys-plugin-hub-client' ),
                'confirmDelete'     => __( 'Are you sure you want to delete this plugin? This action cannot be undone.', 'ys-plugin-hub-client' ),
                'lastPluginWarning' => __( 'The last YS plugin has been deactivated. Redirecting to the Plugins page…', 'ys-plugin-hub-client' ),
                'free'              => __( 'Free', 'ys-plugin-hub-client' ),
                'viewPlugin'        => __( 'View plugin', 'ys-plugin-hub-client' ),
            ),
        ) );
    }
}
