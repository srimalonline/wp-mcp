<?php
/**
 * Plugin Name: WP MCP Connector
 * Description: Connect this site to a Claude MCP session with a revocable token. While connected: content, media, plugins, themes and direct file editing. Disconnect any time — the token dies with the session.
 * Version: 1.0.0
 * Author: Srimal Fernando
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

final class WPMCP_Connector {

	const NS         = 'wp-mcp/v1';
	const OPT_TOKENS = 'wpmcp_tokens';
	const OPT_RESCUE = 'wpmcp_rescue_token';
	const MAX_READ   = 4194304; // 4 MB per file read

	public static function boot() {
		add_filter('determine_current_user', [__CLASS__, 'auth'], 20);
		add_action('rest_api_init', [__CLASS__, 'routes']);
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_post_wpmcp_generate', [__CLASS__, 'handle_generate']);
		add_action('admin_post_wpmcp_disconnect', [__CLASS__, 'handle_disconnect']);
		register_activation_hook(__FILE__, [__CLASS__, 'activate']);
	}

	/* ---------------------------------------------------------------- setup */

	public static function activate() {
		// Rescue secret: lets rescue.php restore a backup even when WP is fatally broken.
		$secret = 'wpmcpr_' . wp_generate_password(40, false, false);
		update_option(self::OPT_RESCUE, $secret, false);
		@file_put_contents(__DIR__ . '/.rescue-secret', password_hash($secret, PASSWORD_DEFAULT));
	}

	/* ----------------------------------------------------------------- auth */

	private static function request_token() {
		if (!empty($_SERVER['HTTP_X_WPMCP_TOKEN'])) {
			return trim($_SERVER['HTTP_X_WPMCP_TOKEN']);
		}
		$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
		if (stripos($auth, 'Bearer wpmcp_') === 0) {
			return trim(substr($auth, 7));
		}
		return null;
	}

	public static function auth($user_id) {
		if ($user_id) return $user_id;
		$token = self::request_token();
		if (!$token || strpos($token, 'wpmcp_') !== 0) return $user_id;

		$records = get_option(self::OPT_TOKENS, []);
		if (!is_array($records)) return $user_id;

		foreach ($records as $i => $rec) {
			if (empty($rec['hash']) || empty($rec['user'])) continue;
			if (!empty($rec['expires']) && time() > (int) $rec['expires']) continue;
			if (password_verify($token, $rec['hash'])) {
				if (empty($rec['last_used']) || time() - (int) $rec['last_used'] > 60) {
					$records[$i]['last_used'] = time();
					update_option(self::OPT_TOKENS, $records, false);
				}
				return (int) $rec['user'];
			}
		}
		return $user_id;
	}

	public static function can() {
		return current_user_can('manage_options');
	}

	/* --------------------------------------------------------------- routes */

	public static function routes() {
		$route = function ($path, $methods, $cb) {
			register_rest_route(self::NS, $path, [
				'methods'             => $methods,
				'callback'            => [__CLASS__, $cb],
				'permission_callback' => [__CLASS__, 'can'],
			]);
		};
		$route('/info',       'GET',  'ep_info');
		$route('/disconnect', 'POST', 'ep_disconnect');
		$route('/fs/list',    'GET',  'ep_fs_list');
		$route('/fs/read',    'GET',  'ep_fs_read');
		$route('/fs/write',   'POST', 'ep_fs_write');
		$route('/fs/delete',  'POST', 'ep_fs_delete');
		$route('/fs/restore', 'POST', 'ep_fs_restore');
		$route('/install',    'POST', 'ep_install');
		$route('/theme',      'POST', 'ep_theme');
	}

	/* ------------------------------------------------------------ fs helpers */

	private static function base_dir() {
		$full = defined('WPMCP_ALLOW_FULL') && WPMCP_ALLOW_FULL;
		return wp_normalize_path($full ? untrailingslashit(ABSPATH) : WP_CONTENT_DIR);
	}

	/** Resolve a relative path safely inside the allowed base. */
	private static function resolve($rel) {
		$rel = ltrim(wp_normalize_path(trim((string) $rel)), '/');
		if ($rel !== '') {
			foreach (explode('/', $rel) as $seg) {
				if ($seg === '..') {
					return new WP_Error('wpmcp_path', 'Path traversal is not allowed.', ['status' => 403]);
				}
			}
		}
		return $rel === '' ? self::base_dir() : self::base_dir() . '/' . $rel;
	}

	private static function err($code, $msg, $status = 400) {
		return new WP_Error($code, $msg, ['status' => $status]);
	}

	/* ------------------------------------------------------------- endpoints */

	public static function ep_info() {
		$theme = wp_get_theme();
		$user  = wp_get_current_user();
		return [
			'connected'    => true,
			'plugin'       => 'wp-mcp-connector 1.0.0',
			'site_name'    => get_bloginfo('name'),
			'site_url'     => home_url(),
			'wp_version'   => get_bloginfo('version'),
			'php_version'  => PHP_VERSION,
			'active_theme' => ['name' => $theme->get('Name'), 'stylesheet' => $theme->get_stylesheet()],
			'multisite'    => is_multisite(),
			'fs_scope'     => (defined('WPMCP_ALLOW_FULL') && WPMCP_ALLOW_FULL) ? 'wordpress-root' : 'wp-content',
			'fs_base'      => self::base_dir(),
			'rescue_token' => get_option(self::OPT_RESCUE, null),
			'user'         => $user ? $user->user_login : null,
		];
	}

	public static function ep_disconnect() {
		delete_option(self::OPT_TOKENS);
		return ['disconnected' => true, 'note' => 'All connection tokens revoked. Generate a new one in Settings → WP MCP to reconnect.'];
	}

	public static function ep_fs_list($req) {
		$dir = self::resolve($req['path'] ?? '');
		if (is_wp_error($dir)) return $dir;
		if (!is_dir($dir)) return self::err('wpmcp_not_dir', 'Not a directory: ' . ($req['path'] ?? '/'), 404);

		$entries = [];
		foreach (scandir($dir) as $name) {
			if ($name === '.' || $name === '..') continue;
			$p = $dir . '/' . $name;
			$entries[] = [
				'name'     => $name,
				'type'     => is_dir($p) ? 'dir' : 'file',
				'size'     => is_file($p) ? filesize($p) : null,
				'modified' => gmdate('Y-m-d H:i:s', filemtime($p)),
				'writable' => is_writable($p),
			];
		}
		usort($entries, fn($a, $b) => [$a['type'] !== 'dir', $a['name']] <=> [$b['type'] !== 'dir', $b['name']]);
		return ['base' => self::base_dir(), 'path' => $req['path'] ?? '', 'entries' => $entries];
	}

	public static function ep_fs_read($req) {
		$file = self::resolve($req['path'] ?? '');
		if (is_wp_error($file)) return $file;
		if (!is_file($file)) return self::err('wpmcp_not_file', 'Not a file: ' . ($req['path'] ?? ''), 404);
		$size = filesize($file);
		if ($size > self::MAX_READ) return self::err('wpmcp_too_big', "File is {$size} bytes; limit is " . self::MAX_READ, 413);
		$raw = file_get_contents($file);
		return [
			'path'        => $req['path'],
			'size'        => $size,
			'modified'    => gmdate('Y-m-d H:i:s', filemtime($file)),
			'md5'         => md5($raw),
			'content_b64' => base64_encode($raw),
			'has_backup'  => is_file($file . '.wpmcp-bak'),
		];
	}

	public static function ep_fs_write($req) {
		$file = self::resolve($req['path'] ?? '');
		if (is_wp_error($file)) return $file;
		if (is_dir($file)) return self::err('wpmcp_is_dir', 'Target is a directory.', 400);

		if (isset($req['content_b64'])) {
			$content = base64_decode($req['content_b64'], true);
			if ($content === false) return self::err('wpmcp_b64', 'content_b64 is not valid base64.');
		} elseif (isset($req['content'])) {
			$content = (string) $req['content'];
		} else {
			return self::err('wpmcp_no_content', 'Provide content or content_b64.');
		}

		$backed_up = false;
		$existed   = is_file($file);
		if ($existed && empty($req['no_backup']) && substr($file, -10) !== '.wpmcp-bak') {
			$backed_up = copy($file, $file . '.wpmcp-bak');
		}
		if (!wp_mkdir_p(dirname($file))) return self::err('wpmcp_mkdir', 'Could not create parent directory.', 500);
		$bytes = file_put_contents($file, $content);
		if ($bytes === false) return self::err('wpmcp_write', 'Write failed — check permissions.', 500);

		return ['path' => $req['path'], 'bytes' => $bytes, 'created' => !$existed, 'backup' => $backed_up ? $req['path'] . '.wpmcp-bak' : null];
	}

	public static function ep_fs_delete($req) {
		$target = self::resolve($req['path'] ?? '');
		if (is_wp_error($target)) return $target;
		if ($target === self::base_dir()) return self::err('wpmcp_base', 'Refusing to delete the base directory.', 403);
		if (wp_normalize_path(__DIR__) === $target) return self::err('wpmcp_self', 'Refusing to delete the connector plugin itself.', 403);

		if (is_file($target)) {
			if (!unlink($target)) return self::err('wpmcp_delete', 'Delete failed.', 500);
			return ['deleted' => $req['path'], 'type' => 'file'];
		}
		if (is_dir($target)) {
			if (empty($req['recursive'])) return self::err('wpmcp_dir', 'Target is a directory; pass recursive=true to delete it.', 400);
			self::rrmdir($target);
			return ['deleted' => $req['path'], 'type' => 'dir'];
		}
		return self::err('wpmcp_not_found', 'Not found: ' . ($req['path'] ?? ''), 404);
	}

	private static function rrmdir($dir) {
		foreach (scandir($dir) as $name) {
			if ($name === '.' || $name === '..') continue;
			$p = $dir . '/' . $name;
			is_dir($p) ? self::rrmdir($p) : unlink($p);
		}
		rmdir($dir);
	}

	public static function ep_fs_restore($req) {
		$file = self::resolve($req['path'] ?? '');
		if (is_wp_error($file)) return $file;
		$bak = $file . '.wpmcp-bak';
		if (!is_file($bak)) return self::err('wpmcp_no_backup', 'No .wpmcp-bak backup exists for ' . ($req['path'] ?? ''), 404);
		if (!copy($bak, $file)) return self::err('wpmcp_restore', 'Restore failed.', 500);
		return ['restored' => $req['path'], 'from' => $req['path'] . '.wpmcp-bak'];
	}

	public static function ep_install($req) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		$type = ($req['type'] ?? 'plugin') === 'theme' ? 'theme' : 'plugin';
		$url  = $req['zip_url'] ?? null;
		$slug = $req['slug'] ?? null;

		if (!$url) {
			if (!$slug) return self::err('wpmcp_install', 'Provide slug (wp.org) or zip_url.');
			if ($type === 'plugin') {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
				$api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
			} else {
				require_once ABSPATH . 'wp-admin/includes/theme-install.php';
				$api = themes_api('theme_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
			}
			if (is_wp_error($api)) return $api;
			$url = is_object($api) ? $api->download_link : $api['download_link'];
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = $type === 'plugin' ? new Plugin_Upgrader($skin) : new Theme_Upgrader($skin);
		$result   = $upgrader->install($url, ['overwrite_package' => true]);
		if (is_wp_error($result)) return $result;
		if (is_wp_error($skin->result)) return $skin->result;
		if (!$result) return self::err('wpmcp_install', 'Install failed: ' . implode(' | ', $skin->get_upgrade_messages()), 500);

		$out = ['installed' => true, 'type' => $type, 'messages' => $skin->get_upgrade_messages()];

		if ($type === 'plugin') {
			$file = $upgrader->plugin_info();
			$out['plugin'] = $file;
			if (!empty($req['activate']) && $file) {
				$act = activate_plugin($file);
				if (is_wp_error($act)) return $act;
				$out['activated'] = true;
			}
		} else {
			$info = $upgrader->theme_info();
			$stylesheet = $info ? $info->get_stylesheet() : null;
			$out['theme'] = $stylesheet;
			if (!empty($req['activate']) && $stylesheet) {
				switch_theme($stylesheet);
				$out['activated'] = true;
			}
		}
		return $out;
	}

	public static function ep_theme($req) {
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$action     = $req['action'] ?? '';
		$stylesheet = $req['stylesheet'] ?? '';
		if (!$stylesheet) return self::err('wpmcp_theme', 'Provide stylesheet.');

		if ($action === 'activate') {
			if (!wp_get_theme($stylesheet)->exists()) return self::err('wpmcp_theme', 'Theme not found: ' . $stylesheet, 404);
			switch_theme($stylesheet);
			return ['activated' => $stylesheet];
		}
		if ($action === 'delete') {
			if (get_stylesheet() === $stylesheet) return self::err('wpmcp_theme', 'Refusing to delete the active theme.', 403);
			$res = delete_theme($stylesheet);
			if (is_wp_error($res)) return $res;
			return ['deleted' => $stylesheet];
		}
		return self::err('wpmcp_theme', 'Unknown action: ' . $action);
	}

	/* ------------------------------------------------------------- admin UI */

	public static function menu() {
		add_options_page('WP MCP', 'WP MCP', 'manage_options', 'wpmcp', [__CLASS__, 'page']);
	}

	public static function handle_generate() {
		if (!current_user_can('manage_options')) wp_die('Forbidden');
		check_admin_referer('wpmcp_generate');

		$hours  = (float) ($_POST['expiry'] ?? 24);
		$hours  = in_array($hours, [1.0, 8.0, 24.0, 168.0], true) ? $hours : 24.0;
		$token  = 'wpmcp_' . wp_generate_password(40, false, false);

		// One active connection at a time: a new token replaces all previous ones.
		update_option(self::OPT_TOKENS, [[
			'hash'    => password_hash($token, PASSWORD_DEFAULT),
			'user'    => get_current_user_id(),
			'created' => time(),
			'expires' => time() + (int) ($hours * 3600),
		]], false);

		if (!get_option(self::OPT_RESCUE)) self::activate(); // heal missing rescue secret

		set_transient('wpmcp_show_token_' . get_current_user_id(), $token, 300);
		wp_safe_redirect(admin_url('options-general.php?page=wpmcp'));
		exit;
	}

	public static function handle_disconnect() {
		if (!current_user_can('manage_options')) wp_die('Forbidden');
		check_admin_referer('wpmcp_disconnect');
		delete_option(self::OPT_TOKENS);
		wp_safe_redirect(admin_url('options-general.php?page=wpmcp&disconnected=1'));
		exit;
	}

	public static function page() {
		$records   = get_option(self::OPT_TOKENS, []);
		$active    = [];
		foreach ((array) $records as $rec) {
			if (!empty($rec['expires']) && time() > (int) $rec['expires']) continue;
			$active[] = $rec;
		}
		$new_token = get_transient('wpmcp_show_token_' . get_current_user_id());
		if ($new_token) delete_transient('wpmcp_show_token_' . get_current_user_id());
		?>
		<div class="wrap">
			<h1>WP MCP Connector</h1>
			<?php if (!empty($_GET['disconnected'])) : ?>
				<div class="notice notice-success"><p>Disconnected. All tokens revoked.</p></div>
			<?php endif; ?>

			<?php if ($new_token) : ?>
				<div class="notice notice-warning">
					<p><strong>Connection token — copy it now, it is shown only once:</strong></p>
					<p><input type="text" readonly value="<?php echo esc_attr($new_token); ?>" style="width:100%;max-width:640px;font-family:monospace" onclick="this.select()"></p>
					<p>Then tell Claude: <code>connect to <?php echo esc_html(home_url()); ?> with this token</code></p>
				</div>
			<?php endif; ?>

			<h2>Status</h2>
			<?php if ($active) : $rec = $active[0]; ?>
				<table class="widefat" style="max-width:640px">
					<tr><td>Connection</td><td><strong style="color:green">Token active</strong></td></tr>
					<tr><td>Created</td><td><?php echo esc_html(gmdate('Y-m-d H:i', $rec['created'])); ?> UTC by <?php echo esc_html(get_userdata($rec['user'])->user_login ?? '?'); ?></td></tr>
					<tr><td>Expires</td><td><?php echo esc_html(gmdate('Y-m-d H:i', $rec['expires'])); ?> UTC</td></tr>
					<tr><td>Last used</td><td><?php echo !empty($rec['last_used']) ? esc_html(gmdate('Y-m-d H:i', $rec['last_used'])) . ' UTC' : 'never'; ?></td></tr>
				</table>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
					<?php wp_nonce_field('wpmcp_disconnect'); ?>
					<input type="hidden" name="action" value="wpmcp_disconnect">
					<?php submit_button('Disconnect (revoke token)', 'delete', 'submit', false); ?>
				</form>
			<?php else : ?>
				<p>No active connection. Generate a token to connect.</p>
			<?php endif; ?>

			<h2 style="margin-top:24px">Generate connection token</h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('wpmcp_generate'); ?>
				<input type="hidden" name="action" value="wpmcp_generate">
				<label>Valid for:
					<select name="expiry">
						<option value="1">1 hour</option>
						<option value="8">8 hours</option>
						<option value="24" selected>24 hours</option>
						<option value="168">7 days</option>
					</select>
				</label>
				<?php submit_button('Generate token', 'primary', 'submit', false); ?>
			</form>
			<p class="description" style="margin-top:8px">A new token replaces any previous one — one connection at a time. Generating and disconnecting are the whole lifecycle.</p>

			<h2 style="margin-top:24px">Scope</h2>
			<p class="description">
				File access is limited to <code>wp-content</code>. To allow the whole WordPress root
				(e.g. to edit <code>wp-config.php</code>), add <code>define('WPMCP_ALLOW_FULL', true);</code> to wp-config.php.
			</p>
		</div>
		<?php
	}
}

WPMCP_Connector::boot();
