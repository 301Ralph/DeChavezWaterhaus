<?php
include '../includes/connection.php';
session_start();

// Security check
if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'customer') {
    echo '<script>alert("Access denied. Customers only."); window.location = "../login.php";</script>';
    exit();
}

$userID = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Check if email is verified
$verifyCheck = $conn->prepare("SELECT email_verified FROM customers WHERE userID = ?");
$verifyCheck->bind_param("i", $userID);
$verifyCheck->execute();
$verifyResult = $verifyCheck->get_result()->fetch_assoc();
$verifyCheck->close();

$isEmailVerified = $verifyResult['email_verified'] ?? 0;

// Fetch products with stock information
$productsResult = $conn->query("
    SELECT *, 
           COALESCE(Stock, 0) as current_stock,
           COALESCE(ImageURL, '') as product_image
    FROM product 
    WHERE Status = 'Active'
    ORDER BY ProductName ASC
");

// Handle order submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order'])) {
    if (!$isEmailVerified) {
        echo '<script>alert("Please verify your email address first before placing an order."); window.location = "profile.php";</script>';
        exit();
    }
    
    $productID = intval($_POST['productID']);
    $quantity = intval($_POST['quantity']);
    $deliveryOption = $_POST['delivery_option'] ?? 'today';
    $deliveryDate = $_POST['delivery_date'] ?? date('Y-m-d');
    $paymentMethod = $_POST['payment_method'];
    $gcashReceipt = '';

    // Validate quantity
    if ($quantity < 6 || $quantity > 100) {
        echo '<script>alert("Quantity must be between 6 and 100."); window.location = "products.php";</script>';
        exit();
    }

    // Check stock availability
    $stockCheck = $conn->prepare("
        SELECT COALESCE(Stock, 0) as current_stock, 
               ProductName, Price 
        FROM product 
        WHERE ProductID = ?
    ");
    $stockCheck->bind_param("i", $productID);
    $stockCheck->execute();
    $productInfo = $stockCheck->get_result()->fetch_assoc();
    $stockCheck->close();

    if ($productInfo['current_stock'] < $quantity) {
        echo '<script>alert("Sorry, only ' . $productInfo['current_stock'] . ' units available for ' . $productInfo['ProductName'] . '. Please reduce your quantity."); window.location = "products.php";</script>';
        exit();
    }

    // Handle GCash receipt upload
    if ($paymentMethod == 'GCash') {
        if (isset($_FILES['gcash_receipt']) && $_FILES['gcash_receipt']['error'] == 0) {
            $target_dir = "../uploads/receipts/";
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES["gcash_receipt"]["name"]));
            $target_file = $target_dir . $fileName;
            $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
            
            if (in_array($imageFileType, ['jpg', 'jpeg', 'png', 'gif'])) {
                if (move_uploaded_file($_FILES["gcash_receipt"]["tmp_name"], $target_file)) {
                    $gcashReceipt = 'uploads/receipts/' . $fileName;
                } else {
                    echo '<script>alert("Failed to upload GCash receipt. Please try again."); window.location = "products.php";</script>';
                    exit();
                }
            } else {
                echo '<script>alert("Invalid file type. Only JPG, PNG, GIF allowed."); window.location = "products.php";</script>';
                exit();
            }
        } else {
            echo '<script>alert("Please upload your GCash receipt."); window.location = "products.php";</script>';
            exit();
        }
    }

    // Calculate total amount
    $totalAmount = $productInfo['Price'] * $quantity;

    // Set delivery date
    if ($deliveryOption == 'today') {
        $deliveryDate = date('Y-m-d');
    }

    // Insert order using prepared statement
    // Store GCash receipt path in 'notes' column (since orders table doesn't have gcash_receipt column)
    $orderNotes = ($paymentMethod == 'GCash' && !empty($gcashReceipt)) ? "GCash Receipt: " . $gcashReceipt : "";
    
    $insertOrder = $conn->prepare("
        INSERT INTO orders (userID, order_date, total_amount, status, payment_method, notes, delivery_address) 
        VALUES (?, NOW(), ?, 'Pending', ?, ?, ?)
    ");
    $deliveryAddress = "Customer Address"; // You can fetch from user profile
    $insertOrder->bind_param("idsss", $userID, $totalAmount, $paymentMethod, $orderNotes, $deliveryAddress);
    
    if ($insertOrder->execute()) {
        $orderID = $conn->insert_id;
        $insertOrder->close();

        // Insert order item
        $insertItem = $conn->prepare("
            INSERT INTO order_items (orderID, productID, quantity, unit_price) 
            VALUES (?, ?, ?, ?)
        ");
        $insertItem->bind_param("iiid", $orderID, $productID, $quantity, $productInfo['Price']);
        $insertItem->execute();
        $insertItem->close();

        // Deduct stock from product
        $updateStock = $conn->prepare("
            UPDATE product 
            SET Stock = COALESCE(Stock, 0) - ? 
            WHERE ProductID = ?
        ");
        $updateStock->bind_param("ii", $quantity, $productID);
        $updateStock->execute();
        $updateStock->close();

        // Insert delivery record
        $insertDelivery = $conn->prepare("
            INSERT INTO deliveries (orderID, delivery_date, status) 
            VALUES (?, ?, 'Pending')
        ");
        $insertDelivery->bind_param("is", $orderID, $deliveryDate);
        $insertDelivery->execute();
        $insertDelivery->close();

        // Create notification for customer
        $gcashInfo = ($paymentMethod == 'GCash') ? " (GCash payment - receipt uploaded)" : "";
        $message = "Your order #$orderID for " . $productInfo['ProductName'] . " (x$quantity) has been placed successfully!" . $gcashInfo . " Total: ₱" . number_format($totalAmount, 2);
        $notifStmt = $conn->prepare("INSERT INTO notifications (userID, message, type) VALUES (?, ?, 'order')");
        $notifStmt->bind_param("is", $userID, $message);
        $notifStmt->execute();
        $notifStmt->close();

        // Create notification for admin (optional)
        $adminNotif = $conn->prepare("INSERT INTO notifications (userID, message, type) VALUES (1, ?, 'admin_order')");
        $adminMsg = "New order #$orderID from customer ID $userID - " . $productInfo['ProductName'] . " (x$quantity)";
        $adminNotif->bind_param("s", $adminMsg);
        $adminNotif->execute();
        $adminNotif->close();

        echo '<script>alert("Order placed successfully! Order ID: #' . $orderID . '"); window.location = "order_history.php";</script>';
        exit();
    } else {
        echo '<script>alert("Failed to place order. Please try again."); window.location = "products.php";</script>';
        exit();
    }
}

// Fetch user data for profile picture
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
        
        .sidebar { 
            position: fixed; top: 0; left: 0; height: 100vh; width: 260px; 
            background: white; box-shadow: 2px 0 15px rgba(0,0,0,0.05); z-index: 1000; 
            transition: all 0.3s ease; 
        }
        .sidebar.collapsed { width: 80px; }
        .sidebar .logo { padding: 25px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #eee; }
        .sidebar .logo img { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; }
        .sidebar .nav-link { 
            color: #495057; padding: 14px 22px; display: flex; align-items: center; gap: 14px; 
            font-weight: 500; transition: all 0.3s ease; border-radius: 12px; margin: 4px 10px;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { 
            background-color: #f0f7ff; color: var(--primary); 
        }
        .sidebar .nav-link i { width: 22px; font-size: 1.1rem; }
        
        .main-content { margin-left: 260px; padding: 30px; transition: margin-left 0.3s ease; }
        .sidebar.collapsed ~ .main-content { margin-left: 80px; }
        
        .product-card { 
            background: white; border-radius: 20px; overflow: hidden; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.06); transition: all 0.3s ease;
            border: 1px solid #f0f0f0;
        }
        .product-card:hover { transform: translateY(-8px); box-shadow: 0 15px 35px rgba(0, 119, 182, 0.15); }
        .product-card img { height: 220px; object-fit: cover; background: #f8f9fa; }
        
        .section-title { font-weight: 700; color: #1e293b; margin-bottom: 25px; }

        .sidebar .nav-link {
            padding: 12px 18px;
            margin: 2px 8px;
            border-radius: 10px;
        }
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 3px;
        }
        
        .modal-content { border-radius: 20px; border: none; }
        .modal-header { background: linear-gradient(135deg, #0077B6, #023E8A); color: white; border-radius: 20px 20px 0 0; }

        .stock-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .stock-low { background: #fff3cd; color: #856404; }
        .stock-medium { background: #d4edda; color: #155724; }
        .stock-high { background: #cce5ff; color: #004085; }

        /* Mobile Responsive */
        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
        }
        
        @media (max-width: 576px) {
            .main-content { padding: 15px; }
            .product-card { margin-bottom: 15px; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo p-4 d-flex align-items-center gap-3 border-bottom">
            <img src="../images/logo.jpg" alt="Logo" style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover;">
            <span class="fw-bold fs-5">De Chavez Waterhaus</span>
        </div>
        
        <!-- Scrollable Menu -->
        <div class="px-3 mt-2" style="height: calc(100vh - 90px); overflow-y: auto; padding-bottom: 20px;">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="customer_dashboard.php" class="nav-link"><i class="fas fa-home me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="products.php" class="nav-link active"><i class="fas fa-box me-3"></i> <span>Products</span></a></li>
                <li class="nav-item"><a href="order_history.php" class="nav-link"><i class="fas fa-history me-3"></i> <span>Order History</span></a></li>
                <li class="nav-item"><a href="order_tracking.php" class="nav-link"><i class="fas fa-map-marker-alt me-3"></i> <span>Track Orders</span></a></li>
                <li class="nav-item"><a href="recurring_orders.php" class="nav-link"><i class="fas fa-redo me-3"></i> <span>Recurring Orders</span></a></li>
                <li class="nav-item"><a href="support_tickets.php" class="nav-link"><i class="fas fa-headset me-3"></i> <span>Support Tickets</span></a></li>
                <li class="nav-item"><a href="notifications.php" class="nav-link"><i class="fas fa-bell me-3"></i> <span>Notifications</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user me-3"></i> <span>Profile</span></a></li>
                
                <li class="nav-item mt-4"><a href="../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt me-3"></i> <span>Logout</span></a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        
        <!-- IMPROVED MOBILE NAVBAR -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">
                <!-- Hamburger Button -->
                <button class="btn btn-light d-lg-none me-3 shadow-sm" id="mobileToggle" style="width: 42px; height: 42px; border-radius: 12px;">
                    <i class="fas fa-bars"></i>
                </button>
                
                <div>
                    <h4 class="fw-bold mb-0">Our Products</h4>
                    <p class="text-muted mb-0 d-none d-sm-block">Choose from our premium water collection</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-sm" data-bs-toggle="dropdown">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center overflow-hidden" style="width: 38px; height: 38px;">
                            <?php if (!empty($user['profile_picture']) && file_exists("../" . $user['profile_picture'])): ?>
                                <img src="../<?php echo $user['profile_picture']; ?>" style="width: 38px; height: 38px; object-fit: cover;">
                            <?php else: ?>
                                <span class="fw-bold fs-6"><?php echo strtoupper(substr($userName, 0, 1)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="text-start d-none d-md-block">
                            <div class="fw-semibold"><?php echo htmlspecialchars($userName); ?></div>
                            <small class="text-muted">Customer</small>
                        </div>
                        <i class="fas fa-chevron-down fa-sm text-muted ms-1"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Products Grid -->
        <h5 class="section-title">Available Products</h5>
        
        <?php if ($productsResult->num_rows > 0): ?>
            <div class="row g-4">
                <?php while ($product = $productsResult->fetch_assoc()) { 
                    $stock = intval($product['current_stock']);
                    $stockClass = 'stock-high';
                    $stockText = 'In Stock';
                    
                    if ($stock <= 0) {
                        $stockClass = 'stock-low';
                        $stockText = 'Out of Stock';
                    } elseif ($stock < 10) {
                        $stockClass = 'stock-low';
                        $stockText = $stock . ' left';
                    } elseif ($stock < 30) {
                        $stockClass = 'stock-medium';
                        $stockText = $stock . ' left';
                    }
                    
                    // Handle image path
                    $imagePath = $product['product_image'];
                    if (empty($imagePath) || !file_exists('../' . $imagePath)) {
                        $imagePath = 'https://via.placeholder.com/400x220?text=' . urlencode($product['ProductName']);
                    } else {
                        $imagePath = '../' . $imagePath;
                    }
                ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="product-card h-100">
                            <img src="<?php echo $imagePath; ?>" class="card-img-top" alt="<?php echo htmlspecialchars($product['ProductName']); ?>">
                            <div class="card-body p-4">
                                <h5 class="fw-bold mb-2"><?php echo htmlspecialchars($product['ProductName']); ?></h5>
                                <p class="text-muted small mb-3"><?php echo htmlspecialchars($product['Description'] ?? 'Premium quality water'); ?></p>
                                
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <div>
                                        <span class="fw-bold text-primary fs-4">₱<?php echo number_format($product['Price'], 2); ?></span>
                                        <small class="text-muted">/ 5 Gallon</small>
                                    </div>
                                    <span class="stock-badge <?php echo $stockClass; ?>">
                                        <?php echo $stockText; ?>
                                    </span>
                                </div>
                                
                                <?php if ($stock > 0): ?>
                                    <button class="btn btn-primary w-100 py-2 fw-semibold rounded-pill" 
                                            data-bs-toggle="modal" data-bs-target="#orderModal"
                                            data-productid="<?php echo $product['ProductID']; ?>"
                                            data-productname="<?php echo htmlspecialchars($product['ProductName']); ?>"
                                            data-price="<?php echo $product['Price']; ?>"
                                            data-stock="<?php echo $stock; ?>">
                                        <i class="fas fa-shopping-cart me-2"></i> Order Now
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-secondary w-100 py-2 fw-semibold rounded-pill" disabled>
                                        <i class="fas fa-times me-2"></i> Out of Stock
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-box-open fa-4x text-muted mb-4"></i>
                <h5 class="fw-bold">No Products Available</h5>
                <p class="text-muted">Please check back later or contact support.</p>
            </div>
        <?php endif; ?>
        
    </div>

    <!-- Order Modal -->
    <div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="products.php" method="POST" enctype="multipart/form-data" id="orderForm">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Place Your Order</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="productID" id="productID">
                        
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Product</label>
                            <div id="modalProductName" class="fw-bold fs-5 text-primary"></div>
                            <div class="text-muted small" id="modalStockInfo"></div>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Quantity (5 Gallon)</label>
                                <input type="number" class="form-control" name="quantity" id="quantity" min="6" max="100" value="6" required>
                                <div class="form-text">Minimum 6 • Maximum 100</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Delivery Option</label>
                                <select class="form-select" name="delivery_option" id="delivery_option" required>
                                    <option value="today">Today (Same Day)</option>
                                    <option value="scheduled">Scheduled Delivery</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3 mt-3" id="deliveryDateContainer" style="display: none;">
                            <label class="form-label fw-semibold">Preferred Delivery Date</label>
                            <input type="date" class="form-control" name="delivery_date" id="delivery_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select class="form-select" name="payment_method" id="payment_method" required>
                                <option value="COD">Cash on Delivery (COD)</option>
                                <option value="GCash">GCash</option>
                            </select>
                        </div>
                        
                        <div class="mb-3" id="gcashDetails" style="display: none;">
                            <div class="alert alert-info py-2 px-3 mb-3">
                                <strong>GCash Number:</strong> 0950-200-1713<br>
                                <strong>Account Name:</strong> Romeo E. De Chavez
                            </div>
                            <label class="form-label fw-semibold">Upload GCash Receipt <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" name="gcash_receipt" accept="image/*" id="gcash_receipt">
                            <div class="form-text">Upload screenshot of your GCash payment</div>
                        </div>
                        
                        <div class="alert alert-warning py-2 px-3 mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Minimum Order:</strong> 6 gallons | <strong>Maximum:</strong> 100 gallons
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="place_order" class="btn btn-primary px-5 fw-semibold" id="confirmBtn">
                            <i class="fas fa-check me-2"></i> Confirm Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        
        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => {
                sidebar.classList.toggle('show');
            });
            
            document.addEventListener('click', function(e) {
                if (window.innerWidth < 992 && !sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            });
        }
        
        // Modal data population
        const orderModal = document.getElementById('orderModal');
        orderModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const productID = button.getAttribute('data-productid');
            const productName = button.getAttribute('data-productname');
            const price = button.getAttribute('data-price');
            const stock = parseInt(button.getAttribute('data-stock'));
            
            document.getElementById('productID').value = productID;
            document.getElementById('modalProductName').innerHTML = productName + ' <span class="text-muted fs-6">(₱' + parseFloat(price).toFixed(2) + '/gallon)</span>';
            document.getElementById('modalStockInfo').innerHTML = '<i class="fas fa-box me-1"></i> ' + stock + ' units available';
            
            // Set max quantity based on stock
            const qtyInput = document.getElementById('quantity');
            qtyInput.max = Math.min(100, stock);
            qtyInput.value = Math.min(6, stock);
            
            // Disable confirm button if out of stock
            document.getElementById('confirmBtn').disabled = (stock <= 0);
        });
        
        // Toggle delivery date
        const deliveryOption = document.getElementById('delivery_option');
        const deliveryDateContainer = document.getElementById('deliveryDateContainer');
        deliveryOption.addEventListener('change', function() {
            deliveryDateContainer.style.display = this.value === 'scheduled' ? 'block' : 'none';
            
            if (this.value === 'today') {
                document.getElementById('delivery_date').value = '<?php echo date('Y-m-d'); ?>';
            }
        });
        
        // Toggle GCash details
        const paymentMethod = document.getElementById('payment_method');
        const gcashDetails = document.getElementById('gcashDetails');
        const gcashReceipt = document.getElementById('gcash_receipt');
        
        paymentMethod.addEventListener('change', function() {
            if (this.value === 'GCash') {
                gcashDetails.style.display = 'block';
                gcashReceipt.setAttribute('required', 'required');
            } else {
                gcashDetails.style.display = 'none';
                gcashReceipt.removeAttribute('required');
            }
        });
        
        // Form validation
        document.getElementById('orderForm').addEventListener('submit', function(e) {
            const qty = parseInt(document.getElementById('quantity').value);
            const payment = document.getElementById('payment_method').value;
            const stock = parseInt(document.getElementById('modalStockInfo').innerText) || 0;
            
            if (qty < 6 || qty > 100) {
                e.preventDefault();
                alert('Quantity must be between 6 and 100.');
                return false;
            }
            
            if (payment === 'GCash' && !document.getElementById('gcash_receipt').value) {
                e.preventDefault();
                alert('Please upload your GCash receipt.');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>