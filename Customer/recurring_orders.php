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

// Handle new recurring order
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_recurring'])) {
    $productID   = intval($_POST['productID']);
    $quantity    = intval($_POST['quantity']);
    $frequency   = $_POST['frequency'];
    $deliveryDay = $_POST['delivery_day'];
    $addressID   = intval($_POST['addressID']);

    $today = new DateTime();
    $nextDelivery = clone $today;
    $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $targetDay  = array_search($deliveryDay, $days);
    $currentDay = (int)$today->format('w');
    $daysUntil  = ($targetDay - $currentDay + 7) % 7;
    if ($daysUntil == 0) $daysUntil = 7;
    $nextDelivery->modify("+$daysUntil days");

    $insertStmt = $conn->prepare("INSERT INTO recurring_orders (userID, productID, quantity, frequency, delivery_day, next_delivery_date, addressID) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $nextDeliveryDate = $nextDelivery->format('Y-m-d');
    $insertStmt->bind_param("iiisssi", $userID, $productID, $quantity, $frequency, $deliveryDay, $nextDeliveryDate, $addressID);

    if ($insertStmt->execute()) {
        echo '<script>alert("Recurring delivery set up successfully!"); window.location = "recurring_orders.php";</script>';
        exit();
    } else {
        echo '<script>alert("Failed to set up recurring delivery. Please try again.");</script>';
    }
    $insertStmt->close();
}

// Handle pause/resume/cancel
if (isset($_GET['action']) && isset($_GET['id'])) {
    $recurringID = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'pause')  $conn->query("UPDATE recurring_orders SET status = 'Paused' WHERE recurringID = $recurringID AND userID = $userID");
    if ($action == 'resume') $conn->query("UPDATE recurring_orders SET status = 'Active' WHERE recurringID = $recurringID AND userID = $userID");
    if ($action == 'cancel') $conn->query("UPDATE recurring_orders SET status = 'Cancelled' WHERE recurringID = $recurringID AND userID = $userID");
    echo '<script>window.location = "recurring_orders.php";</script>'; exit();
}

