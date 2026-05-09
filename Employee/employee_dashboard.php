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

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_photo'])) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $ext     = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            $newname     = 'employee_' . $userID . '_' . time() . '.' . $ext;
            $upload_path = '../uploads/profile_pictures/' . $newname;

            if (!is_dir('../uploads/profile_pictures/')) mkdir('../uploads/profile_pictures/', 0777, true);

            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                if (!empty($employee['profile_picture']) && file_exists('../' . $employee['profile_picture'])) {
                    unlink('../' . $employee['profile_picture']);
                }

                $update  = $conn->prepare("UPDATE customers SET profile_picture = ? WHERE userID = ?");
                $db_path = 'uploads/profile_pictures/' . $newname;
                $update->bind_param("si", $db_path, $userID);
                $update->execute(); $update->close();

                echo '<script>alert("Profile picture updated!"); window.location = "employee_dashboard.php";</script>';
                exit();
            }
        } else {
            echo '<script>alert("Invalid file type. Only JPG, PNG, GIF allowed.");</script>';
        }
    }
}

// Fetch stats (safe - catch if tables don't exist)
$assignedOrders     = 0;
$completedDeliveries = 0;

try {
    $r = $conn->query("SELECT COUNT(*) as count FROM deliveries d JOIN orders o ON d.orderID = o.orderID WHERE d.riderID = $userID AND o.status IN ('Pending','Processing','Out for Delivery')");
    if ($r) $assignedOrders = $r->fetch_assoc()['count'] ?? 0;
} catch (Exception $e) {}

try {
    $r = $conn->query("SELECT COUNT(*) as count FROM deliveries d JOIN orders o ON d.orderID = o.orderID WHERE d.riderID = $userID AND o.status = 'Delivered'");
    if ($r) $completedDeliveries = $r->fetch_assoc()['count'] ?? 0;
} catch (Exception $e) {}

// Today's attendance
$today      = date('Y-m-d');
$clockCheck = $conn->prepare("SELECT * FROM attendance WHERE userID = ? AND DATE(clock_in) = ? AND clock_out IS NULL");
$clockCheck->bind_param("is", $userID, $today);
$clockCheck->execute();
$currentShift = $clockCheck->get_result()->fetch_assoc();
$clockCheck->close();
$isClockedIn = $currentShift !== null;

$completedCheck = $conn->prepare("SELECT attendanceID FROM attendance WHERE userID = ? AND DATE(clock_in) = ? AND clock_out IS NOT NULL");
$completedCheck->bind_param("is", $userID, $today);
$completedCheck->execute();
$hasCompletedToday = $completedCheck->get_result()->num_rows > 0;
$completedCheck->close();

// Monthly hours
$monthStmt = $conn->prepare("SELECT SUM(total_hours) as total FROM attendance WHERE userID = ? AND MONTH(clock_in) = MONTH(NOW()) AND YEAR(clock_in) = YEAR(NOW())");
$monthStmt->bind_param("i", $userID);
$monthStmt->execute();
$totalHoursMonth = $monthStmt->get_result()->fetch_assoc()['total'] ?? 0;
$monthStmt->close();

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName  = explode(' ', $userName)[0];

