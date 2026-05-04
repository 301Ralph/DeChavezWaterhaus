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

$customerColumn = 'userID';

// Handle Cancel Order
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_order'])) {
    $orderID = intval($_POST['orderID']);
    
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

// Fetch orders
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
    <title>Order History • De Chavez Waterhaus</title>
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
            position: relative;
            overflow: hidden;
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
            font-size: 1.6rem;
            font-weight: 600;
            color: var(--white);
        }

        .order-date {
            font-size: 0.82rem;
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

        .product-list {
            background: rgba(4,30,53,0.5);
            border-radius: 12px;
            padding: 16px;
            margin: 16px 0;
        }

        .product-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid rgba(72,202,228,0.08);
        }

        .product-item:last-child { border-bottom: none; }

        .product-thumb {
            width: 48px; height: 48px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid var(--glass-border);
        }

        .order-total {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--aqua);
        }

        .btn-glass {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: var(--aqua);
            padding: 10px 20px;
            border-radius: 50px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-glass:hover {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border-color: transparent;
            color: var(--deep);
        }

        .btn-danger-glass {
            background: rgba(248,113,113,0.1);
            border: 1px solid rgba(248,113,113,0.25);
            color: #fca5a5;
            padding: 10px 20px;
            border-radius: 50px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-danger-glass:hover {
            background: #ef4444;
            border-color: #ef4444;
            color: white;
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

        .modal-body {
            padding: 24px;
        }

        .modal-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem;
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
        <a href="order_history.php" class="nav-link active">
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
                <h4>Order History</h4>
                <p>View and manage your past water orders</p>
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

    <!-- Filter Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="btn-group">
            <a href="?order=desc" class="btn btn-glass <?php echo $order == 'DESC' ? 'active' : ''; ?>">
                <i class="fas fa-sort-amount-down me-2"></i> Newest First
            </a>
            <a href="?order=asc" class="btn btn-glass <?php echo $order == 'ASC' ? 'active' : ''; ?>">
                <i class="fas fa-sort-amount-up me-2"></i> Oldest First
            </a>
        </div>
        <a href="products.php" class="btn btn-primary px-4 rounded-pill">
            <i class="fas fa-plus me-2"></i> Place New Order
        </a>
    </div>

    <!-- Orders -->
    <?php if ($ordersResult->num_rows > 0): ?>
        <div class="row g-3">
            <?php while ($order = $ordersResult->fetch_assoc()) { 
                $statusClass = str_replace(' ', '-', $order['status']);
                
                $images = explode('|', $order['product_images']);
                $firstImage = $images[0] ?? '';
                $displayImage = (!empty($firstImage) && file_exists('../' . $firstImage)) 
                    ? '../' . $firstImage 
                    : 'https://via.placeholder.com/60x60?text=Order';
            ?>
                <div class="col-12">
                    <div class="order-card">
                        <div class="order-header">
                            <div>
                                <div class="order-id">#<?php echo $order['orderID']; ?></div>
                                <div class="order-date">
                                    <i class="fas fa-calendar me-1"></i>
                                    <?php echo date('F j, Y • g:i A', strtotime($order['order_date'])); ?>
                                </div>
                            </div>
                            
                            <div>
                                <span class="status-pill pill-<?php echo $statusClass; ?>">
                                    <?php echo $order['status']; ?>
                                </span>
                            </div>
                        </div>

                        <div class="product-list">
                            <div class="product-item">
                                <img src="<?php echo $displayImage; ?>" class="product-thumb" alt="">
                                <div>
                                    <div style="color: var(--foam); font-weight: 500;">
                                        <?php echo htmlspecialchars($order['products']); ?>
                                    </div>
                                    <small style="color: rgba(202,240,248,0.4);">
                                        <?php echo $order['payment_method']; ?> • ₱<?php echo number_format($order['total_amount'], 2); ?>
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-3 pt-3" style="border-top: 1px solid var(--glass-border);">
                            <div>
                                <span style="font-size: 0.82rem; color: rgba(202,240,248,0.4);">Total</span><br>
                                <span class="order-total">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button class="btn btn-glass btn-sm" data-bs-toggle="modal" data-bs-target="#orderDetailModal<?php echo $order['orderID']; ?>">
                                    <i class="fas fa-eye me-1"></i> View Details
                                </button>
                                
                                <?php if (in_array($order['status'], ['Pending', 'Processing'])): ?>
                                    <button class="btn btn-danger-glass btn-sm" data-bs-toggle="modal" data-bs-target="#cancelModal<?php echo $order['orderID']; ?>">
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
                                <h5 class="modal-title">
                                    <i class="fas fa-receipt me-2"></i> Order #<?php echo $order['orderID']; ?>
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold mb-3" style="color: var(--aqua);">Order Information</h6>
                                        <table class="table table-sm table-borderless" style="color: var(--foam);">
                                            <tr>
                                                <td class="text-muted">Order Date</td>
                                                <td><strong><?php echo date('F j, Y g:i A', strtotime($order['order_date'])); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Status</td>
                                                <td>
                                                    <span class="status-pill pill-<?php echo $statusClass; ?>">
                                                        <?php echo $order['status']; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Payment Method</td>
                                                <td>
                                                    <span class="badge bg-<?php echo $order['payment_method'] == 'GCash' ? 'info' : 'secondary'; ?>">
                                                        <?php echo $order['payment_method']; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fw-bold mb-3" style="color: var(--aqua);">Delivery Information</h6>
                                        <table class="table table-sm table-borderless" style="color: var(--foam);">
                                            <tr>
                                                <td class="text-muted">Address</td>
                                                <td><?php echo htmlspecialchars($order['delivery_address'] ?? 'N/A'); ?></td>
                                            </tr>
                                            <?php if (!empty($order['notes'])): ?>
                                            <tr>
                                                <td class="text-muted">Notes</td>
                                                <td><small><?php echo htmlspecialchars($order['notes']); ?></small></td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                </div>
                                
                                <h6 class="fw-bold mt-4 mb-3" style="color: var(--aqua);">Products Ordered</h6>
                                <div class="table-responsive">
                                    <table class="table table-hover" style="color: var(--foam);">
                                        <thead style="background: rgba(4,30,53,0.6);">
                                            <tr>
                                                <th>Product</th>
                                                <th class="text-center">Qty</th>
                                                <th class="text-end">Price</th>
                                                <th class="text-end">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
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
                                                $itemImage = (!empty($item['ImageURL']) && file_exists('../' . $item['ImageURL'])) 
                                                    ? '../' . $item['ImageURL'] 
                                                    : 'https://via.placeholder.com/40x40?text=Item';
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <img src="<?php echo $itemImage; ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px;">
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
                                        <tfoot style="background: rgba(4,30,53,0.6);">
                                            <tr>
                                                <td colspan="3" class="text-end"><strong>Total</strong></td>
                                                <td class="text-end"><strong class="fs-5" style="color: var(--aqua);">₱<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <?php if (in_array($order['status'], ['Pending', 'Processing'])): ?>
                                    <button class="btn btn-danger-glass" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#cancelModal<?php echo $order['orderID']; ?>">
                                        <i class="fas fa-times me-2"></i> Cancel Order
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-glass" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cancel Modal -->
                <?php if (in_array($order['status'], ['Pending', 'Processing'])): ?>
                <div class="modal fade" id="cancelModal<?php echo $order['orderID']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i> Cancel Order</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p>Are you sure you want to cancel <strong>Order #<?php echo $order['orderID']; ?></strong>?</p>
                                <div class="alert alert-warning">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Note:</strong> This action cannot be undone. Stock will be restored to inventory.
                                </div>
                            </div>
                            <div class="modal-footer">
                                <form method="POST" action="order_history.php">
                                    <input type="hidden" name="orderID" value="<?php echo $order['orderID']; ?>">
                                    <button type="button" class="btn btn-glass" data-bs-dismiss="modal">Keep Order</button>
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
        <div class="dash-card text-center py-5">
            <i class="fas fa-shopping-bag fa-4x mb-4" style="color: rgba(0,180,216,0.15);"></i>
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
</script>
</body>
</html>