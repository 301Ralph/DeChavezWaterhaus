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

// Check if email is verified
$verifyCheck = $conn->prepare("SELECT email_verified FROM customers WHERE userID = ?");
$verifyCheck->bind_param("i", $userID);
$verifyCheck->execute();
$verifyResult = $verifyCheck->get_result()->fetch_assoc();
$verifyCheck->close();
$isEmailVerified = $verifyResult['email_verified'] ?? 0;

// Fetch products
$productsResult = $conn->query("
    SELECT *, COALESCE(Stock, 0) as current_stock, COALESCE(ImageURL, '') as product_image
    FROM product WHERE Status = 'Active' ORDER BY ProductName ASC
");

// Handle order submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order'])) {
    if (!$isEmailVerified) {
        echo '<script>alert("Please verify your email address first before placing an order."); window.location = "profile.php";</script>';
        exit();
    }

    $productID      = intval($_POST['productID']);
    $quantity       = intval($_POST['quantity']);
    $deliveryOption = $_POST['delivery_option'] ?? 'today';
    $deliveryDate   = $_POST['delivery_date'] ?? date('Y-m-d');
    $paymentMethod  = $_POST['payment_method'];
    $gcashReceipt   = '';

    if ($quantity < 6 || $quantity > 100) {
        echo '<script>alert("Quantity must be between 6 and 100."); window.location = "products.php";</script>';
        exit();
    }

    $stockCheck = $conn->prepare("SELECT COALESCE(Stock, 0) as current_stock, ProductName, Price FROM product WHERE ProductID = ?");
    $stockCheck->bind_param("i", $productID);
    $stockCheck->execute();
    $productInfo = $stockCheck->get_result()->fetch_assoc();
    $stockCheck->close();

    if ($productInfo['current_stock'] < $quantity) {
        echo '<script>alert("Sorry, only ' . $productInfo['current_stock'] . ' units available for ' . $productInfo['ProductName'] . '. Please reduce your quantity."); window.location = "products.php";</script>';
        exit();
    }

    if ($paymentMethod == 'GCash') {
        if (isset($_FILES['gcash_receipt']) && $_FILES['gcash_receipt']['error'] == 0) {
            $target_dir = "../uploads/receipts/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

            $fileName   = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES["gcash_receipt"]["name"]));
            $target_file = $target_dir . $fileName;
            $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

            if (in_array($imageFileType, ['jpg', 'jpeg', 'png', 'gif'])) {
                if (move_uploaded_file($_FILES["gcash_receipt"]["tmp_name"], $target_file)) {
                    $gcashReceipt = 'uploads/receipts/' . $fileName;
                } else {
                    echo '<script>alert("Failed to upload GCash receipt."); window.location = "products.php";</script>';
                    exit();
                }
            } else {
                echo '<script>alert("Invalid file type. Only JPG, PNG, GIF allowed."); window.location = "products.php";</script>';
                exit();
            }
        } else {
            echo '<script>alert("Please upload your GCash receipt."); window.location = "products.php";</script>';
            exit();
        }
    }

    $totalAmount  = $productInfo['Price'] * $quantity;
    if ($deliveryOption == 'today') $deliveryDate = date('Y-m-d');

    $orderNotes      = ($paymentMethod == 'GCash' && !empty($gcashReceipt)) ? "GCash Receipt: " . $gcashReceipt : "";
    $deliveryAddress = "Customer Address";

    $insertOrder = $conn->prepare("INSERT INTO orders (userID, order_date, total_amount, status, payment_method, notes, delivery_address) VALUES (?, NOW(), ?, 'Pending', ?, ?, ?)");
    $insertOrder->bind_param("idsss", $userID, $totalAmount, $paymentMethod, $orderNotes, $deliveryAddress);

    if ($insertOrder->execute()) {
        $orderID = $conn->insert_id;
        $insertOrder->close();

        $insertItem = $conn->prepare("INSERT INTO order_items (orderID, productID, quantity, unit_price) VALUES (?, ?, ?, ?)");
        $insertItem->bind_param("iiid", $orderID, $productID, $quantity, $productInfo['Price']);
        $insertItem->execute(); $insertItem->close();

        $updateStock = $conn->prepare("UPDATE product SET Stock = COALESCE(Stock, 0) - ? WHERE ProductID = ?");
        $updateStock->bind_param("ii", $quantity, $productID);
        $updateStock->execute(); $updateStock->close();

        $insertDelivery = $conn->prepare("INSERT INTO deliveries (orderID, delivery_date, status) VALUES (?, ?, 'Pending')");
        $insertDelivery->bind_param("is", $orderID, $deliveryDate);
        $insertDelivery->execute(); $insertDelivery->close();

        $gcashInfo = ($paymentMethod == 'GCash') ? " (GCash payment - receipt uploaded)" : "";
        $message   = "Your order #$orderID for " . $productInfo['ProductName'] . " (x$quantity) has been placed successfully!$gcashInfo Total: ₱" . number_format($totalAmount, 2);
        $notifStmt = $conn->prepare("INSERT INTO notifications (userID, message, type) VALUES (?, ?, 'order')");
        $notifStmt->bind_param("is", $userID, $message);
        $notifStmt->execute(); $notifStmt->close();

        echo '<script>alert("Order placed successfully! Order ID: #' . $orderID . '"); window.location = "order_history.php";</script>';
        exit();
    } else {
        echo '<script>alert("Failed to place order. Please try again."); window.location = "products.php";</script>';
        exit();
    }
}

