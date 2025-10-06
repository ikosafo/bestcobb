<?php
require_once __DIR__ . '/config.php';
$page_title = 'Sales';

// Handle non-AJAX form submissions (delete only)
$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'remove_from_cart') {
        $index = filter_input(INPUT_POST, 'index', FILTER_SANITIZE_NUMBER_INT);
        if (isset($_SESSION['cart'][$index])) {
            unset($_SESSION['cart'][$index]);
            $_SESSION['cart'] = array_values($_SESSION['cart']);
            $success_message = 'Item removed from cart!';
        }
    } elseif ($_POST['action'] === 'delete') {
        $transaction_id = filter_input(INPUT_POST, 'transaction_id', FILTER_SANITIZE_NUMBER_INT);
        $query = "DELETE FROM transactions WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'i', $transaction_id);
        if (mysqli_stmt_execute($stmt)) {
            $success_message = 'Sale deleted successfully!';
        } else {
            $error_message = 'Error deleting sale: ' . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
}

// Fetch products for search
$products_query = "SELECT id, name, price, stock, store_id, barcode FROM products WHERE status != 'Out of Stock'";
$products_result = mysqli_query($conn, $products_query);
$products = $products_result ? mysqli_fetch_all($products_result, MYSQLI_ASSOC) : [];

// Fetch metrics
$total_sales_query = "SELECT SUM(amount) as total FROM transactions WHERE DATE(date) = CURDATE()";
$total_sales_result = mysqli_query($conn, $total_sales_query);
$total_sales = $total_sales_result ? mysqli_fetch_assoc($total_sales_result)['total'] ?? 0 : 0;

$transactions_today_query = "SELECT COUNT(*) as count FROM transactions WHERE DATE(date) = CURDATE()";
$transactions_today_result = mysqli_query($conn, $transactions_today_query);
$transactions_today = $transactions_today_result ? mysqli_fetch_assoc($transactions_today_result)['count'] : 0;

$pending_transactions_query = "SELECT COUNT(*) as count FROM transactions WHERE status = 'Pending'";
$pending_transactions_result = mysqli_query($conn, $pending_transactions_query);
$pending_transactions = $pending_transactions_result ? mysqli_fetch_assoc($pending_transactions_result)['count'] : 0;

// Include header
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
            <select name="store_id" id="cart-store-id" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required onchange="updateProductOptions()">
                <option value="">Select Store</option>
                <?php foreach ($stores as $store): ?>
                    <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option>
                <?php endforeach; ?>
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
                <?php foreach ($_SESSION['cart'] as $index => $item): ?>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <td class="p-3"><?php echo htmlspecialchars($item['name']); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($stores[array_search($item['store_id'], array_column($stores, 'id'))]['store_name'] ?? 'Unknown'); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($item['quantity']); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($item['stock'] - $item['quantity']); ?></td>
                        <td class="p-3">$<?php echo number_format($item['price'] ?? 0, 2); ?></td>
                        <td class="p-3">$<?php echo number_format($item['subtotal'] ?? 0, 2); ?></td>
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
        <p class="text-lg font-semibold">Total: $<span id="cart-total"><?php echo number_format(array_sum(array_column($_SESSION['cart'], 'subtotal')) ?? 0, 2); ?></span></p>
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
                <p class="text-xl font-semibold text-primary">$<?php echo number_format($total_sales ?? 0, 2); ?></p>
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
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Customer Name <span class="text-red-500">*</span></label>
                <input type="text" id="customer-name" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Payment Method <span class="text-red-500">*</span></label>
                <select id="payment-method" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
                    <option value="">Select Payment Method</option>
                    <option value="Cash">Cash</option>
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
                <input type="text" id="checkout-total" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400" value="$<?php echo number_format(array_sum(array_column($_SESSION['cart'], 'subtotal')) ?? 0, 2); ?>" readonly>
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
// Product search and filtering
let products = <?php echo json_encode($products); ?>;
function filterProducts() {
    const search = document.getElementById('product-search').value.toLowerCase();
    const storeId = document.getElementById('cart-store-id').value;
    const results = document.getElementById('product-search-results');
    results.innerHTML = '';
    const filteredProducts = products.filter(product => 
        (!storeId || product.store_id == storeId) && 
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

// Update product options based on store selection
function updateProductOptions() {
    const storeId = document.getElementById('cart-store-id').value;
    document.getElementById('product-search').value = '';
    document.getElementById('cart-product-id').value = '';
    document.getElementById('cart-quantity').value = '';
    document.getElementById('product-search-results').classList.add('hidden');
}

// AJAX cart addition
async function addToCart() {
    const storeId = document.getElementById('cart-store-id').value;
    const productId = document.getElementById('cart-product-id').value;
    const quantity = document.getElementById('cart-quantity').value;
    const barcode = document.getElementById('barcode').value;

    if (!storeId || (!productId && !barcode) || !quantity) {
        showMessage('Please fill in all required fields.', false);
        return;
    }

    const button = document.querySelector('#cart-form button');
    button.disabled = true;
    button.innerHTML = '<i data-feather="loader" class="w-4 h-4 mr-2 animate-spin"></i> Adding...';
    feather.replace();

    try {
        const response = await fetch('add_to_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `product_id=${productId}&quantity=${quantity}&store_id=${storeId}&barcode=${encodeURIComponent(barcode)}`
        });
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
            showMessage(data.message, false);
        }
    } catch (error) {
        showMessage('Error adding to cart: ' + error.message, false);
    } finally {
        button.disabled = false;
        button.innerHTML = '<i data-feather="plus" class="w-4 h-4 mr-2"></i> Add to Cart';
        feather.replace();
    }
}

// Update cart table dynamically
function updateCartTable(cart, total) {
    const cartTable = document.getElementById('cart-table');
    const cartTotal = document.getElementById('cart-total');
    const checkoutButton = document.querySelector('button[onclick="openCheckoutModal()"]');
    const stores = <?php echo json_encode($stores); ?>;

    cartTable.innerHTML = '';
    cart.forEach((item, index) => {
        const storeName = stores.find(store => store.id == item.store_id)?.store_name || 'Unknown';
        const row = `
            <tr class="border-b border-gray-200 dark:border-gray-700">
                <td class="p-3">${item.name}</td>
                <td class="p-3">${storeName}</td>
                <td class="p-3">${item.quantity}</td>
                <td class="p-3">${item.stock - item.quantity}</td>
                <td class="p-3">$${Number(item.price).toFixed(2)}</td>
                <td class="p-3">$${Number(item.subtotal).toFixed(2)}</td>
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

    cartTotal.textContent = total;
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
    feather.replace();
}

// Show success/error messages
function showMessage(message, isSuccess) {
    const messageDiv = document.createElement('div');
    messageDiv.id = isSuccess ? 'success-message' : 'error-message';
    messageDiv.className = `mb-6 p-4 ${isSuccess ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'} rounded-lg flex items-center`;
    messageDiv.innerHTML = `<i data-feather="${isSuccess ? 'check-circle' : 'alert-circle'}" class="w-5 h-5 mr-2"></i> ${message}`;
    
    const existingMessage = document.getElementById('success-message') || document.getElementById('error-message');
    if (existingMessage) existingMessage.remove();
    
    document.querySelector('main').insertBefore(messageDiv, document.querySelector('.card'));
    setTimeout(() => messageDiv.remove(), 3000);
    feather.replace();
}

// Barcode input handling
document.getElementById('barcode').addEventListener('change', async function() {
    const barcode = this.value;
    if (barcode) {
        document.getElementById('cart-quantity').value = 1; // Default quantity for barcode scan
        await addToCart();
    }
});

// Checkout modal handling
function openCheckoutModal() {
    document.getElementById('checkout-modal').classList.remove('hidden');
}

function closeCheckoutModal() {
    document.getElementById('checkout-modal').classList.add('hidden');
}

// AJAX checkout
async function processCheckout() {
    const customerName = document.getElementById('customer-name').value;
    const paymentMethod = document.getElementById('payment-method').value;
    const date = document.getElementById('checkout-date').value;

    if (!customerName || !paymentMethod || !date) {
        showMessage('Please fill in all required fields.', false);
        return;
    }

    const button = document.querySelector('#checkout-form button[onclick="processCheckout()"]');
    button.disabled = true;
    button.innerHTML = '<i data-feather="loader" class="w-4 h-4 mr-2 animate-spin"></i> Processing...';
    feather.replace();

    try {
        const response = await fetch('checkout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=checkout&customer_name=${encodeURIComponent(customerName)}&payment_method=${encodeURIComponent(paymentMethod)}&date=${encodeURIComponent(date)}`
        });
        const data = await response.json();

        if (data.success) {
            updateCartTable([], '0.00');
            products = data.updated_products; // Update product stock
            showMessage(data.message, true);
            closeCheckoutModal();
            printReceiptAfterCheckout(data.transactions);
            fetchSales(1); // Refresh sales table
        } else {
            showMessage(data.message, false);
        }
    } catch (error) {
        showMessage('Error processing checkout: ' + error.message, false);
    } finally {
        button.disabled = false;
        button.innerHTML = '<i data-feather="check" class="w-4 h-4 mr-2"></i> Complete Checkout';
        feather.replace();
    }
}

// Receipt modal handling
function openReceiptModal() {
    document.getElementById('receipt-modal').classList.remove('hidden');
}

function closeReceiptModal() {
    document.getElementById('receipt-modal').classList.add('hidden');
}

function printReceipt(sale) {
    const receiptBody = document.getElementById('receipt-body');
    receiptBody.innerHTML = `
        <p><strong>Transaction ID:</strong> ${sale.id}</p>
        <p><strong>Store:</strong> ${sale.store_name}</p>
        <p><strong>Product:</strong> ${sale.product_name}</p>
        <p><strong>Customer:</strong> ${sale.customer_name}</p>
        <p><strong>Quantity:</strong> ${sale.quantity}</p>
        <p><strong>Amount:</strong> $${Number(sale.amount).toFixed(2)}</p>
        <p><strong>Date:</strong> ${sale.date}</p>
        <p><strong>Payment Method:</strong> ${sale.payment_method || 'N/A'}</p>
        <p><strong>Status:</strong> ${sale.status}</p>
    `;
    openReceiptModal();
}

function printReceiptContent() {
    const receiptContent = document.getElementById('receipt-content').innerHTML;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head>
                <title>Receipt</title>
                <style>
                    @page { margin: 0; }
                    body { font-family: 'Poppins', sans-serif; margin: 20px; }
                    h2 { font-size: 1.5rem; }
                    p { margin: 5px 0; }
                </style>
            </head>
            <body>${receiptContent}</body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
    printWindow.close();
}

function printReceiptAfterCheckout(transactions) {
    const receiptBody = document.getElementById('receipt-body');
    let receiptHTML = '<h2>Receipt</h2>';
    transactions.forEach((sale, index) => {
        receiptHTML += `
            <p><strong>Transaction ${index + 1}:</strong></p>
            <p><strong>Store:</strong> ${sale.store_name}</p>
            <p><strong>Product:</strong> ${sale.product_name}</p>
            <p><strong>Quantity:</strong> ${sale.quantity}</p>
            <p><strong>Amount:</strong> $${Number(sale.amount).toFixed(2)}</p>
            ${index < transactions.length - 1 ? '<hr>' : ''}
        `;
    });
    receiptHTML += `
        <p><strong>Customer:</strong> ${transactions[0].customer_name}</p>
        <p><strong>Date:</strong> ${transactions[0].date}</p>
        <p><strong>Payment Method:</strong> ${transactions[0].payment_method}</p>
        <p><strong>Total Amount:</strong> $${Number(transactions.reduce((sum, sale) => sum + Number(sale.amount), 0)).toFixed(2)}</p>
    `;
    receiptBody.innerHTML = receiptHTML;

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head>
                <title>Receipt</title>
                <style>
                    @page { margin: 0; }
                    body { font-family: 'Poppins', sans-serif; margin: 20px; }
                    h2 { font-size: 1.5rem; }
                    p { margin: 5px 0; }
                    hr { margin: 10px 0; }
                </style>
            </head>
            <body>${receiptHTML}</body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
    printWindow.close();
}

// Fetch sales with pagination
let currentPage = 1;
async function fetchSales(page) {
    const rowsPerPage = document.getElementById('rows-per-page').value;
    const statusFilter = document.getElementById('status-filter').value;

    const salesTable = document.getElementById('sales-table');
    salesTable.innerHTML = '<tr><td colspan="10" class="p-3 text-center">Loading...</td></tr>';

    try {
        const response = await fetch(`fetch_sales.php?page=${page}&rows_per_page=${rowsPerPage}${statusFilter ? `&status=${statusFilter}` : ''}`);
        const data = await response.json();

        if (data.success) {
            currentPage = data.current_page;
            salesTable.innerHTML = '';
            data.sales.forEach(sale => {
                const row = `
                    <tr class="table-row border-b border-gray-200 dark:border-gray-700" data-status="${sale.status}" data-store="${sale.store_id}">
                        <td class="p-3">${sale.id}</td>
                        <td class="p-3">${sale.store_name}</td>
                        <td class="p-3">${sale.product_name}</td>
                        <td class="p-3">${sale.customer_name}</td>
                        <td class="p-3">${sale.quantity}</td>
                        <td class="p-3">$${Number(sale.amount).toFixed(2)}</td>
                        <td class="p-3">${sale.date}</td>
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
                            <button onclick='confirmDelete(${sale.id}, "${sale.customer_name}")' class="text-red-500 hover:text-red-600">
                                <i data-feather="trash-2" class="w-5 h-5"></i>
                            </button>
                        </td>
                    </tr>`;
                salesTable.innerHTML += row;
            });

            updatePagination(data.total_pages, data.current_page);
            feather.replace();
        } else {
            showMessage(data.message, false);
        }
    } catch (error) {
        showMessage('Error fetching sales: ' + error.message, false);
    }
}

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

// Initial sales fetch
fetchSales(1);

// Confirm delete
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
</script>

<?php
// Include footer
require_once __DIR__ . '/includes/footer.php';
?>