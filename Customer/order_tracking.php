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
           o.delivery_address, o.notes,
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
$firstName  = explode(' ', $userName)[0];

// Separate active vs past orders
$allOrders    = $ordersResult->fetch_all(MYSQLI_ASSOC);
$activeOrders = array_filter($allOrders, fn($o) => in_array($o['status'], ['Pending', 'Processing', 'Out for Delivery']));
$pastOrders   = array_filter($allOrders, fn($o) => !in_array($o['status'], ['Pending', 'Processing', 'Out for Delivery']));
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
            --green: #4ade80;
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
            position: fixed; top: 0; left: 0;
            height: 100vh; width: var(--sidebar-w);
            background: var(--abyss);
            border-right: 1px solid var(--glass-border);
            z-index: 1000;
            display: flex; flex-direction: column;
            transition: transform 0.3s ease;
        }

        .sidebar-logo {
            padding: 24px 22px;
            display: flex; align-items: center; gap: 12px;
            border-bottom: 1px solid var(--glass-border);
            flex-shrink: 0;
        }

        .sidebar-logo img {
            width: 40px; height: 40px; border-radius: 50%; object-fit: cover;
            border: 1px solid rgba(0,180,216,0.35);
            box-shadow: 0 0 14px rgba(0,180,216,0.2);
        }

        .sidebar-logo span {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.05rem; font-weight: 500; color: var(--white); line-height: 1.2;
        }

        .sidebar-nav {
            flex: 1; overflow-y: auto; padding: 16px 12px 20px;
            scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.15) transparent;
        }

        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }

        .nav-section-label {
            font-size: 0.62rem; letter-spacing: 0.2em; text-transform: uppercase;
            color: rgba(202,240,248,0.25); padding: 16px 12px 6px;
        }

        .nav-link {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 14px; border-radius: 10px;
            color: rgba(202,240,248,0.5) !important;
            text-decoration: none; font-size: 0.87rem; font-weight: 500;
            transition: all 0.25s ease; margin-bottom: 2px; position: relative;
        }

        .nav-link i { width: 18px; text-align: center; font-size: 0.9rem; color: rgba(0,180,216,0.4); transition: color 0.25s; }
        .nav-link:hover { background: var(--glass); color: var(--foam) !important; }
        .nav-link:hover i { color: var(--aqua); }

        .nav-link.active {
            background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12));
            border: 1px solid rgba(0,180,216,0.2); color: var(--aqua) !important;
        }

        .nav-link.active i { color: var(--aqua); }

        .nav-link.active::before {
            content: ''; position: absolute; left: 0; top: 20%; bottom: 20%;
            width: 3px; background: var(--aqua); border-radius: 0 3px 3px 0;
        }

        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }

        .notif-dot {
            margin-left: auto; background: var(--gold); color: var(--deep);
            font-size: 0.62rem; font-weight: 700; padding: 1px 6px;
            border-radius: 50px; min-width: 18px; text-align: center;
        }

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; padding: 28px 32px; }

        /* ── TOP BAR ── */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; }

        .topbar-greeting h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.7rem; font-weight: 400; color: var(--white); line-height: 1.1;
        }

        .topbar-greeting p { font-size: 0.82rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .topbar-actions { display: flex; align-items: center; gap: 12px; }

        .topbar-btn {
            width: 42px; height: 42px; border-radius: 50%;
            background: var(--glass); border: 1px solid var(--glass-border);
            color: rgba(202,240,248,0.6);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem; text-decoration: none; transition: all 0.3s; position: relative;
        }

        .topbar-btn:hover { background: rgba(0,180,216,0.15); border-color: var(--aqua); color: var(--aqua); }

        .topbar-notif-badge {
            position: absolute; top: -3px; right: -3px;
            background: var(--gold); color: var(--deep);
            font-size: 0.58rem; font-weight: 700; min-width: 16px; height: 16px;
            border-radius: 50px; display: flex; align-items: center; justify-content: center; padding: 0 4px;
        }

        .avatar-btn {
            display: flex; align-items: center; gap: 10px;
            background: var(--glass); border: 1px solid var(--glass-border);
            border-radius: 50px; padding: 6px 14px 6px 6px; cursor: pointer; transition: all 0.3s;
        }

        .avatar-btn:hover { border-color: rgba(0,180,216,0.35); background: rgba(0,180,216,0.1); }

        .avatar-circle {
            width: 34px; height: 34px; border-radius: 50%;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: var(--deep); font-weight: 700; font-size: 0.85rem;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; flex-shrink: 0;
        }

        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-name { font-size: 0.82rem; font-weight: 500; color: var(--white); }
        .avatar-role { font-size: 0.7rem; color: rgba(202,240,248,0.4); }

        .dropdown-menu {
            background: var(--ocean) !important; border: 1px solid var(--glass-border) !important;
            border-radius: 14px !important; padding: 8px !important;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5) !important;
        }

        .dropdown-item {
            color: rgba(202,240,248,0.65) !important; border-radius: 8px !important;
            padding: 9px 14px !important; font-size: 0.84rem !important; transition: all 0.2s !important;
        }

        .dropdown-item:hover { background: var(--glass) !important; color: var(--aqua) !important; }
        .dropdown-item.text-danger { color: rgba(252,165,165,0.7) !important; }
        .dropdown-item.text-danger:hover { background: rgba(248,113,113,0.08) !important; color: #fca5a5 !important; }
        .dropdown-divider { border-color: var(--glass-border) !important; margin: 4px 0 !important; }

        /* ── btn-order-now ── */
        .btn-order-now {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border: none; color: var(--deep);
            padding: 10px 22px; border-radius: 50px;
            font-weight: 700; font-size: 0.82rem; letter-spacing: 0.08em; text-transform: uppercase;
            text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
            transition: all 0.3s; box-shadow: 0 5px 18px rgba(0,180,216,0.3);
        }

        .btn-order-now:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,180,216,0.5); color: var(--deep); }

        /* ── PAGE HEADER ── */
        .page-header {
            background: linear-gradient(135deg, rgba(0,119,182,0.2), rgba(0,180,216,0.08));
            border: 1px solid rgba(0,180,216,0.2);
            border-radius: 18px; padding: 24px 28px; margin-bottom: 28px;
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 16px;
        }

        .page-header-icon {
            width: 52px; height: 52px; border-radius: 14px;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: var(--deep); display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0; box-shadow: 0 6px 20px rgba(0,180,216,0.3);
        }

        .page-header-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.6rem; font-weight: 400; color: var(--white);
        }

        .page-header-sub { font-size: 0.82rem; color: rgba(202,240,248,0.4); margin-top: 3px; }

        .refresh-info {
            display: flex; align-items: center; gap: 8px;
            font-size: 0.75rem; color: rgba(202,240,248,0.3);
        }

        .refresh-dot {
            width: 7px; height: 7px; border-radius: 50%; background: var(--green);
            animation: blink 2s ease-in-out infinite;
        }

        @keyframes blink { 0%,100% { opacity:1; } 50% { opacity:0.3; } }

        /* ── SECTION LABEL ── */
        .section-label {
            font-size: 0.68rem; letter-spacing: 0.2em; text-transform: uppercase;
            color: rgba(202,240,248,0.3); margin-bottom: 14px;
            display: flex; align-items: center; gap: 12px;
        }

        .section-label::after { content: ''; flex: 1; height: 1px; background: var(--glass-border); }

        /* ── TRACKING CARD ── */
        .track-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.82));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            overflow: hidden;
            transition: all 0.35s cubic-bezier(0.23,1,0.32,1);
            animation: cardIn 0.4s ease both;
        }

        .track-card:hover {
            transform: translateY(-5px);
            border-color: rgba(0,180,216,0.28);
            box-shadow: 0 22px 50px rgba(0,0,0,0.35);
        }

        .track-card:nth-child(1) { animation-delay:0.05s; }
        .track-card:nth-child(2) { animation-delay:0.10s; }
        .track-card:nth-child(3) { animation-delay:0.15s; }
        .track-card:nth-child(n+4) { animation-delay:0.2s; }

        @keyframes cardIn {
            from { opacity:0; transform:translateY(18px); }
            to   { opacity:1; transform:translateY(0); }
        }

        /* status accent top stripe */
        .track-card.active-card { border-top: 3px solid var(--aqua); }
        .track-card.delivered-card { border-top: 3px solid var(--green); }
        .track-card.cancelled-card { border-top: 3px solid #f87171; }

        .track-card-head {
            padding: 20px 22px 16px;
            display: flex; justify-content: space-between; align-items: flex-start;
        }

        .track-order-num {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.5rem; font-weight: 600; color: var(--white);
        }

        .track-order-date {
            font-size: 0.77rem; color: rgba(202,240,248,0.38);
            margin-top: 3px; display: flex; align-items: center; gap: 5px;
        }

        /* status pills */
        .status-pill {
            padding: 5px 14px; border-radius: 50px;
            font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
        }

        .pill-Pending          { background: rgba(244,200,66,0.12); color: #f4c842; border: 1px solid rgba(244,200,66,0.25); }
        .pill-Processing       { background: rgba(0,180,216,0.1);   color: #00b4d8; border: 1px solid rgba(0,180,216,0.25); }
        .pill-Out-for-Delivery { background: rgba(96,165,250,0.1);  color: #60a5fa; border: 1px solid rgba(96,165,250,0.25); }
        .pill-Delivered        { background: rgba(74,222,128,0.1);  color: #4ade80; border: 1px solid rgba(74,222,128,0.25); }
        .pill-Cancelled        { background: rgba(248,113,113,0.1); color: #fca5a5; border: 1px solid rgba(248,113,113,0.25); }

        /* order info strip */
        .track-info-strip {
            display: flex; gap: 0;
            border-top: 1px solid rgba(72,202,228,0.08);
            border-bottom: 1px solid rgba(72,202,228,0.08);
        }

        .track-info-cell {
            flex: 1; padding: 12px 16px; border-right: 1px solid rgba(72,202,228,0.08);
        }

        .track-info-cell:last-child { border-right: none; }

        .track-info-label { font-size: 0.68rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.3); margin-bottom: 4px; }
        .track-info-value { font-size: 0.88rem; color: var(--foam); font-weight: 500; }
        .track-info-value.amount { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; color: var(--aqua); }

        /* ── PROGRESS BAR ── */
        .track-progress-wrap { padding: 22px 22px 8px; }

        .progress-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin-bottom: 8px;
        }

        .progress-steps::before {
            content: '';
            position: absolute;
            top: 18px; left: 18px; right: 18px;
            height: 3px;
            background: rgba(72,202,228,0.12);
            z-index: 0;
        }

        .progress-fill {
            position: absolute;
            top: 18px; left: 18px;
            height: 3px;
            background: linear-gradient(90deg, var(--teal), var(--aqua));
            z-index: 1;
            transition: width 0.8s cubic-bezier(0.23,1,0.32,1);
        }

        .progress-step {
            display: flex; flex-direction: column; align-items: center;
            gap: 8px; position: relative; z-index: 2; flex: 1;
        }

        .step-dot {
            width: 36px; height: 36px; border-radius: 50%;
            background: rgba(4,30,53,0.9);
            border: 2px solid rgba(72,202,228,0.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.82rem; color: rgba(202,240,248,0.25);
            transition: all 0.4s ease;
        }

        .step-dot.done {
            background: var(--green);
            border-color: var(--green);
            color: var(--deep);
            box-shadow: 0 0 12px rgba(74,222,128,0.3);
        }

        .step-dot.current {
            background: var(--aqua);
            border-color: var(--aqua);
            color: var(--deep);
            box-shadow: 0 0 0 5px rgba(0,180,216,0.15), 0 0 18px rgba(0,180,216,0.3);
            animation: stepPulse 2s infinite;
        }

        .step-dot.cancelled-dot {
            background: #ef4444;
            border-color: #ef4444;
            color: white;
        }

        @keyframes stepPulse {
            0%   { box-shadow: 0 0 0 0 rgba(0,180,216,0.4), 0 0 18px rgba(0,180,216,0.3); }
            70%  { box-shadow: 0 0 0 10px rgba(0,180,216,0), 0 0 18px rgba(0,180,216,0.3); }
            100% { box-shadow: 0 0 0 0 rgba(0,180,216,0), 0 0 18px rgba(0,180,216,0.3); }
        }

        .step-label {
            font-size: 0.66rem; color: rgba(202,240,248,0.3);
            text-align: center; line-height: 1.3; white-space: nowrap;
        }

        .step-label.active-label { color: var(--aqua); font-weight: 600; }
        .step-label.done-label   { color: rgba(74,222,128,0.7); }

        /* ── DELIVERY DATE CHIP ── */
        .delivery-chip {
            display: inline-flex; align-items: center; gap: 7px;
            background: rgba(0,180,216,0.08); border: 1px solid rgba(0,180,216,0.2);
            border-radius: 50px; padding: 6px 14px;
            font-size: 0.78rem; color: rgba(202,240,248,0.6);
            margin: 4px 22px 0;
        }

        .delivery-chip i { color: var(--aqua); }

        /* ── CARD FOOTER ── */
        .track-card-footer {
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px 22px 18px; flex-wrap: wrap; gap: 10px;
        }

        .btn-glass {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--glass); border: 1px solid var(--glass-border);
            color: var(--aqua); padding: 9px 18px; border-radius: 50px;
            font-size: 0.8rem; font-weight: 600; text-decoration: none;
            cursor: pointer; transition: all 0.3s;
        }

        .btn-glass:hover {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border-color: transparent; color: var(--deep);
        }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center; padding: 72px 20px;
            background: linear-gradient(145deg, rgba(10,45,74,0.4), rgba(3,15,30,0.6));
            border: 1px solid var(--glass-border); border-radius: 18px;
        }

        .empty-ring {
            width: 90px; height: 90px; border-radius: 50%;
            background: rgba(0,180,216,0.07); border: 1px solid rgba(0,180,216,0.12);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 24px; font-size: 2rem; color: rgba(0,180,216,0.25);
        }

        .empty-state h5 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.5rem; font-weight: 400; color: var(--white); margin-bottom: 8px;
        }

        .empty-state p { font-size: 0.86rem; color: rgba(202,240,248,0.35); margin-bottom: 24px; }

        /* ── MODALS ── */
        .modal-content {
            background: var(--ocean) !important;
            border: 1px solid var(--glass-border) !important;
            border-radius: 20px !important;
        }

        .modal-header { border-bottom: 1px solid var(--glass-border) !important; padding: 22px 26px !important; }
        .modal-footer { border-top: 1px solid var(--glass-border) !important; padding: 18px 26px !important; }
        .modal-body { padding: 26px !important; }

        .modal-title {
            font-family: 'Cormorant Garamond', serif !important;
            font-size: 1.4rem !important; font-weight: 500 !important; color: var(--white) !important;
        }

        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

        .modal-detail-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
        }

        .modal-detail-cell {
            background: rgba(4,30,53,0.5);
            border: 1px solid rgba(72,202,228,0.08);
            border-radius: 12px; padding: 14px 16px;
        }

        .modal-detail-cell.full { grid-column: 1 / -1; }

        .modal-detail-label { font-size: 0.68rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-bottom: 5px; }
        .modal-detail-value { font-size: 0.9rem; color: var(--foam); font-weight: 500; }
        .modal-detail-value.highlight { font-family: 'Cormorant Garamond', serif; font-size: 1.3rem; color: var(--aqua); }

        /* ── MOBILE ── */
        .sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px);
        }

        .mobile-toggle {
            background: var(--glass); border: 1px solid var(--glass-border);
            color: var(--aqua); width: 40px; height: 40px; border-radius: 10px;
            display: none; align-items: center; justify-content: center;
            cursor: pointer; font-size: 0.9rem;
        }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px; }
            .mobile-toggle { display: flex; }
            .track-info-strip { flex-wrap: wrap; }
            .track-info-cell { min-width: 50%; }
            .modal-detail-grid { grid-template-columns: 1fr; }
            .modal-detail-cell.full { grid-column: auto; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
            .step-label { display: none; }
            .page-header { padding: 18px 20px; }
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
        <a href="order_tracking.php"     class="nav-link active"><i class="fas fa-map-marker-alt"></i> Track Orders</a>
        <a href="recurring_orders.php"   class="nav-link"><i class="fas fa-redo"></i> Recurring Orders</a>

        <div class="nav-section-label">Account</div>
        <a href="support_tickets.php" class="nav-link"><i class="fas fa-headset"></i> Support</a>
        <a href="notifications.php"   class="nav-link">
            <i class="fas fa-bell"></i> Notifications
            <?php if ($notifCount > 0): ?><span class="notif-dot"><?php echo $notifCount > 9 ? '9+' : $notifCount; ?></span><?php endif; ?>
        </a>
        <a href="profile.php"         class="nav-link"><i class="fas fa-user"></i> Profile</a>
        <div class="nav-section-label" style="margin-top:16px;"></div>
        <a href="../logout.php"       class="nav-link danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
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

            <a href="products.php" class="btn-order-now d-none d-md-inline-flex">
                <i class="fas fa-plus"></i> New Order
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
            <div class="page-header-icon"><i class="fas fa-location-dot"></i></div>
            <div>
                <div class="page-header-title">Live Delivery Tracker</div>
                <div class="page-header-sub">
                    <?php echo count($activeOrders); ?> active · <?php echo count($pastOrders); ?> completed
                </div>
            </div>
        </div>
        <div class="refresh-info">
            <div class="refresh-dot"></div>
            Auto-refreshes every 30s
        </div>
    </div>

    <?php if (count($allOrders) > 0): ?>

        <!-- Active Orders -->
        <?php if (count($activeOrders) > 0): ?>
            <div class="section-label mb-3">Active Orders</div>
            <div class="row g-3 mb-4">
                <?php foreach ($activeOrders as $order):
                    $status     = $order['status'];
                    $statusClass = str_replace(' ', '-', $status);

                    // Progress: 0=Pending, 1=Processing, 2=OutForDelivery, 3=Delivered
                    $stepMap   = ['Pending'=>0, 'Processing'=>1, 'Out for Delivery'=>2, 'Delivered'=>3];
                    $stepIndex = $stepMap[$status] ?? 0;
                    $fillPct   = match($stepIndex) { 0=>0, 1=>33, 2=>66, 3=>100, default=>0 };

                    $steps = [
                        ['icon'=>'fa-receipt',  'label'=>'Order Placed'],
                        ['icon'=>'fa-gear',     'label'=>'Processing'],
                        ['icon'=>'fa-truck',    'label'=>'Out for Delivery'],
                        ['icon'=>'fa-house',    'label'=>'Delivered'],
                    ];
                ?>
                <div class="col-lg-6">
                    <div class="track-card active-card">

                        <!-- Head -->
                        <div class="track-card-head">
                            <div>
                                <div class="track-order-num">Order #<?php echo $order['orderID']; ?></div>
                                <div class="track-order-date">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?php echo date('F j, Y · g:i A', strtotime($order['order_date'])); ?>
                                </div>
                            </div>
                            <span class="status-pill pill-<?php echo $statusClass; ?>"><?php echo $status; ?></span>
                        </div>

                        <!-- Info strip -->
                        <div class="track-info-strip">
                            <div class="track-info-cell">
                                <div class="track-info-label">Product</div>
                                <div class="track-info-value"><?php echo htmlspecialchars($order['ProductName'] ?? 'Water'); ?></div>
                            </div>
                            <div class="track-info-cell">
                                <div class="track-info-label">Qty</div>
                                <div class="track-info-value"><?php echo $order['quantity']; ?> unit<?php echo $order['quantity'] != 1 ? 's' : ''; ?></div>
                            </div>
                            <div class="track-info-cell">
                                <div class="track-info-label">Total</div>
                                <div class="track-info-value amount">₱<?php echo number_format($order['total_amount'], 2); ?></div>
                            </div>
                            <div class="track-info-cell">
                                <div class="track-info-label">Payment</div>
                                <div class="track-info-value"><?php echo htmlspecialchars($order['payment_method'] ?? 'N/A'); ?></div>
                            </div>
                        </div>

                        <!-- Progress steps -->
                        <div class="track-progress-wrap">
                            <div class="progress-steps" id="steps-<?php echo $order['orderID']; ?>">
                                <div class="progress-fill" style="width: calc(<?php echo $fillPct; ?>% - 36px * <?php echo $fillPct/100; ?>);"></div>
                                <?php foreach ($steps as $i => $step):
                                    $isDone    = $i < $stepIndex;
                                    $isCurrent = $i === $stepIndex;
                                ?>
                                <div class="progress-step">
                                    <div class="step-dot <?php echo $isDone ? 'done' : ($isCurrent ? 'current' : ''); ?>">
                                        <i class="fas <?php echo $isDone ? 'fa-check' : $step['icon']; ?>"></i>
                                    </div>
                                    <div class="step-label <?php echo $isDone ? 'done-label' : ($isCurrent ? 'active-label' : ''); ?>">
                                        <?php echo $step['label']; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Delivery date chip -->
                        <?php if (!empty($order['delivery_date'])): ?>
                        <div class="delivery-chip mb-2">
                            <i class="fas fa-calendar-check"></i>
                            Scheduled: <?php echo date('F j, Y', strtotime($order['delivery_date'])); ?>
                        </div>
                        <?php else: ?>
                        <div class="delivery-chip mb-2">
                            <i class="fas fa-clock"></i>
                            Delivery date will be confirmed soon
                        </div>
                        <?php endif; ?>

                        <!-- Footer -->
                        <div class="track-card-footer">
                            <?php if (!empty($order['delivery_address'])): ?>
                                <div style="font-size:0.78rem; color:rgba(202,240,248,0.4); display:flex; align-items:flex-start; gap:6px; flex:1; min-width:0;">
                                    <i class="fas fa-map-marker-alt mt-1" style="color:var(--aqua); flex-shrink:0;"></i>
                                    <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                        <?php echo htmlspecialchars($order['delivery_address']); ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <span></span>
                            <?php endif; ?>

                            <button class="btn-glass"
                                    data-bs-toggle="modal"
                                    data-bs-target="#trackModal<?php echo $order['orderID']; ?>">
                                <i class="fas fa-eye"></i> Details
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Past Orders -->
        <?php if (count($pastOrders) > 0): ?>
            <div class="section-label mb-3">Completed & Cancelled</div>
            <div class="row g-3">
                <?php foreach ($pastOrders as $order):
                    $status      = $order['status'];
                    $statusClass = str_replace(' ', '-', $status);
                    $cardClass   = $status === 'Delivered' ? 'delivered-card' : 'cancelled-card';
                ?>
                <div class="col-lg-6">
                    <div class="track-card <?php echo $cardClass; ?>">

                        <div class="track-card-head">
                            <div>
                                <div class="track-order-num">Order #<?php echo $order['orderID']; ?></div>
                                <div class="track-order-date">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?php echo date('F j, Y · g:i A', strtotime($order['order_date'])); ?>
                                </div>
                            </div>
                            <span class="status-pill pill-<?php echo $statusClass; ?>"><?php echo $status; ?></span>
                        </div>

                        <div class="track-info-strip">
                            <div class="track-info-cell">
                                <div class="track-info-label">Product</div>
                                <div class="track-info-value"><?php echo htmlspecialchars($order['ProductName'] ?? 'Water'); ?></div>
                            </div>
                            <div class="track-info-cell">
                                <div class="track-info-label">Qty</div>
                                <div class="track-info-value"><?php echo $order['quantity']; ?> unit<?php echo $order['quantity'] != 1 ? 's' : ''; ?></div>
                            </div>
                            <div class="track-info-cell">
                                <div class="track-info-label">Total</div>
                                <div class="track-info-value amount">₱<?php echo number_format($order['total_amount'], 2); ?></div>
                            </div>
                            <div class="track-info-cell">
                                <div class="track-info-label">Payment</div>
                                <div class="track-info-value"><?php echo htmlspecialchars($order['payment_method'] ?? 'N/A'); ?></div>
                            </div>
                        </div>

                        <!-- Simplified final state -->
                        <div style="padding: 16px 22px; display:flex; align-items:center; gap:12px;">
                            <?php if ($status === 'Delivered'): ?>
                                <div style="width:36px;height:36px;border-radius:50%;background:rgba(74,222,128,0.15);border:1px solid rgba(74,222,128,0.3);display:flex;align-items:center;justify-content:center;color:#4ade80;font-size:0.9rem;flex-shrink:0;">
                                    <i class="fas fa-check"></i>
                                </div>
                                <div>
                                    <div style="font-size:0.88rem;color:var(--foam);font-weight:500;">Successfully Delivered</div>
                                    <?php if (!empty($order['delivery_date'])): ?>
                                        <div style="font-size:0.74rem;color:rgba(202,240,248,0.38);">
                                            <?php echo date('F j, Y', strtotime($order['delivery_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div style="width:36px;height:36px;border-radius:50%;background:rgba(248,113,113,0.1);border:1px solid rgba(248,113,113,0.25);display:flex;align-items:center;justify-content:center;color:#fca5a5;font-size:0.9rem;flex-shrink:0;">
                                    <i class="fas fa-xmark"></i>
                                </div>
                                <div>
                                    <div style="font-size:0.88rem;color:#fca5a5;font-weight:500;">Order Cancelled</div>
                                    <div style="font-size:0.74rem;color:rgba(202,240,248,0.38);">Cancelled by customer</div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="track-card-footer" style="padding-top:0;">
                            <span></span>
                            <button class="btn-glass"
                                    data-bs-toggle="modal"
                                    data-bs-target="#trackModal<?php echo $order['orderID']; ?>">
                                <i class="fas fa-eye"></i> Details
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="empty-state">
            <div class="empty-ring"><i class="fas fa-box-open"></i></div>
            <h5>No Orders to Track</h5>
            <p>You haven't placed any orders yet. Start your first order!</p>
            <a href="products.php" class="btn-order-now">
                <i class="fas fa-droplet"></i> Browse Products
            </a>
        </div>
    <?php endif; ?>

</main>

<!-- ── MODALS (all orders) ── -->
<?php foreach ($allOrders as $order):
    $status      = $order['status'];
    $statusClass = str_replace(' ', '-', $status);
?>
<div class="modal fade" id="trackModal<?php echo $order['orderID']; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-receipt me-2" style="color:var(--aqua);"></i>
                    Order #<?php echo $order['orderID']; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="modal-detail-grid">
                    <div class="modal-detail-cell">
                        <div class="modal-detail-label">Order Date</div>
                        <div class="modal-detail-value"><?php echo date('F j, Y', strtotime($order['order_date'])); ?></div>
                    </div>
                    <div class="modal-detail-cell">
                        <div class="modal-detail-label">Status</div>
                        <div class="modal-detail-value">
                            <span class="status-pill pill-<?php echo $statusClass; ?>"><?php echo $status; ?></span>
                        </div>
                    </div>
                    <div class="modal-detail-cell">
                        <div class="modal-detail-label">Product</div>
                        <div class="modal-detail-value"><?php echo htmlspecialchars($order['ProductName'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="modal-detail-cell">
                        <div class="modal-detail-label">Quantity</div>
                        <div class="modal-detail-value"><?php echo $order['quantity']; ?> unit<?php echo $order['quantity'] != 1 ? 's' : ''; ?></div>
                    </div>
                    <div class="modal-detail-cell">
                        <div class="modal-detail-label">Total Amount</div>
                        <div class="modal-detail-value highlight">₱<?php echo number_format($order['total_amount'], 2); ?></div>
                    </div>
                    <div class="modal-detail-cell">
                        <div class="modal-detail-label">Payment Method</div>
                        <div class="modal-detail-value"><?php echo htmlspecialchars($order['payment_method'] ?? 'N/A'); ?></div>
                    </div>
                    <?php if (!empty($order['delivery_date'])): ?>
                    <div class="modal-detail-cell">
                        <div class="modal-detail-label">Delivery Date</div>
                        <div class="modal-detail-value"><?php echo date('F j, Y', strtotime($order['delivery_date'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['delivery_address'])): ?>
                    <div class="modal-detail-cell full">
                        <div class="modal-detail-label">Delivery Address</div>
                        <div class="modal-detail-value"><?php echo htmlspecialchars($order['delivery_address']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['notes'])): ?>
                    <div class="modal-detail-cell full">
                        <div class="modal-detail-label">Notes</div>
                        <div class="modal-detail-value"><?php echo htmlspecialchars($order['notes']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <a href="order_history.php" class="btn-glass" style="font-size:0.8rem;">
                    <i class="fas fa-history"></i> Full History
                </a>
                <button type="button" class="btn-glass" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

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

    sidebar.querySelectorAll('.nav-link').forEach(l =>
        l.addEventListener('click', () => { if (window.innerWidth < 992) closeSidebar(); })
    );

    // ── COUNTDOWN REFRESH ──
    let seconds = 30;
    const refreshInfo = document.querySelector('.refresh-info');

    const countdown = setInterval(() => {
        seconds--;
        if (refreshInfo) refreshInfo.lastChild.textContent = ` Refreshes in ${seconds}s`;
        if (seconds <= 0) { clearInterval(countdown); location.reload(); }
    }, 1000);
</script>
</body>
</html>