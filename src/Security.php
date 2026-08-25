<?php
declare(strict_types=1);

namespace OvosConsole;

use WP_Error;

use function implode;
use function in_array;
use function is_array;
use function is_object;
use function is_scalar;
use function method_exists;

/**
 * Security-event hooks — reports what WordPress REFUSED, beside what broke,
 * as type=security events (see Sender::reportRefusal). Unlike the console's
 * PHP-library senders, where placing the reportRefusal() call is itself the
 * developer's opt-in, this plugin places every hook — so the whole layer is
 * gated by the security_events setting and none of these callbacks are even
 * registered while it is off.
 *
 * Failed logins funnel through wp_login_failed regardless of the door
 * (wp-login form, XML-RPC, REST basic auth); rejected nonce checks are the
 * CSRF signal; forbidden REST calls surface permission probing; and the
 * privileged_action family records the changes an attacker makes AFTER
 * getting in (role grants, plugin installs, signup/URL option flips) —
 * routine for an admin, an audit trail during an incident.
 */
class Security
{
	/**
	 * Options whose change is a classic post-compromise move — new admins
	 * via open signup, redirected site URLs, hijacked recovery e-mail
	 */
	protected const SENSITIVE_OPTIONS = [
		'users_can_register',
		'default_role',
		'admin_email',
		'siteurl',
		'home',
	];
	
	public function __construct(
		protected Sender $sender,
	)
	{
	}
	
	public function register(): void
	{
		add_action('wp_login_failed', [$this, 'reportLoginFailed'], 10, 2);
		add_action('application_password_failed_authentication', [$this, 'reportAppPasswordFailed']);
		add_action('check_admin_referer', [$this, 'reportNonceFailure'], 10, 2);
		add_action('check_ajax_referer', [$this, 'reportNonceFailure'], 10, 2);
		add_filter('rest_request_after_callbacks', [$this, 'inspectRestResponse'], 10, 3);
		add_action('set_user_role', [$this, 'reportRoleChange'], 10, 3);
		add_action('activated_plugin', [$this, 'reportPluginActivated']);
		add_action('upgrader_process_complete', [$this, 'reportUpgrade'], 10, 2);
		add_action('updated_option', [$this, 'reportOptionChange']);
	}
	
	/**
	 * wp_login_failed — every failed credential presentation passes here,
	 * whatever the door (wp-login, XML-RPC, REST basic auth). The username
	 * is masked; the WP_Error CODES (invalid_username, incorrect_password)
	 * say why without quoting core's HTML error messages.
	 */
	public function reportLoginFailed(
		$username,
		$error = null,
	): void
	{
		$message = 'login failed for ' . Redactor::maskName(
			is_scalar($username) ? (string)$username : '');
			
		if($error instanceof WP_Error && $error->get_error_codes() !== [])
		{
			$message .= ': ' . implode(', ', $error->get_error_codes());
		}
		
		$this->sender->reportRefusal('auth_failure', $message);
	}
	
	/**
	 * A rejected application password — the API-access door, separate from
	 * interactive logins
	 */
	public function reportAppPasswordFailed(
		$error = null,
	): void
	{
		$message = 'application password rejected';
		
		if($error instanceof WP_Error && $error->get_error_codes() !== [])
		{
			$message .= ': ' . implode(', ', $error->get_error_codes());
		}
		
		$this->sender->reportRefusal('auth_failure', $message);
	}
	
	/**
	 * check_admin_referer / check_ajax_referer fire their action on every
	 * check — $result is false only when the nonce was missing or invalid,
	 * which is the CSRF (or expired-session replay) signal
	 */
	public function reportNonceFailure(
		$action,
		$result = null,
	): void
	{
		if($result !== false)
		{
			return;
		}
		
		$this->sender->reportRefusal('csrf_reject',
			'nonce check failed for action '
			. (is_scalar($action) ? (string)$action : '?'));
	}
	
	/**
	 * rest_request_after_callbacks (filter — the response passes through
	 * untouched): a WP_Error answered 401/403 is a permission refusal, the
	 * signal of REST probing (user enumeration, capability testing)
	 */
	public function inspectRestResponse(
		$response,
		$handler = null,
		$request = null,
	)
	{
		if($response instanceof WP_Error)
		{
			$data = $response->get_error_data();
			$status = is_array($data) ? (int)($data['status'] ?? 0) : 0;
			
			if($status === 401 || $status === 403)
			{
				$route = is_object($request) && method_exists($request, 'get_route')
					? (string)$request->get_route()
					: '';
					
				$this->sender->reportRefusal('permission_denied',
					'REST request refused (' . $status . '): '
					. (string)$response->get_error_code()
					. ($route !== '' ? ' on ' . $route : ''));
			}
		}
		
		return $response;
	}
	
	/**
	 * set_user_role — a role grant is the classic post-compromise move;
	 * user id and role names only, never the account's identity
	 */
	public function reportRoleChange(
		$userId,
		$role = '',
		$oldRoles = [],
	): void
	{
		$this->sender->reportRefusal('privileged_action',
			'user role changed: #' . (int)$userId
			. ' ' . implode(',', (array)$oldRoles)
			. ' -> ' . (is_scalar($role) ? (string)$role : '?'));
	}
	
	public function reportPluginActivated(
		$plugin = '',
	): void
	{
		$this->sender->reportRefusal('privileged_action',
			'plugin activated: ' . (is_scalar($plugin) ? (string)$plugin : '?'));
	}
	
	/**
	 * upgrader_process_complete — plugin/theme/core installs and updates
	 * (a webshell often arrives AS a plugin upload); routine translation
	 * downloads are skipped
	 */
	public function reportUpgrade(
		$upgrader = null,
		$extra = [],
	): void
	{
		if(is_array($extra) === false)
		{
			return;
		}
		
		$type = is_scalar($extra['type'] ?? null) ? (string)$extra['type'] : '';
		$action = is_scalar($extra['action'] ?? null) ? (string)$extra['action'] : '';
		
		if(in_array($type, ['plugin', 'theme', 'core'], true) === false)
		{
			return;
		}
		
		$this->sender->reportRefusal('privileged_action',
			'upgrader: ' . ($action !== '' ? $action : '?') . ' ' . $type);
	}
	
	/**
	 * updated_option fires only on an actual change; the values are
	 * deliberately not reported (admin_email is an address, siteurl may
	 * embed credentials) — the fact of the change is the signal
	 */
	public function reportOptionChange(
		$option = '',
	): void
	{
		if(in_array($option, self::SENSITIVE_OPTIONS, true) === false)
		{
			return;
		}
		
		$this->sender->reportRefusal('privileged_action',
			'option changed: ' . (string)$option);
	}
}
