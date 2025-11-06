<?php
// sales.php
require_once __DIR__ . '/config.php';

$page_title = 'Sales';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Initialize user array
$user = [
    'name' => $_SESSION['username'] ?? 'John Doe',
    'role' => $_SESSION['role'] ?? 'Mall Admin',
    'store' => 'Unknown Store'
];

// Fetch store name
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

// Load settings
$settings_query = "SELECT * FROM settings WHERE id = 1";
$settings_result = mysqli_query($conn, $settings_query);
$settings = mysqli_fetch_assoc($settings_result) ?: [
    'currency_symbol' => 'GHS',
    'store_name' => 'Mall Supermarket POS',
    'address' => '123 Market St, Cityville',
    'contact' => '(123) 456-7890',
    'receipt_header' => 'Mall Supermarket POS',
    'receipt_footer' => 'Thank you for shopping at our mall!',
    'receipt_width' => 80,
    'auto_print' => 0,
    'payment_summary_alignment' => 'center'
];

// Calculate cart total safely
$cart_total = 0;
foreach ($_SESSION['cart'] ?? [] as $item) {
    $cart_total += floatval($item['subtotal'] ?? 0);
}

// Handle non-AJAX form submissions
$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'remove_from_cart') {
        $index = filter_input(INPUT_POST, 'index', FILTER_VALIDATE_INT);
        if ($index !== false && isset($_SESSION['cart'][$index])) {
            unset($_SESSION['cart'][$index]);
            $_SESSION['cart'] = array_values($_SESSION['cart']);
            $success_message = 'Item removed from cart!';
        } else {
            $error_message = 'Invalid cart item.';
        }
    } elseif ($_POST['action'] === 'delete') {
        $transaction_id = filter_input(INPUT_POST, 'transaction_id', FILTER_VALIDATE_INT);
        // New: Reserve delete for Admins only
        if ($_SESSION['role'] !== 'Admin') {  // Adjust 'Admin' if your role name differs
            $error_message = 'Only Admins can delete transactions.';
        } elseif ($transaction_id) {
            $query = "DELETE FROM transactions WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, 'i', $transaction_id);
            if (mysqli_stmt_execute($stmt)) {
                $success_message = 'Sale deleted successfully!';
            } else {
                $error_message = 'Error deleting sale: ' . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        } else {
            $error_message = 'Invalid transaction ID.';
        }
    }
}

// Fetch products
$products_query = "SELECT id, name, price, stock, store_id, barcode FROM products WHERE status != 'Out of Stock'";
if (isset($_SESSION['store_id'])) {
    $products_query .= " AND store_id = {$_SESSION['store_id']}";
}
$products_result = mysqli_query($conn, $products_query);
$products = $products_result ? mysqli_fetch_all($products_result, MYSQLI_ASSOC) : [];

// Fetch metrics
$total_sales_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE DATE(date) = CURDATE()";
if (isset($_SESSION['store_id'])) {
    $total_sales_query .= " AND store_id = {$_SESSION['store_id']}";
}
$total_sales_result = mysqli_query($conn, $total_sales_query);
$total_sales = $total_sales_result ? mysqli_fetch_assoc($total_sales_result)['total'] ?? 0 : 0;

$transactions_today_query = "SELECT COALESCE(COUNT(*), 0) as count FROM transactions WHERE DATE(date) = CURDATE()";
if (isset($_SESSION['store_id'])) {
    $transactions_today_query .= " AND store_id = {$_SESSION['store_id']}";
}
$transactions_today_result = mysqli_query($conn, $transactions_today_query);
$transactions_today = $transactions_today_result ? mysqli_fetch_assoc($transactions_today_result)['count'] : 0;

$pending_transactions_query = "SELECT COALESCE(COUNT(*), 0) as count FROM transactions WHERE status = 'Pending'";
if (isset($_SESSION['store_id'])) {
    $pending_transactions_query .= " AND store_id = {$_SESSION['store_id']}";
}
$pending_transactions_result = mysqli_query($conn, $pending_transactions_query);
$pending_transactions = $pending_transactions_result ? mysqli_fetch_assoc($pending_transactions_result)['count'] : 0;

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($success_message): ?>
    <div id="success-message" class="mb-6 p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
        <i data-feather="check-circle" class="w-5 h-5 mr-2"></i>
        <?php echo htmlspecialchars($success_message); ?>
    </div>
<?php elseif ($error_message): ?>
    <div id="error-message" class="mb-6 p-4 bg-red-100 text-red-700 rounded-lg flex items-center">
        <i data-feather="alert-circle" class="w-5 h-5 mr-2"></i>
        <?php echo htmlspecialchars($error_message); ?>
    </div>
<?php endif; ?>

