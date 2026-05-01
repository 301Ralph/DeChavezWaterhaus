<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo '<script>alert("Access denied. Admins only."); window.location = "../login.php";</script>';
    exit();
}

$adminName = $_SESSION['userName'] ?? 'Admin';

// Fetch admin data for profile picture
$admin = $conn->query("SELECT * FROM customers WHERE userID = " . $_SESSION['userID'])->fetch_assoc();

// Handle Accept Pending Order (with receipt verification)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accept_order'])) {
    $orderID = intval($_POST['orderID']);
    
    // Update status to Processing
    $stmt = $conn->prepare("UPDATE orders SET status = 'Processing' WHERE orderID = ?");
    $stmt->bind_param("i", $orderID);
    $stmt->execute();
    $stmt->close();
    
    // Create notification for customer
    $order = $conn->query("SELECT userID FROM orders WHERE orderID = $orderID")->fetch_assoc();
    if ($order) {
        $message = "Your order #$orderID has been accepted and is now being processed!";
        $stmt = $conn->prepare("INSERT INTO notifications (userID, message) VALUES (?, ?)");
        $stmt->bind_param("is", $order['userID'], $message);
        $stmt->execute();
        $stmt->close();
    }
    
    echo '<script>alert("Order accepted! Status changed to Processing."); window.location = "manage_orders.php";</script>';
    exit();
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $orderID = intval($_POST['orderID']);
    $newStatus = $_POST['status'];
    $employeeID = isset($_POST['employeeID']) ? intval($_POST['employeeID']) : null;

    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE orderID = ?");
    $stmt->bind_param("si", $newStatus, $orderID);
    $stmt->execute();
    $stmt->close();

    // If assigning to employee and status is Out for Delivery
    if ($employeeID && $newStatus == 'Out for Delivery') {
        // Check if delivery record exists
        $checkDelivery = $conn->query("SELECT deliveryID FROM deliveries WHERE orderID = $orderID");
        if ($checkDelivery->num_rows > 0) {
            // Update existing delivery record
            $conn->query("UPDATE deliveries SET riderID = $employeeID, status = 'In Transit' WHERE orderID = $orderID");
        } else {
            // Create new delivery record
            $conn->query("INSERT INTO deliveries (orderID, riderID, delivery_date, status) VALUES ($orderID, $employeeID, CURDATE(), 'In Transit')");
        }
    }

    // Create notification for customer
    $order = $conn->query("SELECT userID FROM orders WHERE orderID = $orderID")->fetch_assoc();
    if ($order) {
        $message = "Your order #$orderID status has been updated to: $newStatus";
        $stmt = $conn->prepare("INSERT INTO notifications (userID, message) VALUES (?, ?)");
        $stmt->bind_param("is", $order['userID'], $message);
        $stmt->execute();
        $stmt->close();
    }

    echo '<script>alert("Order status updated!"); window.location = "manage_orders.php";</script>';
    exit();
}

