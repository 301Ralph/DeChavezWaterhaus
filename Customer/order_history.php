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

// Fetch orders
$order = isset($_GET['order']) && $_GET['order'] == 'asc' ? 'ASC' : 'DESC';
$ordersQuery = "
    SELECT o.orderID, o.order_date, o.status, o.payment_method, o.total_amount,
           GROUP_CONCAT(CONCAT(p.ProductName, ' x', oi.quantity) SEPARATOR ', ') AS products
    FROM orders o
    JOIN order_items oi ON o.orderID = oi.orderID
    JOIN product p ON oi.productID = p.ProductID
    WHERE o.$customerColumn = $userID
    GROUP BY o.orderID
    ORDER BY o.order_date $order";

$ordersResult = $conn->query($ordersQuery);
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
        
        .section-title { font-weight: 700; color: #1e293b; margin-bottom: 25px; }
        
        .order-table { background: white; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); overflow: hidden; }
        .order-table th { background: #f8f9fa; font-weight: 600; color: #475569; }
        .order-table td { vertical-align: middle; }
        
        .status-badge { padding: 7px 16px; border-radius: 50px; font-size: 0.82rem; font-weight: 600; display: inline-block; }
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
                <li class="nav-item"><a href="products.php" class="nav-link"><i class="fas fa-box"></i> <span>Products</span></a></li>
                <li class="nav-item">
                    <a href="orders.php" class="nav-link">
                        <i class="fas fa-shopping-cart"></i> <span>Place Order</span>
                    </a>
                </li>
                <li class="nav-item"><a href="order_history.php" class="nav-link active"><i class="fas fa-history"></i> <span>Order History</span></a></li>
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
                <h4 class="fw-bold mb-0">Order History</h4>
                <p class="text-muted mb-0">Track all your past water deliveries</p>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <a href="order_history.php?order=<?php echo $order == 'ASC' ? 'desc' : 'asc'; ?>" class="btn btn-outline-primary px-4 rounded-pill">
                    <i class="fas fa-sort me-2"></i> Sort by Date
                </a>
                
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

        <!-- Orders Table -->
        <div class="order-table">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Order ID</th>
                            <th>Date</th>
                            <th>Products</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($ordersResult->num_rows > 0): ?>
                            <?php while ($order = $ordersResult->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4"><strong class="text-dark">#<?php echo $order['orderID']; ?></strong></td>
                                    <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                    <td>
                                        <div class="fw-medium"><?php echo $order['products']; ?></div>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-primary">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = match($order['status']) {
                                            'Delivered' => 'bg-success',
                                            'Out for Delivery' => 'bg-warning text-dark',
                                            'Processing' => 'bg-info text-dark',
                                            'Pending' => 'bg-secondary',
                                            'Cancelled' => 'bg-danger',
                                            default => 'bg-secondary'
                                        };
                                        ?>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <?php echo $order['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark"><?php echo $order['payment_method']; ?></span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="order_details.php?order_id=<?php echo $order['orderID']; ?>" class="btn btn-sm btn-outline-primary px-3 rounded-pill">
                                            View Details
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No orders found yet.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
    </script>
</body>
</html>