<!-- Add to Cart Form -->
<div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 mb-6">
    <h2 class="text-lg font-semibold mb-4">Add to Cart</h2>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4" id="cart-form">
        <div>
            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Store <span class="text-red-500">*</span></label>
            <select name="store_id" id="cart-store-id" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed" disabled required>
                <option value="<?php echo isset($_SESSION['store_id']) ? $_SESSION['store_id'] : ''; ?>">
                    <?php echo htmlspecialchars($user['store']); ?>
                </option>
            </select>
        </div>
        <div class="relative">
            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Product <span class="text-red-500">*</span></label>
            <input type="text" id="product-search" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Search product..." oninput="filterProducts()" autocomplete="off">
            <input type="hidden" name="product_id" id="cart-product-id">
            <div id="product-search-results" class="absolute z-10 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg mt-1 hidden"></div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Barcode</label>
            <input type="text" name="barcode" id="barcode" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Scan barcode...">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Quantity <span class="text-red-500">*</span></label>
            <input type="number" name="quantity" id="cart-quantity" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" min="1" required>
        </div>
        <div class="md:col-span-4">
            <button onclick="addToCart()" class="bg-primary text-white px-6 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                <i data-feather="plus" class="w-4 h-4 mr-2"></i> Add to Cart
            </button>
        </div>
    </div>
</div>

<!-- Cart Section -->
<div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 mb-6">
    <h2 class="text-lg font-semibold mb-4">Cart</h2>
    <div class="max-h-64 overflow-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="p-3 text-left font-medium">Product</th>
                    <th class="p-3 text-left font-medium">Store</th>
                    <th class="p-3 text-left font-medium">Quantity</th>
                    <th class="p-3 text-left font-medium">Available Stock</th>
                    <th class="p-3 text-left font-medium">Price</th>
                    <th class="p-3 text-left font-medium">Subtotal</th>
                    <th class="p-3 text-left font-medium">Actions</th>
                </tr>
            </thead>
            <tbody id="cart-table">
                <?php foreach ($_SESSION['cart'] ?? [] as $index => $item): ?>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <td class="p-3"><?php echo htmlspecialchars($item['name']); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($user['store']); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($item['quantity']); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($item['stock'] - $item['quantity']); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($settings['currency_symbol']) . number_format($item['price'] ?? 0, 2); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($settings['currency_symbol']) . number_format($item['subtotal'] ?? 0, 2); ?></td>
                        <td class="p-3">
                            <form method="POST" action="sales.php">
                                <input type="hidden" name="action" value="remove_from_cart">
                                <input type="hidden" name="index" value="<?php echo $index; ?>">
                                <button type="submit" class="text-red-500 hover:text-red-600">
                                    <i data-feather="trash-2" class="w-5 h-5"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-4">
        <p class="text-lg font-semibold">Total: <?php echo htmlspecialchars($settings['currency_symbol']); ?><span id="cart-total"><?php echo number_format($cart_total, 2); ?></span></p>
    </div>
    <?php if (!empty($_SESSION['cart'])): ?>
        <div class="mt-4">
            <button onclick="openCheckoutModal()" class="bg-primary text-white px-6 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                <i data-feather="check" class="w-4 h-4 mr-2"></i> Proceed to Checkout
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Sales Table -->
<div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-semibold">Sales History</h2>
        <div class="flex items-center space-x-4">
            <select id="status-filter" class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm" onchange="fetchSales(1)">
                <option value="">All Statuses</option>
                <option value="Completed">Completed</option>
                <option value="Pending">Pending</option>
            </select>
            <select id="rows-per-page" class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm" onchange="fetchSales(1)">
                <option value="10">10 per page</option>
                <option value="25">25 per page</option>
                <option value="50">50 per page</option>
            </select>
        </div>
    </div>
    <div class="max-h-96 overflow-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="p-3 text-left font-medium">ID</th>
                    <th class="p-3 text-left font-medium">Store</th>
                    <th class="p-3 text-left font-medium">Product</th>
                    <th class="p-3 text-left font-medium">Customer</th>
                    <th class="p-3 text-left font-medium">Quantity</th>
                    <th class="p-3 text-left font-medium">Amount</th>
                    <th class="p-3 text-left font-medium">Amount Paid</th>
                    <th class="p-3 text-left font-medium">Change Given</th>
                    <th class="p-3 text-left font-medium">Date</th>
                    <th class="p-3 text-left font-medium">Payment</th>
                    <th class="p-3 text-left font-medium">Status</th>
                    <th class="p-3 text-left font-medium">Actions</th>
                </tr>
            </thead>
            <tbody id="sales-table"></tbody>
        </table>
    </div>
    <div class="mt-4 flex justify-between items-center">
        <button id="prev-page" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition disabled:opacity-50" onclick="fetchSales(currentPage - 1)" disabled>Previous</button>
        <div id="page-numbers" class="flex space-x-2"></div>
        <button id="next-page" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition disabled:opacity-50" onclick="fetchSales(currentPage + 1)" disabled>Next</button>
    </div>
