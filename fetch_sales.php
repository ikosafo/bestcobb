<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'sales' => [], 'total_pages' => 0, 'current_page' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) ?: 1;
    $rows_per_page = filter_input(INPUT_GET, 'rows_per_page', FILTER_SANITIZE_NUMBER_INT) ?: 10;
    $offset = ($page - 1) * $rows_per_page;

    // Count total transactions for pagination
    $count_query = "SELECT COUNT(*) as total FROM transactions";
    $count_result = mysqli_query($conn, $count_query);
    $total_transactions = $count_result ? mysqli_fetch_assoc($count_result)['total'] : 0;
    $total_pages = ceil($total_transactions / $rows_per_page);

    // Fetch sales for the current page
    $sales_query = "SELECT t.id, t.store_id, t.product_id, t.customer_name, t.quantity, t.amount, t.date, t.status, t.payment_method, s.store_name, p.name as product_name 
                    FROM transactions t 
                    LEFT JOIN stores s ON t.store_id = s.id 
                    LEFT JOIN products p ON t.product_id = p.id 
                    ORDER BY t.date DESC 
                    LIMIT ? OFFSET ?";
    $stmt = mysqli_prepare($conn, $sales_query);
    mysqli_stmt_bind_param($stmt, 'ii', $rows_per_page, $offset);
    mysqli_stmt_execute($stmt);
    $sales_result = mysqli_stmt_get_result($stmt);
    $sales = $sales_result ? mysqli_fetch_all($sales_result, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);

    $response['success'] = true;
    $response['sales'] = $sales;
    $response['total_pages'] = $total_pages;
    $response['current_page'] = $page;
} else {
    $response['message'] = 'Invalid request method.';
}

echo json_encode($response);
?>