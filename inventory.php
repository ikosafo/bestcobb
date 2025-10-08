<?php
// inventory.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php'; // Include PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

$page_title = 'Inventory';

// Check if user is logged in and has the correct role
if (!isset($_SESSION['role']) || $_SESSION['role'] == 'cashier') {
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

// Handle form submissions, GET delete, and export/import
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete'])) {
    $encrypted_id = filter_input(INPUT_GET, 'delete', FILTER_SANITIZE_SPECIAL_CHARS);
    error_log("Delete attempt with encrypted_id: " . ($encrypted_id ?: 'empty'));
    if ($encrypted_id) {
        $delete_id = decryptId($encrypted_id);
        if ($delete_id !== false) {
            // Check for foreign key constraints (e.g., sales or other tables)
            $check_query = "SELECT COUNT(*) as count FROM sales WHERE product_id = ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, 'i', $delete_id);
            mysqli_stmt_execute($check_stmt);
            $result = mysqli_stmt_get_result($check_stmt);
            $row = mysqli_fetch_assoc($result);
            mysqli_stmt_close($check_stmt);

            if ($row['count'] > 0) {
                $error_message = "Cannot delete product: It is associated with {$row['count']} sale(s).";
                error_log("Delete failed: Product ID $delete_id is associated with {$row['count']} sale(s)");
            } else {
                $query = "DELETE FROM products WHERE id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, 'i', $delete_id);
                if (mysqli_stmt_execute($stmt)) {
                    $success_message = "Product deleted successfully!";
                    error_log("Product ID $delete_id deleted successfully");
                } else {
                    $error_message = "Error deleting product: " . mysqli_error($conn);
                    error_log("Delete failed for product ID $delete_id: " . mysqli_error($conn));
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            $error_message = "Invalid or tampered delete request. (Decryption failed for ID: " . htmlspecialchars($encrypted_id) . ")";
            error_log("Decryption failed for encrypted_id: " . $encrypted_id);
        }
    } else {
        $error_message = "Delete request missing encrypted ID.";
        error_log("No encrypted_id received in GET: " . print_r($_GET, true));
    }
}

// Handle export to Excel
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    // Clear any existing output
    if (ob_get_length()) {
        ob_end_clean();
    }
    ob_start();

    // Suppress errors during export
    ini_set('display_errors', 0);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Products');

    // Set headers
    $headers = ['ID', 'Product Name', 'Category', 'Store Name', 'Stock', 'Price', 'Status', 'Barcode'];
    $sheet->fromArray($headers, null, 'A1');

    // Fetch products for export
    $products_query = "SELECT p.id, p.name, p.category, s.store_name, p.stock, p.price, p.status, p.barcode 
                       FROM products p LEFT JOIN stores s ON p.store_id = s.id ORDER BY p.id DESC";
    $products_result = mysqli_query($conn, $products_query);
    $row_number = 2;
    while ($row = mysqli_fetch_assoc($products_result)) {
        $sheet->fromArray([
            $row['id'],
            $row['name'],
            $row['category'],
            $row['store_name'] ?? 'N/A',
            $row['stock'],
            $row['price'],
            $row['status'],
            $row['barcode'] ?? ''
        ], null, "A$row_number");
        $row_number++;
    }

    // Auto-size columns
    foreach (range('A', 'H') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="products_export.xlsx"');
    header('Cache-Control: max-age=0');
    header('Expires: 0');
    header('Pragma: public');

    // Flush output buffer and send file
    ob_end_flush();
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    error_log("Exported products to Excel at " . date('Y-m-d H:i:s'));
    exit;
}

// Handle import from Excel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    if ($file['error'] === UPLOAD_ERR_OK && $file['size'] > 0) {
        $allowed_types = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'application/octet-stream',
            'application/zip'
        ];
        error_log("Uploaded file type: " . $file['type']);
        if (in_array($file['type'], $allowed_types)) {
            try {
                $spreadsheet = IOFactory::load($file['tmp_name']);
                $sheet = $spreadsheet->getActiveSheet();
                $data = $sheet->toArray();
                $headers = array_shift($data);

                // Normalize headers for comparison
                $headers = array_map('trim', array_map('strtolower', $headers));
                $expected_headers = array_map('strtolower', ['ID', 'Product Name', 'Category', 'Store Name', 'Stock', 'Price', 'Status', 'Barcode']);

                if ($headers !== $expected_headers) {
                    $error_message = "Invalid Excel file format. Expected headers: " . implode(', ', $expected_headers) . ". Found: " . implode(', ', $headers);
                    error_log("Invalid Excel headers: " . implode(', ', $headers));
                } else {
                    $success_count = 0;
                    $error_count = 0;
                    foreach ($data as $row) {
                        $id = filter_var($row[0], FILTER_VALIDATE_INT) ?: null;
                        $name = trim(filter_var($row[1], FILTER_SANITIZE_SPECIAL_CHARS) ?: '');
                        $category = trim(filter_var($row[2], FILTER_SANITIZE_SPECIAL_CHARS) ?: '');
                        $store_name = trim(filter_var($row[3], FILTER_SANITIZE_SPECIAL_CHARS) ?: '');
                        $stock = filter_var($row[4], FILTER_VALIDATE_INT) ?: null;
                        $price = filter_var($row[5], FILTER_VALIDATE_FLOAT) ?: null;
                        $status = trim(filter_var($row[6], FILTER_SANITIZE_SPECIAL_CHARS) ?: '');
                        $barcode = trim(filter_var($row[7], FILTER_SANITIZE_SPECIAL_CHARS) ?: '');

                        if (!$name || !$category || !$store_name || $stock === null || $price === null || !$status) {
                            error_log("Skipping row due to missing fields: " . json_encode($row));
                            $error_count++;
                            continue;
                        }

                        if (!in_array($status, ['In Stock', 'Low Stock', 'Out of Stock'])) {
                            error_log("Invalid status '$status' in row: " . json_encode($row));
                            $error_count++;
                            continue;
                        }

                        // Validate store_id
                        $store_query = "SELECT id FROM stores WHERE store_name = ? AND status = 'Active'";
                        $stmt = mysqli_prepare($conn, $store_query);
                        mysqli_stmt_bind_param($stmt, 's', $store_name);
                        mysqli_stmt_execute($stmt);
                        $store_result = mysqli_stmt_get_result($stmt);
                        if ($store_row = mysqli_fetch_assoc($store_result)) {
                            $store_id = $store_row['id'];
                        } else {
                            error_log("Invalid store name '$store_name' in row: " . json_encode($row));
                            $error_count++;
                            continue;
                        }
                        mysqli_stmt_close($stmt);

                        // Escape values to prevent SQL injection
                        $escaped_name = mysqli_real_escape_string($conn, $name);
                        $escaped_category = mysqli_real_escape_string($conn, $category);
                        $escaped_store_id = (int)$store_id; // Integer, no escaping needed
                        $escaped_stock = (int)$stock; // Integer, no escaping needed
                        $escaped_price = (float)$price; // Float, no escaping needed
                        $escaped_status = mysqli_real_escape_string($conn, $status);
                        $escaped_barcode = mysqli_real_escape_string($conn, $barcode);

                        if ($id) {
                            $escaped_id = (int)$id; // Integer, no escaping needed
                            // Check if product exists
                            $check_query = "SELECT id FROM products WHERE id = $escaped_id";
                            $check_result = mysqli_query($conn, $check_query);
                            if (!$check_result) {
                                error_log("Error checking product ID $escaped_id: " . mysqli_error($conn));
                                $error_count++;
                                continue;
                            }
                            if (mysqli_num_rows($check_result) > 0) {
                                // Update existing product
                                $query = "UPDATE products SET `name` = '$escaped_name', category = '$escaped_category', store_id = $escaped_store_id, stock = $escaped_stock, price = $escaped_price, `status` = '$escaped_status', barcode = '$escaped_barcode' WHERE id = $escaped_id";
                            } else {
                                // Insert new product with ID
                                $query = "INSERT INTO products (id, name, category, store_id, stock, price, status, barcode) VALUES ($escaped_id, '$escaped_name', '$escaped_category', $escaped_store_id, $escaped_stock, $escaped_price, '$escaped_status', '$escaped_barcode')";
                            }
                            mysqli_free_result($check_result);
                        } else {
                            // Insert new product without ID (auto-increment)
                            $query = "INSERT INTO products (name, category, store_id, stock, price, status, barcode) VALUES ('$escaped_name', '$escaped_category', $escaped_store_id, $escaped_stock, $escaped_price, '$escaped_status', '$escaped_barcode')";
                        }

                        // Execute the query
                        if (mysqli_query($conn, $query)) {
                            $success_count++;
                        } else {
                            error_log("Error importing product: " . mysqli_error($conn) . " - Row: " . json_encode($row));
                            $error_count++;
                        }
                    }
                    $success_message = "Imported $success_count products successfully. $error_count errors occurred.";
                    error_log("Import completed: $success_count successes, $error_count errors");
                }
            } catch (Exception $e) {
                $error_message = "Error processing Excel file: " . $e->getMessage();
                error_log("Import error: " . $e->getMessage());
            }
        } else {
            $error_message = "Invalid file type. Please upload an Excel file (.xlsx or .xls). Received type: " . $file['type'];
            error_log("Invalid file type uploaded: " . $file['type']);
        }
    } else {
        $error_message = "Error uploading file: " . ($file['error'] === UPLOAD_ERR_NO_FILE ? "No file uploaded" : $file['error']);
        error_log("File upload error: " . $file['error']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add', 'edit'])) {
    $product_id = filter_input(INPUT_POST, 'product_id', FILTER_SANITIZE_NUMBER_INT);
    $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS) ?: '');
    $category = trim(filter_input(INPUT_POST, 'category', FILTER_SANITIZE_SPECIAL_CHARS) ?: '');
    $store_id = filter_input(INPUT_POST, 'store_id', FILTER_SANITIZE_NUMBER_INT);
    $stock = filter_input(INPUT_POST, 'stock', FILTER_SANITIZE_NUMBER_INT);
    $price = filter_input(INPUT_POST, 'price', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $status = trim(filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS) ?: '');
    $barcode = trim(filter_input(INPUT_POST, 'barcode', FILTER_SANITIZE_SPECIAL_CHARS) ?: '');

    if ($name && $category && $store_id && $stock !== null && $price !== null && $status) {
        if ($_POST['action'] === 'add') {
            $query = "INSERT INTO products (name, category, store_id, stock, price, status, barcode) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, 'ssiidss', $name, $category, $store_id, $stock, $price, $status, $barcode); // Corrected
        } else {
            $query = "UPDATE products SET name = ?, category = ?, store_id = ?, stock = ?, price = ?, status = ?, barcode = ? WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, 'ssisdsdsi', $name, $category, $store_id, $stock, $price, $status, $barcode, $product_id);
        }
        if (mysqli_stmt_execute($stmt)) {
            $success_message = $_POST['action'] === 'add' ? 'Product added successfully!' : 'Product updated successfully!';
            error_log($_POST['action'] === 'add' ? "Product added: $name" : "Product updated: ID $product_id");
        } else {
            $error_message = 'Error saving product: ' . mysqli_error($conn);
            error_log("Error saving product: " . mysqli_error($conn));
        }
        mysqli_stmt_close($stmt);
    } else {
        $error_message = 'Please fill in all required fields.';
        error_log("Missing required fields in product form: " . print_r($_POST, true));
    }
}

