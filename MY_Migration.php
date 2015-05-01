 <?php defined('BASEPATH') OR exit('No direct script access allowed');
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
	protected $_migration_type = 'timestamp';
	protected $_migration_table = 'migrations';
	protected $_migration_auto_latest = FALSE;
	protected $_migration_regex = NULL;
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

        // If not set, set it
		$this->_migration_path !== '' OR $this->_migration_path = APPPATH.'migrations/';

		// Add trailing slash if not set
		$this->_migration_path = rtrim($this->_migration_path, '/').'/';

		// Load migration language
		$this->lang->load('migration');

		// They'll probably be using dbforge
		$this->load->dbforge();
		// Make sure the migration table name was set.
		if (empty($this->_migration_table))
		{
			show_error('Migrations configuration file (migration.php) must have "migration_table" set.');
		}

		// Migration basename regex
		$this->_migration_regex = ($this->_migration_type === 'timestamp')
			? '/^\d{14}_(\w+)$/'
			: '/^\d{3}_(\w+)$/';

		// Make sure a valid migration numbering type was set.
		if ( ! in_array($this->_migration_type, array('sequential', 'timestamp')))
		{
			show_error('An invalid migration numbering type was specified: '.$this->_migration_type);
		}

		// If the migrations table is missing, make it
		if ( ! $this->db->table_exists($this->_migration_table))
		{
			$this->dbforge->add_field(array(
				'module'  => array('type' => 'VARCHAR', 'constraint' => 20),
				'version' => array('type' => 'BIGINT', 'constraint' => 20),
			));

			$this->dbforge->create_table($this->_migration_table, TRUE);

			$this->db->insert($this->_migration_table, array('version' => 0));
		}

		// Do we auto migrate to the latest migration?
		if ($this->_migration_auto_latest === TRUE && ! $this->latest())
		{
			show_error($this->error_string());
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
			if ($path === FALSE) {
				return FALSE;
            }

			if (!$config = Modules::load_file($file, $path, 'config')) {
				return FALSE;
            }

			!$config['migration_path'] AND $config['migration_path'] = '../migrations';
			$config['migration_path'] = normalizePath($path . $config['migration_path']);
		}
		foreach ($config as $key => $val) {
			$this->{'_' . $key} = $val;
		}
		if ($this->_migration_enabled !== TRUE) {
			return FALSE;
        }

		$this->_migration_path = rtrim($this->_migration_path, '/') . '/';

		if (!file_exists($this->_migration_path)) {
			return FALSE;
        }
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
		// Note: We use strings, so that timestamp versions work on 32-bit systems
		$current_version = $this->_get_version();

		if ($this->_migration_type === 'sequential') {
			$target_version = sprintf('%03d', $target_version);
		} else {
			$target_version = (string) $target_version;
		}

		$migrations = $this->find_migrations();

		if ($target_version > 0 && ! isset($migrations[$target_version])) {
			$this->_error_string = sprintf($this->lang->line('migration_not_found'), $target_version);
			return FALSE;
		}

		if ($target_version > $current_version) {
			// Moving Up
			$method = 'up';
		} else {
			// Moving Down, apply in reverse order
			$method = 'down';
			krsort($migrations);
		}

		if (empty($migrations)) {
			return TRUE;
		}

		$previous = FALSE;

		// Validate all available migrations, and run the ones within our target range
		foreach ($migrations as $number => $file)
		{
			// Check for sequence gaps
			if ($this->_migration_type === 'sequential' && $previous !== FALSE && abs($number - $previous) > 1)
			{
				$this->_error_string = sprintf($this->lang->line('migration_sequence_gap'), $number);
				return FALSE;
			}

			include_once($file);
			$class = 'Migration_'.ucfirst(strtolower($this->_get_migration_name(basename($file, '.php'))));

			// Validate the migration file structure
			if ( ! class_exists($class, FALSE))
			{
				$this->_error_string = sprintf($this->lang->line('migration_class_doesnt_exist'), $class);
				return FALSE;
			}

			$previous = $number;

			// Run migrations that are inside the target range
			if (
				($method === 'up'   && $number > $current_version && $number <= $target_version) OR
				($method === 'down' && $number <= $current_version && $number > $target_version)
			)
			{
				$instance = new $class();
				if ( ! is_callable(array($instance, $method)))
				{
					$this->_error_string = sprintf($this->lang->line('migration_missing_'.$method.'_method'), $class);
					return FALSE;
				}

				log_message('debug', 'Migrating '.$method.' from version '.$current_version.' to version '.$number);
				call_user_func(array($instance, $method));
				$current_version = $number;
				$this->_update_version($current_version);
			}
		}

		// This is necessary when moving down, since the the last migration applied
		// will be the down() method for the next migration up from the target
		if ($current_version <> $target_version)
		{
			$current_version = $target_version;
			$this->_update_version($current_version);
		}

		log_message('debug', 'Finished migrating to '.$current_version);

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
		$migrations = $this->find_migrations();

		if (empty($migrations))
		{
			$this->_error_string = $this->lang->line('migration_none_found');
			return FALSE;
		}

		$last_migration = basename(end($migrations));

		// Calculate the last migration step from existing migration
		// filenames and proceed to the standard version migration
		return $this->version($this->_get_migration_number($last_migration));
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
	public function find_migrations()
	{
		$migrations = array();

		// Load all *_*.php files in the migrations path
		foreach (glob($this->_migration_path.'*_*.php') as $file) {
			$name = basename($file, '.php');

			// Filter out non-migration files
			if (preg_match($this->_migration_regex, $name))
			{
				$number = $this->_get_migration_number($name);

				// There cannot be duplicate migration numbers
				if (isset($migrations[$number]))
				{
					$this->_error_string = sprintf($this->lang->line('migration_multiple_version'), $number);
					show_error($this->_error_string);
				}

				$migrations[$number] = $file;
			}
		}

		ksort($migrations);
		return $migrations;
	}
// --------------------------------------------------------------------
	/**
	 * Extracts the migration number from a filename
	 *
	 * @param	string	$migration
	 * @return	string	Numeric portion of a migration filename
	 */
	protected function _get_migration_number($migration)
	{
		return sscanf($migration, '%[0-9]+', $number)
			? $number : '0';
	}

	// --------------------------------------------------------------------

	/**
	 * Extracts the migration class name from a filename
	 *
	 * @param	string	$migration
	 * @return	string	text portion of a migration filename
	 */
	protected function _get_migration_name($migration)
	{
		$parts = explode('_', $migration);
		array_shift($parts);
		return implode('_', $parts);
	}
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
