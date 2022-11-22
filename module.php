<?php
	/**
	 *  +------------------------------------------------------------+
	 *  | apnscp                                                     |
	 *  +------------------------------------------------------------+
	 *  | Copyright (c) Apis Networks                                |
	 *  +------------------------------------------------------------+
	 *  | Licensed under Artistic License 2.0                        |
	 *  +------------------------------------------------------------+
	 *  | Author: Matt Saladna (msaladna@apisnetworks.com)           |
	 *  +------------------------------------------------------------+
	 */

	use Module\Support\Webapps\App\Type\Nextcloud\TreeWalker;
	use Module\Support\Webapps\Composer;
	use Module\Support\Webapps\DatabaseGenerator;
	use Module\Support\Webapps\VersionFetcher\Github;

	/**
	 * Nextcloud management
	 *
	 * @package core
	 */
	class Nextcloud_Module extends Composer
	{
		const APP_NAME = 'Nextcloud';
		// @todo pull from github.com/nextcloud/server ?
		const PACKAGIST_NAME =  'christophwurst/nextcloud';

		protected $aclList = array(
			'min' => array(
				'apps',
				'config',
				'data'
			),
			'max' => array(
				'config',
				'data'
			)
		);

		/**
		 * Install Nextcloud into a pre-existing location
		 *
		 * @param string $hostname domain or subdomain to install Laravel
		 * @param string $path     optional path under hostname
		 * @param array  $opts     additional install options
		 * @return bool
		 */
		public function install(string $hostname, string $path = '', array $opts = array()): bool
		{
			if (!$this->mysql_enabled()) {
				return error('%(what)s must be enabled to install %(app)s',
					['what' => 'MySQL', 'app' => static::APP_NAME]);
			}
			if (!version_compare($this->php_version(), '7.2', '>=')) {
				return error('%(app)s requires PHP %(minver).1f',
					['app' => static::APP_NAME, 'minver' => 7.2]
				);
			}

			if (!$this->php_jailed()) {
				return error("%s requires PHP-FPM by setting apache,jail=1 in service configuration", static::APP_NAME);
			}

			if (!$this->php_composer_exists()) {
				return error('composer missing! contact sysadmin');
			}

			if (!$this->hasMemoryAllowance(512, $available)) {
				return error("%(app)s requires at least %(min)d MB memory, `%(found)d' MB provided for account",
					['app' => 'Nextcloud', 'min' => 512, 'found' => $available]);
			}

			// Same situation as with Ghost. We can't install under a path for fear of
			// leaking information
			if ($path) {
				return error('Composer projects may only be installed directly on a subdomain or domain without a child path, e.g. https://domain.com but not https://domain.com/path');
			}

			if (!($docroot = $this->getDocumentRoot($hostname, $path))) {
				return error("failed to normalize path for `%s'", $hostname);
			}

			if (!$this->parseInstallOptions($opts, $hostname, $path)) {
				return false;
			}

			if (isset($opts['datadir']) && !$this->checkDataDirectory($opts['datadir'])) {
				return false;
			}

			$args['version'] = $opts['version'];

			$dlUrl = 'https://download.nextcloud.com/server/releases/nextcloud-' . $opts['version'] . '.tar.bz2';
			$oldex = \Error_Reporter::exception_upgrade();
			$approot = $this->getAppRoot($hostname, $path);
			try {
				$this->download($dlUrl, "$docroot/nextcloud.tar.bz2");
				$this->file_move("$docroot/nextcloud/", $docroot) && $this->file_delete("$docroot/nextcloud", true);
				$db = DatabaseGenerator::mysql($this->getAuthContext(), $hostname);
				$db->connectionLimit = max($db->connectionLimit, 15);
				$db->dbArgs = [
					'utf8mb4',
					'utf8mb4_general_ci'
				];
				if (!$db->create()) {
					return false;
				}
				if (!isset($opts['password'])) {
					$password = \Opcenter\Auth\Password::generate();
					info("autogenerated password `%s'", $password);
				}
				$ret = $this->execPhp($docroot, 'occ maintenance:install --database=%(dbtype)s ' .
					'--database-name=%(dbname)s --database-pass=%(dbpassword)s --database-user=%(dbuser)s ' .
					'--admin-user=%(admin)s --admin-pass=%(passwd)s %(datadir)s', [
						'dbtype' => $db->kind,
						'dbname' => $db->database,
						'dbpassword' => $db->password,
						'dbuser' => $db->username,
						'admin' => $this->username,
						'passwd' => $password ?? $opts['password'],
						'datadir' => !empty($opts['datadir']) ? '--data-dir=' . escapeshellarg($opts['datadir']) : null
				]);

				if (!$ret['success']) {
					return error("Failed to run occ maintenance:install: %s", coalesce($ret['stderr'], $ret['stdout']));
				}
				$this->reconfigure($hostname, $path, 'migrate', $hostname);
				$this->file_chmod($approot . '/config/config.php', 604);
				$this->file_set_acls($docroot . '/config/config.php', [$this->web_get_user($docroot, $path) => 'r'], null, [File_Module::ACL_NO_RECALC_MASK => false]);
			} catch (\apnscpException $e) {
				$this->file_delete($approot, true);
				return error('Failed to install %s: %s', static::APP_NAME, $e->getMessage());
			} finally {
				\Error_Reporter::exception_upgrade($oldex);
			}

			// by default, let's only open up ACLs to the bare minimum

			$this->initializeMeta($docroot, $opts);

			$this->writeConfiguration($approot, 'htaccess.RewriteBase', '/' . trim($path, '/'));
			$this->fixRewriteBase($docroot);

			$this->fortify($hostname, $path, 'max');

			$this->notifyInstalled($hostname, $path, ['password' => $password ?? ''] + $opts);

			return info('%(app)s installed - confirmation email with login info sent to %(email)s',
				['app' => static::APP_NAME, 'email' => $opts['email']]);
		}

		/**
		 * Validate datadir parameter
		 *
		 * @param string $directory
		 * @return bool
		 */
		private function checkDataDirectory(string $directory): bool
		{
			if (!$this->file_file_exists($directory)) {
				return $this->file_create_directory($directory, 0711);
			}

			return !count($this->file_get_directory_contents($directory)) ?:
				error("Data directory `%s' is not empty", $directory);
		}

		protected function checkVersion(array &$options): bool
		{
			if (!parent::checkVersion($options)) {
				return false;
			}
			$phpversion = $this->php_version();

			$cap = null;

			if ($cap && version_compare($options['version'], $cap, '>=')) {
				info("PHP version `%s' detected, capping Nextcloud to %s", $phpversion, $cap);
				$options['version'] = $cap;
			}

			return true;
		}

		/**
		 * Restrict write-access by the app
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param string $mode
		 * @param array  $args
		 * @return bool
		 */
		public function fortify(string $hostname, string $path = '', string $mode = 'max', $args = []): bool
		{
			$this->writeConfiguration($this->getAppRoot($hostname, $path), 'config_is_read_only', false);
			return parent::fortify($hostname, $path, $mode, $args) &&
				$this->setLockdown($hostname, $path, $mode === 'max');
		}

		/**
		 * Get installed version
		 *
		 * @param string $hostname
		 * @param string $path
		 * @return string version number
		 */
		public function get_version(string $hostname, string $path = ''): ?string
		{
			$approot = $this->getAppRoot($hostname, $path);
			$ret = $this->execPhp($approot, 'occ --no-warnings -V');
			if (!$ret['success']) {
				return null;
			}
			$pos = strrpos($ret['stdout'], ' ');
			return trim(substr($ret['stdout'], $pos));
		}

		/**
		 * Location is a valid Laravel install
		 *
		 * @param string $hostname or $docroot
		 * @param string $path
		 * @return bool
		 */
		public function valid(string $hostname, string $path = ''): bool
		{
			if ($hostname[0] === '/') {
				if (!($path = realpath($this->domain_fs_path($hostname)))) {
					return false;
				}
			} else {
				$approot = $this->getAppRoot($hostname, $path);
				if (!$approot) {
					return false;
				}
				$path = $this->domain_fs_path($approot);
			}
			return file_exists($path . '/occ') && is_file($path . '/occ');
		}

		/**
		 * Get all available Ghost versions
		 *
		 * @return array
		 */
		public function get_versions(): array
		{
			$versions = $this->_getVersions();

			return array_column($versions, 'version');
		}

		/**
		 * Get all current major versions
		 *
		 * @return array
		 */
		protected function _getVersions(string $name = null)
		{
			$key = 'nextcloud.versions';
			$cache = Cache_Super_Global::spawn();
			if (false !== ($ver = $cache->get($key))) {
				return $name ? ($ver[$name] ?? []) : (array)$ver;
			}
			$versions = (new Github)->setMode('tags')->fetch('nextcloud/server');
			$versions = array_filter(array_combine(array_column($versions, 'version'), $versions),
				static function ($v) {
					return strspn($v['version'], '0123456789.') === \strlen($v['version']);
				});
			$cache->set($key, $versions, 43200);

			return $name ? ($versions[$name] ?? []) : $versions;
		}


		/**
		 * Uninstall Laravel from a location
		 *
		 * @param        $hostname
		 * @param string $path
		 * @param string $delete remove all files under docroot
		 * @return bool
		 */
		public function uninstall(string $hostname, string $path = '', string $delete = 'all'): bool
		{
			return parent::uninstall($hostname, $path, $delete);
		}

		/**
		 * Get database configuration for a blog
		 *
		 * @param string $hostname domain or subdomain of wp blog
		 * @param string $path     optional path
		 * @return array|bool
		 */
		public function db_config(string $hostname, string $path = '')
		{
			$this->web_purge();
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('failed to determine %s', self::APP_NAME);
			}
			$code = 'include("./config/config.php"); ' .
				'print serialize(array("user" => $CONFIG["dbuser"], "password" => $CONFIG["dbpassword"], "db" => $CONFIG["dbname"], ' .
				'"host" => $CONFIG["dbhost"], "prefix" => $CONFIG["dbtableprefix"]));';
			$cmd = 'cd %(path)s && php -d mysqli.default_socket=' . escapeshellarg(ini_get('mysqli.default_socket')) . ' -r %(code)s';
			$ret = $this->pman_run($cmd, ['path' => $docroot, 'code' => $code]);

			if (!$ret['success']) {
				return error("failed to obtain Nextcloud configuration for `%s'", $docroot);
			}
			$data = \Util_PHP::unserialize($ret['stdout']);

			return $data;
		}

		public function update_all(string $hostname, string $path = '', string $version = null): bool
		{
			return $this->update($hostname, $path, $version) || error('failed to update all components');
		}

		protected function getAppRoot(string $hostname, string $path = ''): ?string
		{
			return $this->getDocumentRoot($hostname, $path);
		}

		/**
		 * Update Nextcloud to latest version
		 *
		 * @param string $hostname domain or subdomain under which WP is installed
		 * @param string $path     optional subdirectory
		 * @param string $version  version to upgrade
		 * @return bool
		 */
		public function update(string $hostname, string $path = '', string $version = null): bool
		{
			$docroot = $this->getDocumentRoot($hostname, $path);
			if (!$docroot) {
				return error('update failed');
			}
			$newversion = $version ?? \Opcenter\Versioning::maxVersion(
				$this->get_versions(),
				$this->parseLock(
					\Module\Support\Webapps\App\Loader::fromDocroot('nextcloud', $docroot, $this->getAuthContext())->getOptions()['verlock'] ?? self::DEFAULT_VERSION_LOCK,
					$this->get_version($hostname, $path)
				)
			);
			$ret = serial(function () use ($docroot, $newversion, $hostname, $path) {
				$dlUrl = 'https://download.nextcloud.com/server/releases/nextcloud-' . $newversion . '.tar.bz2';
				$this->download($dlUrl, "$docroot/nextcloud.tar.bz2");
				$this->pman_run('cd %(chdir)s && mv -f nextcloud/{*,.*} .', ['chdir' => $docroot], null, ['user' => $this->getDocrootUser($docroot)]);
				$this->file_delete("$docroot/nextcloud", true);
				$this->writeConfiguration($docroot, 'config_is_read_only', false);
				$ret = $this->execPhp($docroot, 'occ --no-warnings upgrade');
				if ($ret['success']) {
					$this->execPhp($docroot, 'occ --no-warnings maintenance:mode --off');
				}

				$this->fortify($hostname, $path, array_get($this->getOptions($docroot), 'fortify') ?: 'max');
				return $ret['success'] ?: error("Failed to upgrade %s: %s", static::APP_NAME, $ret['stderr']);
			});

			$this->setInfo($docroot, [
				'version' => $newversion,
				'failed'  => (bool)$ret
			]);

			return (bool)$ret;
		}

		/**
		 * Update Nextcloud plugins
		 *
		 * @param string $hostname domain or subdomain
		 * @param string $path     optional path within host
		 * @param array  $plugins
		 * @return bool
		 */
		public function update_plugins(string $hostname, string $path = '', array $plugins = array()): bool
		{
			return parent::update_plugins($hostname, $path, $plugins);
		}

		/**
		 * Update Nextcloud themes
		 *
		 * @param string $hostname subdomain or domain
		 * @param string $path     optional path under hostname
		 * @param array  $themes
		 * @return bool
		 */
		public function update_themes(string $hostname, string $path = '', array $themes = array()): bool
		{
			return parent::update_themes($hostname, $path, $themes);
		}

		/**
		 * @inheritDoc
		 */
		public function has_fortification(string $hostname, string $path = '', string $mode = null): bool
		{
			return parent::has_fortification($hostname, $path, $mode);
		}

		/**
		 * @inheritDoc
		 */
		public function fortification_modes(string $hostname, string $path = ''): array
		{
			return parent::fortification_modes($hostname, $path);
		}

		/**
		 * Relax permissions to allow write-access
		 *
		 * @param string $hostname
		 * @param string $path
		 * @return bool
		 * @internal param string $mode
		 */
		public function unfortify(string $hostname, string $path = ''): bool
		{
			return parent::unfortify($hostname, $path) &&
				$this->setLockdown($hostname, $path, false);
		}

		private function setLockdown(string $hostname, string $path, bool $enabled): bool
		{
			$approot = $this->getAppRoot($hostname, $path);
			$ret = $this->writeConfiguration($approot, 'appstoreenabled', !$enabled) && $this->writeConfiguration($approot,
					'config_is_read_only', $enabled);
			if ($ret) {
				$this->file_set_acls(
					$approot . '/config/config.php',
					[$this->web_get_user($hostname, $path) => 'rw']
				);
			}
			return $ret;
		}

		private function writeConfiguration(string $approot, string $var, $val): bool
		{
			if ($var === 'config_is_read_only') {
				return $this->directWrite($approot, $var, $val);
			}
			$args = [
				'var'  => $var,
				'idx'  => is_array($val) ? key($val) : null,
				'type' => is_array($val) ? current($val) : gettype($val),
				'val'  => $val
			];
			if (is_bool($val)) {
				$args['val'] = $val ? 'true' : 'false';
			}
			$ret = $this->execPhp(
				$approot,
				'occ --no-warnings config:system:set %(var)s %(idx)s --type=%(type)s --value=%(val)s', $args);
			return $ret['success'] ?: error("Failed to set %s: %s", $var, $ret['stderr']);
		}

		private function directWrite(string $approot, string $var, $val): bool
		{
			return TreeWalker::instantiateContexted($this->getAuthContext(), [$approot . '/config/config.php'])->set($var, $val)->save();
		}

		public function plugin_status(string $hostname, string $path = '', string $plugin = null)
		{
			return false;
		}

		public function install_plugin(
			string $hostname,
			string $path,
			string $plugin,
			string $version = 'stable'
		): bool {
			return false;
		}

		public function uninstall_plugin(string $hostname, string $path, string $plugin, bool $force = false): bool
		{
			return false;
		}

		public function disable_all_plugins(string $hostname, string $path = ''): bool
		{
			return false;
		}

		public function theme_status(string $hostname, string $path = '', string $theme = null)
		{
			return parent::theme_status($hostname, $path, $theme); // TODO: Change the autogenerated stub
		}

		public function install_theme(string $hostname, string $path, string $theme, string $version = null): bool
		{
			return parent::install_theme($hostname, $path, $theme, $version);
		}

		/**
		 * Change admin values
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param array  $fields valid fields: "password"
		 * @return bool
		 */
		public function change_admin(string $hostname, string $path, array $fields): bool
		{
			if (null === ($admin = $this->get_admin($hostname, $path))) {
				return error("Cannot detect admin");
			}
			$approot = $this->getAppRoot($hostname, $path);
			if ($password = array_pull($fields, 'password')) {
				$ret = $this->execPhp($approot, 'occ user:resetpassword --no-warnings --password-from-env %s', [$admin], ['OC_PASS' => $password]);
				if (!$ret['success']) {
					return error("Failed to change password for %s: %s", $admin, $ret['stdout']);
				}
			}

			return true;
		}

		public function get_admin(string $hostname, string $path = ''): ?string
		{
			$approot = $this->getAppRoot($hostname, $path);
			$ret = $this->execPhp($approot, 'occ -i --output=json --no-warnings user:list');
			if (!$ret['success']) {
				return null;
			}
			return array_first(json_decode($ret['stdout'], true), static function ($v) {
				return in_array('admin', $v['groups'], true);
			})['user_id'] ?? null;
		}

		/**
		 * @inheritDoc
		 */
		public function reconfigure(string $hostname, string $path, $param, $value = null): bool
		{
			if (!is_array($param)) {
				$param = [$param => $value];
				$value = null;
			}
			if (array_pull($param, 'migrate')) {
				$approot = $this->getAppRoot($hostname, $path);
				$this->execPhp($approot, 'occ config:system:set trusted_domains 1 --value=%s', [$hostname]);
			}

			if (empty($param)) {
				return true;
			}

			return parent::reconfigure($hostname, $path, $param, $value); // TODO: Change the autogenerated stub
		}

		/**
		 * @inheritDoc
		 */
		public function reconfigurables(string $hostname, string $path = ''): array
		{
			return parent::reconfigurables($hostname, $path);
		}
	}
