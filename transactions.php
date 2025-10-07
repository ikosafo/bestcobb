<?php
// transactions.php
require_once __DIR__ . '/config.php';
$page_title = 'Transactions';

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

// Handle form submissions and GET delete
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete'])) {
    $encrypted_id = filter_input(INPUT_GET, 'delete', FILTER_SANITIZE_SPECIAL_CHARS);
    error_log("Delete attempt with encrypted_id: " . ($encrypted_id ?: 'empty'));
    if ($encrypted_id) {
        $delete_id = decryptId($encrypted_id);
        if ($delete_id !== false) {
            $query = "DELETE FROM sales WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, 'i', $delete_id);
            if (mysqli_stmt_execute($stmt)) {
                $success_message = "Transaction deleted successfully!";
                error_log("Transaction ID $delete_id deleted successfully");
            } else {
                $error_message = "Error deleting transaction: " . mysqli_error($conn);
                error_log("Delete failed for transaction ID $delete_id: " . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt);
        } else {
            $error_message = "Invalid or tampered delete request. (Decryption failed for ID: " . htmlspecialchars($encrypted_id) . ")";
            error_log("Decryption failed for encrypted_id: " . $encrypted_id);
        }
    } else {
        $error_message = "Delete request missing encrypted ID.";
        error_log("No encrypted_id received in GET: " . print_r($_GET, true));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $sale_id = filter_input(INPUT_POST, 'sale_id', FILTER_SANITIZE_NUMBER_INT);
            $product_id = filter_input(INPUT_POST, 'product_id', FILTER_SANITIZE_NUMBER_INT);
            $store_id = filter_input(INPUT_POST, 'store_id', FILTER_SANITIZE_NUMBER_INT);
            $quantity = filter_input(INPUT_POST, 'quantity', FILTER_SANITIZE_NUMBER_INT);
            $total_amount = filter_input(INPUT_POST, 'total_amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
            $transaction_date = filter_input(INPUT_POST, 'transaction_date', FILTER_SANITIZE_STRING);

            // Validate inputs to prevent NULL
            if ($product_id && $store_id && $quantity !== null && $quantity > 0 && $total_amount !== null && $total_amount >= 0 && $status && $transaction_date) {
                // Validate product and store
                $product_query = "SELECT id, stock FROM products WHERE id = ? AND store_id = ?";
                $stmt = mysqli_prepare($conn, $product_query);
                mysqli_stmt_bind_param($stmt, 'ii', $product_id, $store_id);
                mysqli_stmt_execute($stmt);
                $product_result = mysqli_stmt_get_result($stmt);
                if (!$product_result || mysqli_num_rows($product_result) === 0) {
                    $error_message = "Invalid product or store.";
                    error_log("Invalid product_id: $product_id or store_id: $store_id");
                    mysqli_stmt_close($stmt);
                } else {
                    $product = mysqli_fetch_assoc($product_result);
                    if ($_POST['action'] === 'add' && $quantity > $product['stock']) {
                        $error_message = "Insufficient stock for product.";
                        error_log("Insufficient stock for product_id: $product_id, requested: $quantity, available: {$product['stock']}");
                        mysqli_stmt_close($stmt);
                    } else {
                        mysqli_stmt_close($stmt);
                        if ($_POST['action'] === 'add') {
                            $query = "INSERT INTO sales (product_id, store_id, quantity, total_amount, status, transaction_date) VALUES (?, ?, ?, ?, ?, ?)";
                            $stmt = mysqli_prepare($conn, $query);
                            mysqli_stmt_bind_param($stmt, 'iiidss', $product_id, $store_id, $quantity, $total_amount, $status, $transaction_date);
                            // Update stock
                            $stock_query = "UPDATE products SET stock = stock - ? WHERE id = ?";
                            $stock_stmt = mysqli_prepare($conn, $stock_query);
                            mysqli_stmt_bind_param($stock_stmt, 'ii', $quantity, $product_id);
                            mysqli_stmt_execute($stock_stmt);
                            mysqli_stmt_close($stock_stmt);
                        } else {
                            $query = "UPDATE sales SET product_id = ?, store_id = ?, quantity = ?, total_amount = ?, status = ?, transaction_date = ? WHERE id = ?";
                            $stmt = mysqli_prepare($conn, $query);
                            mysqli_stmt_bind_param($stmt, 'iiidssi', $product_id, $store_id, $quantity, $total_amount, $status, $transaction_date, $sale_id);
                        }
                        if (mysqli_stmt_execute($stmt)) {
                            $success_message = $_POST['action'] === 'add' ? 'Transaction added successfully!' : 'Transaction updated successfully!';
                            error_log($_POST['action'] === 'add' ? "Transaction added: product_id $product_id" : "Transaction updated: ID $sale_id");
                        } else {
                            $error_message = 'Error saving transaction: ' . mysqli_error($conn);
                            error_log("Error saving transaction: " . mysqli_error($conn));
                        }
                        mysqli_stmt_close($stmt);
                    }
                }
            } else {
                $error_message = 'Please fill in all required fields with valid values.';
                error_log("Missing or invalid required fields in transaction form: " . print_r($_POST, true));
            }
        }
    } else {
        $error_message = 'Invalid form action.';
        error_log('No action specified in POST request: ' . print_r($_POST, true));
    }
}

