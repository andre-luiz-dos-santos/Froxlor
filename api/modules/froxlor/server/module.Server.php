<?php

/**
 * Froxlor API Server-Module
 *
 * PHP version 5
 *
 * This file is part of the Froxlor project.
 * Copyright (c) 2013- the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Froxlor team <team@froxlor.org> (2013-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @category   Modules
 * @package    API
 * @since      0.99.0
 */

/**
 * Class Server
 *
 * @copyright  (c) the authors
 * @author     Froxlor team <team@froxlor.org> (2013-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @category   Modules
 * @package    API
 * @since      0.99.0
 */
class Server extends FroxlorModule {

	/**
	 * returns all servers, optionally only servers
	 * owned by given $owner
	 *
	 * @param int $owner optional id of the owner-user
	 *
	 * @throws ServerException if given owner does not exist
	 * @return array
	 */
	public static function listServer() {

		// get owner, non-negative, default 0 = no owner
		$owner = self::getIntParam('owner', true, 0, false);

		if ($owner > 0) {
			$servers = Database::find('server', ' user_id = ? ORDER BY name ASC', array($owner));
		} else {
			$servers = Database::findAll('server', ' ORDER BY name ASC');
		}

		// create array from beans
		$server_array = Database::exportAll($servers, false);

		// return all the servers as array (api)
		return ApiResponse::createResponse(
				200,
				null,
				$server_array
		);
	}

	/**
	 * returns a server by given id
	 *
	 * @param int $id id of the server
	 *
	 * @throws ServerException
	 * @return array
	 */
	public static function statusServer() {
		$sid = self::getIntParam('id');
		$server = Database::load('server', $sid);
		if ($server->id) {
			return ApiResponse::createResponse(200, null, Database::exportAll($server));
		}
		throw new ServerException(404, 'Server with id #'.$sid.' could not be found');
	}

	/**
	 * adds a new server to the database, additionally
	 * @see Server::addServerIP() is used to assign
	 * the default ip to the server (editable if > one IP)
	 * via @see Server::modifyServerIP()
	 * Hooks that are being called:
	 * - addServer_afterStore
	 * - addServer_beforeReturn
	 *
	 * @param string $name name of server
	 * @param string $desc description
	 * @param string $ipaddress initial default IP of that server
	 * @param int $owner optional user-id of owner or the user who adds the server is added as owner
	 *
	 * @throws ServerException
	 * @return array exported newly added server bean
	 */
	public static function addServer() {

		$name = self::getParam('name');
		$desc = self::getParam('desc', true, "");
		$ipaddress = self::getParam('ipaddress');
		$owner = self::getIntParam('owner', true, null);

		// check permissions
		$user = self::getParam('_userinfo');
		if (!self::isAllowed($user, 'Server.addServer')) {
			throw new ApiException(403, 'You are not allowed to access this function');
		}

		// set up new server
		$server = Database::dispense('server');
		$server->name = $name;
		$server->desc = $desc;

		// check for owner
		$sowner = $user;
		if ($owner !== null) {
			$o = Database::load('user', $owner);
			if ($o->id) {
				$newowner = $o;
			} else {
				throw new ServerException(404, 'Specified owner with id #'.$owner.' does not exist');
			}
		}

		$server->serverowner = $owner;
		$server_id = Database::store($server);
		// load server bean
		$serverbean = Database::load('server', $server_id);
		$server_array = Database::exportAll($serverbean);

		Hooks::callHooks('addServer_afterStore', $server_array);

		// now add IP address
		$ip_result = Froxlor::getApi()->apiCall(
				'Server.addServerIP',
				array('ipaddress' => $ipaddress, 'isdefault' => true, 'serverid' => $server_id)
		);
		if ($ip_result->getResponseCode() == 200) {
			Hooks::callHooks('addServer_beforeReturn', $server_array);
			// return result with updated server-bean
			return ApiResponse::createResponse(200, null, $server_array);
		}
		// rollback, there was an error, so the server needs to be removed from the database
		Database::trash($serverbean);
		// return the error-message from addServerIP
		return $ip_result->getResponse();

	}

