<?php defined('BASEPATH') OR exit('No direct script access allowed');

//Modules helpers, you can move this functions to a true helper file
if (!function_exists('module_exists')) {
    /**
     * Return the CodeIgniter modules list
     * @param bool $with_location
     * @return array
     */
    function modules_list($with_location = TRUE)
    {
        !function_exists('directory_map') && get_instance()->load->helper('directory');

        $modules = array();

        foreach (Modules::$locations as $location => $offset) {

            $files = directory_map($location, 1);
            if (is_array($files)) {
                foreach ($files as $name) {
                    if (is_dir($location . $name))
                        $modules[] = $with_location ? array($location, $name) : $name;
                }
            }
        }

        return $modules;
    }

    /**
     * Check if a CodeIgniter module with the given name exists
     * @param $module_name
     * @return bool
     */
    function module_exists($module_name)
    {
        return in_array($module_name, modules_list(FALSE));
    }
}

if (!function_exists('normalizePath')) {

	/**
	 * Remove the ".." from the middle of a path string
	 * @param string $path
	 * @return string
	 */
	function normalizePath($path)
	{
		$parts    = array(); // Array to build a new path from the good parts
		$path     = str_replace('\\', '/', $path); // Replace backslashes with forwardslashes
		$path     = preg_replace('/\/+/', '/', $path); // Combine multiple slashes into a single slash
		$segments = explode('/', $path); // Collect path segments
		foreach ($segments as $segment) {
			if ($segment != '.') {
				$test = array_pop($parts);
				if (is_null($test))
					$parts[] = $segment;
				else if ($segment == '..') {
					if ($test == '..')
						$parts[] = $test;

					if ($test == '..' || $test == '')
						$parts[] = $segment;
				} else {
					$parts[] = $test;
					$parts[] = $segment;
				}
			}
		}

		return implode('/', $parts);
	}

}


/**
 * Migration Class for HMVC application
 *
 * All migrations should implement this, forces up() and down() and gives
 * access to the CI super-global.
 *
 * Usage :
 * 		$this->load->library('migration');
 * 		$this->migration->migrate_all_modules();
 *
 *		if ($this->migration->init_module($module_name))
 * 			$this->migration->current();
 *
 *		if ($this->migration->init_module($module_name))
 * 			$this->migration->version($module_version);
 *
 *
 * @author        Michel Roca
 */
class MY_Migration
{
	protected $_migration_enabled = FALSE;
	protected $_migration_path = NULL;
	protected $_migration_version = 0;

	protected $_current_module = '';

	protected $_core_config = array();

	protected $_error_string = '';

	public function __construct($config = array())
	{
		# Only run this constructor on main library load
		if (get_parent_class($this) !== FALSE) {
			return;
		}

		$this->_core_config = $config;

		$this->init_module();

		log_message('debug', 'Migrations class initialized');

		// Are they trying to use migrations while it is disabled?
		if ($this->_migration_enabled !== TRUE) {
			show_error('Migrations has been loaded but is disabled or set up incorrectly.');
		}

		// Load migration language
		$this->lang->load('migration');

		// They'll probably be using dbforge
		$this->load->dbforge();

		// If the migrations table is missing, make it
		if (!$this->db->table_exists('migrations')) {
			$this->dbforge->add_field(array(
				'module'  => array('type' => 'VARCHAR', 'constraint' => 20),
				'version' => array('type' => 'INT', 'constraint' => 3),
			));

			$this->dbforge->create_table('migrations', TRUE);

			$this->db->insert('migrations', array('module' => 'CI_core', 'version' => 0));
		}
	}

	public function display_current_migrations()
	{
		$modules = $this->list_all_modules_with_migrations();

		$migrations = array();
		foreach ($modules as $module) {
			$this->init_module($module[1]);
			$migrations[$module[1]] = $this->_get_version($module[1]);
		}

		return $migrations;
	}