</div>

<!-- Sales Metrics Overview -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="flex items-center">
            <i data-feather="dollar-sign" class="w-8 h-8 text-accent mr-4"></i>
            <div>
                <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Sales Today</h2>
                <p class="text-xl font-semibold text-primary"><?php echo htmlspecialchars($settings['currency_symbol']) . number_format($total_sales ?? 0, 2); ?></p>
            </div>
        </div>
    </div>
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="flex items-center">
            <i data-feather="bar-chart-2" class="w-8 h-8 text-accent mr-4"></i>
            <div>
                <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Transactions Today</h2>
                <p class="text-xl font-semibold text-primary"><?php echo $transactions_today; ?></p>
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
</div>

<!-- Checkout Modal -->
<div id="checkout-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white dark:bg-gray-800 p-6 rounded-lg max-w-lg w-full overflow-hidden">
        <h2 class="text-lg font-semibold mb-4">Checkout</h2>
        <div id="checkout-form">
            <div id="checkout-message" class="hidden mb-4"></div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Customer Name</label>
                <input type="text" id="customer-name" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Payment Method <span class="text-red-500">*</span></label>
                <select id="payment-method" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
                    <option value="Cash" selected>Cash</option>
                    <option value="Credit Card">Credit Card</option>
                    <option value="Mobile Payment">Mobile Payment</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Date <span class="text-red-500">*</span></label>
                <input type="date" id="checkout-date" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Total</label>
                <input type="hidden" id="checkout-total-value" value="<?php echo $cart_total; ?>">
                <input type="text" id="checkout-total-display" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400" value="<?php echo htmlspecialchars($settings['currency_symbol']) . number_format($cart_total, 2); ?>" readonly>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Amount Paid <span class="text-red-500">*</span></label>
                <input type="number" id="amount-paid" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" min="0" step="0.01" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Change Given</label>
                <input type="text" id="change-given" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400" readonly>
            </div>
            <div class="flex justify-end space-x-2">
                <button onclick="closeCheckoutModal()" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600 transition">Cancel</button>
                <button onclick="processCheckout()" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                    <i data-feather="check" class="w-4 h-4 mr-2"></i> Complete Checkout
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div id="receipt-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white p-6 rounded-lg max-w-lg w-full overflow-hidden" id="receipt-content">
        <h2 class="text-lg font-semibold mb-4">Receipt</h2>
        <div id="receipt-body"></div>
        <div class="flex justify-end mt-4">
            <button onclick="printReceiptContent()" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                <i data-feather="printer" class="w-4 h-4 mr-2"></i> Print
            </button>
            <button onclick="closeReceiptModal()" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600 transition ml-2">Close</button>
        </div>
    </div>
</div>

<script>
// Load tax rates
const taxRates = <?php
    $tax_query = "SELECT name, rate FROM tax_rates";
    $tax_result = mysqli_query($conn, $tax_query);
    $taxes = mysqli_fetch_all($tax_result, MYSQLI_ASSOC);
    echo json_encode($taxes);
?>;

// Load settings
const settings = <?php echo json_encode($settings); ?>;

// New: Echo user role for JS
const userRole = '<?php echo $_SESSION['role'] ?? ''; ?>';

// Product search
let products = <?php echo json_encode($products); ?>;
let currencySymbol = '<?php echo htmlspecialchars($settings['currency_symbol']); ?>';
function filterProducts() {
    const search = document.getElementById('product-search').value.toLowerCase();
    const results = document.getElementById('product-search-results');
    results.innerHTML = '';
    const filteredProducts = products.filter(product => 
        (product.name.toLowerCase().includes(search) || product.barcode?.toLowerCase().includes(search))
    );
    if (filteredProducts.length > 0 && search) {
        filteredProducts.forEach(product => {
            const div = document.createElement('div');
            div.className = 'p-2 hover:bg-gray-100 dark:hover:bg-gray-600 cursor-pointer';
            div.textContent = `${product.name} (Stock: ${product.stock})`;
            div.dataset.id = product.id;
            div.dataset.stock = product.stock;
            div.onclick = () => selectProduct(product.id, product.name, product.stock);
            results.appendChild(div);
        });
        results.classList.remove('hidden');
    } else {
        results.classList.add('hidden');
    }
}

function selectProduct(id, name, stock) {
    document.getElementById('cart-product-id').value = id;
    document.getElementById('product-search').value = name;
    document.getElementById('cart-quantity').max = stock;
    document.getElementById('cart-quantity').value = Math.min(document.getElementById('cart-quantity').value || 1, stock);
    document.getElementById('product-search-results').classList.add('hidden');
}

