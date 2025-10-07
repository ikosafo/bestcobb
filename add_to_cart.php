<?php
// add_to_cart.php
require_once __DIR__ . '/config.php';

// Prevent any output before JSON
ob_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Unknown error'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
    $store_id = filter_input(INPUT_POST, 'store_id', FILTER_VALIDATE_INT);
    $barcode = filter_input(INPUT_POST, 'barcode', FILTER_SANITIZE_STRING);

    if ((!$product_id && !$barcode) || !$quantity || !$store_id || $quantity <= 0) {
        throw new Exception('Invalid input data.');
    }

    // Initialize cart if not set
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    // Fetch product if barcode is provided
    if ($barcode && !$product_id) {
        $query = "SELECT id, name, price, stock FROM products WHERE barcode = ? AND store_id = ? AND status != 'Out of Stock'";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            throw new Exception('Database query preparation failed: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'si', $barcode, $store_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($product = mysqli_fetch_assoc($result)) {
            $product_id = $product['id'];
        } else {
            throw new Exception('Product not found for barcode.');
        }
        mysqli_stmt_close($stmt);
    }

    // Fetch product details
    $query = "SELECT name, price, stock FROM products WHERE id = ? AND store_id = ? AND status != 'Out of Stock'";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        throw new Exception('Database query preparation failed: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'ii', $product_id, $store_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($product = mysqli_fetch_assoc($result)) {
        if ($quantity > $product['stock']) {
            throw new Exception('Insufficient stock.');
        }

        // Ensure price is numeric
        $price = floatval($product['price']);
        if ($price <= 0) {
            throw new Exception('Invalid product price.');
        }
        $subtotal = $price * $quantity;

        // Check for existing item
        $existing_index = -1;
        foreach ($_SESSION['cart'] as $index => $item) {
            if ($item['id'] == $product_id) {
                $existing_index = $index;
                break;
            }
        }

        if ($existing_index !== -1) {
            $_SESSION['cart'][$existing_index]['quantity'] += $quantity;
            $_SESSION['cart'][$existing_index]['subtotal'] = $_SESSION['cart'][$existing_index]['quantity'] * $price;
        } else {
            $_SESSION['cart'][] = [
                'id' => $product_id,
                'name' => $product['name'],
                'quantity' => $quantity,
                'price' => $price,
                'subtotal' => $subtotal,
                'stock' => $product['stock']
            ];
        }

        // Calculate total
        $cart_total = 0;
        foreach ($_SESSION['cart'] as $item) {
            $cart_total += floatval($item['subtotal']);
        }

        $response = [
            'success' => true,
            'message' => 'Item added to cart!',
            'cart' => array_values($_SESSION['cart']),
            'cart_total' => $cart_total
        ];
    } else {
        throw new Exception('Product not found.');
    }
    mysqli_stmt_close($stmt);
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('add_to_cart.php error: ' . $e->getMessage());
}

ob_end_clean();
echo json_encode($response);
exit;
?>