// Fetch pending orders (need receipt verification before accepting)
$pendingOrders = $conn->query("
    SELECT o.*, CONCAT(c.Firstname, ' ', c.Lastname) as customer_name, c.Contact as customer_phone,
           p.ProductName, oi.quantity
    FROM orders o
    JOIN customers c ON o.userID = c.userID
    LEFT JOIN order_items oi ON o.orderID = oi.orderID
    LEFT JOIN product p ON oi.productID = p.ProductID
    WHERE o.status = 'Pending'
    ORDER BY o.order_date DESC
");

// Fetch all other orders (Processing, Out for Delivery, Delivered, Cancelled)
$orders = $conn->query("
    SELECT o.*, CONCAT(c.Firstname, ' ', c.Lastname) as customer_name, c.Contact as customer_phone,
           d.delivery_date, d.riderID,
           p.ProductName, oi.quantity,
           CONCAT(e.Firstname, ' ', e.Lastname) as employee_name
    FROM orders o
    JOIN customers c ON o.userID = c.userID
    LEFT JOIN deliveries d ON o.orderID = d.orderID
    LEFT JOIN order_items oi ON o.orderID = oi.orderID
    LEFT JOIN product p ON oi.productID = p.ProductID
    LEFT JOIN customers e ON d.riderID = e.userID
    WHERE o.status != 'Pending'
    ORDER BY o.order_date DESC
");

// Fetch active employees for assignment
$employees = $conn->query("SELECT userID, CONCAT(Firstname, ' ', Lastname) as name FROM customers WHERE Role = 'employee' AND verification_status = 'approved'");
$employeeList = [];
while ($e = $employees->fetch_assoc()) {
    $employeeList[] = $e;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders • Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <!-- DataTables removed - using simple table -->
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
        
        .status-badge { padding: 6px 14px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
        .table thead th { font-weight: 600; border-bottom: 2px solid #dee2e6; }
        .table tbody tr:hover { background-color: #f8f9fa; }
        .table td { vertical-align: middle; }
        
        /* Simple table styling - DataTables removed */
        
        .dataTables_wrapper .dataTables_info {
            color: #6c757d;
            font-size: 0.9rem;
            padding-top: 15px;
        }
        
        .table thead th {
            border-bottom: 2px solid #e9ecef;
            font-weight: 600;
            color: #495057;
            padding: 15px 12px;
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
            transform: scale(1.01);
        }
        
        .table td {
            padding: 15px 12px;
            vertical-align: middle;
        }
        
        .dataTables_wrapper {
            padding: 20px;
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
                <li class="nav-item"><a href="admin_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="manage_products.php" class="nav-link"><i class="fas fa-box me-3"></i> <span>Manage Products</span></a></li>
                <li class="nav-item"><a href="manage_orders.php" class="nav-link active"><i class="fas fa-shopping-cart me-3"></i> <span>Manage Orders</span></a></li>
                <li class="nav-item"><a href="manage_users.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Users</span></a></li>
                <li class="nav-item"><a href="manage_employees.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Employees</span></a></li>
                <li class="nav-item"><a href="attendance_management.php" class="nav-link"><i class="fas fa-clock me-3"></i> <span>Attendance</span></a></li>
                <li class="nav-item"><a href="payroll_management.php" class="nav-link"><i class="fas fa-money-bill me-3"></i> <span>Payroll</span></a></li>
                <li class="nav-item"><a href="generate_payslip.php" class="nav-link"><i class="fas fa-file-pdf me-3"></i> <span>Generate Payslip</span></a></li>
                <li class="nav-item"><a href="leave_management.php" class="nav-link"><i class="fas fa-calendar-alt me-3"></i> <span>Manage Leave</span></a></li>
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
                    <h4 class="fw-bold mb-0">Manage Orders</h4>
                    <p class="text-muted mb-0 d-none d-sm-block">Approve, assign, and track all customer orders</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <!-- Notification Bell -->
                <div class="dropdown">
                    <button class="btn btn-light position-relative" data-bs-toggle="dropdown" style="width: 42px; height: 42px; border-radius: 12px;">
                        <i class="fas fa-bell fa-lg"></i>
                        <?php 
                        $unreadCount = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE userID = " . $_SESSION['userID'] . " AND is_read = 0")->fetch_assoc()['count'] ?? 0;
                        if ($unreadCount > 0): 
                        ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 9px; padding: 2px 6px;">
                                <?php echo min($unreadCount, 9); ?><?php echo $unreadCount > 9 ? '+' : ''; ?>
                            </span>
                        <?php endif; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow" style="width: 320px; max-height: 400px; overflow-y: auto;">
                        <li class="dropdown-header fw-bold">Notifications</li>
                        <?php 
                        $notifs = $conn->query("SELECT * FROM notifications WHERE userID = " . $_SESSION['userID'] . " ORDER BY created_at DESC LIMIT 5");
                        if ($notifs->num_rows > 0):
                            while ($n = $notifs->fetch_assoc()):
                        ?>
                            <li><a class="dropdown-item small" href="notifications.php"><?php echo htmlspecialchars($n['message']); ?></a></li>
                        <?php endwhile; else: ?>
                            <li><span class="dropdown-item text-muted small">No new notifications</span></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-center small text-primary" href="notifications.php">View All</a></li>
                    </ul>
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
        </div>

        <!-- Pending Orders Section (Need Receipt Verification) -->
        <?php if ($pendingOrders && $pendingOrders->num_rows > 0): ?>
        <div class="card border-0 shadow-sm mb-4" style="border-left: 4px solid #ffc107;">
            <div class="card-header bg-warning bg-opacity-10 border-0 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-bold mb-0 text-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i> Pending Orders - Awaiting Receipt Verification
                        </h6>
                        <small class="text-muted">Review GCash receipts before accepting these orders</small>
                    </div>
                    <span class="badge bg-warning text-dark"><?php echo $pendingOrders->num_rows; ?> Pending</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Order ID</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Amount</th>
                                <th>Payment</th>
                                <th>Date</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($pending = $pendingOrders->fetch_assoc()) { ?>
                                <tr>
                                    <td class="ps-4"><strong>#<?php echo $pending['orderID']; ?></strong></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($pending['customer_name']); ?></div>
                                        <small class="text-muted"><?php echo $pending['customer_phone']; ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($pending['ProductName'] ?? 'N/A'); ?></div>
                                        <small class="text-muted">x<?php echo $pending['quantity'] ?? 0; ?></small>
                                    </td>
                                    <td class="fw-bold">₱<?php echo number_format($pending['total_amount'], 2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $pending['payment_method'] == 'GCash' ? 'info' : 'secondary'; ?>">
                                            <?php echo $pending['payment_method']; ?>
                                        </span>
                                        <?php if ($pending['payment_method'] == 'GCash' && !empty($pending['notes'])): ?>
                                            <button class="btn btn-sm btn-outline-primary ms-1" data-bs-toggle="modal" data-bs-target="#receiptModal<?php echo $pending['orderID']; ?>">
                                                <i class="fas fa-receipt"></i> View Receipt
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?php echo date('M j, Y', strtotime($pending['order_date'])); ?></td>
                                    <td class="text-end pe-4">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Accept this order and change status to Processing?')">
                                            <input type="hidden" name="orderID" value="<?php echo $pending['orderID']; ?>">
                                            <button type="submit" name="accept_order" class="btn btn-success btn-sm">
                                                <i class="fas fa-check me-1"></i> Accept Order
                                            </button>
                                        </form>
                                    </td>
                                </tr>

                                <!-- Receipt Modal -->
                                <?php if ($pending['payment_method'] == 'GCash' && !empty($pending['notes'])): ?>
                                <div class="modal fade" id="receiptModal<?php echo $pending['orderID']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title fw-bold">GCash Receipt - Order #<?php echo $pending['orderID']; ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body text-center">
                                                <?php 
                                                    // Extract file path from notes (format: "GCash Receipt: uploads/receipts/filename.jpg")
                                                    $receiptPath = $pending['notes'];
                                                    if (strpos($receiptPath, 'GCash Receipt: ') === 0) {
                                                        $receiptPath = substr($receiptPath, strlen('GCash Receipt: '));
                                                    }
                                                    $fullPath = '../' . $receiptPath;
                                                    ?>
                                                    <?php if (file_exists($fullPath)): ?>
                                                        <img src="<?php echo htmlspecialchars($fullPath); ?>" class="img-fluid rounded" style="max-height: 500px;" alt="GCash Receipt">
                                                    <?php else: ?>
                                                        <div class="alert alert-warning">
                                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                                            Receipt file not found: <?php echo htmlspecialchars($receiptPath); ?>
                                                        </div>
                                                    <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Accept this order?')">
                                                    <input type="hidden" name="orderID" value="<?php echo $pending['orderID']; ?>">
                                                    <button type="submit" name="accept_order" class="btn btn-success">
                                                        <i class="fas fa-check me-1"></i> Accept & Process Order
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Orders Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Order ID</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Amount</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Date</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($orders->num_rows > 0): ?>
                                <?php while ($order = $orders->fetch_assoc()) { ?>
                                    <tr>
                                        <td class="ps-4"><strong>#<?php echo $order['orderID']; ?></strong></td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                                            <small class="text-muted"><?php echo $order['customer_phone']; ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($order['ProductName'] ?? 'N/A'); ?></div>
                                            <small class="text-muted">x<?php echo $order['quantity'] ?? 0; ?></small>
                                        </td>
                                        <td class="fw-bold">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $order['payment_method'] == 'GCash' ? 'info' : 'secondary'; ?>">
                                                <?php echo $order['payment_method']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $statusClass = 'bg-secondary';
                                            if ($order['status'] == 'Pending') $statusClass = 'bg-warning text-dark';
                                            elseif ($order['status'] == 'Processing') $statusClass = 'bg-info text-white';
                                            elseif ($order['status'] == 'Out for Delivery') $statusClass = 'bg-primary text-white';
                                            elseif ($order['status'] == 'Delivered') $statusClass = 'bg-success text-white';
                                            elseif ($order['status'] == 'Cancelled') $statusClass = 'bg-danger text-white';
                                            ?>
                                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo $order['status']; ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($order['employee_name'])): ?>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($order['employee_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                        <td class="text-end pe-4">
                                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#updateModal<?php echo $order['orderID']; ?>">
                                                <i class="fas fa-edit me-1"></i> Update
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Update Status Modal -->
                                    <div class="modal fade" id="updateModal<?php echo $order['orderID']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title fw-bold">Update Order #<?php echo $order['orderID']; ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <input type="hidden" name="orderID" value="<?php echo $order['orderID']; ?>">
                                                    <div class="modal-body p-4">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Current Status</label>
                                                            <div><span class="status-badge <?php echo $statusClass; ?>"><?php echo $order['status']; ?></span></div>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">New Status</label>
                                                            <select class="form-select" name="status" required>
                                                                <option value="Pending" <?php echo $order['status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                                                <option value="Processing" <?php echo $order['status'] == 'Processing' ? 'selected' : ''; ?>>Processing</option>
                                                                <option value="Out for Delivery" <?php echo $order['status'] == 'Out for Delivery' ? 'selected' : ''; ?>>Out for Delivery</option>
                                                                <option value="Delivered" <?php echo $order['status'] == 'Delivered' ? 'selected' : ''; ?>>Delivered</option>
                                                                <option value="Cancelled" <?php echo $order['status'] == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                                            </select>
                                                        </div>

                                                        <div class="mb-3" id="employeeAssign<?php echo $order['orderID']; ?>" style="display: <?php echo $order['status'] == 'Out for Delivery' ? 'block' : 'none'; ?>;">
                                                            <label class="form-label fw-semibold">Assign to Employee</label>
                                                            <select class="form-select" name="employeeID">
                                                                <option value="">Select Employee</option>
                                                                <?php foreach ($employeeList as $employee) { ?>
                                                                    <option value="<?php echo $employee['userID']; ?>" <?php echo ($order['riderID'] == $employee['userID']) ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($employee['name']); ?>
                                                                    </option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer border-0 p-4 pt-0">
                                                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="update_status" class="btn btn-primary px-5">Update Status</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php } ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3 opacity-50"></i>
                                        <p>No orders yet.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- DataTables removed - using simple Bootstrap table -->
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

        // Show/hide employee assignment based on status
        document.querySelectorAll('[name="status"]').forEach(select => {
            select.addEventListener('change', function() {
                const modalId = this.closest('.modal').id;
                const employeeDiv = document.getElementById('employeeAssign' + modalId.replace('updateModal', ''));
                if (employeeDiv) {
                    employeeDiv.style.display = this.value === 'Out for Delivery' ? 'block' : 'none';
                }
            });
        });

        // Simple table - no DataTables needed
    </script>
</body>
</html>