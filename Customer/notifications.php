<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'customer') {
    echo '<script>alert("Access denied. Customers only."); window.location = "../login.php";</script>';
    exit();
}

$userID   = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Mark all as read when visiting the page
$conn->query("UPDATE notifications SET is_read = 1 WHERE userID = $userID");

// Fetch notifications
$notifs = $conn->query("SELECT * FROM notifications WHERE userID = $userID ORDER BY created_at DESC LIMIT 50");

$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$notifCount = 0; // already marked read above
$firstName  = explode(' ', $userName)[0];
$totalCount = $notifs->num_rows;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications • De Chavez Waterhaus</title>
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

        .nav-link:hover { background: var(--glass); color: var(--foam) !important; }
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
        .avatar-name { font-size: 0.82rem; font-weight: 500; color: var(--white); }
        .avatar-role { font-size: 0.7rem; color: rgba(202,240,248,0.4); }

        /* dropdown */
        .dropdown-menu {
            background: var(--ocean) !important;
            border: 1px solid var(--glass-border) !important;
            border-radius: 14px !important;
            padding: 8px !important;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5) !important;
        }

        .dropdown-item {
            color: rgba(202,240,248,0.65) !important;
            border-radius: 8px !important;
            padding: 9px 14px !important;
            font-size: 0.84rem !important;
            transition: all 0.2s !important;
        }

        .dropdown-item:hover { background: var(--glass) !important; color: var(--aqua) !important; }
        .dropdown-item.text-danger { color: rgba(252,165,165,0.7) !important; }
        .dropdown-item.text-danger:hover { background: rgba(248,113,113,0.08) !important; color: #fca5a5 !important; }
        .dropdown-divider { border-color: var(--glass-border) !important; margin: 4px 0 !important; }

        /* ── PAGE HEADER ── */
        .page-header {
            background: linear-gradient(135deg, rgba(0,119,182,0.2), rgba(0,180,216,0.08));
            border: 1px solid rgba(0,180,216,0.2);
            border-radius: 18px;
            padding: 28px 32px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-header-icon {
            width: 56px; height: 56px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: var(--deep);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
            box-shadow: 0 6px 20px rgba(0,180,216,0.3);
        }

        .page-header-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.7rem;
            font-weight: 400;
            color: var(--white);
            line-height: 1.1;
        }

        .page-header-sub {
            font-size: 0.82rem;
            color: rgba(202,240,248,0.45);
            margin-top: 3px;
        }

        .count-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 50px;
            padding: 8px 18px;
            font-size: 0.82rem;
            color: rgba(202,240,248,0.6);
        }

        .count-pill strong { color: var(--aqua); font-size: 1.1rem; font-family: 'Cormorant Garamond', serif; }

        /* ── FILTER TABS ── */
        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 7px 18px;
            border-radius: 50px;
            border: 1px solid var(--glass-border);
            background: transparent;
            color: rgba(202,240,248,0.45);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.25s;
        }

        .filter-tab:hover { border-color: rgba(0,180,216,0.3); color: var(--foam); }

        .filter-tab.active {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border-color: transparent;
            color: var(--deep);
            font-weight: 600;
            box-shadow: 0 4px 14px rgba(0,180,216,0.25);
        }

        /* ── NOTIFICATION ITEMS ── */
        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            background: linear-gradient(145deg, rgba(10,45,74,0.55), rgba(3,15,30,0.75));
            border: 1px solid var(--glass-border);
            border-left: 3px solid rgba(0,180,216,0.3);
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 10px;
            position: relative;
            transition: all 0.3s ease;
            animation: slideIn 0.4s ease both;
        }

        .notif-item:hover {
            transform: translateX(4px);
            border-color: rgba(0,180,216,0.3);
            border-left-color: var(--aqua);
            background: linear-gradient(145deg, rgba(10,45,74,0.7), rgba(3,15,30,0.85));
        }

        .notif-item.is-unread {
            border-left-color: var(--gold);
            background: linear-gradient(145deg, rgba(244,200,66,0.07), rgba(3,15,30,0.82));
        }

        .notif-item.is-unread:hover { border-left-color: var(--gold); }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* stagger delay */
        .notif-item:nth-child(1)  { animation-delay: 0.05s; }
        .notif-item:nth-child(2)  { animation-delay: 0.10s; }
        .notif-item:nth-child(3)  { animation-delay: 0.15s; }
        .notif-item:nth-child(4)  { animation-delay: 0.20s; }
        .notif-item:nth-child(5)  { animation-delay: 0.25s; }
        .notif-item:nth-child(n+6){ animation-delay: 0.30s; }

        .notif-icon-wrap {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .notif-icon-wrap.order   { background: rgba(0,180,216,0.1);  color: var(--aqua); }
        .notif-icon-wrap.deliver { background: rgba(74,222,128,0.1); color: #4ade80; }
        .notif-icon-wrap.alert   { background: rgba(244,200,66,0.1); color: var(--gold); }
        .notif-icon-wrap.info    { background: rgba(167,139,250,0.1);color: #a78bfa; }
        .notif-icon-wrap.default { background: var(--glass); color: rgba(202,240,248,0.5); }

        .notif-body { flex: 1; min-width: 0; }

        .notif-message {
            font-size: 0.91rem;
            color: var(--foam);
            line-height: 1.55;
            margin-bottom: 6px;
            word-break: break-word;
        }

        .notif-item.is-unread .notif-message { color: var(--white); font-weight: 500; }

        .notif-time {
            font-size: 0.73rem;
            color: rgba(202,240,248,0.35);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .unread-dot {
            position: absolute;
            top: 18px; right: 18px;
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--gold);
            box-shadow: 0 0 8px rgba(244,200,66,0.5);
            animation: pulseDot 2s ease-in-out infinite;
        }

        @keyframes pulseDot {
            0%,100% { opacity:1; transform:scale(1); }
            50%      { opacity:0.5; transform:scale(0.7); }
        }

        /* ── DATE GROUP LABEL ── */
        .date-group-label {
            font-size: 0.68rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(202,240,248,0.25);
            padding: 16px 4px 10px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .date-group-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--glass-border);
        }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center;
            padding: 72px 20px;
            background: linear-gradient(145deg, rgba(10,45,74,0.4), rgba(3,15,30,0.6));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
        }

        .empty-ring {
            width: 90px; height: 90px;
            border-radius: 50%;
            background: rgba(0,180,216,0.07);
            border: 1px solid rgba(0,180,216,0.12);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 24px;
            font-size: 2rem;
            color: rgba(0,180,216,0.25);
        }

        .empty-state h5 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem;
            font-weight: 400;
            color: var(--white);
            margin-bottom: 8px;
        }

        .empty-state p {
            font-size: 0.86rem;
            color: rgba(202,240,248,0.35);
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
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: var(--aqua);
            width: 40px; height: 40px;
            border-radius: 10px;
            display: none;
            align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 0.9rem;
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px; }
            .mobile-toggle { display: flex; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
            .page-header { padding: 20px; }
            .page-header-title { font-size: 1.4rem; }
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
        <a href="customer_dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a>
        <a href="products.php"           class="nav-link"><i class="fas fa-droplet"></i> Products</a>
        <a href="order_history.php"      class="nav-link"><i class="fas fa-history"></i> Order History</a>
        <a href="order_tracking.php"     class="nav-link"><i class="fas fa-map-marker-alt"></i> Track Orders</a>
        <a href="recurring_orders.php"   class="nav-link"><i class="fas fa-redo"></i> Recurring Orders</a>

        <div class="nav-section-label">Account</div>
        <a href="support_tickets.php" class="nav-link"><i class="fas fa-headset"></i> Support</a>
        <a href="notifications.php"   class="nav-link active"><i class="fas fa-bell"></i> Notifications</a>
        <a href="profile.php"         class="nav-link"><i class="fas fa-user"></i> Profile</a>

        <div class="nav-section-label" style="margin-top:16px;"></div>
        <a href="../logout.php" class="nav-link danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN CONTENT ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
            <div class="topbar-greeting">
                <h4>Notifications</h4>
                <p>Stay updated with your orders and account activity</p>
            </div>
        </div>

        <div class="topbar-actions">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
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

    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex align-items-center gap-3">
            <div class="page-header-icon"><i class="fas fa-bell"></i></div>
            <div>
                <div class="page-header-title">Your Notifications</div>
                <div class="page-header-sub">All notifications are marked as read when you open this page</div>
            </div>
        </div>
        <div class="count-pill">
            <i class="fas fa-layer-group" style="color:rgba(0,180,216,0.5);"></i>
            <strong><?php echo $totalCount; ?></strong> total
        </div>
    </div>

    <?php if ($totalCount > 0): ?>

        <!-- Filter tabs -->
        <div class="filter-tabs">
            <button class="filter-tab active" onclick="filterNotifs('all', this)">All</button>
            <button class="filter-tab" onclick="filterNotifs('order', this)">Orders</button>
            <button class="filter-tab" onclick="filterNotifs('deliver', this)">Deliveries</button>
            <button class="filter-tab" onclick="filterNotifs('alert', this)">Alerts</button>
        </div>

        <!-- Notifications list -->
        <div id="notifList">
            <?php
            $notifs->data_seek(0);
            $prevDate = null;

            while ($notif = $notifs->fetch_assoc()):
                $isUnread  = $notif['is_read'] == 0;
                $msg       = htmlspecialchars($notif['message']);
                $msgLower  = strtolower($notif['message']);
                $createdAt = strtotime($notif['created_at']);
                $dateLabel = date('Y-m-d', $createdAt);
                $today     = date('Y-m-d');
                $yesterday = date('Y-m-d', strtotime('-1 day'));

                // Determine type
                if (str_contains($msgLower, 'deliver') || str_contains($msgLower, 'out for')) {
                    $type = 'deliver'; $icon = 'fa-truck'; $iconClass = 'deliver';
                } elseif (str_contains($msgLower, 'order') || str_contains($msgLower, 'placed')) {
                    $type = 'order'; $icon = 'fa-shopping-bag'; $iconClass = 'order';
                } elseif (str_contains($msgLower, 'payment') || str_contains($msgLower, 'cancel') || str_contains($msgLower, 'fail')) {
                    $type = 'alert'; $icon = 'fa-exclamation-triangle'; $iconClass = 'alert';
                } else {
                    $type = 'info'; $icon = 'fa-info-circle'; $iconClass = 'default';
                }

                // Date group label
                if ($dateLabel !== $prevDate):
                    $labelText = match(true) {
                        $dateLabel === $today     => 'Today',
                        $dateLabel === $yesterday => 'Yesterday',
                        default                   => date('F j, Y', $createdAt)
                    };
            ?>
                <div class="date-group-label"><?php echo $labelText; ?></div>
            <?php
                    $prevDate = $dateLabel;
                endif;
            ?>

            <div class="notif-item <?php echo $isUnread ? 'is-unread' : ''; ?>" data-type="<?php echo $type; ?>">
                <div class="notif-icon-wrap <?php echo $iconClass; ?>">
                    <i class="fas <?php echo $icon; ?>"></i>
                </div>
                <div class="notif-body">
                    <div class="notif-message"><?php echo $msg; ?></div>
                    <div class="notif-time">
                        <i class="fas fa-clock"></i>
                        <?php echo date('g:i A', $createdAt); ?>
                        <?php if ($dateLabel !== $today && $dateLabel !== $yesterday): ?>
                            · <?php echo date('M j', $createdAt); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($isUnread): ?><div class="unread-dot"></div><?php endif; ?>
            </div>

            <?php endwhile; ?>
        </div>

    <?php else: ?>

        <!-- Empty state -->
        <div class="empty-state">
            <div class="empty-ring"><i class="fas fa-bell-slash"></i></div>
            <h5>You're all caught up!</h5>
            <p>No notifications at the moment. We'll let you know when something happens.</p>
        </div>

    <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── SIDEBAR MOBILE ──
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle  = document.getElementById('mobileToggle');

    function openSidebar()  { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }

    if (toggle)  toggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => { if (window.innerWidth < 992) closeSidebar(); });
    });

    // ── FILTER TABS ──
    function filterNotifs(type, btn) {
        // Update active tab
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');

        // Show/hide items
        document.querySelectorAll('.notif-item').forEach(item => {
            const match = type === 'all' || item.dataset.type === type;
            item.style.display = match ? 'flex' : 'none';
        });

        // Show/hide date group labels (only if at least one notif visible follows it)
        document.querySelectorAll('.date-group-label').forEach(label => {
            let next = label.nextElementSibling;
            let hasVisible = false;
            while (next && !next.classList.contains('date-group-label')) {
                if (next.classList.contains('notif-item') && next.style.display !== 'none') {
                    hasVisible = true;
                    break;
                }
                next = next.nextElementSibling;
            }
            label.style.display = hasVisible ? '' : 'none';
        });
    }
</script>
</body>
</html>