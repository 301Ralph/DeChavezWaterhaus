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

// Check if already clocked in today
$today      = date('Y-m-d');
$clockCheck = $conn->prepare("SELECT * FROM attendance WHERE userID = ? AND DATE(clock_in) = ? AND clock_out IS NULL");
$clockCheck->bind_param("is", $userID, $today);
$clockCheck->execute();
$currentShift = $clockCheck->get_result()->fetch_assoc();
$clockCheck->close();
$isClockedIn = $currentShift !== null;

// Handle Clock In
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['clock_in'])) {
    if (date('w') == 0) {
        echo '<script>alert("Sunday is your rest day. You cannot clock in today."); window.location = "attendance.php";</script>';
        exit();
    }

    $today = date('Y-m-d');
    $checkFullDay = $conn->prepare("SELECT attendanceID FROM attendance WHERE userID = ? AND DATE(clock_in) = ? AND clock_out IS NOT NULL");
    $checkFullDay->bind_param("is", $userID, $today);
    $checkFullDay->execute();
    if ($checkFullDay->get_result()->num_rows > 0) {
        echo '<script>alert("You have already completed your shift today."); window.location = "attendance.php";</script>';
        $checkFullDay->close(); exit();
    }
    $checkFullDay->close();

    if ($isClockedIn) {
        echo '<script>alert("You are already clocked in!"); window.location = "attendance.php";</script>';
        exit();
    }

    if ((int)date('H') < 5) {
        echo '<script>alert("Clock-in opens at 5:00 AM."); window.location = "attendance.php";</script>';
        exit();
    }

    $clockInTime  = date('Y-m-d H:i:s');
    $currentTime  = date('H:i:s');
    $status       = ($currentTime > '10:00:00') ? 'Absent' : 'On Duty';

    $stmt = $conn->prepare("INSERT INTO attendance (userID, clock_in, status) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userID, $clockInTime, $status);

    if ($stmt->execute()) {
        $notifMsg = "You have successfully clocked in at " . date('g:i A');
        $conn->query("INSERT INTO notifications (userID, message) VALUES ($userID, '$notifMsg')");
        echo '<script>alert("Clocked in successfully! Status: ' . $status . '"); window.location = "attendance.php";</script>';
    } else {
        echo '<script>alert("Error clocking in."); window.location = "attendance.php";</script>';
    }
    $stmt->close(); exit();
}

// Handle Clock Out
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['clock_out'])) {
    if (!$isClockedIn) {
        echo '<script>alert("You are not clocked in!"); window.location = "attendance.php";</script>';
        exit();
    }

    $clockOutTime = date('Y-m-d H:i:s');
    $attendanceID = $currentShift['attendanceID'];

    $clockIn  = new DateTime($currentShift['clock_in']);
    $clockOut = new DateTime($clockOutTime);
    $interval = $clockIn->diff($clockOut);
    $totalHours = $interval->h + ($interval->i / 60);
    if ($totalHours > 10) $totalHours = 10;
    $paidHours = max(0, round($totalHours - 1.5, 2));

    $stmt = $conn->prepare("UPDATE attendance SET clock_out = ?, total_hours = ?, status = 'Completed' WHERE attendanceID = ?");
    $stmt->bind_param("sdi", $clockOutTime, $paidHours, $attendanceID);

    if ($stmt->execute()) {
        $notifMsg = "Clocked out successfully! Paid hours: $paidHours (Break deducted: 1.5 hrs)";
        $conn->query("INSERT INTO notifications (userID, message) VALUES ($userID, '$notifMsg')");
        echo '<script>alert("Clocked out! Paid Hours: ' . $paidHours . ' (break of 1.5 hrs deducted)"); window.location = "attendance.php";</script>';
    } else {
        echo '<script>alert("Error clocking out."); window.location = "attendance.php";</script>';
    }
    $stmt->close(); exit();
}

