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

// Fetch user data
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

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

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName = explode(' ', $userName)[0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Tracking • De Chavez Waterhaus</title>
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

        /* ── ORDER CARDS ── */
        .order-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 24px;
            transition: all 0.35s cubic-bezier(0.23,1,0.32,1);
        }

        .order-card:hover {
            transform: translateY(-6px);
            border-color: rgba(0,180,216,0.3);
            box-shadow: 0 20px 45px rgba(0,0,0,0.35);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .order-id {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--white);
        }

        .order-date {
            font-size: 0.78rem;
            color: rgba(202,240,248,0.4);
            margin-top: 4px;
        }

        .status-pill {
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .pill-Pending { background: rgba(244,200,66,0.12); color: var(--gold); border: 1px solid rgba(244,200,66,0.25); }
        .pill-Processing { background: rgba(0,180,216,0.1); color: var(--aqua); border: 1px solid rgba(0,180,216,0.25); }
        .pill-Out-for-Delivery { background: rgba(0,119,182,0.15); color: #60a5fa; border: 1px solid rgba(96,165,250,0.3); }
        .pill-Delivered { background: rgba(74,222,128,0.1); color: #4ade80; border: 1px solid rgba(74,222,128,0.25); }
        .pill-Cancelled { background: rgba(248,113,113,0.1); color: #fca5a5; border: 1px solid rgba(248,113,113,0.25); }

        /* ── TIMELINE ── */
        .timeline {
            position: relative;
            padding-left: 32px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 11px;
            top: 8px;
            bottom: 8px;
            width: 3px;
            background: rgba(72,202,228,0.2);
        }

        .timeline-step {
            position: relative;
            margin-bottom: 18px;
        }

        .timeline-step:last-child { margin-bottom: 0; }

        .timeline-dot {
            position: absolute;
            left: -32px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--ocean);
            border: 3px solid var(--aqua);
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .timeline-step.completed .timeline-dot {
            background: #22c55e;
            border-color: #22c55e;
        }

        .timeline-step.current .timeline-dot {
            background: var(--aqua);
            border-color: var(--aqua);
            box-shadow: 0 0 0 6px rgba(0,180,216,0.2);
            animation: pulse 2s infinite;
        }

        .timeline-step.cancelled .timeline-dot {
            background: #ef4444;
            border-color: #ef4444;
        }

        .timeline-dot i {
            font-size: 0.7rem;
            color: white;
        }

        .timeline-content {
            padding-left: 8px;
        }

        .timeline-title {
            font-weight: 600;
            color: var(--white);
            font-size: 0.92rem;
        }

        .timeline-subtitle {
            font-size: 0.75rem;
            color: rgba(202,240,248,0.4);
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(0,180,216,0.4); }
            70% { box-shadow: 0 0 0 10px rgba(0,180,216,0); }
            100% { box-shadow: 0 0 0 0 rgba(0,180,216,0); }
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

        .modal-footer {
            border-top: 1px solid var(--glass-border);
            padding: 20px 24px;
        }

        .modal-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.35rem;
            font-weight: 500;
        }

        /* Mobile */
        @media (max-width: 991px) {
            .main-content { margin-left: 0; padding: 20px 18px; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
            .order-card { padding: 18px; }
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
        <a href="products.php" class="nav-link">
            <i class="fas fa-droplet"></i> Products
        </a>
        <a href="order_history.php" class="nav-link">
            <i class="fas fa-history"></i> Order History
        </a>
        <a href="order_tracking.php" class="nav-link active">
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
                <h4>Order Tracking</h4>
                <p>Real-time status of your water deliveries</p>
            </div>
        </div>

        <div class="topbar-actions">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if ($notifCount > 0): ?>
                    <span class="topbar-notif-badge"><?php echo $notifCount > 9 ? '9+' : $notifCount; ?></span>
                <?php endif; ?>
            </a>

            <a href="products.php" class="btn btn-primary px-4 rounded-pill">
                <i class="fas fa-plus me-2"></i> New Order
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

    <!-- Orders -->
    <?php if ($ordersResult->num_rows > 0): ?>
        <div class="row g-3">
            <?php while ($order = $ordersResult->fetch_assoc()) { 
                $status = $order['status'];
                $badgeClass = match($status) {
                    'Processing'       => 'pill-Processing',
                    'Out for Delivery' => 'pill-Out-for-Delivery',
                    'Delivered'        => 'pill-Delivered',
                    'Cancelled'        => 'pill-Cancelled',
                    default            => 'pill-Pending'
                };
            ?>
                <div class="col-lg-6">
                    <div class="order-card">
                        <div class="order-header">
                            <div>
                                <div class="order-id">#<?php echo $order['orderID']; ?></div>
                                <div class="order-date">
                                    <i class="fas fa-calendar me-1"></i>
                                    <?php echo date('F j, Y • g:i A', strtotime($order['order_date'])); ?>
                                </div>
                            </div>
                            <span class="status-pill <?php echo $badgeClass; ?>">
                                <?php echo $status; ?>
                            </span>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span style="color: rgba(202,240,248,0.5);">Product</span>
                                <span style="color: var(--foam);"><?php echo $order['ProductName']; ?> × <?php echo $order['quantity']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span style="color: rgba(202,240,248,0.5);">Total</span>
                                <span class="fw-bold" style="color: var(--aqua);">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span style="color: rgba(202,240,248,0.5);">Payment</span>
                                <span><?php echo $order['payment_method']; ?></span>
                            </div>
                        </div>

                        <!-- Timeline -->
                        <div class="timeline mt-4">
                            <?php if ($status != 'Cancelled'): ?>
                                <div class="timeline-step <?php echo ($status != 'Pending') ? 'completed' : 'current'; ?>">
                                    <div class="timeline-dot"><i class="fas fa-check"></i></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Order Placed</div>
                                        <div class="timeline-subtitle"><?php echo date('M j, g:i A', strtotime($order['order_date'])); ?></div>
                                    </div>
                                </div>
                                
                                <div class="timeline-step <?php echo ($status == 'Processing' || $status == 'Out for Delivery' || $status == 'Delivered') ? 'completed' : ($status == 'Pending' ? '' : 'current'); ?>">
                                    <div class="timeline-dot"><i class="fas fa-cog"></i></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Processing</div>
                                        <div class="timeline-subtitle">Preparing your order</div>
                                    </div>
                                </div>
                                
                                <div class="timeline-step <?php echo ($status == 'Out for Delivery' || $status == 'Delivered') ? 'completed' : ($status == 'Processing' ? 'current' : ''); ?>">
                                    <div class="timeline-dot"><i class="fas fa-truck"></i></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Out for Delivery</div>
                                        <div class="timeline-subtitle">
                                            <?php echo $order['delivery_date'] ? date('M j, Y', strtotime($order['delivery_date'])) : 'Scheduled soon'; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="timeline-step <?php echo ($status == 'Delivered') ? 'completed' : ($status == 'Out for Delivery' ? 'current' : ''); ?>">
                                    <div class="timeline-dot"><i class="fas fa-home"></i></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Delivered</div>
                                        <div class="timeline-subtitle">Enjoy your water!</div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="timeline-step cancelled">
                                    <div class="timeline-dot"><i class="fas fa-times"></i></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title text-danger">Order Cancelled</div>
                                        <div class="timeline-subtitle">This order has been cancelled</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 pt-3" style="border-top: 1px solid var(--glass-border);">
                            <button class="btn btn-glass w-100" data-bs-toggle="modal" data-bs-target="#trackModal<?php echo $order['orderID']; ?>">
                                <i class="fas fa-map-marker-alt me-2"></i> View Full Details
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Order Detail Modal -->
                <div class="modal fade" id="trackModal<?php echo $order['orderID']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-receipt me-2"></i> Order #<?php echo $order['orderID']; ?>
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-6">
                                        <div style="color: rgba(202,240,248,0.5); font-size: 0.78rem;">Order Date</div>
                                        <div style="color: var(--foam);"><?php echo date('F j, Y g:i A', strtotime($order['order_date'])); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div style="color: rgba(202,240,248,0.5); font-size: 0.78rem;">Status</div>
                                        <div><span class="status-pill <?php echo $badgeClass; ?>"><?php echo $status; ?></span></div>
                                    </div>
                                    <div class="col-6">
                                        <div style="color: rgba(202,240,248,0.5); font-size: 0.78rem;">Product</div>
                                        <div style="color: var(--foam);"><?php echo $order['ProductName']; ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div style="color: rgba(202,240,248,0.5); font-size: 0.78rem;">Quantity</div>
                                        <div style="color: var(--foam);"><?php echo $order['quantity']; ?> gallons</div>
                                    </div>
                                    <div class="col-6">
                                        <div style="color: rgba(202,240,248,0.5); font-size: 0.78rem;">Total Amount</div>
                                        <div class="fw-bold" style="color: var(--aqua);">₱<?php echo number_format($order['total_amount'], 2); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div style="color: rgba(202,240,248,0.5); font-size: 0.78rem;">Payment</div>
                                        <div style="color: var(--foam);"><?php echo $order['payment_method']; ?></div>
                                    </div>
                                    <?php if ($order['delivery_date']): ?>
                                    <div class="col-12">
                                        <div style="color: rgba(202,240,248,0.5); font-size: 0.78rem;">Scheduled Delivery</div>
                                        <div style="color: var(--foam);"><?php echo date('F j, Y', strtotime($order['delivery_date'])); ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-glass" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
    <?php else: ?>
        <div class="dash-card text-center py-5">
            <i class="fas fa-box-open fa-4x mb-4" style="color: rgba(0,180,216,0.15);"></i>
            <h5 class="fw-semibold mb-2">No Orders Yet</h5>
            <p class="text-muted mb-4">You haven't placed any orders yet.</p>
            <a href="products.php" class="btn btn-primary px-5">
                <i class="fas fa-shopping-cart me-2"></i> Browse Products
            </a>
        </div>
    <?php endif; ?>

</main>

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

    // Auto-refresh every 30 seconds
    setTimeout(() => {
        location.reload();
    }, 30000);
</script>
</body>
</html>