// Fetch transactions
$transactions_query = "SELECT s.id, s.product_id, s.store_id, s.quantity, s.total_amount, s.status, s.transaction_date, p.name as product_name, st.store_name 
                      FROM sales s 
                      LEFT JOIN products p ON s.product_id = p.id 
                      LEFT JOIN stores st ON s.store_id = st.id 
                      ORDER BY s.transaction_date DESC";
$transactions_result = mysqli_query($conn, $transactions_query);
$transactions = [];
if ($transactions_result) {
    while ($row = mysqli_fetch_assoc($transactions_result)) {
        $sale_id = $row['id'] ?? '';
        $encrypted_id = $sale_id ? encryptId($sale_id) : '';
        if ($encrypted_id === false) {
            error_log("Failed to encrypt sale ID: $sale_id");
        }
        $transactions[] = [
            'id' => $sale_id,
            'encrypted_id' => $encrypted_id,
            'product_id' => $row['product_id'] ?? '',
            'product_name' => $row['product_name'] ?? 'N/A',
            'store_id' => $row['store_id'] ?? '',
            'store_name' => $row['store_name'] ?? 'N/A',
            'quantity' => $row['quantity'] ?? 0,
            'total_amount' => $row['total_amount'] ?? 0.00, // Default to 0.00 if NULL
            'status' => $row['status'] ?? 'Pending',
            'transaction_date' => $row['transaction_date'] ?? ''
        ];
    }
} else {
    $error_message = 'Error fetching transactions: ' . mysqli_error($conn);
    error_log("Error fetching transactions: " . mysqli_error($conn));
}

// Fetch stores for dropdown
$stores_query = "SELECT id, store_name FROM stores WHERE status = 'Active' ORDER BY store_name";
$stores_result = mysqli_query($conn, $stores_query);
$stores = $stores_result ? mysqli_fetch_all($stores_result, MYSQLI_ASSOC) : [];

// Fetch products for dropdown
$products_query = "SELECT id, name FROM products WHERE status != 'Out of Stock' ORDER BY name";
$products_result = mysqli_query($conn, $products_query);
$products = $products_result ? mysqli_fetch_all($products_result, MYSQLI_ASSOC) : [];

