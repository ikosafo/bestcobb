<?php
// get_cart.php
require_once __DIR__ . '/config.php';

// Prevent any output before JSON
ob_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Unknown error'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Invalid request method.');
    }

    // Return the current cart from session
    $cart = $_SESSION['cart'] ?? [];
    $cart_total = 0;
    foreach ($cart as $item) {
        $cart_total += floatval($item['subtotal'] ?? 0);
    }

    $response = [
        'success' => true,
        'cart' => array_values($cart),
        'cart_total' => $cart_total
    ];
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('get_cart.php error: ' . $e->getMessage());
}

ob_end_clean();
echo json_encode($response);
exit;
?>