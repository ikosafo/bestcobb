<?php
require_once __DIR__ . '/config.php';

$page_title = 'Dashboard';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// --- User and Store Context Setup ---

$user = [
    'name' => $_SESSION['username'] ?? 'John Doe',
    'role' => $_SESSION['role'] ?? 'Mall Admin',
    'store_id' => $_SESSION['store_id'] ?? null, 
    'store' => 'All Stores'
];

$is_store_user = $user['store_id'] !== null;
$store_param_types = 'i';
$store_param_value = $user['store_id'];

if ($is_store_user) {
    // Fetch the specific store name
    $store_query = "SELECT store_name FROM stores WHERE id = ?";
    $stmt = mysqli_prepare($conn, $store_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $user['store_id']);
        mysqli_stmt_execute($stmt);
        $store_result = mysqli_stmt_get_result($stmt);
        if ($store_row = mysqli_fetch_assoc($store_result)) {
            $user['store'] = $store_row['store_name'];
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log("Error preparing store name query: " . mysqli_error($conn));
    }
}


// --- Data Retrieval ---

// Load settings for currency symbol
$settings_query = "SELECT currency_symbol FROM settings WHERE id = 1";
$settings_result = mysqli_query($conn, $settings_query);
$settings = mysqli_fetch_assoc($settings_result) ?: ['currency_symbol' => 'GHS'];
$currency_symbol = htmlspecialchars($settings['currency_symbol']);


// 1. Daily Sales Query
$daily_sales_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions t 
                      WHERE DATE(date) = CURDATE()" . ($is_store_user ? " AND t.store_id = ? " : "");
$stmt = mysqli_prepare($conn, $daily_sales_query);
if ($stmt) {
    if ($is_store_user) {
        mysqli_stmt_bind_param($stmt, $store_param_types, $store_param_value);
    }
    mysqli_stmt_execute($stmt);
    $daily_sales_result = mysqli_stmt_get_result($stmt);
    $daily_sales = $daily_sales_result ? mysqli_fetch_assoc($daily_sales_result)['total'] : 0;
    mysqli_stmt_close($stmt);
} else {
    error_log("Error in daily sales query: " . mysqli_error($conn));
    $daily_sales = 0;
}


// 2. Low Stock Query
$low_stock_query = "SELECT COUNT(*) as count FROM products p 
                    WHERE p.stock < 10" . ($is_store_user ? " AND p.store_id = ? " : "");
$stmt = mysqli_prepare($conn, $low_stock_query);
if ($stmt) {
    if ($is_store_user) {
        mysqli_stmt_bind_param($stmt, $store_param_types, $store_param_value);
    }
    mysqli_stmt_execute($stmt);
    $low_stock_result = mysqli_stmt_get_result($stmt);
    $low_stock_items = $low_stock_result ? mysqli_fetch_assoc($low_stock_result)['count'] : 0;
    mysqli_stmt_close($stmt);
} else {
    error_log("Error in low stock query: " . mysqli_error($conn));
    $low_stock_items = 0;
}


// 3. Top Category Query
$top_category_query = "SELECT p.category FROM transactions t 
                       JOIN products p ON t.product_id = p.id 
                       WHERE 1=1" . ($is_store_user ? " AND t.store_id = ? " : "") . "
                       GROUP BY p.category 
                       ORDER BY SUM(t.amount) DESC LIMIT 1";

$stmt = mysqli_prepare($conn, $top_category_query);
if ($stmt) {
    if ($is_store_user) {
        mysqli_stmt_bind_param($stmt, $store_param_types, $store_param_value);
    }
    mysqli_stmt_execute($stmt);
    $top_category_result = mysqli_stmt_get_result($stmt);
    $top_category = $top_category_result ? mysqli_fetch_assoc($top_category_result)['category'] : 'N/A';
    mysqli_stmt_close($stmt);
} else {
    error_log("Error in top category query: " . mysqli_error($conn));
    $top_category = 'N/A';
}


// 4. Recent Transactions Query
$transactions_query = "SELECT t.id, t.customer_name, t.amount, t.date, t.status, s.store_name 
                       FROM transactions t 
                       JOIN stores s ON t.store_id = s.id 
                       WHERE 1=1" . ($is_store_user ? " AND t.store_id = ? " : "") . "
                       ORDER BY t.date DESC LIMIT 5";