// Fetch attendance history (last 30 days)
$history = [];
$historyStmt = $conn->prepare("SELECT * FROM attendance WHERE userID = ? AND clock_in >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY clock_in DESC");
$historyStmt->bind_param("i", $userID);
$historyStmt->execute();
$result = $historyStmt->get_result();
while ($row = $result->fetch_assoc()) $history[] = $row;
$historyStmt->close();

// Monthly summary
$monthStmt = $conn->prepare("SELECT SUM(total_hours) as total FROM attendance WHERE userID = ? AND MONTH(clock_in) = MONTH(NOW()) AND YEAR(clock_in) = YEAR(NOW())");
$monthStmt->bind_param("i", $userID);
$monthStmt->execute();
$totalHoursMonth = $monthStmt->get_result()->fetch_assoc()['total'] ?? 0;
$monthStmt->close();

// Check if completed today
$completedCheck = $conn->prepare("SELECT attendanceID FROM attendance WHERE userID = ? AND DATE(clock_in) = ? AND clock_out IS NOT NULL");
$completedCheck->bind_param("is", $userID, $today);
$completedCheck->execute();
$hasCompletedToday = $completedCheck->get_result()->num_rows > 0;
$completedCheck->close();

$daysWorked    = count(array_filter($history, fn($r) => !empty($r['total_hours'])));
$hourlyRate    = $employee['hourly_rate'] ?? 100;
$estEarnings   = $totalHoursMonth * $hourlyRate;
$avgPerDay     = $daysWorked > 0 ? $totalHoursMonth / $daysWorked : 0;

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName  = explode(' ', $userName)[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;  --abyss: #030f1e;  --ocean: #041e35;  --navy:  #0a2d4a;
            --teal:  #0077b6;  --aqua:  #00b4d8;  --cyan:  #48cae4;  --glow:  #90e0ef;
            --foam:  #caf0f8;  --white: #f0f9ff;  --gold:  #f4c842;
            --green: #4ade80;  --red:   #f87171;
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

        /* ── CLOCK HERO CARD ── */
        .clock-hero {
            background: linear-gradient(145deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12));
            border: 1px solid rgba(0,180,216,0.28);
            border-radius: 22px;
            padding: 40px 32px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .clock-hero::before {
            content: '';
            position: absolute;
            top: -80px; right: -80px;
            width: 220px; height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0,180,216,0.12), transparent 70%);
        }

        .clock-hero::after {
            content: '';
            position: absolute;
            bottom: -60px; left: -60px;
            width: 180px; height: 180px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0,119,182,0.1), transparent 70%);
        }

        .clock-icon-ring {
            width: 80px; height: 80px;
            border-radius: 50%;
            background: rgba(0,180,216,0.1);
            border: 1px solid rgba(0,180,216,0.25);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            font-size: 1.8rem; color: var(--aqua);
            position: relative; z-index: 1;
            animation: clockPulse 3s ease-in-out infinite;
        }

        @keyframes clockPulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(0,180,216,0.3); }
            50%      { box-shadow: 0 0 0 12px rgba(0,180,216,0); }
        }

        .clock-date-label {
            font-size: 0.78rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: rgba(202,240,248,0.4);
            margin-bottom: 8px;
            position: relative; z-index: 1;
        }

        .clock-time {
            font-family: 'Cormorant Garamond', serif;
            font-size: 4rem;
            font-weight: 300;
            color: var(--white);
            line-height: 1;
            letter-spacing: -0.02em;
            position: relative; z-index: 1;
        }

        .clock-time .ampm {
            font-size: 1.5rem;
            color: var(--aqua);
            margin-left: 6px;
        }

        .clock-date {
            font-size: 0.9rem;
            color: rgba(202,240,248,0.5);
            margin-top: 8px;
            margin-bottom: 28px;
            position: relative; z-index: 1;
        }

        /* clocked-in info box */
        .clocked-in-box {
            background: rgba(74,222,128,0.08);
            border: 1px solid rgba(74,222,128,0.2);
            border-radius: 12px;
            padding: 12px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 0.88rem;
            color: var(--green);
            position: relative; z-index: 1;
        }

        .completed-box {
            background: rgba(167,139,250,0.08);
            border: 1px solid rgba(167,139,250,0.2);
            border-radius: 12px;
            padding: 12px 20px;
            margin-bottom: 20px;
            font-size: 0.86rem;
            color: #a78bfa;
            position: relative; z-index: 1;
        }

        /* clock buttons */
        .btn-clock-in {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 15px 44px;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border: none; border-radius: 50px;
            color: var(--deep); font-family: 'DM Sans', sans-serif;
            font-size: 0.92rem; font-weight: 800;
            letter-spacing: 0.14em; text-transform: uppercase;
            cursor: pointer; transition: all 0.3s;
            box-shadow: 0 8px 28px rgba(0,180,216,0.4);
            position: relative; z-index: 1;
        }

        .btn-clock-in:hover { transform: translateY(-3px); box-shadow: 0 14px 40px rgba(0,180,216,0.6); }

        .btn-clock-out {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 15px 44px;
            background: linear-gradient(135deg, #dc2626, #ef4444);
            border: none; border-radius: 50px;
            color: white; font-family: 'DM Sans', sans-serif;
            font-size: 0.92rem; font-weight: 800;
            letter-spacing: 0.14em; text-transform: uppercase;
            cursor: pointer; transition: all 0.3s;
            box-shadow: 0 8px 28px rgba(239,68,68,0.4);
            position: relative; z-index: 1;
        }

        .btn-clock-out:hover { transform: translateY(-3px); box-shadow: 0 14px 40px rgba(239,68,68,0.6); }

        .btn-disabled-clock {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 15px 44px;
            background: rgba(167,139,250,0.15);
            border: 1px solid rgba(167,139,250,0.3);
            border-radius: 50px;
            color: #a78bfa; font-family: 'DM Sans', sans-serif;
            font-size: 0.9rem; font-weight: 700;
            letter-spacing: 0.1em; text-transform: uppercase;
            position: relative; z-index: 1;
            cursor: not-allowed;
        }

        .clock-hint {
            font-size: 0.75rem;
            color: rgba(202,240,248,0.3);
            margin-top: 14px;
            letter-spacing: 0.05em;
            position: relative; z-index: 1;
        }

        /* ── SHIFT TIMELINE ── */
        .shift-timeline {
            margin-top: 24px;
            padding: 18px 20px;
            background: rgba(2,13,24,0.4);
            border-radius: 14px;
            border: 1px solid rgba(72,202,228,0.08);
            position: relative; z-index: 1;
        }

        .shift-tl-label { font-size: 0.68rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.3); margin-bottom: 12px; }

        .tl-bar {
            position: relative;
            height: 6px;
            background: rgba(72,202,228,0.1);
            border-radius: 3px;
            overflow: visible;
        }

        .tl-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--teal), var(--aqua));
            border-radius: 3px;
            transition: width 1s ease;
        }

        .tl-markers { display: flex; justify-content: space-between; margin-top: 6px; }
        .tl-marker { font-size: 0.65rem; color: rgba(202,240,248,0.3); }

        /* ── STAT CARDS ── */
        .stat-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.82));
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 22px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.3s;
        }

        .stat-card:hover { transform: translateY(-4px); border-color: rgba(0,180,216,0.25); box-shadow: 0 16px 40px rgba(0,0,0,0.3); }

        .stat-icon { width: 48px; height: 48px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .si-blue   { background: rgba(0,180,216,0.12); color: var(--aqua); }
        .si-green  { background: rgba(74,222,128,0.1); color: var(--green); }
        .si-gold   { background: rgba(244,200,66,0.1); color: var(--gold); }
        .si-violet { background: rgba(167,139,250,0.1); color: #a78bfa; }

        .stat-num { font-family: 'Cormorant Garamond', serif; font-size: 1.85rem; font-weight: 600; color: var(--white); line-height: 1; }
        .stat-lbl { font-size: 0.72rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-top: 3px; }

        /* ── HISTORY TABLE ── */
        .history-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.5), rgba(3,15,30,0.75));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            overflow: hidden;
        }

        .history-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 22px 26px;
            border-bottom: 1px solid var(--glass-border);
        }

        .history-title { font-family: 'Cormorant Garamond', serif; font-size: 1.25rem; font-weight: 500; color: var(--white); }
        .history-sub   { font-size: 0.78rem; color: rgba(202,240,248,0.35); margin-top: 2px; }

        .att-table { width: 100%; border-collapse: collapse; }

        .att-table th {
            font-size: 0.68rem; letter-spacing: 0.15em; text-transform: uppercase;
            color: rgba(202,240,248,0.3); padding: 0 20px 14px;
            text-align: left; border-bottom: 1px solid var(--glass-border);
        }

        .att-table td {
            padding: 15px 20px; font-size: 0.87rem;
            color: rgba(202,240,248,0.7);
            border-bottom: 1px solid rgba(72,202,228,0.06);
            vertical-align: middle;
        }

        .att-table tr:last-child td { border-bottom: none; }

        .att-table tr:hover td {
            background: rgba(0,180,216,0.03);
            color: var(--foam);
        }

        .day-name  { font-size: 0.75rem; color: rgba(202,240,248,0.35); }
        .date-bold { font-weight: 500; color: var(--white); }

        .hours-val { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; color: var(--green); font-weight: 600; }

        /* status pills */
        .s-pill { padding: 4px 12px; border-radius: 50px; font-size: 0.71rem; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; }
        .s-Completed { background: rgba(74,222,128,0.1);  color: var(--green); border: 1px solid rgba(74,222,128,0.25); }
        .s-On-Duty   { background: rgba(0,180,216,0.1);   color: var(--aqua);  border: 1px solid rgba(0,180,216,0.25); }
        .s-Absent    { background: rgba(248,113,113,0.1); color: #fca5a5;       border: 1px solid rgba(248,113,113,0.25); }
        .s-Late      { background: rgba(244,200,66,0.1);  color: var(--gold);   border: 1px solid rgba(244,200,66,0.25); }

        .on-duty-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(0,180,216,0.1); border: 1px solid rgba(0,180,216,0.25);
            border-radius: 50px; padding: 4px 12px;
            font-size: 0.72rem; color: var(--aqua); font-weight: 600;
        }

        .on-duty-dot {
            width: 6px; height: 6px; border-radius: 50%; background: var(--aqua);
            animation: blink 1.5s ease-in-out infinite;
        }

        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.3} }

        /* empty state */
        .empty-att { text-align: center; padding: 60px 20px; color: rgba(202,240,248,0.3); }
        .empty-att i { font-size: 2.5rem; display: block; margin-bottom: 14px; color: rgba(0,180,216,0.15); }
        .empty-att p { font-size: 0.86rem; }

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
            .clock-time { font-size: 3rem; }
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
        <a href="attendance.php"         class="nav-link active"><i class="fas fa-clock"></i> Attendance</a>
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
                <h4>Attendance</h4>
                <p>Clock in / out and track your working hours</p>
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
                        <li><a class="dropdown-item" href="notifications.php" style="font-size:0.83rem;white-space:normal;"><?php echo htmlspecialchars(mb_strimwidth($n['message'],0,70,'…'));?></a></li>
                    <?php endwhile; else: ?>
                        <li><span class="dropdown-item" style="color:rgba(202,240,248,0.35);font-size:0.83rem;">No notifications</span></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="notifications.php" style="text-align:center;font-size:0.8rem;color:var(--aqua);">View All</a></li>
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

    <!-- Top Row: Clock Hero + Stats -->
    <div class="row g-4 mb-4">

        <!-- Clock Hero -->
        <div class="col-lg-5">
            <div class="clock-hero">
                <div class="clock-icon-ring"><i class="fas fa-clock"></i></div>

                <div class="clock-date-label">Current Time</div>
                <div class="clock-time">
                    <span id="clockH">--</span>:<span id="clockM">--</span>:<span id="clockS">--</span>
                    <span class="ampm" id="clockAMPM">--</span>
                </div>
                <div class="clock-date"><?php echo date('l, F j, Y');?></div>

                <?php if($isClockedIn): ?>
                    <div class="clocked-in-box">
                        <span class="on-duty-dot"></span>
                        Clocked in at <strong><?php echo date('g:i A', strtotime($currentShift['clock_in']));?></strong>
                    </div>
                    <form method="POST">
                        <button type="submit" name="clock_out" class="btn-clock-out">
                            <i class="fas fa-sign-out-alt"></i> Clock Out
                        </button>
                    </form>

                <?php elseif($hasCompletedToday): ?>
                    <div class="completed-box">
                        <i class="fas fa-check-circle me-2"></i>
                        Shift completed for today. Great work, <?php echo htmlspecialchars($firstName);?>!
                    </div>
                    <div class="btn-disabled-clock">
                        <i class="fas fa-moon"></i> Duty Completed
                    </div>

                <?php else: ?>
                    <form method="POST">
                        <button type="submit" name="clock_in" class="btn-clock-in">
                            <i class="fas fa-sign-in-alt"></i> Clock In
                        </button>
                    </form>
                    <div class="clock-hint">
                        <i class="fas fa-info-circle me-1"></i>
                        Clock-in opens at 5:00 AM · Shift: 7:00 AM – 5:00 PM
                    </div>
                <?php endif; ?>

                <!-- Shift timeline bar -->
                <?php
                $shiftStart    = strtotime('07:00');
                $shiftEnd      = strtotime('17:00');
                $shiftDuration = $shiftEnd - $shiftStart;
                $now           = time();
                $nowInShift    = max(0, min($now - $shiftStart, $shiftDuration));
                $fillPct       = min(100, ($nowInShift / $shiftDuration) * 100);
                if ($fillPct < 0) $fillPct = 0;
                ?>
                <div class="shift-timeline">
                    <div class="shift-tl-label">Today's Shift Progress</div>
                    <div class="tl-bar">
                        <div class="tl-fill" style="width:<?php echo round($fillPct);?>%;"></div>
                    </div>
                    <div class="tl-markers">
                        <span class="tl-marker">7:00 AM</span>
                        <span class="tl-marker">12:00 PM</span>
                        <span class="tl-marker">5:00 PM</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="col-lg-7">
            <div class="row g-3">
                <div class="col-6">
                    <div class="stat-card">
                        <div class="stat-icon si-blue"><i class="fas fa-hourglass-half"></i></div>
                        <div>
                            <div class="stat-num"><?php echo number_format($totalHoursMonth, 1);?></div>
                            <div class="stat-lbl">Hours This Month</div>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="stat-card">
                        <div class="stat-icon si-green"><i class="fas fa-calendar-check"></i></div>
                        <div>
                            <div class="stat-num"><?php echo $daysWorked;?></div>
                            <div class="stat-lbl">Days Worked</div>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="stat-card">
                        <div class="stat-icon si-gold"><i class="fas fa-peso-sign"></i></div>
                        <div>
                            <div class="stat-num" style="font-size:1.5rem;">₱<?php echo number_format($estEarnings, 0);?></div>
                            <div class="stat-lbl">Est. Earnings</div>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="stat-card">
                        <div class="stat-icon si-violet"><i class="fas fa-chart-simple"></i></div>
                        <div>
                            <div class="stat-num"><?php echo number_format($avgPerDay, 1);?></div>
                            <div class="stat-lbl">Avg Hrs / Day</div>
                        </div>
                    </div>
                </div>

                <!-- Shift info panel -->
                <div class="col-12">
                    <div style="background:linear-gradient(145deg,rgba(10,45,74,0.55),rgba(3,15,30,0.75));border:1px solid var(--glass-border);border-radius:16px;padding:20px 22px;">
                        <div style="font-size:0.7rem;letter-spacing:0.15em;text-transform:uppercase;color:rgba(202,240,248,0.3);margin-bottom:14px;">Shift Rules</div>
                        <div class="row g-2">
                            <?php
                            $rules = [
                                ['fa-sun',        'Shift Hours',   '7:00 AM – 5:00 PM'],
                                ['fa-door-open',  'Clock-In Opens','5:00 AM'],
                                ['fa-mug-hot',    'Break Time',    '1.5 hrs deducted'],
                                ['fa-moon',       'Rest Day',      'Sundays'],
                            ];
                            foreach($rules as [$icon, $label, $val]):
                            ?>
                            <div class="col-6">
                                <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(4,30,53,0.5);border-radius:10px;border:1px solid rgba(72,202,228,0.07);">
                                    <i class="fas <?php echo $icon;?>" style="color:var(--aqua);font-size:0.9rem;width:16px;text-align:center;flex-shrink:0;"></i>
                                    <div>
                                        <div style="font-size:0.68rem;color:rgba(202,240,248,0.3);letter-spacing:0.08em;text-transform:uppercase;"><?php echo $label;?></div>
                                        <div style="font-size:0.84rem;color:var(--foam);font-weight:500;"><?php echo $val;?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- History Table -->
    <div class="history-card">
        <div class="history-header">
            <div>
                <div class="history-title">Attendance History</div>
                <div class="history-sub">Last 30 days · <?php echo count($history);?> record<?php echo count($history)!=1?'s':'';?></div>
            </div>
            <?php if($isClockedIn): ?>
                <span class="on-duty-badge">
                    <span class="on-duty-dot"></span>
                    On Duty
                </span>
            <?php endif; ?>
        </div>

        <?php if(count($history) > 0): ?>
        <div style="overflow-x:auto;">
            <table class="att-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Clock In</th>
                        <th>Clock Out</th>
                        <th>Paid Hours</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($history as $rec):
                        $statusKey = str_replace(' ', '-', $rec['status']);
                    ?>
                    <tr>
                        <td>
                            <div class="date-bold"><?php echo date('M j, Y', strtotime($rec['clock_in']));?></div>
                            <div class="day-name"><?php echo date('l', strtotime($rec['clock_in']));?></div>
                        </td>
                        <td><?php echo date('g:i A', strtotime($rec['clock_in']));?></td>
                        <td>
                            <?php if($rec['clock_out']): ?>
                                <?php echo date('g:i A', strtotime($rec['clock_out']));?>
                            <?php else: ?>
                                <span class="on-duty-badge"><span class="on-duty-dot"></span> On Duty</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if(!empty($rec['total_hours'])): ?>
                                <span class="hours-val"><?php echo number_format($rec['total_hours'],1);?> hrs</span>
                            <?php else: ?>
                                <span style="color:rgba(202,240,248,0.25);">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="s-pill s-<?php echo $statusKey;?>"><?php echo $rec['status'];?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-att">
            <i class="fas fa-clock"></i>
            <p>No attendance records in the last 30 days.<br>Clock in to start tracking your hours.</p>
        </div>
        <?php endif; ?>
    </div>

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
    sidebar.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => { if(window.innerWidth < 992) closeSidebar(); }));

    // ── LIVE CLOCK ──
    function updateClock() {
        const now  = new Date();
        let h      = now.getHours();
        const m    = String(now.getMinutes()).padStart(2, '0');
        const s    = String(now.getSeconds()).padStart(2, '0');
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;

        document.getElementById('clockH').textContent    = h;
        document.getElementById('clockM').textContent    = m;
        document.getElementById('clockS').textContent    = s;
        document.getElementById('clockAMPM').textContent = ampm;

        // Update shift progress bar
        const shiftStart = new Date(); shiftStart.setHours(7, 0, 0, 0);
        const shiftEnd   = new Date(); shiftEnd.setHours(17, 0, 0, 0);
        const total = shiftEnd - shiftStart;
        const elapsed = Math.max(0, now - shiftStart);
        const pct = Math.min(100, (elapsed / total) * 100);
        const fill = document.querySelector('.tl-fill');
        if(fill) fill.style.width = pct.toFixed(1) + '%';
    }

    setInterval(updateClock, 1000);
    updateClock();
</script>
</body>
</html>