<?php

class Database
{
    private string $host;

    private string $db_name;

    private string $db_user;

    private string $db_pass;

    public function __construct()
    {
        $this->host = $_ENV['HOST'] ?? 'localhost';
        $this->db_name = $_ENV['DB_NAME'] ?? '';
        $this->db_user = $_ENV['DB_USER'] ?? '';
        $this->db_pass = $_ENV['DB_PASS'] ?? '';
    }

    public function connect()
    {

        try {
            $this->connection = new mysqli($this->host, $this->db_user, $this->db_pass, $this->db_name);

        } catch (Exception $e) {
            exit('Database connection failed: '.$e->getMessage());
        }
    }
}

$db = new Database;
