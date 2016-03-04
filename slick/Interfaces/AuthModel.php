<?php
namespace Interfaces;

interface AuthModel
{
	
	/*
	 * improve this later
	 * */
	
	public static function userInfo($userId);
	
	public function checkAuth($data);
	
	public function checkSession($auth);
	
	public function clearSession($auth);
	
	public function makeSession($userId, $token);
}