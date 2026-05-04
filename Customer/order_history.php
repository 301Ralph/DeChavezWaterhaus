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
        $restoreStock = $conn->prepare("UPDATE product SET Stock = COALESCE(Stock, 0) + ? WHERE ProductID = ?");
        $restoreStock->bind_param("ii", $orderData['quantity'], $orderData['productID']);
        $restoreStock->execute();
        $restoreStock->close();

        $cancelStmt = $conn->prepare("UPDATE orders SET status = 'Cancelled', cancelled_at = NOW(), cancel_reason = 'Cancelled by customer' WHERE orderID = ? AND $customerColumn = ?");
        $cancelStmt->bind_param("ii", $orderID, $userID);
        $cancelStmt->execute();
        $cancelStmt->close();

        $message = "Your order #$orderID has been cancelled successfully. Stock has been restored.";
        $notifStmt = $conn->prepare("INSERT INTO notifications (userID, message, type) VALUES (?, ?, 'order')");
        $notifStmt->bind_param("is", $userID, $message);
        $notifStmt->execute();
        $notifStmt->close();

        echo '<script>alert("Order cancelled successfully!"); window.location = "order_history.php";</script>';
        exit();
    } else {
        echo '<script>alert("Cannot cancel this order. It may have already been processed."); window.location = "order_history.php";</script>';
        exit();
    }
}

// Fetch orders
$sortOrder = isset($_GET['order']) && $_GET['order'] == 'asc' ? 'ASC' : 'DESC';
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
    ORDER BY o.order_date $sortOrder";

$ordersResult = $conn->query($ordersQuery);

