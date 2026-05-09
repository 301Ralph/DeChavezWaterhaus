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

$currentYear = date('Y');

// Leave balances
function getUsedDays($conn, $userID, $type, $year) {
    $q = $conn->prepare("SELECT SUM(total_days) as total FROM leaves WHERE userID = ? AND leave_type = ? AND status = 'Approved' AND YEAR(start_date) = ?");
    $q->bind_param("isi", $userID, $type, $year);
    $q->execute();
    $val = $q->get_result()->fetch_assoc()['total'] ?? 0;
    $q->close();
    return (int)$val;
}

$vacationUsed   = getUsedDays($conn, $userID, 'Vacation',  $currentYear);
$sickUsed       = getUsedDays($conn, $userID, 'Sick',      $currentYear);
$emergencyUsed  = getUsedDays($conn, $userID, 'Emergency', $currentYear);

$vacationTotal   = 15;
$sickTotal       = 10;
$emergencyTotal  = 5;

$vacationRemaining  = max(0, $vacationTotal  - $vacationUsed);
$sickRemaining      = max(0, $sickTotal      - $sickUsed);
$emergencyRemaining = max(0, $emergencyTotal - $emergencyUsed);

// Handle leave submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_leave'])) {
    $leave_type = $_POST['leave_type'];
    $start_date = $_POST['start_date'];
    $end_date   = $_POST['end_date'];
    $reason     = htmlspecialchars($_POST['reason']);

    $start      = new DateTime($start_date);
    $end        = new DateTime($end_date);
    $total_days = $end->diff($start)->days + 1;

    $canProceed = true;
    $errorMsg   = '';

    if ($leave_type == 'Vacation'  && $total_days > $vacationRemaining)  { $canProceed = false; $errorMsg = "You only have $vacationRemaining vacation days remaining!"; }
    if ($leave_type == 'Sick'      && $total_days > $sickRemaining)      { $canProceed = false; $errorMsg = "You only have $sickRemaining sick days remaining!"; }
    if ($leave_type == 'Emergency' && $total_days > $emergencyRemaining) { $canProceed = false; $errorMsg = "You only have $emergencyRemaining emergency days remaining!"; }

    if ($canProceed) {
        $stmt = $conn->prepare("INSERT INTO leaves (userID, leave_type, start_date, end_date, total_days, reason) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssis", $userID, $leave_type, $start_date, $end_date, $total_days, $reason);
        if ($stmt->execute()) {
            echo '<script>alert("Leave request submitted! Admin will review it soon."); window.location = "leave_request.php";</script>';
        } else {
            echo '<script>alert("Error submitting leave request.");</script>';
        }
        $stmt->close();
    } else {
        echo '<script>alert("' . $errorMsg . '"); window.location = "leave_request.php";</script>';
    }
    exit();
}

// Fetch leave history
$leavesStmt = $conn->prepare("SELECT * FROM leaves WHERE userID = ? ORDER BY created_at DESC");
$leavesStmt->bind_param("i", $userID);
$leavesStmt->execute();
$leaveResult = $leavesStmt->get_result();
$leavesStmt->close();

