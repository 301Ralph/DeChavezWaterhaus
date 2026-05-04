<?php
include '../includes/connection.php';
session_start();

// Security check
if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'customer') {
    echo '<script>alert("Access denied. Customers only."); window.location = "../login.php";</script>';
    exit();
}

$userID   = $_SESSION['userID'];
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

    // Insert order
    $orderNotes = ($paymentMethod == 'GCash' && !empty($gcashReceipt)) ? "GCash Receipt: " . $gcashReceipt : "";
    
    $insertOrder = $conn->prepare("
        INSERT INTO orders (userID, order_date, total_amount, status, payment_method, notes, delivery_address) 
        VALUES (?, NOW(), ?, 'Pending', ?, ?, ?)
    ");
    $deliveryAddress = "Customer Address";
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

        // Deduct stock
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

        echo '<script>alert("Order placed successfully! Order ID: #' . $orderID . '"); window.location = "order_history.php";</script>';
        exit();
    } else {
        echo '<script>alert("Failed to place order. Please try again."); window.location = "products.php";</script>';
        exit();
    }
}

// Fetch user data
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName = explode(' ', $userName)[0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;
            --abyss: #030f1e;
            --ocean: #041e35;
            --navy:  #0a2d4a;
            --teal:  #0077b6;
            --aqua:  #00b4d8;
            --cyan:  #48cae4;
            --glow:  #90e0ef;
            --foam:  #caf0f8;
            --white: #f0f9ff;
            --gold:  #f4c842;
            --glass: rgba(0,180,216,0.08);
            --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--deep);
            color: var(--white);
            min-height: 100vh;
        }

        /* ── SIDEBAR ── */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            width: var(--sidebar-w);
            background: var(--abyss);
            border-right: 1px solid var(--glass-border);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }

        .sidebar-logo {
            padding: 24px 22px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--glass-border);
            flex-shrink: 0;
        }

        .sidebar-logo img {
            width: 40px; height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid rgba(0,180,216,0.35);
            box-shadow: 0 0 14px rgba(0,180,216,0.2);
        }

        .sidebar-logo span {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.05rem;
            font-weight: 500;
            color: var(--white);
            line-height: 1.2;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 16px 12px 20px;
            scrollbar-width: thin;
            scrollbar-color: rgba(72,202,228,0.15) transparent;
        }

        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }

        .nav-section-label {
            font-size: 0.62rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: rgba(202,240,248,0.25);
            padding: 16px 12px 6px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 10px;
            color: rgba(202,240,248,0.5) !important;
            text-decoration: none;
            font-size: 0.87rem;
            font-weight: 500;
            transition: all 0.25s ease;
            margin-bottom: 2px;
            position: relative;
        }

        .nav-link i {
            width: 18px;
            text-align: center;
            font-size: 0.9rem;
            color: rgba(0,180,216,0.4);
            transition: color 0.25s;
        }

        .nav-link:hover {
            background: var(--glass);
            color: var(--foam) !important;
        }

        .nav-link:hover i { color: var(--aqua); }

        .nav-link.active {
            background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12));
            border: 1px solid rgba(0,180,216,0.2);
            color: var(--aqua) !important;
        }

        .nav-link.active i { color: var(--aqua); }

        .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0; top: 20%; bottom: 20%;
            width: 3px;
            background: var(--aqua);
            border-radius: 0 3px 3px 0;
        }

        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }

        /* ── MAIN ── */
        .main-content {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
            padding: 28px 32px;
            transition: margin-left 0.3s ease;
        }

        /* ── TOP BAR ── */
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .topbar-greeting h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.7rem;
            font-weight: 400;
            color: var(--white);
            line-height: 1.1;
        }

        .topbar-greeting p {
            font-size: 0.82rem;
            color: rgba(202,240,248,0.4);
            margin-top: 2px;
        }

        .topbar-actions { display: flex; align-items: center; gap: 12px; }

        .topbar-btn {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: rgba(202,240,248,0.6);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.3s;
            position: relative;
        }

        .topbar-btn:hover {
            background: rgba(0,180,216,0.15);
            border-color: var(--aqua);
            color: var(--aqua);
        }

        .topbar-notif-badge {
            position: absolute;
            top: -3px; right: -3px;
            background: var(--gold);
            color: var(--deep);
            font-size: 0.58rem;
            font-weight: 700;
            min-width: 16px;
            height: 16px;
            border-radius: 50px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px;
        }

        .avatar-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 50px;
            padding: 6px 14px 6px 6px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .avatar-btn:hover {
            border-color: rgba(0,180,216,0.35);
            background: rgba(0,180,216,0.1);
        }

        .avatar-circle {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: var(--deep);
            font-weight: 700;
            font-size: 0.85rem;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }

        .avatar-name {
            font-size: 0.82rem;
            font-weight: 500;
            color: var(--white);
        }

        .avatar-role {
            font-size: 0.7rem;
            color: rgba(202,240,248,0.4);
        }

        /* ── PRODUCT CARDS ── */
        .product-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            overflow: hidden;
            transition: all 0.35s cubic-bezier(0.23,1,0.32,1);
        }

        .product-card:hover {
            transform: translateY(-8px);
            border-color: rgba(0,180,216,0.3);
            box-shadow: 0 20px 45px rgba(0,0,0,0.35);
        }

        .product-card img {
            height: 220px;
            object-fit: cover;
            background: rgba(4,30,53,0.5);
        }

        .product-card .card-body {
            padding: 24px;
        }

        .product-name {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.35rem;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 8px;
        }

        .product-price {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--aqua);
        }

        .stock-badge {
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.08em;
        }

        .stock-low { background: rgba(244,200,66,0.12); color: var(--gold); border: 1px solid rgba(244,200,66,0.25); }
        .stock-medium { background: rgba(0,180,216,0.1); color: var(--aqua); border: 1px solid rgba(0,180,216,0.25); }
        .stock-high { background: rgba(74,222,128,0.1); color: #4ade80; border: 1px solid rgba(74,222,128,0.25); }

        /* ── MODAL ── */
        .modal-content {
            background: var(--ocean);
            border: 1px solid var(--glass-border);
            border-radius: 18px;
        }

        .modal-header {
            border-bottom: 1px solid var(--glass-border);
            padding: 20px 24px;
        }

        .modal-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem;
            font-weight: 500;
        }

        .modal-footer {
            border-top: 1px solid var(--glass-border);
            padding: 20px 24px;
        }

        .form-control, .form-select {
            background: rgba(4,30,53,0.6);
            border: 1px solid var(--glass-border);
            color: var(--white);
            border-radius: 10px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--aqua);
            box-shadow: 0 0 0 0.2rem rgba(0,180,216,0.15);
            background: rgba(4,30,53,0.8);
        }

        .form-label {
            color: rgba(202,240,248,0.7);
            font-weight: 500;
        }

        /* Mobile */
        @media (max-width: 991px) {
            .main-content { margin-left: 0; padding: 20px 18px; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
            .product-card { margin-bottom: 15px; }
        }
    </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../images/logo.jpg" alt="Logo">
        <span>De Chavez<br>Waterhaus</span>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="customer_dashboard.php" class="nav-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="products.php" class="nav-link active">
            <i class="fas fa-droplet"></i> Products
        </a>
        <a href="order_history.php" class="nav-link">
            <i class="fas fa-history"></i> Order History
        </a>
        <a href="order_tracking.php" class="nav-link">
            <i class="fas fa-map-marker-alt"></i> Track Orders
        </a>
        <a href="recurring_orders.php" class="nav-link">
            <i class="fas fa-redo"></i> Recurring Orders
        </a>

        <div class="nav-section-label">Account</div>
        <a href="support_tickets.php" class="nav-link">
            <i class="fas fa-headset"></i> Support
        </a>
        <a href="notifications.php" class="nav-link">
            <i class="fas fa-bell"></i> Notifications
        </a>
        <a href="profile.php" class="nav-link">
            <i class="fas fa-user"></i> Profile
        </a>

        <div class="nav-section-label" style="margin-top: 16px;"></div>
        <a href="../logout.php" class="nav-link danger">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN CONTENT ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle d-lg-none" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="topbar-greeting">
                <h4>Our Products</h4>
                <p>Choose from our premium water collection</p>
            </div>
        </div>

        <div class="topbar-actions">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if ($notifCount > 0): ?>
                    <span class="topbar-notif-badge"><?php echo $notifCount > 9 ? '9+' : $notifCount; ?></span>
                <?php endif; ?>
            </a>

            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if (!empty($user['profile_picture']) && file_exists('../' . $user['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($userName, 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($userName); ?></div>
                        <div class="avatar-role">Customer</div>
                    </div>
                    <i class="fas fa-chevron-down fa-xs ms-1" style="color:rgba(202,240,248,0.3);"></i>
                </div>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Products Grid -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="mb-1" style="font-family: 'Cormorant Garamond', serif; font-size: 1.5rem;">Available Products</h5>
            <p class="text-muted mb-0 small">Premium purified water delivered to your door</p>
        </div>
        <a href="order_history.php" class="btn btn-glass">
            <i class="fas fa-history me-2"></i> Order History
        </a>
    </div>
    
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
                        <div class="card-body">
                            <h5 class="product-name"><?php echo htmlspecialchars($product['ProductName']); ?></h5>
                            <p class="text-muted small mb-3"><?php echo htmlspecialchars($product['Description'] ?? 'Premium quality water'); ?></p>
                            
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <div>
                                    <span class="product-price">₱<?php echo number_format($product['Price'], 2); ?></span>
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
        <div class="dash-card text-center py-5">
            <i class="fas fa-box-open fa-4x mb-4" style="color: rgba(0,180,216,0.15);"></i>
            <h5 class="fw-semibold mb-2">No Products Available</h5>
            <p class="text-muted">Please check back later or contact support.</p>
        </div>
    <?php endif; ?>
    
</main>

<!-- Order Modal -->
<div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="products.php" method="POST" enctype="multipart/form-data" id="orderForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-shopping-cart me-2"></i> Place Your Order
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="productID" id="productID">
                    
                    <div class="mb-4">
                        <label class="form-label">Product</label>
                        <div id="modalProductName" class="fw-bold fs-5" style="color: var(--aqua);"></div>
                        <div class="text-muted small" id="modalStockInfo"></div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Quantity (5 Gallon)</label>
                            <input type="number" class="form-control" name="quantity" id="quantity" min="6" max="100" value="6" required>
                            <div class="form-text">Minimum 6 • Maximum 100</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Delivery Option</label>
                            <select class="form-select" name="delivery_option" id="delivery_option" required>
                                <option value="today">Today (Same Day)</option>
                                <option value="scheduled">Scheduled Delivery</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3 mt-3" id="deliveryDateContainer" style="display: none;">
                        <label class="form-label">Preferred Delivery Date</label>
                        <input type="date" class="form-control" name="delivery_date" id="delivery_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Payment Method</label>
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
                        <label class="form-label">Upload GCash Receipt <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" name="gcash_receipt" accept="image/*" id="gcash_receipt">
                        <div class="form-text">Upload screenshot of your GCash payment</div>
                    </div>
                    
                    <div class="alert alert-warning py-2 px-3 mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Minimum Order:</strong> 6 gallons | <strong>Maximum:</strong> 100 gallons
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass" data-bs-dismiss="modal">Cancel</button>
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
    // Mobile Sidebar
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle = document.getElementById('mobileToggle');

    function openSidebar() { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }

    if (toggle) toggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 992) closeSidebar();
        });
    });
    
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