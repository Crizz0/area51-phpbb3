<?php
/**
*
* @package acm
* @version $Id$
* @copyright (c) 2005 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* ACM Abstract Memory Class
* @package acm
*/
class acm_memory
{
	var $vars = array();
	var $is_modified = false;

	var $sql_rowset = array();
	var $sql_row_pointer = array();
	var $cache_dir = '';

	/**
	* Set cache path
	*/
	function acm_memory()
	{
		$this->cache_dir = $phpbb_root_path . 'cache/';
	}

	/**
	* Load global cache
	*/
	function load()
	{
		// grab the global cache
		$this->vars = $this->read('global');

		if ($this->vars !== false)
		{
			return true;
		}

		return false;
	}

	/**
	* Unload cache object
	*/
	function unload()
	{
		$this->save();
		unset($this->vars);
		unset($this->sql_rowset);
		unset($this->sql_row_pointer);

		$this->vars = array();
		$this->sql_rowset = array();
		$this->sql_row_pointer = array();
	}

	/**
	* Save modified objects
	*/
	function save()
	{
		if (!$this->is_modified)
		{
			return;
		}

		$this->write('global', $this->vars, 2592000);

		$this->is_modified = false;
	}

	/**
	* Tidy cache
	*/
	function tidy()
	{
		// cache has auto GC, no need to have any code here :)

		set_config('cache_last_gc', time(), true);
	}

	/**
	* Get saved cache object
	*/
	function get($var_name)
	{
		if ($var_name[0] == '_')
		{
			if (!$this->_exists($var_name))
			{
				return false;
			}

			return $this->read($var_name);
		}
		else
		{
			return ($this->_exists($var_name)) ? $this->vars[$var_name] : false;
		}
	}

	/**
	* Put data into cache
	*/
	function put($var_name, $var, $ttl = 2592000)
	{
		if ($var_name[0] == '_')
		{
			$this->write($var_name, $var, $ttl);
		}
		else
		{
			$this->vars[$var_name] = $var;
			$this->is_modified = true;
		}
	}

	/**
	* Purge cache data
	*/
	function purge()
	{
		// Purge all phpbb cache files
		$dir = @opendir($this->cache_dir);

		if (!$dir)
		{
			return;
		}

		while (($entry = readdir($dir)) !== false)
		{
			if (strpos($entry, 'ctpl_') !== 0 && strpos($entry, 'tpl_') !== 0)
			{
				continue;
			}

			$this->remove_file($this->cache_dir . $entry);
		}
		closedir($dir);

		unset($this->vars);
		unset($this->sql_rowset);
		unset($this->sql_row_pointer);

		$this->vars = array();
		$this->sql_rowset = array();
		$this->sql_row_pointer = array();

		$this->is_modified = false;
	}


	/**
	* Destroy cache data
	*/
	function destroy($var_name, $table = '')
	{
		if ($var_name == 'sql' && !empty($table))
		{
			if (!is_array($table))
			{
				$table = array($table);
			}

			foreach ($table as $table_name)
			{
				// gives us the md5s that we want
				$temp = $this->read('sql_' . $table_name);

				if ($temp === false)
				{
					continue;
				}

				// delete each query ref
				foreach ($temp as $md5_id => $void)
				{
					$this->delete('sql_' . $md5_id);
				}

				// delete the table ref
				$this->delete('sql_' . $table_name);
			}

			return;
		}

		if (!$this->_exists($var_name))
		{
			return;
		}

		if ($var_name[0] == '_')
		{
			$this->delete($var_name);
		}
		else if (isset($this->vars[$var_name]))
		{
			$this->is_modified = true;
			unset($this->vars[$var_name]);

			// We save here to let the following cache hits succeed
			$this->save();
		}
	}

	/**
	* Check if a given cache entry exist
	*/
	function _exists($var_name)
	{
		if ($var_name[0] == '_')
		{
			return true;
		}
		else
		{
			if (!sizeof($this->vars))
			{
				$this->load();
			}

			return isset($this->vars[$var_name]);
		}
	}

