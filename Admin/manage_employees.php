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

// Handle Add Employee
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_employee'])) {
    $firstname = trim(htmlspecialchars($_POST['firstname']));
    $lastname  = trim(htmlspecialchars($_POST['lastname']));
    $email     = trim(filter_var($_POST['email'], FILTER_SANITIZE_EMAIL));
    $phone     = trim(htmlspecialchars($_POST['phone']));
    $password  = $_POST['password'];

    $errors = [];
    if (empty($firstname)) $errors[] = "First name is required";
    if (empty($lastname))  $errors[] = "Last name is required";
    if (empty($email))     $errors[] = "Email is required";
    if (empty($phone))     $errors[] = "Phone number is required";
    if (empty($password))  $errors[] = "Password is required";
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";

    if (!empty($email)) {
        $ck = $conn->prepare("SELECT userID FROM customers WHERE Email=?");
        $ck->bind_param("s", $email); $ck->execute();
        if ($ck->get_result()->num_rows > 0) $errors[] = "Email already exists";
        $ck->close();
    }

    if (!empty($password)) {
        if (strlen($password) < 8)             $errors[] = "Password must be at least 8 characters";
        if (!preg_match('/[A-Z]/', $password))  $errors[] = "Password must contain at least one uppercase letter";
        if (!preg_match('/[0-9]/', $password))  $errors[] = "Password must contain at least one number";
    }

    if (!empty($errors)) {
        echo '<script>alert("'.implode('\\n',$errors).'"); window.location="manage_employees.php";</script>';
        exit();
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt   = $conn->prepare("INSERT INTO customers (Firstname,Lastname,Email,Contact,Password,Role,verification_status,isVerified) VALUES (?,?,?,?,?,'employee','approved',1)");
    $stmt->bind_param("sssss", $firstname, $lastname, $email, $phone, $hashed);
    echo $stmt->execute()
        ? '<script>alert("Employee added successfully!"); window.location="manage_employees.php";</script>'
        : '<script>alert("Error adding employee."); window.location="manage_employees.php";</script>';
    $stmt->close(); exit();
}

// Handle delete
if (isset($_GET['delete'])) {
    $uid = intval($_GET['delete']);
    $conn->query("DELETE FROM customers WHERE userID=$uid AND Role='employee'");
    echo '<script>window.location="manage_employees.php";</script>'; exit();
}

// Handle update employee
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_employee'])) {
    $uid         = intval($_POST['userID']);
    $newEmail    = trim(filter_var($_POST['new_email'], FILTER_SANITIZE_EMAIL));
    $newPassword = $_POST['new_password'] ?? '';
    $errors = [];

    if (!empty($newEmail) && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    if (!empty($newEmail)) {
        $ck = $conn->prepare("SELECT userID FROM customers WHERE Email=? AND userID!=?");
        $ck->bind_param("si", $newEmail, $uid); $ck->execute();
        if ($ck->get_result()->num_rows > 0) $errors[] = "Email already exists for another account";
        $ck->close();
    }

    if (!empty($errors)) {
        echo '<script>alert("'.implode('\\n',$errors).'"); window.location="manage_employees.php";</script>'; exit();
    }

    $updates = []; $params = []; $types = '';
    if (!empty($newEmail))    { $updates[] = "Email=?";    $params[] = $newEmail;                              $types .= 's'; }
    if (!empty($newPassword)) { $updates[] = "Password=?"; $params[] = password_hash($newPassword, PASSWORD_DEFAULT); $types .= 's'; }

    if (!empty($updates)) {
        $params[] = $uid; $types .= 'i';
        $stmt = $conn->prepare("UPDATE customers SET ".implode(',',$updates)." WHERE userID=? AND Role='employee'");
        $stmt->bind_param($types, ...$params);
        echo $stmt->execute()
            ? '<script>alert("Employee updated successfully!"); window.location="manage_employees.php";</script>'
            : '<script>alert("Error updating employee."); window.location="manage_employees.php";</script>';
        $stmt->close();
    } else {
        echo '<script>alert("No changes made."); window.location="manage_employees.php";</script>';
    }
    exit();
}

// Fetch employees
$employees = $conn->query("SELECT * FROM customers WHERE Role='employee' ORDER BY created_at DESC");
$empCount  = $employees->num_rows;