// Fetch metrics
$total_transactions = count($transactions);
$pending_query = "SELECT COUNT(*) as count FROM sales WHERE status = 'Pending'";
$pending_result = mysqli_query($conn, $pending_query);
$pending_transactions = $pending_result ? mysqli_fetch_assoc($pending_result)['count'] : 0;
$total_revenue_query = "SELECT SUM(total_amount) as total FROM sales WHERE status = 'Completed'";
$total_revenue_result = mysqli_query($conn, $total_revenue_query);
$total_revenue = $total_revenue_result ? (mysqli_fetch_assoc($total_revenue_result)['total'] ?? 0.00) : 0.00; // Handle NULL

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

    <!-- Transactions Table -->
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-semibold">Transaction Management</h2>
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <input type="text" id="search-transactions" placeholder="Search transactions..." class="pl-10 pr-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary" oninput="filterTransactions()">
                    <i data-feather="search" class="absolute left-3 top-1/2 transform -translate-y-1/2 text-neutral"></i>
                </div>
                <select id="store-filter" class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm" onchange="filterTransactions()">
                    <option value="">All Stores</option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="status-filter" class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm" onchange="filterTransactions()">
                    <option value="">All Statuses</option>
                    <option value="Completed">Completed</option>
                    <option value="Pending">Pending</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
                <button onclick="openModal('add')" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                    <i data-feather="plus" class="w-4 h-4 mr-2"></i> Add Transaction
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="p-3 text-left font-medium">ID</th>
                        <th class="p-3 text-left font-medium">Product</th>
                        <th class="p-3 text-left font-medium">Store</th>
                        <th class="p-3 text-left font-medium">Quantity</th>
                        <th class="p-3 text-left font-medium">Total Amount</th>
                        <th class="p-3 text-left font-medium">Status</th>
                        <th class="p-3 text-left font-medium">Date</th>
                        <th class="p-3 text-left font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody id="transaction-table">
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="8" class="p-3 text-center text-gray-500">No transactions found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr class="table-row border-b border-gray-200 dark:border-gray-700" data-status="<?php echo htmlspecialchars($transaction['status'], ENT_QUOTES, 'UTF-8'); ?>" data-store="<?php echo $transaction['store_id']; ?>">
                                <td class="p-3"><?php echo htmlspecialchars($transaction['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($transaction['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($transaction['store_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($transaction['quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8') . number_format($transaction['total_amount'] ?? 0.00, 2); ?></td> <!-- Fix: Handle NULL total_amount -->
                                <td class="p-3">
                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                        echo $transaction['status'] == 'Completed' ? 'bg-green-100 text-green-700' : 
                                            ($transaction['status'] == 'Pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'); ?>">
                                        <?php echo htmlspecialchars($transaction['status'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td class="p-3"><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($transaction['transaction_date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3 flex space-x-2">
                                    <button onclick='openModal("edit", <?php echo json_encode($transaction); ?>)' class="text-primary hover:text-primary/80">
                                        <i data-feather="edit" class="w-5 h-5"></i>
                                    </button>
                                    <button 
                                        onclick='confirmDelete("<?php echo htmlspecialchars($transaction['encrypted_id'], ENT_QUOTES, 'UTF-8'); ?>", "<?php echo htmlspecialchars($transaction['product_name'], ENT_QUOTES, 'UTF-8'); ?>")' 
                                        class="text-red-500 hover:text-red-600" 
                                        <?php echo ($transaction['encrypted_id'] === false || $transaction['encrypted_id'] === '') ? 'disabled title="Encryption failed for this ID"' : ''; ?>
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

    <!-- Transaction Metrics Overview -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <i data-feather="shopping-cart" class="w-8 h-8 text-accent mr-4"></i>
                <div>
                    <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Transactions</h2>
                    <p class="text-xl font-semibold text-primary"><?php echo $total_transactions; ?></p>
                </div>
            </div>
        </div>
        <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <i data-feather="alert-triangle" class="w-8 h-8 text-yellow-500 mr-4"></i>
                <div>
                    <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Pending Transactions</h2>
                    <p class="text-xl font-semibold text-primary"><?php echo $pending_transactions; ?></p>
                </div>
            </div>
        </div>
        <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <i data-feather="dollar-sign" class="w-8 h-8 text-accent mr-4"></i>
                <div>
                    <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Revenue</h2>
                    <p class="text-xl font-semibold text-primary"><?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8') . number_format($total_revenue, 2); ?></p> <!-- Fix: Handle NULL total_revenue -->
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Add/Edit Transaction -->
    <div id="transaction-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden modal">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 w-full max-w-lg modal-content">
            <h2 id="modal-title" class="text-lg font-semibold mb-4"></h2>
            <form id="transaction-form" method="POST" action="transactions.php" class="space-y-4">
                <input type="hidden" name="action" id="form-action">
                <input type="hidden" name="sale_id" id="sale-id">
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Product <span class="text-red-500">*</span></label>
                    <select name="product_id" id="transaction-product-id" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo $product['id']; ?>"><?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Store <span class="text-red-500">*</span></label>
                    <select name="store_id" id="transaction-store-id" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Quantity <span class="text-red-500">*</span></label>
                    <input type="number" name="quantity" id="transaction-quantity" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" min="1" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Total Amount (<?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8'); ?>) <span class="text-red-500">*</span></label>
                    <input type="number" name="total_amount" id="transaction-total-amount" step="0.01" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" min="0" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Status <span class="text-red-500">*</span></label>
                    <select name="status" id="transaction-status" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
                        <option value="Completed">Completed</option>
                        <option value="Pending">Pending</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Transaction Date <span class="text-red-500">*</span></label>
                    <input type="datetime-local" name="transaction_date" id="transaction-date" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" required>
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

    <!-- Delete Confirmation Modal -->
    <div id="delete-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden modal">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 w-full max-w-md">
            <h2 class="text-lg font-semibold mb-4">Confirm Delete</h2>
            <p id="delete-message" class="text-sm text-gray-500 dark:text-gray-400 mb-4"></p>
            <form id="delete-form" method="GET" action="transactions.php">
                <input type="hidden" name="delete" id="delete-transaction-encrypted-id">
                <div class="flex space-x-4">
                    <button type="submit" class="bg-red-500 text-white px-6 py-2 rounded-lg hover:bg-red-600 transition flex items-center">
                        <i data-feather="trash-2" class="w-4 h-4 mr-2"></i> Delete
                    </button>
                    <button type="button" onclick="closeDeleteModal()" class="bg-neutral text-white px-6 py-2 rounded-lg hover:bg-neutral/90 transition flex items-center">
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

    // Modal handling
    function openModal(action, transaction = {}) {
        const modal = document.getElementById('transaction-modal');
        const title = document.getElementById('modal-title');
        document.getElementById('form-action').value = action;
        title.textContent = action === 'add' ? 'Add Transaction' : 'Edit Transaction';
        
        document.getElementById('sale-id').value = transaction.id || '';
        document.getElementById('transaction-product-id').value = transaction.product_id || '';
        document.getElementById('transaction-store-id').value = transaction.store_id || '';
        document.getElementById('transaction-quantity').value = transaction.quantity || '';
        document.getElementById('transaction-total-amount').value = transaction.total_amount || '';
        document.getElementById('transaction-status').value = transaction.status || 'Pending';
        document.getElementById('transaction-date').value = transaction.transaction_date ? transaction.transaction_date.slice(0, 16) : '';
        
        modal.classList.remove('hidden');
        console.log('Opened transaction modal for action:', action, 'Transaction:', transaction);
        feather.replace();
    }

    function closeModal() {
        document.getElementById('transaction-modal').classList.add('hidden');
        document.getElementById('transaction-form').reset();
        console.log('Closed transaction modal');
    }

    function confirmDelete(encryptedId, productName) {
        const modal = document.getElementById('delete-modal');
        document.getElementById('delete-transaction-encrypted-id').value = encryptedId;
        document.getElementById('delete-message').textContent = `Are you sure you want to delete the transaction for "${productName}"? This action cannot be undone.`;
        
        if (!encryptedId) {
            alert('Error: Cannot delete. The transaction ID could not be encrypted.');
            return;
        }

        modal.classList.remove('hidden');
        console.log('Opening delete modal for encrypted_id:', encryptedId, 'Product Name:', productName);
        feather.replace();
    }

    function closeDeleteModal() {
        document.getElementById('delete-modal').classList.add('hidden');
        document.getElementById('delete-transaction-encrypted-id').value = '';
        console.log('Closed delete modal');
    }

    // Transaction filtering
    function filterTransactions() {
        const search = document.getElementById('search-transactions').value.toLowerCase();
        const store = document.getElementById('store-filter').value;
        const status = document.getElementById('status-filter').value;
        const rows = document.querySelectorAll('#transaction-table tr');
        
        rows.forEach(row => {
            const product = row.children[1]?.textContent.toLowerCase() || '';
            const storeName = row.children[2]?.textContent.toLowerCase() || '';
            const rowStore = row.getAttribute('data-store') || '';
            const rowStatus = row.getAttribute('data-status') || '';
            const matchesSearch = product.includes(search) || storeName.includes(search);
            const matchesStore = !store || rowStore === store;
            const matchesStatus = !status || rowStatus === status;
            row.style.display = matchesSearch && matchesStore && matchesStatus ? '' : 'none';
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php mysqli_close($conn); ?>