// Add to cart
async function addToCart() {
    const storeId = document.getElementById('cart-store-id').value;
    const productId = document.getElementById('cart-product-id').value;
    const quantity = document.getElementById('cart-quantity').value;
    const barcode = document.getElementById('barcode').value;

    if (!storeId || (!productId && !barcode) || !quantity || quantity <= 0) {
        showMessage('Please fill in all required fields with valid data.', false);
        return;
    }

    const button = document.querySelector('#cart-form button');
    button.disabled = true;
    button.innerHTML = '<i data-feather="loader" class="w-4 h-4 mr-2 animate-spin"></i> Adding...';
    safeFeatherReplace();

    try {
        const response = await fetch('add_to_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `product_id=${productId}&quantity=${quantity}&store_id=${storeId}&barcode=${encodeURIComponent(barcode)}`
        });

        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }

        const data = await response.json();

        if (data.success) {
            updateCartTable(data.cart, data.cart_total);
            if (data.updated_stock) {
                products = products.map(p => p.id == data.updated_stock.product_id ? { ...p, stock: data.updated_stock.stock } : p);
            }
            showMessage(data.message, true);
            document.getElementById('cart-product-id').value = '';
            document.getElementById('product-search').value = '';
            document.getElementById('cart-quantity').value = '';
            document.getElementById('barcode').value = '';
            document.getElementById('product-search-results').classList.add('hidden');
        } else {
            showMessage(data.message || 'Failed to add to cart.', false);
        }
    } catch (error) {
        console.error('Add to cart error:', error);
        showMessage('Error adding to cart: ' + error.message, false);
    } finally {
        button.disabled = false;
        button.innerHTML = '<i data-feather="plus" class="w-4 h-4 mr-2"></i> Add to Cart';
        safeFeatherReplace();
    }
}

// Update cart table
function updateCartTable(cart, total) {
    const cartTable = document.getElementById('cart-table');
    const cartTotal = document.getElementById('cart-total');
    const checkoutButton = document.querySelector('button[onclick="openCheckoutModal()"]');
    const storeName = '<?php echo htmlspecialchars($user['store']); ?>';

    cartTable.innerHTML = '';
    let calculatedTotal = 0;

    cart.forEach((item, index) => {
        const itemSubtotal = Number(item.subtotal) || 0;
        calculatedTotal += itemSubtotal;

        const row = `
            <tr class="border-b border-gray-200 dark:border-gray-700">
                <td class="p-3">${item.name}</td>
                <td class="p-3">${storeName}</td>
                <td class="p-3">${item.quantity}</td>
                <td class="p-3">${item.stock - item.quantity}</td>
                <td class="p-3">${currencySymbol}${Number(item.price || 0).toFixed(2)}</td>
                <td class="p-3">${currencySymbol}${itemSubtotal.toFixed(2)}</td>
                <td class="p-3">
                    <form method="POST" action="sales.php">
                        <input type="hidden" name="action" value="remove_from_cart">
                        <input type="hidden" name="index" value="${index}">
                        <button type="submit" class="text-red-500 hover:text-red-600">
                            <i data-feather="trash-2" class="w-5 h-5"></i>
                        </button>
                    </form>
                </td>
            </tr>`;
        cartTable.innerHTML += row;
    });

    const displayTotal = isNaN(Number(total)) ? calculatedTotal : Number(total);
    cartTotal.textContent = displayTotal.toFixed(2);

    document.getElementById('checkout-total-value').value = displayTotal;

    const modalDisplay = document.getElementById('checkout-total-display');
    if (modalDisplay) {
        modalDisplay.value = currencySymbol + displayTotal.toFixed(2);
    }

    if (cart.length > 0) {
        if (!checkoutButton) {
            const checkoutDiv = document.createElement('div');
            checkoutDiv.className = 'mt-4';
            checkoutDiv.innerHTML = `
                <button onclick="openCheckoutModal()" class="bg-primary text-white px-6 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                    <i data-feather="check" class="w-4 h-4 mr-2"></i> Proceed to Checkout
                </button>`;
            cartTable.parentElement.parentElement.appendChild(checkoutDiv);
        }
    } else if (checkoutButton) {
        checkoutButton.parentElement.remove();
    }
    safeFeatherReplace();
}

// Show messages
function showMessage(message, isSuccess) {
    const messageDiv = document.createElement('div');
    messageDiv.id = isSuccess ? 'success-message' : 'error-message';
    messageDiv.className = `p-4 ${isSuccess ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'} rounded-lg flex items-center`;
    messageDiv.innerHTML = `<i data-feather="${isSuccess ? 'check-circle' : 'alert-circle'}" class="w-5 h-5 mr-2"></i> ${message}`;

    const checkoutModal = document.getElementById('checkout-modal');
    const isModalOpen = !checkoutModal.classList.contains('hidden');
    const targetContainer = isModalOpen ? document.getElementById('checkout-message') : document.querySelector('main');

    // Clear existing messages
    const existingMessage = targetContainer.querySelector('#success-message, #error-message');
    if (existingMessage) existingMessage.remove();

    // Show message in modal or main page
    if (isModalOpen) {
        targetContainer.classList.remove('hidden');
        targetContainer.appendChild(messageDiv);
    } else {
        targetContainer.insertBefore(messageDiv, document.querySelector('.card'));
    }

    setTimeout(() => messageDiv.remove(), 3000);
    safeFeatherReplace();
}

