<?php
// reports.php
require_once __DIR__ . '/config.php';
$page_title = 'Reports';

// Check if user is logged in and has the correct role
if (!isset($_SESSION['role']) || $_SESSION['role'] == 'Cashier') {
    header('Location: index.php'); // Redirect to Dashboard if Cashier tries to access
    exit;
}

// Enable full error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Ensure ENCRYPTION_KEY is defined
if (!defined('ENCRYPTION_KEY')) {
    die("Encryption key not defined in config.php. Please define: define('ENCRYPTION_KEY', 'Your32CharacterSecretKeyHere...');");
}

// Initialize user array
$user = [
    'name' => $_SESSION['username'] ?? 'John Doe',
    'role' => $_SESSION['role'] ?? 'Mall Admin',
    'store' => 'All Stores'
];

// Fetch store name if store_id is set
if (isset($_SESSION['store_id'])) {
    $store_query = "SELECT store_name FROM stores WHERE id = ? AND status = 'Active'";
    $stmt = mysqli_prepare($conn, $store_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $_SESSION['store_id']);
        mysqli_stmt_execute($stmt);
        $store_result = mysqli_stmt_get_result($stmt);
        if ($store_row = mysqli_fetch_assoc($store_result)) {
            $user['store'] = $store_row['store_name'];
        } else {
            error_log("No active store found for store_id: {$_SESSION['store_id']}");
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log("Error preparing store query: " . mysqli_error($conn));
    }
}

// Fetch currency symbol
$settings_query = "SELECT currency_symbol FROM settings LIMIT 1";
$settings_result = mysqli_query($conn, $settings_query);
$settings = $settings_result && mysqli_num_rows($settings_result) > 0 ? mysqli_fetch_assoc($settings_result) : ['currency_symbol' => '$'];
$currency_symbol = $settings['currency_symbol'];

// Encryption functions
function encryptId($id) {
    $cipher = "AES-256-CBC";
    $iv_length = openssl_cipher_iv_length($cipher);
    $iv = openssl_random_pseudo_bytes($iv_length);
    $encrypted = openssl_encrypt($id, $cipher, ENCRYPTION_KEY, 0, $iv);
    if ($encrypted === false) {
        error_log("Encryption failed for ID: " . $id);
        return false;
    }
    return base64_encode($encrypted . '::' . base64_encode($iv));
}

function decryptId($encrypted) {
    $encrypted = trim($encrypted);
    $decoded_data = base64_decode($encrypted, true);
    if ($decoded_data === false) {
        error_log("Decryption error: Failed to Base64 decode the input.");
        return false;
    }
    $parts = explode('::', $decoded_data);
    if (count($parts) !== 2) {
        error_log("Decryption error: Data format invalid (missing '::').");
        return false;
    }
    list($encrypted_data, $iv_base64) = $parts;
    $iv = base64_decode($iv_base64, true);
    if ($iv === false) {
        error_log("Decryption error: Failed to Base64 decode IV.");
        return false;
    }
    $decrypted = openssl_decrypt($encrypted_data, "AES-256-CBC", ENCRYPTION_KEY, 0, $iv);
    if ($decrypted === false) {
        error_log("Decryption error: openssl_decrypt failed. Check ENCRYPTION_KEY.");
        return false;
    }
    $decrypted_id = trim($decrypted);
    if (!is_numeric($decrypted_id)) {
        error_log("Decryption error: Decrypted data is not numeric. Received: " . $decrypted_id);
        return false;
    }
    return (int)$decrypted_id;
}

