<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'cart' => [], 'cart_total' => 0, 'updated_stock' => null];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = filter_input(INPUT_POST, 'product_id', FILTER_SANITIZE_NUMBER_INT);
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_SANITIZE_NUMBER_INT);
    $store_id = filter_input(INPUT_POST, 'store_id', FILTER_SANITIZE_NUMBER_INT);
    $barcode = filter_input(INPUT_POST, 'barcode', FILTER_SANITIZE_SPECIAL_CHARS);

    // If barcode is provided, find product_id
    if ($barcode) {
        $barcode_query = "SELECT id, store_id, stock, price, name FROM products WHERE barcode = ?";
        $stmt = mysqli_prepare($conn, $barcode_query);
        mysqli_stmt_bind_param($stmt, 's', $barcode);
        mysqli_stmt_execute($stmt);
        $barcode_result = mysqli_stmt_get_result($stmt);
        $product = mysqli_fetch_assoc($barcode_result);
        mysqli_stmt_close($stmt);
        if ($product) {
            $product_id = $product['id'];
            $store_id = $product['store_id'];
        } else {
            $response['message'] = 'Invalid barcode.';
            echo json_encode($response);
            exit;
        }
    }

    // Validate stock
    if ($product_id && $store_id) {
        $stock_query = "SELECT stock, price, name FROM products WHERE id = ? AND store_id = ?";
        $stmt = mysqli_prepare($conn, $stock_query);
        mysqli_stmt_bind_param($stmt, 'ii', $product_id, $store_id);
        mysqli_stmt_execute($stmt);
        $stock_result = mysqli_stmt_get_result($stmt);
        $product = mysqli_fetch_assoc($stock_result);
        mysqli_stmt_close($stmt);

        if ($product && $quantity > 0 && $quantity <= $product['stock']) {
            $cart_item = [
                'product_id' => $product_id,
                'store_id' => $store_id,
                'name' => $product['name'],
                'quantity' => $quantity,
                'price' => $product['price'],
                'subtotal' => $quantity * $product['price'],
                'stock' => $product['stock']
            ];
            $_SESSION['cart'][] = $cart_item;
            $response['success'] = true;
            $response['message'] = 'Product added to cart!';
            $response['cart'] = $_SESSION['cart'];
            $response['cart_total'] = number_format(array_sum(array_column($_SESSION['cart'], 'subtotal')) ?? 0, 2);
            $response['updated_stock'] = ['product_id' => $product_id, 'stock' => $product['stock'] - $quantity];
        } else {
            $response['message'] = 'Invalid quantity or insufficient stock.';
        }
    } else {
        $response['message'] = 'Invalid product or store.';
    }
} else {
    $response['message'] = 'Invalid request method.';
}

echo json_encode($response);
?>