// Fetch user data
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName  = explode(' ', $userName)[0];
$totalProducts = $productsResult->num_rows;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products • De Chavez Waterhaus</title>
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
            z-index: 1000; display: flex; flex-direction: column;
            transition: transform 0.3s ease;
        }

        .sidebar-logo {
            padding: 24px 22px; display: flex; align-items: center; gap: 12px;
            border-bottom: 1px solid var(--glass-border); flex-shrink: 0;
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

        /* ── SEARCH / FILTER BAR ── */
        .filter-bar {
            display: flex; gap: 10px; align-items: center;
            margin-bottom: 24px; flex-wrap: wrap;
        }

        .search-wrap { position: relative; flex: 1; min-width: 200px; max-width: 320px; }

        .search-input {
            width: 100%;
            background: var(--glass); border: 1px solid var(--glass-border);
            color: var(--white); border-radius: 50px;
            padding: 10px 16px 10px 40px;
            font-family: 'DM Sans', sans-serif; font-size: 0.85rem;
            outline: none; transition: all 0.3s;
        }

        .search-input::placeholder { color: rgba(202,240,248,0.25); }

        .search-input:focus {
            border-color: rgba(0,180,216,0.4);
            background: rgba(0,180,216,0.08);
            box-shadow: 0 0 0 3px rgba(0,180,216,0.08);
        }

        .search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: rgba(0,180,216,0.4); font-size: 0.8rem; }

        .filter-pill {
            padding: 8px 16px; border-radius: 50px;
            border: 1px solid var(--glass-border); background: transparent;
            color: rgba(202,240,248,0.45); font-family: 'DM Sans', sans-serif;
            font-size: 0.78rem; font-weight: 500; cursor: pointer; transition: all 0.25s;
        }

        .filter-pill:hover { color: var(--foam); border-color: rgba(0,180,216,0.3); }

        .filter-pill.active {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border-color: transparent; color: var(--deep); font-weight: 700;
            box-shadow: 0 4px 14px rgba(0,180,216,0.25);
        }

        /* ── PRODUCT CARDS ── */
        .product-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.82));
            border: 1px solid var(--glass-border);
            border-radius: 20px; overflow: hidden;
            transition: all 0.35s cubic-bezier(0.23,1,0.32,1);
            display: flex; flex-direction: column;
            animation: cardIn 0.4s ease both;
        }

        .product-card:hover {
            transform: translateY(-8px);
            border-color: rgba(0,180,216,0.3);
            box-shadow: 0 24px 52px rgba(0,0,0,0.4), 0 0 30px rgba(0,180,216,0.06);
        }

        .product-card:nth-child(1) { animation-delay:0.05s; }
        .product-card:nth-child(2) { animation-delay:0.10s; }
        .product-card:nth-child(3) { animation-delay:0.15s; }
        .product-card:nth-child(n+4) { animation-delay:0.20s; }

        @keyframes cardIn {
            from { opacity:0; transform:translateY(20px); }
            to   { opacity:1; transform:translateY(0); }
        }

        /* image wrap */
        .product-img-wrap {
            position: relative; overflow: hidden; height: 220px;
        }

        .product-img-wrap img {
            width: 100%; height: 100%; object-fit: cover;
            transition: transform 0.6s cubic-bezier(0.23,1,0.32,1);
        }

        .product-card:hover .product-img-wrap img { transform: scale(1.07); }

        .product-img-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(to top, rgba(2,13,24,0.65) 0%, transparent 55%);
        }

        .product-img-badge {
            position: absolute; top: 12px; right: 12px;
            background: rgba(2,13,24,0.75); backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
            border-radius: 50px; padding: 4px 12px;
            font-size: 0.7rem; letter-spacing: 0.1em; font-weight: 500; color: var(--aqua);
        }

        .product-img-badge.out { color: #fca5a5; border-color: rgba(248,113,113,0.25); }

        /* body */
        .product-body { padding: 22px; flex: 1; display: flex; flex-direction: column; }

        .product-name {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem; font-weight: 500; color: var(--white); margin-bottom: 6px;
        }

        .product-desc {
            font-size: 0.83rem; color: rgba(202,240,248,0.45);
            line-height: 1.6; flex: 1; margin-bottom: 16px;
        }

        .product-footer {
            display: flex; justify-content: space-between; align-items: center;
            padding-top: 14px; border-top: 1px solid rgba(72,202,228,0.1);
            margin-bottom: 16px;
        }

        .product-price {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.85rem; font-weight: 600; color: var(--aqua); line-height: 1;
        }

        .product-price small {
            font-family: 'DM Sans', sans-serif;
            font-size: 0.72rem; color: rgba(202,240,248,0.35);
            font-weight: 400; display: block; margin-top: 2px;
        }

        .stock-pill {
            padding: 5px 12px; border-radius: 50px;
            font-size: 0.72rem; font-weight: 700; letter-spacing: 0.07em;
        }

        .stock-high   { background: rgba(74,222,128,0.1);  color: #4ade80;  border: 1px solid rgba(74,222,128,0.25); }
        .stock-medium { background: rgba(0,180,216,0.1);   color: var(--aqua); border: 1px solid rgba(0,180,216,0.25); }
        .stock-low    { background: rgba(244,200,66,0.12); color: var(--gold); border: 1px solid rgba(244,200,66,0.25); }
        .stock-out    { background: rgba(248,113,113,0.1); color: #fca5a5; border: 1px solid rgba(248,113,113,0.25); }

        /* order button */
        .btn-order {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border: none; border-radius: 50px;
            color: var(--deep); font-family: 'DM Sans', sans-serif;
            font-size: 0.85rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
            cursor: pointer; transition: all 0.3s;
            box-shadow: 0 5px 18px rgba(0,180,216,0.25);
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }

        .btn-order:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,180,216,0.45); }

        .btn-order:disabled {
            background: rgba(72,202,228,0.1); border: 1px solid var(--glass-border);
            color: rgba(202,240,248,0.3); box-shadow: none; cursor: not-allowed; transform: none;
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

        .empty-state h5 { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; color: var(--white); margin-bottom: 8px; }
        .empty-state p  { font-size: 0.86rem; color: rgba(202,240,248,0.35); }

        /* ── UNVERIFIED BANNER ── */
        .unverified-banner {
            display: flex; align-items: center; gap: 14px;
            background: rgba(244,200,66,0.08);
            border: 1px solid rgba(244,200,66,0.25);
            border-radius: 14px; padding: 14px 18px;
            margin-bottom: 24px;
        }

        .unverified-banner i { color: var(--gold); font-size: 1.1rem; flex-shrink: 0; }
        .unverified-banner p { font-size: 0.86rem; color: rgba(244,200,66,0.85); margin: 0; }
        .unverified-banner a { color: var(--gold); font-weight: 600; }

        /* ── ORDER MODAL ── */
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
            font-size: 1.45rem !important; font-weight: 500 !important; color: var(--white) !important;
        }

        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

        /* modal product preview */
        .modal-product-preview {
            background: rgba(4,30,53,0.6);
            border: 1px solid rgba(72,202,228,0.1);
            border-radius: 14px; padding: 16px 18px;
            display: flex; align-items: center; gap: 16px; margin-bottom: 24px;
        }

        .modal-product-img {
            width: 60px; height: 60px; border-radius: 10px;
            object-fit: cover; border: 1px solid var(--glass-border); flex-shrink: 0;
        }

        .modal-product-name {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.2rem; font-weight: 500; color: var(--white);
        }

        .modal-product-price { font-size: 0.8rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .modal-product-price strong { color: var(--aqua); font-size: 1rem; font-family: 'Cormorant Garamond', serif; }

        /* form fields */
        .field-group { margin-bottom: 18px; }

        .field-label {
            display: block; font-size: 0.72rem; letter-spacing: 0.12em;
            text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 8px;
        }

        .field-input, .field-select {
            width: 100%;
            background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border);
            color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem;
            padding: 12px 16px; border-radius: 12px; outline: none; transition: all 0.3s;
        }

        .field-input::placeholder { color: rgba(202,240,248,0.2); }

        .field-input:focus, .field-select:focus {
            border-color: var(--aqua);
            background: rgba(0,180,216,0.07);
            box-shadow: 0 0 0 3px rgba(0,180,216,0.1);
        }

        .field-select option { background: var(--ocean); color: var(--white); }

        .field-hint { font-size: 0.73rem; color: rgba(202,240,248,0.3); margin-top: 5px; }

        /* total display */
        .order-total-preview {
            background: rgba(0,180,216,0.08); border: 1px solid rgba(0,180,216,0.18);
            border-radius: 12px; padding: 14px 18px;
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 18px;
        }

        .order-total-preview .label { font-size: 0.78rem; color: rgba(202,240,248,0.45); }
        .order-total-preview .value {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.6rem; font-weight: 600; color: var(--aqua);
        }

        /* gcash panel */
        .gcash-panel {
            background: rgba(244,200,66,0.06);
            border: 1px solid rgba(244,200,66,0.2);
            border-radius: 12px; padding: 16px 18px; margin-bottom: 18px;
        }

        .gcash-panel-title { font-size: 0.72rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--gold); margin-bottom: 10px; }

        .gcash-detail { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 4px 0; }
        .gcash-detail .key { color: rgba(202,240,248,0.4); }
        .gcash-detail .val { color: var(--white); font-weight: 600; }

        /* file upload */
        .file-upload-area {
            border: 2px dashed rgba(72,202,228,0.2);
            border-radius: 12px; padding: 20px;
            text-align: center; cursor: pointer; transition: all 0.3s;
            position: relative;
        }

        .file-upload-area:hover { border-color: rgba(0,180,216,0.4); background: rgba(0,180,216,0.04); }
        .file-upload-area input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

        .file-upload-icon { font-size: 1.4rem; color: rgba(0,180,216,0.3); margin-bottom: 6px; }
        .file-upload-text { font-size: 0.82rem; color: rgba(202,240,248,0.4); }
        .file-upload-text span { color: var(--aqua); font-weight: 600; }

        /* modal btns */
        .btn-submit-order {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border: none; border-radius: 50px;
            color: var(--deep); font-family: 'DM Sans', sans-serif;
            font-size: 0.87rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase;
            cursor: pointer; transition: all 0.3s;
            box-shadow: 0 6px 22px rgba(0,180,216,0.3);
            display: flex; align-items: center; justify-content: center; gap: 10px;
        }

        .btn-submit-order:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(0,180,216,0.5); }
        .btn-submit-order:disabled { opacity: 0.35; cursor: not-allowed; transform: none; }

        .btn-glass {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--glass); border: 1px solid var(--glass-border);
            color: var(--aqua); padding: 9px 18px; border-radius: 50px;
            font-size: 0.8rem; font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.3s;
        }

        .btn-glass:hover { background: rgba(0,180,216,0.15); color: var(--foam); border-color: rgba(0,180,216,0.3); }

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
        <a href="products.php"           class="nav-link active"><i class="fas fa-droplet"></i> Products</a>
        <a href="order_history.php"      class="nav-link"><i class="fas fa-history"></i> Order History</a>
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

