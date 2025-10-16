<?php
// checkout.php
require_once __DIR__ . '/config.php';

// Prevent any output before JSON
ob_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Unknown error'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'checkout') {
        throw new Exception('Invalid request.');
    }

    if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
        throw new Exception('Cart is empty.');
    }

   $customer_name = trim(filter_input(INPUT_POST, 'customer_name') ?? '');
    $customer_name = $customer_name !== '' ? htmlspecialchars($customer_name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8') : 'Guest';

    $payment_method = trim(filter_input(INPUT_POST, 'payment_method') ?? '');
    $payment_method = $payment_method !== '' ? htmlspecialchars($payment_method, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8') : '';

    // For $date, since you're validating the format separately, you can do similar but skip if it's not needed for HTML escaping
    $date = trim(filter_input(INPUT_POST, 'date') ?? '');
    $date = $date !== '' ? htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8') : '';
    $amount_paid = filter_input(INPUT_POST, 'amount_paid', FILTER_VALIDATE_FLOAT);
    $change_given = filter_input(INPUT_POST, 'change_given', FILTER_VALIDATE_FLOAT);

    $customer_name = trim($customer_name) ?: 'Guest';

    if (!$payment_method || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid input data.');
    }

    if ($amount_paid === false || $amount_paid < 0 || $change_given === false || $change_given < 0) {
        throw new Exception('Invalid payment data.');
    }

    $store_id = $_SESSION['store_id'] ?? null;
    if (!$store_id) {
        throw new Exception('No store assigned.');
    }

    // Calculate total from cart
    $total = 0;
    foreach ($_SESSION['cart'] as $item) {
        $total += floatval($item['subtotal']);
    }

    if ($amount_paid < $total) {
        throw new Exception('Amount paid is less than total.');
    }

    if (abs($change_given - ($amount_paid - $total)) > 0.01) { // Allow small float precision error
        throw new Exception('Change given does not match calculation.');
    }

    // Fetch store name
    $store_query = "SELECT store_name FROM stores WHERE id = ?";
    $stmt = mysqli_prepare($conn, $store_query);
    if (!$stmt) {
        throw new Exception('Database query preparation failed: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'i', $store_id);
    mysqli_stmt_execute($stmt);
    $store_result = mysqli_stmt_get_result($stmt);
    $store_name = mysqli_fetch_assoc($store_result)['store_name'] ?? 'Unknown Store';
    mysqli_stmt_close($stmt);

    $transactions = [];
    $updated_products = [];
    mysqli_begin_transaction($conn);

    foreach ($_SESSION['cart'] as $item) {
        if (!isset($item['id'], $item['name'], $item['quantity'], $item['subtotal']) || !is_numeric($item['subtotal']) || !is_numeric($item['quantity'])) {
            throw new Exception('Invalid cart item data.');
        }

        // Insert transaction without product_name
        $stmt = mysqli_prepare($conn, "INSERT INTO transactions (store_id, product_id, customer_name, quantity, amount, date, payment_method, status, amount_paid, change_given) VALUES (?, ?, ?, ?, ?, ?, ?, 'Completed', ?, ?)");
        if (!$stmt) {
            throw new Exception('Database query preparation failed: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'iisidssdd', $store_id, $item['id'], $customer_name, $item['quantity'], $item['subtotal'], $date, $payment_method, $amount_paid, $change_given);
        mysqli_stmt_execute($stmt);
        $transaction_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        // Update product stock
        $stmt = mysqli_prepare($conn, "UPDATE products SET stock = stock - ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Database query preparation failed: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'ii', $item['quantity'], $item['id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // Fetch updated stock
        $stmt = mysqli_prepare($conn, "SELECT stock FROM products WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Database query preparation failed: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'i', $item['id']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $updated_stock = mysqli_fetch_assoc($result)['stock'] ?? 0;
        mysqli_stmt_close($stmt);

        // Include product_name in response for receipt and sales table
        $transactions[] = [
            'id' => $transaction_id,
            'store_id' => $store_id,
            'store_name' => $store_name,
            'product_id' => $item['id'],
            'product_name' => $item['name'],
            'customer_name' => $customer_name,
            'quantity' => $item['quantity'],
            'amount' => $item['subtotal'],
            'date' => $date,
            'payment_method' => $payment_method,
            'status' => 'Completed',
            'amount_paid' => $amount_paid,
            'change_given' => $change_given
        ];
        $updated_products[] = ['id' => $item['id'], 'stock' => $updated_stock];
    }

    mysqli_commit($conn);
    $_SESSION['cart'] = []; // Clear cart

    $response = [
        'success' => true,
        'message' => 'Checkout completed successfully!',
        'transactions' => $transactions,
        'updated_products' => $updated_products
    ];
} catch (Exception $e) {
    mysqli_rollback($conn);
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('checkout.php error: ' . $e->getMessage());
}

ob_end_clean();
echo json_encode($response);
exit;
?>