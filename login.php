<?php
require_once __DIR__ . '/config.php';

$page_title = 'Login';
$error_message = ''; // Initialize to prevent undefined variable error

// Debug: Log session start
error_log("Session ID at login.php start: " . session_id());

// Check if settings table exists
$query = "SHOW TABLES LIKE 'settings'";
$result = mysqli_query($conn, $query);
$settings_table_exists = mysqli_num_rows($result) > 0;
mysqli_free_result($result);

// Fetch store name from settings table
$app_name = 'Best Cobb Shop'; // Default
if ($settings_table_exists) {
    $query = 'SELECT store_name FROM settings LIMIT 1';
    $result = mysqli_query($conn, $query);
    if ($result) {
        if ($row = mysqli_fetch_assoc($result)) {
            if (!empty($row['store_name'])) {
                $app_name = htmlspecialchars($row['store_name'], ENT_QUOTES, 'UTF-8');
            }
        }
        mysqli_free_result($result);
    } else {
        error_log("Error fetching store_name: " . mysqli_error($conn));
        $error_message = 'Database error fetching store name. Using default.';
    }
} else {
    error_log("Settings table does not exist.");
    $error_message = 'Settings table not found. Using default store name.';
}

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    error_log("User already logged in: user_id={$_SESSION['user_id']}, role={$_SESSION['role']}");
    // Redirect based on role
    $role = $_SESSION['role'];
    if ($role === 'admin') {
        header('Location: index.php');
    } elseif ($role === 'cashier') {
        header('Location: sales.php');
    } elseif ($role === 'manager') {
        header('Location: reports.php');
    }
    exit;
}

// Initialize CSRF token (reuse if exists)
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
error_log("CSRF token generated: $csrf_token");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: Log submitted CSRF token
    $submitted_token = $_POST['csrf_token'] ?? 'none';
    error_log("CSRF token submitted: $submitted_token");

    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Invalid CSRF token. Please refresh the page and try again.';
        error_log("CSRF token mismatch: Expected {$_SESSION['csrf_token']}, Received $submitted_token");
    } else {
        $username = htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8');
        $password = $_POST['password'] ?? '';

        $errors = [];

        // Validation
        if (empty($username)) {
            $errors[] = 'Username is required.';
        }
        if (empty($password)) {
            $errors[] = 'Password is required.';
        }

        if (empty($errors)) {
            // Check user credentials
            $query = 'SELECT id, username, `password`, `role`, store_id FROM users WHERE username = ?';
            $stmt = mysqli_prepare($conn, $query);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $username);
                if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    $user = mysqli_fetch_assoc($result);
                    mysqli_stmt_close($stmt);

                    if ($user && password_verify($password, $user['password'])) {
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['store_id'] = $user['store_id'];

                        // Regenerate session ID to prevent session fixation
                        $old_session_id = session_id();
                        session_regenerate_id(true);
                        error_log("Session ID regenerated: Old=$old_session_id, New=" . session_id());

                        // Clear CSRF token after successful login
                        unset($_SESSION['csrf_token']);

                        // Redirect based on role
                        if ($user['role'] === 'admin') {
                            header('Location: index.php');
                        } elseif ($user['role'] === 'cashier') {
                            header('Location: sales.php');
                        } elseif ($user['role'] === 'manager') {
                            header('Location: reports.php');
                        }
                        exit;
                    } else {
                        $error_message = 'Invalid username or password.';
                        error_log("Login failed for username: $username. User exists: " . ($user ? 'Yes' : 'No') . ", Password valid: " . ($user ? (password_verify($password, $user['password']) ? 'Yes' : 'No') : 'N/A'));
                    }
                } else {
                    $error_message = 'Database error during query execution. Please try again later.';
                    error_log("Error executing user query: " . mysqli_error($conn));
                }
            } else {
                $error_message = 'Database error preparing query. Please try again later.';
                error_log("Error preparing user query: " . mysqli_error($conn));
            }
        } else {
            $error_message = implode('<br>', $errors);
        }
    }
}

// Include minimal header (no menu)
require_once __DIR__ . '/includes/header_minimal.php';
?>

<div class="flex items-center justify-center h-screen bg-secondary">
    <div class="card bg-white p-8 rounded-lg shadow-lg w-full max-w-md">
        <h1 class="text-3xl font-bold text-center text-primary mb-4"><?php echo htmlspecialchars($app_name); ?></h1>
        <h2 class="text-2xl font-semibold text-center text-neutral mb-6">Login</h2>
        
        <?php if ($error_message): ?>
            <div id="error-message" class="mb-6 p-4 bg-red-100 text-red-700 rounded-lg flex items-center">
                <i data-feather="alert-circle" class="w-5 h-5 mr-2"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            
            <div>
                <label class="block text-sm font-medium text-neutral">Username</label>
                <input type="text" name="username" class="mt-1 w-full p-2 border border-gray-200 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-primary" required>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-neutral">Password</label>
                <input type="password" name="password" class="mt-1 w-full p-2 border border-gray-200 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-primary" required>
            </div>
            
            <button type="submit" class="w-full bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition flex items-center justify-center">
                <i data-feather="log-in" class="w-4 h-4 mr-2"></i> Log In
            </button>
        </form>
        
        <p class="mt-4 text-center text-sm text-neutral">
            Forgot your password? <a href="reset_password.php" class="text-primary hover:underline">Reset it</a>
        </p>
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