// Custom date validation function
function validateDate($date, $format = 'Y-m-d') {
    if (empty($date)) {
        return false;
    }
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Handle export requests
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    $encrypted_type = filter_input(INPUT_GET, 'export', FILTER_SANITIZE_SPECIAL_CHARS);
    $export_type = decryptId($encrypted_type);
    if ($export_type !== false) {
        // Prepare CSV export
        $filename = "report_" . $export_type . "_" . date('YmdHis') . ".csv";
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');

        // Define report queries
        $report_types = [
            'sales' => [
                'headers' => ['Transaction ID', 'Product', 'Store', 'Quantity', 'Total Amount', 'Status', 'Date'],
                'query' => "SELECT s.id, p.name as product_name, st.store_name, s.quantity, s.total_amount, s.status, s.transaction_date 
                            FROM sales s 
                            LEFT JOIN products p ON s.product_id = p.id 
                            LEFT JOIN stores st ON s.store_id = st.id 
                            WHERE s.status = 'Completed'"
            ],
            'inventory' => [
                'headers' => ['Product ID', 'Product Name', 'Category', 'Store', 'Stock', 'Price', 'Status'],
                'query' => "SELECT p.id, p.name, p.category, st.store_name, p.stock, p.price, p.status 
                            FROM products p 
                            LEFT JOIN stores st ON p.store_id = st.id"
            ]
        ];

        // Apply store filter for non-admins
        if ($user['role'] !== 'Mall Admin' && isset($_SESSION['store_id'])) {
            $report_types['sales']['query'] .= " AND s.store_id = ?";
            $report_types['inventory']['query'] .= " AND p.store_id = ?";
        }

        if (isset($report_types[$export_type])) {
            $query = $report_types[$export_type]['query'];
            $stmt = mysqli_prepare($conn, $query);
            if ($user['role'] !== 'Mall Admin' && isset($_SESSION['store_id'])) {
                mysqli_stmt_bind_param($stmt, 'i', $_SESSION['store_id']);
            }
            if (!mysqli_stmt_execute($stmt)) {
                error_log("Export query failed: " . mysqli_error($conn));
                $error_message = "Failed to export data. Please try again.";
            } else {
                $result = mysqli_stmt_get_result($stmt);

                // Write CSV headers
                fputcsv($output, $report_types[$export_type]['headers']);

                // Write CSV rows
                while ($row = mysqli_fetch_assoc($result)) {
                    if ($export_type === 'sales') {
                        $row['total_amount'] = $currency_symbol . number_format($row['total_amount'] ?? 0.00, 2);
                        $row['transaction_date'] = date('Y-m-d H:i:s', strtotime($row['transaction_date']));
                        fputcsv($output, [
                            $row['id'],
                            $row['product_name'] ?? 'N/A',
                            $row['store_name'] ?? 'N/A',
                            $row['quantity'] ?? 0,
                            $row['total_amount'],
                            $row['status'] ?? 'Pending',
                            $row['transaction_date']
                        ]);
                    } elseif ($export_type === 'inventory') {
                        $row['price'] = $currency_symbol . number_format($row['price'] ?? 0.00, 2);
                        fputcsv($output, [
                            $row['id'],
                            $row['name'] ?? 'N/A',
                            $row['category'] ?? 'N/A',
                            $row['store_name'] ?? 'N/A',
                            $row['stock'] ?? 0,
                            $row['price'],
                            $row['status'] ?? 'Out of Stock'
                        ]);
                    }
                }
                mysqli_stmt_close($stmt);
                fclose($output);
                exit;
            }
        } else {
            $error_message = "Invalid export type.";
            error_log("Invalid export type: " . $export_type);
        }
    } else {
        $error_message = "Invalid or tampered export request.";
        error_log("Decryption failed for export type: " . $encrypted_type);
    }
}

