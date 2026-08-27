<?php
declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

if(is_multisite())
{
	foreach(get_sites(['fields' => 'ids']) as $ovosConsoleSiteId)
	{
		switch_to_blog((int)$ovosConsoleSiteId);
		delete_option('ovos_console');
		delete_option('ovos_console_inventory');
		delete_option('ovos_console_inventory_dirty');
		restore_current_blog();
	}
	
	return;
}

delete_option('ovos_console');
delete_option('ovos_console_inventory');
delete_option('ovos_console_inventory_dirty');