// Barcode input
document.getElementById('barcode').addEventListener('change', async function() {
    const barcode = this.value;
    if (barcode) {
        document.getElementById('cart-quantity').value = 1;
        await addToCart();
    }
});

// Checkout modal
function openCheckoutModal() {
    const numericTotal = parseFloat(document.getElementById('checkout-total-value').value) || 0;
    document.getElementById('checkout-total-display').value = currencySymbol + numericTotal.toFixed(2);
    document.getElementById('change-given').value = currencySymbol + '0.00';
    document.getElementById('checkout-modal').classList.remove('hidden');
}

function closeCheckoutModal() {
    document.getElementById('checkout-modal').classList.add('hidden');
    const checkoutMessage = document.getElementById('checkout-message');
    checkoutMessage.innerHTML = '';
    checkoutMessage.classList.add('hidden');
}

// Calculate change
document.getElementById('amount-paid').addEventListener('input', function() {
    const paid = parseFloat(this.value) || 0;
    const total = parseFloat(document.getElementById('checkout-total-value').value) || 0;
    const change = (paid >= total) ? (paid - total) : 0;
    document.getElementById('change-given').value = currencySymbol + change.toFixed(2);
});

// Process checkout
async function processCheckout() {
    const customerName = document.getElementById('customer-name').value;
    const paymentMethod = document.getElementById('payment-method').value;
    const date = document.getElementById('checkout-date').value;
    const amountPaid = parseFloat(document.getElementById('amount-paid').value) || 0;
    const changeGivenStr = document.getElementById('change-given').value.replace(currencySymbol, '');
    const changeGiven = parseFloat(changeGivenStr) || 0;
    const total = parseFloat(document.getElementById('checkout-total-value').value) || 0;

    // Validate inputs
    if (!paymentMethod) {
        showMessage('Please select a payment method.', false);
        return;
    }
    if (!date) {
        showMessage('Please select a valid date.', false);
        return;
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
        showMessage('Date must be in YYYY-MM-DD format.', false);
        return;
    }
    if (amountPaid < total) {
        showMessage('Amount paid must be at least the total.', false);
        return;
    }

    const button = document.querySelector('#checkout-form button[onclick="processCheckout()"]');
    button.disabled = true;
    button.innerHTML = '<i data-feather="loader" class="w-4 h-4 mr-2 animate-spin"></i> Processing...';
    safeFeatherReplace();

    try {
        // Fetch the latest cart from the server
        const cartResponse = await fetch('get_cart.php', {
            method: 'GET',
            headers: { 'Content-Type': 'application/json' }
        });

        if (!cartResponse.ok) {
            throw new Error(`HTTP error! Status: ${cartResponse.status}`);
        }

        const cartData = await cartResponse.json();
        if (!cartData.success) {
            throw new Error(cartData.message || 'Failed to fetch cart.');
        }

        const cart = cartData.cart;
        if (!cart.length) {
            showMessage('Cart is empty. Add items before checking out.', false);
            return;
        }

        // Validate cart totals
        let cartTotal = 0;
        for (const item of cart) {
            const subtotal = Number(item.subtotal) || 0;
            if (isNaN(subtotal)) {
                showMessage('Invalid cart data. Please clear cart and try again.', false);
                return;
            }
            cartTotal += subtotal;
        }

        if (Math.abs(cartTotal - total) > 0.01) {
            throw new Error('Cart total mismatch.');
        }

        // Proceed with checkout
        const checkoutResponse = await fetch('checkout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=checkout&customer_name=${encodeURIComponent(customerName.trim())}&payment_method=${encodeURIComponent(paymentMethod)}&date=${encodeURIComponent(date)}&amount_paid=${encodeURIComponent(amountPaid)}&change_given=${encodeURIComponent(changeGiven)}`
        });

        if (!checkoutResponse.ok) {
            throw new Error(`HTTP error! Status: ${checkoutResponse.status}`);
        }

        const data = await checkoutResponse.json();

        if (data.success) {
            updateCartTable([], '0.00');
            products = data.updated_products || products;
            showMessage(data.message, true);
            closeCheckoutModal();
            printReceiptAfterCheckout(data.transactions);
            fetchSales(1);
        } else {
            showMessage(data.message || 'Checkout failed. Please try again.', false);
        }
    } catch (error) {
        console.error('Checkout error:', error);
        showMessage('Error processing checkout: ' + error.message, false);
    } finally {
        button.disabled = false;
        button.innerHTML = '<i data-feather="check" class="w-4 h-4 mr-2"></i> Complete Checkout';
        safeFeatherReplace();
    }
}