	/**
	* Load cached sql query
	*/
	function sql_load($query)
	{
		// Remove extra spaces and tabs
		$query = preg_replace('/[\n\r\s\t]+/', ' ', $query);
		$query_id = sizeof($this->sql_rowset);

		if (($result = $this->read('sql_' . md5($query))) === false)
		{
			return false;
		}

		$this->sql_rowset[$query_id] = $result;
		$this->sql_row_pointer[$query_id] = 0;

		return $query_id;
	}

	/**
	* Save sql query
	*/
	function sql_save($query, &$query_result, $ttl)
	{
		global $db;

		// Remove extra spaces and tabs
		$query = preg_replace('/[\n\r\s\t]+/', ' ', $query);
		$hash = md5($query);

		// determine which tables this query belongs to
		// Some queries use backticks, namely the get_database_size() query
		// don't check for conformity, the SQL would error and not reach here.
		if (!preg_match('/FROM \\(?(`?\\w+`?(?: \\w+)?(?:, ?`?\\w+`?(?: \\w+)?)*)\\)?/', $query, $regs))
		{
			// Bail out if the match fails.
			return;
		}
		$tables = array_map('trim', explode(',', $regs[1]));

		foreach ($tables as $table_name)
		{
			// Remove backticks
			$table_name = ($table_name[0] == '`') ? substr($table_name, 1, -1) : $table_name;

			if (($pos = strpos($table_name, ' ')) !== false)
			{
				$table_name = substr($table_name, 0, $pos);
			}

			$temp = $this->read('sql_' . $table_name);

			if ($temp === false)
			{
				$temp = array();
			}

			$temp[$hash] = true;

			// This must never expire
			$this->write('sql_' . $table_name, $temp, 0);
		}

		// store them in the right place
		$query_id = sizeof($this->sql_rowset);
		$this->sql_rowset[$query_id] = array();
		$this->sql_row_pointer[$query_id] = 0;

		while ($row = $db->sql_fetchrow($query_result))
		{
			$this->sql_rowset[$query_id][] = $row;
		}
		$db->sql_freeresult($query_result);

		$this->write('sql_' . $hash, $this->sql_rowset[$query_id], $ttl);

		$query_result = $query_id;
	}

	/**
	* Ceck if a given sql query exist in cache
	*/
	function sql_exists($query_id)
	{
		return isset($this->sql_rowset[$query_id]);
	}

	/**
	* Fetch row from cache (database)
	*/
	function sql_fetchrow($query_id)
	{
		if ($this->sql_row_pointer[$query_id] < sizeof($this->sql_rowset[$query_id]))
		{
			return $this->sql_rowset[$query_id][$this->sql_row_pointer[$query_id]++];
		}

		return false;
	}

	/**
	* Fetch a field from the current row of a cached database result (database)
	*/
	function sql_fetchfield($query_id, $field)
	{
		if ($this->sql_row_pointer[$query_id] < sizeof($this->sql_rowset[$query_id]))
		{
			return (isset($this->sql_rowset[$query_id][$this->sql_row_pointer[$query_id]][$field])) ? $this->sql_rowset[$query_id][$this->sql_row_pointer[$query_id]][$field] : false;
		}

		return false;
	}

	/**
	* Seek a specific row in an a cached database result (database)
	*/
	function sql_rowseek($rownum, $query_id)
	{
		if ($rownum >= sizeof($this->sql_rowset[$query_id]))
		{
			return false;
		}

		$this->sql_row_pointer[$query_id] = $rownum;
		return true;
	}

	/**
	* Free memory used for a cached database result (database)
	*/
	function sql_freeresult($query_id)
	{
		if (!isset($this->sql_rowset[$query_id]))
		{
			return false;
		}

		unset($this->sql_rowset[$query_id]);
		unset($this->sql_row_pointer[$query_id]);

		return true;
	}

	/**
	* Removes/unlinks file
	*/
	function remove_file($filename, $check = false)
	{
		if ($check && !@is_writable($this->cache_dir))
		{
			// E_USER_ERROR - not using language entry - intended.
			trigger_error('Unable to remove files within ' . $this->cache_dir . '. Please check directory permissions.', E_USER_ERROR);
		}

		return @unlink($filename);
	}
}

?>