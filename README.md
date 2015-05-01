# CodeIgniter HMVC migration class for CodeIgniter 3.0.0

This class permit to use migrations with HMVC modules (work with <https://bitbucket.org/wiredesignz/codeigniter-modular-extensions-hmvc>)

Usage:

* Import the MY_Migrations.php class into applications/libraries
* Create a file $modules_path/$module_name/config/migration.php into the module. The "module_config_migration_example.php" file show the correct syntax.

In code usage :

	$this->load->library('migration');
	$this->migration->migrate_all_modules();

	if ($this->migration->init_module($module_name))
		$this->migration->current();

	if ($this->migration->init_module($module_name))
		$this->migration->version($module_version);

Note:

This code is not compatible with earlier versions of CodeIgniter!
