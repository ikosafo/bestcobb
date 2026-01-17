<?php
require_once 'config.php';
header('Content-Type: application/json');

$id = (int)$_GET['id'];
if (!$id) {
    echo json_encode(['success' => false]);
    exit;
}

$query = "
    SELECT t.*, s.store_name 
    FROM transactions t 
    LEFT JOIN stores s ON t.store_id = s.id 
    WHERE t.id = ?
";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$transaction = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$transaction) {
    echo json_encode(['success' => false]);
    exit;
}

$itemsQuery = "SELECT ti.*, p.name as product_name FROM transaction_items ti LEFT JOIN products p ON ti.product_id = p.id WHERE ti.transaction_id = ?";
$stmt2 = mysqli_prepare($conn, $itemsQuery);
mysqli_stmt_bind_param($stmt2, 'i', $id);
mysqli_stmt_execute($stmt2);
$itemsResult = mysqli_stmt_get_result($stmt2);
$items = [];
while ($item = mysqli_fetch_assoc($itemsResult)) {
    $items[] = $item;
}

echo json_encode([
    'success' => true,
    'transaction' => array_merge($transaction, ['items' => $items])
]);