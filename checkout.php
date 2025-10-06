<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'transactions' => [], 'updated_products' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'checkout') {
    $customer_name = filter_input(INPUT_POST, 'customer_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_SPECIAL_CHARS);
    $date = filter_input(INPUT_POST, 'date', FILTER_SANITIZE_SPECIAL_CHARS);

    if ($customer_name && $payment_method && $date && !empty($_SESSION['cart'])) {
        mysqli_begin_transaction($conn);
        try {
            $transactions = [];
            $updated_products = [];

            foreach ($_SESSION['cart'] as $item) {
                // Insert transaction
                $query = "INSERT INTO transactions (store_id, product_id, customer_name, quantity, amount, date, status, payment_method) VALUES (?, ?, ?, ?, ?, ?, 'Completed', ?)";
                $stmt = mysqli_prepare($conn, $query);
                $amount = $item['quantity'] * $item['price'];
                mysqli_stmt_bind_param($stmt, 'iisidss', $item['store_id'], $item['product_id'], $customer_name, $item['quantity'], $amount, $date, $payment_method);
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception('Error saving transaction: ' . mysqli_error($conn));
                }
                $transaction_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                // Update stock
                $update_stock = "UPDATE products SET stock = stock - ? WHERE id = ?";
                $stock_stmt = mysqli_prepare($conn, $update_stock);
                mysqli_stmt_bind_param($stock_stmt, 'ii', $item['quantity'], $item['product_id']);
                if (!mysqli_stmt_execute($stock_stmt)) {
                    throw new Exception('Error updating stock: ' . mysqli_error($conn));
                }
                mysqli_stmt_close($stock_stmt);

                // Fetch updated stock
                $stock_query = "SELECT stock FROM products WHERE id = ?";
                $stock_stmt = mysqli_prepare($conn, $stock_query);
                mysqli_stmt_bind_param($stock_stmt, 'i', $item['product_id']);
                mysqli_stmt_execute($stock_stmt);
                $stock_result = mysqli_stmt_get_result($stock_stmt);
                $updated_stock = mysqli_fetch_assoc($stock_result)['stock'];
                mysqli_stmt_close($stock_stmt);

                // Fetch store name for receipt
                $store_query = "SELECT store_name FROM stores WHERE id = ?";
                $store_stmt = mysqli_prepare($conn, $store_query);
                mysqli_stmt_bind_param($store_stmt, 'i', $item['store_id']);
                mysqli_stmt_execute($store_stmt);
                $store_result = mysqli_stmt_get_result($store_stmt);
                $store_name = mysqli_fetch_assoc($store_result)['store_name'];
                mysqli_stmt_close($store_stmt);

                $transactions[] = [
                    'id' => $transaction_id,
                    'store_name' => $store_name,
                    'product_name' => $item['name'],
                    'customer_name' => $customer_name,
                    'quantity' => $item['quantity'],
                    'amount' => $amount,
                    'date' => $date,
                    'payment_method' => $payment_method,
                    'status' => 'Completed'
                ];
                $updated_products[] = [
                    'id' => $item['product_id'],
                    'stock' => $updated_stock
                ];
            }

            mysqli_commit($conn);
            $_SESSION['cart'] = []; // Clear cart
            $response['success'] = true;
            $response['message'] = 'Checkout completed successfully!';
            $response['transactions'] = $transactions;
            $response['updated_products'] = $updated_products;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $response['message'] = $e->getMessage();
        }
    } else {
        $response['message'] = 'Please fill in all required fields or ensure cart is not empty.';
    }
} else {
    $response['message'] = 'Invalid request.';
}

echo json_encode($response);
?>