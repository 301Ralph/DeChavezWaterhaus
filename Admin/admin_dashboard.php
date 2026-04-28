<?php
include '../includes/connection.php';
session_start();

// Security check - Admin only
if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo '<script>alert("Access denied. Admins only."); window.location = "../login.php";</script>';
    exit();
}

$adminID = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';

// Fetch admin data for profile picture
$admin = $conn->query("SELECT * FROM customers WHERE userID = " . $_SESSION['userID'])->fetch_assoc();

// Fetch dashboard statistics
$totalOrders = $conn->query("SELECT COUNT(*) as count FROM orders")->fetch_assoc()['count'] ?? 0;
$totalRevenue = $conn->query("SELECT SUM(total_amount) as total FROM orders WHERE status = 'Delivered'")->fetch_assoc()['total'] ?? 0;
$totalCustomers = $conn->query("SELECT COUNT(*) as count FROM customers WHERE Role = 'customer'")->fetch_assoc()['count'] ?? 0;
$pendingOrders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status IN ('Pending', 'Processing')")->fetch_assoc()['count'] ?? 0;
$activeRiders = 0;
try {
    $activeEmployees = 0;
try {
    $activeEmployees = $conn->query("SELECT COUNT(*) as count FROM customers WHERE Role = 'employee' AND status = 'Active'")->fetch_assoc()['count'] ?? 0;
} catch (Exception $e) {
    // Table might not exist yet
}
} catch (Exception $e) {
    // Riders table doesn't exist yet - will be 0
}
$openTickets = 0;
try {
    $openTickets = $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE status IN ('Open', 'In Progress')")->fetch_assoc()['count'] ?? 0;
} catch (Exception $e) {
    // support_tickets table doesn't exist yet - will be 0
}

