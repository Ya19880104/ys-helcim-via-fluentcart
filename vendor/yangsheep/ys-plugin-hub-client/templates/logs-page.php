<?php
/**
 * 系統資訊頁面模板
 *
 * @package YangSheep\PluginHubClient
 * @var array  $logs   日誌列表
 * @var int    $total  日誌總數
 * @var string $filter_level  篩選等級
 * @var string $filter_action 篩選操作
 * @var int    $page   目前頁碼
 */

defined( 'ABSPATH' ) || exit;

/* ── 收集系統資訊 ── */
global $wpdb;

$sys = array(
	'wp_version'    => get_bloginfo( 'version' ),
	'php_version'   => PHP_VERSION,
	'mysql_version' => $wpdb->db_version(),
	'mysql_server'  => $wpdb->db_server_info(),
	'memory_limit'  => ini_get( 'memory_limit' ),
	'max_execution' => ini_get( 'max_execution_time' ),
	'upload_max'    => ini_get( 'upload_max_filesize' ),
	'post_max'      => ini_get( 'post_max_size' ),
	'php_sapi'      => php_sapi_name(),
	'server_sw'     => sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ?? '' ),
	'site_url'      => get_site_url(),
	'hub_url'       => defined( 'YS_HUB_CLIENT_HUB_URL' ) ? YS_HUB_CLIENT_HUB_URL : '-',
	'hub_client_v'  => defined( 'YS_HUB_CLIENT_VERSION' ) ? YS_HUB_CLIENT_VERSION : '-',
	'is_ssl'        => is_ssl() ? __( 'Yes', 'ys-plugin-hub-client' ) : __( 'No', 'ys-plugin-hub-client' ),
	'multisite'     => is_multisite() ? __( 'Yes', 'ys-plugin-hub-client' ) : __( 'No', 'ys-plugin-hub-client' ),
	'debug_mode'    => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? __( 'On', 'ys-plugin-hub-client' ) : __( 'Off', 'ys-plugin-hub-client' ),
	'timezone'      => wp_timezone_string(),
);

// 主機傳出 IP（快取 24 小時避免每次載入都打外部 API）
$outbound_ip = get_site_transient( 'ys_hub_outbound_ip' );
if ( false === $outbound_ip ) {
	$ip_response = wp_remote_get( 'https://api64.ipify.org?format=json', array( 'timeout' => 5, 'sslverify' => true ) );
	if ( ! is_wp_error( $ip_response ) ) {
		$ip_data     = json_decode( wp_remote_retrieve_body( $ip_response ), true );
		$outbound_ip = $ip_data['ip'] ?? '';
	}
	if ( empty( $outbound_ip ) ) {
		// Fallback
		$ip_response = wp_remote_get( 'https://checkip.amazonaws.com/', array( 'timeout' => 5 ) );
		$outbound_ip = ! is_wp_error( $ip_response ) ? trim( wp_remote_retrieve_body( $ip_response ) ) : __( 'Unable to detect', 'ys-plugin-hub-client' );
	}
	set_site_transient( 'ys_hub_outbound_ip', $outbound_ip, DAY_IN_SECONDS );
}

// WooCommerce 版本
$wc_version = '-';
if ( defined( 'WC_VERSION' ) ) {
	$wc_version = WC_VERSION;
} elseif ( class_exists( 'WooCommerce' ) ) {
	$wc_version = WooCommerce::instance()->version;
}

