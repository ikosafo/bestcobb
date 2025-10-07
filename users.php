<?php
require_once __DIR__ . '/config.php';
$page_title = 'Users';

// Check if user is logged in and has the correct role
if (!isset($_SESSION['role']) || $_SESSION['role'] == 'cashier') {
    header('Location: index.php'); // Redirect to Dashboard if Cashier tries to access
    exit;
}

// Initialize variables
$success_message = '';
$error_message = '';
$edit_user = null;

// Ensure ENCRYPTION_KEY is defined
if (!defined('ENCRYPTION_KEY')) {
    die("Encryption key not defined in config.php");
}

// Encryption function
function encryptId($id) {
    $cipher = "AES-256-CBC";
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($cipher));
    $encrypted = openssl_encrypt($id, $cipher, ENCRYPTION_KEY, 0, $iv);
    return base64_encode($encrypted . '::' . base64_encode($iv));
}

// Decryption function
function decryptId($encrypted) {
    $parts = explode('::', base64_decode($encrypted));
    if (count($parts) !== 2) return false;
    list($encrypted_data, $iv) = $parts;
    $decrypted = openssl_decrypt($encrypted_data, "AES-256-CBC", ENCRYPTION_KEY, 0, base64_decode($iv));
    return $decrypted !== false ? (int)$decrypted : false;
}

