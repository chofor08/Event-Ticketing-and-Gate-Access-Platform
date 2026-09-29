<?php

require '..models/customer_model.php';

class CustomerController
{
    private Customer $customer;

    public function __construct()
    {
        $this->customer = new Customer;
    }

    /**
     * Create customer
     */
    public function create(array $data)
    {
        if ($this->customer->emailExists($data['email'])) {
            return [
                'success' => false,
                'message' => 'Email already exists',
            ];
        }

        try {
            $id = $this->customer->create($data);

            return [
                'success' => true,
                'message' => 'Student successfuly created',
                'id' => $id,
            ];

        } catch (Exception $e) {
            error_log($e->getMessage());

            return [
                'success' => false,
                'message' => 'Student couldn\'t be created ',
            ];
        }
    }

    public function getOne(array $data)
    {
        try {
            return $this->customer->getOne($data['email']);
        } catch (Exception $e) {
            error_log($e->getMessage());

            return [
                'success' => false,
                'message' => 'Student couldn\'t be created ',
            ];
        }
    }

    public function update(array $data)
    {

        $customer = $this->getOne($data['email']);
        if ($customer === null) {
            return [
                'success' => false,
                'message' => "Customer doesn't exists",
            ];
        }

        $id = $customer['id'];

        try {
            $this->customer->update($data, $id);

            return [
                'success' => true,
                'message' => 'Customer updated successfuly',
            ];
        } catch (Exception $e) {
            error_log($e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to update customer',
            ];
        }

    }

    public function delete(array $data)
    {
        $customer = $this->getOne($data['email']);

        if ($customer === null) {
            return [
                'success' => false,
                'message' => "Customer doesn't exists",
            ];
        }

        $id = $customer['id'];

        try {
            $this->customer->delete($id);

            return [
                'success' => true,
                'message' => 'Customer deleted successfuly',
            ];

        } catch (Exception $e) {
            error_log($e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to delete customer',
            ];

        }
    }
}