<!-- ── MAIN ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
            <div class="topbar-greeting">
                <h4>Our Products</h4>
                <p>Choose from our premium water collection</p>
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
            <div class="page-header-icon"><i class="fas fa-droplet"></i></div>
            <div>
                <div class="page-header-title">Water Collection</div>
                <div class="page-header-sub"><?php echo $totalProducts; ?> product<?php echo $totalProducts != 1 ? 's' : ''; ?> available · 5-gallon containers</div>
            </div>
        </div>
        <a href="order_history.php" class="btn-glass">
            <i class="fas fa-history"></i> Order History
        </a>
    </div>

    <!-- Email unverified warning -->
    <?php if (!$isEmailVerified): ?>
    <div class="unverified-banner">
        <i class="fas fa-triangle-exclamation"></i>
        <p>Your email is not verified. <a href="profile.php">Verify your email</a> to place orders.</p>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="search-wrap">
            <i class="fas fa-search search-icon"></i>
            <input type="text" class="search-input" id="productSearch" placeholder="Search products…">
        </div>
        <button class="filter-pill active" onclick="filterProducts('all', this)">All</button>
        <button class="filter-pill" onclick="filterProducts('in-stock', this)">In Stock</button>
        <button class="filter-pill" onclick="filterProducts('out', this)">Out of Stock</button>
    </div>

    <!-- Products Grid -->
    <?php if ($totalProducts > 0): ?>
    <div class="row g-4" id="productsGrid">
        <?php
        $productsResult->data_seek(0);
        while ($product = $productsResult->fetch_assoc()):
            $stock = intval($product['current_stock']);

            if ($stock <= 0)       { $stockClass = 'stock-out';    $stockText = 'Out of Stock'; $filterKey = 'out'; }
            elseif ($stock < 10)   { $stockClass = 'stock-low';    $stockText = $stock . ' left'; $filterKey = 'in-stock'; }
            elseif ($stock < 30)   { $stockClass = 'stock-medium'; $stockText = $stock . ' units'; $filterKey = 'in-stock'; }
            else                   { $stockClass = 'stock-high';   $stockText = 'In Stock'; $filterKey = 'in-stock'; }

            $imagePath = $product['product_image'];
            if (empty($imagePath) || !file_exists('../' . $imagePath)) {
                $imagePath = 'https://images.unsplash.com/photo-1548839140-29a749e1cf4d?auto=format&fit=crop&w=600&q=80';
            } else {
                $imagePath = '../' . $imagePath;
            }
        ?>
        <div class="col-md-6 col-lg-4 product-col" data-stock="<?php echo $filterKey; ?>"
             data-search="<?php echo strtolower(htmlspecialchars($product['ProductName'] . ' ' . ($product['Description'] ?? ''))); ?>">
            <div class="product-card h-100">
                <div class="product-img-wrap">
                    <img src="<?php echo $imagePath; ?>" alt="<?php echo htmlspecialchars($product['ProductName']); ?>"
                         onerror="this.src='https://images.unsplash.com/photo-1548839140-29a749e1cf4d?w=600&q=80'">
                    <div class="product-img-overlay"></div>
                    <span class="product-img-badge <?php echo $stock <= 0 ? 'out' : ''; ?>">
                        <?php echo $stock <= 0 ? 'Out of Stock' : '5 Gallon'; ?>
                    </span>
                </div>

                <div class="product-body">
                    <h5 class="product-name"><?php echo htmlspecialchars($product['ProductName']); ?></h5>
                    <p class="product-desc"><?php echo htmlspecialchars($product['Description'] ?? 'Premium quality purified water.'); ?></p>

                    <div class="product-footer">
                        <div class="product-price">
                            ₱<?php echo number_format($product['Price'], 2); ?>
                            <small>per 5-gallon container</small>
                        </div>
                        <span class="stock-pill <?php echo $stockClass; ?>"><?php echo $stockText; ?></span>
                    </div>

                    <?php if ($stock > 0): ?>
                        <button class="btn-order"
                                data-bs-toggle="modal" data-bs-target="#orderModal"
                                data-productid="<?php echo $product['ProductID']; ?>"
                                data-productname="<?php echo htmlspecialchars($product['ProductName']); ?>"
                                data-price="<?php echo $product['Price']; ?>"
                                data-stock="<?php echo $stock; ?>"
                                data-image="<?php echo $imagePath; ?>">
                            <i class="fas fa-shopping-bag"></i> Order Now
                        </button>
                    <?php else: ?>
                        <button class="btn-order" disabled>
                            <i class="fas fa-xmark"></i> Out of Stock
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    </div>

    <!-- No results -->
    <div id="noResults" style="display:none;" class="empty-state mt-3">
        <div class="empty-ring"><i class="fas fa-magnifying-glass"></i></div>
        <h5>No products found</h5>
        <p>Try a different search term or filter.</p>
    </div>

    <?php else: ?>
    <div class="empty-state">
        <div class="empty-ring"><i class="fas fa-box-open"></i></div>
        <h5>No Products Available</h5>
        <p>Please check back later or contact our support team.</p>
    </div>
    <?php endif; ?>

