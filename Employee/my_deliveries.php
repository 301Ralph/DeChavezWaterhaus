<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'employee') {
    echo '<script>alert("Access denied. Employees only."); window.location = "../login.php";</script>';
    exit();
}

$userID   = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Fetch employee data
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle mark as delivered
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_delivered'])) {
    $orderID = intval($_POST['orderID']);
    $update  = $conn->prepare("UPDATE orders SET status = 'Delivered' WHERE orderID = ?");
    $update->bind_param("i", $orderID);
    $update->execute();
    $update->close();
    echo '<script>alert("Order #' . $orderID . ' marked as Delivered!"); window.location = "my_deliveries.php";</script>';
    exit();
}

// Fetch assigned deliveries
$deliveries = [];
try {
    $result = $conn->query("
        SELECT o.*,
               GROUP_CONCAT(CONCAT(p.ProductName, ' x', oi.quantity) SEPARATOR ', ') AS products,
               GROUP_CONCAT(p.ImageURL SEPARATOR '|') AS product_images,
               c.Firstname AS customer_firstname, c.Lastname AS customer_lastname,
               c.Address AS customer_address, c.Contact AS customer_contact,
               da.full_address AS delivery_full_address, da.contact_number AS delivery_contact
        FROM orders o
        LEFT JOIN order_items oi ON o.orderID = oi.orderID
        LEFT JOIN product p ON oi.productID = p.ProductID
        LEFT JOIN customers c ON o.userID = c.userID
        INNER JOIN deliveries d ON o.orderID = d.orderID
        LEFT JOIN delivery_addresses da ON c.userID = da.userID AND da.is_default = 1
        WHERE d.riderID = $userID
        GROUP BY o.orderID
        ORDER BY o.order_date DESC
    ");
    if ($result) while ($row = $result->fetch_assoc()) $deliveries[] = $row;
} catch (Exception $e) {}

// Stats
$totalDeliveries    = count($deliveries);
$outForDelivery     = count(array_filter($deliveries, fn($d) => $d['status'] === 'Out for Delivery'));
$completed          = count(array_filter($deliveries, fn($d) => $d['status'] === 'Delivered'));
$pending            = count(array_filter($deliveries, fn($d) => in_array($d['status'], ['Pending', 'Processing'])));

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName  = explode(' ', $userName)[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Deliveries • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;  --abyss: #030f1e;  --ocean: #041e35;  --navy:  #0a2d4a;
            --teal:  #0077b6;  --aqua:  #00b4d8;  --cyan:  #48cae4;  --glow:  #90e0ef;
            --foam:  #caf0f8;  --white: #f0f9ff;  --gold:  #f4c842;
            --green: #4ade80;  --violet: #a78bfa;  --red: #f87171;
            --glass: rgba(0,180,216,0.08);  --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--deep); color: var(--white); min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-w); background: var(--abyss); border-right: 1px solid var(--glass-border); z-index: 1000; display: flex; flex-direction: column; transition: transform 0.3s ease; }
        .sidebar-logo { padding: 24px 22px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--glass-border); flex-shrink: 0; }
        .sidebar-logo img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(0,180,216,0.35); box-shadow: 0 0 14px rgba(0,180,216,0.2); }
        .sidebar-logo-text { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; font-weight: 500; color: var(--white); line-height: 1.2; }
        .sidebar-logo-sub  { font-size: 0.68rem; color: rgba(202,240,248,0.3); letter-spacing: 0.1em; text-transform: uppercase; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 16px 12px 16px; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.15) transparent; }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }
        .nav-section-label { font-size: 0.62rem; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(202,240,248,0.25); padding: 16px 12px 6px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 10px; color: rgba(202,240,248,0.5) !important; text-decoration: none; font-size: 0.87rem; font-weight: 500; transition: all 0.25s ease; margin-bottom: 2px; position: relative; }
        .nav-link i { width: 18px; text-align: center; font-size: 0.9rem; color: rgba(0,180,216,0.4); transition: color 0.25s; }
        .nav-link:hover { background: var(--glass); color: var(--foam) !important; }
        .nav-link:hover i { color: var(--aqua); }
        .nav-link.active { background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12)); border: 1px solid rgba(0,180,216,0.2); color: var(--aqua) !important; }
        .nav-link.active i { color: var(--aqua); }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 20%; bottom: 20%; width: 3px; background: var(--aqua); border-radius: 0 3px 3px 0; }
        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }
        .sidebar-footer { padding: 14px 12px; border-top: 1px solid var(--glass-border); flex-shrink: 0; }

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; padding: 28px 32px; }

        /* ── TOP BAR ── */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; }
        .topbar-left h4 { font-family: 'Cormorant Garamond', serif; font-size: 1.7rem; font-weight: 400; color: var(--white); line-height: 1.1; }
        .topbar-left p { font-size: 0.82rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .topbar-btn { width: 42px; height: 42px; border-radius: 50%; background: var(--glass); border: 1px solid var(--glass-border); color: rgba(202,240,248,0.6); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; text-decoration: none; transition: all 0.3s; position: relative; cursor: pointer; }
        .topbar-btn:hover { background: rgba(0,180,216,0.15); border-color: var(--aqua); color: var(--aqua); }
        .topbar-notif-badge { position: absolute; top: -3px; right: -3px; background: var(--gold); color: var(--deep); font-size: 0.58rem; font-weight: 700; min-width: 16px; height: 16px; border-radius: 50px; display: flex; align-items: center; justify-content: center; padding: 0 4px; }
        .avatar-btn { display: flex; align-items: center; gap: 10px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 50px; padding: 6px 14px 6px 6px; cursor: pointer; transition: all 0.3s; }
        .avatar-btn:hover { border-color: rgba(0,180,216,0.35); background: rgba(0,180,216,0.1); }
        .avatar-circle { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-name { font-size: 0.82rem; font-weight: 500; color: var(--white); }
        .avatar-role { font-size: 0.7rem; color: rgba(202,240,248,0.4); }
        .dropdown-menu { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 14px !important; padding: 8px !important; box-shadow: 0 20px 50px rgba(0,0,0,0.5) !important; }
        .dropdown-item { color: rgba(202,240,248,0.65) !important; border-radius: 8px !important; padding: 9px 14px !important; font-size: 0.84rem !important; transition: all 0.2s !important; }
        .dropdown-item:hover { background: var(--glass) !important; color: var(--aqua) !important; }
        .dropdown-item.text-danger { color: rgba(252,165,165,0.7) !important; }
        .dropdown-item.text-danger:hover { background: rgba(248,113,113,0.08) !important; color: #fca5a5 !important; }
        .dropdown-divider { border-color: var(--glass-border) !important; margin: 4px 0 !important; }

        /* ── PAGE HEADER ── */
        .page-header {
            background: linear-gradient(135deg, rgba(0,119,182,0.2), rgba(0,180,216,0.08));
            border: 1px solid rgba(0,180,216,0.2);
            border-radius: 18px; padding: 24px 28px; margin-bottom: 24px;
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 16px;
        }

        .page-header-icon { width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; box-shadow: 0 6px 20px rgba(0,180,216,0.3); }
        .page-header-title { font-family: 'Cormorant Garamond', serif; font-size: 1.6rem; font-weight: 400; color: var(--white); }
        .page-header-sub { font-size: 0.82rem; color: rgba(202,240,248,0.4); margin-top: 3px; }

        /* ── STAT CARDS ── */
        .stat-card { background: linear-gradient(145deg,rgba(10,45,74,0.65),rgba(3,15,30,0.85)); border: 1px solid var(--glass-border); border-radius: 16px; padding: 20px 18px; display: flex; align-items: center; gap: 14px; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-4px); border-color: rgba(0,180,216,0.25); box-shadow: 0 16px 40px rgba(0,0,0,0.3); }
        .stat-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .si-blue   { background: rgba(0,180,216,0.12);   color: var(--aqua); }
        .si-cyan   { background: rgba(96,165,250,0.12);  color: #60a5fa; }
        .si-green  { background: rgba(74,222,128,0.1);   color: var(--green); }
        .si-gold   { background: rgba(244,200,66,0.1);   color: var(--gold); }
        .stat-num  { font-family: 'Cormorant Garamond', serif; font-size: 1.8rem; font-weight: 600; color: var(--white); line-height: 1; }
        .stat-lbl  { font-size: 0.72rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-top: 3px; }

        /* ── FILTER BAR ── */
        .filter-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
        .filter-pill { padding: 7px 16px; border-radius: 50px; border: 1px solid var(--glass-border); background: transparent; color: rgba(202,240,248,0.45); font-family: 'DM Sans', sans-serif; font-size: 0.78rem; font-weight: 500; cursor: pointer; transition: all 0.25s; }
        .filter-pill:hover { color: var(--foam); border-color: rgba(0,180,216,0.3); }
        .filter-pill.active { background: linear-gradient(135deg, var(--teal), var(--aqua)); border-color: transparent; color: var(--deep); font-weight: 700; box-shadow: 0 4px 14px rgba(0,180,216,0.25); }

        /* ── DELIVERY CARDS ── */
        .del-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.58), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 18px; overflow: hidden;
            transition: all 0.35s cubic-bezier(0.23,1,0.32,1);
            animation: cardIn 0.4s ease both;
        }

        .del-card:hover { transform: translateY(-6px); border-color: rgba(0,180,216,0.28); box-shadow: 0 22px 50px rgba(0,0,0,0.35); }

        .del-card:nth-child(1){animation-delay:.05s} .del-card:nth-child(2){animation-delay:.1s} .del-card:nth-child(3){animation-delay:.15s} .del-card:nth-child(n+4){animation-delay:.2s}

        @keyframes cardIn { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

        /* stripe by status */
        .del-card.s-Out-for-Delivery { border-top: 3px solid #60a5fa; }
        .del-card.s-Delivered        { border-top: 3px solid var(--green); }
        .del-card.s-Pending          { border-top: 3px solid var(--gold); }
        .del-card.s-Processing       { border-top: 3px solid var(--aqua); }

        .del-head { display: flex; justify-content: space-between; align-items: flex-start; padding: 20px 22px 0; }

        .del-order-id { font-family: 'Cormorant Garamond', serif; font-size: 1.35rem; font-weight: 500; color: var(--white); }
        .del-order-date { font-size: 0.75rem; color: rgba(202,240,248,0.38); margin-top: 2px; }

        /* status pills */
        .s-pill { padding: 5px 14px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
        .sp-Pending         { background:rgba(244,200,66,0.12);  color:#f4c842; border:1px solid rgba(244,200,66,0.25); }
        .sp-Processing      { background:rgba(0,180,216,0.1);    color:var(--aqua); border:1px solid rgba(0,180,216,0.25); }
        .sp-Out-for-Delivery{ background:rgba(96,165,250,0.12);  color:#60a5fa; border:1px solid rgba(96,165,250,0.3); }
        .sp-Delivered       { background:rgba(74,222,128,0.1);   color:var(--green); border:1px solid rgba(74,222,128,0.25); }

        /* product strip */
        .del-product-strip { display: flex; align-items: center; gap: 12px; margin: 14px 22px; padding: 12px 14px; background: rgba(4,30,53,0.5); border: 1px solid rgba(72,202,228,0.08); border-radius: 12px; }
        .del-product-img { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; border: 1px solid var(--glass-border); flex-shrink: 0; }
        .del-product-name { font-size: 0.88rem; color: var(--foam); font-weight: 500; line-height: 1.4; }
        .del-product-price { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; color: var(--aqua); margin-top: 2px; }

        /* info rows */
        .del-info { padding: 0 22px; }
        .del-info-row { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(72,202,228,0.06); font-size: 0.84rem; color: rgba(202,240,248,0.55); }
        .del-info-row:last-child { border-bottom: none; }
        .del-info-icon { width: 18px; text-align: center; color: rgba(0,180,216,0.4); font-size: 0.8rem; margin-top: 2px; flex-shrink: 0; }

        /* card footer */
        .del-footer { padding: 14px 22px 18px; }

        .btn-mark-delivered {
            width: 100%; padding: 12px;
            background: linear-gradient(135deg, #059669, #22c55e);
            border: none; border-radius: 50px;
            color: white; font-family: 'DM Sans', sans-serif;
            font-size: 0.83rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase;
            cursor: pointer; transition: all 0.3s;
            box-shadow: 0 5px 18px rgba(34,197,94,0.3);
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }

        .btn-mark-delivered:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(34,197,94,0.5); }

        .completed-notice { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: rgba(74,222,128,0.07); border: 1px solid rgba(74,222,128,0.18); border-radius: 10px; font-size: 0.82rem; color: rgba(74,222,128,0.75); }
        .waiting-notice   { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: rgba(244,200,66,0.07); border: 1px solid rgba(244,200,66,0.15); border-radius: 10px; font-size: 0.82rem; color: rgba(244,200,66,0.65); }

        /* ── EMPTY STATE ── */
        .empty-state { text-align: center; padding: 72px 20px; background: linear-gradient(145deg,rgba(10,45,74,0.4),rgba(3,15,30,0.6)); border: 1px solid var(--glass-border); border-radius: 18px; }
        .empty-ring { width: 90px; height: 90px; border-radius: 50%; background: rgba(0,180,216,0.07); border: 1px solid rgba(0,180,216,0.12); display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 2rem; color: rgba(0,180,216,0.25); }
        .empty-state h5 { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; font-weight: 400; color: var(--white); margin-bottom: 8px; }
        .empty-state p { font-size: 0.86rem; color: rgba(202,240,248,0.35); margin-bottom: 24px; }

        .btn-back { background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; color: var(--deep); padding: 11px 28px; border-radius: 50px; font-weight: 700; font-size: 0.82rem; cursor: pointer; transition: all 0.3s; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-back:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,180,216,0.5); color: var(--deep); }

        /* no results */
        #noResults { display: none; text-align: center; padding: 40px; color: rgba(202,240,248,0.3); font-size: 0.86rem; }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 40px; height: 40px; border-radius: 10px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.9rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px; }
            .mobile-toggle { display: flex; }
        }

        @media (max-width: 576px) { .main-content { padding: 16px 14px; } }
    </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../images/logo.jpg" alt="Logo">
        <div>
            <div class="sidebar-logo-text">De Chavez Waterhaus</div>
            <div class="sidebar-logo-sub">Employee Portal</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="employee_dashboard.php" class="nav-link"><i class="fas fa-house"></i> Dashboard</a>
        <a href="attendance.php"         class="nav-link"><i class="fas fa-clock"></i> Attendance</a>
        <a href="payslip.php"            class="nav-link"><i class="fas fa-file-invoice-dollar"></i> My Payslip</a>
        <a href="leave_request.php"      class="nav-link"><i class="fas fa-calendar-alt"></i> Leave Requests</a>
        <a href="my_deliveries.php"      class="nav-link active"><i class="fas fa-truck"></i> My Deliveries</a>
        <a href="profile.php"            class="nav-link"><i class="fas fa-user"></i> My Profile</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-link danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
            <div class="topbar-left">
                <h4>My Deliveries</h4>
                <p>Track and update your assigned deliveries</p>
            </div>
        </div>

        <div class="topbar-right">
            <div class="dropdown">
                <button class="topbar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-bell"></i>
                    <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo min($notifCount,9).($notifCount>9?'+':'');?></span><?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="min-width:280px;max-height:340px;overflow-y:auto;">
                    <li style="padding:12px 16px 8px;font-size:0.7rem;letter-spacing:0.15em;text-transform:uppercase;color:rgba(202,240,248,0.3);">Notifications</li>
                    <?php
                    $notifs = $conn->query("SELECT * FROM notifications WHERE userID = $userID ORDER BY created_at DESC LIMIT 5");
                    if($notifs->num_rows > 0):
                        while($n = $notifs->fetch_assoc()):
                    ?>
                        <li><a class="dropdown-item" href="notifications.php" style="font-size:0.83rem;white-space:normal;"><?php echo htmlspecialchars(mb_strimwidth($n['message'],0,70,'…'));?></a></li>
                    <?php endwhile; else: ?>
                        <li><span class="dropdown-item" style="color:rgba(202,240,248,0.35);font-size:0.83rem;">No notifications</span></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="notifications.php" style="text-align:center;font-size:0.8rem;color:var(--aqua);">View All</a></li>
                </ul>
            </div>

            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if(!empty($employee['profile_picture'])&&file_exists('../'.$employee['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($employee['profile_picture']);?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($userName,0,1));?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($userName);?></div>
                        <div class="avatar-role">Employee</div>
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
            <div class="page-header-icon"><i class="fas fa-truck"></i></div>
            <div>
                <div class="page-header-title">Assigned Deliveries</div>
                <div class="page-header-sub"><?php echo $totalDeliveries;?> total · <?php echo $outForDelivery;?> out for delivery · <?php echo $completed;?> completed</div>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="fas fa-layer-group"></i></div>
                <div><div class="stat-num"><?php echo $totalDeliveries;?></div><div class="stat-lbl">Total</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-cyan"><i class="fas fa-truck-fast"></i></div>
                <div><div class="stat-num" style="color:#60a5fa;"><?php echo $outForDelivery;?></div><div class="stat-lbl">Out for Delivery</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="fas fa-check-circle"></i></div>
                <div><div class="stat-num" style="color:var(--green);"><?php echo $completed;?></div><div class="stat-lbl">Delivered</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-gold"><i class="fas fa-hourglass-half"></i></div>
                <div><div class="stat-num" style="color:var(--gold);"><?php echo $pending;?></div><div class="stat-lbl">Pending</div></div>
            </div>
        </div>
    </div>

    <?php if(count($deliveries) > 0): ?>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <button class="filter-pill active" onclick="filterDel('all', this)">All</button>
        <button class="filter-pill" onclick="filterDel('Out for Delivery', this)">Out for Delivery</button>
        <button class="filter-pill" onclick="filterDel('Pending', this)">Pending</button>
        <button class="filter-pill" onclick="filterDel('Processing', this)">Processing</button>
        <button class="filter-pill" onclick="filterDel('Delivered', this)">Delivered</button>
    </div>

    <!-- Cards Grid -->
    <div class="row g-3" id="delGrid">
        <?php foreach($deliveries as $delivery):
            $status      = $delivery['status'];
            $statusClass = str_replace(' ', '-', $status);
            $statusPill  = str_replace(' ', '-', $status);

            // Address
            $address = !empty($delivery['delivery_full_address'])
                ? $delivery['delivery_full_address']
                : (!empty($delivery['delivery_address']) ? $delivery['delivery_address'] : 'No address provided');

            // Contact
            $contact = !empty($delivery['delivery_contact'])
                ? $delivery['delivery_contact']
                : (!empty($delivery['customer_contact']) ? $delivery['customer_contact'] : '');

            // Customer name
            $customerName = trim(($delivery['customer_firstname'] ?? '') . ' ' . ($delivery['customer_lastname'] ?? ''));

            // Product image
            $firstImage = '';
            if (!empty($delivery['product_images'])) {
                $imgs = explode('|', $delivery['product_images']);
                $firstImage = $imgs[0] ?? '';
            }

            $imgSrc = (!empty($firstImage) && file_exists('../' . $firstImage))
                ? '../' . $firstImage
                : 'https://images.unsplash.com/photo-1548839140-29a749e1cf4d?w=80&q=60';
        ?>
        <div class="col-lg-6 del-row" data-status="<?php echo htmlspecialchars($status);?>">
            <div class="del-card s-<?php echo $statusClass;?>">

                <!-- Head -->
                <div class="del-head">
                    <div>
                        <div class="del-order-id">Order #<?php echo $delivery['orderID'];?></div>
                        <div class="del-order-date">
                            <i class="fas fa-calendar-alt me-1"></i>
                            <?php echo date('F j, Y · g:i A', strtotime($delivery['order_date']));?>
                        </div>
                    </div>
                    <span class="s-pill sp-<?php echo $statusPill;?>"><?php echo $status;?></span>
                </div>

                <!-- Product strip -->
                <div class="del-product-strip">
                    <img src="<?php echo $imgSrc;?>" class="del-product-img" alt=""
                         onerror="this.src='https://images.unsplash.com/photo-1548839140-29a749e1cf4d?w=80&q=60'">
                    <div>
                        <div class="del-product-name"><?php echo htmlspecialchars($delivery['products'] ?? 'Water Delivery');?></div>
                        <div class="del-product-price">₱<?php echo number_format($delivery['total_amount'],2);?></div>
                    </div>
                </div>

                <!-- Info rows -->
                <div class="del-info">
                    <?php if(!empty($customerName)): ?>
                    <div class="del-info-row">
                        <i class="fas fa-user del-info-icon"></i>
                        <span><?php echo htmlspecialchars($customerName);?></span>
                    </div>
                    <?php endif; ?>

                    <div class="del-info-row">
                        <i class="fas fa-location-dot del-info-icon" style="color:rgba(248,113,113,0.5);"></i>
                        <span><?php echo htmlspecialchars($address);?></span>
                    </div>

                    <?php if(!empty($contact)): ?>
                    <div class="del-info-row">
                        <i class="fas fa-phone del-info-icon"></i>
                        <span><?php echo htmlspecialchars($contact);?></span>
                    </div>
                    <?php endif; ?>

                    <div class="del-info-row">
                        <i class="fas fa-credit-card del-info-icon"></i>
                        <span><?php echo htmlspecialchars($delivery['payment_method'] ?? 'N/A');?></span>
                    </div>
                </div>

                <!-- Footer -->
                <div class="del-footer">
                    <?php if($status === 'Out for Delivery'): ?>
                        <form method="POST">
                            <input type="hidden" name="orderID" value="<?php echo $delivery['orderID'];?>">
                            <button type="submit" name="mark_delivered" class="btn-mark-delivered"
                                    onclick="return confirm('Mark Order #<?php echo $delivery['orderID'];?> as Delivered?')">
                                <i class="fas fa-check-circle"></i> Mark as Delivered
                            </button>
                        </form>

                    <?php elseif($status === 'Delivered'): ?>
                        <div class="completed-notice">
                            <i class="fas fa-check-circle"></i>
                            Delivered · <?php echo date('M j, Y', strtotime($delivery['updated_at'] ?? $delivery['order_date']));?>
                        </div>

                    <?php else: ?>
                        <div class="waiting-notice">
                            <i class="fas fa-clock"></i>
                            Waiting for admin to set "Out for Delivery"
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="noResults">No deliveries match this filter.</div>

    <?php else: ?>
    <div class="empty-state">
        <div class="empty-ring"><i class="fas fa-truck"></i></div>
        <h5>No Deliveries Assigned</h5>
        <p>You don't have any assigned deliveries at the moment.<br>Check back later or contact your supervisor.</p>
        <a href="employee_dashboard.php" class="btn-back">
            <i class="fas fa-house"></i> Back to Dashboard
        </a>
    </div>
    <?php endif; ?>

</main>

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

    // ── FILTER ──
    function filterDel(status, btn) {
        document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const rows  = document.querySelectorAll('.del-row');
        let visible = 0;

        rows.forEach(row => {
            const show = status === 'all' || row.dataset.status === status;
            row.style.display = show ? '' : 'none';
            if(show) visible++;
        });

        const noRes = document.getElementById('noResults');
        if(noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
    }
</script>
</body>
</html>