// Handle filters
$start_date_input = filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
$end_date_input = filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
$store_id = filter_input(INPUT_GET, 'store_id', FILTER_SANITIZE_NUMBER_INT) ?: '';
$report_type_input = filter_input(INPUT_GET, 'report_type', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'sales';

// Validate dates
$start_date = validateDate($start_date_input) ? $start_date_input : date('Y-m-d', strtotime('-30 days'));
$end_date = validateDate($end_date_input) ? $end_date_input : date('Y-m-d');

// Validate report_type
$valid_report_types = ['sales', 'inventory'];
$report_type = in_array($report_type_input, $valid_report_types) ? $report_type_input : 'sales';

// Fetch stores for dropdown
$stores_query = "SELECT id, store_name FROM stores WHERE status = 'Active' ORDER BY store_name";
$stores_result = mysqli_query($conn, $stores_query);
$stores = $stores_result ? mysqli_fetch_all($stores_result, MYSQLI_ASSOC) : [];

// Fetch metrics
$total_sales_query = "SELECT COUNT(*) as count, SUM(total_amount) as total FROM sales WHERE status = 'Completed'";
if ($user['role'] !== 'Mall Admin' && isset($_SESSION['store_id'])) {
    $total_sales_query .= " AND store_id = ?";
    $stmt = mysqli_prepare($conn, $total_sales_query);
    mysqli_stmt_bind_param($stmt, 'i', $_SESSION['store_id']);
} else {
    $stmt = mysqli_prepare($conn, $total_sales_query);
}
if (!mysqli_stmt_execute($stmt)) {
    error_log("Total sales query failed: " . mysqli_error($conn));
}
$total_sales_result = mysqli_stmt_get_result($stmt);
$total_sales = mysqli_fetch_assoc($total_sales_result) ?: ['count' => 0, 'total' => 0.00];
mysqli_stmt_close($stmt);

$low_stock_query = "SELECT COUNT(*) as count FROM products WHERE stock < 10";
if ($user['role'] !== 'Mall Admin' && isset($_SESSION['store_id'])) {
    $low_stock_query .= " AND store_id = ?";
    $stmt = mysqli_prepare($conn, $low_stock_query);
    mysqli_stmt_bind_param($stmt, 'i', $_SESSION['store_id']);
} else {
    $stmt = mysqli_prepare($conn, $low_stock_query);
}
if (!mysqli_stmt_execute($stmt)) {
    error_log("Low stock query failed: " . mysqli_error($conn));
}
$low_stock_result = mysqli_stmt_get_result($stmt);
$low_stock_items = mysqli_fetch_assoc($low_stock_result)['count'] ?? 0;
mysqli_stmt_close($stmt);

$top_products_query = "SELECT p.name, SUM(s.quantity) as total_quantity 
                      FROM sales s 
                      LEFT JOIN products p ON s.product_id = p.id 
                      WHERE s.status = 'Completed' 
                      GROUP BY p.id, p.name";
if ($user['role'] !== 'Mall Admin' && isset($_SESSION['store_id'])) {
    $top_products_query .= " AND s.store_id = ?";
    $stmt = mysqli_prepare($conn, $top_products_query);
    mysqli_stmt_bind_param($stmt, 'i', $_SESSION['store_id']);
} else {
    $stmt = mysqli_prepare($conn, $top_products_query);
}
if (!mysqli_stmt_execute($stmt)) {
    error_log("Top products query failed: " . mysqli_error($conn));
}
$top_products_result = mysqli_stmt_get_result($stmt);
$top_products = [];
while ($row = mysqli_fetch_assoc($top_products_result)) {
    $top_products[] = $row;
}
mysqli_stmt_close($stmt);

// Fixed: Use SELECT alias in ORDER BY to comply with ONLY_FULL_GROUP_BY
$sales_trend_query = "SELECT DATE(transaction_date) as date, SUM(total_amount) as total 
                      FROM sales 
                      WHERE status = 'Completed' AND transaction_date BETWEEN ? AND ? 
                      GROUP BY date 
                      ORDER BY date"; // Changed to use alias 'date'
$params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
$types = 'ss';
if ($user['role'] !== 'Mall Admin' && isset($_SESSION['store_id'])) {
    $sales_trend_query .= " AND store_id = ?";
    $params[] = $_SESSION['store_id'];
    $types .= 'i';
}
$stmt = mysqli_prepare($conn, $sales_trend_query);
if (!$stmt) {
    error_log("Sales trend query preparation failed: " . mysqli_error($conn));
    $error_message = "Failed to load sales trend data.";
} else {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) {
        error_log("Sales trend query execution failed: " . mysqli_error($conn));
        $error_message = "Failed to load sales trend data.";
    } else {
        $sales_trend_result = mysqli_stmt_get_result($stmt);
        $sales_trend = [];
        $labels = [];
        while ($row = mysqli_fetch_assoc($sales_trend_result)) {
            $labels[] = date('M d', strtotime($row['date']));
            $sales_trend[] = $row['total'] ?? 0.00;
        }
        mysqli_stmt_close($stmt);
    }
}

// Fetch report data
$report_query = $report_type === 'sales' ?
    "SELECT s.id, p.name as product_name, st.store_name, s.quantity, s.total_amount, s.status, s.transaction_date 
     FROM sales s 
     LEFT JOIN products p ON s.product_id = p.id 
     LEFT JOIN stores st ON s.store_id = st.id 
     WHERE s.transaction_date BETWEEN ? AND ?" :
    "SELECT p.id, p.name, p.category, st.store_name, p.stock, p.price, p.status 
     FROM products p 
     LEFT JOIN stores st ON p.store_id = st.id";