// Calculate taxes
function calculateTaxes(price, quantity) {
    let totalTax = 0;
    const taxDetails = [];
    taxRates.forEach(tax => {
        const taxAmount = (price * quantity * tax.rate) / 100;
        totalTax += taxAmount;
        taxDetails.push({
            name: tax.name,
            rate: tax.rate,
            amount: taxAmount
        });
    });
    return { totalTax, taxDetails };
}

// REUSABLE PRINT FUNCTION
function printReceiptInNewWindow(content) {
    const printWindow = window.open('', '_blank', 'width=800,height=600');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Receipt</title>
            <meta charset="UTF-8">
            <style>
                @page {
                    size: ${settings.receipt_width}mm auto;
                    margin: 5mm;
                }
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                body {
                    font-family: 'Courier New', monospace !important;
                    font-size: 12px;
                    line-height: 1.4;
                    color: #000;
                    padding: 5mm;
                    width: ${settings.receipt_width}mm;
                    margin: 0 auto;
                }
                .receipt-print {
                    width: 100%;
                }
                h2 {
                    font-size: 16px;
                    text-align: center;
                    margin: 0 0 5px 0;
                }
                p {
                    margin: 5px 0;
                    font-size: 11px;
                }
                hr {
                    border: 0;
                    border-top: 1px dashed #000;
                    margin: 10px 0;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 11px;
                    margin: 8px 0;
                }
                th, td {
                    padding: 3px 0;
                    text-align: left;
                }
                th:nth-child(2), td:nth-child(2) { text-align: center; }
                th:nth-child(3), td:nth-child(3),
                th:nth-child(4), td:nth-child(4) { text-align: right; }
                tbody tr { border-bottom: 1px dashed #ccc; }
                .text-right { text-align: right; }
                .text-center { text-align: center; }
                strong { font-weight: bold; }
                @media print {
                    body { -webkit-print-color-adjust: exact; }
                }
            </style>
        </head>
        <body onload="window.print(); window.close();">
            ${content}
        </body>
        </html>
    `);
    printWindow.document.close();
}

// Print single receipt
function printReceipt(sale) {
    const { totalTax, taxDetails } = calculateTaxes(sale.amount / sale.quantity, sale.quantity);
    const subtotal = Number(sale.amount) - totalTax;

    const receiptHTML = `
        <div class="receipt-print">
            <div style="text-align: center;">
                <h2>${settings.receipt_header}</h2>
                <p><strong>${settings.store_name}</strong></p>
                <p>${settings.address}</p>
                <p>${settings.contact}</p>
            </div>
            <hr>
            <p><strong>Transaction ID:</strong> ${sale.id}</p>
            <p><strong>Customer:</strong> ${sale.customer_name || 'Walk-in'}</p>
            <p><strong>Date:</strong> ${sale.date}</p>
            <p><strong>Payment:</strong> ${sale.payment_method || 'N/A'}</p>
            <hr>
            <table>
                <thead>
                    <tr style="border-bottom: 1px dashed #000;">
                        <th>Item</th>
                        <th style="text-align: center;">Qty</th>
                        <th style="text-align: right;">Price</th>
                        <th style="text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>${sale.product_name}</td>
                        <td style="text-align: center;">${sale.quantity}</td>
                        <td style="text-align: right;">${currencySymbol}${(sale.amount / sale.quantity).toFixed(2)}</td>
                        <td style="text-align: right;">${currencySymbol}${subtotal.toFixed(2)}</td>
                    </tr>
                </tbody>
            </table>
            <hr>
            <p style="text-align: right;"><strong>Subtotal:</strong> ${currencySymbol}${subtotal.toFixed(2)}</p>
            ${taxDetails.map(t => `<p style="text-align: right;"><strong>${t.name} (${t.rate}%):</strong> ${currencySymbol}${t.amount.toFixed(2)}</p>`).join('')}
            <p style="text-align: right; font-size: 13px;"><strong>TOTAL:</strong> ${currencySymbol}${Number(sale.amount).toFixed(2)}</p>
            <p style="text-align: right;"><strong>Paid:</strong> ${currencySymbol}${Number(sale.amount_paid || 0).toFixed(2)}</p>
            <p style="text-align: right;"><strong>Change:</strong> ${currencySymbol}${Number(sale.change_given || 0).toFixed(2)}</p>
            <hr>
            <p style="text-align: center; font-size: 11px;">${settings.receipt_footer}</p>
        </div>
    `;

    printReceiptInNewWindow(receiptHTML);
}

// Print after checkout
function printReceiptAfterCheckout(transactions) {
    const receiptBody = document.getElementById('receipt-body');
    let totalAmount = 0;
    let totalTax = 0;
    const taxDetailsMap = {};

    const itemsHTML = transactions.map(sale => {
        const { totalTax: itemTax, taxDetails } = calculateTaxes(sale.amount / sale.quantity, sale.quantity);
        const subtotal = Number(sale.amount) - itemTax;
        totalAmount += Number(sale.amount);
        totalTax += itemTax;

        taxDetails.forEach(tax => {
            if (!taxDetailsMap[tax.name]) taxDetailsMap[tax.name] = { rate: tax.rate, amount: 0 };
            taxDetailsMap[tax.name].amount += tax.amount;
        });

        return `
            <tr>
                <td>${sale.product_name}</td>
                <td style="text-align: center;">${sale.quantity}</td>
                <td style="text-align: right;">${currencySymbol}${(sale.amount / sale.quantity).toFixed(2)}</td>
                <td style="text-align: right;">${currencySymbol}${subtotal.toFixed(2)}</td>
            </tr>`;
    }).join('');

    const taxSummaryHTML = Object.keys(taxDetailsMap).map(name => 
        `<p style="text-align: right;"><strong>${name} (${taxDetailsMap[name].rate}%):</strong> ${currencySymbol}${taxDetailsMap[name].amount.toFixed(2)}</p>`
    ).join('');

    const receiptHTML = `
        <div class="receipt-print">
            <div style="text-align: center;">
                <h2>${settings.receipt_header}</h2>
                <p><strong>${settings.store_name}</strong></p>
                <p>${settings.address}</p>
                <p>${settings.contact}</p>
            </div>
            <hr>
            <p><strong>Customer:</strong> ${transactions[0].customer_name || 'Walk-in'}</p>
            <p><strong>Date:</strong> ${transactions[0].date}</p>
            <p><strong>Payment:</strong> ${transactions[0].payment_method}</p>
            <hr>
            <table>
                <thead>
                    <tr style="border-bottom: 1px dashed #000;">
                        <th>Item</th>
                        <th style="text-align: center;">Qty</th>
                        <th style="text-align: right;">Price</th>
                        <th style="text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>${itemsHTML}</tbody>
            </table>
            <hr>
            <p style="text-align: right;"><strong>Subtotal:</strong> ${currencySymbol}${(totalAmount - totalTax).toFixed(2)}</p>
            ${taxSummaryHTML}
            <p style="text-align: right; font-size: 13px;"><strong>TOTAL:</strong> ${currencySymbol}${totalAmount.toFixed(2)}</p>
            <p style="text-align: right;"><strong>Paid:</strong> ${currencySymbol}${Number(transactions[0].amount_paid).toFixed(2)}</p>
            <p style="text-align: right;"><strong>Change:</strong> ${currencySymbol}${Number(transactions[0].change_given).toFixed(2)}</p>
            <hr>
            <p style="text-align: center; font-size: 11px;">${settings.receipt_footer}</p>
        </div>
    `;

    receiptBody.innerHTML = receiptHTML;

    if (settings.auto_print == 1) {
        printReceiptInNewWindow(receiptHTML);
    } else {
        openReceiptModal();
    }
}

// Print from modal
function printReceiptContent() {
    const content = document.getElementById('receipt-content').innerHTML;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Receipt</title>
            <style>
                @page { size: ${settings.receipt_width}mm auto; margin: 5mm; }
                * { margin:0; padding:0; box-sizing:border-box; }
                body { font-family:'Courier New',monospace; font-size:12px; line-height:1.4; padding:5mm; width:${settings.receipt_width}mm; margin:0 auto; }
                h2 { font-size:16px; text-align:center; margin:0 0 5px; }
                p { margin:5px 0; font-size:11px; }
                hr { border:0; border-top:1px dashed #000; margin:10px 0; }
                table { width:100%; border-collapse:collapse; font-size:11px; margin:8px 0; }
                th,td { padding:3px 0; text-align:left; }
                th:nth-child(2),td:nth-child(2) { text-align:center; }
                th:nth-child(3),td:nth-child(3), th:nth-child(4),td:nth-child(4) { text-align:right; }
                strong { font-weight:bold; }
            </style>
        </head>
        <body onload="window.print(); window.close();">${content}</body>
        </html>
    `);
    printWindow.document.close();
}

