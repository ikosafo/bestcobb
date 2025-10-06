<?php
session_start();

// Simulated user session (replace with your auth logic)
$user = isset($_SESSION['user']) ? $_SESSION['user'] : ['name' => 'John Doe', 'role' => 'Mall Admin', 'store' => 'All Stores'];

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = 'root';
$db_name = 'bestcobb';

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Handle form submissions
$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $product_id = filter_input(INPUT_POST, 'product_id', FILTER_SANITIZE_NUMBER_INT);
            $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
            $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
            $store_id = filter_input(INPUT_POST, 'store_id', FILTER_SANITIZE_NUMBER_INT);
            $stock = filter_input(INPUT_POST, 'stock', FILTER_SANITIZE_NUMBER_INT);
            $price = filter_input(INPUT_POST, 'price', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);

            if ($name && $category && $store_id && $stock !== null && $price !== null && $status) {
                if ($_POST['action'] === 'add') {
                    $query = "INSERT INTO products (name, category, store_id, stock, price, status) VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, 'ssisds', $name, $category, $store_id, $stock, $price, $status);
                } else {
                    $query = "UPDATE products SET name = ?, category = ?, store_id = ?, stock = ?, price = ?, status = ? WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, 'ssisdsi', $name, $category, $store_id, $stock, $price, $status, $product_id);
                }
                if (mysqli_stmt_execute($stmt)) {
                    $success_message = $_POST['action'] === 'add' ? 'Product added successfully!' : 'Product updated successfully!';
                } else {
                    $error_message = 'Error saving product: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt);
            } else {
                $error_message = 'Please fill in all required fields.';
            }
        } elseif ($_POST['action'] === 'delete') {
            $product_id = filter_input(INPUT_POST, 'product_id', FILTER_SANITIZE_NUMBER_INT);
            $query = "DELETE FROM products WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, 'i', $product_id);
            if (mysqli_stmt_execute($stmt)) {
                $success_message = 'Product deleted successfully!';
            } else {
                $error_message = 'Error deleting product: ' . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Fetch products
$products_query = "SELECT p.id, p.name, p.category, p.store_id, p.stock, p.price, p.status, s.store_name 
                   FROM products p LEFT JOIN stores s ON p.store_id = s.id";
$products_result = mysqli_query($conn, $products_query);
$products = $products_result ? mysqli_fetch_all($products_result, MYSQLI_ASSOC) : [];

// Fetch stores for dropdown
$stores_query = "SELECT id, store_name FROM stores WHERE status = 'Active'";
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - Mall POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        body {
            font-family: 'Poppins', sans-serif;
        }
        .sidebar {
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        .sidebar-hidden {
            width: 4.5rem;
        }
        .sidebar-full {
            width: 16rem;
        }
        .card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.15);
        }
        .table-row {
            transition: background-color 0.2s ease;
        }
        .table-row:hover {
            background-color: #f8fafc;
        }
        .dark .table-row:hover {
            background-color: #2d3748;
        }
        .gradient-bg {
            background: linear-gradient(135deg, #1e3a8a 0%, #2b6cb0 100%);
        }
        .modal {
            transition: opacity 0.3s ease;
        }
        .modal-content {
            max-height: 80vh;
            overflow: scroll;
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1e3a8a', // Deep navy
                        secondary: '#f1f5f9', // Soft white
                        accent: '#d4af37', // Gold accent
                        neutral: '#64748b' // Slate
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-secondary dark:bg-gray-900 text-gray-900 dark:text-gray-100">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <div id="sidebar" class="sidebar sidebar-full gradient-bg text-white h-screen shadow-lg">
            <div class="p-4 flex justify-between items-center">
                <h1 id="sidebar-title" class="text-lg font-semibold tracking-tight">Mall POS</h1>
                <button onclick="toggleSidebar()" class="p-2 rounded hover:bg-primary/80">
                    <i data-feather="menu" class="w-5 h-5"></i>
                </button>
            </div>
            <nav class="mt-4">
                <?php
                $nav_items = [
                    ['name' => 'Dashboard', 'icon' => 'home'],
                    ['name' => 'Sales', 'icon' => 'dollar-sign'],
                    ['name' => 'Inventory', 'icon' => 'package'],
                    ['name' => 'Transactions', 'icon' => 'file-text'],
                    ['name' => 'Customers', 'icon' => 'users'],
                    ['name' => 'Reports', 'icon' => 'bar-chart-2'],
                    ['name' => 'Stores', 'icon' => 'store'],
                    ['name' => 'Settings', 'icon' => 'settings'],
                ];
                foreach ($nav_items as $item) {
                    $active = $item['name'] === 'Inventory' ? 'bg-primary/80' : '';
                    echo "<a href='#' class='flex items-center p-4 hover:bg-primary/80 transition rounded-lg mx-2 $active'>
                            <i data-feather='{$item['icon']}' class='w-5 h-5'></i>
                            <span id='nav-{$item['name']}' class='ml-3 text-sm font-medium'>{$item['name']}</span>
                          </a>";
                }
                ?>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Header -->
            <header class="bg-white dark:bg-gray-800 shadow-sm p-4 flex justify-between items-center">
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <input type="text" id="search-products" placeholder="Search products..." class="pl-10 pr-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary" oninput="filterProducts()">
                        <i data-feather="search" class="absolute left-3 top-1/2 transform -translate-y-1/2 text-neutral"></i>
                    </div>
                    <select id="store-filter" class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm" onchange="filterProducts()">
                        <option value="">All Stores</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button onclick="openModal('add')" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                        <i data-feather="plus" class="w-4 h-4 mr-2"></i> Add Product
                    </button>
                </div>
                <div class="flex items-center space-x-4">
                    <button class="relative p-2 rounded hover:bg-gray-200 dark:hover:bg-gray-600">
                        <i data-feather="bell" class="w-5 h-5"></i>
                        <span class="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full"></span>
                    </button>
                    <div class="relative group">
                        <span class="cursor-pointer flex items-center text-sm font-Medium">
                            <?php echo htmlspecialchars($user['name']) . " ({$user['role']})"; ?>
                            <i data-feather="chevron-down" class="ml-2 w-4 h-4"></i>
                        </span>
                        <div class="absolute hidden group-hover:block bg-white dark:bg-gray-700 shadow-lg rounded-lg mt-2 right-0 w-48 z-10">
                            <a href="profile.php" class="block px-4 py-2 text-sm hover:bg-secondary dark:hover:bg-gray-600">Profile</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm hover:bg-secondary dark:hover:bg-gray-600">Logout</a>
                        </div>
                    </div>
                    <button onclick="toggleDarkMode()" class="p-2 rounded hover:bg-gray-200 dark:hover:bg-gray-600">
                        <i data-feather="moon" class="w-5 h-5"></i>
                    </button>
                </div>
            </header>

            <!-- Main Inventory Content -->
            <main class="p-6 flex-1 overflow-auto">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-2xl font-semibold tracking-tight">Inventory</h1>
                    <div class="text-sm text-neutral">Last updated: <?php echo date('M d, Y H:i'); ?></div>
                </div>

                <?php if ($success_message): ?>
                    <div class="mb-6 p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
                        <i data-feather="check-circle" class="w-5 h-5 mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php elseif ($error_message): ?>
                    <div class="mb-6 p-4 bg-red-100 text-red-700 rounded-lg flex items-center">
                        <i data-feather="alert-circle" class="w-5 h-5 mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Inventory Table -->
                <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold">Inventory Management</h2>
                        <select id="status-filter" class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm" onchange="filterProducts()">
                            <option value="">All Statuses</option>
                            <option value="In Stock">In Stock</option>
                            <option value="Out of Stock">Out of Stock</option>
                            <option value="Low Stock">Low Stock</option>
                        </select>
                    </div>
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
                                <th class="p-3 text-left font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="product-table">
                            <?php foreach ($products as $product): ?>
                                <tr class="table-row border-b border-gray-200 dark:border-gray-700" data-status="<?php echo htmlspecialchars($product['status']); ?>" data-store="<?php echo $product['store_id']; ?>">
                                    <td class="p-3"><?php echo htmlspecialchars($product['id']); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($product['category']); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($product['store_name']); ?></td>
                                    <td class="p-3"><?php echo htmlspecialchars($product['stock']); ?></td>
                                    <td class="p-3">$<?php echo number_format($product['price'], 2); ?></td>
                                    <td class="p-3">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                            echo $product['status'] == 'In Stock' ? 'bg-green-100 text-green-700' : 
                                                ($product['status'] == 'Low Stock' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'); ?>">
                                            <?php echo htmlspecialchars($product['status']); ?>
                                        </span>
                                    </td>
                                    <td class="p-3 flex space-x-2">
                                        <button onclick='openModal("edit", <?php echo json_encode($product); ?>)' class="text-primary hover:text-primary/80">
                                            <i data-feather="edit" class="w-5 h-5"></i>
                                        </button>
                                        <button onclick='confirmDelete(<?php echo $product['id']; ?>, "<?php echo htmlspecialchars($product['name']); ?>")' class="text-red-500 hover:text-red-600">
                                            <i data-feather="trash-2" class="w-5 h-5"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Inventory Metrics Overview -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
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
                                <p class="text-xl font-semibold text-primary">$<?php echo number_format($total_value, 2); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

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
                                    <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Stock <span class="text-red-500">*</span></label>
                            <input type="number" name="stock" id="product-stock" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" min="0" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Price ($) <span class="text-red-500">*</span></label>
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
                    <form id="delete-form" method="POST" action="inventory.php">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="product_id" id="delete-product-id">
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
        </div>
    </div>

    <script>
        // Initialize Feather Icons
        feather.replace();

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const title = document.getElementById('sidebar-title');
            const navItems = document.querySelectorAll('[id^="nav-"]');
            if (sidebar.classList.contains('sidebar-full')) {
                sidebar.classList.replace('sidebar-full', 'sidebar-hidden');
                title.style.display = 'none';
                navItems.forEach(item => item.style.display = 'none');
            } else {
                sidebar.classList.replace('sidebar-hidden', 'sidebar-full');
                title.style.display = 'block';
                navItems.forEach(item => item.style.display = 'block');
            }
        }

        // Dark mode toggle
        function toggleDarkMode() {
            document.documentElement.classList.toggle('dark');
        }

        // Modal handling
        function openModal(action, product = {}) {
            const modal = document.getElementById('product-modal');
            const form = document.getElementById('product-form');
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
            
            modal.classList.remove('hidden');
            feather.replace();
        }

        function closeModal() {
            document.getElementById('product-modal').classList.add('hidden');
            document.getElementById('product-form').reset();
        }

        function confirmDelete(id, name) {
            const modal = document.getElementById('delete-modal');
            document.getElementById('delete-product-id').value = id;
            document.getElementById('delete-message').textContent = `Are you sure you want to delete "${name}"? This action cannot be undone.`;
            modal.classList.remove('hidden');
            feather.replace();
        }

        function closeDeleteModal() {
            document.getElementById('delete-modal').classList.add('hidden');
        }

        // Product filtering
        function filterProducts() {
            const search = document.getElementById('search-products').value.toLowerCase();
            const store = document.getElementById('store-filter').value;
            const status = document.getElementById('status-filter').value;
            const rows = document.querySelectorAll('#product-table tr');
            
            rows.forEach(row => {
                const name = row.children[1].textContent.toLowerCase();
                const category = row.children[2].textContent.toLowerCase();
                const rowStore = row.getAttribute('data-store');
                const rowStatus = row.getAttribute('data-status');
                const matchesSearch = name.includes(search) || category.includes(search);
                const matchesStore = !store || rowStore === store;
                const matchesStatus = !status || rowStatus === status;
                row.style.display = matchesSearch && matchesStore && matchesStatus ? '' : 'none';
            });
        }
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>