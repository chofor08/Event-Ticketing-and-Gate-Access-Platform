<?php
include 'customer_controller.php';

$data = [
    'name' => $_POST['name'] ?? '',
    'email' => $_POST['email'] ?? '',
    'phone' => $_POST['phone'] ?? '',
    'password' => $_POST['password'] ?? '',
];

$message = '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Reg</title>
    <link rel="stylesheet" href="customer.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Customer Registration</h1>
        </div>
        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST">
            
            <div class="form-group">
                    <label for="name">Name:</label>
                    <input type="text" id="name" name="name" value="<?php echo $data['name']; ?>" required><br><br>
                </div>

                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" value ="<?php echo $data['email']; ?>" required><br><br>
                </div>

                <div class="form-group">
                    <label for="phone">Phone:</label>
                    <input type="tel" id="phone" name="phone" value ="<?php echo $data['phone']; ?>" required><br><br>
                </div>

                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required><br><br>
                </div>
                <?php if ($message) { ?>
                <p style="color: red">
                    <?php echo htmlspecialchars($message);
                    ?>
                </p>
                <?php } ?>
                
                <div class="submit-btn">
                    <input type="submit" name="submit" value="submit">
                </div>

        </form>
    </div>
</body>
</html>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller = new CustomerController;
    $response = $controller->create($data);
    $message = $response['message'];
}
?>