$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$hourlyRate = $employee['hourly_rate'] ?? 100;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;  --abyss: #030f1e;  --ocean: #041e35;  --navy:  #0a2d4a;
            --teal:  #0077b6;  --aqua:  #00b4d8;  --cyan:  #48cae4;  --glow:  #90e0ef;
            --foam:  #caf0f8;  --white: #f0f9ff;  --gold:  #f4c842;
            --green: #4ade80;  --violet: #a78bfa;
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
        .sidebar-logo-sub { font-size: 0.68rem; color: rgba(202,240,248,0.3); letter-spacing: 0.1em; text-transform: uppercase; }
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

        /* ── WELCOME BANNER ── */
        .welcome-banner {
            background: linear-gradient(135deg, rgba(0,119,182,0.3), rgba(0,180,216,0.15));
            border: 1px solid rgba(0,180,216,0.25);
            border-radius: 20px;
            padding: 30px 36px;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
        }

        .welcome-banner::before { content: ''; position: absolute; top: -60px; right: -60px; width: 200px; height: 200px; border-radius: 50%; background: rgba(0,180,216,0.07); }
        .welcome-banner::after  { content: ''; position: absolute; bottom: -80px; right: 100px; width: 160px; height: 160px; border-radius: 50%; background: rgba(0,119,182,0.08); }

        .welcome-title { font-family: 'Cormorant Garamond', serif; font-size: 1.85rem; font-weight: 400; color: var(--white); margin-bottom: 6px; }
        .welcome-sub   { font-size: 0.88rem; color: rgba(202,240,248,0.55); }

        .status-orb {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 7px 16px; border-radius: 50px;
            font-size: 0.78rem; font-weight: 600;
            position: relative; z-index: 1;
            margin-top: 16px;
        }

        .orb-active   { background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.25); color: var(--green); }
        .orb-complete { background: rgba(167,139,250,0.1); border: 1px solid rgba(167,139,250,0.25); color: var(--violet); }
        .orb-idle     { background: var(--glass); border: 1px solid var(--glass-border); color: rgba(202,240,248,0.45); }

        .orb-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; animation: orbPulse 2s ease-in-out infinite; }
        @keyframes orbPulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.4;transform:scale(0.7)} }

        /* ── STAT CARDS ── */
        .stat-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.65), rgba(3,15,30,0.85));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 24px 22px;
            display: flex; align-items: center; gap: 18px;
            transition: all 0.35s cubic-bezier(0.23,1,0.32,1);
            position: relative; overflow: hidden;
        }

        .stat-card::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, rgba(0,180,216,0.3), transparent); opacity: 0; transition: opacity 0.3s; }
        .stat-card:hover { transform: translateY(-6px); border-color: rgba(0,180,216,0.28); box-shadow: 0 20px 48px rgba(0,0,0,0.35); }
        .stat-card:hover::after { opacity: 1; }

        .stat-icon { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .si-blue   { background: rgba(0,180,216,0.12); color: var(--aqua); }
        .si-green  { background: rgba(74,222,128,0.1); color: var(--green); }
        .si-gold   { background: rgba(244,200,66,0.1); color: var(--gold); }
        .si-violet { background: rgba(167,139,250,0.1); color: var(--violet); }

        .stat-num { font-family: 'Cormorant Garamond', serif; font-size: 2rem; font-weight: 600; color: var(--white); line-height: 1; }
        .stat-lbl { font-size: 0.73rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-top: 3px; }

        /* ── TODAY STATUS CARD ── */
        .today-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.55), rgba(3,15,30,0.78));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 26px;
        }

        .today-card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.2rem; font-weight: 500; color: var(--white); margin-bottom: 18px; }

        .clock-mini {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2.4rem; font-weight: 300;
            color: var(--white); line-height: 1;
            letter-spacing: -0.02em;
        }

        .clock-mini .ampm { font-size: 1rem; color: var(--aqua); margin-left: 4px; }

        .today-info-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid rgba(72,202,228,0.07); }
        .today-info-row:last-child { border-bottom: none; }
        .today-info-label { font-size: 0.78rem; color: rgba(202,240,248,0.4); }
        .today-info-value { font-size: 0.88rem; font-weight: 500; color: var(--foam); }

        /* quick clock btn in today card */
        .btn-quick-clock {
            width: 100%; padding: 12px;
            border: none; border-radius: 50px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.83rem; font-weight: 700;
            letter-spacing: 0.1em; text-transform: uppercase;
            cursor: pointer; transition: all 0.3s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            text-decoration: none; margin-top: 18px;
        }

        .btn-qc-in  { background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); box-shadow: 0 5px 18px rgba(0,180,216,0.3); }
        .btn-qc-in:hover  { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,180,216,0.5); color: var(--deep); }
        .btn-qc-out { background: linear-gradient(135deg, #dc2626, #ef4444); color: white; box-shadow: 0 5px 18px rgba(239,68,68,0.3); }
        .btn-qc-out:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(239,68,68,0.5); }
        .btn-qc-done { background: rgba(167,139,250,0.12); border: 1px solid rgba(167,139,250,0.25); color: var(--violet); cursor: not-allowed; }

        /* ── QUICK ACTIONS ── */
        .qa-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }

        .qa-btn {
            display: flex; align-items: center; gap: 14px;
            padding: 18px 18px;
            background: rgba(4,30,53,0.55);
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            text-decoration: none;
            color: var(--foam);
            transition: all 0.3s;
            cursor: pointer;
        }

        .qa-btn:hover { background: var(--glass); border-color: rgba(0,180,216,0.3); color: var(--white); transform: translateX(3px); }

        .qa-icon { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); display: flex; align-items: center; justify-content: center; font-size: 0.95rem; flex-shrink: 0; }
        .qa-icon.red { background: linear-gradient(135deg, #dc2626, #ef4444); }
        .qa-icon.violet { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .qa-icon.gold { background: linear-gradient(135deg, #b45309, #f4c842); }

        .qa-label { font-size: 0.87rem; font-weight: 500; }
        .qa-sub   { font-size: 0.73rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .qa-arrow { margin-left: auto; color: rgba(0,180,216,0.3); font-size: 0.75rem; transition: all 0.3s; }
        .qa-btn:hover .qa-arrow { color: var(--aqua); transform: translateX(3px); }

        /* ── MODAL ── */
        .modal-content { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 20px !important; }
        .modal-header { border-bottom: 1px solid var(--glass-border) !important; padding: 22px 26px !important; }
        .modal-footer { border-top: 1px solid var(--glass-border) !important; padding: 18px 26px !important; }
        .modal-body { padding: 26px !important; }
        .modal-title { font-family: 'Cormorant Garamond', serif !important; font-size: 1.35rem !important; font-weight: 500 !important; color: var(--white) !important; }
        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

        .field-label { display: block; font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 8px; }
        .field-input { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 12px 15px; border-radius: 12px; outline: none; transition: all 0.3s; }
        .field-input:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }

        .btn-glass { display: inline-flex; align-items: center; gap: 6px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 10px 20px; border-radius: 50px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-glass:hover { background: rgba(0,180,216,0.15); color: var(--foam); }

        .btn-submit { padding: 12px 28px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.84rem; font-weight: 700; letter-spacing: 0.08em; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 18px rgba(0,180,216,0.3); }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,180,216,0.5); }

        /* avatar preview in modal */
        .avatar-preview { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(0,180,216,0.35); box-shadow: 0 0 20px rgba(0,180,216,0.15); }
        .avatar-initial-lg { width: 100px; height: 100px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-family: 'Cormorant Garamond', serif; font-size: 2.8rem; display: flex; align-items: center; justify-content: center; margin: 0 auto; border: 2px solid rgba(0,180,216,0.35); }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 40px; height: 40px; border-radius: 10px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.9rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px; }
            .mobile-toggle { display: flex; }
            .qa-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
            .welcome-banner { padding: 22px 20px; }
            .welcome-title { font-size: 1.5rem; }
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
            <div class="sidebar-logo-sub">Employee Portal</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="employee_dashboard.php" class="nav-link active"><i class="fas fa-house"></i> Dashboard</a>
        <a href="attendance.php"         class="nav-link"><i class="fas fa-clock"></i> Attendance</a>
        <a href="payslip.php"            class="nav-link"><i class="fas fa-file-invoice-dollar"></i> My Payslip</a>
        <a href="leave_request.php"      class="nav-link"><i class="fas fa-calendar-alt"></i> Leave Requests</a>
        <a href="my_deliveries.php"      class="nav-link"><i class="fas fa-truck"></i> My Deliveries</a>
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
                <h4><?php echo $greeting;?>, <?php echo htmlspecialchars($firstName);?>!</h4>
                <p>Welcome back to your employee portal</p>
            </div>
        </div>

        <div class="topbar-right">
            <!-- Notifications -->
            <div class="dropdown">
                <button class="topbar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-bell"></i>
                    <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo min($notifCount,9).($notifCount>9?'+':'');?></span><?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="min-width:300px;max-height:360px;overflow-y:auto;">
                    <li style="padding:12px 16px 8px;font-size:0.7rem;letter-spacing:0.15em;text-transform:uppercase;color:rgba(202,240,248,0.3);">Notifications</li>
                    <?php
                    $notifs = $conn->query("SELECT * FROM notifications WHERE userID = $userID ORDER BY created_at DESC LIMIT 5");
                    if($notifs->num_rows > 0):
                        while($n = $notifs->fetch_assoc()):
                    ?>
                        <li>
                            <a class="dropdown-item" href="notifications.php" style="font-size:0.83rem;white-space:normal;padding:10px 14px !important;">
                                <div style="display:flex;gap:10px;">
                                    <i class="fas fa-bell" style="color:var(--aqua);margin-top:2px;flex-shrink:0;font-size:0.8rem;"></i>
                                    <div>
                                        <div><?php echo htmlspecialchars(mb_strimwidth($n['message'],0,70,'…'));?></div>
                                        <div style="font-size:0.7rem;color:rgba(202,240,248,0.3);margin-top:2px;"><?php echo date('M j, g:i A', strtotime($n['created_at']));?></div>
                                    </div>
                                </div>
                            </a>
                        </li>
                    <?php endwhile; else: ?>
                        <li><span class="dropdown-item" style="color:rgba(202,240,248,0.35);font-size:0.83rem;">You're all caught up!</span></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="notifications.php" style="text-align:center;font-size:0.8rem;color:var(--aqua);">View All Notifications</a></li>
                </ul>
            </div>

            <!-- Avatar -->
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

    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div class="row align-items-center">
            <div class="col-md-8" style="position:relative;z-index:1;">
                <div class="welcome-title">Welcome back, <?php echo htmlspecialchars($firstName);?>!</div>
                <div class="welcome-sub">You're doing great work delivering clean water to our customers. Keep it up!</div>

                <?php if($isClockedIn): ?>
                    <div class="status-orb orb-active">
                        <span class="orb-dot"></span>
                        On Duty · Clocked in at <?php echo date('g:i A', strtotime($currentShift['clock_in']));?>
                    </div>
                <?php elseif($hasCompletedToday): ?>
                    <div class="status-orb orb-complete">
                        <i class="fas fa-check-circle" style="font-size:0.75rem;"></i>
                        Shift Completed for Today
                    </div>
                <?php else: ?>
                    <div class="status-orb orb-idle">
                        <i class="fas fa-moon" style="font-size:0.75rem;"></i>
                        Not Clocked In
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0" style="position:relative;z-index:1;">
                <i class="fas fa-droplet" style="font-size:4rem;color:rgba(0,180,216,0.15);"></i>
            </div>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="fas fa-truck"></i></div>
                <div>
                    <div class="stat-num"><?php echo $assignedOrders;?></div>
                    <div class="stat-lbl">Assigned Orders</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="fas fa-check-circle"></i></div>
                <div>
                    <div class="stat-num"><?php echo $completedDeliveries;?></div>
                    <div class="stat-lbl">Completed</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon si-gold"><i class="fas fa-hourglass-half"></i></div>
                <div>
                    <div class="stat-num"><?php echo number_format($totalHoursMonth,1);?></div>
                    <div class="stat-lbl">Hours This Month</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon si-violet"><i class="fas fa-peso-sign"></i></div>
                <div>
                    <div class="stat-num" style="font-size:1.5rem;">₱<?php echo number_format($totalHoursMonth*$hourlyRate,0);?></div>
                    <div class="stat-lbl">Est. Earnings</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Row -->
    <div class="row g-4">

        <!-- Today's Status Card -->
        <div class="col-lg-4">
            <div class="today-card">
                <div class="today-card-title">Today's Status</div>

                <div class="text-center mb-4">
                    <div class="clock-mini">
                        <span id="clockH">--</span>:<span id="clockM">--</span>:<span id="clockS">--</span>
                        <span class="ampm" id="clockAMPM">--</span>
                    </div>
                    <div style="font-size:0.78rem;color:rgba(202,240,248,0.35);margin-top:6px;"><?php echo date('l, F j, Y');?></div>
                </div>

                <div class="today-info-row">
                    <span class="today-info-label">Clock In</span>
                    <span class="today-info-value">
                        <?php echo $isClockedIn ? date('g:i A', strtotime($currentShift['clock_in'])) : ($hasCompletedToday ? '✓ Done' : '—');?>
                    </span>
                </div>
                <div class="today-info-row">
                    <span class="today-info-label">Status</span>
                    <span class="today-info-value" style="color:<?php echo $isClockedIn ? 'var(--green)' : ($hasCompletedToday ? 'var(--violet)' : 'rgba(202,240,248,0.35)');?>">
                        <?php echo $isClockedIn ? 'On Duty' : ($hasCompletedToday ? 'Shift Complete' : 'Not Clocked In');?>
                    </span>
                </div>
                <div class="today-info-row">
                    <span class="today-info-label">Hourly Rate</span>
                    <span class="today-info-value" style="color:var(--gold);">₱<?php echo number_format($hourlyRate,2);?>/hr</span>
                </div>

                <?php if($isClockedIn): ?>
                    <form method="POST">
                        <button type="submit" name="clock_out_dash" class="btn-quick-clock btn-qc-out">
                            <i class="fas fa-sign-out-alt"></i> Clock Out
                        </button>
                    </form>
                <?php elseif($hasCompletedToday): ?>
                    <div class="btn-quick-clock btn-qc-done">
                        <i class="fas fa-moon"></i> Duty Completed
                    </div>
                <?php else: ?>
                    <a href="attendance.php" class="btn-quick-clock btn-qc-in">
                        <i class="fas fa-sign-in-alt"></i> Go to Clock In
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="col-lg-8">
            <div style="background:linear-gradient(145deg,rgba(10,45,74,0.55),rgba(3,15,30,0.78));border:1px solid var(--glass-border);border-radius:18px;padding:26px;">
                <div style="font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:500;color:var(--white);margin-bottom:18px;">Quick Actions</div>

                <div class="qa-grid">
                    <a href="attendance.php" class="qa-btn">
                        <div class="qa-icon"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="qa-label">Attendance</div>
                            <div class="qa-sub">Clock in / out for today</div>
                        </div>
                        <i class="fas fa-chevron-right qa-arrow"></i>
                    </a>

                    <a href="my_deliveries.php" class="qa-btn">
                        <div class="qa-icon"><i class="fas fa-truck"></i></div>
                        <div>
                            <div class="qa-label">My Deliveries</div>
                            <div class="qa-sub">View assigned orders</div>
                        </div>
                        <i class="fas fa-chevron-right qa-arrow"></i>
                    </a>

                    <a href="payslip.php" class="qa-btn">
                        <div class="qa-icon gold"><i class="fas fa-file-invoice-dollar"></i></div>
                        <div>
                            <div class="qa-label">My Payslip</div>
                            <div class="qa-sub">View earnings & deductions</div>
                        </div>
                        <i class="fas fa-chevron-right qa-arrow"></i>
                    </a>

                    <a href="leave_request.php" class="qa-btn">
                        <div class="qa-icon violet"><i class="fas fa-calendar-alt"></i></div>
                        <div>
                            <div class="qa-label">Leave Requests</div>
                            <div class="qa-sub">File or view leave status</div>
                        </div>
                        <i class="fas fa-chevron-right qa-arrow"></i>
                    </a>

                    <button class="qa-btn" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal">
                        <div class="qa-icon"><i class="fas fa-camera"></i></div>
                        <div>
                            <div class="qa-label">Update Photo</div>
                            <div class="qa-sub">Change profile picture</div>
                        </div>
                        <i class="fas fa-chevron-right qa-arrow"></i>
                    </button>

                    <a href="../logout.php" class="qa-btn">
                        <div class="qa-icon red"><i class="fas fa-sign-out-alt"></i></div>
                        <div>
                            <div class="qa-label">Logout</div>
                            <div class="qa-sub">End your session</div>
                        </div>
                        <i class="fas fa-chevron-right qa-arrow"></i>
                    </a>
                </div>
            </div>
        </div>

    </div>
</main>

<!-- ── UPLOAD PHOTO MODAL ── -->
<div class="modal fade" id="uploadPhotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-camera me-2" style="color:var(--aqua);"></i>Update Profile Photo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <?php if(!empty($employee['profile_picture'])&&file_exists('../'.$employee['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($employee['profile_picture']);?>" class="avatar-preview" alt="">
                        <?php else: ?>
                            <div class="avatar-initial-lg"><?php echo strtoupper(substr($userName,0,1));?></div>
                        <?php endif; ?>
                        <div style="font-size:0.75rem;color:rgba(202,240,248,0.35);margin-top:10px;">Current profile picture</div>
                    </div>

                    <div>
                        <label class="field-label">Select New Photo</label>
                        <input type="file" class="field-input" name="profile_picture" accept="image/*" required style="padding:10px;">
                        <div style="font-size:0.72rem;color:rgba(202,240,248,0.3);margin-top:6px;">Allowed: JPG, PNG, GIF · Max 2MB</div>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="upload_photo" class="btn-submit">
                        <i class="fas fa-upload me-2"></i>Upload Photo
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

    // ── LIVE CLOCK ──
    function updateClock() {
        const now  = new Date();
        let h      = now.getHours();
        const m    = String(now.getMinutes()).padStart(2,'0');
        const s    = String(now.getSeconds()).padStart(2,'0');
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        document.getElementById('clockH').textContent    = h;
        document.getElementById('clockM').textContent    = m;
        document.getElementById('clockS').textContent    = s;
        document.getElementById('clockAMPM').textContent = ampm;
    }

    setInterval(updateClock, 1000);
    updateClock();
</script>
</body>
</html>