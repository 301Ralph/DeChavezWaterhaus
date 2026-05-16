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

$uploadDir = '../uploads/products/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// Handle Add Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $productName = htmlspecialchars($_POST['product_name']);
    $description = htmlspecialchars($_POST['description']);
    $price       = floatval($_POST['price']);
    $stock       = intval($_POST['stock']);
    $status      = $_POST['status'];
    $imageURL    = '';

    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == 0) {
        $allowed = ['image/jpeg','image/png','image/jpg','image/webp'];
        if (in_array($_FILES['product_image']['type'], $allowed)) {
            $fileName = time().'_'.basename($_FILES['product_image']['name']);
            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $uploadDir.$fileName)) {
                $imageURL = 'uploads/products/'.$fileName;
            }
        }
    }

    $stmt = $conn->prepare("INSERT INTO product (ProductName,Description,Price,Stock,Status,ImageURL) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("ssdiss", $productName, $description, $price, $stock, $status, $imageURL);
    echo $stmt->execute()
        ? '<script>alert("Product added successfully!"); window.location="manage_products.php";</script>'
        : '<script>alert("Error adding product.");</script>';
    $stmt->close();
}

// Handle Update Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_product'])) {
    $productID   = intval($_POST['productID']);
    $productName = htmlspecialchars($_POST['product_name']);
    $description = htmlspecialchars($_POST['description']);
    $price       = floatval($_POST['price']);
    $stock       = intval($_POST['stock']);
    $status      = $_POST['status'];
    $imageURL    = $_POST['current_image'] ?? '';

    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == 0) {
        $allowed = ['image/jpeg','image/png','image/jpg','image/webp'];
        if (in_array($_FILES['product_image']['type'], $allowed)) {
            $fileName = time().'_'.basename($_FILES['product_image']['name']);
            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $uploadDir.$fileName)) {
                if (!empty($imageURL) && file_exists('../'.$imageURL)) unlink('../'.$imageURL);
                $imageURL = 'uploads/products/'.$fileName;
            }
        }
    }

    $stmt = $conn->prepare("UPDATE product SET ProductName=?,Description=?,Price=?,Stock=?,Status=?,ImageURL=? WHERE ProductID=?");
    $stmt->bind_param("ssdissi", $productName, $description, $price, $stock, $status, $imageURL, $productID);
    echo $stmt->execute()
        ? '<script>alert("Product updated successfully!"); window.location="manage_products.php";</script>'
        : '<script>alert("Error updating product.");</script>';
    $stmt->close();
}

// Handle Delete Product
if (isset($_GET['delete'])) {
    $productID = intval($_GET['delete']);
    $r = $conn->query("SELECT ImageURL FROM product WHERE ProductID=$productID");
    if ($r && $row = $r->fetch_assoc()) {
        if (!empty($row['ImageURL']) && file_exists('../'.$row['ImageURL'])) unlink('../'.$row['ImageURL']);
    }
    $conn->query("DELETE FROM product WHERE ProductID=$productID");
    echo '<script>window.location="manage_products.php";</script>'; exit();
}

$products   = $conn->query("SELECT * FROM product ORDER BY ProductID DESC");
$prodCount  = $products->num_rows;
$notifCount = $conn->query("SELECT COUNT(*) as u FROM notifications WHERE userID=$adminID AND is_read=0")->fetch_assoc()['u'] ?? 0;

