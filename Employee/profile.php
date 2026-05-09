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
                echo '<script>alert("Profile picture updated!"); window.location = "profile.php";</script>';
                exit();
            }
        } else {
            echo '<script>alert("Invalid file type. Only JPG, PNG, GIF allowed.");</script>';
        }
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $firstname = trim(htmlspecialchars($_POST['firstname']));
    $lastname  = trim(htmlspecialchars($_POST['lastname']));
    $email     = trim(filter_var($_POST['email'], FILTER_SANITIZE_EMAIL));
    $phone     = trim(htmlspecialchars($_POST['phone']));

    $errors = [];
    if (empty($firstname)) $errors[] = "First name is required";
    if (empty($lastname))  $errors[] = "Last name is required";
    if (empty($email))     $errors[] = "Email is required";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";

    if (!empty($errors)) {
        echo '<script>alert("' . implode('\\n', $errors) . '");</script>';
    } else {
        $update = $conn->prepare("UPDATE customers SET Firstname = ?, Lastname = ?, Email = ?, Contact = ? WHERE userID = ?");
        $update->bind_param("ssssi", $firstname, $lastname, $email, $phone, $userID);
        if ($update->execute()) {
            $_SESSION['userName'] = $firstname . ' ' . $lastname;
            echo '<script>alert("Profile updated successfully!"); window.location = "profile.php";</script>';
            exit();
        } else {
            echo '<script>alert("Error updating profile.");</script>';
        }
        $update->close();
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'];
    $new     = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    if (!password_verify($current, $employee['Password'])) {
        echo '<script>alert("Current password is incorrect.");</script>';
    } elseif ($new !== $confirm) {
        echo '<script>alert("New passwords do not match.");</script>';
    } elseif (strlen($new) < 8) {
        echo '<script>alert("New password must be at least 8 characters.");</script>';
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE customers SET Password = ? WHERE userID = ?");
        $update->bind_param("si", $hashed, $userID);
        $update->execute(); $update->close();
        echo '<script>alert("Password changed successfully!"); window.location = "profile.php";</script>';
        exit();
    }
}

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName  = explode(' ', $userName)[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile • De Chavez Waterhaus</title>
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

        /* ── AVATAR HERO CARD ── */
        .avatar-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.82));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            overflow: hidden;
        }

        .avatar-hero {
            background: linear-gradient(135deg, rgba(0,119,182,0.3), rgba(0,180,216,0.15));
            border-bottom: 1px solid rgba(0,180,216,0.15);
            padding: 36px 24px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .avatar-hero::before { content: ''; position: absolute; top: -50px; right: -50px; width: 150px; height: 150px; border-radius: 50%; background: rgba(0,180,216,0.07); }
        .avatar-hero::after  { content: ''; position: absolute; bottom: -40px; left: -40px; width: 100px; height: 100px; border-radius: 50%; background: rgba(0,119,182,0.06); }

        .avatar-hero-img {
            width: 96px; height: 96px; border-radius: 50%; object-fit: cover;
            border: 3px solid rgba(0,180,216,0.4);
            box-shadow: 0 0 28px rgba(0,180,216,0.2);
            margin-bottom: 14px;
            position: relative; z-index: 1;
        }

        .avatar-hero-initial {
            width: 96px; height: 96px; border-radius: 50%;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: var(--deep); font-family: 'Cormorant Garamond', serif;
            font-size: 2.6rem; font-weight: 300;
            display: flex; align-items: center; justify-content: center;
            border: 3px solid rgba(0,180,216,0.4);
            box-shadow: 0 0 28px rgba(0,180,216,0.2);
            margin: 0 auto 14px;
            position: relative; z-index: 1;
        }

        .avatar-hero-name { font-family: 'Cormorant Garamond', serif; font-size: 1.4rem; font-weight: 500; color: var(--white); margin-bottom: 4px; position: relative; z-index: 1; }
        .avatar-hero-role { font-size: 0.72rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.38); position: relative; z-index: 1; }

        .btn-change-photo {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(0,119,182,0.2); border: 1px solid rgba(0,180,216,0.3);
            color: var(--aqua); border-radius: 50px;
            padding: 7px 18px; font-size: 0.78rem; font-weight: 600;
            cursor: pointer; transition: all 0.3s; margin-top: 14px;
            position: relative; z-index: 1;
        }

        .btn-change-photo:hover { background: rgba(0,180,216,0.2); color: var(--white); }

        /* info rows */
        .info-rows { padding: 18px 22px; }
        .info-row  { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid rgba(72,202,228,0.07); }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-size: 0.77rem; color: rgba(202,240,248,0.38); }
        .info-value { font-size: 0.87rem; font-weight: 500; color: var(--foam); }

        .status-active { background: rgba(74,222,128,0.1); color: var(--green); border: 1px solid rgba(74,222,128,0.25); padding: 3px 10px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; }

        /* ── FORM CARDS ── */
        .form-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.55), rgba(3,15,30,0.78));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .form-card:last-child { margin-bottom: 0; }

        .form-card-head {
            padding: 18px 24px;
            border-bottom: 1px solid var(--glass-border);
            display: flex; align-items: center; gap: 10px;
        }

        .form-card-head-icon { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0; }
        .form-card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; font-weight: 500; color: var(--white); }
        .form-card-body { padding: 24px; }

        /* fields */
        .field-group { margin-bottom: 18px; }
        .field-group:last-child { margin-bottom: 0; }
        .field-label { display: block; font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 8px; }
        .field-input { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 12px 15px; border-radius: 12px; outline: none; transition: all 0.3s; }
        .field-input::placeholder { color: rgba(202,240,248,0.2); }
        .field-input:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }

        /* password eye toggle */
        .pw-wrap { position: relative; }
        .pw-toggle { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; color: rgba(202,240,248,0.3); cursor: pointer; font-size: 0.85rem; transition: color 0.2s; }
        .pw-toggle:hover { color: var(--aqua); }

        /* pw hints */
        .pw-hints { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
        .pw-hint { display: inline-flex; align-items: center; gap: 5px; font-size: 0.72rem; color: rgba(202,240,248,0.3); padding: 4px 10px; border-radius: 50px; border: 1px solid rgba(202,240,248,0.1); transition: all 0.3s; }
        .pw-hint.met { color: var(--green); border-color: rgba(74,222,128,0.3); background: rgba(74,222,128,0.06); }

        /* divider */
        .form-divider { height: 1px; background: var(--glass-border); margin: 20px 0; }

        /* action buttons */
        .btn-save {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 28px; background: linear-gradient(135deg, var(--teal), var(--aqua));
            border: none; border-radius: 50px; color: var(--deep);
            font-family: 'DM Sans', sans-serif; font-size: 0.83rem; font-weight: 700;
            letter-spacing: 0.08em; text-transform: uppercase; cursor: pointer; transition: all 0.3s;
            box-shadow: 0 5px 18px rgba(0,180,216,0.25);
        }

        .btn-save:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,180,216,0.45); }

        .btn-glass { display: inline-flex; align-items: center; gap: 6px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 10px 20px; border-radius: 50px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-glass:hover { background: rgba(0,180,216,0.15); color: var(--foam); }

        /* ── MODAL ── */
        .modal-content { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 20px !important; }
        .modal-header { border-bottom: 1px solid var(--glass-border) !important; padding: 22px 26px !important; }
        .modal-footer { border-top: 1px solid var(--glass-border) !important; padding: 18px 26px !important; }
        .modal-body { padding: 26px !important; }
        .modal-title { font-family: 'Cormorant Garamond', serif !important; font-size: 1.35rem !important; font-weight: 500 !important; color: var(--white) !important; }
        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

        .avatar-preview { width: 90px; height: 90px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(0,180,216,0.35); }
        .avatar-initial-modal { width: 90px; height: 90px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-family: 'Cormorant Garamond', serif; font-size: 2.4rem; display: flex; align-items: center; justify-content: center; margin: 0 auto; border: 2px solid rgba(0,180,216,0.35); }

        .btn-submit-modal { padding: 11px 26px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.84rem; font-weight: 700; letter-spacing: 0.08em; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 18px rgba(0,180,216,0.3); }
        .btn-submit-modal:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,180,216,0.5); }

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

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
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
        <a href="employee_dashboard.php" class="nav-link"><i class="fas fa-house"></i> Dashboard</a>
        <a href="attendance.php"         class="nav-link"><i class="fas fa-clock"></i> Attendance</a>
        <a href="payslip.php"            class="nav-link"><i class="fas fa-file-invoice-dollar"></i> My Payslip</a>
        <a href="leave_request.php"      class="nav-link"><i class="fas fa-calendar-alt"></i> Leave Requests</a>
        <a href="my_deliveries.php"      class="nav-link"><i class="fas fa-truck"></i> My Deliveries</a>
        <a href="profile.php"            class="nav-link active"><i class="fas fa-user"></i> My Profile</a>
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
                <h4>My Profile</h4>
                <p>Manage your account information</p>
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
                    if($notifs->num_rows > 0): while($n = $notifs->fetch_assoc()):
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

    <!-- Content -->
    <div class="row g-4">

        <!-- Left: Avatar Card -->
        <div class="col-lg-4">
            <div class="avatar-card">

                <!-- Hero section -->
                <div class="avatar-hero">
                    <?php if(!empty($employee['profile_picture'])&&file_exists('../'.$employee['profile_picture'])): ?>
                        <img src="../<?php echo htmlspecialchars($employee['profile_picture']);?>" class="avatar-hero-img" alt="">
                    <?php else: ?>
                        <div class="avatar-hero-initial"><?php echo strtoupper(substr($userName,0,1));?></div>
                    <?php endif; ?>

                    <div class="avatar-hero-name"><?php echo htmlspecialchars($employee['Firstname'].' '.$employee['Lastname']);?></div>
                    <div class="avatar-hero-role">Delivery Staff</div>

                    <button class="btn-change-photo" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal">
                        <i class="fas fa-camera"></i> Change Photo
                    </button>
                </div>

                <!-- Info rows -->
                <div class="info-rows">
                    <div class="info-row">
                        <span class="info-label">Employee ID</span>
                        <span class="info-value">#<?php echo str_pad($userID,5,'0',STR_PAD_LEFT);?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email</span>
                        <span class="info-value" style="font-size:0.82rem;"><?php echo htmlspecialchars($employee['Email']);?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Phone</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['Contact']??'—');?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Member Since</span>
                        <span class="info-value"><?php echo date('F Y', strtotime($employee['created_at']));?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Status</span>
                        <span class="status-active">Active</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Forms -->
        <div class="col-lg-8">

            <!-- Personal Information -->
            <div class="form-card">
                <div class="form-card-head">
                    <div class="form-card-head-icon"><i class="fas fa-user"></i></div>
                    <div class="form-card-title">Personal Information</div>
                </div>
                <div class="form-card-body">
                    <form method="POST">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="field-group">
                                    <label class="field-label">First Name</label>
                                    <input type="text" class="field-input" name="firstname"
                                           value="<?php echo htmlspecialchars($employee['Firstname']);?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="field-group">
                                    <label class="field-label">Last Name</label>
                                    <input type="text" class="field-input" name="lastname"
                                           value="<?php echo htmlspecialchars($employee['Lastname']);?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="field-group">
                                    <label class="field-label">Email Address</label>
                                    <input type="email" class="field-input" name="email"
                                           value="<?php echo htmlspecialchars($employee['Email']);?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="field-group">
                                    <label class="field-label">Phone Number</label>
                                    <input type="tel" class="field-input" name="phone"
                                           value="<?php echo htmlspecialchars($employee['Contact']??'');?>"
                                           placeholder="09XX XXX XXXX">
                                </div>
                            </div>
                        </div>

                        <div class="form-divider"></div>

                        <button type="submit" name="update_profile" class="btn-save">
                            <i class="fas fa-check"></i> Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Change Password -->
            <div class="form-card">
                <div class="form-card-head">
                    <div class="form-card-head-icon"><i class="fas fa-lock"></i></div>
                    <div class="form-card-title">Change Password</div>
                </div>
                <div class="form-card-body">
                    <form method="POST" id="pwForm">
                        <div class="field-group">
                            <label class="field-label">Current Password</label>
                            <div class="pw-wrap">
                                <input type="password" class="field-input" name="current_password" id="curPw" required style="padding-right:44px;">
                                <button type="button" class="pw-toggle" onclick="togglePw('curPw','eye0')">
                                    <i class="fas fa-eye" id="eye0"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="field-group">
                                    <label class="field-label">New Password</label>
                                    <div class="pw-wrap">
                                        <input type="password" class="field-input" name="new_password" id="newPw" required minlength="8" style="padding-right:44px;">
                                        <button type="button" class="pw-toggle" onclick="togglePw('newPw','eye1')">
                                            <i class="fas fa-eye" id="eye1"></i>
                                        </button>
                                    </div>
                                    <div class="pw-hints">
                                        <span class="pw-hint" id="hint-len"><i class="fas fa-circle" style="font-size:0.45rem;"></i> 8+ chars</span>
                                        <span class="pw-hint" id="hint-upper"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Uppercase</span>
                                        <span class="pw-hint" id="hint-num"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Number</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="field-group">
                                    <label class="field-label">Confirm Password</label>
                                    <div class="pw-wrap">
                                        <input type="password" class="field-input" name="confirm_password" id="confPw" required style="padding-right:44px;">
                                        <button type="button" class="pw-toggle" onclick="togglePw('confPw','eye2')">
                                            <i class="fas fa-eye" id="eye2"></i>
                                        </button>
                                    </div>
                                    <div class="pw-hints">
                                        <span class="pw-hint" id="hint-match"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Passwords match</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-divider"></div>

                        <button type="submit" name="change_password" class="btn-save" id="pwBtn" disabled style="opacity:0.4;cursor:not-allowed;">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </form>
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
                            <div class="avatar-initial-modal"><?php echo strtoupper(substr($userName,0,1));?></div>
                        <?php endif; ?>
                        <div style="font-size:0.73rem;color:rgba(202,240,248,0.3);margin-top:8px;">Current photo</div>
                    </div>
                    <div>
                        <label class="field-label" style="display:block;">Select New Photo</label>
                        <input type="file" class="field-input" name="profile_picture" accept="image/*" required style="padding:10px;">
                        <div style="font-size:0.72rem;color:rgba(202,240,248,0.3);margin-top:5px;">JPG, PNG, GIF · Max 2MB</div>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="upload_photo" class="btn-submit-modal">
                        <i class="fas fa-upload me-2"></i>Upload
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

    // ── PASSWORD TOGGLE ──
    function togglePw(id, iconId) {
        const inp  = document.getElementById(id);
        const icon = document.getElementById(iconId);
        inp.type   = inp.type === 'password' ? 'text' : 'password';
        icon.className = inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
    }

    // ── PASSWORD HINTS ──
    const newPw  = document.getElementById('newPw');
    const confPw = document.getElementById('confPw');
    const pwBtn  = document.getElementById('pwBtn');

    function updateHints() {
        const pw = newPw ? newPw.value : '';
        const hasLen   = pw.length >= 8;
        const hasUpper = /[A-Z]/.test(pw);
        const hasNum   = /[0-9]/.test(pw);
        const hasMatch = pw === (confPw ? confPw.value : '') && pw.length > 0;

        setHint('hint-len',   hasLen);
        setHint('hint-upper', hasUpper);
        setHint('hint-num',   hasNum);
        setHint('hint-match', hasMatch);

        const valid = hasLen && hasUpper && hasNum && hasMatch;
        if(pwBtn) {
            pwBtn.disabled         = !valid;
            pwBtn.style.opacity    = valid ? '1' : '0.4';
            pwBtn.style.cursor     = valid ? 'pointer' : 'not-allowed';
        }
    }

    function setHint(id, met) {
        const el = document.getElementById(id);
        if(el) el.classList.toggle('met', met);
    }

    if(newPw)  newPw.addEventListener('input', updateHints);
    if(confPw) confPw.addEventListener('input', updateHints);
</script>
</body>
</html>