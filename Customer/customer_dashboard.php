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

$customerColumn = 'userID';

// Fetch dashboard data
$stmt = $conn->prepare("SELECT COUNT(*) as total_orders FROM orders WHERE $customerColumn = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$totalOrders = $stmt->get_result()->fetch_assoc()['total_orders'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as active_orders FROM orders WHERE $customerColumn = ? AND status IN ('Pending', 'Processing', 'Out for Delivery')");
$stmt->bind_param("i", $userID);
$stmt->execute();
$activeOrders = $stmt->get_result()->fetch_assoc()['active_orders'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT SUM(total_amount) as total_spent FROM orders WHERE $customerColumn = ? AND status = 'Delivered'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$totalSpent = $stmt->get_result()->fetch_assoc()['total_spent'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as pending_orders FROM orders WHERE $customerColumn = ? AND status = 'Pending'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$pendingOrders = $stmt->get_result()->fetch_assoc()['pending_orders'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT orderID, order_date, total_amount, status FROM orders WHERE $customerColumn = ? ORDER BY order_date DESC LIMIT 5");
$stmt->bind_param("i", $userID);
$stmt->execute();
$recentOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT orderID, order_date, total_amount, status FROM orders WHERE $customerColumn = ? AND status IN ('Pending', 'Processing', 'Out for Delivery') ORDER BY order_date DESC LIMIT 1");
$stmt->bind_param("i", $userID);
$stmt->execute();
$currentOrder = $stmt->get_result()->fetch_assoc();
$stmt->close();

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;

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
    <title>Dashboard • De Chavez Waterhaus</title>
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
        
        .stat-card { 
            background: white; border-radius: 20px; padding: 28px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.06); transition: transform 0.3s ease;
            border: 1px solid #f0f0f0;
        }
        .stat-card:hover { transform: translateY(-6px); }
        .stat-icon { 
            width: 58px; height: 58px; border-radius: 16px; 
            display: flex; align-items: center; justify-content: center; font-size: 1.7rem; 
            margin-bottom: 18px;
        }
        
        .welcome-header { 
            background: linear-gradient(135deg, #0077B6, #023E8A); 
            color: white; border-radius: 24px; padding: 38px 42px; 
            margin-bottom: 32px; position: relative; overflow: hidden;
        }
        .welcome-header::before { 
            content: ''; position: absolute; top: -50%; right: -20%; 
            width: 320px; height: 320px; background: rgba(255,255,255,0.1); border-radius: 50%; 
        }
        
        .quick-action-btn { 
            background: white; border: 2px solid #e9ecef; border-radius: 18px; 
            padding: 22px; text-align: center; transition: all 0.3s ease; 
            text-decoration: none; color: #333; display: flex; align-items: center;
        }
        .quick-action-btn:hover { 
            border-color: var(--primary); color: var(--primary); 
            transform: translateY(-4px); box-shadow: 0 8px 25px rgba(0, 119, 182, 0.15);
        }
        .quick-action-btn i { font-size: 2.1rem; margin-right: 16px; color: var(--primary); }
        
        .section-title { font-weight: 700; color: #1e293b; margin-bottom: 20px; }
        
        .order-table { border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .order-table th { background: #f8f9fa; font-weight: 600; color: #475569; }
        
        .status-badge { 
            padding: 7px 16px; border-radius: 50px; font-size: 0.82rem; 
            font-weight: 600; display: inline-block;
        }
        
        .dashboard-card { 
            background: white; border-radius: 20px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #f0f0f0;
        }

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

        /* Mobile Responsive */
        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            
            .welcome-header { padding: 25px 20px; }
            .welcome-header h2 { font-size: 1.5rem; }
            
            .stat-card { padding: 20px; }
            .stat-icon { width: 48px; height: 48px; font-size: 1.4rem; }
            
            .quick-action-btn { padding: 18px; }
            .quick-action-btn i { font-size: 1.8rem; margin-right: 12px; }
        }
        
        @media (max-width: 576px) {
            .main-content { padding: 15px; }
            .welcome-header { padding: 20px 15px; }
            .welcome-header h2 { font-size: 1.3rem; }
            
            .stat-card { padding: 18px; }
            .section-title { font-size: 1.1rem; }
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
        
        <div class="px-3 mt-2" style="height: calc(100vh - 90px); overflow-y: auto; padding-bottom: 20px;">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="customer_dashboard.php" class="nav-link active"><i class="fas fa-home me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="products.php" class="nav-link"><i class="fas fa-box me-3"></i> <span>Products</span></a></li>
                <li class="nav-item"><a href="orders.php" class="nav-link"><i class="fas fa-shopping-cart me-3"></i> <span>Place Order</span></a></li>
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

        <!-- Top Navbar -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">
                <!-- Hamburger Button (Mobile Only) -->
                <button class="btn btn-light d-lg-none me-3 shadow-sm" id="mobileToggle" style="width: 42px; height: 42px; border-radius: 12px;">
                    <i class="fas fa-bars"></i>
                </button>
                
                <div>
                    <h4 class="fw-bold mb-0 d-none d-sm-block">Good morning, <?php echo htmlspecialchars($userName); ?>!</h4>
                    <h4 class="fw-bold mb-0 d-sm-none">Hi, <?php echo htmlspecialchars(explode(' ', $userName)[0]); ?>!</h4>
                    <p class="text-muted mb-0 d-none d-sm-block">Here's your water delivery overview</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <!-- Notification Bell -->
                <?php
                $notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
                ?>
                <div class="position-relative">
                    <a href="notifications.php" class="btn btn-light rounded-circle p-2 shadow-sm" style="width: 46px; height: 46px; position: relative;">
                        <i class="fas fa-bell fa-lg text-secondary"></i>
                        <?php if ($notifCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; padding: 2px 6px;">
                                <?php echo $notifCount > 9 ? '9+' : $notifCount; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <!-- User Profile -->
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

        <!-- Welcome Header -->
        <div class="welcome-header text-white mb-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="fw-bold mb-2">Stay Hydrated, Stay Healthy!</h2>
                    <p class="mb-0 opacity-90">Thank you for being part of the De Chavez family.</p>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <a href="orders.php" class="btn btn-light btn-lg px-5 py-2 fw-semibold rounded-pill shadow-sm">
                        <i class="fas fa-plus me-2"></i> Order Now
                    </a>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fas fa-shopping-bag"></i>
                    </div>
                    <h2 class="fw-bold mb-1"><?php echo $totalOrders; ?></h2>
                    <p class="text-muted mb-0 fw-medium">Total Orders</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fas fa-truck"></i>
                    </div>
                    <h2 class="fw-bold mb-1"><?php echo $activeOrders; ?></h2>
                    <p class="text-muted mb-0 fw-medium">Active Orders</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="fas fa-peso-sign"></i>
                    </div>
                    <h2 class="fw-bold mb-1">₱<?php echo number_format($totalSpent, 2); ?></h2>
                    <p class="text-muted mb-0 fw-medium">Total Spent</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h2 class="fw-bold mb-1"><?php echo $pendingOrders; ?></h2>
                    <p class="text-muted mb-0 fw-medium">Pending Orders</p>
                </div>
            </div>
        </div>

        <div class="row g-4">
            
            <!-- Quick Actions -->
            <div class="col-lg-5">
                <div class="dashboard-card h-100">
                    <div class="card-body p-4">
                        <h5 class="section-title">Quick Actions</h5>
                        
                        <div class="d-grid gap-3">
                            <a href="orders.php" class="quick-action-btn">
                                <i class="fas fa-plus-circle"></i>
                                <div>
                                    <div class="fw-semibold">Place New Order</div>
                                    <small class="text-muted">Order fresh water now</small>
                                </div>
                            </a>
                            
                            <a href="order_history.php" class="quick-action-btn">
                                <i class="fas fa-history"></i>
                                <div>
                                    <div class="fw-semibold">View Order History</div>
                                    <small class="text-muted">See all past deliveries</small>
                                </div>
                            </a>
                            
                            <a href="profile.php" class="quick-action-btn">
                                <i class="fas fa-user-edit"></i>
                                <div>
                                    <div class="fw-semibold">Update Profile</div>
                                    <small class="text-muted">Manage your account</small>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Current Active Order -->
            <div class="col-lg-7">
                <div class="dashboard-card h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="section-title mb-0">Current Order</h5>
                            <?php if ($currentOrder): ?>
                                <span class="badge bg-warning text-dark px-3 py-2 fw-semibold">In Progress</span>
                            <?php else: ?>
                                <span class="badge bg-secondary px-3 py-2 fw-semibold">No Active Order</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($currentOrder): ?>
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="fw-bold fs-5 mb-1">Order #<?php echo $currentOrder['orderID']; ?></div>
                                    <div class="text-muted small">Placed <?php echo date('M d, Y • h:i A', strtotime($currentOrder['order_date'])); ?></div>
                                    <div class="mt-3">
                                        <span class="fw-bold text-primary fs-4">₱<?php echo number_format($currentOrder['total_amount'], 2); ?></span>
                                    </div>
                                </div>
                                <div>
                                    <a href="orders.php?order_id=<?php echo $currentOrder['orderID']; ?>" 
                                       class="btn btn-primary px-4 py-2 rounded-pill fw-semibold">
                                        Track Order
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-truck-loading fa-3x text-muted mb-3 opacity-75"></i>
                                <p class="text-muted mb-3">You currently have no active orders.</p>
                                <a href="orders.php" class="btn btn-primary px-4 rounded-pill">Place Your First Order</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Recent Orders -->
            <div class="col-12">
                <div class="dashboard-card">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="section-title mb-0">Recent Orders</h5>
                            <a href="order_history.php" class="text-primary fw-semibold text-decoration-none">
                                View All <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                        
                        <?php if (!empty($recentOrders)): ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th class="ps-0">Order ID</th>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th class="text-end pe-0">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentOrders as $order): ?>
                                            <tr>
                                                <td class="ps-0"><strong class="text-dark">#<?php echo $order['orderID']; ?></strong></td>
                                                <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                                <td><span class="fw-bold">₱<?php echo number_format($order['total_amount'], 2); ?></span></td>
                                                <td>
                                                    <?php
                                                    $statusClass = match($order['status']) {
                                                        'Delivered' => 'bg-success',
                                                        'Out for Delivery' => 'bg-warning text-dark',
                                                        'Processing' => 'bg-info text-dark',
                                                        'Pending' => 'bg-secondary',
                                                        default => 'bg-secondary'
                                                    };
                                                    ?>
                                                    <span class="status-badge <?php echo $statusClass; ?>">
                                                        <?php echo $order['status']; ?>
                                                    </span>
                                                </td>
                                                <td class="text-end pe-0">
                                                    <a href="order_details.php?order_id=<?php echo $order['orderID']; ?>" 
                                                       class="btn btn-sm btn-outline-primary px-3 rounded-pill">
                                                        View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No orders yet. Start ordering today!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
        </div>
        
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle for mobile
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        
        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => {
                sidebar.classList.toggle('show');
            });
            
            // Close sidebar when clicking outside
            document.addEventListener('click', function(e) {
                if (window.innerWidth < 992 && 
                    !sidebar.contains(e.target) && 
                    !mobileToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            });
        }
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth < 992 && !sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
                sidebar.classList.remove('show');
            }
        });
        
        // Auto collapse on mobile
        if (window.innerWidth < 992) {
            sidebar.classList.remove('show');
        }

        
    </script>
</body>
</html>