<?php
// Ensure config is included before header
require_once __DIR__ . '/../config.php';

// Initialize user array for header
$user = [
    'name' => $_SESSION['username'] ?? 'John Doe',
    'role' => $_SESSION['role'] ?? 'Mall Admin',
    'store' => 'Unknown Store' // Default; will be updated below
];

// Fetch store name for the logged-in user
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
            $user['store'] = 'No Store Assigned';
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log("Error preparing store query: " . mysqli_error($conn));
    }
} else {
    error_log("No store_id set for user_id: {$_SESSION['user_id']}");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - Mall POS' : 'Mall POS'; ?></title>
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
        #product-search-results {
            max-height: 200px;
            overflow-y: auto;
        }
        @media print {
            body * {
                visibility: hidden;
            }
            #receipt, #receipt * {
                visibility: visible;
            }
            #receipt {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                margin: 0;
                padding: 0;
                width: 100%;
                border: none;
            }
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
                    ['name' => 'Dashboard', 'icon' => 'home', 'url' => 'index.php'],
                    ['name' => 'Sales', 'icon' => 'dollar-sign', 'url' => 'sales.php'],
                    ['name' => 'Inventory', 'icon' => 'package', 'url' => 'inventory.php'],
                    ['name' => 'Transactions', 'icon' => 'file-text', 'url' => 'transactions.php'],
                    ['name' => 'Customers', 'icon' => 'users', 'url' => 'customers.php'],
                    ['name' => 'Reports', 'icon' => 'bar-chart-2', 'url' => 'reports.php'],
                    ['name' => 'Stores', 'icon' => 'shopping-cart', 'url' => 'stores.php'],
                    ['name' => 'Users', 'icon' => 'users', 'url' => 'users.php'],
                    ['name' => 'Settings', 'icon' => 'settings', 'url' => 'settings.php'],
                    ['name' => 'Log Out', 'icon' => 'power', 'url' => 'logout.php'],
                ];

                // Filter navigation items for Cashier role
                if ($user['role'] == 'cashier') {
                    $allowed_items = ['Dashboard', 'Sales', 'Log Out'];
                    $nav_items = array_filter($nav_items, function($item) use ($allowed_items) {
                        return in_array($item['name'], $allowed_items);
                    });
                }

                $current_page = isset($page_title) ? $page_title : '';
                foreach ($nav_items as $item) {
                    $active = $item['name'] === $current_page ? 'bg-primary/80' : '';
                    echo "<a href='{$item['url']}' class='flex items-center p-4 hover:bg-primary/80 transition rounded-lg mx-2 $active'>
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
                        <input type="text" id="search-sales" placeholder="Search sales..." class="pl-10 pr-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary" oninput="filterSales()">
                        <i data-feather="search" class="absolute left-3 top-1/2 transform -translate-y-1/2 text-neutral"></i>
                    </div>
                    <select id="store-filter" class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm" disabled>
                        <option value="<?php echo isset($_SESSION['store_id']) ? $_SESSION['store_id'] : ''; ?>">
                            <?php echo htmlspecialchars($user['store']); ?>
                        </option>
                    </select>
                </div>
                <div class="flex items-center space-x-4">
                    <button class="relative p-2 rounded hover:bg-gray-200 dark:hover:bg-gray-600">
                        <i data-feather="bell" class="w-5 h-5"></i>
                        <span class="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full"></span>
                    </button>
                    <div class="relative group">
                        <span class="cursor-pointer flex items-center text-sm font-medium">
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

            <!-- Main Content Opening -->
            <main class="p-6 flex-1 overflow-auto">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-2xl font-semibold tracking-tight"><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Page'; ?></h1>
                    <div class="text-sm text-neutral">Last updated: <?php echo date('M d, Y H:i'); ?></div>
                </div>