$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName  = explode(' ', $userName)[0];
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
            font-size: 1.05rem; font-weight: 500;
            color: var(--white); line-height: 1.2;
        }

        .sidebar-nav {
            flex: 1; overflow-y: auto;
            padding: 16px 12px 20px;
            scrollbar-width: thin;
            scrollbar-color: rgba(72,202,228,0.15) transparent;
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
            border: 1px solid rgba(0,180,216,0.2);
            color: var(--aqua) !important;
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
        .main-content {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
            padding: 28px 32px;
        }

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
            border-radius: 50px; padding: 6px 14px 6px 6px;
            cursor: pointer; transition: all 0.3s;
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

        /* dropdown */
        .dropdown-menu {
            background: var(--ocean) !important;
            border: 1px solid var(--glass-border) !important;
            border-radius: 14px !important;
            padding: 8px !important;
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

        /* ── PAGE HEADER ── */
        .page-header {
            background: linear-gradient(135deg, rgba(0,119,182,0.2), rgba(0,180,216,0.08));
            border: 1px solid rgba(0,180,216,0.2);
            border-radius: 18px;
            padding: 24px 28px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-header-icon {
            width: 52px; height: 52px; border-radius: 14px;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: var(--deep);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
            box-shadow: 0 6px 20px rgba(0,180,216,0.3);
        }

        .page-header-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.6rem; font-weight: 400; color: var(--white);
        }

        .page-header-sub { font-size: 0.82rem; color: rgba(202,240,248,0.4); margin-top: 3px; }

        .btn-order-now {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border: none; color: var(--deep);
            padding: 11px 26px; border-radius: 50px;
            font-weight: 700; font-size: 0.83rem;
            letter-spacing: 0.08em; text-transform: uppercase;
            text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px;
            transition: all 0.3s;
            box-shadow: 0 5px 18px rgba(0,180,216,0.3);
        }

        .btn-order-now:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,180,216,0.5); color: var(--deep); }

        /* ── TOOLBAR ── */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 12px;
            flex-wrap: wrap;
        }

        .toolbar-left { display: flex; gap: 8px; flex-wrap: wrap; }

        .sort-btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 18px; border-radius: 50px;
            border: 1px solid var(--glass-border);
            background: transparent;
            color: rgba(202,240,248,0.5);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.8rem; font-weight: 500;
            text-decoration: none; transition: all 0.25s;
        }

        .sort-btn:hover { border-color: rgba(0,180,216,0.3); color: var(--foam); }

        .sort-btn.active {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border-color: transparent; color: var(--deep); font-weight: 700;
            box-shadow: 0 4px 14px rgba(0,180,216,0.25);
        }

        /* Status filter pills */
        .status-filter-wrap { display: flex; gap: 6px; flex-wrap: wrap; }

        .status-filter {
            padding: 6px 14px; border-radius: 50px;
            border: 1px solid var(--glass-border);
            background: transparent;
            color: rgba(202,240,248,0.4);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.75rem; font-weight: 500;
            cursor: pointer; transition: all 0.25s;
        }

        .status-filter:hover { color: var(--foam); border-color: rgba(0,180,216,0.25); }
        .status-filter.active { background: rgba(0,180,216,0.12); color: var(--aqua); border-color: rgba(0,180,216,0.3); font-weight: 600; }

        /* search */
        .search-wrap { position: relative; }

        .search-input {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: var(--white);
            border-radius: 50px;
            padding: 9px 16px 9px 38px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.83rem;
            width: 200px;
            transition: all 0.3s;
            outline: none;
        }

        .search-input::placeholder { color: rgba(202,240,248,0.25); }

        .search-input:focus {
            border-color: rgba(0,180,216,0.4);
            background: rgba(0,180,216,0.08);
            box-shadow: 0 0 0 3px rgba(0,180,216,0.08);
            width: 240px;
        }

        .search-icon {
            position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
            color: rgba(0,180,216,0.4); font-size: 0.8rem; pointer-events: none;
        }

        /* ── ORDER CARDS ── */
        .order-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.55), rgba(3,15,30,0.78));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            overflow: hidden;
            transition: all 0.35s cubic-bezier(0.23,1,0.32,1);
            animation: cardIn 0.4s ease both;
        }

        .order-card:hover {
            transform: translateY(-5px);
            border-color: rgba(0,180,216,0.28);
            box-shadow: 0 22px 50px rgba(0,0,0,0.35);
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .order-card:nth-child(1) { animation-delay: 0.05s; }
        .order-card:nth-child(2) { animation-delay: 0.10s; }
        .order-card:nth-child(3) { animation-delay: 0.15s; }
        .order-card:nth-child(n+4) { animation-delay: 0.20s; }

        /* status accent stripe */
        .order-card.stripe-Pending      { border-left: 3px solid #f4c842; }
        .order-card.stripe-Processing   { border-left: 3px solid #00b4d8; }
        .order-card.stripe-Out-for-Delivery { border-left: 3px solid #60a5fa; }
        .order-card.stripe-Delivered    { border-left: 3px solid #4ade80; }
        .order-card.stripe-Cancelled    { border-left: 3px solid #f87171; }

        .order-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 20px 22px 0;
        }

        .order-number {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.55rem; font-weight: 600; color: var(--white);
        }

        .order-meta {
            font-size: 0.78rem; color: rgba(202,240,248,0.38);
            margin-top: 3px;
            display: flex; align-items: center; gap: 6px;
        }

        /* status pills */
        .status-pill {
            padding: 5px 14px; border-radius: 50px;
            font-size: 0.72rem; font-weight: 700;
            letter-spacing: 0.08em; text-transform: uppercase;
            white-space: nowrap;
        }

        .pill-Pending          { background: rgba(244,200,66,0.12); color: #f4c842; border: 1px solid rgba(244,200,66,0.25); }
        .pill-Processing       { background: rgba(0,180,216,0.1);   color: #00b4d8; border: 1px solid rgba(0,180,216,0.25); }
        .pill-Out-for-Delivery { background: rgba(96,165,250,0.1);  color: #60a5fa; border: 1px solid rgba(96,165,250,0.25); }
        .pill-Delivered        { background: rgba(74,222,128,0.1);  color: #4ade80; border: 1px solid rgba(74,222,128,0.25); }
        .pill-Cancelled        { background: rgba(248,113,113,0.1); color: #fca5a5; border: 1px solid rgba(248,113,113,0.25); }

        /* product strip */
        .product-strip {
            display: flex; align-items: center; gap: 12px;
            margin: 16px 22px;
            padding: 14px 16px;
            background: rgba(4,30,53,0.55);
            border: 1px solid rgba(72,202,228,0.08);
            border-radius: 12px;
        }

        .product-thumb {
            width: 50px; height: 50px; border-radius: 10px;
            object-fit: cover; border: 1px solid var(--glass-border);
            flex-shrink: 0;
        }

        .product-name {
            font-size: 0.88rem; color: var(--foam); font-weight: 500; line-height: 1.4;
        }

        .product-meta {
            font-size: 0.75rem; color: rgba(202,240,248,0.38); margin-top: 3px;
        }

        /* card footer */
        .order-card-footer {
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px 22px 18px;
            border-top: 1px solid rgba(72,202,228,0.08);
            flex-wrap: wrap; gap: 12px;
        }

        .order-total-label { font-size: 0.72rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); }
        .order-total-value {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.7rem; font-weight: 600; color: var(--aqua); line-height: 1;
        }

        .card-actions { display: flex; gap: 8px; }

        .btn-glass {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--glass); border: 1px solid var(--glass-border);
            color: var(--aqua); padding: 9px 18px; border-radius: 50px;
            font-size: 0.8rem; font-weight: 600; text-decoration: none; cursor: pointer;
            transition: all 0.3s ease; white-space: nowrap;
        }

        .btn-glass:hover {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border-color: transparent; color: var(--deep);
        }

        .btn-danger-glass {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(248,113,113,0.08);
            border: 1px solid rgba(248,113,113,0.22);
            color: #fca5a5; padding: 9px 18px; border-radius: 50px;
            font-size: 0.8rem; font-weight: 600; cursor: pointer;
            transition: all 0.3s ease; white-space: nowrap;
        }

        .btn-danger-glass:hover { background: #ef4444; border-color: #ef4444; color: white; }

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

        .modal-header {
            border-bottom: 1px solid var(--glass-border) !important;
            padding: 22px 26px !important;
        }

        .modal-footer {
            border-top: 1px solid var(--glass-border) !important;
            padding: 18px 26px !important;
        }

        .modal-body { padding: 26px !important; }

        .modal-title {
            font-family: 'Cormorant Garamond', serif !important;
            font-size: 1.4rem !important; font-weight: 500 !important;
            color: var(--white) !important;
        }

        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

        /* detail table */
        .detail-table { width: 100%; border-collapse: collapse; }

        .detail-table td {
            padding: 9px 0;
            font-size: 0.86rem;
            border-bottom: 1px solid rgba(72,202,228,0.07);
            vertical-align: top;
        }

        .detail-table tr:last-child td { border-bottom: none; }
        .detail-table td:first-child { color: rgba(202,240,248,0.4); width: 38%; }
        .detail-table td:last-child  { color: var(--foam); font-weight: 500; }

        .detail-section-label {
            font-size: 0.7rem; letter-spacing: 0.2em; text-transform: uppercase;
            color: var(--aqua); margin-bottom: 12px; margin-top: 4px;
            display: flex; align-items: center; gap: 8px;
        }

        .detail-section-label::after {
            content: ''; flex: 1; height: 1px; background: rgba(0,180,216,0.15);
        }

        /* items table */
        .items-table { width: 100%; border-collapse: collapse; }

        .items-table th {
            font-size: 0.68rem; letter-spacing: 0.15em; text-transform: uppercase;
            color: rgba(202,240,248,0.3); padding: 0 8px 12px;
            border-bottom: 1px solid var(--glass-border);
            text-align: left;
        }

        .items-table th:last-child, .items-table td:last-child { text-align: right; }
        .items-table th:nth-child(2), .items-table td:nth-child(2) { text-align: center; }

        .items-table td {
            padding: 12px 8px;
            font-size: 0.85rem; color: rgba(202,240,248,0.7);
            border-bottom: 1px solid rgba(72,202,228,0.06);
        }

        .items-table tr:last-child td { border-bottom: none; }
        .items-table tr:hover td { background: rgba(0,180,216,0.03); color: var(--foam); }

        .item-thumb { width: 38px; height: 38px; border-radius: 8px; object-fit: cover; border: 1px solid var(--glass-border); }

        .items-tfoot td {
            padding: 14px 8px 0;
            border-top: 1px solid var(--glass-border);
            font-size: 0.85rem; color: rgba(202,240,248,0.5);
            font-weight: 600;
        }

        .items-tfoot .total-val {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem; color: var(--aqua);
        }

        /* cancel modal warning */
        .cancel-warning {
            background: rgba(248,113,113,0.07);
            border: 1px solid rgba(248,113,113,0.2);
            border-radius: 12px;
            padding: 14px 16px;
            font-size: 0.85rem; color: rgba(252,165,165,0.8);
            display: flex; gap: 10px; margin-top: 14px;
        }

        /* ── MOBILE ── */
        .sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(2,13,24,0.7); z-index: 999;
            backdrop-filter: blur(3px);
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
            .search-input { width: 160px; }
            .search-input:focus { width: 180px; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
            .page-header { padding: 18px 20px; }
            .order-card-footer { flex-direction: column; align-items: flex-start; }
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
        <a href="order_history.php"      class="nav-link active"><i class="fas fa-history"></i> Order History</a>
        <a href="order_tracking.php"     class="nav-link"><i class="fas fa-map-marker-alt"></i> Track Orders</a>
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

<!-- ── MAIN CONTENT ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
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

    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex align-items-center gap-3">
            <div class="page-header-icon"><i class="fas fa-history"></i></div>
            <div>
                <div class="page-header-title">Your Orders</div>
                <div class="page-header-sub"><?php echo $ordersResult->num_rows; ?> order<?php echo $ordersResult->num_rows != 1 ? 's' : ''; ?> found in your history</div>
            </div>
        </div>
        <a href="products.php" class="btn-order-now">
            <i class="fas fa-plus"></i> New Order
        </a>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-left">
            <!-- Sort -->
            <a href="?order=desc" class="sort-btn <?php echo $sortOrder == 'DESC' ? 'active' : ''; ?>">
                <i class="fas fa-sort-amount-down"></i> Newest
            </a>
            <a href="?order=asc" class="sort-btn <?php echo $sortOrder == 'ASC' ? 'active' : ''; ?>">
                <i class="fas fa-sort-amount-up"></i> Oldest
            </a>

            <!-- Status filters -->
            <div class="status-filter-wrap ms-2">
                <button class="status-filter active" onclick="filterStatus('all', this)">All</button>
                <button class="status-filter" onclick="filterStatus('Pending', this)">Pending</button>
                <button class="status-filter" onclick="filterStatus('Processing', this)">Processing</button>
                <button class="status-filter" onclick="filterStatus('Out for Delivery', this)">Delivery</button>
                <button class="status-filter" onclick="filterStatus('Delivered', this)">Delivered</button>
                <button class="status-filter" onclick="filterStatus('Cancelled', this)">Cancelled</button>
            </div>
        </div>

        <!-- Search -->
        <div class="search-wrap">
            <i class="fas fa-search search-icon"></i>
            <input type="text" class="search-input" id="orderSearch" placeholder="Search orders…">
        </div>
    </div>

    <!-- Orders List -->
    <?php if ($ordersResult->num_rows > 0): ?>
        <div class="row g-3" id="ordersList">
            <?php
            $ordersResult->data_seek(0);
            while ($order = $ordersResult->fetch_assoc()):
                $statusClass = str_replace(' ', '-', $order['status']);
                $images      = explode('|', $order['product_images']);
                $firstImage  = $images[0] ?? '';
                $displayImage = (!empty($firstImage) && file_exists('../' . $firstImage))
                    ? '../' . $firstImage
                    : 'https://images.unsplash.com/photo-1548839140-29a749e1cf4d?w=80&q=60';
            ?>
            <div class="col-12 order-row" data-status="<?php echo htmlspecialchars($order['status']); ?>"
                 data-search="<?php echo strtolower($order['orderID'] . ' ' . $order['products'] . ' ' . $order['status']); ?>">
                <div class="order-card stripe-<?php echo $statusClass; ?>">

                    <!-- Card Header -->
                    <div class="order-card-header">
                        <div>
                            <div class="order-number">Order #<?php echo $order['orderID']; ?></div>
                            <div class="order-meta">
                                <i class="fas fa-calendar-alt"></i>
                                <?php echo date('F j, Y · g:i A', strtotime($order['order_date'])); ?>
                            </div>
                        </div>
                        <span class="status-pill pill-<?php echo $statusClass; ?>">
                            <?php echo $order['status']; ?>
                        </span>
                    </div>

                    <!-- Product Strip -->
                    <div class="product-strip">
                        <img src="<?php echo $displayImage; ?>" class="product-thumb"
                             alt="" onerror="this.src='https://images.unsplash.com/photo-1548839140-29a749e1cf4d?w=80&q=60'">
                        <div style="min-width:0;">
                            <div class="product-name"><?php echo htmlspecialchars($order['products']); ?></div>
                            <div class="product-meta">
                                <i class="fas fa-credit-card me-1"></i>
                                <?php echo htmlspecialchars($order['payment_method'] ?? 'N/A'); ?>
                                <?php if (!empty($order['delivery_address'])): ?>
                                    · <i class="fas fa-map-marker-alt ms-1 me-1"></i>
                                    <?php echo htmlspecialchars(mb_strimwidth($order['delivery_address'], 0, 45, '…')); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Card Footer -->
                    <div class="order-card-footer">
                        <div>
                            <div class="order-total-label">Total Amount</div>
                            <div class="order-total-value">₱<?php echo number_format($order['total_amount'], 2); ?></div>
                        </div>

                        <div class="card-actions">
                            <button class="btn-glass"
                                    data-bs-toggle="modal"
                                    data-bs-target="#detailModal<?php echo $order['orderID']; ?>">
                                <i class="fas fa-eye"></i> Details
                            </button>

                            <?php if (in_array($order['status'], ['Pending', 'Processing'])): ?>
                                <button class="btn-danger-glass"
                                        data-bs-toggle="modal"
                                        data-bs-target="#cancelModal<?php echo $order['orderID']; ?>">
                                    <i class="fas fa-xmark"></i> Cancel
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── DETAIL MODAL ── -->
            <div class="modal fade" id="detailModal<?php echo $order['orderID']; ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-receipt me-2" style="color:var(--aqua);"></i>
                                Order #<?php echo $order['orderID']; ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">

                            <div class="row g-4 mb-4">
                                <!-- Order info -->
                                <div class="col-md-6">
                                    <div class="detail-section-label">Order Info</div>
                                    <table class="detail-table">
                                        <tr>
                                            <td>Order Date</td>
                                            <td><?php echo date('F j, Y · g:i A', strtotime($order['order_date'])); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Status</td>
                                            <td><span class="status-pill pill-<?php echo $statusClass; ?>"><?php echo $order['status']; ?></span></td>
                                        </tr>
                                        <tr>
                                            <td>Payment</td>
                                            <td><?php echo htmlspecialchars($order['payment_method'] ?? 'N/A'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                                <!-- Delivery info -->
                                <div class="col-md-6">
                                    <div class="detail-section-label">Delivery Info</div>
                                    <table class="detail-table">
                                        <tr>
                                            <td>Address</td>
                                            <td><?php echo htmlspecialchars($order['delivery_address'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <?php if (!empty($order['notes'])): ?>
                                        <tr>
                                            <td>Notes</td>
                                            <td><?php echo htmlspecialchars($order['notes']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>

                            <!-- Items -->
                            <div class="detail-section-label">Items Ordered</div>
                            <div style="overflow-x:auto;">
                                <table class="items-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Qty</th>
                                            <th>Unit Price</th>
                                            <th>Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $iStmt = $conn->prepare("SELECT oi.*, p.ProductName, p.ImageURL FROM order_items oi JOIN product p ON oi.productID = p.ProductID WHERE oi.orderID = ?");
                                        $iStmt->bind_param("i", $order['orderID']);
                                        $iStmt->execute();
                                        $items = $iStmt->get_result();
                                        while ($item = $items->fetch_assoc()):
                                            $img = (!empty($item['ImageURL']) && file_exists('../' . $item['ImageURL']))
                                                ? '../' . $item['ImageURL']
                                                : 'https://images.unsplash.com/photo-1548839140-29a749e1cf4d?w=60&q=60';
                                        ?>
                                        <tr>
                                            <td>
                                                <div style="display:flex;align-items:center;gap:10px;">
                                                    <img src="<?php echo $img; ?>" class="item-thumb" alt="">
                                                    <span><?php echo htmlspecialchars($item['ProductName']); ?></span>
                                                </div>
                                            </td>
                                            <td><?php echo $item['quantity']; ?></td>
                                            <td>₱<?php echo number_format($item['unit_price'], 2); ?></td>
                                            <td style="color:var(--aqua);font-weight:600;">₱<?php echo number_format($item['quantity'] * $item['unit_price'], 2); ?></td>
                                        </tr>
                                        <?php endwhile; $iStmt->close(); ?>
                                    </tbody>
                                    <tfoot class="items-tfoot">
                                        <tr>
                                            <td colspan="3">Total</td>
                                            <td class="total-val">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                        </div>
                        <div class="modal-footer">
                            <?php if (in_array($order['status'], ['Pending', 'Processing'])): ?>
                                <button class="btn-danger-glass"
                                        data-bs-dismiss="modal"
                                        data-bs-toggle="modal"
                                        data-bs-target="#cancelModal<?php echo $order['orderID']; ?>">
                                    <i class="fas fa-xmark"></i> Cancel Order
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn-glass" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── CANCEL MODAL ── -->
            <?php if (in_array($order['status'], ['Pending', 'Processing'])): ?>
            <div class="modal fade" id="cancelModal<?php echo $order['orderID']; ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header" style="border-bottom-color:rgba(248,113,113,0.2) !important;">
                            <h5 class="modal-title" style="color:#fca5a5 !important;">
                                <i class="fas fa-triangle-exclamation me-2"></i> Cancel Order
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p style="color:rgba(202,240,248,0.7); font-size:0.92rem;">
                                Are you sure you want to cancel
                                <strong style="color:var(--white);">Order #<?php echo $order['orderID']; ?></strong>?
                            </p>
                            <div class="cancel-warning">
                                <i class="fas fa-info-circle mt-1 flex-shrink-0"></i>
                                <span>This action cannot be undone. Product stock will be automatically restored to inventory.</span>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <form method="POST" action="order_history.php">
                                <input type="hidden" name="orderID" value="<?php echo $order['orderID']; ?>">
                                <div style="display:flex;gap:10px;">
                                    <button type="button" class="btn-glass" data-bs-dismiss="modal">Keep Order</button>
                                    <button type="submit" name="cancel_order" class="btn-danger-glass"
                                            style="background:#ef4444;border-color:#ef4444;color:white;">
                                        <i class="fas fa-xmark"></i> Yes, Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php endwhile; ?>
        </div>

        <!-- No results after filter -->
        <div id="noResults" style="display:none;" class="empty-state mt-3">
            <div class="empty-ring"><i class="fas fa-magnifying-glass"></i></div>
            <h5>No orders found</h5>
            <p>Try a different status filter or search term.</p>
        </div>

    <?php else: ?>
        <div class="empty-state">
            <div class="empty-ring"><i class="fas fa-shopping-bag"></i></div>
            <h5>No Orders Yet</h5>
            <p>You haven't placed any orders yet. Start shopping!</p>
            <a href="products.php" class="btn-order-now">
                <i class="fas fa-droplet"></i> Browse Products
            </a>
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
    sidebar.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => { if (window.innerWidth < 992) closeSidebar(); }));

    // ── STATUS FILTER ──
    let activeStatus = 'all';
    let searchTerm   = '';

    function filterStatus(status, btn) {
        activeStatus = status;
        document.querySelectorAll('.status-filter').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        applyFilters();
    }

    function applyFilters() {
        const rows    = document.querySelectorAll('.order-row');
        let visible   = 0;

        rows.forEach(row => {
            const matchStatus = activeStatus === 'all' || row.dataset.status === activeStatus;
            const matchSearch = !searchTerm || row.dataset.search.includes(searchTerm);
            const show = matchStatus && matchSearch;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        const noRes = document.getElementById('noResults');
        if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
    }

    // ── SEARCH ──
    const searchInput = document.getElementById('orderSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            searchTerm = this.value.toLowerCase().trim();
            applyFilters();
        });
        searchInput.addEventListener('keydown', e => {
            if (e.key === 'Escape') { searchInput.value = ''; searchTerm = ''; applyFilters(); }
        });
    }
</script>
</body>
</html>