// Handle form submission (Add/Edit User)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    $user_id = $_POST['user_id'] ?? null;

    // Sanitize and validate inputs
    $full_name = htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $username = htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8');
    $role = htmlspecialchars($_POST['role'] ?? '', ENT_QUOTES, 'UTF-8');
    $telephone = htmlspecialchars($_POST['telephone'] ?? '', ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8');
    $store_id = filter_input(INPUT_POST, 'store_id', FILTER_VALIDATE_INT);
    $password = $_POST['password'] ?? '';

    $errors = [];

    // Validation
    if (empty($full_name)) $errors[] = "Full name is required.";
    if (empty($username)) $errors[] = "Username is required.";
    if (empty($role) || !in_array($role, ['admin', 'cashier', 'manager'])) $errors[] = "Valid role is required.";
    if (empty($telephone)) $errors[] = "Telephone is required.";
    if (!$store_id) $errors[] = "Store is required.";
    if ($action === 'add' && empty($password)) $errors[] = "Password is required for new users.";

    // Check for unique username (exclude current user in edit mode)
    $unique_check_query = "SELECT id FROM users WHERE username = ? AND id != ?";
    $stmt_check = mysqli_prepare($conn, $unique_check_query);
    $check_id = $user_id ?? 0;
    mysqli_stmt_bind_param($stmt_check, 'si', $username, $check_id);
    mysqli_stmt_execute($stmt_check);
    mysqli_stmt_store_result($stmt_check);
    if (mysqli_stmt_num_rows($stmt_check) > 0) {
        $errors[] = "Username already exists.";
    }
    mysqli_stmt_close($stmt_check);

    // Check for unique telephone (exclude current user in edit mode)
    $unique_tel_query = "SELECT id FROM users WHERE telephone = ? AND id != ?";
    $stmt_tel = mysqli_prepare($conn, $unique_tel_query);
    mysqli_stmt_bind_param($stmt_tel, 'si', $telephone, $check_id);
    mysqli_stmt_execute($stmt_tel);
    mysqli_stmt_store_result($stmt_tel);
    if (mysqli_stmt_num_rows($stmt_tel) > 0) {
        $errors[] = "Telephone already exists.";
    }
    mysqli_stmt_close($stmt_tel);

    // Check for unique email (if provided, exclude current user in edit mode)
    if (!empty($email)) {
        $unique_email_query = "SELECT id FROM users WHERE email = ? AND id != ?";
        $stmt_email = mysqli_prepare($conn, $unique_email_query);
        mysqli_stmt_bind_param($stmt_email, 'si', $email, $check_id);
        mysqli_stmt_execute($stmt_email);
        mysqli_stmt_store_result($stmt_email);
        if (mysqli_stmt_num_rows($stmt_email) > 0) {
            $errors[] = "Email already exists.";
        }
        mysqli_stmt_close($stmt_email);
    }

    if (empty($errors)) {
        // Hash password if provided
        $hashed_password = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;

        if ($action === 'add') {
            // Insert new user
            $query = "INSERT INTO users (full_name, username, password, role, telephone, email, store_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, 'ssssssi', $full_name, $username, $hashed_password, $role, $telephone, $email, $store_id);
            if (mysqli_stmt_execute($stmt)) {
                $success_message = "User added successfully!";
            } else {
                $error_message = "Error adding user: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        } elseif ($action === 'edit') {
            // Update existing user
            if ($hashed_password) {
                $query = "UPDATE users SET full_name = ?, username = ?, password = ?, role = ?, telephone = ?, email = ?, store_id = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, 'ssssssii', $full_name, $username, $hashed_password, $role, $telephone, $email, $store_id, $user_id);
            } else {
                $query = "UPDATE users SET full_name = ?, username = ?, role = ?, telephone = ?, email = ?, store_id = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, 'sssssii', $full_name, $username, $role, $telephone, $email, $store_id, $user_id);
            }
            if (mysqli_stmt_execute($stmt)) {
                $success_message = "User updated successfully!";
            } else {
                $error_message = "Error updating user: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Handle delete action
if (isset($_GET['delete'])) {
    $encrypted_id = $_GET['delete'];
    $delete_id = decryptId($encrypted_id);
    if ($delete_id === false) {
        $error_message = "Invalid or tampered delete request.";
    } else {
        $query = "DELETE FROM users WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'i', $delete_id);
        if (mysqli_stmt_execute($stmt)) {
            $success_message = "User deleted successfully!";
        } else {
            $error_message = "Error deleting user: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
}

// Handle edit action (load user data)
if (isset($_GET['edit'])) {
    $edit_id = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    if ($edit_id) {
        $query = "SELECT * FROM users WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'i', $edit_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $edit_user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    }
}

// Fetch all users
$users_query = "SELECT u.*, s.store_name FROM users u LEFT JOIN stores s ON u.store_id = s.id ORDER BY u.created_at DESC";
$users_result = mysqli_query($conn, $users_query);
$users = $users_result ? mysqli_fetch_all($users_result, MYSQLI_ASSOC) : [];

// Fetch stores for dropdown
$stores_query = "SELECT id, store_name FROM stores ORDER BY store_name";
$stores_result = mysqli_query($conn, $stores_query);
$stores = $stores_result ? mysqli_fetch_all($stores_result, MYSQLI_ASSOC) : [];

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
        <?php echo $error_message; ?>
    </div>
<?php endif; ?>

<!-- User Form -->
<div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 mb-6">
    <h2 class="text-lg font-semibold mb-4"><?php echo $edit_user ? 'Edit User' : 'Add New User'; ?></h2>
    <form method="POST" action="users.php" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <input type="hidden" name="action" value="<?php echo $edit_user ? 'edit' : 'add'; ?>">
        <?php if ($edit_user): ?>
            <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
        <?php endif; ?>
        
        <div>
            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Full Name <span class="text-red-500">*</span></label>
            <input type="text" name="full_name" value="<?php echo htmlspecialchars($edit_user['full_name'] ?? ''); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" required>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Username <span class="text-red-500">*</span></label>
            <input type="text" name="username" value="<?php echo htmlspecialchars($edit_user['username'] ?? ''); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" required>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Password <?php echo $edit_user ? '' : '<span class="text-red-500">*</span>'; ?></label>
            <input type="password" name="password" placeholder="<?php echo $edit_user ? 'Leave blank to keep current password' : 'Default: Password123!'; ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" <?php echo $edit_user ? '' : 'required'; ?>>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Role <span class="text-red-500">*</span></label>
            <select name="role" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
                <option value="">Select Role</option>
                <option value="admin" <?php echo ($edit_user['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                <option value="cashier" <?php echo ($edit_user['role'] ?? '') === 'cashier' ? 'selected' : ''; ?>>Cashier</option>
                <option value="manager" <?php echo ($edit_user['role'] ?? '') === 'manager' ? 'selected' : ''; ?>>Manager</option>
            </select>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Telephone <span class="text-red-500">*</span></label>
            <input type="tel" name="telephone" value="<?php echo htmlspecialchars($edit_user['telephone'] ?? ''); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" required>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Email</label>
            <input type="email" name="email" value="<?php echo htmlspecialchars($edit_user['email'] ?? ''); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary">
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Store <span class="text-red-500">*</span></label>
            <select name="store_id" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
                <option value="">Select Store</option>
                <?php foreach ($stores as $store): ?>
                    <option value="<?php echo $store['id']; ?>" <?php echo ($edit_user['store_id'] ?? '') == $store['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($store['store_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="md:col-span-2">
            <button type="submit" class="bg-primary text-white px-6 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                <i data-feather="save" class="w-4 h-4 mr-2"></i> <?php echo $edit_user ? 'Update User' : 'Add User'; ?>
            </button>
            <?php if ($edit_user): ?>
                <a href="users.php" class="ml-2 bg-gray-500 text-white px-6 py-2 rounded-lg hover:bg-gray-600 transition flex items-center inline-flex">
                    <i data-feather="x" class="w-4 h-4 mr-2"></i> Cancel
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Users Table -->
<div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
    <h2 class="text-lg font-semibold mb-4">All Users</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="p-3 text-left font-medium">Full Name</th>
                    <th class="p-3 text-left font-medium">Username</th>
                    <th class="p-3 text-left font-medium">Role</th>
                    <th class="p-3 text-left font-medium">Telephone</th>
                    <th class="p-3 text-left font-medium">Email</th>
                    <th class="p-3 text-left font-medium">Store</th>
                    <th class="p-3 text-left font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <td class="p-3"><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($user['username']); ?></td>
                        <td class="p-3">
                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $user['role'] === 'admin' ? 'bg-purple-100 text-purple-700' : ($user['role'] === 'manager' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700'); ?>">
                                <?php echo ucfirst($user['role']); ?>
                            </span>
                        </td>
                        <td class="p-3"><?php echo htmlspecialchars($user['telephone']); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                        <td class="p-3"><?php echo htmlspecialchars($user['store_name'] ?? 'N/A'); ?></td>
                        <td class="p-3 flex space-x-2">
                            <a href="users.php?edit=<?php echo $user['id']; ?>" class="text-accent hover:text-accent/80">
                                <i data-feather="edit" class="w-5 h-5"></i>
                            </a>
                            <a href="users.php?delete=<?php echo urlencode(encryptId($user['id'])); ?>" class="text-red-500 hover:text-red-600" onclick="return confirm('Are you sure you want to delete this user?');">
                                <i data-feather="trash-2" class="w-5 h-5"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="7" class="p-3 text-center text-gray-500">No users found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // Initialize Feather Icons
    feather.replace();
</script>

<?php
// Include footer
require_once __DIR__ . '/includes/footer.php';
?>