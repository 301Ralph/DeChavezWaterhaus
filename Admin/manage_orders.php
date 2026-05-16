<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo '<script>alert("Access denied. Admins only."); window.location = "../login.php";</script>';
    exit();
}

$adminID   = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';
$admin     = $conn->query("SELECT * FROM customers WHERE userID = $adminID")->fetch_assoc();

// Handle Accept Pending Order
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accept_order'])) {
    $orderID = intval($_POST['orderID']);
    $stmt = $conn->prepare("UPDATE orders SET status='Processing' WHERE orderID=?");
    $stmt->bind_param("i", $orderID); $stmt->execute(); $stmt->close();
    $order = $conn->query("SELECT userID FROM orders WHERE orderID=$orderID")->fetch_assoc();
    if ($order) {
        $msg = "Your order #$orderID has been accepted and is now being processed!";
        $s   = $conn->prepare("INSERT INTO notifications (userID,message) VALUES (?,?)");
        $s->bind_param("is", $order['userID'], $msg); $s->execute(); $s->close();
    }
    echo '<script>alert("Order accepted! Status changed to Processing."); window.location="manage_orders.php";</script>';
    exit();
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $orderID    = intval($_POST['orderID']);
    $newStatus  = $_POST['status'];
    $employeeID = isset($_POST['employeeID']) ? intval($_POST['employeeID']) : null;

    $stmt = $conn->prepare("UPDATE orders SET status=? WHERE orderID=?");
    $stmt->bind_param("si", $newStatus, $orderID); $stmt->execute(); $stmt->close();

    if ($employeeID && $newStatus == 'Out for Delivery') {
        $existing = $conn->query("SELECT deliveryID FROM deliveries WHERE orderID=$orderID");
        if ($existing->num_rows > 0) {
            $conn->query("UPDATE deliveries SET riderID=$employeeID, status='In Transit' WHERE orderID=$orderID");
        } else {
            $conn->query("INSERT INTO deliveries (orderID,riderID,delivery_date,status) VALUES ($orderID,$employeeID,CURDATE(),'In Transit')");
        }
    }

    $order = $conn->query("SELECT userID FROM orders WHERE orderID=$orderID")->fetch_assoc();
    if ($order) {
        $msg = "Your order #$orderID status has been updated to: $newStatus";
        $s   = $conn->prepare("INSERT INTO notifications (userID,message) VALUES (?,?)");
        $s->bind_param("is", $order['userID'], $msg); $s->execute(); $s->close();
    }
    echo '<script>alert("Order status updated!"); window.location="manage_orders.php";</script>';
    exit();
}

