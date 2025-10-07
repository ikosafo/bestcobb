<?php
require_once __DIR__ . '/config.php';

$page_title = 'Dashboard';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Map session data to user array for consistency with header.php
$user = [
    'name' => $_SESSION['username'] ?? 'John Doe',
    'role' => $_SESSION['role'] ?? 'Mall Admin',
    'store' => 'All Stores' // Default; update based on store_id if needed
];

// Fetch store name based on store_id
if (isset($_SESSION['store_id'])) {
    $store_query = "SELECT store_name FROM stores WHERE id = ?";
    $stmt = mysqli_prepare($conn, $store_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $_SESSION['store_id']);
        mysqli_stmt_execute($stmt);
        $store_result = mysqli_stmt_get_result($stmt);
        if ($store_row = mysqli_fetch_assoc($store_result)) {
            $user['store'] = $store_row['store_name'];
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log("Error preparing store query: " . mysqli_error($conn));
    }
}

// Load settings for currency symbol
$settings_query = "SELECT currency_symbol FROM settings WHERE id = 1";
$settings_result = mysqli_query($conn, $settings_query);
$settings = mysqli_fetch_assoc($settings_result) ?: ['currency_symbol' => 'GHS'];

// Sample queries for mall-specific data
$daily_sales_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE DATE(date) = CURDATE()";
$daily_sales_result = mysqli_query($conn, $daily_sales_query);
$daily_sales = 0; // Default value
if ($daily_sales_result) {
    $row = mysqli_fetch_assoc($daily_sales_result);
    $daily_sales = $row['total'] ?? 0;
} else {
    error_log("Error in daily sales query: " . mysqli_error($conn));
}

$low_stock_query = "SELECT COUNT(*) as count FROM products WHERE stock < 10";
$low_stock_result = mysqli_query($conn, $low_stock_query);
$low_stock_items = $low_stock_result ? mysqli_fetch_assoc($low_stock_result)['count'] : 0;

$top_category_query = "SELECT category FROM transactions t JOIN products p ON t.product_id = p.id GROUP BY category ORDER BY SUM(amount) DESC LIMIT 1";
$top_category_result = mysqli_query($conn, $top_category_query);
$top_category = $top_category_result ? mysqli_fetch_assoc($top_category_result)['category'] : 'Electronics';

$transactions_query = "SELECT t.id, t.customer_name, t.amount, t.date, t.status, s.store_name 
                      FROM transactions t JOIN stores s ON t.store_id = s.id 
                      ORDER BY t.date DESC LIMIT 5";
$transactions_result = mysqli_query($conn, $transactions_query);
$transactions = $transactions_result ? mysqli_fetch_all($transactions_result, MYSQLI_ASSOC) : [];

// Include header
require_once __DIR__ . '/includes/header.php';
?>

<!-- Main Dashboard Content -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="flex items-center">
            <i data-feather="dollar-sign" class="w-8 h-8 text-accent mr-4"></i>
            <div>
                <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Daily Sales</h2>
                <p class="text-xl font-semibold text-primary"><?php echo htmlspecialchars($settings['currency_symbol']) . number_format($daily_sales, 2); ?></p>
                <p class="text-xs text-neutral">Across <?php echo htmlspecialchars($user['store']); ?></p>
            </div>
        </div>
    </div>
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="flex items-center">
            <i data-feather="alert-triangle" class="w-8 h-8 text-yellow-500 mr-4"></i>
            <div>
                <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Low Stock Items</h2>
                <p class="text-xl font-semibold text-primary"><?php echo $low_stock_items; ?></p>
                <p class="text-xs text-neutral">Requires restocking</p>
            </div>
        </div>
    </div>
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="flex items-center">
            <i data-feather="award" class="w-8 h-8 text-accent mr-4"></i>
            <div>
                <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Top Category</h2>
                <p class="text-xl font-semibold text-primary"><?php echo htmlspecialchars($top_category); ?></p>
                <p class="text-xs text-neutral">Highest revenue</p>
            </div>
        </div>
    </div>
</div>

<!-- Transactions and Analytics -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Transactions Table -->
    <div class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-semibold">Recent Transactions</h2>
            <div class="flex space-x-2">
                <select class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm">
                    <option>All Stores</option>
                    <option>Store A</option>
                    <option>Store B</option>
                </select>
                <button class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm hover:bg-secondary dark:hover:bg-gray-600">
                    <i data-feather="filter" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
        <table id="sales-table" class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="p-3 text-left font-medium">ID</th>
                    <th class="p-3 text-left font-medium">Store</th>
                    <th class="p-3 text-left font-medium">Customer</th>
                    <th class="p-3 text-left font-medium">Amount</th>
                    <th class="p-3 text-left font-medium">Date</th>
                    <th class="p-3 text-left font-medium">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $tx): ?>
                    <tr class="table-row border-b border-gray-200 dark:border-gray-700" data-store="<?php echo htmlspecialchars($tx['store_name']); ?>" data-status="<?php echo htmlspecialchars($tx['status']); ?>">
                        <td class="p-3"><?php echo htmlspecialchars($tx['id']); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($tx['store_name']); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($tx['customer_name']); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($settings['currency_symbol']) . number_format($tx['amount'], 2); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($tx['date']); ?></td>
                        <td class="p-3">
                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $tx['status'] == 'Completed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'; ?>">
                                <?php echo htmlspecialchars($tx['status']); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="mt-4 flex justify-between text-sm">
            <span>Showing 1-5 of 50</span>
            <div class="flex space-x-2">
                <button class="p-2 border border-gray-200 dark:border-gray-600 rounded hover:bg-secondary dark:hover:bg-gray-600">
                    <i data-feather="chevron-left" class="w-4 h-4"></i>
                </button>
                <button class="p-2 border border-gray-200 dark:border-gray-600 rounded hover:bg-secondary dark:hover:bg-gray-600">
                    <i data-feather="chevron-right" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Analytics Section -->
    <div class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <h2 class="text-lg font-semibold mb-4">Sales Analytics</h2>
        <div class="flex justify-between items-center mb-4">
            <span class="text-sm text-neutral">Sales by Store (Last 7 Days)</span>
            <select class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm">
                <option>Last 7 Days</option>
                <option>Last 30 Days</option>
            </select>
        </div>
        <canvas id="salesChart" class="w-full h-64"></canvas>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('salesChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Store A', 'Store B', 'Store C', 'Store D', 'Store E'],
            datasets: [{
                label: 'Sales (<?php echo htmlspecialchars($settings['currency_symbol']); ?>)',
                data: [5200, 3900, 6000, 2500, 4800],
                backgroundColor: '#1e3a8a80',
                borderColor: '#1e3a8a',
                borderWidth: 1
            }]
        },
        options: {
            scales: {
                y: { 
                    beginAtZero: true, 
                    grid: { color: '#e2e8f0', borderColor: '#e2e8f0' },
                    ticks: { color: '#64748b' }
                },
                x: { 
                    grid: { display: false },
                    ticks: { color: '#64748b' }
                }
            },
            plugins: {
                legend: { 
                    labels: { 
                        font: { size: 12, family: 'Poppins' }, 
                        color: '#64748b' 
                    } 
                }
            },
            animation: {
                duration: 1000,
                easing: 'easeOutQuart'
            }
        }
    });
});
</script>

<?php
// Include footer
require_once __DIR__ . '/includes/footer.php';

// Close database connection
mysqli_close($conn);
?>