$totalLeaves   = $leaveResult->num_rows;
$pendingCount  = 0; $approvedCount = 0; $rejectedCount = 0;
$allLeaves     = [];
while ($l = $leaveResult->fetch_assoc()) {
    $allLeaves[] = $l;
    if ($l['status'] === 'Pending')  $pendingCount++;
    if ($l['status'] === 'Approved') $approvedCount++;
    if ($l['status'] === 'Rejected') $rejectedCount++;
}

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName  = explode(' ', $userName)[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Requests • De Chavez Waterhaus</title>
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

        /* ── NEW LEAVE BTN ── */
        .btn-new-leave { background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; color: var(--deep); padding: 10px 22px; border-radius: 50px; font-weight: 700; font-size: 0.82rem; letter-spacing: 0.08em; text-transform: uppercase; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(0,180,216,0.25); display: inline-flex; align-items: center; gap: 8px; }
        .btn-new-leave:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); color: var(--deep); }

        /* ── LEAVE BALANCE CARDS ── */
        .balance-card {
            border-radius: 18px;
            padding: 26px 24px;
            position: relative;
            overflow: hidden;
            transition: all 0.35s cubic-bezier(0.23,1,0.32,1);
            border: 1px solid;
        }

        .balance-card:hover { transform: translateY(-6px); box-shadow: 0 22px 50px rgba(0,0,0,0.35); }

        .balance-card::before { content: ''; position: absolute; bottom: -40px; right: -40px; width: 130px; height: 130px; border-radius: 50%; background: rgba(255,255,255,0.06); }
        .balance-card::after  { content: ''; position: absolute; top: -30px; left: -30px; width: 90px; height: 90px; border-radius: 50%; background: rgba(255,255,255,0.04); }

        .bc-vacation  { background: linear-gradient(135deg, rgba(0,119,182,0.35), rgba(0,180,216,0.2));  border-color: rgba(0,180,216,0.3); }
        .bc-sick      { background: linear-gradient(135deg, rgba(74,222,128,0.2), rgba(5,150,105,0.15)); border-color: rgba(74,222,128,0.3); }
        .bc-emergency { background: linear-gradient(135deg, rgba(244,200,66,0.2), rgba(180,83,9,0.15));  border-color: rgba(244,200,66,0.3); }

        .bc-type { font-size: 0.68rem; letter-spacing: 0.2em; text-transform: uppercase; opacity: 0.6; margin-bottom: 8px; position: relative; z-index: 1; }
        .bc-days { font-family: 'Cormorant Garamond', serif; font-size: 3.2rem; font-weight: 300; line-height: 1; color: var(--white); position: relative; z-index: 1; }
        .bc-sub  { font-size: 0.78rem; opacity: 0.55; margin-top: 4px; position: relative; z-index: 1; }

        .bc-icon { position: absolute; top: 22px; right: 24px; font-size: 2rem; opacity: 0.18; z-index: 0; }

        /* progress bar */
        .bc-bar-wrap { margin-top: 16px; position: relative; z-index: 1; }
        .bc-bar { height: 4px; background: rgba(255,255,255,0.12); border-radius: 2px; overflow: hidden; }
        .bc-fill { height: 100%; border-radius: 2px; background: rgba(255,255,255,0.6); transition: width 1s ease; }
        .bc-bar-label { display: flex; justify-content: space-between; font-size: 0.68rem; opacity: 0.5; margin-top: 5px; }

        /* ── STATS ROW ── */
        .mini-stat { background: linear-gradient(145deg,rgba(10,45,74,0.6),rgba(3,15,30,0.82)); border: 1px solid var(--glass-border); border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; transition: all 0.3s; }
        .mini-stat:hover { transform: translateY(-4px); border-color: rgba(0,180,216,0.25); }
        .ms-icon { width: 42px; height: 42px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
        .ms-i-total   { background: rgba(0,180,216,0.12);   color: var(--aqua); }
        .ms-i-pending { background: rgba(244,200,66,0.1);   color: var(--gold); }
        .ms-i-approved{ background: rgba(74,222,128,0.1);   color: var(--green); }
        .ms-i-rejected{ background: rgba(248,113,113,0.1);  color: var(--red); }
        .ms-num { font-family: 'Cormorant Garamond', serif; font-size: 1.7rem; font-weight: 600; color: var(--white); line-height: 1; }
        .ms-lbl { font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-top: 2px; }

        /* ── HISTORY TABLE ── */
        .history-card { background: linear-gradient(145deg,rgba(10,45,74,0.5),rgba(3,15,30,0.75)); border: 1px solid var(--glass-border); border-radius: 18px; overflow: hidden; }

        .history-header { display: flex; justify-content: space-between; align-items: center; padding: 22px 26px; border-bottom: 1px solid var(--glass-border); flex-wrap: wrap; gap: 12px; }
        .history-title { font-family: 'Cormorant Garamond', serif; font-size: 1.25rem; font-weight: 500; color: var(--white); }
        .history-sub   { font-size: 0.78rem; color: rgba(202,240,248,0.35); margin-top: 2px; }

        /* filter pills */
        .filter-pills { display: flex; gap: 6px; flex-wrap: wrap; }
        .filter-pill { padding: 5px 14px; border-radius: 50px; font-size: 0.75rem; font-weight: 500; border: 1px solid var(--glass-border); background: transparent; color: rgba(202,240,248,0.45); cursor: pointer; transition: all 0.25s; }
        .filter-pill:hover { color: var(--foam); }
        .filter-pill.active { background: linear-gradient(135deg, var(--teal), var(--aqua)); border-color: transparent; color: var(--deep); font-weight: 700; box-shadow: 0 4px 14px rgba(0,180,216,0.25); }

        .leave-table { width: 100%; border-collapse: collapse; }
        .leave-table th { font-size: 0.68rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.3); padding: 0 20px 14px; text-align: left; border-bottom: 1px solid var(--glass-border); }
        .leave-table td { padding: 16px 20px; font-size: 0.87rem; color: rgba(202,240,248,0.7); border-bottom: 1px solid rgba(72,202,228,0.06); vertical-align: middle; }
        .leave-table tr:last-child td { border-bottom: none; }
        .leave-table tr:hover td { background: rgba(0,180,216,0.03); color: var(--foam); }

        /* leave type badge */
        .type-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 50px; font-size: 0.75rem; font-weight: 600; }
        .tb-Vacation  { background: rgba(0,180,216,0.1);   color: var(--aqua);   border: 1px solid rgba(0,180,216,0.25); }
        .tb-Sick      { background: rgba(74,222,128,0.1);  color: var(--green);  border: 1px solid rgba(74,222,128,0.25); }
        .tb-Emergency { background: rgba(244,200,66,0.1);  color: var(--gold);   border: 1px solid rgba(244,200,66,0.25); }
        .tb-Other     { background: rgba(167,139,250,0.1); color: var(--violet); border: 1px solid rgba(167,139,250,0.25); }

        /* status pills */
        .s-pill { padding: 4px 12px; border-radius: 50px; font-size: 0.71rem; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; }
        .s-Approved { background: rgba(74,222,128,0.1);  color: var(--green); border: 1px solid rgba(74,222,128,0.25); }
        .s-Pending  { background: rgba(244,200,66,0.12); color: var(--gold);  border: 1px solid rgba(244,200,66,0.25); }
        .s-Rejected { background: rgba(248,113,113,0.1); color: var(--red);   border: 1px solid rgba(248,113,113,0.25); }

        .days-val { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; font-weight: 600; color: var(--white); }
        .reason-text { font-size: 0.82rem; color: rgba(202,240,248,0.45); max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* empty state */
        .empty-leave { text-align: center; padding: 64px 20px; color: rgba(202,240,248,0.3); }
        .empty-leave i { font-size: 2.5rem; display: block; margin-bottom: 14px; color: rgba(0,180,216,0.15); }
        .empty-leave p { font-size: 0.86rem; }

        /* no results */
        #noResults { display: none; text-align: center; padding: 40px 20px; color: rgba(202,240,248,0.3); font-size: 0.86rem; }

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
        .field-input, .field-select, .field-textarea { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 12px 15px; border-radius: 12px; outline: none; transition: all 0.3s; }
        .field-input::placeholder, .field-textarea::placeholder { color: rgba(202,240,248,0.2); }
        .field-input:focus, .field-select:focus, .field-textarea:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }
        .field-select option { background: var(--ocean); }
        .field-textarea { resize: vertical; min-height: 90px; line-height: 1.6; }

        /* leave summary in modal */
        .leave-summary-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: rgba(4,30,53,0.5); border-radius: 10px; margin-bottom: 8px; font-size: 0.85rem; }
        .ls-type  { color: var(--foam); font-weight: 500; }
        .ls-value { color: var(--aqua); font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; font-weight: 600; }

        .btn-glass { display: inline-flex; align-items: center; gap: 6px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 10px 20px; border-radius: 50px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-glass:hover { background: rgba(0,180,216,0.15); color: var(--foam); }
        .btn-submit { padding: 12px 28px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.84rem; font-weight: 700; letter-spacing: 0.08em; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 18px rgba(0,180,216,0.3); }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,180,216,0.5); }

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
            .bc-days { font-size: 2.5rem; }
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
        <a href="leave_request.php"      class="nav-link active"><i class="fas fa-calendar-alt"></i> Leave Requests</a>
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
                <h4>Leave Requests</h4>
                <p>Request time off and track your leave status</p>
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

            <button class="btn-new-leave" data-bs-toggle="modal" data-bs-target="#requestLeaveModal">
                <i class="fas fa-plus"></i> Request Leave
            </button>
        </div>
    </div>

    <!-- Leave Balance Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="balance-card bc-vacation">
                <i class="fas fa-umbrella-beach bc-icon"></i>
                <div class="bc-type">Vacation Leave</div>
                <div class="bc-days"><?php echo $vacationRemaining;?></div>
                <div class="bc-sub">days remaining · <?php echo $vacationUsed;?> of <?php echo $vacationTotal;?> used</div>
                <div class="bc-bar-wrap">
                    <div class="bc-bar"><div class="bc-fill" style="width:<?php echo $vacationTotal>0?round(($vacationUsed/$vacationTotal)*100):0;?>%;"></div></div>
                    <div class="bc-bar-label"><span>0</span><span><?php echo $vacationTotal;?> days total</span></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="balance-card bc-sick">
                <i class="fas fa-head-side-cough bc-icon"></i>
                <div class="bc-type">Sick Leave</div>
                <div class="bc-days"><?php echo $sickRemaining;?></div>
                <div class="bc-sub">days remaining · <?php echo $sickUsed;?> of <?php echo $sickTotal;?> used</div>
                <div class="bc-bar-wrap">
                    <div class="bc-bar"><div class="bc-fill" style="width:<?php echo $sickTotal>0?round(($sickUsed/$sickTotal)*100):0;?>%;"></div></div>
                    <div class="bc-bar-label"><span>0</span><span><?php echo $sickTotal;?> days total</span></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="balance-card bc-emergency">
                <i class="fas fa-triangle-exclamation bc-icon"></i>
                <div class="bc-type">Emergency Leave</div>
                <div class="bc-days"><?php echo $emergencyRemaining;?></div>
                <div class="bc-sub">days remaining · <?php echo $emergencyUsed;?> of <?php echo $emergencyTotal;?> used</div>
                <div class="bc-bar-wrap">
                    <div class="bc-bar"><div class="bc-fill" style="width:<?php echo $emergencyTotal>0?round(($emergencyUsed/$emergencyTotal)*100):0;?>%;"></div></div>
                    <div class="bc-bar-label"><span>0</span><span><?php echo $emergencyTotal;?> days total</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mini Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="mini-stat">
                <div class="ms-icon ms-i-total"><i class="fas fa-layer-group"></i></div>
                <div><div class="ms-num"><?php echo $totalLeaves;?></div><div class="ms-lbl">Total</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mini-stat">
                <div class="ms-icon ms-i-pending"><i class="fas fa-clock"></i></div>
                <div><div class="ms-num" style="color:var(--gold);"><?php echo $pendingCount;?></div><div class="ms-lbl">Pending</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mini-stat">
                <div class="ms-icon ms-i-approved"><i class="fas fa-check-circle"></i></div>
                <div><div class="ms-num" style="color:var(--green);"><?php echo $approvedCount;?></div><div class="ms-lbl">Approved</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mini-stat">
                <div class="ms-icon ms-i-rejected"><i class="fas fa-xmark-circle"></i></div>
                <div><div class="ms-num" style="color:var(--red);"><?php echo $rejectedCount;?></div><div class="ms-lbl">Rejected</div></div>
            </div>
        </div>
    </div>

    <!-- History -->
    <div class="history-card">
        <div class="history-header">
            <div>
                <div class="history-title">Leave History</div>
                <div class="history-sub"><?php echo $totalLeaves;?> request<?php echo $totalLeaves!=1?'s':'';?> total</div>
            </div>
            <div class="filter-pills">
                <button class="filter-pill active" onclick="filterLeaves('all', this)">All</button>
                <button class="filter-pill" onclick="filterLeaves('Pending', this)">Pending</button>
                <button class="filter-pill" onclick="filterLeaves('Approved', this)">Approved</button>
                <button class="filter-pill" onclick="filterLeaves('Rejected', this)">Rejected</button>
            </div>
        </div>

        <?php if(count($allLeaves) > 0): ?>
        <div style="overflow-x:auto;">
            <table class="leave-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Period</th>
                        <th>Days</th>
                        <th>Status</th>
                        <th>Reason</th>
                        <th>Filed</th>
                    </tr>
                </thead>
                <tbody id="leaveTableBody">
                    <?php foreach($allLeaves as $leave): ?>
                    <tr class="leave-row" data-status="<?php echo $leave['status'];?>">
                        <td><span class="type-badge tb-<?php echo $leave['leave_type'];?>"><?php echo $leave['leave_type'];?></span></td>
                        <td>
                            <div style="font-weight:500;color:var(--white);"><?php echo date('M j', strtotime($leave['start_date']));?> – <?php echo date('M j, Y', strtotime($leave['end_date']));?></div>
                        </td>
                        <td><span class="days-val"><?php echo $leave['total_days'];?></span><span style="font-size:0.75rem;color:rgba(202,240,248,0.38);"> day<?php echo $leave['total_days']!=1?'s':'';?></span></td>
                        <td><span class="s-pill s-<?php echo $leave['status'];?>"><?php echo $leave['status'];?></span></td>
                        <td><div class="reason-text"><?php echo htmlspecialchars($leave['reason']);?></div></td>
                        <td style="font-size:0.78rem;color:rgba(202,240,248,0.35);"><?php echo date('M j, Y', strtotime($leave['created_at']));?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="noResults">No leave requests match this filter.</div>

        <?php else: ?>
        <div class="empty-leave">
            <i class="fas fa-calendar-times"></i>
            <p>No leave requests yet.<br>Click <strong>"Request Leave"</strong> to file your first request.</p>
        </div>
        <?php endif; ?>
    </div>