	public function display_all_migrations()
	{
		$modules = $this->list_all_modules_with_migrations();

		$migrations = array();
		foreach ($modules as $module) {
			$this->init_module($module[1]);
			$migrations[$module[1]] = $this->find_migrations();
		}

		return $migrations;
	}

	public function migrate_all_modules()
	{
		$modules = $this->list_all_modules_with_migrations();
		foreach ($modules as $module) {
			$this->init_module($module[1]);
			$this->current();
		}

		return TRUE;
	}

	public function list_all_modules_with_migrations()
	{
		$modules = $this->list_all_modules();

		foreach ($modules as $i => $module) {
			list($location, $name) = $module;

			if ($this->init_module($name) !== TRUE)
				unset($modules[$i]);
		}

		return array_merge(array(array('', 'CI_core')), $modules);
	}

	public function list_all_modules()
	{
		return modules_list();
	}

	public function init_module($module = 'CI_core')
	{
		if ($module === 'CI_core') {

			$config = $this->_core_config;
			$config['migration_path'] == '' AND $config['migration_path'] = APPPATH . 'migrations/';

		} else {

			list($path, $file) = Modules::find('migration', $module, 'config/');

			if ($path === FALSE)
				return FALSE;

			if (!$config = Modules::load_file($file, $path, 'config'))
				return FALSE;

			!$config['migration_path'] AND $config['migration_path'] = '../migrations';

			$config['migration_path'] = normalizePath($path . $config['migration_path']);

		}

		foreach ($config as $key => $val) {
			$this->{'_' . $key} = $val;
		}

		if ($this->_migration_enabled !== TRUE)
			return FALSE;

		$this->_migration_path = rtrim($this->_migration_path, '/') . '/';

		if (!file_exists($this->_migration_path))
			return FALSE;

		$this->_current_module = $module;

		return TRUE;
	}

	/**
	 * Migrate to a schema version
	 *
	 * Calls each migration step required to get to the schema version of
	 * choice
	 *
	 * @param    int $target_version Target schema version
	 * @return    mixed    TRUE if already latest, FALSE if failed, int if upgraded
	 */
	public function version($target_version)
	{
		$start = $current_version = $this->_get_version();
		$stop  = $target_version;

		if ($target_version > $current_version) {
			// Moving Up
			++$start;
			++$stop;
			$step = 1;
		} else {
			// Moving Down
			$step = -1;
		}

		$method     = ($step === 1) ? 'up' : 'down';
		$migrations = array();

		// We now prepare to actually DO the migrations
		// But first let's make sure that everything is the way it should be
		for ($i = $start; $i != $stop; $i += $step) {
			$f = glob(sprintf($this->_migration_path . '%03d_*.php', $i));

			// Only one migration per step is permitted
			if (count($f) > 1) {
				$this->_error_string = sprintf($this->lang->line('migration_multiple_version'), $i);
				return FALSE;
			}

			// Migration step not found
			if (count($f) == 0) {
				// If trying to migrate up to a version greater than the last
				// existing one, migrate to the last one.
				if ($step == 1) {
					break;
				}

				// If trying to migrate down but we're missing a step,
				// something must definitely be wrong.
				$this->_error_string = sprintf($this->lang->line('migration_not_found'), $i);

				return FALSE;
			}

			$file = basename($f[0]);
			$name = basename($f[0], '.php');

			// Filename validations
			if (preg_match('/^\d{3}_(\w+)$/', $name, $match)) {
				$match[1] = strtolower($match[1]);

				// Cannot repeat a migration at different steps
				if (in_array($match[1], $migrations)) {
					$this->_error_string = sprintf($this->lang->line('migration_multiple_version'), $match[1]);

					return FALSE;
				}

				include $f[0];
				$class = 'Migration_' . ucfirst($match[1]);

				if (!class_exists($class)) {
					$this->_error_string = sprintf($this->lang->line('migration_class_doesnt_exist'), $class);

					return FALSE;
				}

				if (!is_callable(array($class, $method))) {
					$this->_error_string = sprintf($this->lang->line('migration_missing_' . $method . '_method'), $class);

					return FALSE;
				}

				$migrations[] = $match[1];
			} else {
				$this->_error_string = sprintf($this->lang->line('migration_invalid_filename'), $file);

				return FALSE;
			}
		}

		log_message('debug', 'Current migration: ' . $current_version);

		$version = $i + ($step == 1 ? -1 : 0);

		// If there is nothing to do so quit
		if ($migrations === array()) {
			return TRUE;
		}

		log_message('debug', 'Migrating from ' . $method . ' to version ' . $version);

		// Loop through the migrations
		foreach ($migrations AS $migration) {
			// Run the migration class
			$class = 'Migration_' . ucfirst(strtolower($migration));
			call_user_func(array(new $class, $method));

			$current_version += $step;
			$this->_update_version($current_version);
		}

		log_message('debug', 'Finished migrating to ' . $current_version);

		return $current_version;
	}

// --------------------------------------------------------------------