$notifCount = $conn->query("SELECT COUNT(*) as u FROM notifications WHERE userID=$adminID AND is_read=0")->fetch_assoc()['u'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employees • Admin</title>
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

        /* ── ADD EMPLOYEE BTN ── */
        .btn-add { display: inline-flex; align-items: center; gap: 7px; padding: 10px 20px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(0,180,216,0.25); }
        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); color: var(--deep); }

        /* ── DATA CARD ── */
        .data-card { background: linear-gradient(145deg,rgba(10,45,74,0.5),rgba(3,15,30,0.75)); border: 1px solid var(--glass-border); border-radius: 17px; overflow: hidden; }
        .data-card-head { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 1px solid var(--glass-border); flex-wrap: wrap; gap: 10px; }
        .data-card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.18rem; font-weight: 500; color: var(--white); }
        .data-card-sub   { font-size: 0.75rem; color: rgba(202,240,248,0.35); margin-top: 2px; }
        .count-badge { background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); padding: 3px 10px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; }

        /* ── SEARCH BAR ── */
        .search-bar-wrap { padding: 14px 20px; border-bottom: 1px solid rgba(72,202,228,0.06); }
        .search-input { width: 100%; max-width: 340px; background: rgba(4,30,53,0.6); border: 1px solid var(--glass-border); color: var(--white); border-radius: 50px; padding: 9px 16px 9px 38px; font-size: 0.84rem; font-family: 'DM Sans', sans-serif; outline: none; transition: all 0.3s; }
        .search-input::placeholder { color: rgba(202,240,248,0.22); }
        .search-input:focus { border-color: var(--aqua); background: rgba(0,180,216,0.06); }
        .search-icon { position: absolute; left: 34px; top: 50%; transform: translateY(-50%); color: rgba(0,180,216,0.35); font-size: 0.78rem; }

        /* ── TABLE ── */
        .emp-table { width: 100%; border-collapse: collapse; }
        .emp-table th { font-size: 0.66rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.3); padding: 0 18px 12px; text-align: left; border-bottom: 1px solid var(--glass-border); }
        .emp-table td { padding: 15px 18px; font-size: 0.86rem; color: rgba(202,240,248,0.7); border-bottom: 1px solid rgba(72,202,228,0.06); vertical-align: middle; }
        .emp-table tr:last-child td { border-bottom: none; }
        .emp-table tr:hover td { background: rgba(0,180,216,0.03); color: var(--foam); }

        .emp-avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1px solid var(--glass-border); flex-shrink: 0; }
        .emp-initial { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.82rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .emp-name { font-weight: 500; color: var(--white); font-size: 0.88rem; }
        .emp-sub  { font-size: 0.72rem; color: rgba(202,240,248,0.35); margin-top: 1px; }

        /* ── ACTION BUTTONS ── */
        .btn-edit-sm { display: inline-flex; align-items: center; gap: 5px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 6px 14px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; cursor: pointer; transition: all 0.25s; }
        .btn-edit-sm:hover { background: rgba(0,180,216,0.15); border-color: rgba(0,180,216,0.3); }
        .btn-del-sm { display: inline-flex; align-items: center; gap: 5px; background: rgba(248,113,113,0.08); border: 1px solid rgba(248,113,113,0.22); color: var(--red); padding: 6px 14px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.25s; }
        .btn-del-sm:hover { background: rgba(248,113,113,0.18); color: var(--red); }

        /* ── EMPTY ── */
        .empty-state { text-align: center; padding: 60px 20px; color: rgba(202,240,248,0.3); }
        .empty-state i { font-size: 2.5rem; display: block; margin-bottom: 14px; color: rgba(0,180,216,0.15); }
        .empty-state p { font-size: 0.85rem; }

        /* ── MODAL ── */
        .modal-content { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 18px !important; }
        .modal-header { border-bottom: 1px solid var(--glass-border) !important; padding: 20px 24px !important; }
        .modal-footer { border-top: 1px solid var(--glass-border) !important; padding: 16px 24px !important; }
        .modal-body { padding: 24px !important; }
        .modal-title { font-family: 'Cormorant Garamond', serif !important; font-size: 1.3rem !important; font-weight: 500 !important; color: var(--white) !important; }
        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

        .field-group { margin-bottom: 18px; }
        .field-group:last-child { margin-bottom: 0; }
        .field-label { display: block; font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 7px; }
        .field-req  { color: var(--red); margin-left: 2px; }
        .field-input { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 11px 14px; border-radius: 11px; outline: none; transition: all 0.3s; }
        .field-input::placeholder { color: rgba(202,240,248,0.2); }
        .field-input:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }
        .field-hint { font-size: 0.72rem; color: rgba(202,240,248,0.28); margin-top: 5px; }

        .pw-wrap { position: relative; display: flex; gap: 8px; }
        .pw-wrap .field-input { flex: 1; }
        .btn-pw-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: rgba(202,240,248,0.4); border-radius: 10px; padding: 0 14px; cursor: pointer; transition: all 0.25s; font-size: 0.85rem; flex-shrink: 0; }
        .btn-pw-toggle:hover { border-color: var(--aqua); color: var(--aqua); }
        .btn-pw-gen { background: rgba(0,180,216,0.1); border: 1px solid rgba(0,180,216,0.25); color: var(--aqua); border-radius: 10px; padding: 0 14px; cursor: pointer; font-size: 0.76rem; font-weight: 600; flex-shrink: 0; transition: all 0.25s; white-space: nowrap; }
        .btn-pw-gen:hover { background: rgba(0,180,216,0.2); }

        /* pw strength hints */
        .pw-hints { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .pw-hint { display: inline-flex; align-items: center; gap: 4px; font-size: 0.7rem; color: rgba(202,240,248,0.28); padding: 3px 9px; border-radius: 50px; border: 1px solid rgba(202,240,248,0.08); transition: all 0.3s; }
        .pw-hint.met { color: var(--green); border-color: rgba(74,222,128,0.28); background: rgba(74,222,128,0.05); }

        .info-box { background: rgba(0,180,216,0.06); border: 1px solid rgba(0,180,216,0.18); border-radius: 10px; padding: 10px 14px; font-size: 0.8rem; color: rgba(202,240,248,0.5); display: flex; align-items: flex-start; gap: 8px; }
        .info-box i { color: var(--aqua); margin-top: 2px; flex-shrink: 0; }

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
        }

        @media (max-width: 576px) { .main-content { padding: 14px 12px; } }
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
        <a href="manage_orders.php"     class="nav-link"><i class="fas fa-shopping-cart"></i> Orders</a>
        <a href="manage_users.php"      class="nav-link"><i class="fas fa-users"></i> Users</a>
        <a href="manage_employees.php"  class="nav-link active"><i class="fas fa-user-tie"></i> Employees</a>
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
                <h4>Manage Employees</h4>
                <p>Add and manage refill &amp; delivery staff</p>
            </div>
        </div>

        <div class="topbar-right">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo min($notifCount,9).($notifCount>9?'+':'');?></span><?php endif; ?>
            </a>

            <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addEmpModal">
                <i class="fas fa-plus"></i> Add Employee
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

    <!-- Data Card -->
    <div class="data-card">
        <div class="data-card-head">
            <div>
                <div class="data-card-title">Employee Roster</div>
                <div class="data-card-sub">All active delivery and refill staff</div>
            </div>
            <span class="count-badge"><?php echo $empCount;?> Employee<?php echo $empCount!=1?'s':'';?></span>
        </div>

        <!-- Search -->
        <div class="search-bar-wrap" style="position:relative;">
            <i class="fas fa-search search-icon"></i>
            <input type="text" class="search-input" id="empSearch" placeholder="Search by name, email or phone…">
        </div>

        <?php if($empCount > 0): ?>
        <div style="overflow-x:auto;">
            <table class="emp-table" id="empTable">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Joined</th>
                        <th style="text-align:right;padding-right:22px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $employees->data_seek(0); while($emp = $employees->fetch_assoc()): ?>
                    <tr class="emp-row" data-search="<?php echo strtolower(htmlspecialchars($emp['Firstname'].' '.$emp['Lastname'].' '.$emp['Email'].' '.($emp['Contact']??'')));?>">
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <?php if(!empty($emp['profile_picture'])&&file_exists('../'.$emp['profile_picture'])): ?>
                                    <img src="../<?php echo htmlspecialchars($emp['profile_picture']);?>" class="emp-avatar" alt="">
                                <?php else: ?>
                                    <div class="emp-initial"><?php echo strtoupper(substr($emp['Firstname'],0,1).substr($emp['Lastname'],0,1));?></div>
                                <?php endif; ?>
                                <div>
                                    <div class="emp-name"><?php echo htmlspecialchars($emp['Firstname'].' '.$emp['Lastname']);?></div>
                                    <div class="emp-sub">#<?php echo str_pad($emp['userID'],5,'0',STR_PAD_LEFT);?></div>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($emp['Email']);?></td>
                        <td><?php echo htmlspecialchars($emp['Contact']??'—');?></td>
                        <td style="font-size:0.78rem;color:rgba(202,240,248,0.35);"><?php echo date('M j, Y', strtotime($emp['created_at']));?></td>
                        <td style="text-align:right;padding-right:18px;">
                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;">
                                <button class="btn-edit-sm" data-bs-toggle="modal" data-bs-target="#editEmpModal<?php echo $emp['userID'];?>">
                                    <i class="fas fa-pen"></i> Edit
                                </button>
                                <a href="manage_employees.php?delete=<?php echo $emp['userID'];?>"
                                   class="btn-del-sm"
                                   onclick="return confirm('Delete <?php echo htmlspecialchars($emp['Firstname']);?>? This cannot be undone.')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <div id="noResults" style="display:none;text-align:center;padding:40px 20px;color:rgba(202,240,248,0.3);font-size:0.85rem;">
            No employees match your search.
        </div>

        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-user-tie"></i>
            <p>No employees yet.<br>Click <strong>"Add Employee"</strong> to onboard your first staff member.</p>
        </div>
        <?php endif; ?>
    </div>