	/**
	 * adds and assigns a new ipaddress to a server
	 * Hooks that are being called:
	 * - addServerIP_afterStore
	 * - addServerIP_beforeReturn
	 *
	 * @param string $ipadress the IP adress (v4 or v6)
	 * @param int $serverid the id of the server to add the IP to
	 * @param bool $isdefault optional, whether this ip should be the default server ip, default: false
	 *
	 * @throws ServerException
	 * @return array exported added IP bean
	 */
	public static function addServerIP() {

		$ipaddress = self::getParam('ipaddress');
		$serverid = self::getIntParam('serverid');
		$isdefault = self::getParam('isdefault', true, false);

		// check permissions
		$user = self::getParam('_userinfo');
		if (!self::isAllowed($user, 'Server.addServerIP')) {
			throw new ApiException(403, 'You are not allowed to access this function');
		}

		// look for duplicate
		$ip_check = Database::findOne('ipaddress', ' ip = ?', array($ipaddress));
		if ($ip_check !== null) {
			$server = Database::load('server', $ip_check->server_id);
			throw new ServerException(406, 'IP address "'.$ipaddress.'" already exists for server "'.$server->name.'"');
		}
		$ip = Database::dispense('ipaddress');
		$ip->ip = $ipaddress;
		$ip->isdefault = $isdefault;
		$ip->server_id = $serverid;
		$ip_id = Database::store($ip);
		$ip_array = Database::load('ipaddress', $ip_id)->export();

		Hooks::callHooks('addServerIP_afterStore', $ip_array);

		// now update the "old" default IP to be non-default
		self::_unsetFormerDefaultIP($serverid);

		Hooks::callHooks('addServerIP_beforeReturn', $ip_array);

		// return newly added ip
		return ApiResponse::createResponse(200, null, $ip_array);
	}

	/**
	 * modify name, description and owner of the server
	 *
	 * @param int $id id of the server
	 * @param string $name optional name of server
	 * @param string $desc optional description
	 * @param array $owner optional, user-id of the server-owner
	 *
	 * @throws ServerException
	 * @return array exported updated Server bean
	 */
	public static function modifyServer() {

		$serverid = self::getIntParam('id');
		$name = self::getParam('name', true, null);
		$desc = self::getParam('desc', true, null);
		$owner = self::getIntParam('owner', true, null);

		// check permissions
		$user = self::getParam('_userinfo');
		if (!self::isAllowed($user, 'Server.modifyServer')) {
			throw new ApiException(403, 'You are not allowed to access this function');
		}

		$server = Database::load('server', $serverid);

		if ($server->id) {
			if ($name !== null && trim($name) != '') {
				$server->name = trim($name);
			}
			if ($desc !== null) {
				$server->desc = trim($desc);
			}
			if ($owner != null) {
				// owner = 0 -> remove old owner (which implicitly sets the current user as owner)
				if ($owner == 0) {
					$user = self::getParam('_userinfo');
					$newowner =$user;
				} else {
					$o = Database::load('user', $owner);
					if ($o->id) {
						$newowner = $o;
					} else {
						throw new ServerException(404, 'Specified owner with id #'.$owner.' does not exist');
					}
				}
				$server->serverowner = $newowner;
			}
			if ($server->isTainted()) {
				Database::store($server);
				$server = Database::load('server', $serverid);
			}
			$server_array = Database::exportAll($server);
			return ApiResponse::createResponse(200, null, $server_array);
		}
		throw new ServerException(404, "Server with id #".$serverid." could not be found");
	}

