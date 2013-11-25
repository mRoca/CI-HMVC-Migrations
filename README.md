# CodeIgniter HMVC migration class

This class permit to use migrations with HMVC modules ()

Usage:

* Import the MY_Migrations.php class into applications/libraries
* Create a file $modules_path/$module_name/config/migration.php into the module. The "module_config_migration_example.php" file show the correct syntax.

In code usage :

	$this->load->library('migration');
	$this->migration->migrate_all_modules();

	$this->load->library('migration');
	if ($this->migration->init_module($module_name))
		$this->migration->current();

	$this->load->library('migration');
	if ($this->migration->init_module($module_name))
		$this->migration->version($module_version);