</main>

<!-- ── EDIT MODALS ── -->
<?php $employees->data_seek(0); while($emp = $employees->fetch_assoc()): ?>
<div class="modal fade" id="editEmpModal<?php echo $emp['userID'];?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="userID" value="<?php echo $emp['userID'];?>">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-pen me-2" style="color:var(--aqua);font-size:0.9rem;"></i>
                        Edit · <?php echo htmlspecialchars($emp['Firstname'].' '.$emp['Lastname']);?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="field-group">
                        <label class="field-label">New Email</label>
                        <input type="email" class="field-input" name="new_email" placeholder="<?php echo htmlspecialchars($emp['Email']);?>">
                        <div class="field-hint">Leave empty to keep current email</div>
                    </div>
                    <div class="field-group">
                        <label class="field-label">New Password</label>
                        <div class="pw-wrap">
                            <input type="password" class="field-input" name="new_password" id="epw_<?php echo $emp['userID'];?>" placeholder="Enter new password">
                            <button type="button" class="btn-pw-toggle" onclick="togglePw('epw_<?php echo $emp['userID'];?>',this)"><i class="fas fa-eye"></i></button>
                            <button type="button" class="btn-pw-gen" onclick="genPwEdit('epw_<?php echo $emp['userID'];?>')"><i class="fas fa-magic"></i> Generate</button>
                        </div>
                        <div class="field-hint">Leave empty to keep current password · Min 8 chars</div>
                    </div>
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        Employee will use the new credentials on next login.
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass-modal" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_employee" class="btn-save-modal">
                        <i class="fas fa-check me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endwhile; ?>