// Fetch products
$products_query = "SELECT p.id, p.name, p.category, p.store_id, p.stock, p.price, p.status, p.barcode, s.store_name 
                   FROM products p LEFT JOIN stores s ON p.store_id = s.id ORDER BY p.id DESC";
$products_result = mysqli_query($conn, $products_query);
$products = [];
if ($products_result) {
    while ($row = mysqli_fetch_assoc($products_result)) {
        $product_id = $row['id'] ?? '';
        $encrypted_id = $product_id ? encryptId($product_id) : '';
        if ($encrypted_id === false) {
            error_log("Failed to encrypt product ID: $product_id");
        }
        $products[] = [
            'id' => $product_id,
            'encrypted_id' => $encrypted_id,
            'name' => $row['name'] ?? '',
            'category' => $row['category'] ?? '',
            'store_id' => $row['store_id'] ?? '',
            'store_name' => $row['store_name'] ?? 'N/A',
            'stock' => $row['stock'] ?? 0,
            'price' => $row['price'] ?? 0.00,
            'status' => $row['status'] ?? 'Out of Stock',
            'barcode' => $row['barcode'] ?? ''
        ];
    }
} else {
    $error_message = 'Error fetching products: ' . mysqli_error($conn);
    error_log("Error fetching products: " . mysqli_error($conn));
}

