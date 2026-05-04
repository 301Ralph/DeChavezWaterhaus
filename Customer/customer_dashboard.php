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

// Fetch dashboard data
$stmt = $conn->prepare("SELECT COUNT(*) as total_orders FROM orders WHERE $customerColumn = ?");
$stmt->bind_param("i", $userID); $stmt->execute();
$totalOrders = $stmt->get_result()->fetch_assoc()['total_orders'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as active_orders FROM orders WHERE $customerColumn = ? AND status IN ('Pending', 'Processing', 'Out for Delivery')");
$stmt->bind_param("i", $userID); $stmt->execute();
$activeOrders = $stmt->get_result()->fetch_assoc()['active_orders'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT SUM(total_amount) as total_spent FROM orders WHERE $customerColumn = ? AND status = 'Delivered'");
$stmt->bind_param("i", $userID); $stmt->execute();
$totalSpent = $stmt->get_result()->fetch_assoc()['total_spent'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as pending_orders FROM orders WHERE $customerColumn = ? AND status = 'Pending'");
$stmt->bind_param("i", $userID); $stmt->execute();
$pendingOrders = $stmt->get_result()->fetch_assoc()['pending_orders'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT orderID, order_date, total_amount, status FROM orders WHERE $customerColumn = ? ORDER BY order_date DESC LIMIT 5");
$stmt->bind_param("i", $userID); $stmt->execute();
$recentOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT orderID, order_date, total_amount, status FROM orders WHERE $customerColumn = ? AND status IN ('Pending', 'Processing', 'Out for Delivery') ORDER BY order_date DESC LIMIT 1");
$stmt->bind_param("i", $userID); $stmt->execute();
$currentOrder = $stmt->get_result()->fetch_assoc();
$stmt->close();

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;

$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$firstName = explode(' ', $userName)[0];
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard • De Chavez Waterhaus</title>
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

        .notif-dot {
            margin-left: auto;
            background: var(--gold);
            color: var(--deep);
            font-size: 0.62rem;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 50px;
            min-width: 18px;
            text-align: center;
        }

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

        /* dropdown */
        .dropdown-menu {
            background: var(--ocean) !important;
            border: 1px solid var(--glass-border) !important;
            border-radius: 14px !important;
            padding: 8px !important;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5) !important;
            min-width: 180px;
        }

        .dropdown-item {
            color: rgba(202,240,248,0.65) !important;
            border-radius: 8px !important;
            padding: 9px 14px !important;
            font-size: 0.84rem !important;
            transition: all 0.2s !important;
        }

        .dropdown-item:hover {
            background: var(--glass) !important;
            color: var(--aqua) !important;
        }

        .dropdown-item.text-danger { color: rgba(252,165,165,0.7) !important; }
        .dropdown-item.text-danger:hover { background: rgba(248,113,113,0.08) !important; color: #fca5a5 !important; }

        .dropdown-divider { border-color: var(--glass-border) !important; margin: 4px 0 !important; }

        /* ── WELCOME BANNER ── */
        .welcome-banner {
            background: linear-gradient(135deg, rgba(0,119,182,0.3) 0%, rgba(0,180,216,0.15) 100%);
            border: 1px solid rgba(0,180,216,0.25);
            border-radius: 20px;
            padding: 32px 36px;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 220px; height: 220px;
            border-radius: 50%;
            background: rgba(0,180,216,0.06);
        }

        .welcome-banner::after {
            content: '';
            position: absolute;
            bottom: -80px; right: 80px;
            width: 160px; height: 160px;
            border-radius: 50%;
            background: rgba(0,119,182,0.08);
        }

        .welcome-banner h2 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.9rem;
            font-weight: 400;
            color: var(--white);
            margin-bottom: 6px;
        }

        .welcome-banner p {
            color: rgba(202,240,248,0.55);
            font-size: 0.88rem;
        }

        .btn-order-now {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border: none;
            color: var(--deep);
            padding: 12px 32px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.85rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            box-shadow: 0 6px 20px rgba(0,180,216,0.3);
            position: relative; z-index: 1;
        }

        .btn-order-now:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(0,180,216,0.5);
            color: var(--deep);
        }

        /* ── STAT CARDS ── */
        .stat-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.7), rgba(3,15,30,0.85));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 24px;
            transition: all 0.35s cubic-bezier(0.23,1,0.32,1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(0,180,216,0.4), transparent);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-6px);
            border-color: rgba(0,180,216,0.3);
            box-shadow: 0 20px 45px rgba(0,0,0,0.35);
        }

        .stat-card:hover::after { opacity: 1; }

        .stat-icon {
            width: 52px; height: 52px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 16px;
        }

        .stat-icon.blue   { background: rgba(0,119,182,0.15); color: var(--aqua); }
        .stat-icon.amber  { background: rgba(244,200,66,0.12); color: var(--gold); }
        .stat-icon.green  { background: rgba(74,222,128,0.1); color: #4ade80; }
        .stat-icon.violet { background: rgba(167,139,250,0.12); color: #a78bfa; }

        .stat-num {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2.2rem;
            font-weight: 600;
            color: var(--white);
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.78rem;
            letter-spacing: 0.1em;
            color: rgba(202,240,248,0.4);
            text-transform: uppercase;
        }

        /* ── SECTION CARDS ── */
        .dash-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 28px;
        }

        .dash-card-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.2rem;
            font-weight: 500;
            color: var(--white);
            margin-bottom: 20px;
        }

        /* Quick action buttons */
        .qa-btn {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 18px;
            background: rgba(4,30,53,0.5);
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            text-decoration: none;
            color: var(--foam);
            transition: all 0.3s ease;
            margin-bottom: 10px;
        }

        .qa-btn:last-child { margin-bottom: 0; }

        .qa-btn:hover {
            background: var(--glass);
            border-color: rgba(0,180,216,0.3);
            color: var(--white);
            transform: translateX(4px);
        }

        .qa-icon {
            width: 46px; height: 46px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: var(--deep);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .qa-label { font-size: 0.88rem; font-weight: 500; }
        .qa-sub   { font-size: 0.75rem; color: rgba(202,240,248,0.4); margin-top: 2px; }

        .qa-arrow {
            margin-left: auto;
            color: rgba(0,180,216,0.35);
            font-size: 0.75rem;
            transition: all 0.3s;
        }

        .qa-btn:hover .qa-arrow { color: var(--aqua); transform: translateX(3px); }

        /* Current order card */
        .order-status-pill {
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.08em;
        }

        .pill-active   { background: rgba(244,200,66,0.12); color: var(--gold); border: 1px solid rgba(244,200,66,0.25); }
        .pill-none     { background: var(--glass); color: rgba(202,240,248,0.4); border: 1px solid var(--glass-border); }
        .pill-pending  { background: rgba(167,139,250,0.12); color: #a78bfa; border: 1px solid rgba(167,139,250,0.25); }
        .pill-delivered{ background: rgba(74,222,128,0.1);  color: #4ade80;  border: 1px solid rgba(74,222,128,0.25); }
        .pill-processing{ background: rgba(0,180,216,0.1);  color: var(--aqua); border: 1px solid rgba(0,180,216,0.25); }

        .order-id {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.5rem;
            font-weight: 500;
            color: var(--white);
        }

        .order-date {
            font-size: 0.78rem;
            color: rgba(202,240,248,0.4);
            margin-top: 3px;
        }

        .order-amount {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2rem;
            font-weight: 600;
            color: var(--aqua);
        }

        .btn-track {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: var(--aqua);
            padding: 10px 24px;
            border-radius: 50px;
            font-size: 0.83rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
        }

        .btn-track:hover {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border-color: transparent;
            color: var(--deep);
        }

        /* Recent orders table */
        .orders-table { width: 100%; border-collapse: collapse; }

        .orders-table th {
            font-size: 0.7rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: rgba(202,240,248,0.35);
            padding: 0 12px 14px;
            text-align: left;
            border-bottom: 1px solid var(--glass-border);
        }

        .orders-table td {
            padding: 14px 12px;
            font-size: 0.86rem;
            color: rgba(202,240,248,0.7);
            border-bottom: 1px solid rgba(72,202,228,0.06);
        }

        .orders-table tr:last-child td { border-bottom: none; }

        .orders-table tr:hover td {
            background: rgba(0,180,216,0.04);
            color: var(--foam);
        }

        .order-id-cell {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1rem;
            color: var(--white) !important;
        }

        /* ── MOBILE OVERLAY ── */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(2,13,24,0.7);
            z-index: 999;
            backdrop-filter: blur(3px);
        }

        .mobile-toggle {
            display: none;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: var(--aqua);
            width: 40px; height: 40px;
            border-radius: 10px;
            align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 0.9rem;
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
                box-shadow: 4px 0 40px rgba(0,0,0,0.5);
            }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px; }
            .mobile-toggle { display: flex; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
            .welcome-banner { padding: 22px 20px; }
            .welcome-banner h2 { font-size: 1.5rem; }
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
        <a href="customer_dashboard.php" class="nav-link active">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="products.php" class="nav-link">
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
            <?php if ($notifCount > 0): ?>
                <span class="notif-dot"><?php echo $notifCount > 9 ? '9+' : $notifCount; ?></span>
            <?php endif; ?>
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
            <button class="mobile-toggle" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="topbar-greeting">
                <h4><?php echo $greeting; ?>, <?php echo htmlspecialchars($firstName); ?></h4>
                <p>Here's your water delivery overview</p>
            </div>
        </div>

        <div class="topbar-actions">
            <!-- Notifications -->
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if ($notifCount > 0): ?>
                    <span class="topbar-notif-badge"><?php echo $notifCount > 9 ? '9+' : $notifCount; ?></span>
                <?php endif; ?>
            </a>

            <!-- Avatar dropdown -->
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

    <!-- Welcome Banner -->
    <div class="welcome-banner mb-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2>Stay Hydrated, Stay Healthy!</h2>
                <p class="mt-1">Thank you for being part of the De Chavez family.</p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="orders.php" class="btn-order-now">
                    <i class="fas fa-plus"></i> Order Now
                </a>
            </div>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-shopping-bag"></i></div>
                <div class="stat-num"><?php echo $totalOrders; ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon amber"><i class="fas fa-truck"></i></div>
                <div class="stat-num"><?php echo $activeOrders; ?></div>
                <div class="stat-label">Active Orders</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-peso-sign"></i></div>
                <div class="stat-num" style="font-size:1.6rem;">₱<?php echo number_format($totalSpent, 0); ?></div>
                <div class="stat-label">Total Spent</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon violet"><i class="fas fa-clock"></i></div>
                <div class="stat-num"><?php echo $pendingOrders; ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
    </div>

    <!-- Bottom Row -->
    <div class="row g-3">

        <!-- Quick Actions -->
        <div class="col-lg-4">
            <div class="dash-card h-100">
                <div class="dash-card-title">Quick Actions</div>

                <a href="orders.php" class="qa-btn">
                    <div class="qa-icon"><i class="fas fa-plus"></i></div>
                    <div>
                        <div class="qa-label">Place New Order</div>
                        <div class="qa-sub">Order fresh water now</div>
                    </div>
                    <i class="fas fa-chevron-right qa-arrow"></i>
                </a>

                <a href="order_history.php" class="qa-btn">
                    <div class="qa-icon"><i class="fas fa-history"></i></div>
                    <div>
                        <div class="qa-label">Order History</div>
                        <div class="qa-sub">See all past deliveries</div>
                    </div>
                    <i class="fas fa-chevron-right qa-arrow"></i>
                </a>

                <a href="order_tracking.php" class="qa-btn">
                    <div class="qa-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div>
                        <div class="qa-label">Track Orders</div>
                        <div class="qa-sub">Real-time delivery status</div>
                    </div>
                    <i class="fas fa-chevron-right qa-arrow"></i>
                </a>

                <a href="profile.php" class="qa-btn">
                    <div class="qa-icon"><i class="fas fa-user-edit"></i></div>
                    <div>
                        <div class="qa-label">Update Profile</div>
                        <div class="qa-sub">Manage your account</div>
                    </div>
                    <i class="fas fa-chevron-right qa-arrow"></i>
                </a>
            </div>
        </div>

        <!-- Current Order + Recent Orders -->
        <div class="col-lg-8">
            <div class="row g-3 h-100">

                <!-- Current Order -->
                <div class="col-12">
                    <div class="dash-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="dash-card-title mb-0">Current Order</div>
                            <?php if ($currentOrder): ?>
                                <span class="order-status-pill pill-active">In Progress</span>
                            <?php else: ?>
                                <span class="order-status-pill pill-none">No Active Order</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($currentOrder): ?>
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                                <div>
                                    <div class="order-id">Order #<?php echo $currentOrder['orderID']; ?></div>
                                    <div class="order-date">Placed <?php echo date('M d, Y · h:i A', strtotime($currentOrder['order_date'])); ?></div>
                                    <div class="order-amount mt-2">₱<?php echo number_format($currentOrder['total_amount'], 2); ?></div>
                                </div>
                                <a href="orders.php?order_id=<?php echo $currentOrder['orderID']; ?>" class="btn-track">
                                    <i class="fas fa-location-dot me-2"></i> Track Order
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-truck fa-2x mb-3" style="color:rgba(0,180,216,0.2);"></i>
                                <p style="color:rgba(202,240,248,0.4); font-size:0.88rem; margin-bottom:16px;">No active orders at the moment.</p>
                                <a href="orders.php" class="btn-order-now" style="padding:10px 24px; font-size:0.8rem;">
                                    <i class="fas fa-plus"></i> Place Your First Order
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Orders -->
                <div class="col-12">
                    <div class="dash-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="dash-card-title mb-0">Recent Orders</div>
                            <a href="order_history.php" style="font-size:0.78rem; color:var(--aqua); text-decoration:none; letter-spacing:0.05em;">
                                View All <i class="fas fa-arrow-right ms-1" style="font-size:0.7rem;"></i>
                            </a>
                        </div>

                        <?php if (count($recentOrders) > 0): ?>
                        <div style="overflow-x:auto;">
                            <table class="orders-table">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): 
                                        $s = $order['status'];
                                        $pillClass = match($s) {
                                            'Delivered'        => 'pill-delivered',
                                            'Pending'          => 'pill-pending',
                                            'Processing'       => 'pill-processing',
                                            'Out for Delivery' => 'pill-active',
                                            default            => 'pill-none'
                                        };
                                    ?>
                                    <tr>
                                        <td class="order-id-cell">#<?php echo $order['orderID']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                        <td style="color:var(--aqua); font-family:'Cormorant Garamond',serif; font-size:1rem;">
                                            ₱<?php echo number_format($order['total_amount'], 2); ?>
                                        </td>
                                        <td>
                                            <span class="order-status-pill <?php echo $pillClass; ?>">
                                                <?php echo htmlspecialchars($s); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="text-center py-4" style="color:rgba(202,240,248,0.35); font-size:0.86rem;">
                                No orders yet. Start your first order!
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebar  = document.getElementById('sidebar');
    const overlay  = document.getElementById('sidebarOverlay');
    const toggle   = document.getElementById('mobileToggle');

    function openSidebar()  { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }

    if (toggle)  toggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    // Close on nav link tap (mobile)
    sidebar.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 992) closeSidebar();
        });
    });
</script>
</body>
</html>