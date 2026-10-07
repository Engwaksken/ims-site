<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Controller; use App\Core\Auth; use App\Models\User;
final class AuthController extends Controller {public function showLogin():void{if(Auth::check())redirect('/dashboard');$this->view('auth/login',['title'=>'Login'],'layouts/guest');} public function login():void{$this->validateCsrf();$login=trim((string)$this->input('username'));$password=(string)$this->input('password');$user=(new User($this->app->db))->findByLogin($login);if(!$user||!(bool)$user['is_active']||!password_verify($password,(string)$user['password_hash'])){$_SESSION['error']='Invalid username or password.';redirect('/login');}session_regenerate_id(true);$_SESSION['user_id']=$user['user_id'];$_SESSION['username']=$user['username'];$_SESSION['full_name']=$user['full_name'];$_SESSION['role']=$user['role'];redirect('/dashboard');} public function logout():void{$this->validateCsrf();Auth::logout();redirect('/login');}}