	/**
	 * Set's the schema to the latest migration
	 *
	 * @return    mixed    true if already latest, false if failed, int if upgraded
	 */
	public function latest()
	{
		if (!$migrations = $this->find_migrations()) {
			$this->_error_string = $this->lang->line('migration_none_found');

			return false;
		}

		$last_migration = basename(end($migrations));

		// Calculate the last migration step from existing migration
		// filenames and procceed to the standard version migration
		return $this->version((int)substr($last_migration, 0, 3));
	}

// --------------------------------------------------------------------

	/**
	 * Set's the schema to the migration version set in config
	 *
	 * @return    mixed    true if already current, false if failed, int if upgraded
	 */
	public function current()
	{
		return $this->version($this->_migration_version);
	}

// --------------------------------------------------------------------

	/**
	 * Error string
	 *
	 * @return    string    Error message returned as a string
	 */
	public function error_string()
	{
		return $this->_error_string;
	}

// --------------------------------------------------------------------

	/**
	 * Set's the schema to the latest migration
	 *
	 * @return    mixed    true if already latest, false if failed, int if upgraded
	 */
	protected function find_migrations()
	{
		// Load all *_*.php files in the migrations path
		$files      = glob($this->_migration_path . '*_*.php');
		$file_count = count($files);

		for ($i = 0; $i < $file_count; $i++) {
			// Mark wrongly formatted files as false for later filtering
			$name = basename($files[$i], '.php');
			if (!preg_match('/^\d{3}_(\w+)$/', $name)) {
				$files[$i] = FALSE;
			}
		}

		sort($files);

		return $files;
	}

// --------------------------------------------------------------------

	/**
	 * Retrieves current schema version
	 *
	 * @param string $module
	 * @return    int    Current Migration
	 */
	protected function _get_version($module = '')
	{
		! $module AND $module = $this->_current_module;
		$row = $this->db->get_where('migrations', array('module' => $module))->row();
		return $row ? $row->version : 0;
	}

// --------------------------------------------------------------------

	/**
	 * Stores the current schema version
	 *
	 * @param    int    Migration reached
	 * @param string $module
	 * @return    bool
	 */
	protected function _update_version($migrations, $module = '')
	{
		! $module AND $module = $this->_current_module;
		$row = $this->db->get_where('migrations', array('module' => $module))->row();
		if (count($row)) {
			return $this->db->where(array('module' => $module))->update('migrations', array('version' => $migrations));
		} else {
			return $this->db->insert('migrations', array('module' => $module, 'version' => $migrations));
		}
	}

// --------------------------------------------------------------------

	/**
	 * Enable the use of CI super-global
	 *
	 * @param    mixed $var
	 * @return    mixed
	 */
	public function __get($var)
	{
		return get_instance()->$var;
	}
}

/* End of file Migration.php */
/* Location: ./system/libraries/Migration.php */
