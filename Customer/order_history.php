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

// ==================== CHANGE THIS IF YOU GET COLUMN ERROR ====================
$customerColumn = 'userID';   // ← Change this if needed

// Handle Cancel Order
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_order'])) {
    $orderID = intval($_POST['orderID']);
    
    // Check if order belongs to user and is cancellable
    $checkStmt = $conn->prepare("
        SELECT o.status, oi.productID, oi.quantity 
        FROM orders o 
        JOIN order_items oi ON o.orderID = oi.orderID 
        WHERE o.orderID = ? AND o.$customerColumn = ? AND o.status IN ('Pending', 'Processing')
    ");
    $checkStmt->bind_param("ii", $orderID, $userID);
    $checkStmt->execute();
    $orderData = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if ($orderData) {
        // Restore stock
        $restoreStock = $conn->prepare("
            UPDATE product 
            SET Stock = COALESCE(Stock, 0) + ? 
            WHERE ProductID = ?
        ");
        $restoreStock->bind_param("ii", $orderData['quantity'], $orderData['productID']);
        $restoreStock->execute();
        $restoreStock->close();
        
        // Update order status to Cancelled
        $cancelStmt = $conn->prepare("
            UPDATE orders 
            SET status = 'Cancelled', cancelled_at = NOW(), cancel_reason = 'Cancelled by customer' 
            WHERE orderID = ? AND $customerColumn = ?
        ");
        $cancelStmt->bind_param("ii", $orderID, $userID);
        $cancelStmt->execute();
        $cancelStmt->close();
        
        // Create notification
        $message = "Your order #$orderID has been cancelled successfully. Stock has been restored.";
        $notifStmt = $conn->prepare("INSERT INTO notifications (userID, message, type) VALUES (?, ?, 'order')");
        $notifStmt->bind_param("is", $userID, $message);
        $notifStmt->execute();
        $notifStmt->close();
        
        echo '<script>alert("Order cancelled successfully! Stock has been restored."); window.location = "order_history.php";</script>';
        exit();
    } else {
        echo '<script>alert("Cannot cancel this order. It may have already been processed."); window.location = "order_history.php";</script>';
        exit();
    }
}

// Fetch orders with product details
$order = isset($_GET['order']) && $_GET['order'] == 'asc' ? 'ASC' : 'DESC';
$ordersQuery = "
    SELECT o.*, 
           GROUP_CONCAT(CONCAT(p.ProductName, ' x', oi.quantity) SEPARATOR ', ') AS products,
           GROUP_CONCAT(p.ImageURL SEPARATOR '|') AS product_images,
           SUM(oi.quantity * oi.unit_price) AS calculated_total
    FROM orders o
    JOIN order_items oi ON o.orderID = oi.orderID
    JOIN product p ON oi.productID = p.ProductID
    WHERE o.$customerColumn = $userID
    GROUP BY o.orderID
    ORDER BY o.order_date $order";

$ordersResult = $conn->query($ordersQuery);

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
    <title>Order History • De Chavez Waterhaus</title>
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
        
        .order-card { 
            background: white; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            transition: transform 0.2s ease;
        }
        .order-card:hover { transform: translateY(-3px); }
        
        .status-badge { 
            padding: 8px 16px; border-radius: 20px; font-size: 0.85rem; font-weight: 600;
        }
        .status-Pending { background: #fff3cd; color: #856404; }
        .status-Processing { background: #cce5ff; color: #004085; }
        .status-Out-for-Delivery { background: #d4edda; color: #155724; }
        .status-Delivered { background: #d1e7dd; color: #0f5132; }
        .status-Cancelled { background: #f8d7da; color: #721c24; }
        
        .product-thumb { width: 50px; height: 50px; object-fit: cover; border-radius: 8px; }
        
        .sidebar .nav-link {
            padding: 12px 18px;
            margin: 2px 8px;
            border-radius: 10px;
        }
        
        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo p-4 d-flex align-items-center gap-3 border-bottom">
            <img src="../images/logo.jpg" alt="Logo" style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover;">
            <div>
                <span class="fw-bold fs-5">De Chavez Waterhaus</span>
                <small class="d-block text-muted">Customer Portal</small>
            </div>
        </div>
        
        <div class="px-3 mt-2" style="height: calc(100vh - 90px); overflow-y: auto; padding-bottom: 20px;">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="customer_dashboard.php" class="nav-link"><i class="fas fa-home me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="products.php" class="nav-link"><i class="fas fa-box me-3"></i> <span>Products</span></a></li>
                <li class="nav-item"><a href="orders.php" class="nav-link"><i class="fas fa-shopping-cart me-3"></i> <span>Place Order</span></a></li>
                <li class="nav-item"><a href="order_history.php" class="nav-link active"><i class="fas fa-history me-3"></i> <span>Order History</span></a></li>
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
        <!-- Top Navbar -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-lg-none me-3 shadow-sm" id="mobileToggle" style="width: 42px; height: 42px; border-radius: 12px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="fw-bold mb-0">Order History</h4>
                    <p class="text-muted mb-0">View and manage your past orders</p>
                </div>
            </div>
            
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

        <!-- Filter -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="btn-group">
                <a href="?order=desc" class="btn btn-outline-primary <?php echo $order == 'DESC' ? 'active' : ''; ?>">
                    <i class="fas fa-sort-amount-down me-2"></i> Newest First
                </a>
                <a href="?order=asc" class="btn btn-outline-primary <?php echo $order == 'ASC' ? 'active' : ''; ?>">
                    <i class="fas fa-sort-amount-up me-2"></i> Oldest First
                </a>
            </div>
            <a href="products.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i> Place New Order
            </a>
        </div>

        <!-- Orders List -->
        <?php if ($ordersResult->num_rows > 0): ?>
            <div class="row g-4">
                <?php while ($order = $ordersResult->fetch_assoc()) { 
                    $statusClass = str_replace(' ', '-', $order['status']);
                    
                    // Get first product image
                    $images = explode('|', $order['product_images']);
                    $firstImage = $images[0] ?? '';
                    if (!empty($firstImage) && file_exists('../' . $firstImage)) {
                        $displayImage = '../' . $firstImage;
                    } else {
                        $displayImage = 'https://via.placeholder.com/60x60?text=Order';
                    }
                ?>
                    <div class="col-12">
                        <div class="order-card p-4">
                            <div class="row align-items-center">
                                <!-- Order Info -->
                                <div class="col-md-2">
                                    <div class="d-flex align-items-center gap-3">
                                        <img src="<?php echo $displayImage; ?>" class="product-thumb" alt="Product">
                                        <div>
                                            <div class="fw-bold">#<?php echo $order['orderID']; ?></div>
                                            <small class="text-muted"><?php echo date('M j, Y', strtotime($order['order_date'])); ?></small>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Products -->
                                <div class="col-md-4">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($order['products']); ?></div>
                                </div>
                                
                                <!-- Amount -->
                                <div class="col-md-2">
                                    <div class="fw-bold text-primary fs-5">₱<?php echo number_format($order['total_amount'], 2); ?></div>
                                </div>
                                
                                <!-- Status -->
                                <div class="col-md-2">
                                    <span class="status-badge status-<?php echo $statusClass; ?>">
                                        <?php echo $order['status']; ?>
                                    </span>
                                </div>
                                
                                <!-- Actions -->
                                <div class="col-md-2 text-end">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#orderDetailModal<?php echo $order['orderID']; ?>">
                                        <i class="fas fa-eye me-1"></i> View
                                    </button>
                                    
                                    <?php if (in_array($order['status'], ['Pending', 'Processing'])): ?>
                                        <button class="btn btn-sm btn-outline-danger mt-1 mt-md-0" data-bs-toggle="modal" data-bs-target="#cancelModal<?php echo $order['orderID']; ?>">
                                            <i class="fas fa-times me-1"></i> Cancel
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Order Detail Modal -->
                    <div class="modal fade" id="orderDetailModal<?php echo $order['orderID']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title fw-bold">
                                        <i class="fas fa-receipt me-2"></i> Order #<?php echo $order['orderID']; ?>
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body p-4">
                                    <div class="row">
                                        <!-- Order Info -->
                                        <div class="col-md-6">
                                            <h6 class="fw-bold mb-3">Order Information</h6>
                                            <table class="table table-sm">
                                                <tr>
                                                    <td class="text-muted">Order Date:</td>
                                                    <td><strong><?php echo date('F j, Y g:i A', strtotime($order['order_date'])); ?></strong></td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Status:</td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $statusClass; ?>">
                                                            <?php echo $order['status']; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Payment:</td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $order['payment_method'] == 'GCash' ? 'info' : 'secondary'; ?>">
                                                            <?php echo $order['payment_method']; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Total Amount:</td>
                                                    <td><strong class="text-primary fs-5">₱<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                                                </tr>
                                            </table>
                                        </div>
                                        
                                        <!-- Delivery Info -->
                                        <div class="col-md-6">
                                            <h6 class="fw-bold mb-3">Delivery Information</h6>
                                            <table class="table table-sm">
                                                <tr>
                                                    <td class="text-muted">Delivery Address:</td>
                                                    <td><?php echo htmlspecialchars($order['delivery_address'] ?? 'N/A'); ?></td>
                                                </tr>
                                                <?php if (!empty($order['notes'])): ?>
                                                <tr>
                                                    <td class="text-muted">Notes:</td>
                                                    <td><small><?php echo htmlspecialchars($order['notes']); ?></small></td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($order['status'] == 'Cancelled' && !empty($order['cancel_reason'])): ?>
                                                <tr>
                                                    <td class="text-muted">Cancel Reason:</td>
                                                    <td><span class="text-danger"><?php echo htmlspecialchars($order['cancel_reason']); ?></span></td>
                                                </tr>
                                                <?php endif; ?>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <!-- Products Ordered -->
                                    <h6 class="fw-bold mt-4 mb-3">Products Ordered</h6>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Product</th>
                                                    <th class="text-center">Quantity</th>
                                                    <th class="text-end">Unit Price</th>
                                                    <th class="text-end">Subtotal</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                // Fetch order items for this order
                                                $itemsQuery = "
                                                    SELECT oi.*, p.ProductName, p.ImageURL 
                                                    FROM order_items oi 
                                                    JOIN product p ON oi.productID = p.ProductID 
                                                    WHERE oi.orderID = ?
                                                ";
                                                $itemsStmt = $conn->prepare($itemsQuery);
                                                $itemsStmt->bind_param("i", $order['orderID']);
                                                $itemsStmt->execute();
                                                $itemsResult = $itemsStmt->get_result();
                                                
                                                while ($item = $itemsResult->fetch_assoc()) {
                                                    $itemImage = !empty($item['ImageURL']) && file_exists('../' . $item['ImageURL']) 
                                                        ? '../' . $item['ImageURL'] 
                                                        : 'https://via.placeholder.com/40x40?text=Item';
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <img src="<?php echo $itemImage; ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 5px;">
                                                                <span><?php echo htmlspecialchars($item['ProductName']); ?></span>
                                                            </div>
                                                        </td>
                                                        <td class="text-center"><strong><?php echo $item['quantity']; ?></strong></td>
                                                        <td class="text-end">₱<?php echo number_format($item['unit_price'], 2); ?></td>
                                                        <td class="text-end"><strong>₱<?php echo number_format($item['quantity'] * $item['unit_price'], 2); ?></strong></td>
                                                    </tr>
                                                <?php } 
                                                $itemsStmt->close();
                                                ?>
                                            </tbody>
                                            <tfoot class="table-light">
                                                <tr>
                                                    <td colspan="3" class="text-end"><strong>Total:</strong></td>
                                                    <td class="text-end"><strong class="text-primary fs-5">₱<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                                <div class="modal-footer border-0 p-4 pt-0">
                                    <?php if (in_array($order['status'], ['Pending', 'Processing'])): ?>
                                        <button class="btn btn-danger" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#cancelModal<?php echo $order['orderID']; ?>">
                                            <i class="fas fa-times me-2"></i> Cancel Order
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cancel Confirmation Modal -->
                    <?php if (in_array($order['status'], ['Pending', 'Processing'])): ?>
                    <div class="modal fade" id="cancelModal<?php echo $order['orderID']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header bg-danger text-white">
                                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i> Cancel Order</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body p-4">
                                    <p>Are you sure you want to cancel <strong>Order #<?php echo $order['orderID']; ?></strong>?</p>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Note:</strong> This action cannot be undone. The stock will be restored to inventory.
                                    </div>
                                </div>
                                <div class="modal-footer border-0 p-4 pt-0">
                                    <form method="POST" action="order_history.php">
                                        <input type="hidden" name="orderID" value="<?php echo $order['orderID']; ?>">
                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep Order</button>
                                        <button type="submit" name="cancel_order" class="btn btn-danger">
                                            <i class="fas fa-times me-2"></i> Yes, Cancel Order
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php } ?>
            </div>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-shopping-bag fa-4x text-muted mb-4"></i>
                <h5 class="fw-bold">No Orders Yet</h5>
                <p class="text-muted">You haven't placed any orders yet.</p>
                <a href="products.php" class="btn btn-primary px-5 mt-3">
                    <i class="fas fa-shopping-cart me-2"></i> Browse Products
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        
        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => sidebar.classList.toggle('show'));
        }
    </script>
</body>
</html>