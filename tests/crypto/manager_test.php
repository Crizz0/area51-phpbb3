<?php
/**
*
* @package testing
* @copyright (c) 2013 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

require_once dirname(__FILE__) . '/../mock/container_builder.php';
require_once dirname(__FILE__) . '/../../phpBB/includes/crypto/driver/bcrypt.php';
require_once dirname(__FILE__) . '/../../phpBB/includes/crypto/driver/bcrypt_2y.php';
require_once dirname(__FILE__) . '/../../phpBB/includes/crypto/driver/salted_md5.php';

class phpbb_crypto_manager_test extends PHPUnit_Framework_TestCase
{
	public function setUp()
	{
		global $phpbb_root_path, $phpEx;

		// Mock phpbb_container
		$this->phpbb_container = new phpbb_mock_container_builder;

		// Prepare dependencies for manager and driver
		$config = new phpbb_config(array());

		$crypto_drivers = array(
			'crypto.driver.bcrypt'		=> new phpbb_crypto_driver_bcrypt($config),
			'crypto.driver.bcrypt_2y'	=> new phpbb_crypto_driver_bcrypt_2y($config),
			'crypto.driver.salted_md5'	=> new phpbb_crypto_driver_salted_md5($config),
		);

		foreach ($crypto_drivers as $key => $driver)
		{
			$this->phpbb_container->set($key, $driver);
		}
/*
		$config['allow_avatar_' . get_class($this->avatar_foobar)] = true;
		$config['allow_avatar_' . get_class($this->avatar_barfoo)] = false;
*/
		// Set up avatar manager
		$this->manager = new phpbb_crypto_manager($config, $this->phpbb_container, $crypto_drivers);
	}

	public function hash_password_data()
	{
		return array(
			array('', '2y', 60),
			array('crypto.driver.bcrypt_2y', '2y', 60),
			array('crypto.driver.bcrypt', '2a', 60),
			array('crypto.driver.salted_md5', 'H', 34),
		);
	}

	/**
	* @dataProvider hash_password_data
	*/
	public function test_hash_password($type, $prefix, $length)
	{
		$hash = $this->manager->hash_password('foobar', $type);
		preg_match('#^\$([a-zA-Z0-9\\\]*?)\$#', $hash, $match);
		$this->assertEquals($prefix, $match[1]);
		$this->assertEquals($length, strlen($hash));
	}
}
