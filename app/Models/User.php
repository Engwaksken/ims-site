<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Database;
final class User {public function __construct(private Database $db){} public function findByLogin(string $login):?array{return $this->db->fetch('SELECT user_id,username,email,password_hash,full_name,role,is_active FROM users WHERE username=:login OR email=:login LIMIT 1',['login'=>$login]);} public function find(int $id):?array{return $this->db->fetch('SELECT * FROM users WHERE user_id=:id',['id'=>$id]);}}
