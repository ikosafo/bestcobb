<?php

require_once __DIR__ . '/config.php';
$page_title = 'Stores';

// Check if user is logged in and has the correct role
if (!isset($_SESSION['role']) || $_SESSION['role'] == 'Cashier') {
    header('Location: index.php'); // Redirect to Dashboard if Cashier tries to access
    exit;
}

// Enable full error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Simulated user session
$user = isset($_SESSION['user']) ? $_SESSION['user'] : ['name' => 'John Doe', 'role' => 'Mall Admin', 'store' => 'All Stores'];

// Database connection
if (!isset($conn) || !$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Ensure ENCRYPTION_KEY is defined
if (!defined('ENCRYPTION_KEY')) {
    die("Encryption key not defined in config.php. Please define: define('ENCRYPTION_KEY', 'Your32CharacterSecretKeyHere...');");
}

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
$user_msg_success = '';
$user_msg_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete'])) {
    $encrypted_id = filter_input(INPUT_GET, 'delete', FILTER_SANITIZE_SPECIAL_CHARS);
    error_log("Delete attempt with encrypted_id: " . ($encrypted_id ?: 'empty'));
    if ($encrypted_id) {
        $delete_id = decryptId($encrypted_id);
        if ($delete_id !== false) {
            // Check for foreign key constraints
            $check_query = "SELECT COUNT(*) as count FROM users WHERE store_id = ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, 'i', $delete_id);
            mysqli_stmt_execute($check_stmt);
            $result = mysqli_stmt_get_result($check_stmt);
            $row = mysqli_fetch_assoc($result);
            mysqli_stmt_close($check_stmt);

            if ($row['count'] > 0) {
                $user_msg_error = "Cannot delete store: It is associated with " . $row['count'] . " user(s).";
                error_log("Delete failed: Store ID $delete_id is associated with {$row['count']} user(s)");
            } else {
                $query = "DELETE FROM stores WHERE id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, 'i', $delete_id);
                if (mysqli_stmt_execute($stmt)) {
                    $user_msg_success = "Store deleted successfully!";
                    error_log("Store ID $delete_id deleted successfully");
                } else {
                    $user_msg_error = "Error deleting store: " . mysqli_error($conn);
                    error_log("Delete failed for store ID $delete_id: " . mysqli_error($conn));
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            $user_msg_error = "Invalid or tampered delete request. (Decryption failed for ID: " . htmlspecialchars($encrypted_id) . ")";
            error_log("Decryption failed for encrypted_id: " . $encrypted_id);
        }
    } else {
        $user_msg_error = "Delete request missing encrypted ID.";
        error_log("No encrypted_id received in GET: " . print_r($_GET, true));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $form_store_id = filter_input(INPUT_POST, 'store_id', FILTER_SANITIZE_NUMBER_INT);
            $store_name_val = filter_input(INPUT_POST, 'store_name', FILTER_SANITIZE_SPECIAL_CHARS);
            $store_location_val = filter_input(INPUT_POST, 'location', FILTER_SANITIZE_SPECIAL_CHARS);
            $store_contact_val = filter_input(INPUT_POST, 'contact', FILTER_SANITIZE_SPECIAL_CHARS);
            $store_manager_val = filter_input(INPUT_POST, 'manager', FILTER_SANITIZE_SPECIAL_CHARS);
            $store_status_val = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS);
            $store_hours_val = filter_input(INPUT_POST, 'operating_hours', FILTER_SANITIZE_SPECIAL_CHARS);

            $store_contact_val = $store_contact_val ?? '';
            $store_manager_val = $store_manager_val ?? '';
            $store_hours_val = $store_hours_val ?? '';

            if ($store_name_val && $store_location_val && $store_status_val) {
                if ($_POST['action'] === 'add') {
                    $query = "INSERT INTO stores (store_name, location, contact, manager, status, operating_hours) VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, 'ssssss', $store_name_val, $store_location_val, $store_contact_val, $store_manager_val, $store_status_val, $store_hours_val);
                } else {
                    $query = "UPDATE stores SET store_name = ?, location = ?, contact = ?, manager = ?, status = ?, operating_hours = ? WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, 'ssssssi', $store_name_val, $store_location_val, $store_contact_val, $store_manager_val, $store_status_val, $store_hours_val, $form_store_id);
                }
                if (mysqli_stmt_execute($stmt)) {
                    $user_msg_success = $_POST['action'] === 'add' ? 'Store added successfully!' : 'Store updated successfully!';
                } else {
                    $user_msg_error = 'Error saving store: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt);
            } else {
                $user_msg_error = 'Please fill in all required fields.';
            }
        }
    } else {
        $user_msg_error = 'Invalid form action.';
        error_log('No action specified in POST request: ' . print_r($_POST, true));
    }
}

// Fetch stores
$db_query_stores = "SELECT id, store_name, location, contact, manager, status, operating_hours FROM stores ORDER BY id DESC";
$db_result_stores = mysqli_query($conn, $db_query_stores);

if (!$db_result_stores) {
    $user_msg_error = 'Error fetching stores: ' . mysqli_error($conn);
    $list_stores = [];
} else {
    $list_stores = [];
    while ($row = mysqli_fetch_assoc($db_result_stores)) {
        $store_id = $row['id'] ?? '';
        $encrypted_id = $store_id ? encryptId($store_id) : '';
        if ($encrypted_id === false) {
            error_log("Failed to encrypt store ID: $store_id");
        }
        $list_stores[] = [
            'id' => $store_id,
            'encrypted_id' => $encrypted_id,
            'store_name' => $row['store_name'] ?? '',
            'location' => $row['location'] ?? '',
            'contact' => $row['contact'] ?? '',
            'manager' => $row['manager'] ?? '',
            'status' => $row['status'] ?? 'Inactive',
            'operating_hours' => $row['operating_hours'] ?? ''
        ];
    }
    error_log('Fetched stores: ' . print_r($list_stores, true));
}

// Debug: Display raw data
$debug_data = print_r($list_stores, true);

// Log before header include
error_log('Before header include: list_stores = ' . print_r($list_stores, true));

// Include header
require_once __DIR__ . '/includes/header.php';

// Log after header include
error_log('After header include: list_stores = ' . print_r($list_stores, true));
?>

<main class="max-w-7xl mx-auto p-6">
    <?php if ($user_msg_success): ?>
        <div class="mb-6 p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
            <i data-feather="check-circle" class="w-5 h-5 mr-2"></i>
            <?php echo htmlspecialchars($user_msg_success, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php elseif ($user_msg_error): ?>
        <div class="mb-6 p-4 bg-red-100 text-red-700 rounded-lg flex items-center">
            <i data-feather="alert-circle" class="w-5 h-5 mr-2"></i>
            <?php echo htmlspecialchars($user_msg_error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-semibold">Store Management</h2>
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <input type="text" id="search-stores" placeholder="Search stores..." class="pl-10 pr-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary" oninput="filterStores()">
                    <i data-feather="search" class="absolute left-3 top-1/2 transform -translate-y-1/2 text-neutral"></i>
                </div>
                <select id="status-filter" class="p-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm" onchange="filterStores()">
                    <option value="">All Statuses</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
                <button onclick="openModal('add')" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                    <i data-feather="plus" class="w-4 h-4 mr-2"></i> Add Store
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="p-3 text-left font-medium">Store Name</th>
                        <th class="p-3 text-left font-medium">Location</th>
                        <th class="p-3 text-left font-medium">Contact</th>
                        <th class="p-3 text-left font-medium">Manager</th>
                        <th class="p-3 text-left font-medium">Status</th>
                        <th class="p-3 text-left font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody id="store-table">
                    <?php if (empty($list_stores)): ?>
                        <tr>
                            <td colspan="6" class="p-3 text-center text-gray-500">No stores found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($list_stores as $store_data): ?>
                            <tr class="table-row border-b border-gray-200 dark:border-gray-700" data-status="<?php echo htmlspecialchars(isset($store_data['status']) ? $store_data['status'] : 'Inactive', ENT_QUOTES, 'UTF-8'); ?>">
                                <td class="p-3"><?php echo htmlspecialchars(isset($store_data['store_name']) ? $store_data['store_name'] : '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars(isset($store_data['location']) && $store_data['location'] !== '' ? $store_data['location'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars(isset($store_data['contact']) && $store_data['contact'] !== '' ? $store_data['contact'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars(isset($store_data['manager']) && $store_data['manager'] !== '' ? $store_data['manager'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-3">
                                    <?php
                                        $status_class = (isset($store_data['status']) && $store_data['status'] === 'Active') ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
                                    ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars(isset($store_data['status']) ? $store_data['status'] : 'Inactive', ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td class="p-3 flex space-x-2">
                                    <button onclick='openModal("edit", <?php echo json_encode($store_data); ?>)' class="text-primary hover:text-primary/80">
                                        <i data-feather="edit" class="w-5 h-5"></i>
                                    </button>
                                    
                                    <button 
                                        onclick="confirmStoreDelete(
                                            '<?php echo htmlspecialchars(isset($store_data['encrypted_id']) ? $store_data['encrypted_id'] : '', ENT_QUOTES, 'UTF-8'); ?>', 
                                            '<?php echo htmlspecialchars(isset($store_data['store_name']) ? $store_data['store_name'] : 'Unknown Store', ENT_QUOTES, 'UTF-8'); ?>'
                                        );" 
                                        class="text-red-500 hover:text-red-600"
                                        type="button"
                                        <?php echo (isset($store_data['encrypted_id']) && ($store_data['encrypted_id'] === false || $store_data['encrypted_id'] === '')) ? 'disabled title="Encryption failed for this ID"' : ''; ?>
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
</main>

<div id="store-delete-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden modal">
    <div class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 w-full max-w-md">
        <h2 class="text-lg font-semibold mb-4">Confirm Store Delete</h2>
        <p id="store-delete-message" class="text-sm text-gray-500 dark:text-gray-400 mb-4">Are you sure you want to delete this store? This action cannot be undone and may fail if users are linked to it.</p>
        <form id="store-delete-form" method="GET" action="stores.php">
            <input type="hidden" name="delete" id="store-delete-encrypted-id">
            <div class="flex space-x-4">
                <button type="submit" class="bg-red-500 text-white px-6 py-2 rounded-lg hover:bg-red-600 transition flex items-center">
                    <i data-feather="trash-2" class="w-4 h-4 mr-2"></i> Delete Store
                </button>
                <button type="button" onclick="closeStoreDeleteModal()" class="bg-neutral text-white px-6 py-2 rounded-lg hover:bg-neutral/90 transition flex items-center">
                    <i data-feather="x" class="w-4 h-4 mr-2"></i> Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<div id="store-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden modal">
    <div class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 w-full max-w-lg modal-content">
        <h2 id="modal-title" class="text-lg font-semibold mb-4"></h2>
        <form id="store-form" method="POST" action="stores.php" class="space-y-4">
            <input type="hidden" name="action" id="form-action">
            <input type="hidden" name="store_id" id="store-id">
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Store Name <span class="text-red-500">*</span></label>
                <input type="text" name="store_name" id="store-name" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Location <span class="text-red-500">*</span></label>
                <input type="text" name="location" id="store-location" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Contact</label>
                <input type="text" name="contact" id="store-contact" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Manager</label>
                <input type="text" name="manager" id="store-manager" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Status <span class="text-red-500">*</span></label>
                <select name="status" id="store-status" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Operating Hours</label>
                <input type="text" name="operating_hours" id="store-operating-hours" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" placeholder="e.g., 9:00 AM - 9:00 PM">
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

<script>
    // Initialize Feather Icons
    feather.replace();

    // Modal handling
    function openModal(action, store = {}) {
        const modal = document.getElementById('store-modal');
        const title = document.getElementById('modal-title');
        document.getElementById('form-action').value = action;
        title.textContent = action === 'add' ? 'Add Store' : 'Edit Store';
        
        document.getElementById('store-id').value = store.id || '';
        document.getElementById('store-name').value = store.store_name || '';
        document.getElementById('store-location').value = store.location || '';
        document.getElementById('store-contact').value = store.contact || '';
        document.getElementById('store-manager').value = store.manager || '';
        document.getElementById('store-status').value = store.status || 'Active';
        document.getElementById('store-operating-hours').value = store.operating_hours || '';
        
        modal.classList.remove('hidden');
        console.log('Opened store modal for action:', action, 'Store:', store);
        feather.replace();
    }

    function closeModal() {
        document.getElementById('store-modal').classList.add('hidden');
        document.getElementById('store-form').reset();
        console.log('Closed store modal');
    }

    // **NEW: Store Delete Confirmation modal handling**
    function confirmStoreDelete(encryptedId, storeName) {
        const modal = document.getElementById('store-delete-modal');
        document.getElementById('store-delete-encrypted-id').value = encryptedId;
        document.getElementById('store-delete-message').textContent = `Are you sure you want to delete the store "${storeName}"? This action cannot be undone and may fail if users are linked to it.`;
        
        if (!encryptedId) {
            alert('Error: Cannot delete. The store ID could not be encrypted.');
            return;
        }

        modal.classList.remove('hidden');
        console.log('Opening store delete modal for encrypted_id:', encryptedId, 'Store Name:', storeName);
        feather.replace();
    }

    function closeStoreDeleteModal() {
        document.getElementById('store-delete-modal').classList.add('hidden');
        document.getElementById('store-delete-encrypted-id').value = '';
        console.log('Closed store delete modal');
    }

    // Store filtering
    function filterStores() {
        const search = document.getElementById('search-stores').value.toLowerCase();
        const status = document.getElementById('status-filter').value;
        const rows = document.querySelectorAll('#store-table tr');
        rows.forEach(row => {
            // Corrected potential error: safely access children and textContent
            const name = row.children[0]?.textContent.toLowerCase() || ''; 
            const location = row.children[1]?.textContent.toLowerCase() || '';
            const rowStatus = row.getAttribute('data-status') || '';
            const matchesSearch = name.includes(search) || location.includes(search);
            const matchesStatus = !status || rowStatus === status;
            row.style.display = matchesSearch && matchesStatus ? '' : 'none';
        });
    }
</script>

<?php
// Include footer
require_once __DIR__ . '/includes/footer.php';
?>