// Quick stats
$activeCount   = 0; $lowStockCount = 0; $outCount = 0;
$allProds = [];
while($p = $products->fetch_assoc()) {
    $allProds[] = $p;
    if($p['Status']==='Active') $activeCount++;
    if($p['Stock']>0 && $p['Stock']<=10) $lowStockCount++;
    if($p['Stock']===0) $outCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Products • Admin</title>
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
        .btn-add { display: inline-flex; align-items: center; gap: 7px; padding: 10px 20px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(0,180,216,0.25); }
        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); color: var(--deep); }
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
        .stat-card:hover { transform: translateY(-4px); border-color: rgba(0,180,216,0.25); }
        .stat-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .si-blue  { background: rgba(0,180,216,0.12); color: var(--aqua); }
        .si-green { background: rgba(74,222,128,0.1);  color: var(--green); }
        .si-gold  { background: rgba(244,200,66,0.1);  color: var(--gold); }
        .si-red   { background: rgba(248,113,113,0.1); color: var(--red); }
        .stat-num { font-family: 'Cormorant Garamond', serif; font-size: 1.85rem; font-weight: 600; color: var(--white); line-height: 1; }
        .stat-lbl { font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-top: 3px; }

        /* ── DATA CARD ── */
        .data-card { background: linear-gradient(145deg,rgba(10,45,74,0.5),rgba(3,15,30,0.75)); border: 1px solid var(--glass-border); border-radius: 17px; overflow: hidden; }
        .data-card-head { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 1px solid var(--glass-border); flex-wrap: wrap; gap: 10px; }
        .data-card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.18rem; font-weight: 500; color: var(--white); }
        .data-card-sub   { font-size: 0.75rem; color: rgba(202,240,248,0.35); margin-top: 2px; }
        .count-badge { background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); padding: 3px 10px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; }

        /* search + filter bar */
        .toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; padding: 14px 20px; border-bottom: 1px solid rgba(72,202,228,0.06); }
        .search-wrap { position: relative; }
        .search-input { background: rgba(4,30,53,0.6); border: 1px solid var(--glass-border); color: var(--white); border-radius: 50px; padding: 8px 16px 8px 36px; font-size: 0.84rem; font-family: 'DM Sans', sans-serif; outline: none; transition: all 0.3s; width: 260px; }
        .search-input::placeholder { color: rgba(202,240,248,0.22); }
        .search-input:focus { border-color: var(--aqua); background: rgba(0,180,216,0.06); }
        .search-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: rgba(0,180,216,0.35); font-size: 0.78rem; }
        .filter-pills { display: flex; gap: 6px; flex-wrap: wrap; }
        .filter-pill { padding: 5px 13px; border-radius: 50px; border: 1px solid var(--glass-border); background: transparent; color: rgba(202,240,248,0.42); font-family: 'DM Sans', sans-serif; font-size: 0.76rem; font-weight: 500; cursor: pointer; transition: all 0.22s; }
        .filter-pill:hover { color: var(--foam); border-color: rgba(0,180,216,0.28); }
        .filter-pill.active { background: linear-gradient(135deg, var(--teal), var(--aqua)); border-color: transparent; color: var(--deep); font-weight: 700; box-shadow: 0 4px 14px rgba(0,180,216,0.22); }

        /* ── TABLE ── */
        .prod-table { width: 100%; border-collapse: collapse; }
        .prod-table th { font-size: 0.66rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.3); padding: 0 16px 12px; text-align: left; border-bottom: 1px solid var(--glass-border); }
        .prod-table td { padding: 14px 16px; font-size: 0.86rem; color: rgba(202,240,248,0.7); border-bottom: 1px solid rgba(72,202,228,0.06); vertical-align: middle; }
        .prod-table tr:last-child td { border-bottom: none; }
        .prod-table tr:hover td { background: rgba(0,180,216,0.03); color: var(--foam); }

        /* product thumb */
        .prod-thumb { width: 52px; height: 52px; border-radius: 10px; object-fit: cover; border: 1px solid var(--glass-border); }
        .prod-placeholder { width: 52px; height: 52px; border-radius: 10px; background: rgba(4,30,53,0.6); border: 1px solid var(--glass-border); display: flex; align-items: center; justify-content: center; color: rgba(202,240,248,0.2); font-size: 1.2rem; }

        .prod-name { font-weight: 500; color: var(--white); font-size: 0.88rem; }
        .prod-desc { font-size: 0.73rem; color: rgba(202,240,248,0.35); margin-top: 2px; max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .price-val { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; font-weight: 600; color: var(--white); }

        /* stock badges */
        .stock-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; }
        .stock-ok   { background: rgba(74,222,128,0.1);  color: var(--green); border: 1px solid rgba(74,222,128,0.25); }
        .stock-low  { background: rgba(244,200,66,0.12); color: var(--gold);  border: 1px solid rgba(244,200,66,0.25); }
        .stock-out  { background: rgba(248,113,113,0.1); color: var(--red);   border: 1px solid rgba(248,113,113,0.25); }

        /* status pill */
        .s-Active   { background: rgba(74,222,128,0.1);  color: var(--green); border: 1px solid rgba(74,222,128,0.25); padding: 3px 11px; border-radius: 50px; font-size: 0.71rem; font-weight: 700; }
        .s-Inactive { background: rgba(148,163,184,0.1); color: #94a3b8;      border: 1px solid rgba(148,163,184,0.2); padding: 3px 11px; border-radius: 50px; font-size: 0.71rem; font-weight: 700; }

        /* action buttons */
        .btn-edit-sm { display: inline-flex; align-items: center; gap: 5px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 6px 14px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; cursor: pointer; transition: all 0.25s; }
        .btn-edit-sm:hover { background: rgba(0,180,216,0.15); border-color: rgba(0,180,216,0.3); }
        .btn-del-sm { display: inline-flex; align-items: center; gap: 5px; background: rgba(248,113,113,0.08); border: 1px solid rgba(248,113,113,0.22); color: var(--red); padding: 6px 14px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.25s; }
        .btn-del-sm:hover { background: rgba(248,113,113,0.18); color: var(--red); }

        /* empty */
        .empty-state { text-align: center; padding: 56px 20px; color: rgba(202,240,248,0.3); }
        .empty-state i { font-size: 2.5rem; display: block; margin-bottom: 14px; color: rgba(0,180,216,0.15); }
        .empty-state p { font-size: 0.85rem; }

        /* no results */
        #noResults { display: none; text-align: center; padding: 40px; color: rgba(202,240,248,0.3); font-size: 0.85rem; }

        /* ── MODAL ── */
        .modal-content { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 18px !important; }
        .modal-header { border-bottom: 1px solid var(--glass-border) !important; padding: 20px 24px !important; }
        .modal-footer { border-top: 1px solid var(--glass-border) !important; padding: 16px 24px !important; }
        .modal-body { padding: 24px !important; }
        .modal-title { font-family: 'Cormorant Garamond', serif !important; font-size: 1.3rem !important; font-weight: 500 !important; color: var(--white) !important; }
        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

        .field-label { display: block; font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 7px; }
        .field-input, .field-select, .field-textarea { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 11px 14px; border-radius: 11px; outline: none; transition: all 0.3s; }
        .field-input::placeholder, .field-textarea::placeholder { color: rgba(202,240,248,0.2); }
        .field-input:focus, .field-select:focus, .field-textarea:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }
        .field-select option { background: var(--ocean); }
        .field-textarea { resize: vertical; min-height: 80px; line-height: 1.6; }
        .field-file { width: 100%; background: rgba(4,30,53,0.5); border: 1px dashed rgba(72,202,228,0.25); color: rgba(202,240,248,0.5); font-family: 'DM Sans', sans-serif; font-size: 0.85rem; padding: 10px 14px; border-radius: 11px; outline: none; transition: all 0.3s; cursor: pointer; }
        .field-file::-webkit-file-upload-button { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); border-radius: 6px; padding: 5px 12px; cursor: pointer; font-size: 0.8rem; margin-right: 10px; }
        .field-hint { font-size: 0.72rem; color: rgba(202,240,248,0.28); margin-top: 5px; }

        /* image preview in modal */
        .img-preview-wrap { background: rgba(4,30,53,0.5); border: 1px solid var(--glass-border); border-radius: 14px; padding: 16px; text-align: center; }
        .img-preview { width: 130px; height: 130px; object-fit: cover; border-radius: 10px; border: 2px solid rgba(0,180,216,0.25); }
        .img-placeholder { width: 130px; height: 130px; border-radius: 10px; background: rgba(4,30,53,0.7); border: 2px dashed rgba(72,202,228,0.2); display: flex; align-items: center; justify-content: center; color: rgba(202,240,248,0.2); font-size: 2rem; margin: 0 auto; }

        .btn-glass-modal { display: inline-flex; align-items: center; gap: 6px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 9px 18px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-glass-modal:hover { background: rgba(0,180,216,0.15); color: var(--foam); }
        .btn-save-modal { padding: 10px 26px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.83rem; font-weight: 700; letter-spacing: 0.07em; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(0,180,216,0.25); }
        .btn-save-modal:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 38px; height: 38px; border-radius: 9px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.88rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 18px 16px; }
            .mobile-toggle { display: flex; }
            .search-input { width: 200px; }
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
        <a href="manage_products.php"   class="nav-link active"><i class="fas fa-box"></i> Products</a>
        <a href="manage_orders.php"     class="nav-link"><i class="fas fa-shopping-cart"></i> Orders</a>
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
                <h4>Manage Products</h4>
                <p>Add, edit, and manage your product inventory</p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo min($notifCount,9).($notifCount>9?'+':'');?></span><?php endif; ?>
            </a>
            <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addProdModal">
                <i class="fas fa-plus"></i> Add Product
            </button>
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

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="fas fa-box"></i></div>
                <div><div class="stat-num"><?php echo $prodCount;?></div><div class="stat-lbl">Total Products</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="fas fa-circle-check"></i></div>
                <div><div class="stat-num" style="color:var(--green);"><?php echo $activeCount;?></div><div class="stat-lbl">Active</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-gold"><i class="fas fa-triangle-exclamation"></i></div>
                <div><div class="stat-num" style="color:var(--gold);"><?php echo $lowStockCount;?></div><div class="stat-lbl">Low Stock</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-red"><i class="fas fa-ban"></i></div>
                <div><div class="stat-num" style="color:var(--red);"><?php echo $outCount;?></div><div class="stat-lbl">Out of Stock</div></div>
            </div>
        </div>
    </div>

    <!-- Products Table -->
    <div class="data-card">
        <div class="data-card-head">
            <div>
                <div class="data-card-title">All Products</div>
                <div class="data-card-sub">Click Edit to update details or image</div>
            </div>
            <span class="count-badge"><?php echo $prodCount;?> Product<?php echo $prodCount!=1?'s':'';?></span>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="filter-pills">
                <button class="filter-pill active" onclick="filterProds('all',this)">All</button>
                <button class="filter-pill" onclick="filterProds('Active',this)">Active</button>
                <button class="filter-pill" onclick="filterProds('Inactive',this)">Inactive</button>
                <button class="filter-pill" onclick="filterProds('low',this)">Low Stock</button>
                <button class="filter-pill" onclick="filterProds('out',this)">Out of Stock</button>
            </div>
            <div class="search-wrap">
                <i class="fas fa-search search-icon"></i>
                <input type="text" class="search-input" id="prodSearch" placeholder="Search products…">
            </div>
        </div>

        <?php if(count($allProds) > 0): ?>
        <div style="overflow-x:auto;">
            <table class="prod-table">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th style="text-align:right;padding-right:22px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="prodBody">
                    <?php foreach($allProds as $prod):
                        $stockClass = $prod['Stock'] > 10 ? 'ok' : ($prod['Stock'] > 0 ? 'low' : 'out');
                        $stockLabel = $prod['Stock'] > 10 ? $prod['Stock'].' units' : ($prod['Stock'] > 0 ? $prod['Stock'].' — Low' : 'Out of Stock');
                    ?>
                    <tr class="prod-row"
                        data-status="<?php echo $prod['Status'];?>"
                        data-stock="<?php echo $stockClass;?>"
                        data-search="<?php echo strtolower(htmlspecialchars($prod['ProductName'].' '.$prod['Description']));?>">
                        <td>
                            <?php if(!empty($prod['ImageURL'])&&file_exists('../'.$prod['ImageURL'])): ?>
                                <img src="../<?php echo htmlspecialchars($prod['ImageURL']);?>" class="prod-thumb" alt="">
                            <?php else: ?>
                                <div class="prod-placeholder"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="prod-name"><?php echo htmlspecialchars($prod['ProductName']);?></div>
                            <div class="prod-desc"><?php echo htmlspecialchars($prod['Description']);?></div>
                        </td>
                        <td><span class="price-val">₱<?php echo number_format($prod['Price'],2);?></span></td>
                        <td><span class="stock-badge stock-<?php echo $stockClass;?>"><?php echo $stockLabel;?></span></td>
                        <td><span class="s-<?php echo $prod['Status'];?>"><?php echo $prod['Status'];?></span></td>
                        <td style="text-align:right;padding-right:18px;">
                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;">
                                <button class="btn-edit-sm" data-bs-toggle="modal" data-bs-target="#editProdModal<?php echo $prod['ProductID'];?>">
                                    <i class="fas fa-pen"></i> Edit
                                </button>
                                <a href="manage_products.php?delete=<?php echo $prod['ProductID'];?>" class="btn-del-sm" onclick="return confirm('Delete this product? This cannot be undone.')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="noResults">No products match your search or filter.</div>

        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-box"></i>
            <p>No products yet.<br>Click <strong>"Add Product"</strong> to add your first item.</p>
        </div>
        <?php endif; ?>
    </div>

