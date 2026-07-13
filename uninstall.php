<?php
declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

if(is_multisite())
{
	foreach(get_sites(['fields' => 'ids']) as $siteId)
	{
		switch_to_blog((int)$siteId);
		delete_option('ovos_console');
		restore_current_blog();
	}
	
	return;
}

delete_option('ovos_console');