<!-- ── ADD EMPLOYEE MODAL ── -->
<div class="modal fade" id="addEmpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" id="addEmpForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2" style="color:var(--aqua);font-size:0.9rem;"></i>
                        Add New Employee
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">First Name<span class="field-req">*</span></label>
                                <input type="text" class="field-input" name="firstname" placeholder="Juan" required pattern="[A-Za-z\s]+" title="Letters only">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Last Name<span class="field-req">*</span></label>
                                <input type="text" class="field-input" name="lastname" placeholder="Dela Cruz" required pattern="[A-Za-z\s]+" title="Letters only">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Email Address<span class="field-req">*</span></label>
                                <input type="email" class="field-input" name="email" placeholder="juan@email.com" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Phone Number<span class="field-req">*</span></label>
                                <input type="tel" class="field-input" name="phone" placeholder="09XX XXX XXXX" required pattern="[0-9+\-\s]+" title="Numbers only">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="field-group mb-0">
                                <label class="field-label">Password<span class="field-req">*</span></label>
                                <div class="pw-wrap">
                                    <input type="password" class="field-input" name="password" id="newEmpPw" required minlength="8" placeholder="Min 8 chars, 1 uppercase, 1 number">
                                    <button type="button" class="btn-pw-toggle" onclick="togglePw('newEmpPw',this)"><i class="fas fa-eye"></i></button>
                                    <button type="button" class="btn-pw-gen" onclick="genPwNew()"><i class="fas fa-magic"></i> Generate</button>
                                </div>
                                <div class="pw-hints">
                                    <span class="pw-hint" id="hint-len"><i class="fas fa-circle" style="font-size:0.42rem;"></i> 8+ chars</span>
                                    <span class="pw-hint" id="hint-upper"><i class="fas fa-circle" style="font-size:0.42rem;"></i> Uppercase</span>
                                    <span class="pw-hint" id="hint-num"><i class="fas fa-circle" style="font-size:0.42rem;"></i> Number</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass-modal" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_employee" class="btn-save-modal">
                        <i class="fas fa-user-plus me-1"></i> Add Employee
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
    if(toggle)  toggle.addEventListener('click', openSidebar);
    if(overlay) overlay.addEventListener('click', closeSidebar);
    sidebar.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => { if(window.innerWidth<992) closeSidebar(); }));

    // ── SEARCH ──
    document.getElementById('empSearch')?.addEventListener('input', function() {
        const term = this.value.toLowerCase().trim();
        const rows = document.querySelectorAll('.emp-row');
        let vis = 0;
        rows.forEach(r => {
            const show = !term || r.dataset.search.includes(term);
            r.style.display = show ? '' : 'none';
            if(show) vis++;
        });
        const nr = document.getElementById('noResults');
        if(nr) nr.style.display = vis === 0 ? 'block' : 'none';
    });

    // ── PASSWORD TOGGLE ──
    function togglePw(id, btn) {
        const inp  = document.getElementById(id);
        const icon = btn.querySelector('i');
        inp.type   = inp.type === 'password' ? 'text' : 'password';
        icon.className = inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
    }

    // ── GENERATE PASSWORD (NEW) ──
    function genPwNew() {
        const pw  = `DeChavez${new Date().getFullYear()}${ '!@#'[Math.floor(Math.random()*3)] }${Math.floor(Math.random()*90)+10}`;
        const inp = document.getElementById('newEmpPw');
        inp.value = pw; inp.type = 'text';
        updatePwHints(pw);
        setTimeout(() => { inp.type = 'password'; }, 3000);
    }

    // ── GENERATE PASSWORD (EDIT) ──
    function genPwEdit(id) {
        const pw  = `DeChavez${new Date().getFullYear()}${ '!@#'[Math.floor(Math.random()*3)] }${Math.floor(Math.random()*90)+10}`;
        const inp = document.getElementById(id);
        inp.value = pw; inp.type = 'text';
        setTimeout(() => { inp.type = 'password'; }, 2500);
    }

    // ── PASSWORD HINTS ──
    function updatePwHints(pw) {
        setHint('hint-len',   pw.length >= 8);
        setHint('hint-upper', /[A-Z]/.test(pw));
        setHint('hint-num',   /[0-9]/.test(pw));
    }

    function setHint(id, met) {
        const el = document.getElementById(id);
        if(el) el.classList.toggle('met', met);
    }

    const newEmpPw = document.getElementById('newEmpPw');
    if(newEmpPw) newEmpPw.addEventListener('input', () => updatePwHints(newEmpPw.value));

    // ── CLIENT-SIDE FORM VALIDATION ──
    document.getElementById('addEmpForm')?.addEventListener('submit', function(e) {
        const pw = document.getElementById('newEmpPw')?.value ?? '';
        const errors = [];
        if(pw.length < 8)           errors.push('Password must be at least 8 characters');
        if(!/[A-Z]/.test(pw))       errors.push('Password must contain at least one uppercase letter');
        if(!/[0-9]/.test(pw))       errors.push('Password must contain at least one number');
        if(errors.length) { e.preventDefault(); alert(errors.join('\n')); }
    });
</script>
</body>
</html>