</main>

<!-- ── EDIT MODALS ── -->
<?php foreach($allProds as $prod): ?>
<div class="modal fade" id="editProdModal<?php echo $prod['ProductID'];?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="productID"     value="<?php echo $prod['ProductID'];?>">
                <input type="hidden" name="current_image" value="<?php echo htmlspecialchars($prod['ImageURL']);?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen me-2" style="color:var(--aqua);font-size:0.9rem;"></i>Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="img-preview-wrap">
                                <?php if(!empty($prod['ImageURL'])&&file_exists('../'.$prod['ImageURL'])): ?>
                                    <img src="../<?php echo htmlspecialchars($prod['ImageURL']);?>" class="img-preview" alt="">
                                <?php else: ?>
                                    <div class="img-placeholder"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                                <div style="margin-top:12px;">
                                    <label class="field-label" style="text-align:center;display:block;">Change Image</label>
                                    <input type="file" class="field-file" name="product_image" accept="image/*">
                                    <div class="field-hint" style="text-align:center;">JPG, PNG, WebP</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div style="margin-bottom:16px;">
                                <label class="field-label">Product Name</label>
                                <input type="text" class="field-input" name="product_name" value="<?php echo htmlspecialchars($prod['ProductName']);?>" required>
                            </div>
                            <div style="margin-bottom:16px;">
                                <label class="field-label">Description</label>
                                <textarea class="field-textarea" name="description" required><?php echo htmlspecialchars($prod['Description']);?></textarea>
                            </div>
                            <div class="row g-3">
                                <div class="col-6">
                                    <label class="field-label">Price (₱)</label>
                                    <input type="number" step="0.01" min="0.01" class="field-input" name="price" value="<?php echo $prod['Price'];?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="field-label">Stock</label>
                                    <input type="number" min="0" class="field-input" name="stock" value="<?php echo $prod['Stock'];?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="field-label">Status</label>
                                    <select class="field-select" name="status" required>
                                        <option value="Active"   <?php echo $prod['Status']==='Active'?'selected':'';?>>Active</option>
                                        <option value="Inactive" <?php echo $prod['Status']==='Inactive'?'selected':'';?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass-modal" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_product" class="btn-save-modal"><i class="fas fa-check me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ── ADD PRODUCT MODAL ── -->