// Fetch sales
// Fetch sales
let currentPage = 1;
async function fetchSales(page) {
    const rowsPerPage = document.getElementById('rows-per-page').value;
    const statusFilter = document.getElementById('status-filter').value;
    const storeId = '<?php echo isset($_SESSION['store_id']) ? $_SESSION['store_id'] : ""; ?>';
    const filterDate = 'today';

    const salesTable = document.getElementById('sales-table');
    salesTable.innerHTML = '<tr><td colspan="12" class="p-3 text-center">Loading...</td></tr>';

    try {
        const response = await fetch(`fetch_sales.php?page=${page}&rows_per_page=${rowsPerPage}${statusFilter ? `&status=${statusFilter}` : ''}${storeId ? `&store_id=${storeId}` : ''}&filter_date=${filterDate}`);
        if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
        const data = await response.json();

        if (data.success) {
            currentPage = data.current_page;
            salesTable.innerHTML = '';
            data.sales.forEach(sale => {
                const relativeTime = formatRelativeTime(sale.date);
                const absoluteTime = sale.formatted_date;

                const row = `
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <td class="p-3">${sale.id}</td>
                        <td class="p-3">${sale.store_name}</td>
                        <td class="p-3">${sale.product_name}</td>
                        <td class="p-3">${sale.customer_name}</td>
                        <td class="p-3">${sale.quantity}</td>
                        <td class="p-3">${currencySymbol}${Number(sale.amount).toFixed(2)}</td>
                        <td class="p-3">${currencySymbol}${Number(sale.amount_paid || 0).toFixed(2)}</td>
                        <td class="p-3">${currencySymbol}${Number(sale.change_given || 0).toFixed(2)}</td>
                        <td class="p-3" title="${absoluteTime}">${relativeTime}</td>
                        <td class="p-3">${sale.payment_method || 'N/A'}</td>
                        <td class="p-3">
                            <span class="px-2 py-1 text-xs font-medium rounded-full ${sale.status == 'Completed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}">
                                ${sale.status}
                            </span>
                        </td>
                        <td class="p-3 flex space-x-2">
                            <button onclick='printReceipt(${JSON.stringify(sale)})' class="text-accent hover:text-accent/80">
                                <i data-feather="printer" class="w-5 h-5"></i>
                            </button>
                            ${userRole === 'Admin' ? `<button onclick='confirmDelete(${sale.id}, "${sale.customer_name}")' class="text-red-500 hover:text-red-600">
                                <i data-feather="trash-2" class="w-5 h-5"></i>
                            </button>` : ''}
                        </td>
                    </tr>`;
                salesTable.innerHTML += row;
            });

            updatePagination(data.total_pages, data.current_page);
            safeFeatherReplace();
        } else {
            showMessage(data.message || 'Failed to fetch sales.', false);
        }
    } catch (error) {
        console.error('Fetch sales error:', error);
        showMessage('Error fetching sales: ' + error.message, false);
    }
}