// Recent orders
$recentOrders = $conn->query("
    SELECT o.orderID, o.order_date, o.total_amount, o.status, o.payment_method,
           CONCAT(c.Firstname, ' ', c.Lastname) as customer_name
    FROM orders o
    JOIN customers c ON o.userID = c.userID
    ORDER BY o.order_date DESC
    LIMIT 10
");

// Pending verifications
$pendingVerifications = $conn->query("
    SELECT userID, Firstname, Lastname, Email, created_at
    FROM customers 
    WHERE verification_status = 'pending' AND Role = 'customer'
    ORDER BY created_at DESC
    LIMIT 5
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard • De Chavez Waterhaus</title>
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
            display: flex;
            flex-direction: column;
        }
        .sidebar .nav-menu {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 20px;
        }
        .sidebar .logout-section {
            padding: 15px 10px;
            border-top: 1px solid #eee;
            background: white;
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
        
        .stat-card { 
            background: white; border-radius: 20px; padding: 24px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #f0f0f0;
            transition: transform 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon { 
            width: 60px; height: 60px; border-radius: 16px; 
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem;
        }
        
        .section-title { font-weight: 700; color: #1e293b; margin-bottom: 20px; }
        
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
                <small class="d-block text-muted">Admin Panel</small>
            </div>
        </div>
        
        <div class="nav-menu px-3 mt-2">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="admin_dashboard.php" class="nav-link active"><i class="fas fa-tachometer-alt me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="manage_products.php" class="nav-link"><i class="fas fa-box me-3"></i> <span>Manage Products</span></a></li>
                <li class="nav-item"><a href="manage_orders.php" class="nav-link"><i class="fas fa-shopping-cart me-3"></i> <span>Manage Orders</span></a></li>
                <li class="nav-item"><a href="manage_users.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Users</span></a></li>
                <li class="nav-item"><a href="manage_employees.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Employees</span></a></li>
                <li class="nav-item"><a href="support_tickets.php" class="nav-link"><i class="fas fa-headset me-3"></i> <span>Support Tickets</span></a></li>
                <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar me-3"></i> <span>Reports & Analytics</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user me-3"></i> <span>My Profile</span></a></li>
            </ul>
        </div>
        
        <div class="logout-section">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt me-3"></i> <span>Logout</span></a></li>
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
                    <h4 class="fw-bold mb-0">Admin Dashboard</h4>
                    <p class="text-muted mb-0 d-none d-sm-block">Welcome back, <?php echo htmlspecialchars($adminName); ?>!</p>
                </div>
            </div>
            
            <div class="dropdown">
                <button class="btn btn-light d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-sm" data-bs-toggle="dropdown">
                    <?php if (!empty($admin['profile_picture']) && file_exists('../' . $admin['profile_picture'])): ?>
                        <img src="../<?php echo $admin['profile_picture']; ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <span class="fw-bold fs-6"><?php echo strtoupper(substr($adminName, 0, 1)); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="text-start d-none d-md-block">
                        <div class="fw-semibold"><?php echo htmlspecialchars($adminName); ?></div>
                        <small class="text-muted">Administrator</small>
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

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-primary text-white me-3">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Orders</div>
                            <div class="fw-bold fs-3"><?php echo number_format($totalOrders); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-success text-white me-3">
                            <i class="fas fa-peso-sign"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Revenue</div>
                            <div class="fw-bold fs-3">₱<?php echo number_format($totalRevenue, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-info text-white me-3">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Customers</div>
                            <div class="fw-bold fs-3"><?php echo number_format($totalCustomers); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-warning text-white me-3">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Pending Orders</div>
                            <div class="fw-bold fs-3"><?php echo number_format($pendingOrders); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Recent Orders -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="section-title mb-0">Recent Orders</h5>
                            <a href="manage_orders.php" class="btn btn-sm btn-outline-primary">View All</a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4">Order ID</th>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th class="text-end pe-4">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recentOrders->num_rows > 0): ?>
                                        <?php while ($order = $recentOrders->fetch_assoc()) { ?>
                                            <tr>
                                                <td class="ps-4"><strong>#<?php echo $order['orderID']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                                <td class="fw-semibold">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                                <td>
                                                    <?php
                                                    $statusClass = 'bg-secondary';
                                                    if ($order['status'] == 'Pending') $statusClass = 'bg-warning text-dark';
                                                    elseif ($order['status'] == 'Processing') $statusClass = 'bg-info text-white';
                                                    elseif ($order['status'] == 'Out for Delivery') $statusClass = 'bg-primary text-white';
                                                    elseif ($order['status'] == 'Delivered') $statusClass = 'bg-success text-white';
                                                    elseif ($order['status'] == 'Cancelled') $statusClass = 'bg-danger text-white';
                                                    ?>
                                                    <span class="badge <?php echo $statusClass; ?> px-3 py-2"><?php echo $order['status']; ?></span>
                                                </td>
                                                <td class="small text-muted"><?php echo date('M j, g:i A', strtotime($order['order_date'])); ?></td>
                                                <td class="text-end pe-4">
                                                    <a href="manage_orders.php?view=<?php echo $order['orderID']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">No orders yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions & Stats -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0">Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="manage_products.php?action=add" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i> Add New Product
                            </a>
                            <a href="manage_orders.php" class="btn btn-outline-primary">
                                <i class="fas fa-tasks me-2"></i> Process Pending Orders
                            </a>
                            <a href="manage_users.php?filter=pending" class="btn btn-outline-warning">
                                <i class="fas fa-user-check me-2"></i> Review Verifications
                            </a>
                            <a href="support_tickets.php" class="btn btn-outline-info">
                                <i class="fas fa-headset me-2"></i> Handle Support Tickets
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Additional Stats -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0">System Overview</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div><i class="fas fa-users text-primary me-2"></i> Active Employees</div>
                            <span class="fw-bold"><?php echo $activeEmployees; ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div><i class="fas fa-ticket-alt text-warning me-2"></i> Open Tickets</div>
                            <span class="fw-bold"><?php echo $openTickets; ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <div><i class="fas fa-user-clock text-info me-2"></i> Pending Verifications</div>
                            <span class="fw-bold"><?php echo $pendingVerifications->num_rows; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Verifications -->
        <?php if ($pendingVerifications->num_rows > 0): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="section-title mb-0">Pending Account Verifications</h5>
                            <a href="manage_users.php?filter=pending" class="btn btn-sm btn-warning">Review All</a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4">Customer</th>
                                        <th>Email</th>
                                        <th>Registered</th>
                                        <th class="text-end pe-4">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($cust = $pendingVerifications->fetch_assoc()) { ?>
                                        <tr>
                                            <td class="ps-4"><strong><?php echo htmlspecialchars($cust['Firstname'] . ' ' . $cust['Lastname']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($cust['Email']); ?></td>
                                            <td class="small text-muted"><?php echo date('M j, Y', strtotime($cust['created_at'])); ?></td>
                                            <td class="text-end pe-4">
                                                <a href="manage_users.php?verify=<?php echo $cust['userID']; ?>" class="btn btn-sm btn-success">Approve</a>
                                                <a href="manage_users.php?reject=<?php echo $cust['userID']; ?>" class="btn btn-sm btn-outline-danger">Reject</a>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
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
    </script>
</body>
</html>