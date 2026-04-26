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

// Fetch all orders with latest status
$ordersQuery = "
    SELECT o.orderID, o.order_date, o.total_amount, o.status, o.payment_method,
           d.delivery_date,
           p.ProductName, oi.quantity
    FROM orders o
    LEFT JOIN deliveries d ON o.orderID = d.orderID
    LEFT JOIN order_items oi ON o.orderID = oi.orderID
    LEFT JOIN product p ON oi.productID = p.ProductID
    WHERE o.userID = ?
    ORDER BY o.order_date DESC
";

$stmt = $conn->prepare($ordersQuery);
$stmt->bind_param("i", $userID);
$stmt->execute();
$ordersResult = $stmt->get_result();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Tracking • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
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
        .sidebar .logo img { width: 42px; height: 42px; border-radius: 50%; }
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
        .order-card { background: white; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; transition: transform 0.2s ease; }
        .order-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .status-badge { padding: 6px 14px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-processing { background: #cce5ff; color: #004085; }
        .status-delivery { background: #d4edda; color: #155724; }
        .status-delivered { background: #28a745; color: white; }
        .timeline { position: relative; padding-left: 30px; }
        .timeline::before { content: ''; position: absolute; left: 15px; top: 0; bottom: 0; width: 3px; background: #e9ecef; }
        .timeline-step { position: relative; margin-bottom: 20px; }
        .timeline-step::before { content: ''; position: absolute; left: -30px; width: 20px; height: 20px; border-radius: 50%; background: #fff; border: 3px solid #0077B6; z-index: 2; }
        .timeline-step.completed::before { background: #28a745; border-color: #28a745; }
        .timeline-step.current::before { background: #0077B6; border-color: #0077B6; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(0,119,182,0.4); } 70% { box-shadow: 0 0 0 10px rgba(0,119,182,0); } 100% { box-shadow: 0 0 0 0 rgba(0,119,182,0); } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo p-4 d-flex align-items-center gap-3 border-bottom">
            <img src="../images/logo.png" alt="Logo" style="width: 45px; height: 45px; border-radius: 50%;">
            <span class="fw-bold fs-5">De Chavez Waterhaus</span>
        </div>
        
        <div class="px-3 mt-2">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="customer_dashboard.php" class="nav-link"><i class="fas fa-home "></i><span>Dashboard</span></a></li>
                <li class="nav-item"><a href="products.php" class="nav-link"><i class="fas fa-box "></i><span>Products</span></a></li>
                <li class="nav-item">
                    <a href="orders.php" class="nav-link">
                        <i class="fas fa-shopping-cart"></i> <span>Place Order</span>
                    </a>
                </li>
                <li class="nav-item"><a href="order_history.php" class="nav-link"><i class="fas fa-history "></i><span>Order History</span></a></li>
                <li class="nav-item"><a href="order_tracking.php" class="nav-link active"><i class="fas fa-map-marker-alt "></i><span>Track Orders</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user "></i><span>Profile</span></a></li>
                <li class="nav-item mt-4"><a href="../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt "></i><span>Logout</span></a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">Order Tracking</h4>
                <p class="text-muted mb-0">Track the status of your water deliveries</p>
            </div>
            <a href="products.php" class="btn btn-primary px-4 rounded-pill">
                <i class="fas fa-plus me-2"></i> Place New Order
            </a>
        </div>

        <?php if ($ordersResult->num_rows > 0): ?>
            <div class="row g-4">
                <?php while ($order = $ordersResult->fetch_assoc()) { 
                    $status = $order['status'];
                    
                    // Determine badge class
                    $badgeClass = 'status-pending';
                    if ($status == 'Processing') $badgeClass = 'status-processing';
                    if ($status == 'Out for Delivery') $badgeClass = 'status-delivery';
                    if ($status == 'Delivered') $badgeClass = 'status-delivered';
                ?>
                    <div class="col-lg-6">
                        <div class="order-card p-4">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <div class="fw-bold text-primary">Order #<?php echo $order['orderID']; ?></div>
                                    <small class="text-muted"><?php echo date('F j, Y g:i A', strtotime($order['order_date'])); ?></small>
                                </div>
                                <span class="status-badge <?php echo $badgeClass; ?>"><?php echo $status; ?></span>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Product:</span>
                                    <span class="fw-semibold"><?php echo $order['ProductName']; ?> (x<?php echo $order['quantity']; ?>)</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Total Amount:</span>
                                    <span class="fw-bold text-success">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Payment:</span>
                                    <span><?php echo $order['payment_method']; ?></span>
                                </div>
                            </div>
                            
                            <!-- Timeline -->
                            <div class="timeline mt-4">
                                <div class="timeline-step <?php echo ($status != 'Pending') ? 'completed' : 'current'; ?>">
                                    <div class="fw-semibold">Order Placed</div>
                                    <small class="text-muted"><?php echo date('M j, g:i A', strtotime($order['order_date'])); ?></small>
                                </div>
                                
                                <div class="timeline-step <?php echo ($status == 'Processing' || $status == 'Out for Delivery' || $status == 'Delivered') ? 'completed' : ($status == 'Pending' ? '' : 'current'); ?>">
                                    <div class="fw-semibold">Processing</div>
                                    <small class="text-muted">Preparing your order</small>
                                </div>
                                
                                <div class="timeline-step <?php echo ($status == 'Out for Delivery' || $status == 'Delivered') ? 'completed' : ($status == 'Processing' ? 'current' : ''); ?>">
                                    <div class="fw-semibold">Out for Delivery</div>
                                    <small class="text-muted"><?php echo $order['delivery_date'] ? date('M j', strtotime($order['delivery_date'])) : 'Scheduled soon'; ?></small>
                                </div>
                                
                                <div class="timeline-step <?php echo ($status == 'Delivered') ? 'completed' : ($status == 'Out for Delivery' ? 'current' : ''); ?>">
                                    <div class="fw-semibold">Delivered</div>
                                    <small class="text-muted">Enjoy your water!</small>
                                </div>
                            </div>
                            
                            <div class="mt-4 pt-3 border-top d-flex gap-2">
                                <button class="btn btn-outline-primary btn-sm flex-grow-1" data-bs-toggle="modal" data-bs-target="#trackModal<?php echo $order['orderID']; ?>">
                                    <i class="fas fa-map-marker-alt me-1"></i> View Details
                                </button>
                                
                                <?php if ($status != 'Delivered'): ?>
                                    <button class="btn btn-outline-secondary btn-sm" onclick="contactSupport(<?php echo $order['orderID']; ?>)">
                                        <i class="fas fa-headset me-1"></i> Support
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
                <h5 class="fw-bold">No Orders Yet</h5>
                <p class="text-muted">You haven't placed any orders yet.</p>
                <a href="products.php" class="btn btn-primary px-5 rounded-pill">Browse Products</a>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function contactSupport(orderID) {
            alert("Support ticket feature coming soon! For now, please contact us at support@dechavezwaterhaus.com");
        }
        
        // Auto-refresh every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>