// Fetch stores for dropdown
$stores_query = "SELECT id, store_name FROM stores WHERE status = 'Active' ORDER BY store_name";
$stores_result = mysqli_query($conn, $stores_query);
$stores = $stores_result ? mysqli_fetch_all($stores_result, MYSQLI_ASSOC) : [];

// Fetch metrics
$total_products = count($products);
$low_stock_query = "SELECT COUNT(*) as count FROM products WHERE stock < 10";
$low_stock_result = mysqli_query($conn, $low_stock_query);
$low_stock_items = $low_stock_result ? mysqli_fetch_assoc($low_stock_result)['count'] : 0;
$total_value_query = "SELECT SUM(stock * price) as total FROM products";
$total_value_result = mysqli_query($conn, $total_value_query);
$total_value = $total_value_result ? mysqli_fetch_assoc($total_value_result)['total'] : 0;

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

    <!-- Inventory Table -->
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-semibold">Inventory Management</h2>
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <input type="text" id="search-products" placeholder="Search products..." class="pl-10 pr-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary" oninput="filterProducts()">
                    <i data-feather="search" class="absolute left-3 top-1/2 transform -translate-y-1/2 text-neutral"></i>
                </div>
                <select id="store-filter" class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm" onchange="filterProducts()">
                    <option value="">All Stores</option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="status-filter" class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm" onchange="filterProducts()">
                    <option value="">All Statuses</option>
                    <option value="In Stock">In Stock</option>
                    <option value="Out of Stock">Out of Stock</option>
                    <option value="Low Stock">Low Stock</option>
                </select>
                <button onclick="openModal('add')" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                    <i data-feather="plus" class="w-4 h-4 mr-2"></i> Add Product
                </button>
                <p>
                    <a href="inventory.php?export=true" class="bg-accent text-white px-4 py-2 rounded-lg hover:bg-accent/90 transition flex items-center">
                        <i data-feather="download" class="w-4 h-4 mr-2"></i> Export Excel
                    </a>
                    <br>
                    <button onclick="openImportModal()" class="bg-accent text-white px-4 py-2 rounded-lg hover:bg-accent/90 transition flex items-center">
                        <i data-feather="upload" class="w-4 h-4 mr-2"></i> Import Excel
                    </button>
                </p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="p-3 text-left font-medium">ID</th>
                        <th class="p-3 text-left font-medium">Product Name</th>
                        <th class="p-3 text-left font-medium">Category</th>
                        <th class="p-3 text-left font-medium">Store</th>
                        <th class="p-3 text-left font-medium">Stock</th>
                        <th class="p-3 text-left font-medium">Price</th>
                        <th class="p-3 text-left font-medium">Status</th>
                        <th class="p-3 text-left font-medium">Barcode</th>
                        <th class="p-3 text-left font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody id="product-table">
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="9" class="p-3 text-center text-gray-500">No products found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <tr class="table-row border-b border-gray-200 dark:border-gray-700" data-status="<?php echo htmlspecialchars($product['status'], ENT_QUOTES, 'UTF-8'); ?>" data-store="<?php echo $product['store_id']; ?>">
                                <td class="p-3"><?php echo htmlspecialchars($product['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($product['store_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($product['stock'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8') . number_format($product['price'], 2); ?></td>
                                <td class="p-3">
                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                        echo $product['status'] == 'In Stock' ? 'bg-green-100 text-green-700' : 
                                            ($product['status'] == 'Low Stock' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'); ?>">
                                        <?php echo htmlspecialchars($product['status'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td class="p-3"><?php echo htmlspecialchars($product['barcode'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3 flex space-x-2">
                                    <button onclick='openModal("edit", <?php echo json_encode($product); ?>)' class="text-primary hover:text-primary/80">
                                        <i data-feather="edit" class="w-5 h-5"></i>
                                    </button>
                                    <button 
                                        onclick='if (confirm(`Are you sure you want to delete \"<?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>\"? This action cannot be undone and may fail if the product is linked to sales.`)) { window.location.href = "inventory.php?delete=<?php echo htmlspecialchars($product['encrypted_id'], ENT_QUOTES, 'UTF-8'); ?>"; }' 
                                        class="text-red-500 hover:text-red-600" 
                                        <?php echo ($product['encrypted_id'] === false || $product['encrypted_id'] === '') ? 'disabled title="Encryption failed for this ID"' : ''; ?>
                                    >
                                        <i data-feather="trash-2" class="w-5 h-5"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Inventory Metrics Overview -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <i data-feather="package" class="w-8 h-8 text-accent mr-4"></i>
                <div>
                    <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Products</h2>
                    <p class="text-xl font-semibold text-primary"><?php echo $total_products; ?></p>
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
        <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <i data-feather="dollar-sign" class="w-8 h-8 text-accent mr-4"></i>
                <div>
                    <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Inventory Value</h2>
                    <p class="text-xl font-semibold text-primary"><?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8') . number_format($total_value, 2); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Add/Edit Product -->
    <div id="product-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden modal">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 w-full max-w-lg modal-content">
            <h2 id="modal-title" class="text-lg font-semibold mb-4"></h2>
            <form id="product-form" method="POST" action="inventory.php" class="space-y-4">
                <input type="hidden" name="action" id="form-action">
                <input type="hidden" name="product_id" id="product-id">
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Product Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="product-name" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Category <span class="text-red-500">*</span></label>
                    <input type="text" name="category" id="product-category" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Store <span class="text-red-500">*</span></label>
                    <select name="store_id" id="product-store-id" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Stock <span class="text-red-500">*</span></label>
                    <input type="number" name="stock" id="product-stock" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" min="0" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Price (<?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8'); ?>) <span class="text-red-500">*</span></label>
                    <input type="number" name="price" id="product-price" step="0.01" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" min="0" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Status <span class="text-red-500">*</span></label>
                    <select name="status" id="product-status" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
                        <option value="In Stock">In Stock</option>
                        <option value="Low Stock">Low Stock</option>
                        <option value="Out of Stock">Out of Stock</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Barcode</label>
                    <input type="text" name="barcode" id="product-barcode" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div class="flex space-x-4">
                    <button type="submit" class="bg-primary text-white px-6 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                        <i data-feather="save" class="w-4 h-4 mr-2"></i> Save
                    </button>
                    <button type="button" onclick="closeModal()" class="bg-neutral text-white px-6 py-2 rounded-lg hover:bg-neutral/90 transition flex items-center">
                        <i data-feather="x" class="w-4 h-4 mr-2"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Import Excel Modal -->
    <div id="import-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden modal">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 w-full max-w-md">
            <h2 class="text-lg font-semibold mb-4">Import Products from Excel</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                Upload an Excel file with columns: ID (optional), Product Name, Category, Store Name, Stock, Price, Status (In Stock, Low Stock, or Out of Stock), Barcode.
            </p>
            <form id="import-form" method="POST" action="inventory.php" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="import">
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Excel File <span class="text-red-500">*</span></label>
                    <input type="file" name="import_file" accept=".xlsx,.xls" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
                </div>
                <div class="flex space-x-4">
                    <button type="submit" class="bg-primary text-white px-6 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                        <i data-feather="upload" class="w-4 h-4 mr-2"></i> Import
                    </button>
                    <button type="button" onclick="closeImportModal()" class="bg-neutral text-white px-6 py-2 rounded-lg hover:bg-neutral/90 transition flex items-center">
                        <i data-feather="x" class="w-4 h-4 mr-2"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
    // Initialize Feather Icons
    feather.replace();

    // Modal handling for Add/Edit
    function openModal(action, product = {}) {
        const modal = document.getElementById('product-modal');
        const title = document.getElementById('modal-title');
        document.getElementById('form-action').value = action;
        title.textContent = action === 'add' ? 'Add Product' : 'Edit Product';
        
        document.getElementById('product-id').value = product.id || '';
        document.getElementById('product-name').value = product.name || '';
        document.getElementById('product-category').value = product.category || '';
        document.getElementById('product-store-id').value = product.store_id || '';
        document.getElementById('product-stock').value = product.stock || '';
        document.getElementById('product-price').value = product.price || '';
        document.getElementById('product-status').value = product.status || 'In Stock';
        document.getElementById('product-barcode').value = product.barcode || '';
        
        modal.classList.remove('hidden');
        console.log('Opened product modal for action:', action, 'Product:', product);
        feather.replace();
    }

    function closeModal() {
        document.getElementById('product-modal').classList.add('hidden');
        document.getElementById('product-form').reset();
        console.log('Closed product modal');
    }

    // Modal handling for Import
    function openImportModal() {
        document.getElementById('import-modal').classList.remove('hidden');
        console.log('Opened import modal');
        feather.replace();
    }

    function closeImportModal() {
        document.getElementById('import-modal').classList.add('hidden');
        document.getElementById('import-form').reset();
        console.log('Closed import modal');
    }

    // Product filtering
    function filterProducts() {
        const search = document.getElementById('search-products').value.toLowerCase();
        const store = document.getElementById('store-filter').value;
        const status = document.getElementById('status-filter').value;
        const rows = document.querySelectorAll('#product-table tr');
        
        rows.forEach(row => {
            const name = row.children[1]?.textContent.toLowerCase() || '';
            const category = row.children[2]?.textContent.toLowerCase() || '';
            const rowStore = row.getAttribute('data-store') || '';
            const rowStatus = row.getAttribute('data-status') || '';
            const matchesSearch = name.includes(search) || category.includes(search);
            const matchesStore = !store || rowStore === store;
            const matchesStatus = !status || rowStatus === status;
            row.style.display = matchesSearch && matchesStore && matchesStatus ? '' : 'none';
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php mysqli_close($conn); ?>