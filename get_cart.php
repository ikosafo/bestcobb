<?php
// get_cart.php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Unknown error'];

try {
    if (!isset($_SESSION['cart'])) {
        throw new Exception('Cart not found.');
    }

    $response = [
        'success' => true,
        'cart' => $_SESSION['cart'] ?? []
    ];
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('get_cart.php error: ' . $e->getMessage());
}

echo json_encode($response);
exit;
?>