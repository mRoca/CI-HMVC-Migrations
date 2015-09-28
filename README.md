# CodeIgniter HMVC migration class

This class permit to use migrations with HMVC modules (work with [wiredesignz/codeigniter-modular-extensions-hmvc](https://bitbucket.org/wiredesignz/codeigniter-modular-extensions-hmvc))

Usage:

* Import the `MY_Migrations.php` class into `applications/libraries`
* Create a file `$modules_path/$module_name/config/migration.php` into the module. The `module_config_migration_example.php` file shows the correct syntax.

In code usage :

```php
$this->load->library('migration');

// Migrates all modules
$this->migration->migrate_all_modules();

// Migrates a module to the current version
if ($this->migration->init_module($module_name)) {
	$this->migration->current();
}

// Migrates a module to a fixed version
if ($this->migration->init_module($module_name)) {
	$this->migration->version($module_version);
}
```