// 已安裝 YS 外掛
$ys_plugins = \YangSheep\PluginHubClient\YSPluginHubClient::detect_ys_plugins();
?>
<div class="ys-marketplace-wrap">

	<!-- Hero Header -->
	<div class="ys-page-hero">
		<div class="ys-page-hero-content">
			<div class="ys-hero-title">
				<span class="dashicons dashicons-info-outline"></span>
				<?php esc_html_e( 'System info', 'ys-plugin-hub-client' ); ?>
			</div>
			<div class="ys-hero-subtitle"><?php
				printf(
					esc_html__( 'Developed and maintained by %s', 'ys-plugin-hub-client' ),
					'<a href="https://yangsheep.com.tw" target="_blank" rel="noopener noreferrer" style="color:rgba(255,255,255,0.95);text-decoration:none;">YANGSHEEP CLOUD</a>'
				);
			?></div>
		</div>
	</div>

	<!-- WP notice 錨點 -->
	<div class="wrap"><h2 style="display:none;"></h2></div>

	<!-- ━━━ 1. 傳出 IP ━━━ -->
	<div class="ys-sysinfo-section">
		<div class="ys-sysinfo-ip-card">
			<div class="ys-sysinfo-ip-icon"><span class="dashicons dashicons-networking"></span></div>
			<div>
				<div class="ys-sysinfo-ip-label"><?php esc_html_e( 'Server outbound IP', 'ys-plugin-hub-client' ); ?></div>
				<div class="ys-sysinfo-ip-value"><?php echo esc_html( $outbound_ip ); ?></div>
				<div class="ys-sysinfo-note"><?php esc_html_e( 'The IP address this server uses for outbound connections (affects API rate limits and firewall allowlisting)', 'ys-plugin-hub-client' ); ?></div>
			</div>
		</div>
	</div>

	<!-- ━━━ 2. 主機環境 ━━━ -->
	<div class="ys-sysinfo-section">
		<h3 class="ys-sysinfo-heading">
			<span class="dashicons dashicons-cloud"></span>
			<?php esc_html_e( 'Server environment', 'ys-plugin-hub-client' ); ?>
		</h3>
		<div class="ys-sysinfo-table-card">
			<table class="ys-sysinfo-table">
				<tbody>
					<tr>
						<td class="ys-sysinfo-td-label">PHP</td>
						<td><?php echo esc_html( $sys['php_version'] ); ?> <span class="ys-sysinfo-note-inline">(<?php echo esc_html( $sys['php_sapi'] ); ?>)</span></td>
					</tr>
					<tr>
						<td class="ys-sysinfo-td-label">MySQL</td>
						<td><?php echo esc_html( $sys['mysql_version'] ); ?></td>
					</tr>
					<tr>
						<td class="ys-sysinfo-td-label">WordPress</td>
						<td><?php echo esc_html( $sys['wp_version'] ); ?></td>
					</tr>
					<tr>
						<td class="ys-sysinfo-td-label">WooCommerce</td>
						<td><?php echo esc_html( $wc_version ); ?></td>
					</tr>
					<tr>
						<td class="ys-sysinfo-td-label"><?php esc_html_e( 'PHP memory limit', 'ys-plugin-hub-client' ); ?></td>
						<td><?php echo esc_html( $sys['memory_limit'] ); ?></td>
					</tr>
					<tr>
						<td class="ys-sysinfo-td-label"><?php esc_html_e( 'Upload limit', 'ys-plugin-hub-client' ); ?></td>
						<td><?php echo esc_html( $sys['upload_max'] ); ?></td>
					</tr>
					<tr>
						<td class="ys-sysinfo-td-label"><?php esc_html_e( 'Max execution time', 'ys-plugin-hub-client' ); ?></td>
						<td><?php echo esc_html( $sys['max_execution'] ); ?>s</td>
					</tr>
					<tr>
						<td class="ys-sysinfo-td-label">SSL</td>
						<td><?php echo esc_html( $sys['is_ssl'] ); ?></td>
					</tr>
					<tr>
						<td class="ys-sysinfo-td-label"><?php esc_html_e( 'Debug mode', 'ys-plugin-hub-client' ); ?></td>
						<td><?php echo esc_html( $sys['debug_mode'] ); ?></td>
					</tr>
					<tr>
						<td class="ys-sysinfo-td-label"><?php esc_html_e( 'Timezone', 'ys-plugin-hub-client' ); ?></td>
						<td><?php echo esc_html( $sys['timezone'] ); ?></td>
					</tr>
					<tr>
						<td class="ys-sysinfo-td-label"><?php esc_html_e( 'Web Server', 'ys-plugin-hub-client' ); ?></td>
						<td><?php echo esc_html( $sys['server_sw'] ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>

	<!-- ━━━ 3. 已安裝 YS 外掛 ━━━ -->
	<div class="ys-sysinfo-section">
		<h3 class="ys-sysinfo-heading">
			<span class="dashicons dashicons-admin-plugins"></span>
			<?php esc_html_e( 'Installed YS plugins', 'ys-plugin-hub-client' ); ?>
		</h3>
		<div class="ys-sysinfo-plugins">
			<?php foreach ( $ys_plugins as $slug => $info ) : ?>
				<span class="ys-sysinfo-plugin-pill <?php echo $info['active'] ? 'active' : 'inactive'; ?>">
					<?php echo esc_html( $slug ); ?>
					<span class="ys-sysinfo-pill-ver">v<?php echo esc_html( $info['version'] ); ?></span>
				</span>
			<?php endforeach; ?>
			<?php if ( empty( $ys_plugins ) ) : ?>
				<span style="color:#7b8a96;"><?php esc_html_e( 'No YS plugins detected', 'ys-plugin-hub-client' ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<!-- ━━━ 4. 系統日誌 ━━━ -->
	<div class="ys-sysinfo-section">
		<h3 class="ys-sysinfo-heading">
			<span class="dashicons dashicons-list-view"></span>
			<?php esc_html_e( 'Activity log', 'ys-plugin-hub-client' ); ?>
		</h3>

		<div class="ys-log-toolbar">
			<div class="ys-log-filters">
				<select id="ys-log-level-filter" class="ys-select">
					<option value=""><?php esc_html_e( 'All levels', 'ys-plugin-hub-client' ); ?></option>
					<option value="info" <?php selected( $filter_level, 'info' ); ?>><?php esc_html_e( 'Info', 'ys-plugin-hub-client' ); ?></option>
					<option value="success" <?php selected( $filter_level, 'success' ); ?>><?php esc_html_e( 'Success', 'ys-plugin-hub-client' ); ?></option>
					<option value="warning" <?php selected( $filter_level, 'warning' ); ?>><?php esc_html_e( 'Warning', 'ys-plugin-hub-client' ); ?></option>
					<option value="error" <?php selected( $filter_level, 'error' ); ?>><?php esc_html_e( 'Error', 'ys-plugin-hub-client' ); ?></option>
				</select>
				<select id="ys-log-action-filter" class="ys-select">
					<option value=""><?php esc_html_e( 'All actions', 'ys-plugin-hub-client' ); ?></option>
					<option value="install" <?php selected( $filter_action, 'install' ); ?>><?php esc_html_e( 'Install', 'ys-plugin-hub-client' ); ?></option>
					<option value="update" <?php selected( $filter_action, 'update' ); ?>><?php esc_html_e( 'Update', 'ys-plugin-hub-client' ); ?></option>
					<option value="activate" <?php selected( $filter_action, 'activate' ); ?>><?php esc_html_e( 'Activate', 'ys-plugin-hub-client' ); ?></option>
					<option value="connect" <?php selected( $filter_action, 'connect' ); ?>><?php esc_html_e( 'Connect', 'ys-plugin-hub-client' ); ?></option>
				</select>
				<button id="ys-log-filter-btn" class="ys-btn ys-btn-outline ys-btn-sm"><?php esc_html_e( 'Filter', 'ys-plugin-hub-client' ); ?></button>
			</div>
			<div style="display:flex;align-items:center;gap:10px;">
				<span style="font-size:12px;color:#7b8a96;"><?php esc_html_e( '* Automatically cleared after 30 days', 'ys-plugin-hub-client' ); ?></span>
				<button id="ys-log-clear-btn" class="ys-btn ys-btn-sm" style="color:#c08080;border:1px solid #c08080;background:transparent;">
					<span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span>
					<?php esc_html_e( 'Clear all', 'ys-plugin-hub-client' ); ?>
				</button>
			</div>
		</div>

		<table class="widefat striped ys-log-table">
			<thead>
				<tr>
					<th style="width:150px;"><?php esc_html_e( 'Time', 'ys-plugin-hub-client' ); ?></th>
					<th style="width:70px;"><?php esc_html_e( 'Level', 'ys-plugin-hub-client' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Action', 'ys-plugin-hub-client' ); ?></th>
					<th><?php esc_html_e( 'Message', 'ys-plugin-hub-client' ); ?></th>
				</tr>
			</thead>
			<tbody id="ys-log-tbody">
				<?php if ( empty( $logs ) ) : ?>
					<tr><td colspan="4" style="text-align:center;color:#7b8a96;padding:32px;">
						<?php esc_html_e( 'No log entries yet', 'ys-plugin-hub-client' ); ?>
					</td></tr>
				<?php else : ?>
					<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td style="font-size:12px;color:#7b8a96;white-space:nowrap;">
							<?php echo esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $log->created_at ) ) ); ?>
						</td>
						<td>
							<?php
							$level_map = array(
								'info'    => array( 'Info', '#e8eff5', '#6b8a9a' ),
								'success' => array( 'Success', '#e8f3ec', '#7dab8e' ),
								'warning' => array( 'Warning', '#faf2e5', '#c4a67a' ),
								'error'   => array( 'Error', '#f8e8e8', '#c08080' ),
							);
							$lv = $level_map[ $log->level ] ?? $level_map['info'];
							?>
							<span style="background:<?php echo esc_attr( $lv[1] ); ?>;color:<?php echo esc_attr( $lv[2] ); ?>;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">
								<?php echo esc_html( $lv[0] ); ?>
							</span>
						</td>
						<td style="font-size:12px;">
							<?php
							$act_map = array( 'install' => 'Install', 'update' => 'Update', 'activate' => 'Activate', 'check' => 'Check', 'connect' => 'Connect', 'sync' => 'Sync' );
							echo esc_html( $act_map[ $log->action ] ?? $log->action );
							?>
						</td>
						<td><?php echo esc_html( $log->message ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total > 50 ) : ?>
		<div style="margin-top:16px;display:flex;gap:8px;justify-content:center;">
			<?php
			$total_pages = ceil( $total / 50 );
			for ( $i = 1; $i <= min( $total_pages, 10 ); $i++ ) :
				$url = add_query_arg( array( 'paged' => $i, 'level' => $filter_level, 'action_filter' => $filter_action ), admin_url( 'admin.php?page=ys-hub-logs' ) );
			?>
				<a href="<?php echo esc_url( $url ); ?>" style="padding:4px 10px;border:1px solid #e2e8ed;border-radius:4px;text-decoration:none;<?php echo $page === $i ? 'font-weight:700;color:#6b8a9a;' : ''; ?>">
					<?php echo esc_html( $i ); ?>
				</a>
			<?php endfor; ?>
		</div>
		<?php endif; ?>
	</div>

</div>

<script>
jQuery(function($){
	$('#ys-log-filter-btn').on('click', function(){
		var level = $('#ys-log-level-filter').val();
		var action = $('#ys-log-action-filter').val();
		var url = '<?php echo esc_js( admin_url( 'admin.php?page=ys-hub-logs' ) ); ?>';
		if(level) url += '&level=' + level;
		if(action) url += '&action_filter=' + action;
		window.location.href = url;
	});
	$('#ys-log-clear-btn').on('click', function(){
		if(!confirm('<?php echo esc_js( __( 'Are you sure you want to clear all logs?', 'ys-plugin-hub-client' ) ); ?>')) return;
		var btn = $(this);
		btn.prop('disabled', true);
		$.post(ysHubClient.ajaxUrl, {
			action: 'ys_hub_client_clear_logs',
			nonce: ysHubClient.nonce
		}, function(r){ if(r.success) window.location.reload(); }).always(function(){ btn.prop('disabled', false); });
	});
});
</script>