</main>

<!-- ── ORDER MODAL ── -->
<div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form action="products.php" method="POST" enctype="multipart/form-data" id="orderForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-shopping-bag me-2" style="color:var(--aqua);"></i>
                        Place Your Order
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="productID" id="productID">

                    <!-- Product preview -->
                    <div class="modal-product-preview">
                        <img src="" alt="" class="modal-product-img" id="modalProductImg">
                        <div>
                            <div class="modal-product-name" id="modalProductName"></div>
                            <div class="modal-product-price">
                                <strong id="modalProductPrice"></strong> / 5-gallon container
                            </div>
                            <div style="font-size:0.75rem; color:rgba(202,240,248,0.35); margin-top:2px;">
                                <i class="fas fa-box me-1"></i> <span id="modalStockInfo"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Quantity + Delivery -->
                    <div class="row g-3 mb-0">
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Quantity (5-Gallon)</label>
                                <input type="number" class="field-input" name="quantity" id="quantity" min="6" max="100" value="6" required>
                                <div class="field-hint">Min. 6 · Max. 100</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Delivery Option</label>
                                <select class="field-select" name="delivery_option" id="delivery_option">
                                    <option value="today">Today (Same Day)</option>
                                    <option value="scheduled">Scheduled</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="field-group" id="deliveryDateContainer" style="display:none;">
                        <label class="field-label">Preferred Delivery Date</label>
                        <input type="date" class="field-input" name="delivery_date" id="delivery_date"
                               min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="field-group">
                        <label class="field-label">Payment Method</label>
                        <select class="field-select" name="payment_method" id="payment_method">
                            <option value="COD">Cash on Delivery (COD)</option>
                            <option value="GCash">GCash</option>
                        </select>
                    </div>

                    <!-- GCash section -->
                    <div id="gcashDetails" style="display:none;">
                        <div class="gcash-panel">
                            <div class="gcash-panel-title"><i class="fas fa-mobile-screen-button me-1"></i> GCash Payment Details</div>
                            <div class="gcash-detail"><span class="key">Number</span><span class="val">0950-200-1713</span></div>
                            <div class="gcash-detail"><span class="key">Account Name</span><span class="val">Romeo E. De Chavez</span></div>
                        </div>

                        <div class="field-group mb-0">
                            <label class="field-label">Upload Payment Receipt <span style="color:#fca5a5;">*</span></label>
                            <div class="file-upload-area" id="fileUploadArea">
                                <input type="file" name="gcash_receipt" id="gcash_receipt" accept="image/*">
                                <div class="file-upload-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                                <div class="file-upload-text"><span>Click to upload</span> or drag & drop</div>
                                <div class="field-hint" style="margin-top:4px;">JPG, PNG, GIF accepted</div>
                            </div>
                            <div id="filePreview" style="display:none; margin-top:8px;">
                                <img id="filePreviewImg" style="max-height:100px; border-radius:8px; border:1px solid var(--glass-border);">
                                <div id="fileName" style="font-size:0.75rem; color:rgba(202,240,248,0.4); margin-top:4px;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Order total -->
                    <div class="order-total-preview mt-4">
                        <div>
                            <div class="label">Estimated Total</div>
                            <div style="font-size:0.72rem;color:rgba(202,240,248,0.25);margin-top:2px;">Based on quantity × unit price</div>
                        </div>
                        <div class="value" id="orderTotal">₱0.00</div>
                    </div>

                    <button type="submit" name="place_order" class="btn-submit-order" id="confirmBtn">
                        <i class="fas fa-check-circle"></i> Confirm Order
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
    sidebar.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => { if (window.innerWidth < 992) closeSidebar(); }));

    // ── SEARCH + FILTER ──
    let activeFilter = 'all';
    let searchTerm   = '';

    document.getElementById('productSearch').addEventListener('input', function () {
        searchTerm = this.value.toLowerCase().trim();
        applyFilters();
    });

    function filterProducts(filter, btn) {
        activeFilter = filter;
        document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        applyFilters();
    }

    function applyFilters() {
        const cols   = document.querySelectorAll('.product-col');
        let visible  = 0;

        cols.forEach(col => {
            const matchFilter = activeFilter === 'all' || col.dataset.stock === activeFilter;
            const matchSearch = !searchTerm || col.dataset.search.includes(searchTerm);
            const show = matchFilter && matchSearch;
            col.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        const noRes = document.getElementById('noResults');
        if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
    }

    // ── ORDER MODAL ──
    let currentPrice = 0;
    let currentStock = 0;

    const orderModal = document.getElementById('orderModal');
    orderModal.addEventListener('show.bs.modal', function (e) {
        const btn = e.relatedTarget;
        const productID   = btn.dataset.productid;
        const productName = btn.dataset.productname;
        const price       = parseFloat(btn.dataset.price);
        const stock       = parseInt(btn.dataset.stock);
        const image       = btn.dataset.image;

        currentPrice = price;
        currentStock = stock;

        document.getElementById('productID').value    = productID;
        document.getElementById('modalProductName').textContent  = productName;
        document.getElementById('modalProductPrice').textContent = '₱' + price.toFixed(2);
        document.getElementById('modalStockInfo').textContent    = stock + ' unit' + (stock !== 1 ? 's' : '') + ' available';
        document.getElementById('modalProductImg').src           = image;

        const qtyInput = document.getElementById('quantity');
        qtyInput.max   = Math.min(100, stock);
        qtyInput.value = Math.min(6, stock);

        updateTotal();
        document.getElementById('confirmBtn').disabled = stock <= 0;
    });

    // Quantity → update total
    document.getElementById('quantity').addEventListener('input', updateTotal);

    function updateTotal() {
        const qty   = parseInt(document.getElementById('quantity').value) || 0;
        const total = qty * currentPrice;
        document.getElementById('orderTotal').textContent = '₱' + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // Delivery option toggle
    document.getElementById('delivery_option').addEventListener('change', function () {
        const show = this.value === 'scheduled';
        document.getElementById('deliveryDateContainer').style.display = show ? 'block' : 'none';
        if (!show) document.getElementById('delivery_date').value = '<?php echo date('Y-m-d'); ?>';
    });

    // Payment method toggle
    document.getElementById('payment_method').addEventListener('change', function () {
        const isGcash = this.value === 'GCash';
        document.getElementById('gcashDetails').style.display = isGcash ? 'block' : 'none';
        document.getElementById('gcash_receipt')[isGcash ? 'setAttribute' : 'removeAttribute']('required', 'required');
    });

    // File preview
    document.getElementById('gcash_receipt').addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('filePreviewImg').src = e.target.result;
            document.getElementById('fileName').textContent = file.name;
            document.getElementById('filePreview').style.display = 'block';
            document.getElementById('fileUploadArea').style.borderColor = 'rgba(0,180,216,0.5)';
        };
        reader.readAsDataURL(file);
    });

    // Form validation
    document.getElementById('orderForm').addEventListener('submit', function (e) {
        const qty     = parseInt(document.getElementById('quantity').value);
        const payment = document.getElementById('payment_method').value;

        if (qty < 6 || qty > 100) {
            e.preventDefault();
            alert('Quantity must be between 6 and 100.');
            return false;
        }

        if (payment === 'GCash' && !document.getElementById('gcash_receipt').value) {
            e.preventDefault();
            alert('Please upload your GCash receipt.');
            return false;
        }

        return true;
    });
</script>
</body>
</html>