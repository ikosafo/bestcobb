<?php
require_once __DIR__ . '/config.php';
$page_title = 'Settings';

// Check if user is logged in and has the correct role
if (!isset($_SESSION['role']) || $_SESSION['role'] == 'cashier') {
    header('Location: index.php'); // Redirect to Dashboard if Cashier tries to access
    exit;
}

// Load settings from database
$settings_query = "SELECT * FROM settings WHERE id = 1";
$settings_result = mysqli_query($conn, $settings_query);
$settings = mysqli_fetch_assoc($settings_result) ?: [
    'store_name' => 'Mall Supermarket POS',
    'address' => '123 Market St, Cityville',
    'contact' => '(123) 456-7890',
    'receipt_footer' => 'Thank you for shopping at our mall!',
    'receipt_header' => 'Mall Supermarket POS',
    'currency_symbol' => 'GHS',
    'low_stock_threshold' => 10,
    'printer' => 'Default Browser Printer',
    'payment_summary_alignment' => 'center',
    'session_timeout' => 30,
    'show_logo' => 0,
    'restock_alerts' => 0,
    'barcode_format' => 'UPC-A',
    'default_role' => 'admin',
    'restrict_cashier' => 0,
    'receipt_width' => 80,
    'auto_print' => 0,
    'accepted_payments' => 'Cash,Card,Mobile',
];

// Load tax rates from database
$tax_query = "SELECT name, rate FROM tax_rates";
$tax_result = mysqli_query($conn, $tax_query);
$settings['tax_rates'] = [];
while ($row = mysqli_fetch_assoc($tax_result)) {
    $settings['tax_rates'][] = ['name' => $row['name'], 'rate' => $row['rate']];
}

// Convert accepted_payments to array
$settings['accepted_payments'] = explode(',', $settings['accepted_payments'] ?? 'Cash,Card,Mobile');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize text inputs using htmlspecialchars to prevent XSS
    $settings['store_name'] = htmlspecialchars($_POST['store_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $settings['address'] = htmlspecialchars($_POST['address'] ?? '', ENT_QUOTES, 'UTF-8');
    $settings['contact'] = htmlspecialchars($_POST['contact'] ?? '', ENT_QUOTES, 'UTF-8');
    $settings['receipt_footer'] = htmlspecialchars($_POST['receipt_footer'] ?? '', ENT_QUOTES, 'UTF-8');
    $settings['receipt_header'] = htmlspecialchars($_POST['receipt_header'] ?? '', ENT_QUOTES, 'UTF-8');
    $settings['currency_symbol'] = htmlspecialchars($_POST['currency_symbol'] ?? '', ENT_QUOTES, 'UTF-8');
    $settings['printer'] = htmlspecialchars($_POST['printer'] ?? 'default', ENT_QUOTES, 'UTF-8');
    $settings['payment_summary_alignment'] = htmlspecialchars($_POST['payment_summary_alignment'] ?? 'center', ENT_QUOTES, 'UTF-8');
    $settings['barcode_format'] = htmlspecialchars($_POST['barcode_format'] ?? 'UPC-A', ENT_QUOTES, 'UTF-8');
    $settings['default_role'] = htmlspecialchars($_POST['default_role'] ?? 'admin', ENT_QUOTES, 'UTF-8');

    // Sanitize numeric inputs
    $settings['low_stock_threshold'] = filter_input(INPUT_POST, 'low_stock_threshold', FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 0]]);
    $settings['session_timeout'] = filter_input(INPUT_POST, 'session_timeout', FILTER_VALIDATE_INT, ['options' => ['default' => 30, 'min_range' => 1]]);
    $settings['receipt_width'] = filter_input(INPUT_POST, 'receipt_width', FILTER_VALIDATE_INT, ['options' => ['default' => 80, 'min_range' => 50]]);

    // Sanitize boolean inputs
    $settings['show_logo'] = isset($_POST['show_logo']) ? 1 : 0;
    $settings['restock_alerts'] = isset($_POST['restock_alerts']) ? 1 : 0;
    $settings['restrict_cashier'] = isset($_POST['restrict_cashier']) ? 1 : 0;
    $settings['auto_print'] = isset($_POST['auto_print']) ? 1 : 0;

    // Sanitize accepted_payments array
    $accepted_payments = (array)($_POST['accepted_payments'] ?? []);
    $settings['accepted_payments'] = array_map(function($value) {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }, $accepted_payments);

    // Handle tax rates
    $tax_names = $_POST['tax_name'] ?? [];
    $tax_rates = $_POST['tax_rate'] ?? [];
    $settings['tax_rates'] = [];
    for ($i = 0; $i < count($tax_names); $i++) {
        if (!empty($tax_names[$i]) && is_numeric($tax_rates[$i])) {
            $settings['tax_rates'][] = [
                'name' => htmlspecialchars($tax_names[$i], ENT_QUOTES, 'UTF-8'),
                'rate' => filter_var($tax_rates[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => 0]])
            ];
        }
    }

    // Validate required fields
    $errors = [];
    if (empty($settings['store_name'])) {
        $errors[] = "Store name is required.";
    }
    if (empty($settings['currency_symbol'])) {
        $errors[] = "Currency symbol is required.";
    }

    if (empty($errors)) {
        // FIX: The problem is here. mysqli_stmt_bind_param requires a variable by reference.
        // We must compute the string and store it in a variable first.
        $accepted_payments_string = implode(',', $settings['accepted_payments']); // NEW variable

        // Save settings to database
        $query = "REPLACE INTO settings (
            id, store_name, address, contact, receipt_header, receipt_footer, 
            currency_symbol, low_stock_threshold, printer, payment_summary_alignment, 
            session_timeout, show_logo, restock_alerts, barcode_format, default_role, 
            restrict_cashier, receipt_width, auto_print, accepted_payments
        ) VALUES (
            1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )";
        $stmt = mysqli_prepare($conn, $query);
        
        // Bind the new variable instead of the expression
        mysqli_stmt_bind_param(
            $stmt, 'ssssssisssiiisiiis',
            $settings['store_name'],
            $settings['address'],
            $settings['contact'],
            $settings['receipt_header'],
            $settings['receipt_footer'],
            $settings['currency_symbol'],
            $settings['low_stock_threshold'],
            $settings['printer'],
            $settings['payment_summary_alignment'],
            $settings['session_timeout'],
            $settings['show_logo'],
            $settings['restock_alerts'],
            $settings['barcode_format'],
            $settings['default_role'],
            $settings['restrict_cashier'],
            $settings['receipt_width'],
            $settings['auto_print'],
            $accepted_payments_string // This variable is now passed by reference
        );
        
        if (mysqli_stmt_execute($stmt)) {
            // Save tax rates
            mysqli_query($conn, "DELETE FROM tax_rates");
            foreach ($settings['tax_rates'] as $tax) {
                $tax_query = "INSERT INTO tax_rates (name, rate) VALUES (?, ?)";
                $tax_stmt = mysqli_prepare($conn, $tax_query);
                mysqli_stmt_bind_param($tax_stmt, 'sd', $tax['name'], $tax['rate']);
                mysqli_stmt_execute($tax_stmt);
                mysqli_stmt_close($tax_stmt);
            }

            $success_message = "Settings saved successfully!";
        } else {
            $errors[] = "Failed to save settings: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
}