<div class="modal fade" id="addProdModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2" style="color:var(--aqua);font-size:0.9rem;"></i>Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="img-preview-wrap">
                                <div class="img-placeholder" id="newImgPreview"><i class="fas fa-image"></i></div>
                                <div style="margin-top:12px;">
                                    <label class="field-label" style="text-align:center;display:block;">Product Image</label>
                                    <input type="file" class="field-file" name="product_image" accept="image/*" id="newImgInput">
                                    <div class="field-hint" style="text-align:center;">Optional · JPG, PNG, WebP</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div style="margin-bottom:16px;">
                                <label class="field-label">Product Name<span style="color:var(--red);margin-left:2px;">*</span></label>
                                <input type="text" class="field-input" name="product_name" placeholder="e.g. Premium 5-Gallon Water" required>
                            </div>
                            <div style="margin-bottom:16px;">
                                <label class="field-label">Description<span style="color:var(--red);margin-left:2px;">*</span></label>
                                <textarea class="field-textarea" name="description" placeholder="Describe the product…" required></textarea>
                            </div>
                            <div class="row g-3">
                                <div class="col-6">
                                    <label class="field-label">Price (₱)<span style="color:var(--red);margin-left:2px;">*</span></label>
                                    <input type="number" step="0.01" min="0.01" class="field-input" name="price" placeholder="0.00" required>
                                </div>
                                <div class="col-6">
                                    <label class="field-label">Initial Stock<span style="color:var(--red);margin-left:2px;">*</span></label>
                                    <input type="number" min="0" class="field-input" name="stock" value="50" required>
                                </div>
                                <div class="col-12">
                                    <label class="field-label">Status</label>
                                    <select class="field-select" name="status" required>
                                        <option value="Active">Active</option>
                                        <option value="Inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass-modal" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_product" class="btn-save-modal"><i class="fas fa-plus me-1"></i> Add Product</button>
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
    if(toggle)  toggle.addEventListener('click', openSidebar);
    if(overlay) overlay.addEventListener('click', closeSidebar);
    sidebar.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => { if(window.innerWidth<992) closeSidebar(); }));

    // ── IMAGE PREVIEW IN ADD MODAL ──
    document.getElementById('newImgInput')?.addEventListener('change', function() {
        if(!this.files[0]) return;
        const reader = new FileReader();
        reader.onload = e => {
            const wrap = document.getElementById('newImgPreview');
            wrap.innerHTML = `<img src="${e.target.result}" style="width:130px;height:130px;object-fit:cover;border-radius:10px;border:2px solid rgba(0,180,216,0.25);" alt="">`;
        };
        reader.readAsDataURL(this.files[0]);
    });

    // ── FILTER ──
    let currentFilter = 'all';
    let currentSearch = '';

    function filterProds(val, btn) {
        document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentFilter = val;
        applyFilter();
    }

    function applyFilter() {
        const rows = document.querySelectorAll('.prod-row');
        let vis = 0;
        rows.forEach(row => {
            const matchFilter = currentFilter === 'all'
                || row.dataset.status === currentFilter
                || (currentFilter === 'low'  && row.dataset.stock === 'low')
                || (currentFilter === 'out'  && row.dataset.stock === 'out');
            const matchSearch = !currentSearch || row.dataset.search.includes(currentSearch);
            const show = matchFilter && matchSearch;
            row.style.display = show ? '' : 'none';
            if(show) vis++;
        });
        const nr = document.getElementById('noResults');
        if(nr) nr.style.display = vis === 0 ? 'block' : 'none';
    }

    document.getElementById('prodSearch')?.addEventListener('input', function() {
        currentSearch = this.value.toLowerCase().trim();
        applyFilter();
    });
</script>
</body>
</html>