$stmt = mysqli_prepare($conn, $transactions_query);
if ($stmt) {
    if ($is_store_user) {
        mysqli_stmt_bind_param($stmt, $store_param_types, $store_param_value);
    }
    mysqli_stmt_execute($stmt);
    $transactions_result = mysqli_stmt_get_result($stmt);
    $transactions = $transactions_result ? mysqli_fetch_all($transactions_result, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
} else {
    error_log("Error in transactions query: " . mysqli_error($conn));
    $transactions = [];
}


// Include header
require_once __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="flex items-center">
            <i data-feather="dollar-sign" class="w-8 h-8 text-accent mr-4"></i>
            <div>
                <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Daily Sales</h2>
                <p class="text-xl font-semibold text-primary"><?php echo $currency_symbol . number_format($daily_sales, 2); ?></p>
                <p class="text-xs text-neutral">In **<?php echo htmlspecialchars($user['store']); ?>**</p>
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

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-semibold">Recent Transactions in <?php echo htmlspecialchars($user['store']); ?></h2>
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
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="6" class="p-3 text-center text-neutral">No recent transactions found for this store.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                        <tr class="table-row border-b border-gray-200 dark:border-gray-700" data-store="<?php echo htmlspecialchars($tx['store_name']); ?>" data-status="<?php echo htmlspecialchars($tx['status']); ?>">
                            <td class="p-3"><?php echo htmlspecialchars($tx['id']); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($tx['store_name']); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($tx['customer_name']); ?></td>
                            <td class="p-3"><?php echo $currency_symbol . number_format($tx['amount'], 2); ?></td>
                            <td class="p-3"><?php echo date('M d, H:i', strtotime($tx['date'])); ?></td>
                            <td class="p-3">
                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $tx['status'] == 'Completed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'; ?>">
                                    <?php echo htmlspecialchars($tx['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="mt-4 flex justify-between text-sm">
            <span>Showing 1-<?php echo count($transactions); ?> of <?php echo count($transactions); ?> (Recent)</span>
            <div class="flex space-x-2">
                <button class="p-2 border border-gray-200 dark:border-gray-600 rounded hover:bg-secondary dark:hover:bg-gray-600" disabled>
                    <i data-feather="chevron-left" class="w-4 h-4"></i>
                </button>
                <button class="p-2 border border-gray-200 dark:border-gray-600 rounded hover:bg-secondary dark:hover:bg-gray-600" disabled>
                    <i data-feather="chevron-right" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700" style="height: 600px !important; overflow: hidden !important;">
        <h2 class="text-lg font-semibold mb-4">Sales Analytics</h2>
        <div class="flex justify-between items-center mb-4">
            <span class="text-sm text-neutral">Sales Trend (Last 7 Days)</span>
            <select class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm">
                <option>Last 7 Days</option>
                <option>Last 30 Days</option>
            </select>
        </div>
        <canvas id="salesChart" class="w-full h-full"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // --- Utility functions (for header interactivity) ---
    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const sidebarTitle = document.getElementById('sidebar-title');
        const navSpans = sidebar.querySelectorAll('span[id^="nav-"]');
        
        sidebar.classList.toggle('sidebar-hidden');
        sidebar.classList.toggle('sidebar-full');

        if (sidebar.classList.contains('sidebar-hidden')) {
            sidebarTitle.classList.add('hidden');
            navSpans.forEach(span => span.classList.add('hidden'));
        } else {
            sidebarTitle.classList.remove('hidden');
            navSpans.forEach(span => span.classList.remove('hidden'));
        }
        feather.replace();
    }
    
    window.toggleDarkMode = function() {
        document.body.classList.toggle('dark');
    }

    // --- Chart.js Initialization (Bar Chart) ---
    const storeName = "<?php echo $user['store']; ?>";
    const currencySymbol = "<?php echo $currency_symbol; ?>";
    const dailySales = parseFloat("<?php echo $daily_sales; ?>") || 0;

    // Dynamic placeholder data for a smoother trend
    let placeholderSalesData = [
        Math.round(dailySales * 0.9 + 50), 
        Math.round(dailySales * 1.1 - 20), 
        Math.round(dailySales * 0.8 + 100), 
        Math.round(dailySales * 1.2 - 70), 
        Math.round(dailySales * 1.05), 
        Math.round(dailySales * 0.95), 
        dailySales 
    ];

    // If daily sales is 0, provide a sensible default trend 
    if (dailySales === 0) {
        placeholderSalesData = [250, 400, 320, 500, 450, 550, 0];
    }
    
    // Ensure data points are non-negative
    placeholderSalesData = placeholderSalesData.map(val => Math.max(0, val));
    
    const ctx = document.getElementById('salesChart').getContext('2d');
    new Chart(ctx, {
        // Bar Chart implemented
        type: 'bar', 
        data: {
            labels: ['Day -6', 'Day -5', 'Day -4', 'Day -3', 'Day -2', 'Yesterday', 'Today'],
            datasets: [{
                label: `Sales for ${storeName} (${currencySymbol})`,
                data: placeholderSalesData, 
                backgroundColor: '#1e3a8a', 
                borderColor: '#1e3a8a', 
                borderWidth: 1,
            }]
        },
        options: {
            responsive: true,
            // IMPORTANT: Chart.js needs its container height to be fixed for this to work
            maintainAspectRatio: false, 
            scales: {
                y: { 
                    beginAtZero: true, 
                    grid: { color: 'rgba(226, 232, 240, 0.5)', borderColor: '#e2e8f0' },
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
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label = 'Total: ';
                            }
                            if (context.parsed.y !== null) {
                                label += currencySymbol + context.parsed.y.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                            }
                            return label;
                        }
                    }
                }
            },
            animation: {
                duration: 1000,
                easing: 'easeOutQuart'
            }
        }
    });
    
    feather.replace();
});
</script>

<?php
// Include footer
require_once __DIR__ . '/includes/footer.php';

// Close database connection
mysqli_close($conn);
?>