$params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
$types = 'ss';
if ($store_id) {
    $report_query .= " AND " . ($report_type === 'sales' ? 's' : 'p') . ".store_id = ?";
    $params[] = $store_id;
    $types .= 'i';
}
if ($user['role'] !== 'Mall Admin' && isset($_SESSION['store_id'])) {
    $report_query .= " AND " . ($report_type === 'sales' ? 's' : 'p') . ".store_id = ?";
    $params[] = $_SESSION['store_id'];
    $types .= 'i';
}
$report_query .= $report_type === 'sales' ? " ORDER BY s.transaction_date DESC" : " ORDER BY p.id DESC";
$stmt = mysqli_prepare($conn, $report_query);
if (!$stmt) {
    error_log("Report query preparation failed: " . mysqli_error($conn));
    $error_message = "Failed to load report data.";
} else {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) {
        error_log("Report query execution failed: " . mysqli_error($conn));
        $error_message = "Failed to load report data.";
    } else {
        $report_result = mysqli_stmt_get_result($stmt);
        $report_data = [];
        while ($row = mysqli_fetch_assoc($report_result)) {
            $row['id'] = $row['id'] ?? '';
            $row['encrypted_id'] = $row['id'] ? encryptId($row['id']) : '';
            $report_data[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="max-w-7xl mx-auto p-6">
    <?php if ($success_message): ?>
        <div class="mb-6 p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
            <i data-feather="check-circle" class="w-5 h-5 mr-2"></i>
            <?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php elseif ($error_message): ?>
        <div class="mb-6 p-4 bg-red-100 text-red-700 rounded-lg flex items-center">
            <i data-feather="alert-circle" class="w-5 h-5 mr-2"></i>
            <?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 mb-6">
        <h2 class="text-lg font-semibold mb-4">Report Filters</h2>
        <form method="GET" action="reports.php" class="flex flex-col md:flex-row gap-4">
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Start Date</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8'); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none">
            </div>
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">End Date</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8'); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none">
            </div>
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Store</label>
                <select name="store_id" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none">
                    <option value="">All Stores</option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>" <?php echo $store_id == $store['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($store['store_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Report Type</label>
                <select name="report_type" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none">
                    <option value="sales" <?php echo $report_type === 'sales' ? 'selected' : ''; ?>>Sales Report</option>
                    <option value="inventory" <?php echo $report_type === 'inventory' ? 'selected' : ''; ?>>Inventory Report</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                    <i data-feather="filter" class="w-4 h-4 mr-2"></i> Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Metrics Overview -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <i data-feather="shopping-cart" class="w-8 h-8 text-accent mr-4"></i>
                <div>
                    <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Sales</h2>
                    <p class="text-xl font-semibold text-primary"><?php echo $total_sales['count']; ?></p>
                </div>
            </div>
        </div>
        <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <i data-feather="dollar-sign" class="w-8 h-8 text-accent mr-4"></i>
                <div>
                    <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Revenue</h2>
                    <p class="text-xl font-semibold text-primary"><?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8') . number_format($total_sales['total'] ?? 0.00, 2); ?></p>
                </div>
            </div>
        </div>
        <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <i data-feather="alert-triangle" class="w-8 h-8 text-yellow-500 mr-4"></i>
                <div>
                    <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Low Stock Items</h2>
                    <p class="text-xl font-semibold text-primary"><?php echo $low_stock_items; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Sales Trend Chart -->
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 mb-6">
        <h2 class="text-lg font-semibold mb-4">Sales Trend</h2>
        <canvas id="salesTrendChart" class="w-full h-64"></canvas>
    </div>

    <!-- Top Products -->
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 mb-6">
        <h2 class="text-lg font-semibold mb-4">Top Products by Quantity Sold</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="p-3 text-left font-medium">Product Name</th>
                        <th class="p-3 text-left font-medium">Total Quantity Sold</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($top_products)): ?>
                        <tr>
                            <td colspan="2" class="p-3 text-center text-gray-500">No products sold.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($top_products as $product): ?>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <td class="p-3"><?php echo htmlspecialchars($product['name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($product['total_quantity'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Detailed Report -->
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-semibold"><?php echo $report_type === 'sales' ? 'Sales Report' : 'Inventory Report'; ?></h2>
            <div class="flex space-x-4">
                <a href="reports.php?export=<?php echo urlencode(encryptId($report_type)); ?>" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                    <i data-feather="download" class="w-4 h-4 mr-2"></i> Export as CSV
                </a>
                <button onclick="exportToPDF()" class="bg-accent text-white px-4 py-2 rounded-lg hover:bg-accent/90 transition flex items-center">
                    <i data-feather="file-text" class="w-4 h-4 mr-2"></i> Export as PDF
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <?php if ($report_type === 'sales'): ?>
                            <th class="p-3 text-left font-medium">Transaction ID</th>
                            <th class="p-3 text-left font-medium">Product</th>
                            <th class="p-3 text-left font-medium">Store</th>
                            <th class="p-3 text-left font-medium">Quantity</th>
                            <th class="p-3 text-left font-medium">Total Amount</th>
                            <th class="p-3 text-left font-medium">Status</th>
                            <th class="p-3 text-left font-medium">Date</th>
                        <?php else: ?>
                            <th class="p-3 text-left font-medium">Product ID</th>
                            <th class="p-3 text-left font-medium">Product Name</th>
                            <th class="p-3 text-left font-medium">Category</th>
                            <th class="p-3 text-left font-medium">Store</th>
                            <th class="p-3 text-left font-medium">Stock</th>
                            <th class="p-3 text-left font-medium">Price</th>
                            <th class="p-3 text-left font-medium">Status</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="report-table">
                    <?php if (empty($report_data)): ?>
                        <tr>
                            <td colspan="<?php echo $report_type === 'sales' ? 7 : 7; ?>" class="p-3 text-center text-gray-500">No data available.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($report_data as $row): ?>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <?php if ($report_type === 'sales'): ?>
                                    <td class="p-3"><?php echo htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($row['product_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($row['store_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($row['quantity'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8') . number_format($row['total_amount'] ?? 0.00, 2); ?></td>
                                    <td class="p-3">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                            echo ($row['status'] == 'Completed') ? 'bg-green-100 text-green-700' : 
                                                ($row['status'] == 'Pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'); ?>">
                                            <?php echo htmlspecialchars($row['status'] ?? 'Pending', ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td class="p-3"><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($row['transaction_date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php else: ?>
                                    <td class="p-3"><?php echo htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($row['name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($row['category'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($row['store_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($row['stock'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8') . number_format($row['price'] ?? 0.00, 2); ?></td>
                                    <td class="p-3">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                            echo ($row['status'] == 'In Stock') ? 'bg-green-100 text-green-700' : 
                                                ($row['status'] == 'Low Stock' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'); ?>">
                                            <?php echo htmlspecialchars($row['status'] ?? 'Out of Stock', ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>
<script>
    // Initialize Feather Icons
    feather.replace();

    // Sales Trend Chart
    const ctx = document.getElementById('salesTrendChart').getContext('2d');
    const salesTrendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($labels); ?>,
            datasets: [{
                label: 'Sales Revenue (<?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8'); ?>)',
                data: <?php echo json_encode($sales_trend); ?>,
                borderColor: '#10B981',
                backgroundColor: 'rgba(16, 185, 129, 0.2)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                    labels: { color: '#4B5563' }
                }
            },
            scales: {
                x: { ticks: { color: '#4B5563' } },
                y: { 
                    ticks: { 
                        color: '#4B5563',
                        callback: function(value) {
                            return '<?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8'); ?>' + value.toFixed(2);
                        }
                    }
                }
            }
        }
    });

    // Export to PDF
    function exportToPDF() {
        const element = document.querySelector('.card:last-child');
        const options = {
            margin: 1,
            filename: 'report_<?php echo $report_type . '_' . date('YmdHis'); ?>.pdf',
            html2canvas: { scale: 2 },
            jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' }
        };
        html2pdf().set(options).from(element).save().catch(err => {
            console.error('PDF export failed:', err);
            alert('Failed to export PDF. Please try again.');
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php mysqli_close($conn); ?>