<?php
// fetch_sales.php
require_once __DIR__ . '/config.php';

// Suppress errors/warnings from outputting HTML; log them instead
error_reporting(0);
ini_set('display_errors', 0);

// Prevent any output before JSON
ob_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Unknown error'];

try {
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
    $rows_per_page = filter_input(INPUT_GET, 'rows_per_page', FILTER_VALIDATE_INT) ?: 10;
    $status = trim(filter_input(INPUT_GET, 'status') ?? '');
    $status = $status !== '' ? htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8') : '';
    $store_id = filter_input(INPUT_GET, 'store_id', FILTER_VALIDATE_INT);
    // New: Default to today's transactions only
    $filter_date = filter_input(INPUT_GET, 'filter_date') ?? 'today'; // 'today' or 'all'

    if ($page < 1 || $rows_per_page < 1) {
        throw new Exception('Invalid pagination parameters.');
    }

    $offset = ($page - 1) * $rows_per_page;

    // Added COALESCE for amount_paid and change_given
    $query = "SELECT 
                  t.id, 
                  t.store_id, 
                  s.store_name, 
                  t.product_id, 
                  p.name AS product_name, 
                  t.customer_name, 
                  t.quantity, 
                  t.amount, 
                  COALESCE(t.amount_paid, 0) AS amount_paid,
                  COALESCE(t.change_given, 0) AS change_given,
                  t.date,
                  DATE_FORMAT(t.date, '%Y-%m-%d %H:%i:%s') AS formatted_date,
                  t.payment_method, 
                  t.status 
              FROM transactions t 
              JOIN stores s ON t.store_id = s.id 
              JOIN products p ON t.product_id = p.id 
              WHERE 1=1";
    $params = [];
    $types = '';

    if ($status) {
        $query .= " AND t.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    if ($store_id) {
        $query .= " AND t.store_id = ?";
        $params[] = $store_id;
        $types .= 'i';
    }
    // New: Filter by today by default
    if ($filter_date === 'today') {
        $query .= " AND DATE(t.date) = CURDATE()";
    }

    // Changed: Order by ID DESC for reliability (newest first)
    $query .= " ORDER BY t.id DESC LIMIT ? OFFSET ?";
    $params[] = $rows_per_page;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        throw new Exception('Database query preparation failed: ' . mysqli_error($conn));
    }

    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $sales = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    // Get total count for pagination (respect filters)
    $count_query = "SELECT COUNT(*) as total FROM transactions t WHERE 1=1";
    $count_params = [];
    $count_types = '';
    if ($status) {
        $count_query .= " AND t.status = ?";
        $count_params[] = $status;
        $count_types .= 's';
    }
    if ($store_id) {
        $count_query .= " AND t.store_id = ?";
        $count_params[] = $store_id;
        $count_types .= 'i';
    }
    if ($filter_date === 'today') {
        $count_query .= " AND DATE(t.date) = CURDATE()";
    }

    $stmt = mysqli_prepare($conn, $count_query);
    if (!$stmt) {
        throw new Exception('Database query preparation failed: ' . mysqli_error($conn));
    }

    if (!empty($count_params)) {
        mysqli_stmt_bind_param($stmt, $count_types, ...$count_params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $total_rows = mysqli_fetch_assoc($result)['total'];
    mysqli_stmt_close($stmt);

    $total_pages = ceil($total_rows / $rows_per_page);

    $response = [
        'success' => true,
        'sales' => $sales,
        'current_page' => $page,
        'total_pages' => $total_pages
    ];
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('fetch_sales.php error: ' . $e->getMessage());
}

ob_end_clean();
echo json_encode($response);
exit;
?>