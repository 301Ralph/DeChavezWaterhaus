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
$activeEmployees = 0;
try {
    $activeEmployees = $conn->query("SELECT COUNT(*) as count FROM customers WHERE Role = 'employee'")->fetch_assoc()['count'] ?? 0;
} catch (Exception $e) {}
$openTickets = 0;
try {
    $openTickets = $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE status IN ('Open', 'In Progress')")->fetch_assoc()['count'] ?? 0;
} catch (Exception $e) {}

// Low Stock Check (products with stock <= 10)
$lowStockProducts = [];
try {
    $lowStockResult = $conn->query("SELECT ProductName, Stock FROM product WHERE Stock <= 10 ORDER BY Stock ASC LIMIT 5");
    while ($row = $lowStockResult->fetch_assoc()) {
        $lowStockProducts[] = $row;
    }
} catch (Exception $e) {}

// New Orders Today
$newOrdersToday = 0;
try {
    $newOrdersToday = $conn->query("SELECT COUNT(*) as count FROM orders WHERE DATE(order_date) = CURDATE()")->fetch_assoc()['count'] ?? 0;
} catch (Exception $e) {}

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

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $adminID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard • De Chavez Waterhaus</title>
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

        /* ── ADMIN CARDS ── */
        .admin-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 28px;
        }

        .stat-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 24px;
            transition: transform 0.3s ease;
        }

        .stat-card:hover { transform: translateY(-4px); }

        .stat-icon {
            width: 56px; height: 56px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
        }

        .section-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.35rem;
            font-weight: 500;
            color: var(--white);
            margin-bottom: 20px;
        }

        /* Modal */
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
            .admin-card { padding: 18px; }
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
        <a href="admin_dashboard.php" class="nav-link active">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <a href="manage_products.php" class="nav-link">
            <i class="fas fa-box"></i> Manage Products
        </a>
        <a href="manage_orders.php" class="nav-link">
            <i class="fas fa-shopping-cart"></i> Manage Orders
        </a>
        <a href="manage_users.php" class="nav-link">
            <i class="fas fa-users"></i> Manage Users
        </a>
        <a href="manage_employees.php" class="nav-link">
            <i class="fas fa-user-tie"></i> Manage Employees
        </a>

        <div class="nav-section-label">Operations</div>
        <a href="attendance_management.php" class="nav-link">
            <i class="fas fa-clock"></i> Attendance
        </a>
        <a href="payroll_management.php" class="nav-link">
            <i class="fas fa-money-bill"></i> Payroll
        </a>
        <a href="generate_payslip.php" class="nav-link">
            <i class="fas fa-file-pdf"></i> Generate Payslip
        </a>
        <a href="leave_management.php" class="nav-link">
            <i class="fas fa-calendar-alt"></i> Manage Leave
        </a>

        <div class="nav-section-label">Support & Reports</div>
        <a href="support_tickets.php" class="nav-link">
            <i class="fas fa-headset"></i> Support Tickets
        </a>
        <a href="reports.php" class="nav-link">
            <i class="fas fa-chart-bar"></i> Reports & Analytics
        </a>

        <div class="nav-section-label" style="margin-top: 16px;"></div>
        <a href="profile.php" class="nav-link">
            <i class="fas fa-user"></i> My Profile
        </a>
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
                <h4>Admin Dashboard</h4>
                <p>Welcome back, <?php echo htmlspecialchars($adminName); ?>!</p>
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
                    <?php if (!empty($admin['profile_picture']) && file_exists('../' . $admin['profile_picture'])): ?>
                        <img src="../<?php echo htmlspecialchars($admin['profile_picture']); ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <div class="avatar-circle">
                            <?php echo strtoupper(substr($adminName, 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($adminName); ?></div>
                        <div class="avatar-role">Administrator</div>
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
            <div class="admin-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="section-title mb-0">Recent Orders</h5>
                    <a href="manage_orders.php" class="btn btn-sm btn-glass">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle" style="color: var(--foam);">
                        <thead style="background: rgba(4,30,53,0.6);">
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
                                        <td class="small" style="color: rgba(202,240,248,0.5);"><?php echo date('M j, g:i A', strtotime($order['order_date'])); ?></td>
                                        <td class="text-end pe-4">
                                            <a href="manage_orders.php?view=<?php echo $order['orderID']; ?>" class="btn btn-sm btn-glass">View</a>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4" style="color: rgba(202,240,248,0.4);">No orders yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Quick Actions & Stats -->
        <div class="col-lg-4">
            <div class="admin-card mb-4">
                <h5 class="section-title mb-0">Quick Actions</h5>
                <div class="d-grid gap-2 mt-3">
                    <a href="manage_products.php?action=add" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i> Add New Product
                    </a>
                    <a href="manage_orders.php" class="btn btn-glass">
                        <i class="fas fa-tasks me-2"></i> Process Pending Orders
                    </a>
                    <a href="manage_users.php?filter=pending" class="btn btn-glass">
                        <i class="fas fa-user-check me-2"></i> Review Verifications
                    </a>
                    <a href="support_tickets.php" class="btn btn-glass">
                        <i class="fas fa-headset me-2"></i> Handle Support Tickets
                    </a>
                </div>
            </div>

            <!-- Additional Stats -->
            <div class="admin-card">
                <h5 class="section-title mb-0">System Overview</h5>
                <div class="mt-3">
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
            <div class="admin-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="section-title mb-0">Pending Account Verifications</h5>
                    <a href="manage_users.php?filter=pending" class="btn btn-sm btn-warning">Review All</a>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle" style="color: var(--foam);">
                        <thead style="background: rgba(4,30,53,0.6);">
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
                                    <td class="small" style="color: rgba(202,240,248,0.5);"><?php echo date('M j, Y', strtotime($cust['created_at'])); ?></td>
                                    <td class="text-end pe-4">
                                        <a href="manage_users.php?verify=<?php echo $cust['userID']; ?>" class="btn btn-sm btn-success">Approve</a>
                                        <a href="manage_users.php?reject=<?php echo $cust['userID']; ?>" class="btn btn-sm btn-glass">Reject</a>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</main>

<!-- Toast Container for Pop-up Notifications -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999;">
    
    <!-- Low Stock Toast -->
    <?php if (!empty($lowStockProducts)): ?>
    <div id="lowStockToast" class="toast align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="false">
        <div class="d-flex">
            <div class="toast-body">
                <div class="d-flex align-items-center mb-2">
                    <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                    <div>
                        <strong class="fs-5">Low Stock Alert!</strong><br>
                        <small>The following products are running low:</small>
                    </div>
                </div>
                <ul class="mb-0 ps-4 small">
                    <?php foreach ($lowStockProducts as $product): ?>
                        <li><strong><?php echo htmlspecialchars($product['ProductName']); ?></strong> — Only <?php echo $product['Stock']; ?> left</li>
                    <?php endforeach; ?>
                </ul>
                <div class="mt-2">
                    <a href="manage_products.php" class="btn btn-sm btn-light">Manage Stock</a>
                </div>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
    <?php endif; ?>

    <!-- New Orders Toast -->
    <?php if ($newOrdersToday > 0): ?>
    <div id="newOrdersToast" class="toast align-items-center text-white bg-success border-0 mt-2" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="6000">
        <div class="d-flex">
            <div class="toast-body">
                <div class="d-flex align-items-center">
                    <i class="fas fa-shopping-cart fa-2x me-3"></i>
                    <div>
                        <strong class="fs-5">New Order<?php echo $newOrdersToday > 1 ? 's' : ''; ?> Today!</strong><br>
                        <span><?php echo $newOrdersToday; ?> new order<?php echo $newOrdersToday > 1 ? 's have' : ' has'; ?> been placed.</span>
                    </div>
                </div>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
    <?php endif; ?>

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

    // Show popup notifications on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Low Stock Toast
        const lowStockToastEl = document.getElementById('lowStockToast');
        if (lowStockToastEl) {
            const lowStockToast = new bootstrap.Toast(lowStockToastEl, { delay: 10000 });
            lowStockToast.show();
        }

        // New Orders Toast
        const newOrdersToastEl = document.getElementById('newOrdersToast');
        if (newOrdersToastEl) {
            const newOrdersToast = new bootstrap.Toast(newOrdersToastEl);
            setTimeout(() => {
                newOrdersToast.show();
            }, 1500);
        }
    });
</script>
</body>
</html>