// Include header
require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($success_message)): ?>
    <div id="success-message" class="mb-6 p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
        <i data-feather="check-circle" class="w-5 h-5 mr-2"></i>
        <?php echo htmlspecialchars($success_message); ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div id="error-message" class="mb-6 p-4 bg-red-100 text-red-700 rounded-lg">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Main Settings Form -->
<form method="POST" action="settings.php" class="space-y-6">
    <!-- Store Information -->
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="collapsible-header flex justify-between items-center p-4 -m-4 mb-4" onclick="toggleCollapsible('store-info')">
            <h2 class="text-lg font-semibold">Store Information</h2>
            <i data-feather="chevron-down" class="w-5 h-5"></i>
        </div>
        <div id="store-info" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Store Name</label>
                <input type="text" name="store_name" value="<?php echo htmlspecialchars($settings['store_name']); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Address</label>
                <textarea name="address" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary"><?php echo htmlspecialchars($settings['address']); ?></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Contact / Phone</label>
                <input type="text" name="contact" value="<?php echo htmlspecialchars($settings['contact']); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Store Logo</label>
                <input type="file" accept="image/*" name="store_logo" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700">
                <p class="text-xs text-neutral mt-1">Upload a logo for receipts (PNG/JPG, max 1MB).</p>
            </div>
        </div>
    </div>

    <!-- Receipt Customization -->
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="collapsible-header flex justify-between items-center p-4 -m-4 mb-4" onclick="toggleCollapsible('receipt-config')">
            <h2 class="text-lg font-semibold">Receipt Customization</h2>
            <i data-feather="chevron-down" class="w-5 h-5"></i>
        </div>
        <div id="receipt-config" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Receipt Header</label>
                <input type="text" name="receipt_header" value="<?php echo htmlspecialchars($settings['receipt_header']); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Receipt Footer</label>
                <textarea name="receipt_footer" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary"><?php echo htmlspecialchars($settings['receipt_footer']); ?></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Receipt Layout</label>
                <select name="payment_summary_alignment" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none">
                    <option value="left" <?php echo $settings['payment_summary_alignment'] == 'left' ? 'selected' : ''; ?>>Left</option>
                    <option value="center" <?php echo $settings['payment_summary_alignment'] == 'center' ? 'selected' : ''; ?>>Center</option>
                    <option value="right" <?php echo $settings['payment_summary_alignment'] == 'right' ? 'selected' : ''; ?>>Right</option>
                </select>
            </div>
            <div class="flex items-center">
                <input type="checkbox" name="show_logo" class="h-4 w-4 text-primary focus:ring-primary" <?php echo $settings['show_logo'] ? 'checked' : ''; ?>>
                <label class="ml-2 text-sm text-gray-500 dark:text-gray-400">Show logo on receipts</label>
            </div>
        </div>
    </div>

    <!-- Financial Settings -->
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="collapsible-header flex justify-between items-center p-4 -m-4 mb-4" onclick="toggleCollapsible('financial-settings')">
            <h2 class="text-lg font-semibold">Financial Settings</h2>
            <i data-feather="chevron-down" class="w-5 h-5"></i>
        </div>
        <div id="financial-settings" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Currency Symbol</label>
                <input type="text" name="currency_symbol" value="<?php echo htmlspecialchars($settings['currency_symbol']); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Tax Rates</label>
                <div id="tax-rates" class="space-y-2">
                    <?php foreach ($settings['tax_rates'] as $index => $tax): ?>
                        <div class="flex space-x-2">
                            <input type="text" name="tax_name[]" value="<?php echo htmlspecialchars($tax['name']); ?>" placeholder="Tax Name" class="w-1/2 p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary">
                            <input type="number" name="tax_rate[]" value="<?php echo htmlspecialchars($tax['rate']); ?>" placeholder="Rate (%)" step="0.01" class="w-1/2 p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary">
                            <button type="button" onclick="removeTaxRate(this)" class="p-2 text-red-500 hover:text-red-600"><i data-feather="trash-2" class="w-5 h-5"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" onclick="addTaxRate()" class="mt-2 text-sm text-primary hover:text-primary/80 flex items-center">
                    <i data-feather="plus" class="w-4 h-4 mr-1"></i> Add Tax Rate
                </button>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Accepted Payment Methods</label>
                <div class="flex flex-wrap gap-4 mt-1">
                    <?php
                    $payment_methods = ['Cash', 'Card', 'Mobile', 'Bank Transfer', 'Gift Card'];
                    foreach ($payment_methods as $method) {
                        $checked = in_array($method, $settings['accepted_payments']) ? 'checked' : '';
                        echo "<label class='flex items-center'>
                                <input type='checkbox' name='accepted_payments[]' value='$method' class='h-4 w-4 text-primary focus:ring-primary' $checked>
                                <span class='ml-2 text-sm text-gray-500 dark:text-gray-400'>$method</span>
                              </label>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Inventory Settings -->
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="collapsible-header flex justify-between items-center p-4 -m-4 mb-4" onclick="toggleCollapsible('inventory-settings')">
            <h2 class="text-lg font-semibold">Inventory Settings</h2>
            <i data-feather="chevron-down" class="w-5 h-5"></i>
        </div>
        <div id="inventory-settings" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Low Stock Threshold</label>
                <input type="number" name="low_stock_threshold" value="<?php echo htmlspecialchars($settings['low_stock_threshold']); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" min="0">
            </div>
            <div class="flex items-center">
                <input type="checkbox" name="restock_alerts" class="h-4 w-4 text-primary focus:ring-primary" <?php echo $settings['restock_alerts'] ? 'checked' : ''; ?>>
                <label class="ml-2 text-sm text-gray-500 dark:text-gray-400">Enable restock alerts</label>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Barcode Format</label>
                <select name="barcode_format" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none">
                    <option value="UPC-A" <?php echo $settings['barcode_format'] == 'UPC-A' ? 'selected' : ''; ?>>UPC-A</option>
                    <option value="EAN-13" <?php echo $settings['barcode_format'] == 'EAN-13' ? 'selected' : ''; ?>>EAN-13</option>
                    <option value="Code-128" <?php echo $settings['barcode_format'] == 'Code-128' ? 'selected' : ''; ?>>Code-128</option>
                </select>
            </div>
        </div>
    </div>

    <!-- User Management -->
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="collapsible-header flex justify-between items-center p-4 -m-4 mb-4" onclick="toggleCollapsible('user-management')">
            <h2 class="text-lg font-semibold">User Management</h2>
            <i data-feather="chevron-down" class="w-5 h-5"></i>
        </div>
        <div id="user-management" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Session Timeout (minutes)</label>
                <input type="number" name="session_timeout" value="<?php echo htmlspecialchars($settings['session_timeout']); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" min="1">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Default User Role</label>
                <select name="default_role" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none">
                    <option value="admin" <?php echo $settings['default_role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="cashier" <?php echo $settings['default_role'] == 'cashier' ? 'selected' : ''; ?>>Cashier</option>
                    <option value="manager" <?php echo $settings['default_role'] == 'manager' ? 'selected' : ''; ?>>Manager</option>
                </select>
            </div>
            <div class="flex items-center">
                <input type="checkbox" name="restrict_cashier" class="h-4 w-4 text-primary focus:ring-primary" <?php echo $settings['restrict_cashier'] ? 'checked' : ''; ?>>
                <label class="ml-2 text-sm text-gray-500 dark:text-gray-400">Restrict cashiers to sales only</label>
            </div>
        </div>
    </div>

    <!-- Printer & Hardware -->
    <div class="card bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="collapsible-header flex justify-between items-center p-4 -m-4 mb-4" onclick="toggleCollapsible('printer-settings')">
            <h2 class="text-lg font-semibold">Printer & Hardware</h2>
            <i data-feather="chevron-down" class="w-5 h-5"></i>
        </div>
        <div id="printer-settings" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Printer</label>
                <select name="printer" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none">
                    <option value="default" <?php echo $settings['printer'] == 'Default Browser Printer' ? 'selected' : ''; ?>>Default Browser Printer</option>
                    <option value="thermal" <?php echo $settings['printer'] == 'thermal' ? 'selected' : ''; ?>>Thermal Printer</option>
                    <option value="network" <?php echo $settings['printer'] == 'network' ? 'selected' : ''; ?>>Network Printer</option>
                </select>
                <p class="text-xs text-neutral mt-1">Final printer choice is made in your browser's print dialog for default settings.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Receipt Width (mm)</label>
                <input type="number" name="receipt_width" value="<?php echo htmlspecialchars($settings['receipt_width']); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" min="50">
            </div>
            <div class="flex items-center">
                <input type="checkbox" name="auto_print" class="h-4 w-4 text-primary focus:ring-primary" <?php echo $settings['auto_print'] ? 'checked' : ''; ?>>
                <label class="ml-2 text-sm text-gray-500 dark:text-gray-400">Auto-print receipts after sale</label>
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="flex space-x-4">
        <button type="submit" class="bg-primary text-white px-6 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
            <i data-feather="save" class="w-4 h-4 mr-2"></i> Save Settings
        </button>
        <button type="button" onclick="resetForm()" class="bg-neutral text-white px-6 py-2 rounded-lg hover:bg-neutral/90 transition flex items-center">
            <i data-feather="rotate-ccw" class="w-4 h-4 mr-2"></i> Reset
        </button>
    </div>
</form>

<script>
// Initialize Feather Icons
feather.replace();

// Collapsible sections
function toggleCollapsible(id) {
    const content = document.getElementById(id);
    const icon = content.previousElementSibling.querySelector('i');
    content.classList.toggle('hidden');
    icon.setAttribute('data-feather', content.classList.contains('hidden') ? 'chevron-down' : 'chevron-up');
    feather.replace();
}

// Dynamic tax rate fields
function addTaxRate() {
    const container = document.getElementById('tax-rates');
    const div = document.createElement('div');
    div.className = 'flex space-x-2';
    div.innerHTML = `
        <input type="text" name="tax_name[]" placeholder="Tax Name" class="w-1/2 p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary">
        <input type="number" name="tax_rate[]" placeholder="Rate (%)" step="0.01" class="w-1/2 p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary">
        <button type="button" onclick="removeTaxRate(this)" class="p-2 text-red-500 hover:text-red-600"><i data-feather="trash-2" class="w-5 h-5"></i></button>
    `;
    container.appendChild(div);
    feather.replace();
}

function removeTaxRate(button) {
    button.parentElement.remove();
}

// Form reset
function resetForm() {
    document.querySelector('form').reset();
    const taxRates = document.getElementById('tax-rates');
    while (taxRates.children.length > 1) {
        taxRates.removeChild(taxRates.lastChild);
    }
}
</script>

<?php
// Include footer
require_once __DIR__ . '/includes/footer.php';
?>