// Relative time
function formatRelativeTime(dateStr) {
    const now = new Date();
    const past = new Date(dateStr);
    const diffMs = now - past;
    const diffSec = Math.floor(diffMs / 1000);
    const diffMin = Math.floor(diffSec / 60);
    const diffHour = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHour / 24);
    const diffWeek = Math.floor(diffDay / 7);

    if (diffSec < 60) return diffSec <= 0 ? 'Just now' : `${diffSec} seconds ago`;
    if (diffMin < 60) return `${diffMin} minute${diffMin > 1 ? 's' : ''} ago`;
    if (diffHour < 24) {
        const hours = diffHour;
        const minutes = diffMin % 60;
        return `${hours} hour${hours > 1 ? 's' : ''}${minutes > 0 ? ` ${minutes} minute${minutes > 1 ? 's' : ''}` : ''} ago`;
    }
    if (diffDay < 7) return `${diffDay} day${diffDay > 1 ? 's' : ''} ago`;
    return `${diffWeek} week${diffWeek > 1 ? 's' : ''} ago`;
}

// Auto-refresh relative times
setInterval(() => fetchSales(currentPage), 60000);

function updatePagination(totalPages, currentPage) {
    const pageNumbers = document.getElementById('page-numbers');
    const prevButton = document.getElementById('prev-page');
    const nextButton = document.getElementById('next-page');

    pageNumbers.innerHTML = '';
    for (let i = 1; i <= totalPages; i++) {
        const button = document.createElement('button');
        button.className = `px-3 py-1 rounded-lg ${i === currentPage ? 'bg-primary text-white' : 'bg-gray-200 dark:bg-gray-700 hover:bg-primary/90 hover:text-white'}`;
        button.textContent = i;
        button.onclick = () => fetchSales(i);
        pageNumbers.appendChild(button);
    }

    prevButton.disabled = currentPage === 1;
    nextButton.disabled = currentPage === totalPages;
}

fetchSales(1);

function confirmDelete(id, customerName) {
    if (confirm(`Are you sure you want to delete the sale for ${customerName}?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'sales.php';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="transaction_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function openReceiptModal() {
    document.getElementById('receipt-modal').classList.remove('hidden');
}

function closeReceiptModal() {
    document.getElementById('receipt-modal').classList.add('hidden');
}

function safeFeatherReplace() {
    if (typeof feather !== 'undefined') {
        feather.replace();
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>