	/**
	 * update a servers IP address, if $isdefault is set
	 * the former default IP will be set isdefault=false
	 * Hooks that are being called:
	 * - modifyServerIP_beforeReturn
	 *
	 * @param int $ipid id of the ip-address
	 * @param string $ipaddress optional new IP address value
	 * @param bool $isdefault optional, whether this ip should be the default server ip, default: false
	 *
	 * @throws ServerException
	 * @return array exported updated IP bean
	 */
	public static function modifyServerIP() {

		$iid = self::getIntParam('ipid');
		$ipaddress = self::getParam('ipaddress', true, '');
		$isdefault = self::getParam('isdefault', true, null);

		// check permissions
		$user = self::getParam('_userinfo');
		if (!self::isAllowed($user, 'Server.modifyServerIP')) {
			throw new ApiException(403, 'You are not allowed to access this function');
		}

		// get the bean
		$ip = Database::load('ipaddress', $iid);

		// is it valid?
		if ($ip === null) {
			throw new ServerException(404, 'IP address "'.$ipaddress.'" with id #'.$iid.' could not be found');
		}

		// set new values (if changed)
		if ($ipaddress != '') {
			$ip->ip = $ipaddress;
		}
		if ($isdefault !== null) {
			// cannot change "isdefault" to false if it's the default IP
			// -> first set a new default (which will unset the isdefault flag for this one)
			if ($ip->isdefault == true
					&& $isdefault == false
			) {
				throw new ServerException(403, 'Cannot make the IP "'.$ip->ip.'" non-default. Please set a new default IP for the server (#'.$ip->server_id.') first');
			}
			$ip->isdefault = $isdefault;

			// this shall be the new default IP, so make
			// the former default-IP non-default
			if ($isdefault == true) {
				self::_unsetFormerDefaultIP($ip->server_id);
			}
		}

		if ($ip->isTainted()) {
			Database::store($ip);
		}
		$ip_array = Database::load('ipaddress', $iid)->export();

		Hooks::callHooks('modifyServerIP_beforeReturn', $ip_array);

		// return updated bean
		return ApiResponse::createResponse(200, null, $ip_array);
	}

	/**
	 * sets a new server default-IP and updates the former
	 * default IP to be non-default
	 *
	 * @param int $ipid id of the ip-address
	 * @param int $serverid the id of the server to add the IP to
	 *
	 * @throws ServerException
	 * @return array exported updated IP bean
	 */
	public static function setServerDefaultIP() {

		// get params
		$ipid = self::getIntParam('ipid');
		$serverid = self::getIntParam('serverid');

		// check permissions
		$user = self::getParam('_userinfo');
		if (!self::isAllowed($user, 'Server.setServerDefaultIP')) {
			throw new ApiException(403, 'You are not allowed to access this function');
		}

		// get beans
		$server = Database::load('server', $serverid);
		$ip = Database::load('ipaddress', $ipid);

		// valid server?
		if ($server->id) {
			// valid ip?
			if ($ip->id) {
				// check if it belongs to the server
				if (array_key_exists($ip->id, $server->ownIpaddress)) {
					// now, just change the IP via API
					$response = Froxlor::getApi()->apiCall('Server.modifyServerIP', array('id' => $ipid, 'isdefault' => true));
					return $response->getResponse();
				}
				throw new ServerException(406, "IP address '".$ip->ipaddress."' does not belong to server '".$server->name."'");
			}
			throw new ServerException(404, "IP with id #".$ipid." could not be found");
		}
		throw new ServerException(404, "Server with id #".$serverid." could not be found");
	}

	/**
	 * removes a server from the database
	 * Hooks that are being called:
	 * - deleteServer_beforeDelete
	 *
	 * @param int $serverid id of the server
	 *
	 * @throws ServerException
	 * @return bool success = true
	 *
	 * @TODO later check for entities using that server and don't delete but warn about that
	 */
	public static function deleteServer() {
		$serverid = self::getIntParam('serverid');
		// check permissions
		$user = self::getParam('_userinfo');
		if (!self::isAllowed($user, 'Server.deleteServer')) {
			throw new ApiException(403, 'You are not allowed to access this function');
		}
		$server = Database::load('server', $serverid);
		if ($server->id) {

			// call the beforeDelete hook so other modules
			// can check if they still need this thing (throw an exception
			// to avoid this server being removed in your hook-function)
			Hooks::callHooks('deleteServer_beforeDelete', $server->export());

			Database::trash($server);
			return ApiResponse::createResponse(200, null, array('success' => true));
		}
		throw new ServerException(404, "Server with id #".$serverid." could not be found");
	}