// Fetch data
$pendingOrders = $conn->query("
    SELECT o.*, CONCAT(c.Firstname,' ',c.Lastname) as customer_name, c.Contact as customer_phone,
           p.ProductName, oi.quantity
    FROM orders o
    JOIN customers c ON o.userID = c.userID
    LEFT JOIN order_items oi ON o.orderID = oi.orderID
    LEFT JOIN product p ON oi.productID = p.ProductID
    WHERE o.status = 'Pending'
    ORDER BY o.order_date DESC
");

$orders = $conn->query("
    SELECT o.*, CONCAT(c.Firstname,' ',c.Lastname) as customer_name, c.Contact as customer_phone,
           d.delivery_date, d.riderID, p.ProductName, oi.quantity,
           CONCAT(e.Firstname,' ',e.Lastname) as employee_name
    FROM orders o
    JOIN customers c ON o.userID = c.userID
    LEFT JOIN deliveries d ON o.orderID = d.orderID
    LEFT JOIN order_items oi ON o.orderID = oi.orderID
    LEFT JOIN product p ON oi.productID = p.ProductID
    LEFT JOIN customers e ON d.riderID = e.userID
    WHERE o.status != 'Pending'
    ORDER BY o.order_date DESC
");

$employees   = $conn->query("SELECT userID, CONCAT(Firstname,' ',Lastname) as name FROM customers WHERE Role='employee' AND verification_status='approved'");
$employeeList = [];
while ($e = $employees->fetch_assoc()) $employeeList[] = $e;

$notifCount = $conn->query("SELECT COUNT(*) as u FROM notifications WHERE userID=$adminID AND is_read=0")->fetch_assoc()['u'] ?? 0;

// Stats
$statsQ  = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status='Processing' THEN 1 ELSE 0 END) as processing, SUM(CASE WHEN status='Out for Delivery' THEN 1 ELSE 0 END) as delivering, SUM(CASE WHEN status='Delivered' THEN 1 ELSE 0 END) as delivered FROM orders");
$stats   = $statsQ->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders • Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;  --abyss: #030f1e;  --ocean: #041e35;  --navy:  #0a2d4a;
            --teal:  #0077b6;  --aqua:  #00b4d8;  --cyan:  #48cae4;
            --foam:  #caf0f8;  --white: #f0f9ff;  --gold:  #f4c842;
            --green: #4ade80;  --red: #f87171;     --violet: #a78bfa;
            --glass: rgba(0,180,216,0.08);  --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--deep); color: var(--white); min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-w); background: var(--abyss); border-right: 1px solid var(--glass-border); z-index: 1000; display: flex; flex-direction: column; transition: transform 0.3s ease; }
        .sidebar-logo { padding: 22px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--glass-border); flex-shrink: 0; }
        .sidebar-logo img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(0,180,216,0.35); }
        .sidebar-logo-text { font-family: 'Cormorant Garamond', serif; font-size: 1rem; font-weight: 500; color: var(--white); line-height: 1.2; }
        .sidebar-logo-sub  { font-size: 0.65rem; color: rgba(202,240,248,0.3); letter-spacing: 0.1em; text-transform: uppercase; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 12px 10px; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.15) transparent; }
        .sidebar-nav::-webkit-scrollbar { width: 3px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }
        .nav-section-label { font-size: 0.58rem; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(202,240,248,0.22); padding: 14px 10px 5px; }
        .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 9px; color: rgba(202,240,248,0.48) !important; text-decoration: none; font-size: 0.84rem; font-weight: 500; transition: all 0.22s ease; margin-bottom: 1px; position: relative; }
        .nav-link i { width: 16px; text-align: center; font-size: 0.85rem; color: rgba(0,180,216,0.38); transition: color 0.22s; }
        .nav-link:hover { background: var(--glass); color: var(--foam) !important; }
        .nav-link:hover i { color: var(--aqua); }
        .nav-link.active { background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12)); border: 1px solid rgba(0,180,216,0.2); color: var(--aqua) !important; }
        .nav-link.active i { color: var(--aqua); }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 22%; bottom: 22%; width: 3px; background: var(--aqua); border-radius: 0 3px 3px 0; }
        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }
        .sidebar-footer { padding: 12px 10px; border-top: 1px solid var(--glass-border); flex-shrink: 0; }

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; padding: 26px 30px; }

        /* ── TOP BAR ── */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .topbar-left h4 { font-family: 'Cormorant Garamond', serif; font-size: 1.65rem; font-weight: 400; color: var(--white); line-height: 1.1; }
        .topbar-left p { font-size: 0.8rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .topbar-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--glass); border: 1px solid var(--glass-border); color: rgba(202,240,248,0.6); display: flex; align-items: center; justify-content: center; font-size: 0.88rem; text-decoration: none; transition: all 0.3s; position: relative; cursor: pointer; }
        .topbar-btn:hover { background: rgba(0,180,216,0.15); border-color: var(--aqua); color: var(--aqua); }
        .topbar-notif-badge { position: absolute; top: -3px; right: -3px; background: var(--gold); color: var(--deep); font-size: 0.55rem; font-weight: 700; min-width: 15px; height: 15px; border-radius: 50px; display: flex; align-items: center; justify-content: center; padding: 0 3px; }
        .avatar-btn { display: flex; align-items: center; gap: 9px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 50px; padding: 5px 12px 5px 5px; cursor: pointer; transition: all 0.3s; }
        .avatar-btn:hover { border-color: rgba(0,180,216,0.35); background: rgba(0,180,216,0.1); }
        .avatar-circle { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.82rem; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-name { font-size: 0.8rem; font-weight: 500; color: var(--white); }
        .avatar-role { font-size: 0.68rem; color: rgba(202,240,248,0.4); }
        .dropdown-menu { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 13px !important; padding: 7px !important; box-shadow: 0 18px 48px rgba(0,0,0,0.5) !important; }
        .dropdown-item { color: rgba(202,240,248,0.65) !important; border-radius: 7px !important; padding: 8px 13px !important; font-size: 0.83rem !important; transition: all 0.2s !important; }
        .dropdown-item:hover { background: var(--glass) !important; color: var(--aqua) !important; }
        .dropdown-item.text-danger { color: rgba(252,165,165,0.7) !important; }
        .dropdown-item.text-danger:hover { background: rgba(248,113,113,0.08) !important; color: #fca5a5 !important; }
        .dropdown-divider { border-color: var(--glass-border) !important; margin: 4px 0 !important; }

        /* ── STAT CARDS ── */
        .stat-card { background: linear-gradient(145deg,rgba(10,45,74,0.65),rgba(3,15,30,0.85)); border: 1px solid var(--glass-border); border-radius: 15px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-4px); border-color: rgba(0,180,216,0.25); box-shadow: 0 16px 40px rgba(0,0,0,0.3); }
        .stat-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .si-blue   { background: rgba(0,180,216,0.12); color: var(--aqua); }
        .si-gold   { background: rgba(244,200,66,0.1);  color: var(--gold); }
        .si-teal   { background: rgba(0,119,182,0.15);  color: #7dd3fc; }
        .si-purple { background: rgba(129,140,248,0.1); color: #818cf8; }
        .si-green  { background: rgba(74,222,128,0.1);  color: var(--green); }
        .stat-num  { font-family: 'Cormorant Garamond', serif; font-size: 1.85rem; font-weight: 600; color: var(--white); line-height: 1; }
        .stat-lbl  { font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-top: 3px; }

        /* ── PENDING ALERT CARD ── */
        .pending-card {
            background: linear-gradient(145deg, rgba(244,200,66,0.08), rgba(180,83,9,0.06));
            border: 1px solid rgba(244,200,66,0.22);
            border-left: 4px solid var(--gold);
            border-radius: 17px;
            overflow: hidden;
            margin-bottom: 22px;
        }

        .pending-card-head { display: flex; justify-content: space-between; align-items: center; padding: 16px 22px; border-bottom: 1px solid rgba(244,200,66,0.1); flex-wrap: wrap; gap: 10px; }
        .pending-card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; font-weight: 500; color: var(--gold); }
        .pending-card-sub   { font-size: 0.75rem; color: rgba(244,200,66,0.5); margin-top: 2px; }

        /* ── DATA CARD ── */
        .data-card { background: linear-gradient(145deg,rgba(10,45,74,0.5),rgba(3,15,30,0.75)); border: 1px solid var(--glass-border); border-radius: 17px; overflow: hidden; }
        .data-card-head { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 1px solid var(--glass-border); flex-wrap: wrap; gap: 10px; }
        .data-card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.18rem; font-weight: 500; color: var(--white); }
        .data-card-sub   { font-size: 0.75rem; color: rgba(202,240,248,0.35); margin-top: 2px; }

        /* filter pills */
        .filter-pills { display: flex; gap: 6px; flex-wrap: wrap; padding: 14px 20px; border-bottom: 1px solid rgba(72,202,228,0.06); }
        .filter-pill { padding: 5px 14px; border-radius: 50px; border: 1px solid var(--glass-border); background: transparent; color: rgba(202,240,248,0.42); font-family: 'DM Sans', sans-serif; font-size: 0.76rem; font-weight: 500; cursor: pointer; transition: all 0.22s; }
        .filter-pill:hover { color: var(--foam); border-color: rgba(0,180,216,0.28); }
        .filter-pill.active { background: linear-gradient(135deg, var(--teal), var(--aqua)); border-color: transparent; color: var(--deep); font-weight: 700; box-shadow: 0 4px 14px rgba(0,180,216,0.22); }

        /* search bar */
        .search-bar-wrap { padding: 14px 20px; border-bottom: 1px solid rgba(72,202,228,0.06); position: relative; }
        .search-input { width: 100%; max-width: 320px; background: rgba(4,30,53,0.6); border: 1px solid var(--glass-border); color: var(--white); border-radius: 50px; padding: 9px 16px 9px 38px; font-size: 0.84rem; font-family: 'DM Sans', sans-serif; outline: none; transition: all 0.3s; }
        .search-input::placeholder { color: rgba(202,240,248,0.22); }
        .search-input:focus { border-color: var(--aqua); background: rgba(0,180,216,0.06); }
        .search-icon { position: absolute; left: 34px; top: 50%; transform: translateY(-50%); color: rgba(0,180,216,0.35); font-size: 0.78rem; }

        /* ── TABLE ── */
        .ord-table { width: 100%; border-collapse: collapse; }
        .ord-table th { font-size: 0.66rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.3); padding: 0 16px 12px; text-align: left; border-bottom: 1px solid var(--glass-border); }
        .ord-table td { padding: 14px 16px; font-size: 0.85rem; color: rgba(202,240,248,0.7); border-bottom: 1px solid rgba(72,202,228,0.06); vertical-align: middle; }
        .ord-table tr:last-child td { border-bottom: none; }
        .ord-table tr:hover td { background: rgba(0,180,216,0.03); color: var(--foam); }

        .order-id { font-family: 'Cormorant Garamond', serif; font-size: 1rem; font-weight: 600; color: var(--aqua); }
        .cust-name { font-weight: 500; color: var(--white); font-size: 0.87rem; }
        .cust-phone { font-size: 0.72rem; color: rgba(202,240,248,0.35); }
        .amount-val { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; font-weight: 600; color: var(--white); }

        /* status pills */
        .s-pill { padding: 4px 11px; border-radius: 50px; font-size: 0.71rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
        .s-Pending          { background: rgba(244,200,66,0.12); color: var(--gold);  border: 1px solid rgba(244,200,66,0.25); }
        .s-Processing       { background: rgba(0,180,216,0.1);   color: var(--aqua);  border: 1px solid rgba(0,180,216,0.25); }
        .s-Out-for-Delivery { background: rgba(129,140,248,0.1); color: #818cf8;      border: 1px solid rgba(129,140,248,0.25); }
        .s-Delivered        { background: rgba(74,222,128,0.1);  color: var(--green); border: 1px solid rgba(74,222,128,0.25); }
        .s-Cancelled        { background: rgba(248,113,113,0.1); color: var(--red);   border: 1px solid rgba(248,113,113,0.25); }

        /* payment badge */
        .pay-badge { padding: 3px 10px; border-radius: 50px; font-size: 0.7rem; font-weight: 600; }
        .pay-GCash { background: rgba(0,180,216,0.1); color: var(--aqua); border: 1px solid rgba(0,180,216,0.22); }
        .pay-COD   { background: var(--glass); color: rgba(202,240,248,0.5); border: 1px solid var(--glass-border); }

        /* employee assigned badge */
        .emp-badge { display: inline-flex; align-items: center; gap: 5px; background: rgba(74,222,128,0.08); border: 1px solid rgba(74,222,128,0.2); color: var(--green); padding: 3px 10px; border-radius: 50px; font-size: 0.72rem; font-weight: 600; }

        /* count badge */
        .count-badge { background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); padding: 3px 10px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; }
        .pending-count { background: linear-gradient(135deg, #b45309, var(--gold)); color: var(--deep); padding: 3px 10px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; }

        /* action buttons */
        .btn-accept { display: inline-flex; align-items: center; gap: 5px; background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.25); color: var(--green); padding: 7px 16px; border-radius: 50px; font-size: 0.78rem; font-weight: 700; cursor: pointer; transition: all 0.25s; font-family: 'DM Sans', sans-serif; }
        .btn-accept:hover { background: rgba(74,222,128,0.2); transform: translateY(-1px); }
        .btn-receipt { display: inline-flex; align-items: center; gap: 5px; background: rgba(0,180,216,0.08); border: 1px solid rgba(0,180,216,0.2); color: var(--aqua); padding: 5px 12px; border-radius: 50px; font-size: 0.74rem; font-weight: 600; cursor: pointer; transition: all 0.25s; font-family: 'DM Sans', sans-serif; }
        .btn-receipt:hover { background: rgba(0,180,216,0.16); }
        .btn-update { display: inline-flex; align-items: center; gap: 5px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 7px 16px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; cursor: pointer; transition: all 0.25s; }
        .btn-update:hover { background: rgba(0,180,216,0.15); border-color: rgba(0,180,216,0.3); }

        /* empty */
        .empty-state { text-align: center; padding: 52px 20px; color: rgba(202,240,248,0.3); }
        .empty-state i { font-size: 2.2rem; display: block; margin-bottom: 12px; color: rgba(0,180,216,0.15); }
        .empty-state p { font-size: 0.85rem; }

        /* ── MODAL ── */
        .modal-content { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 18px !important; }
        .modal-header { border-bottom: 1px solid var(--glass-border) !important; padding: 20px 24px !important; }
        .modal-footer { border-top: 1px solid var(--glass-border) !important; padding: 16px 24px !important; }
        .modal-body { padding: 24px !important; }
        .modal-title { font-family: 'Cormorant Garamond', serif !important; font-size: 1.25rem !important; font-weight: 500 !important; color: var(--white) !important; }
        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

        .field-label { display: block; font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 7px; }
        .field-select { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 11px 14px; border-radius: 11px; outline: none; transition: all 0.3s; }
        .field-select:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }
        .field-select option { background: var(--ocean); }

        .current-status-box { background: rgba(4,30,53,0.5); border: 1px solid var(--glass-border); border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; font-size: 0.85rem; }
        .cs-label { font-size: 0.68rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-bottom: 6px; }

        .btn-glass-modal { display: inline-flex; align-items: center; gap: 6px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 9px 18px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-glass-modal:hover { background: rgba(0,180,216,0.15); color: var(--foam); }
        .btn-save-modal { padding: 10px 26px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.83rem; font-weight: 700; letter-spacing: 0.07em; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(0,180,216,0.25); }
        .btn-save-modal:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); }
        .btn-accept-modal { padding: 10px 22px; background: linear-gradient(135deg, #15803d, #4ade80); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.83rem; font-weight: 700; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 14px rgba(74,222,128,0.22); }
        .btn-accept-modal:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(74,222,128,0.38); }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 38px; height: 38px; border-radius: 9px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.88rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 18px 16px; }
            .mobile-toggle { display: flex; }
        }
    </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../images/logo.jpg" alt="Logo">
        <div>
            <div class="sidebar-logo-text">De Chavez Waterhaus</div>
            <div class="sidebar-logo-sub">Admin Panel</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="admin_dashboard.php"   class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="manage_products.php"   class="nav-link"><i class="fas fa-box"></i> Products</a>
        <a href="manage_orders.php"     class="nav-link active"><i class="fas fa-shopping-cart"></i> Orders</a>
        <a href="manage_users.php"      class="nav-link"><i class="fas fa-users"></i> Users</a>
        <a href="manage_employees.php"  class="nav-link"><i class="fas fa-user-tie"></i> Employees</a>
        <div class="nav-section-label">Operations</div>
        <a href="attendance_management.php" class="nav-link"><i class="fas fa-clock"></i> Attendance</a>
        <a href="payroll_management.php"    class="nav-link"><i class="fas fa-money-bill"></i> Payroll</a>
        <a href="generate_payslip.php"      class="nav-link"><i class="fas fa-file-pdf"></i> Generate Payslip</a>
        <a href="leave_management.php"      class="nav-link"><i class="fas fa-calendar-alt"></i> Manage Leave</a>
        <div class="nav-section-label">Support & Reports</div>
        <a href="support_tickets.php"   class="nav-link"><i class="fas fa-headset"></i> Support Tickets</a>
        <a href="reports.php"           class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="nav-section-label" style="margin-top:14px;"></div>
        <a href="profile.php"           class="nav-link"><i class="fas fa-user"></i> My Profile</a>
        <a href="../logout.php"         class="nav-link danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
            <div class="topbar-left">
                <h4>Manage Orders</h4>
                <p>Approve, assign, and track all customer orders</p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo min($notifCount,9).($notifCount>9?'+':'');?></span><?php endif; ?>
            </a>
            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if(!empty($admin['profile_picture'])&&file_exists('../'.$admin['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($admin['profile_picture']);?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($adminName,0,1));?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($adminName);?></div>
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

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="fas fa-shopping-cart"></i></div>
                <div><div class="stat-num"><?php echo $stats['total'];?></div><div class="stat-lbl">Total Orders</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-gold"><i class="fas fa-clock"></i></div>
                <div><div class="stat-num" style="color:var(--gold);"><?php echo $stats['pending'];?></div><div class="stat-lbl">Pending</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-purple"><i class="fas fa-truck"></i></div>
                <div><div class="stat-num" style="color:#818cf8;"><?php echo $stats['delivering'];?></div><div class="stat-lbl">Delivering</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="fas fa-check-circle"></i></div>
                <div><div class="stat-num" style="color:var(--green);"><?php echo $stats['delivered'];?></div><div class="stat-lbl">Delivered</div></div>
            </div>
        </div>
    </div>

    <!-- ── PENDING ORDERS (need receipt verification) ── -->
    <?php if($pendingOrders && $pendingOrders->num_rows > 0): ?>
    <div class="pending-card">
        <div class="pending-card-head">
            <div>
                <div class="pending-card-title"><i class="fas fa-exclamation-triangle me-2"></i>Pending Orders — Awaiting Receipt Verification</div>
                <div class="pending-card-sub">Review GCash receipts before accepting these orders</div>
            </div>
            <span class="pending-count"><?php echo $pendingOrders->num_rows;?> Pending</span>
        </div>

        <div style="overflow-x:auto;">
            <table class="ord-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Amount</th>
                        <th>Payment</th>
                        <th>Date</th>
                        <th style="text-align:right;padding-right:22px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $pendingOrders->data_seek(0); while($pending = $pendingOrders->fetch_assoc()): ?>
                    <tr>
                        <td><span class="order-id">#<?php echo $pending['orderID'];?></span></td>
                        <td>
                            <div class="cust-name"><?php echo htmlspecialchars($pending['customer_name']);?></div>
                            <div class="cust-phone"><?php echo htmlspecialchars($pending['customer_phone']??'');?></div>
                        </td>
                        <td>
                            <div style="font-weight:500;color:var(--white);"><?php echo htmlspecialchars($pending['ProductName']??'N/A');?></div>
                            <div style="font-size:0.72rem;color:rgba(202,240,248,0.35);">×<?php echo $pending['quantity']??0;?></div>
                        </td>
                        <td><span class="amount-val">₱<?php echo number_format($pending['total_amount'],2);?></span></td>
                        <td>
                            <span class="pay-badge pay-<?php echo $pending['payment_method']==='GCash'?'GCash':'COD';?>"><?php echo $pending['payment_method'];?></span>
                            <?php if($pending['payment_method']==='GCash' && !empty($pending['notes'])): ?>
                            <button class="btn-receipt ms-2" data-bs-toggle="modal" data-bs-target="#receiptModal<?php echo $pending['orderID'];?>">
                                <i class="fas fa-receipt"></i> Receipt
                            </button>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.78rem;color:rgba(202,240,248,0.35);"><?php echo date('M j, Y', strtotime($pending['order_date']));?></td>
                        <td style="text-align:right;padding-right:18px;">
                            <form method="POST" onsubmit="return confirm('Accept this order and move to Processing?')">
                                <input type="hidden" name="orderID" value="<?php echo $pending['orderID'];?>">
                                <button type="submit" name="accept_order" class="btn-accept">
                                    <i class="fas fa-check"></i> Accept Order
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Receipt Modals -->
    <?php $pendingOrders->data_seek(0); while($pending = $pendingOrders->fetch_assoc()):
        if($pending['payment_method'] !== 'GCash' || empty($pending['notes'])) continue;
        $receiptPath = $pending['notes'];
        if(strpos($receiptPath,'GCash Receipt: ')===0) $receiptPath = substr($receiptPath, strlen('GCash Receipt: '));
        $fullPath = '../'.$receiptPath;
    ?>
    <div class="modal fade" id="receiptModal<?php echo $pending['orderID'];?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-receipt me-2" style="color:var(--aqua);"></i>GCash Receipt · Order #<?php echo $pending['orderID'];?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="text-align:center;">
                    <?php if(file_exists($fullPath)): ?>
                        <img src="<?php echo htmlspecialchars($fullPath);?>" class="img-fluid rounded" style="max-height:500px;" alt="GCash Receipt">
                    <?php else: ?>
                        <div style="background:rgba(248,113,113,0.08);border:1px solid rgba(248,113,113,0.22);border-radius:12px;padding:20px;color:var(--red);">
                            <i class="fas fa-exclamation-circle me-2"></i>Receipt file not found: <?php echo htmlspecialchars($receiptPath);?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass-modal" data-bs-dismiss="modal">Close</button>
                    <form method="POST" onsubmit="return confirm('Accept this order?')" style="display:inline;">
                        <input type="hidden" name="orderID" value="<?php echo $pending['orderID'];?>">
                        <button type="submit" name="accept_order" class="btn-accept-modal"><i class="fas fa-check me-1"></i> Accept &amp; Process</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endwhile; ?>
    <?php endif; ?>

    <!-- ── ALL ORDERS TABLE ── -->
    <div class="data-card">
        <div class="data-card-head">
            <div>
                <div class="data-card-title">All Orders</div>
                <div class="data-card-sub">Showing all non-pending orders</div>
            </div>
            <span class="count-badge"><?php echo $orders->num_rows;?> Orders</span>
        </div>

        <!-- Filters + Search -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:14px 20px;border-bottom:1px solid rgba(72,202,228,0.06);gap:10px;">
            <div class="filter-pills" style="padding:0;">
                <button class="filter-pill active" onclick="filterOrders('all',this)">All</button>
                <button class="filter-pill" onclick="filterOrders('Processing',this)">Processing</button>
                <button class="filter-pill" onclick="filterOrders('Out for Delivery',this)">Delivering</button>
                <button class="filter-pill" onclick="filterOrders('Delivered',this)">Delivered</button>
                <button class="filter-pill" onclick="filterOrders('Cancelled',this)">Cancelled</button>
            </div>
            <div style="position:relative;">
                <i class="fas fa-search search-icon" style="left:14px;"></i>
                <input type="text" class="search-input" id="orderSearch" placeholder="Search orders…">
            </div>
        </div>

        <?php if($orders->num_rows > 0): ?>
        <div style="overflow-x:auto;">
            <table class="ord-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Amount</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Assigned</th>
                        <th>Date</th>
                        <th style="text-align:right;padding-right:22px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="ordersBody">
                    <?php $orders->data_seek(0); while($order = $orders->fetch_assoc()):
                        $sKey = str_replace(' ','-',$order['status']);
                    ?>
                    <tr class="ord-row"
                        data-status="<?php echo $order['status'];?>"
                        data-search="<?php echo strtolower(htmlspecialchars('#'.$order['orderID'].' '.$order['customer_name'].' '.$order['ProductName']));?>">
                        <td><span class="order-id">#<?php echo $order['orderID'];?></span></td>
                        <td>
                            <div class="cust-name"><?php echo htmlspecialchars($order['customer_name']);?></div>
                            <div class="cust-phone"><?php echo htmlspecialchars($order['customer_phone']??'');?></div>
                        </td>
                        <td>
                            <div style="font-weight:500;color:var(--white);"><?php echo htmlspecialchars($order['ProductName']??'N/A');?></div>
                            <div style="font-size:0.72rem;color:rgba(202,240,248,0.35);">×<?php echo $order['quantity']??0;?></div>
                        </td>
                        <td><span class="amount-val">₱<?php echo number_format($order['total_amount'],2);?></span></td>
                        <td><span class="pay-badge pay-<?php echo $order['payment_method']==='GCash'?'GCash':'COD';?>"><?php echo $order['payment_method'];?></span></td>
                        <td><span class="s-pill s-<?php echo $sKey;?>"><?php echo $order['status'];?></span></td>
                        <td>
                            <?php if(!empty($order['employee_name'])): ?>
                                <span class="emp-badge"><i class="fas fa-user" style="font-size:0.65rem;"></i><?php echo htmlspecialchars($order['employee_name']);?></span>
                            <?php else: ?>
                                <span style="font-size:0.75rem;color:rgba(202,240,248,0.28);">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.78rem;color:rgba(202,240,248,0.35);"><?php echo date('M j, Y', strtotime($order['order_date']));?></td>
                        <td style="text-align:right;padding-right:18px;">
                            <button class="btn-update" data-bs-toggle="modal" data-bs-target="#updateModal<?php echo $order['orderID'];?>">
                                <i class="fas fa-pen"></i> Update
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <div id="noResults" style="display:none;text-align:center;padding:40px 20px;color:rgba(202,240,248,0.3);font-size:0.85rem;">No orders match this filter.</div>

        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p>No orders to display yet.</p>
        </div>
        <?php endif; ?>
    </div>

</main>

<!-- ── UPDATE STATUS MODALS ── -->
<?php $orders->data_seek(0); while($order = $orders->fetch_assoc()):
    $sKey = str_replace(' ','-',$order['status']);
?>
<div class="modal fade" id="updateModal<?php echo $order['orderID'];?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="orderID" value="<?php echo $order['orderID'];?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen me-2" style="color:var(--aqua);font-size:0.9rem;"></i>Update Order #<?php echo $order['orderID'];?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="current-status-box">
                        <div class="cs-label">Customer</div>
                        <div style="font-weight:500;color:var(--white);margin-bottom:8px;"><?php echo htmlspecialchars($order['customer_name']);?></div>
                        <div class="cs-label">Current Status</div>
                        <span class="s-pill s-<?php echo $sKey;?>"><?php echo $order['status'];?></span>
                    </div>

                    <div style="margin-bottom:18px;">
                        <label class="field-label">New Status</label>
                        <select class="field-select" name="status" id="statusSel<?php echo $order['orderID'];?>"
                            onchange="toggleEmployeeSection(<?php echo $order['orderID'];?>, this.value)">
                            <option value="Pending"          <?php echo $order['status']==='Pending'?'selected':'';?>>Pending</option>
                            <option value="Processing"       <?php echo $order['status']==='Processing'?'selected':'';?>>Processing</option>
                            <option value="Out for Delivery" <?php echo $order['status']==='Out for Delivery'?'selected':'';?>>Out for Delivery</option>
                            <option value="Delivered"        <?php echo $order['status']==='Delivered'?'selected':'';?>>Delivered</option>
                            <option value="Cancelled"        <?php echo $order['status']==='Cancelled'?'selected':'';?>>Cancelled</option>
                        </select>
                    </div>

                    <div id="empSection<?php echo $order['orderID'];?>" style="display:<?php echo $order['status']==='Out for Delivery'?'block':'none';?>">
                        <label class="field-label">Assign to Employee</label>
                        <select class="field-select" name="employeeID">
                            <option value="">— Select Employee —</option>
                            <?php foreach($employeeList as $emp): ?>
                            <option value="<?php echo $emp['userID'];?>" <?php echo ($order['riderID']==$emp['userID'])?'selected':'';?>>
                                <?php echo htmlspecialchars($emp['name']);?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass-modal" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_status" class="btn-save-modal"><i class="fas fa-check me-1"></i> Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endwhile; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── SIDEBAR ──
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle  = document.getElementById('mobileToggle');
    function openSidebar()  { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }
    if(toggle)  toggle.addEventListener('click', openSidebar);
    if(overlay) overlay.addEventListener('click', closeSidebar);
    sidebar.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => { if(window.innerWidth<992) closeSidebar(); }));

    // ── TOGGLE EMPLOYEE SECTION ──
    function toggleEmployeeSection(id, val) {
        const el = document.getElementById('empSection' + id);
        if(el) el.style.display = val === 'Out for Delivery' ? 'block' : 'none';
    }

    // ── FILTER ──
    function filterOrders(status, btn) {
        document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        applyFilter();
    }

    function applyFilter() {
        const activePill = document.querySelector('.filter-pill.active');
        const status     = activePill ? activePill.textContent.trim() : 'all';
        const term       = (document.getElementById('orderSearch')?.value ?? '').toLowerCase().trim();
        const rows       = document.querySelectorAll('.ord-row');
        let visible      = 0;

        rows.forEach(row => {
            const matchStatus = status === 'All' || row.dataset.status === status || (status === 'Delivering' && row.dataset.status === 'Out for Delivery');
            const matchSearch = !term || row.dataset.search.includes(term);
            const show = matchStatus && matchSearch;
            row.style.display = show ? '' : 'none';
            if(show) visible++;
        });

        const nr = document.getElementById('noResults');
        if(nr) nr.style.display = visible === 0 ? 'block' : 'none';
    }

    document.getElementById('orderSearch')?.addEventListener('input', applyFilter);
</script>
</body>
</html>