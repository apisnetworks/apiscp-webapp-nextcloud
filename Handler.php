<?php
	/**
 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
 *
 * Unauthorized copying of this file, via any medium, is
 * strictly prohibited without consent. Any dissemination of
 * material herein is prohibited.
 *
 * For licensing inquiries email <licensing@apisnetworks.com>
 *
 * Written by Matt Saladna <matt@apisnetworks.com>, August 2020
 */

	namespace Module\Support\Webapps\App\Type\Nextcloud;

	use Module\Support\Webapps\App\Type\Unknown\Handler as Unknown;

	class Handler extends Unknown
	{
		const NAME = 'Nextcloud';
		const ADMIN_PATH = '/login';
		const LINK = 'https://nextcloud.com';

		const FEAT_ALLOW_SSL = true;
		const FEAT_RECOVERY = false;
		const TRANSIENT_RECONFIGURABLES = ['migrate'];

		public function changePassword(string $password): bool
		{
			return $this->nextcloud_change_admin($this->hostname, $this->path, ['password' => $password]);
		}
	}