// Fetch recurring orders
$recurringOrders = $conn->query("
    SELECT r.*, p.ProductName, p.Price, a.full_address
    FROM recurring_orders r
    JOIN product p ON r.productID = p.ProductID
    LEFT JOIN delivery_addresses a ON r.addressID = a.addressID
    WHERE r.userID = $userID
    ORDER BY r.created_at DESC
");

// Fetch products for form
$products  = $conn->query("SELECT * FROM product WHERE Status = 'Active'");
// Fetch addresses
$addresses = $conn->query("SELECT * FROM delivery_addresses WHERE userID = $userID");

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName  = explode(' ', $userName)[0];

// Collect orders into array so we can count by status
$allRecurring = $recurringOrders->fetch_all(MYSQLI_ASSOC);
$countActive    = count(array_filter($allRecurring, fn($r) => $r['status'] === 'Active'));
$countPaused    = count(array_filter($allRecurring, fn($r) => $r['status'] === 'Paused'));
$countCancelled = count(array_filter($allRecurring, fn($r) => $r['status'] === 'Cancelled'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recurring Orders • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;  --abyss: #030f1e;  --ocean: #041e35;  --navy:  #0a2d4a;
            --teal:  #0077b6;  --aqua:  #00b4d8;  --cyan:  #48cae4;  --glow:  #90e0ef;
            --foam:  #caf0f8;  --white: #f0f9ff;  --gold:  #f4c842;
            --glass: rgba(0,180,216,0.08);  --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body { font-family: 'DM Sans', sans-serif; background: var(--deep); color: var(--white); min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-w); background: var(--abyss); border-right: 1px solid var(--glass-border); z-index: 1000; display: flex; flex-direction: column; transition: transform 0.3s ease; }
        .sidebar-logo { padding: 24px 22px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--glass-border); flex-shrink: 0; }
        .sidebar-logo img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(0,180,216,0.35); box-shadow: 0 0 14px rgba(0,180,216,0.2); }
        .sidebar-logo span { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; font-weight: 500; color: var(--white); line-height: 1.2; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 16px 12px 20px; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.15) transparent; }
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
        .notif-dot { margin-left: auto; background: var(--gold); color: var(--deep); font-size: 0.62rem; font-weight: 700; padding: 1px 6px; border-radius: 50px; min-width: 18px; text-align: center; }

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; padding: 28px 32px; }

        /* ── TOP BAR ── */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; }
        .topbar-greeting h4 { font-family: 'Cormorant Garamond', serif; font-size: 1.7rem; font-weight: 400; color: var(--white); line-height: 1.1; }
        .topbar-greeting p { font-size: 0.82rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .topbar-actions { display: flex; align-items: center; gap: 12px; }
        .topbar-btn { width: 42px; height: 42px; border-radius: 50%; background: var(--glass); border: 1px solid var(--glass-border); color: rgba(202,240,248,0.6); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; text-decoration: none; transition: all 0.3s; position: relative; }
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

        /* ── BTN SETUP ── */
        .btn-setup { background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; color: var(--deep); padding: 10px 24px; border-radius: 50px; font-weight: 700; font-size: 0.82rem; letter-spacing: 0.08em; text-transform: uppercase; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(0,180,216,0.25); display: inline-flex; align-items: center; gap: 8px; }
        .btn-setup:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); color: var(--deep); }

        /* ── PAGE HEADER ── */
        .page-header { background: linear-gradient(135deg, rgba(0,119,182,0.2), rgba(0,180,216,0.08)); border: 1px solid rgba(0,180,216,0.2); border-radius: 18px; padding: 24px 28px; margin-bottom: 28px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
        .page-header-icon { width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; box-shadow: 0 6px 20px rgba(0,180,216,0.3); }
        .page-header-title { font-family: 'Cormorant Garamond', serif; font-size: 1.6rem; font-weight: 400; color: var(--white); }
        .page-header-sub { font-size: 0.82rem; color: rgba(202,240,248,0.4); margin-top: 3px; }

        /* ── STAT PILLS ── */
        .stat-pills { display: flex; gap: 10px; flex-wrap: wrap; }
        .stat-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 50px; font-size: 0.78rem; font-weight: 600; border: 1px solid; cursor: pointer; transition: all 0.25s; }
        .stat-pill.all    { background: var(--glass);               border-color: var(--glass-border);              color: rgba(202,240,248,0.55); }
        .stat-pill.active { background: rgba(74,222,128,0.1);       border-color: rgba(74,222,128,0.25);            color: #4ade80; }
        .stat-pill.paused { background: rgba(244,200,66,0.1);       border-color: rgba(244,200,66,0.25);            color: var(--gold); }
        .stat-pill.cancel { background: rgba(248,113,113,0.08);     border-color: rgba(248,113,113,0.2);            color: #fca5a5; }
        .stat-pill.selected, .stat-pill:hover { filter: brightness(1.2); box-shadow: 0 0 10px rgba(0,180,216,0.1); }

        /* ── RECURRING CARDS ── */
        .rec-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.55), rgba(3,15,30,0.78));
            border: 1px solid var(--glass-border);
            border-radius: 18px; overflow: hidden;
            transition: all 0.35s cubic-bezier(0.23,1,0.32,1);
            animation: cardIn 0.4s ease both;
        }

        .rec-card:hover { transform: translateY(-5px); border-color: rgba(0,180,216,0.28); box-shadow: 0 22px 50px rgba(0,0,0,0.35); }

        .rec-card:nth-child(1) { animation-delay:0.05s; }
        .rec-card:nth-child(2) { animation-delay:0.10s; }
        .rec-card:nth-child(3) { animation-delay:0.15s; }
        .rec-card:nth-child(n+4) { animation-delay:0.20s; }

        @keyframes cardIn { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

        /* status top stripe */
        .rec-card.stripe-active    { border-left: 3px solid #4ade80; }
        .rec-card.stripe-paused    { border-left: 3px solid #f4c842; }
        .rec-card.stripe-cancelled { border-left: 3px solid #f87171; }

        .rec-card-head { display: flex; justify-content: space-between; align-items: flex-start; padding: 20px 22px 0; }

        .rec-product-name { font-family: 'Cormorant Garamond', serif; font-size: 1.4rem; font-weight: 500; color: var(--white); }
        .rec-product-price { font-size: 0.78rem; color: rgba(202,240,248,0.38); margin-top: 2px; }

        /* status pills */
        .status-pill { padding: 5px 14px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
        .pill-Active    { background: rgba(74,222,128,0.1);  color: #4ade80;  border: 1px solid rgba(74,222,128,0.25); }
        .pill-Paused    { background: rgba(244,200,66,0.12); color: #f4c842;  border: 1px solid rgba(244,200,66,0.25); }
        .pill-Cancelled { background: rgba(248,113,113,0.1); color: #fca5a5;  border: 1px solid rgba(248,113,113,0.25); }

        /* info strip */
        .rec-info-strip { display: flex; gap: 0; border-top: 1px solid rgba(72,202,228,0.08); border-bottom: 1px solid rgba(72,202,228,0.08); margin: 16px 0 0; }
        .rec-info-cell { flex: 1; padding: 12px 16px; border-right: 1px solid rgba(72,202,228,0.08); }
        .rec-info-cell:last-child { border-right: none; }
        .rec-info-label { font-size: 0.67rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.3); margin-bottom: 4px; }
        .rec-info-value { font-size: 0.88rem; color: var(--foam); font-weight: 500; }
        .rec-info-value.highlight { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; color: var(--aqua); }

        /* next delivery chip */
        .next-delivery-chip { display: inline-flex; align-items: center; gap: 7px; background: rgba(0,180,216,0.07); border: 1px solid rgba(0,180,216,0.18); border-radius: 50px; padding: 6px 14px; font-size: 0.78rem; color: rgba(202,240,248,0.55); margin: 12px 22px 0; }
        .next-delivery-chip i { color: var(--aqua); }

        /* address strip */
        .rec-address { display: flex; align-items: flex-start; gap: 8px; padding: 10px 22px; font-size: 0.8rem; color: rgba(202,240,248,0.38); }
        .rec-address i { color: rgba(0,180,216,0.45); margin-top: 2px; flex-shrink: 0; }

        /* card footer */
        .rec-card-footer { display: flex; justify-content: flex-end; align-items: center; padding: 12px 22px 18px; gap: 8px; flex-wrap: wrap; }

        /* action buttons */
        .btn-rec { display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; border-radius: 50px; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.06em; cursor: pointer; transition: all 0.3s; text-decoration: none; border: none; }
        .btn-pause  { background: rgba(244,200,66,0.1);  border: 1px solid rgba(244,200,66,0.25); color: #f4c842; }
        .btn-pause:hover  { background: rgba(244,200,66,0.2); color: var(--gold); }
        .btn-resume { background: rgba(74,222,128,0.1);  border: 1px solid rgba(74,222,128,0.25); color: #4ade80; }
        .btn-resume:hover { background: rgba(74,222,128,0.2); color: #4ade80; }
        .btn-cancel { background: rgba(248,113,113,0.08); border: 1px solid rgba(248,113,113,0.22); color: #fca5a5; }
        .btn-cancel:hover { background: rgba(248,113,113,0.18); }

        /* ── EMPTY STATE ── */
        .empty-state { text-align: center; padding: 72px 20px; background: linear-gradient(145deg, rgba(10,45,74,0.4), rgba(3,15,30,0.6)); border: 1px solid var(--glass-border); border-radius: 18px; }
        .empty-ring { width: 90px; height: 90px; border-radius: 50%; background: rgba(0,180,216,0.07); border: 1px solid rgba(0,180,216,0.12); display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 2rem; color: rgba(0,180,216,0.25); }
        .empty-state h5 { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; font-weight: 400; color: var(--white); margin-bottom: 8px; }
        .empty-state p { font-size: 0.86rem; color: rgba(202,240,248,0.35); margin-bottom: 24px; }

        /* ── HOW IT WORKS ── */
        .how-card { background: linear-gradient(145deg, rgba(10,45,74,0.5), rgba(3,15,30,0.7)); border: 1px solid var(--glass-border); border-radius: 18px; padding: 28px; }
        .how-step { display: flex; align-items: flex-start; gap: 16px; padding: 14px 0; border-bottom: 1px solid rgba(72,202,228,0.07); }
        .how-step:last-child { border-bottom: none; }
        .how-step-num { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.9rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .how-step-title { font-size: 0.9rem; font-weight: 600; color: var(--white); margin-bottom: 3px; }
        .how-step-desc { font-size: 0.8rem; color: rgba(202,240,248,0.4); line-height: 1.5; }

        /* ── MODAL ── */
        .modal-content { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 20px !important; }
        .modal-header { border-bottom: 1px solid var(--glass-border) !important; padding: 22px 26px !important; }
        .modal-footer { border-top: 1px solid var(--glass-border) !important; padding: 18px 26px !important; }
        .modal-body { padding: 26px !important; }
        .modal-title { font-family: 'Cormorant Garamond', serif !important; font-size: 1.4rem !important; font-weight: 500 !important; color: var(--white) !important; }
        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

        .field-group { margin-bottom: 18px; }
        .field-label { display: block; font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 8px; }
        .field-input, .field-select { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 12px 15px; border-radius: 12px; outline: none; transition: all 0.3s; }
        .field-input::placeholder { color: rgba(202,240,248,0.2); }
        .field-input:focus, .field-select:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }
        .field-select option { background: var(--ocean); }
        .field-hint { font-size: 0.72rem; color: rgba(202,240,248,0.3); margin-top: 5px; }

        .btn-glass { display: inline-flex; align-items: center; gap: 6px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 10px 20px; border-radius: 50px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-glass:hover { background: rgba(0,180,216,0.15); color: var(--foam); }

        .btn-submit-modal { width: 100%; padding: 14px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.87rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; cursor: pointer; transition: all 0.3s; box-shadow: 0 6px 22px rgba(0,180,216,0.3); display: flex; align-items: center; justify-content: center; gap: 10px; }
        .btn-submit-modal:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(0,180,216,0.5); }

        /* no-results */
        #noResults { display: none; }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 40px; height: 40px; border-radius: 10px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.9rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px; }
            .mobile-toggle { display: flex; }
            .rec-info-strip { flex-wrap: wrap; }
            .rec-info-cell { min-width: 50%; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
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
        <a href="order_tracking.php"     class="nav-link"><i class="fas fa-map-marker-alt"></i> Track Orders</a>
        <a href="recurring_orders.php"   class="nav-link active"><i class="fas fa-redo"></i> Recurring Orders</a>
        <div class="nav-section-label">Account</div>
        <a href="support_tickets.php"    class="nav-link"><i class="fas fa-headset"></i> Support</a>
        <a href="notifications.php"      class="nav-link">
            <i class="fas fa-bell"></i> Notifications
            <?php if($notifCount>0): ?><span class="notif-dot"><?php echo $notifCount>9?'9+':$notifCount;?></span><?php endif; ?>
        </a>
        <a href="profile.php"            class="nav-link"><i class="fas fa-user"></i> Profile</a>
        <div class="nav-section-label" style="margin-top:16px;"></div>
        <a href="../logout.php"          class="nav-link danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
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
                <h4>Recurring Orders</h4>
                <p>Set up automatic water deliveries for convenience</p>
            </div>
        </div>

        <div class="topbar-actions">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo $notifCount>9?'9+':$notifCount;?></span><?php endif; ?>
            </a>

            <button class="btn-setup" data-bs-toggle="modal" data-bs-target="#createRecurringModal">
                <i class="fas fa-plus"></i> Set Up
            </button>

            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if(!empty($user['profile_picture'])&&file_exists('../'.$user['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['profile_picture']);?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($userName,0,1));?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($userName);?></div>
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
            <div class="page-header-icon"><i class="fas fa-redo"></i></div>
            <div>
                <div class="page-header-title">Recurring Deliveries</div>
                <div class="page-header-sub">
                    <?php echo count($allRecurring); ?> total ·
                    <span style="color:#4ade80;"><?php echo $countActive;?> active</span> ·
                    <span style="color:#f4c842;"><?php echo $countPaused;?> paused</span>
                </div>
            </div>
        </div>
        <!-- Stat filter pills -->
        <div class="stat-pills">
            <button class="stat-pill all selected" onclick="filterByStatus('all', this)">All</button>
            <?php if($countActive>0): ?>
                <button class="stat-pill active" onclick="filterByStatus('Active', this)">
                    <i class="fas fa-circle" style="font-size:0.5rem;"></i> Active (<?php echo $countActive;?>)
                </button>
            <?php endif; ?>
            <?php if($countPaused>0): ?>
                <button class="stat-pill paused" onclick="filterByStatus('Paused', this)">
                    <i class="fas fa-pause" style="font-size:0.6rem;"></i> Paused (<?php echo $countPaused;?>)
                </button>
            <?php endif; ?>
            <?php if($countCancelled>0): ?>
                <button class="stat-pill cancel" onclick="filterByStatus('Cancelled', this)">
                    <i class="fas fa-xmark" style="font-size:0.65rem;"></i> Cancelled (<?php echo $countCancelled;?>)
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if(count($allRecurring) > 0): ?>

    <div class="row g-4">

        <!-- Recurring Cards -->
        <div class="col-lg-8">
            <div class="row g-3" id="recList">
                <?php foreach($allRecurring as $rec):
                    $statusClass = $rec['status'];
                    $stripeClass = 'stripe-' . strtolower($rec['status']);
                    $totalPerDelivery = $rec['Price'] * $rec['quantity'];
                ?>
                <div class="col-12 rec-row" data-status="<?php echo $rec['status'];?>">
                    <div class="rec-card <?php echo $stripeClass;?>">

                        <!-- Head -->
                        <div class="rec-card-head">
                            <div>
                                <div class="rec-product-name"><?php echo htmlspecialchars($rec['ProductName']);?></div>
                                <div class="rec-product-price">₱<?php echo number_format($rec['Price'],2);?> per unit</div>
                            </div>
                            <span class="status-pill pill-<?php echo $statusClass;?>"><?php echo $statusClass;?></span>
                        </div>

                        <!-- Info strip -->
                        <div class="rec-info-strip">
                            <div class="rec-info-cell">
                                <div class="rec-info-label">Quantity</div>
                                <div class="rec-info-value"><?php echo $rec['quantity'];?> units</div>
                            </div>
                            <div class="rec-info-cell">
                                <div class="rec-info-label">Frequency</div>
                                <div class="rec-info-value"><?php echo htmlspecialchars($rec['frequency']);?></div>
                            </div>
                            <div class="rec-info-cell">
                                <div class="rec-info-label">Day</div>
                                <div class="rec-info-value"><?php echo htmlspecialchars($rec['delivery_day']);?></div>
                            </div>
                            <div class="rec-info-cell">
                                <div class="rec-info-label">Per Delivery</div>
                                <div class="rec-info-value highlight">₱<?php echo number_format($totalPerDelivery,2);?></div>
                            </div>
                        </div>

                        <!-- Next delivery chip -->
                        <?php if($rec['status'] === 'Active'): ?>
                        <div class="next-delivery-chip">
                            <i class="fas fa-calendar-check"></i>
                            Next delivery: <strong style="color:var(--aqua);"><?php echo date('F j, Y', strtotime($rec['next_delivery_date']));?></strong>
                        </div>
                        <?php elseif($rec['status'] === 'Paused'): ?>
                        <div class="next-delivery-chip" style="background:rgba(244,200,66,0.07);border-color:rgba(244,200,66,0.2);color:rgba(244,200,66,0.7);">
                            <i class="fas fa-pause-circle" style="color:#f4c842;"></i>
                            Delivery paused · Resume to continue
                        </div>
                        <?php else: ?>
                        <div class="next-delivery-chip" style="background:rgba(248,113,113,0.06);border-color:rgba(248,113,113,0.18);color:rgba(252,165,165,0.6);">
                            <i class="fas fa-xmark-circle" style="color:#f87171;"></i>
                            This recurring order has been cancelled
                        </div>
                        <?php endif; ?>

                        <!-- Address -->
                        <?php if(!empty($rec['full_address'])): ?>
                        <div class="rec-address">
                            <i class="fas fa-location-dot"></i>
                            <span><?php echo htmlspecialchars($rec['full_address']);?></span>
                        </div>
                        <?php endif; ?>

                        <!-- Footer Actions -->
                        <div class="rec-card-footer">
                            <?php if($rec['status'] === 'Active'): ?>
                                <a href="recurring_orders.php?action=pause&id=<?php echo $rec['recurringID'];?>" class="btn-rec btn-pause">
                                    <i class="fas fa-pause"></i> Pause
                                </a>
                                <a href="recurring_orders.php?action=cancel&id=<?php echo $rec['recurringID'];?>"
                                   class="btn-rec btn-cancel"
                                   onclick="return confirm('Cancel this recurring delivery?')">
                                    <i class="fas fa-xmark"></i> Cancel
                                </a>
                            <?php elseif($rec['status'] === 'Paused'): ?>
                                <a href="recurring_orders.php?action=resume&id=<?php echo $rec['recurringID'];?>" class="btn-rec btn-resume">
                                    <i class="fas fa-play"></i> Resume
                                </a>
                                <a href="recurring_orders.php?action=cancel&id=<?php echo $rec['recurringID'];?>"
                                   class="btn-rec btn-cancel"
                                   onclick="return confirm('Cancel this recurring delivery?')">
                                    <i class="fas fa-xmark"></i> Cancel
                                </a>
                            <?php else: ?>
                                <span style="font-size:0.78rem;color:rgba(252,165,165,0.45);">
                                    <i class="fas fa-ban me-1"></i> Cancelled
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- No results after filter -->
            <div id="noResults" class="empty-state mt-3">
                <div class="empty-ring"><i class="fas fa-filter"></i></div>
                <h5>No orders found</h5>
                <p>No recurring orders match this filter.</p>
            </div>
        </div>

        <!-- How It Works sidebar -->
        <div class="col-lg-4">
            <div class="how-card">
                <div style="font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:500;color:var(--white);margin-bottom:16px;">
                    <i class="fas fa-circle-info me-2" style="color:var(--aqua);font-size:0.9rem;"></i>How It Works
                </div>

                <div class="how-step">
                    <div class="how-step-num">1</div>
                    <div>
                        <div class="how-step-title">Choose Your Product</div>
                        <div class="how-step-desc">Select the water type and quantity you need regularly.</div>
                    </div>
                </div>
                <div class="how-step">
                    <div class="how-step-num">2</div>
                    <div>
                        <div class="how-step-title">Set Your Schedule</div>
                        <div class="how-step-desc">Pick your preferred day and frequency — weekly, bi-weekly, or monthly.</div>
                    </div>
                </div>
                <div class="how-step">
                    <div class="how-step-num">3</div>
                    <div>
                        <div class="how-step-title">Automatic Delivery</div>
                        <div class="how-step-desc">We handle the rest. Water arrives on your schedule without you lifting a finger.</div>
                    </div>
                </div>
                <div class="how-step">
                    <div class="how-step-num">4</div>
                    <div>
                        <div class="how-step-title">Pause or Cancel Anytime</div>
                        <div class="how-step-desc">Life changes — pause or cancel your recurring order with one click.</div>
                    </div>
                </div>

                <div style="margin-top:20px;padding:14px 16px;background:rgba(0,180,216,0.07);border:1px solid rgba(0,180,216,0.15);border-radius:12px;font-size:0.8rem;color:rgba(202,240,248,0.5);">
                    <i class="fas fa-lightbulb me-2" style="color:var(--gold);"></i>
                    <strong style="color:var(--foam);">Tip:</strong> Setting up a recurring order saves you from manually reordering every week.
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="empty-state">
                <div class="empty-ring"><i class="fas fa-redo"></i></div>
                <h5>No Recurring Orders Yet</h5>
                <p>Set up an automatic delivery schedule and never run out of water again.</p>
                <button class="btn-setup" data-bs-toggle="modal" data-bs-target="#createRecurringModal">
                    <i class="fas fa-plus"></i> Set Up First Recurring Order
                </button>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="how-card">
                <div style="font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:500;color:var(--white);margin-bottom:16px;">
                    <i class="fas fa-circle-info me-2" style="color:var(--aqua);font-size:0.9rem;"></i>How It Works
                </div>
                <div class="how-step">
                    <div class="how-step-num">1</div>
                    <div><div class="how-step-title">Choose Your Product</div><div class="how-step-desc">Select the water type and quantity you need regularly.</div></div>
                </div>
                <div class="how-step">
                    <div class="how-step-num">2</div>
                    <div><div class="how-step-title">Set Your Schedule</div><div class="how-step-desc">Pick your preferred day and frequency.</div></div>
                </div>
                <div class="how-step">
                    <div class="how-step-num">3</div>
                    <div><div class="how-step-title">Automatic Delivery</div><div class="how-step-desc">We handle the rest on your schedule.</div></div>
                </div>
                <div class="how-step">
                    <div class="how-step-num">4</div>
                    <div><div class="how-step-title">Pause or Cancel Anytime</div><div class="how-step-desc">Full control — pause or cancel with one click.</div></div>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>

</main>

<!-- ── CREATE RECURRING MODAL ── -->
<div class="modal fade" id="createRecurringModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-redo me-2" style="color:var(--aqua);"></i>Set Up Recurring Delivery
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="field-group">
                        <label class="field-label">Product</label>
                        <select class="field-select" name="productID" required>
                            <option value="">Select a product…</option>
                            <?php
                            $products->data_seek(0);
                            while ($prod = $products->fetch_assoc()):
                            ?>
                                <option value="<?php echo $prod['ProductID'];?>">
                                    <?php echo htmlspecialchars($prod['ProductName']);?> — ₱<?php echo number_format($prod['Price'],2);?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Quantity (units)</label>
                                <input type="number" class="field-input" name="quantity" min="6" max="100" value="6" required id="modalQty">
                                <div class="field-hint">Min 6 · Max 100</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Frequency</label>
                                <select class="field-select" name="frequency" required>
                                    <option value="Weekly">Weekly</option>
                                    <option value="Bi-Weekly">Every 2 Weeks</option>
                                    <option value="Monthly">Monthly</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Preferred Delivery Day</label>
                        <select class="field-select" name="delivery_day" required>
                            <?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
                                <option value="<?php echo $day;?>"><?php echo $day;?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-group mb-0">
                        <label class="field-label">Delivery Address</label>
                        <?php
                        $addresses->data_seek(0);
                        $addrCount = $addresses->num_rows;
                        ?>
                        <?php if($addrCount > 0): ?>
                            <select class="field-select" name="addressID" required>
                                <?php while($addr = $addresses->fetch_assoc()): ?>
                                    <option value="<?php echo $addr['addressID'];?>">
                                        <?php echo htmlspecialchars($addr['label']);?> — <?php echo htmlspecialchars(mb_strimwidth($addr['full_address'],0,50,'…'));?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        <?php else: ?>
                            <div style="background:rgba(244,200,66,0.07);border:1px solid rgba(244,200,66,0.2);border-radius:12px;padding:12px 16px;font-size:0.84rem;color:rgba(244,200,66,0.75);">
                                <i class="fas fa-triangle-exclamation me-2"></i>
                                No saved addresses. <a href="profile.php" style="color:var(--gold);font-weight:600;">Add one in Profile</a> first.
                            </div>
                            <input type="hidden" name="addressID" value="0">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_recurring" class="btn-submit-modal" style="width:auto;padding:11px 28px;">
                        <i class="fas fa-check-circle"></i> Confirm Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── SIDEBAR ──
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle  = document.getElementById('mobileToggle');
    function openSidebar()  { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }
    if (toggle)  toggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);
    sidebar.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => { if(window.innerWidth<992) closeSidebar(); }));

    // ── STATUS FILTER ──
    function filterByStatus(status, btn) {
        document.querySelectorAll('.stat-pill').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');

        const rows   = document.querySelectorAll('.rec-row');
        let visible  = 0;

        rows.forEach(row => {
            const show = status === 'all' || row.dataset.status === status;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        const noRes = document.getElementById('noResults');
        if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
    }
</script>
</body>
</html>