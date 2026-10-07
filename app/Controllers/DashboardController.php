<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Controller; use App\Core\Auth;
final class DashboardController extends Controller {public function index():void{$stats=[];foreach(['users','programs','participants','projects'] as $table){try{$stats[$table]=(int)($this->app->db->fetch("SELECT COUNT(*) total FROM `$table`")['total']??0);}catch(\Throwable){$stats[$table]=0;}}$this->view('dashboard',['title'=>'Dashboard','user'=>Auth::user(),'stats'=>$stats]);}}
