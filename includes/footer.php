<?php
// footer.php
?>
                <!-- Modals -->
                <div id="checkout-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden modal">
                    <div class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 w-full max-w-lg modal-content">
                        <h2 class="text-lg font-semibold mb-4">Checkout</h2>
                        <form id="checkout-form" method="POST" action="sales.php" class="space-y-4">
                            <input type="hidden" name="action" value="checkout">
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Customer Name <span class="text-red-500">*</span></label>
                                <input type="text" name="customer_name" id="checkout-customer-name" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Payment Method <span class="text-red-500">*</span></label>
                                <select name="payment_method" id="payment-method" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" required>
                                    <option value="Cash">Cash</option>
                                    <option value="Card">Card</option>
                                    <option value="Mobile">Mobile</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Date <span class="text-red-500">*</span></label>
                                <input type="date" name="date" id="checkout-date" value="<?php echo date('Y-m-d'); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Total Amount <span class="text-red-500">*</span></label>
                                <input type="text" id="checkout-total" value="$<?php echo number_format(array_sum(array_column($_SESSION['cart'], 'subtotal')), 2); ?>" class="mt-1 w-full p-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 focus:outline-none" readonly>
                            </div>
                            <div class="flex space-x-4">
                                <button type="submit" class="bg-primary text-white px-6 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                                    <i data-feather="check" class="w-4 h-4 mr-2"></i> Complete Checkout
                                </button>
                                <button type="button" onclick="closeCheckoutModal()" class="bg-neutral text-white px-6 py-2 rounded-lg hover:bg-neutral/90 transition flex items-center">
                                    <i data-feather="x" class="w-4 h-4 mr-2"></i> Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="sales-delete-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden modal">
                    <div class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 w-full max-w-md">
                        <h2 class="text-lg font-semibold mb-4">Confirm Delete</h2>
                        <p id="sales-delete-message" class="text-sm text-gray-500 dark:text-gray-400 mb-4"></p>
                        <form id="sales-delete-form" method="POST" action="sales.php">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="transaction_id" id="sales-delete-transaction-id">
                            <div class="flex space-x-4">
                                <button type="submit" class="bg-red-500 text-white px-6 py-2 rounded-lg hover:bg-red-600 transition flex items-center">
                                    <i data-feather="trash-2" class="w-4 h-4 mr-2"></i> Delete
                                </button>
                                <button type="button" onclick="closeSalesDeleteModal()" class="bg-neutral text-white px-6 py-2 rounded-lg hover:bg-neutral/90 transition flex items-center">
                                    <i data-feather="x" class="w-4 h-4 mr-2"></i> Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="receipt-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden modal">
                    <div id="receipt" class="bg-white dark:bg-gray-800 p-6 rounded-lg border border-gray-200 dark:border-gray-700 w-full max-w-md">
                        <h2 class="text-lg font-semibold mb-4 text-center">Receipt</h2>
                        <div id="receipt-content" class="text-sm">
                            <p class="font-medium">Mall POS</p>
                            <p class="text-gray-500 dark:text-gray-400">Transaction ID: <span id="receipt-id"></span></p>
                            <p class="text-gray-500 dark:text-gray-400">Date: <span id="receipt-date"></span></p>
                            <p class="text-gray-500 dark:text-gray-400">Store: <span id="receipt-store"></span></p>
                            <p class="text-gray-500 dark:text-gray-400">Customer: <span id="receipt-customer"></span></p>
                            <p class="text-gray-500 dark:text-gray-400">Payment Method: <span id="receipt-payment"></span></p>
                            <table class="w-full mt-4 border-t border-gray-200 dark:border-gray-700">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <th class="p-2 text-left font-medium">Product</th>
                                        <th class="p-2 text-left font-medium">Quantity</th>
                                        <th class="p-2 text-left font-medium">Price</th>
                                        <th class="p-2 text-left font-medium">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody id="receipt-items">
                                </tbody>
                                <tfoot>
                                    <tr class="border-t border-gray-200 dark:border-gray-700">
                                        <td colspan="3" class="p-2 font-medium">Total</td>
                                        <td class="p-2 font-medium" id="receipt-total"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div class="flex space-x-4 mt-4">
                            <button onclick="printReceiptContent()" class="bg-primary text-white px-6 py-2 rounded-lg hover:bg-primary/90 transition flex items-center">
                                <i data-feather="printer" class="w-4 h-4 mr-2"></i> Print
                            </button>
                            <button onclick="closeReceiptModal()" class="bg-neutral text-white px-6 py-2 rounded-lg hover:bg-neutral/90 transition flex items-center">
                                <i data-feather="x" class="w-4 h-4 mr-2"></i> Close
                            </button>
                        </div>
                    </div>
                </div>
            </main>
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

        // Checkout modal handling
        function openCheckoutModal() {
            const cartTotal = document.getElementById('cart-total').textContent;
            document.getElementById('checkout-total').value = '$' + cartTotal;
            document.getElementById('checkout-modal').classList.remove('hidden');
            feather.replace();
        }

        function closeCheckoutModal() {
            document.getElementById('checkout-modal').classList.add('hidden');
        }

        // Delete confirmation modal for sales
        function confirmDelete(id, customer_name) {
            const modal = document.getElementById('sales-delete-modal');
            document.getElementById('sales-delete-transaction-id').value = id;
            document.getElementById('sales-delete-message').textContent = `Are you sure you want to delete the sale for "${customer_name}"? This action cannot be undone.`;
            modal.classList.remove('hidden');
            console.log('Opening sales delete modal for transaction_id:', id, 'Customer:', customer_name);
            feather.replace();
        }

        function closeSalesDeleteModal() {
            document.getElementById('sales-delete-modal').classList.add('hidden');
            document.getElementById('sales-delete-transaction-id').value = '';
            console.log('Closed sales delete modal');
        }

        // Receipt modal handling
        function printReceipt(sale) {
            document.getElementById('receipt-id').textContent = sale.id;
            document.getElementById('receipt-date').textContent = sale.date;
            document.getElementById('receipt-store').textContent = sale.store_name;
            document.getElementById('receipt-customer').textContent = sale.customer_name;
            document.getElementById('receipt-payment').textContent = sale.payment_method || 'N/A';
            document.getElementById('receipt-items').innerHTML = `
                <tr>
                    <td class="p-2">${sale.product_name}</td>
                    <td class="p-2">${sale.quantity}</td>
                    <td class="p-2">$${parseFloat(sale.amount / sale.quantity).toFixed(2)}</td>
                    <td class="p-2">$${parseFloat(sale.amount).toFixed(2)}</td>
                </tr>`;
            document.getElementById('receipt-total').textContent = `$${parseFloat(sale.amount).toFixed(2)}`;
            document.getElementById('receipt-modal').classList.remove('hidden');
            feather.replace();
        }

        function printReceiptContent() {
            window.print();
        }

        function closeReceiptModal() {
            document.getElementById('receipt-modal').classList.add('hidden');
        }

        // Sales filtering
        function filterSales() {
            const search = document.getElementById('search-sales').value.toLowerCase();
            const store = document.getElementById('store-filter').value;
            const status = document.getElementById('status-filter').value;
            const rows = document.querySelectorAll('#sales-table tr');

            rows.forEach(row => {
                const customer = row.children[3].textContent.toLowerCase();
                const product = row.children[2].textContent.toLowerCase();
                const rowStore = row.getAttribute('data-store');
                const rowStatus = row.getAttribute('data-status');
                const matchesSearch = customer.includes(search) || product.includes(search);
                const matchesStore = !store || rowStore === store;
                const matchesStatus = !status || rowStatus === status;
                row.style.display = matchesSearch && matchesStore && matchesStatus ? '' : 'none';
            });
        }

        // Log sales delete form submission
        document.getElementById('sales-delete-form').addEventListener('submit', function(event) {
            const transactionId = document.getElementById('sales-delete-transaction-id').value;
            const form = document.getElementById('sales-delete-form');
            console.log('Submitting sales delete form with transaction_id:', transactionId);
            console.log('Form action:', form.getAttribute('action'));
            if (!transactionId) {
                console.error('Form submission error: No transaction_id provided.');
                event.preventDefault();
                alert('Error: No transaction ID provided for deletion.');
            }
        });
    </script>