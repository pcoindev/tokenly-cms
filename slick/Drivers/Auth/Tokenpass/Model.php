<?php
namespace Drivers\Auth;
use Core, UI, Util, App\Profile, App\Account\Settings_Model;

class Tokenpass_Model extends Core\Model implements \Interfaces\AuthModel
{
	public static $activity_updated = false;
	
	function __construct()
	{
		parent::__construct();
		$this->oauth_url = TOKENPASS_URL.'/oauth';
		$this->scopes = array('user', 'tca');
	}
	
	public static function userInfo($userId = false)
	{
		$model = new Tokenpass_Model;
		$sesh_auth = Util\Session::get('accountAuth');
		if(!$userId AND !$sesh_auth){
			return false;
		}
		
		if(!$userId){
			$get = $model->checkSession($sesh_auth);
		}
		else{
			$get = $model->get('users', $userId);
		}
												
		if(!$get){
			return false;
		}
		
		$user = $model->get('users', $get['userId'], array('userId', 'username', 'email', 'slug', 'regDate', 'lastAuth', 'lastActive'));
		$user['auth'] = $get['auth'];
		
		$getSite = currentSite();

		$meta = new \App\Meta_Model;
		$user['meta'] = $meta->userMeta($get['userId']);

		$getRef = $model->get('user_referrals', $get['userId'], array('affiliateId'), 'userId');
		$user['affiliate'] = false;
		if($getRef){
			$getAffiliate = $model->get('users', $getRef['affiliateId'], array('userId', 'username', 'slug'), 'userId');
			if($getAffiliate){
				$user['affiliate'] = $getAffiliate;
			}
		} 
		
		$user['groups'] = $model->fetchAll('SELECT g.name, g.groupId, g.displayName, g.displayView, g.displayRank, g.isSilent as silent
										   FROM group_users u
										   LEFT JOIN groups g ON g.groupId = u.groupId
										   LEFT JOIN group_sites s ON s.groupId = g.groupId
										   WHERE u.userId = :id AND s.siteId = :siteId
										   ORDER  BY g.displayRank DESC, g.displayName ASC, g.name ASC
										   ', array(':id' => $get['userId'], ':siteId' => $getSite['siteId']));
		$user['primary_group'] = false;
		$primary_found = false;
		foreach($user['groups'] as $gk => $gv){
			if(trim($gv['displayName']) == ''){
				$user['groups'][$gk]['displayName'] = $gv['name'];
			}
			if(!$primary_found AND $gv['silent'] == 0){
				$user['primary_group'] = $gv;
				$primary_found = true;
			}
		}
		
		Tokenpass_Model::updateLastActive($get['userId']);
		
		return $user;
	}
	
	public function checkAuth($data)
	{
		
		
	}
	
	public function checkSession($auth)
	{
		$get = $this->fetchSingle('SELECT * FROM user_sessions WHERE auth = :auth ORDER BY sessionId DESC LIMIT 1',
									array(':auth' => $auth), 0, $useCache);
		if($get){
			return $get;
		}
		return false;
	}
	
	public function clearSession($auth)
	{
		$getSesh = $this->container->checkSession($auth);
		if(!$getSesh){
			return false;
		}
		$this->edit('users', $getSesh['userId'], array('lastActive' => null));
		Util\Session::clear('accountAuth');
		return $this->delete('user_sessions', $getSesh['sessionId']);
	}
	
	public function makeSession($userId, $token)
	{
		$check = $this->checkSession($token);
		if($check){
			return false;
		}
		$time = timestamp();
		$insert = $this->insert('user_sessions', array('userId' => $userId, 'auth' => $token, 'IP' => $_SERVER['REMOTE_ADDR'],
													   'authTime' => $time, 'lastActive' => $time));
		if(!$insert){
			return false;
		}
		$this->edit('users', $userId, array('auth' => $token));
		Util\Session::set('accountAuth', $token);
		return true;
	}
	
	protected function setState()
	{
		$state = hash('sha256', microtime().':'.mt_rand(0, 1000).':'.$_SERVER['REMOTE_ADDR']);
		Util\Session::set('auth_state', $state);
		return $state;
	}
	
	protected function getState()
	{
		return Util\Session::get('auth_state');
	}
	
	protected function getAuthUrl()
	{
		$state = $this->container->setState();
		$client_id = TOKENPASS_CLIENT;
		$scope = join(',', $this->scopes);
		$site = currentSite();
		$account_app = get_app('account');
		$auth_module = get_app('account.auth');
		$redirect = $site['url'].'/'.$account_app['url'].'/'.$auth_module['url'].'/callback';
		$query = array('state' => $state, 'client_id' => $client_id, 'scope' => $scope, 'redirect_uri' => $redirect,
						'response_type' => 'code');
		$auth_url = $this->oauth_url.'/authorize?'.http_build_query($query);
		return $auth_url;
	}
	
	protected function getAuthToken($code)
	{
		$url = $this->oauth_url.'/access-token';
		
		$site = currentSite();
		$account_app = get_app('account');
		$auth_module = get_app('account.auth');
		$redirect = $site['url'].'/'.$account_app['url'].'/'.$auth_module['url'].'/callback';		
	
		$params = array(
			'grant_type' => 'authorization_code',
			'code' => $code,
			'client_id' => TOKENPASS_CLIENT,
			'client_secret' => TOKENPASS_SECRET,
			'redirect_uri' => $redirect,
		);
		

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

		$response = json_decode(curl_exec($ch), true);
		curl_close($ch);		
		
		if(!$response OR isset($response['error']) OR !isset($response['access_token'])){
			return false;
		}
		
		return $response['access_token'];
	}
	
	protected function getOAuthUser($token)
	{
		$url = $this->oauth_url.'/user';
		$params = array('client_id' => TOKENPASS_CLIENT, 'access_token' => $token);
		$url .= '?'.http_build_query($params);
		$get = json_decode(@file_get_contents($url), true);
		if(!isset($get['id'])){
			return false;
		}
		return $get;		
	}
	
	protected function findTokenPassUser($uuid)
	{
		$get = $this->fetchSingle('SELECT userId FROM user_meta WHERE metaKey = "tokenly_uuid" AND metaValue = :value',
								   array(':value' => $uuid));
		if(!$get){
			return false;
		}
		$getUser = $this->get('users', $get['userId'], array('userId', 'username', 'email', 'activated'));
		return $getUser;
	}
	
	protected function findMergableUser($oauth_data)
	{
		$get = $this->fetchSingle('SELECT userId, username, email, activated FROM users
									WHERE username = :username AND email = :email',
								array(':username' => $oauth_data['username'], ':email' => $oauth_data['email']));	
		return $get;
	}
	
	protected static function updateLastActive($userId)
	{
		if(!self::$activity_updated){
			$model = new Tokenpass_Model;
			$auth = false;
			$sesh_auth = Util\Session::get('accountAuth');
			if(isset($_SERVER['HTTP_X_AUTHENTICATION_KEY'])){
				$auth = $_SERVER['HTTP_X_AUTHENTICATION_KEY'];
			}
			elseif($sesh_auth){
				$auth = $sesh_auth;
			}
			if(!$auth){
				return false;
			}
			$getSesh = $model->checkSession($auth);
			if($getSesh){
				$time = timestamp();
				$diff = strtotime($time) - strtotime($getSesh['lastActive']);
				if($diff >= 300){
					$update = $model->edit('user_sessions', $getSesh['sessionId'], array('lastActive' => $time));
					if($update){
						$editUser = $model->edit('users', $getSesh['userId'], array('lastActive' => $time));
						self::$activity_updated = true;
						return true;
					}
				}
			}
			return false;
		}
		return true;
	}		
	
	protected function checkSlugExists($slug, $ignore = 0, $count = 0)
	{
		$useslug = $slug;
		if($count > 0){
			$useslug = $slug.'-'.$count;
		}
		$get = $this->get('users', $useslug, array('userId', 'slug'), 'slug');
		if($get AND $get['userId'] != $ignore){
			//slug exists already, search for next level of slug
			$count++;
			return $this->container->checkSlugExists($slug, $ignore, $count);
		}
		
		if($count > 0){
			$slug = $slug.'-'.$count;
		}

		return $slug;
	}	
	
	protected function generateUser($data)
	{
		//check username is taken
		$get = $this->get('users', $data['username'], array(), 'username');
		if($get){
			throw new \Exception('Username already taken by other user in system');
		}
		
		///set up user data
		$time = timestamp();
		$useData = array();
		$useData['username'] = $data['username'];
		$useData['password'] = 'tokenpass';
		$useData['spice'] = 'tokenpass';
		$useData['email'] = $data['email'];
		$useData['regDate'] = $time;
		$useData['lastAuth'] = $time;
		$useData['lastActive'] = $time;
		$useData['slug'] = genURL($data['username']);
		$useData['slug'] = $this->container->checkSlugExists($useData['slug']);
		$useData['activated'] = 1;
		
		$add = $this->insert('users', $useData);
		if(!$add){
			throw new \Exception('Error saving user');
		}
		
		//assign them to any default groups
		$getGroups = $this->getAll('groups', array('isDefault' => 1));
		foreach($getGroups as $group){
			$this->insert('group_users', array('userId' => $add, 'groupId' => $group['groupId']));
		}
		
		//assign meta data
		$meta = new \App\Meta_Model;
		$site = currentSite();

		$meta->updateUserMeta($add, 'IP_ADDRESS', $_SERVER['REMOTE_ADDR']);
		$meta->updateUserMeta($add, 'site_registered', $site['site']);
		$meta->updateUserMeta($add, 'pubProf', 1);
		$meta->updateUserMeta($add, 'emailNotify', 1);		
		$meta->updateUserMeta($add, 'tokenly_uuid', $data['id']);
		
		return $add;
	}
	
}