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

// Fetch products
$productsResult = $conn->query("SELECT * FROM product WHERE Status = 'Active'");

// Handle order submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order'])) {
    if (!$isEmailVerified) {
        echo '<script>alert("Please verify your email address first before placing an order."); window.location = "profile.php";</script>';
        exit();
    }
    $productID = intval($_POST['productID']);
    $quantity = intval($_POST['quantity']);
    $deliveryDate = $_POST['delivery_date'];
    $paymentMethod = $_POST['payment_method'];
    $gcashReceipt = '';

    // Validate quantity
    if ($quantity < 6 || $quantity > 100) {
        echo '<script>alert("Quantity must be between 6 and 100."); window.location = "products.php";</script>';
        exit();
    }

    // Handle GCash receipt
    if ($paymentMethod == 'GCash') {
        if (isset($_FILES['gcash_receipt']) && $_FILES['gcash_receipt']['error'] == 0) {
            $target_dir = "../uploads/receipts/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            
            $target_file = $target_dir . time() . '_' . basename($_FILES["gcash_receipt"]["name"]);
            $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
            
            if (in_array($imageFileType, ['jpg', 'jpeg', 'png'])) {
                if (move_uploaded_file($_FILES["gcash_receipt"]["tmp_name"], $target_file)) {
                    $gcashReceipt = $target_file;
                }
            }
        }
    }

    // Get product price
    $product = $conn->query("SELECT Price FROM product WHERE ProductID = $productID")->fetch_assoc();
    $totalAmount = $product['Price'] * $quantity;

    // Insert order
    $conn->query("INSERT INTO orders (userID, order_date, total_amount, status, payment_method, gcash_receipt) 
                  VALUES ($userID, NOW(), $totalAmount, 'Pending', '$paymentMethod', '$gcashReceipt')");
    $orderID = $conn->insert_id;

    // Insert order item
    $conn->query("INSERT INTO order_items (orderID, productID, quantity, unit_price) 
                  VALUES ($orderID, $productID, $quantity, {$product['Price']})");

    // Insert delivery
    $conn->query("INSERT INTO deliveries (orderID, delivery_date, userID) VALUES ($orderID, '$deliveryDate', $userID)");

    // Create notification
    $message = "Your order #$orderID has been placed successfully!";
    $stmt = $conn->prepare("INSERT INTO notifications (userID, message) VALUES (?, ?)");
    $stmt->bind_param("is", $userID, $message);
    $stmt->execute();

    echo '<script>alert("Order placed successfully!"); window.location = "order_history.php";</script>';
    exit();
}
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
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
        
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: 260px; background: white; box-shadow: 2px 0 15px rgba(0,0,0,0.05); z-index: 1000; transition: all 0.3s ease; }
        .sidebar.collapsed { width: 80px; }
        .sidebar .logo { padding: 25px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #eee; }
        .sidebar .logo img { width: 42px; height: 42px; border-radius: 50%; }
        .sidebar .nav-link { color: #495057; padding: 14px 22px; display: flex; align-items: center; gap: 14px; font-weight: 500; transition: all 0.3s ease; border-radius: 12px; margin: 4px 10px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: #f0f7ff; color: var(--primary); }
        .sidebar .nav-link i { width: 22px; font-size: 1.1rem; }
        
        .main-content { margin-left: 260px; padding: 30px; transition: margin-left 0.3s ease; }
        .sidebar.collapsed ~ .main-content { margin-left: 80px; }
        
        .product-card { 
            background: white; border-radius: 20px; overflow: hidden; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.06); transition: all 0.3s ease;
            border: 1px solid #f0f0f0;
        }
        .product-card:hover { transform: translateY(-8px); box-shadow: 0 15px 35px rgba(0, 119, 182, 0.15); }
        .product-card img { height: 220px; object-fit: cover; }
        
        .section-title { font-weight: 700; color: #1e293b; margin-bottom: 25px; }
        
        .modal-content { border-radius: 20px; border: none; }
        .modal-header { background: linear-gradient(135deg, #0077B6, #023E8A); color: white; border-radius: 20px 20px 0 0; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <img src="../images/logo.png" alt="Logo">
            <span class="fw-bold fs-5">De Chavez Waterhaus</span>
        </div>
        
        <div class="px-3 mt-2">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="customer_dashboard.php" class="nav-link"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="products.php" class="nav-link active"><i class="fas fa-box"></i> <span>Products</span></a></li>
                <li class="nav-item">
                    <a href="orders.php" class="nav-link">
                        <i class="fas fa-shopping-cart"></i> <span>Place Order</span>
                    </a>
                </li>
                <li class="nav-item"><a href="order_history.php" class="nav-link"><i class="fas fa-history"></i> <span>Order History</span></a></li>
                <li class="nav-item">
                    <a href="order_tracking.php" class="nav-link">
                        <i class="fas fa-map-marker-alt"></i> <span>Track Orders</span>
                    </a>
                </li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user"></i> <span>Profile</span></a></li>
                <li class="nav-item mt-4"><a href="../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        
        <!-- Top Navbar -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">Our Products</h4>
                <p class="text-muted mb-0">Choose from our premium water collection</p>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="position-relative">
                    <button class="btn btn-light rounded-circle p-2 shadow-sm" style="width: 46px; height: 46px;">
                        <i class="fas fa-bell fa-lg text-secondary"></i>
                    </button>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">3</span>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-sm" data-bs-toggle="dropdown">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <span class="fw-bold fs-6"><?php echo strtoupper(substr($userName, 0, 1)); ?></span>
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
        
        <div class="row g-4">
            <?php while ($product = $productsResult->fetch_assoc()) { ?>
                <div class="col-md-6 col-lg-4">
                    <div class="product-card h-100">
                        <img src="<?php echo $product['ImageURL'] ?: 'https://via.placeholder.com/400x220?text=Water'; ?>" class="card-img-top" alt="<?php echo $product['ProductName']; ?>">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-2"><?php echo $product['ProductName']; ?></h5>
                            <p class="text-muted small mb-3"><?php echo $product['Description']; ?></p>
                            
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <div>
                                    <span class="fw-bold text-primary fs-4">₱<?php echo number_format($product['Price'], 2); ?></span>
                                    <small class="text-muted">/ 5 Gallon</small>
                                </div>
                                <span class="badge bg-success-subtle text-success px-3 py-2">In Stock</span>
                            </div>
                            
                            <button class="btn btn-primary w-100 py-2 fw-semibold rounded-pill" 
                                    data-bs-toggle="modal" data-bs-target="#orderModal"
                                    data-productid="<?php echo $product['ProductID']; ?>"
                                    data-productname="<?php echo $product['ProductName']; ?>"
                                    data-price="<?php echo $product['Price']; ?>">
                                <i class="fas fa-shopping-cart me-2"></i> Order Now
                            </button>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
        
    </div>

    <!-- Order Modal -->
    <div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="products.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Place Your Order</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="productID" id="productID">
                        
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Product</label>
                            <div id="modalProductName" class="fw-bold fs-5 text-primary"></div>
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
                            <input type="date" class="form-control" name="delivery_date" id="delivery_date" min="<?php echo date('Y-m-d'); ?>">
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
                                <strong>GCash:</strong> 0950-200-1713<br>
                                <strong>Name:</strong> Romeo E. De Chavez
                            </div>
                            <label class="form-label fw-semibold">Upload GCash Receipt</label>
                            <input type="file" class="form-control" name="gcash_receipt" accept="image/*" required>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="place_order" class="btn btn-primary px-5 fw-semibold">Confirm Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.createElement('button');
        toggleBtn.className = 'btn btn-light position-fixed d-lg-none shadow-sm';
        toggleBtn.style.cssText = 'top: 22px; left: 22px; z-index: 1100; border-radius: 12px;';
        toggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
        document.body.appendChild(toggleBtn);
        
        toggleBtn.addEventListener('click', () => sidebar.classList.toggle('collapsed'));
        if (window.innerWidth < 992) sidebar.classList.add('collapsed');
        
        // Modal data population
        const orderModal = document.getElementById('orderModal');
        orderModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const productID = button.getAttribute('data-productid');
            const productName = button.getAttribute('data-productname');
            
            document.getElementById('productID').value = productID;
            document.getElementById('modalProductName').innerHTML = productName;
        });
        
        // Toggle delivery date
        const deliveryOption = document.getElementById('delivery_option');
        const deliveryDateContainer = document.getElementById('deliveryDateContainer');
        deliveryOption.addEventListener('change', function() {
            deliveryDateContainer.style.display = this.value === 'scheduled' ? 'block' : 'none';
        });
        
        // Toggle GCash details
        const paymentMethod = document.getElementById('payment_method');
        const gcashDetails = document.getElementById('gcashDetails');
        paymentMethod.addEventListener('change', function() {
            gcashDetails.style.display = this.value === 'GCash' ? 'block' : 'none';
        });
    </script>
</body>
</html>