</main>

<!-- ── REQUEST LEAVE MODAL ── -->
<div class="modal fade" id="requestLeaveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-plus me-2" style="color:var(--aqua);"></i>Request Leave
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Balance summary -->
                    <div style="margin-bottom:22px;">
                        <div style="font-size:0.68rem;letter-spacing:0.15em;text-transform:uppercase;color:rgba(202,240,248,0.3);margin-bottom:10px;">Your Remaining Balance</div>
                        <div class="leave-summary-row">
                            <span class="ls-type"><i class="fas fa-umbrella-beach me-2" style="color:var(--aqua);"></i>Vacation</span>
                            <span class="ls-value"><?php echo $vacationRemaining;?> days</span>
                        </div>
                        <div class="leave-summary-row">
                            <span class="ls-type"><i class="fas fa-head-side-cough me-2" style="color:var(--green);"></i>Sick</span>
                            <span class="ls-value" style="color:var(--green);"><?php echo $sickRemaining;?> days</span>
                        </div>
                        <div class="leave-summary-row" style="margin-bottom:0;">
                            <span class="ls-type"><i class="fas fa-triangle-exclamation me-2" style="color:var(--gold);"></i>Emergency</span>
                            <span class="ls-value" style="color:var(--gold);"><?php echo $emergencyRemaining;?> days</span>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Leave Type</label>
                        <select class="field-select" name="leave_type" required id="leaveTypeSelect">
                            <option value="Sick">Sick Leave</option>
                            <option value="Vacation">Vacation Leave</option>
                            <option value="Emergency">Emergency Leave</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <div class="field-group">
                                <label class="field-label">Start Date</label>
                                <input type="date" class="field-input" name="start_date" required min="<?php echo date('Y-m-d');?>" id="startDate">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="field-group">
                                <label class="field-label">End Date</label>
                                <input type="date" class="field-input" name="end_date" required min="<?php echo date('Y-m-d');?>" id="endDate">
                            </div>
                        </div>
                    </div>

                    <!-- Days preview -->
                    <div id="daysPreview" style="display:none;background:rgba(0,180,216,0.07);border:1px solid rgba(0,180,216,0.18);border-radius:10px;padding:10px 14px;margin-bottom:18px;font-size:0.84rem;color:rgba(202,240,248,0.65);">
                        <i class="fas fa-calendar-days me-2" style="color:var(--aqua);"></i>
                        Duration: <strong id="daysCount" style="color:var(--aqua);">— days</strong>
                    </div>

                    <div class="field-group mb-0">
                        <label class="field-label">Reason</label>
                        <textarea class="field-textarea" name="reason" placeholder="Briefly describe the reason for your leave…" required></textarea>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_leave" class="btn-submit">
                        <i class="fas fa-paper-plane me-2"></i>Submit Request
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

    // ── FILTER PILLS ──
    function filterLeaves(status, btn) {
        document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const rows   = document.querySelectorAll('.leave-row');
        let visible  = 0;

        rows.forEach(row => {
            const show = status === 'all' || row.dataset.status === status;
            row.style.display = show ? '' : 'none';
            if(show) visible++;
        });

        const noRes = document.getElementById('noResults');
        if(noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
    }

    // ── DAYS PREVIEW ──
    const startDate = document.getElementById('startDate');
    const endDate   = document.getElementById('endDate');
    const preview   = document.getElementById('daysPreview');
    const daysCount = document.getElementById('daysCount');

    function updateDaysPreview() {
        if(!startDate.value || !endDate.value) { preview.style.display = 'none'; return; }
        const s = new Date(startDate.value);
        const e = new Date(endDate.value);
        if(e < s) { endDate.value = startDate.value; return; }
        const days = Math.round((e - s) / (1000 * 60 * 60 * 24)) + 1;
        daysCount.textContent = days + ' day' + (days !== 1 ? 's' : '');
        preview.style.display = 'block';
    }

    if(startDate) startDate.addEventListener('change', function() { if(endDate.value && endDate.value < this.value) endDate.value = this.value; updateDaysPreview(); });
    if(endDate)   endDate.addEventListener('change', updateDaysPreview);
</script>
</body>
</html>