	/**
	 * removes an IP address from a give server; if given IP is the
	 * server's default IP address, it will not be removed
	 *
	 * @param int $serverid id of the server
	 * @param int $ipid id of the ip
	 *
	 * @throws ServerException
	 * @return bool success = true
	 */
	public static function deleteServerIP() {
		$serverid = self::getIntParam('serverid');
		$ipid = self::getIntParam('ipid');

		// check permissions
		$user = self::getParam('_userinfo');
		if (!self::isAllowed($user, 'Server.deleteServerIP')) {
			throw new ApiException(403, 'You are not allowed to access this function');
		}

		$server = Database::load('server', $serverid);
		$ip = Database::load('ipaddress', $ipid);

		// valid server?
		if ($server->id) {
			// valid ip?
			if ($ip->id) {
				// check if it belongs to the server
				if (array_key_exists($ip->id, $server->ownIpaddress)) {
					// is it the only one?
					if (count($server->ownIpaddress) > 1) {
						// check if that is the default one
						// if that is the case, we cannot remove it
						// until another server-ip is being set as default
						if ($ip->isdefault == true) {
							throw new ServerException(403, "Cannot remove IP address '".$ip->ip."' from server '".$server->name."' as it is marked as 'default'");
						}
						// delete from server
						unset($server->ownIpaddress[$ip->id]);
						Database::store($server);
						// delete ipaddress itself as it is not used anymore
						Database::trash($ip);
						return ApiResponse::createResponse(200, null, array('success' => true));
					}
					throw new ServerException(403, "Cannot remove the IP '".$ip->ip."' from server '".$server->name."' as it is the only one");
				}
				throw new ServerException(406, "IP address '".$ip->ipaddress."' does not belong to server '".$server->name."'");
			}
			throw new ServerException(404, "IP with id #".$ipid." could not be found");
		}
		throw new ServerException(404, "Server with id #".$serverid." could not be found");
	}

	/**
	 * sets a given server's default IP address to non-default, mostly
	 * after another IP has been added or modified with isdefault = true
	 *
	 * @param int $serverid id of the server
	 *
	 * @return null
	 * @internal
	 */
	private static function _unsetFormerDefaultIP($serverid) {
		// find the server's default IP
		$formerdefault = Database::findOne('ipaddress',
				' isdefault = :isdef AND server_id = :sid',
				array(':isdef' => true, ':sid' => $serverid)
		);
		// this is faster than calling apiCall('Server.modifyServerIP')
		if ($formerdefault !== null) {
			$formerdefault->isdefault = false;
			Database::store($formerdefault);
		}
	}

	/**
	 * (non-PHPdoc)
	 * @see FroxlorModule::Core_moduleSetup()
	 */
	public function Core_moduleSetup() {
		// FIXME just testing-code here!
		// default IP
		$ip = Database::dispense('ipaddress');
		$ip->ip = '127.0.0.1';
		$ip->isdefault = true;
		$ipid = Database::store($ip);

		// default server
		$srv = Database::dispense('server');
		$srv->name = 'Testserver';
		$srv->desc = 'This is an automatically added default server';
		$srv->ownIpaddress = array(Database::load('ipaddress', $ipid));
		$srv->sharedUser = array(Database::load('user', 1));
		$srvid = Database::store($srv);

		// TODO permission / resources
		$permids = array();
		$perm = Database::dispense('permissions');
		$perm->module = 'Server';
		$perm->name = 'addServer';
		$permids[] = Database::store($perm);

		$perm = Database::dispense('permissions');
		$perm->module = 'Server';
		$perm->name = 'modifyServer';
		$permids[] = Database::store($perm);

		$perm = Database::dispense('permissions');
		$perm->module = 'Server';
		$perm->name = 'deleteServer';
		$permids[] = Database::store($perm);

		$perm = Database::dispense('permissions');
		$perm->module = 'Server';
		$perm->name = 'addServerIP';
		$permids[] = Database::store($perm);

		$perm = Database::dispense('permissions');
		$perm->module = 'Server';
		$perm->name = 'modifyServerIP';
		$permids[] = Database::store($perm);

		$perm = Database::dispense('permissions');
		$perm->module = 'Server';
		$perm->name = 'deleteServerIP';
		$permids[] = Database::store($perm);

		$perm = Database::dispense('permissions');
		$perm->module = 'Server';
		$perm->name = 'setServerDefaultIP';
		$permids[] = Database::store($perm);

		// load superadmin group and add permissions
		$sagroup = Database::findOne('groups', ' groupname = :grp', array(':grp' => '@superadmin'));
		if ($sagroup !== null) {
			$newperms = Database::batch('permissions', $permids);
			$existing = $sagroup->sharedPermissions;
			$sagroup->sharedPermissions = array_merge($existing, $newperms);
			Database::store($sagroup);
		}
	}
}
