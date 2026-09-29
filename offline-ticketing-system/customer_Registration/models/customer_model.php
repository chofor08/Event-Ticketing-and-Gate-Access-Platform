<?php

require 'customer_database.php';

class Customer extends Database
{
    private $table = 'customers';

    public function __construct()
    {
        parent::__construct();
    }

    // Create
    public function create(array $data): int
    {
        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $table = 'customers';

        $query = "INSERT INTO $this->table(name,email,phone,password) VALUES (?, ?, ?, ?)";
        $stmt = $this->connection->prepare($query);
        $stmt->bind_param('ssss', $data['name'], $data['email'], $data['phone'], $hash);
        if (! $stmt->execute()) {
            throw new Exception('Failed to create customer');
        }

        return $this->connection->insert_id;
    }

    // Review
    public function getOne(string $email): ?array
    {
        $sql = "SELECT * FROM $this->table WHERE email = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->fetch_assoc() ?: null;

        return $result;
    }

    // UPDATE
    public function update(array $data, int $id): void
    {
        $query = "UPDATE $this->table SET name =?, phone = ? WHERE id = ? ";
        $stmt = $this->connection->prepare($query);
        $stmt->bind_param('sssi', $data['name'], $data['email'], $data['phone'], $id);
        $stmt->execute();

    }

    // DELETE
    public function delete(int $id): void
    {
        $query = "DELETE FROM $this->table WHERE id = ?";
        $stmt = $this->connection->prepare($query);
        $stmt->bind_param('i', $id);
        $stmt->execute();

    }

    public function emailExists(string $email, ?int $excludedId = null): bool
    {
        $query = "SELECT EXISTS ( 1 FROM $this->table WHERE email = ? AND id != ?)";
        $stmt = $this->connection->prepare($query);
        $stmt->bind_param('si', $email, $excludedId);

        return $stmt->execute();
    }
}
