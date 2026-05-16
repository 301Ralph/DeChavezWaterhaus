<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType    = $_SESSION['flash_type']    ?? 'info';
if ($flashMessage) { unset($_SESSION['flash_message'], $_SESSION['flash_type']); }

$adminID   = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';
$admin     = $conn->query("SELECT * FROM customers WHERE userID = $adminID")->fetch_assoc();

$employees  = $conn->query("SELECT userID, Firstname, Lastname, Email FROM customers WHERE Role = 'employee' ORDER BY Firstname, Lastname");
$allPayrolls = $conn->query("SELECT p.*, c.Firstname, c.Lastname, c.Email FROM payroll p JOIN customers c ON p.userID = c.userID WHERE c.Role = 'employee' ORDER BY c.Firstname, c.Lastname, p.period_end DESC");

$payslipHTML    = null;
$selectedPayroll = null;

// Handle payslip generation (preview)
if (isset($_POST['generate_payslip'])) {
    $uid         = intval($_POST['userID']);
    $periodStart = $_POST['period_start'];
    $periodEnd   = $_POST['period_end'];

    $ck = $conn->prepare("SELECT payrollID FROM payroll WHERE userID=? AND period_start=? AND period_end=?");
    $ck->bind_param("iss", $uid, $periodStart, $periodEnd);
    $ck->execute();
    $exists = $ck->get_result()->num_rows > 0;
    $ck->close();

    if ($exists) {
        $_SESSION['flash_message'] = "Payslip already exists for this employee and period!";
        $_SESSION['flash_type'] = "warning";
        header("Location: generate_payslip.php"); exit();
    }

    $ps = $conn->prepare("SELECT p.*, c.Firstname, c.Lastname, c.Email FROM payroll p JOIN customers c ON p.userID=c.userID WHERE p.userID=? AND p.period_start=? AND p.period_end=?");
    $ps->bind_param("iss", $uid, $periodStart, $periodEnd);
    $ps->execute();
    $payroll = $ps->get_result()->fetch_assoc();
    $ps->close();

    if ($payroll) {
        $payslipHTML    = generatePayslipHTML($payroll, $periodStart, $periodEnd);
        $selectedPayroll = $payroll;
    } else {
        $_SESSION['flash_message'] = "No payroll record found for the selected period.";
        $_SESSION['flash_type'] = "error";
        header("Location: generate_payslip.php"); exit();
    }
}

// View specific payslip
if (isset($_GET['view_payslip'])) {
    $pid = intval($_GET['view_payslip']);
    $ps  = $conn->prepare("SELECT p.*, c.Firstname, c.Lastname, c.Email FROM payroll p JOIN customers c ON p.userID=c.userID WHERE p.payrollID=?");
    $ps->bind_param("i", $pid);
    $ps->execute();
    $payroll = $ps->get_result()->fetch_assoc();
    $ps->close();
    if ($payroll) { $payslipHTML = generatePayslipHTML($payroll, $payroll['period_start'], $payroll['period_end']); $selectedPayroll = $payroll; }
}

// Bulk generation
if (isset($_POST['bulk_generate'])) {
    $selected    = $_POST['selected_employees'] ?? [];
    $periodStart = $_POST['bulk_period_start'];
    $periodEnd   = $_POST['bulk_period_end'];
    $successCount = 0; $errorCount = 0; $errors = [];

    foreach ($selected as $uid) {
        $uid = intval($uid);
        $ck  = $conn->prepare("SELECT payrollID FROM payroll WHERE userID=? AND period_start=? AND period_end=?");
        $ck->bind_param("iss", $uid, $periodStart, $periodEnd);
        $ck->execute();
        $exists = $ck->get_result()->num_rows > 0; $ck->close();

        if ($exists) {
            $ns = $conn->prepare("SELECT CONCAT(Firstname,' ',Lastname) as n FROM customers WHERE userID=?");
            $ns->bind_param("i", $uid);
            $ns->execute();
            $nm = $ns->get_result()->fetch_assoc()['n'] ?? "Employee #$uid"; $ns->close();
            $errors[] = "$nm — payslip already exists"; $errorCount++; continue;
        }

        $hs = $conn->prepare("SELECT COALESCE(SUM(total_hours),0) as t FROM attendance WHERE userID=? AND DATE(clock_in) BETWEEN ? AND ?");
        $hs->bind_param("iss", $uid, $periodStart, $periodEnd);
        $hs->execute();
        $totalHours = $hs->get_result()->fetch_assoc()['t'] ?? 0; $hs->close();

        $es = $conn->prepare("SELECT hourly_rate, daily_rate FROM customers WHERE userID=?");
        $es->bind_param("i", $uid); $es->execute();
        $ed = $es->get_result()->fetch_assoc(); $es->close();

        $hourlyRate = $ed['hourly_rate'] ?? 100;
        $dailyRate  = $ed['daily_rate']  ?? 800;
        $grossPay   = $totalHours * $hourlyRate;
        $deductions = $grossPay * 0.10;
        $netPay     = $grossPay - $deductions;

        $ins = $conn->prepare("INSERT INTO payroll (userID,period_start,period_end,payroll_cycle,total_hours,hourly_rate,daily_rate,gross_pay,deductions,net_pay,status) VALUES (?,?,?,'Monthly',?,?,?,?,?,?,'Processed')");
        $ins->bind_param("issdddddd", $uid, $periodStart, $periodEnd, $totalHours, $hourlyRate, $dailyRate, $grossPay, $deductions, $netPay);
        $ins->execute() ? $successCount++ : ($errors[] = "Failed for employee #$uid" and $errorCount++);
        $ins->close();
    }

    $_SESSION['flash_message'] = $successCount > 0
        ? "$successCount payslip(s) generated!" . ($errorCount > 0 ? " | $errorCount failed: ".implode(', ',$errors) : '')
        : 'No payslips generated. '.implode(', ', $errors);
    $_SESSION['flash_type'] = $successCount > 0 ? 'success' : 'error';
    header("Location: generate_payslip.php"); exit();
}

// Mark as paid
if (isset($_GET['mark_paid'])) {
    $pid = intval($_GET['mark_paid']);
    $up  = $conn->prepare("UPDATE payroll SET status='Paid', payment_date=CURDATE() WHERE payrollID=?");
    $up->bind_param("i", $pid);
    $_SESSION['flash_message'] = $up->execute() ? "Payslip marked as Paid!" : "Failed to update status.";
    $_SESSION['flash_type']    = $up->execute() ? "success" : "error";
    $up->close();
    header("Location: generate_payslip.php"); exit();
}

function generatePayslipHTML($p, $ps, $pe) {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Payslip — '.htmlspecialchars($p['Firstname'].' '.$p['Lastname']).'</title>
<style>
  body{font-family:"Segoe UI",Arial,sans-serif;font-size:14px;color:#1a1a1a;max-width:760px;margin:36px auto;padding:32px;border:1px solid #ccc;}
  .hd{text-align:center;border-bottom:3px solid #0077b6;padding-bottom:18px;margin-bottom:26px;}
  .co{font-size:24px;font-weight:700;color:#0077b6;letter-spacing:0.05em;}
  .ti{font-size:16px;font-weight:600;color:#023e8a;margin-top:8px;}
  table{width:100%;border-collapse:collapse;margin:12px 0;}
  th,td{padding:11px 14px;text-align:left;border:1px solid #ddd;}
  th{background:#f0f7ff;font-weight:600;color:#023e8a;}
  .ar{text-align:right;font-weight:600;}
  .gr{background:#e8f4ff;font-weight:700;font-size:15px;}
  .np{background:#d4edda;color:#155724;font-size:17px;font-weight:700;}
  .ft{margin-top:36px;padding-top:16px;border-top:1px solid #ddd;font-size:11px;color:#999;text-align:center;}
  .pb{background:#0077b6;color:#fff;padding:11px 28px;border:none;border-radius:7px;font-size:14px;cursor:pointer;margin-top:20px;}
  @media print{.pb{display:none;}}
</style></head><body>
<div class="hd">
  <div class="co">DE CHAVEZ WATERHAUS</div>
  <div style="font-size:13px;color:#555;">Water Delivery &amp; Refilling Station</div>
  <div class="ti">OFFICIAL EMPLOYEE PAYSLIP</div>
</div>
<table>
  <tr><th style="width:180px;">Employee Name</th><td>'.htmlspecialchars($p['Firstname'].' '.$p['Lastname']).'</td></tr>
  <tr><th>Employee ID</th><td>#'.str_pad($p['userID'],5,'0',STR_PAD_LEFT).'</td></tr>
  <tr><th>Email</th><td>'.htmlspecialchars($p['Email']).'</td></tr>
  <tr><th>Pay Period</th><td>'.date('F d, Y',strtotime($ps)).' — '.date('F d, Y',strtotime($pe)).'</td></tr>
</table>
<table>
  <tr><th>Description</th><th style="width:160px;text-align:right;">Amount</th></tr>
  <tr><td>Total Hours Worked</td><td class="ar">'.number_format($p['total_hours'],2).' hrs</td></tr>
  <tr><td>Hourly Rate</td><td class="ar">₱'.number_format($p['hourly_rate'],2).' / hr</td></tr>
  <tr class="gr"><td><strong>GROSS PAY</strong></td><td class="ar">₱'.number_format($p['gross_pay'],2).'</td></tr>
</table>
<table>
  <tr><th>Deductions</th><th style="width:160px;text-align:right;">Amount</th></tr>
  <tr><td>Statutory (SSS, PhilHealth, Pag-IBIG, Tax · 10%)</td><td class="ar">−₱'.number_format($p['deductions'],2).'</td></tr>
</table>
<table class="np">
  <tr><th>NET PAY (Take-Home)</th><th style="font-size:20px;text-align:right;">₱'.number_format($p['net_pay'],2).'</th></tr>
</table>
<div class="ft">
  Generated '.date('F d, Y \a\t h:i A').' · Computer-generated document · De Chavez Waterhaus
</div>
<div style="text-align:center;">
  <button onclick="window.print()" class="pb">🖨 Print Payslip</button>
</div>
</body></html>';
}

$notifCount = $conn->query("SELECT COUNT(*) as u FROM notifications WHERE userID=$adminID AND is_read=0")->fetch_assoc()['u'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Payslip • Admin</title>
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

        /* ── FLASH ── */
        .flash-alert { display: flex; align-items: center; gap: 10px; padding: 13px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 0.87rem; }
        .flash-success { background: rgba(74,222,128,0.08); border: 1px solid rgba(74,222,128,0.28); color: var(--green); }
        .flash-error   { background: rgba(248,113,113,0.08); border: 1px solid rgba(248,113,113,0.28); color: var(--red); }
        .flash-warning { background: rgba(244,200,66,0.08);  border: 1px solid rgba(244,200,66,0.28);  color: var(--gold); }
        .flash-info    { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); }

        /* ── DATA CARDS ── */
        .data-card { background: linear-gradient(145deg,rgba(10,45,74,0.5),rgba(3,15,30,0.75)); border: 1px solid var(--glass-border); border-radius: 17px; overflow: hidden; margin-bottom: 22px; }
        .data-card-head { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 1px solid var(--glass-border); flex-wrap: wrap; gap: 10px; }
        .data-card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.15rem; font-weight: 500; color: var(--white); }
        .data-card-sub   { font-size: 0.75rem; color: rgba(202,240,248,0.35); margin-top: 2px; }
        .data-card-body  { padding: 22px; }

        /* ── FORM FIELDS ── */
        .field-label { display: block; font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 7px; }
        .field-input, .field-select { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 11px 14px; border-radius: 11px; outline: none; transition: all 0.3s; }
        .field-input::placeholder { color: rgba(202,240,248,0.2); }
        .field-input:focus, .field-select:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }
        .field-select option { background: var(--ocean); }

        /* ── BUTTONS ── */
        .btn-primary-grd { display: inline-flex; align-items: center; gap: 7px; padding: 11px 22px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(0,180,216,0.25); }
        .btn-primary-grd:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); }

        .btn-green-grd { display: inline-flex; align-items: center; gap: 7px; padding: 11px 22px; background: linear-gradient(135deg, #15803d, #4ade80); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(74,222,128,0.22); }
        .btn-green-grd:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(74,222,128,0.38); }

        .btn-glass-sm { display: inline-flex; align-items: center; gap: 5px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 7px 14px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; cursor: pointer; transition: all 0.25s; text-decoration: none; }
        .btn-glass-sm:hover { background: rgba(0,180,216,0.15); color: var(--foam); }

        .btn-gold-sm { display: inline-flex; align-items: center; gap: 5px; background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.25); color: var(--green); padding: 7px 14px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; cursor: pointer; transition: all 0.25s; text-decoration: none; }
        .btn-gold-sm:hover { background: rgba(74,222,128,0.2); color: var(--green); }

        .btn-outline-sm { display: inline-flex; align-items: center; gap: 5px; background: transparent; border: 1px solid var(--glass-border); color: rgba(202,240,248,0.45); padding: 7px 14px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; cursor: pointer; transition: all 0.25s; }
        .btn-outline-sm:hover { background: var(--glass); color: var(--foam); }

        /* ── EMPLOYEE CHECKBOXES ── */
        .emp-check-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; margin-top: 14px; }
        .emp-check-item { display: flex; align-items: center; gap: 10px; background: rgba(4,30,53,0.5); border: 1px solid var(--glass-border); border-radius: 10px; padding: 10px 14px; cursor: pointer; transition: all 0.22s; }
        .emp-check-item:hover { border-color: rgba(0,180,216,0.3); background: rgba(0,180,216,0.06); }
        .emp-check-item input[type="checkbox"] { accent-color: var(--aqua); width: 15px; height: 15px; cursor: pointer; flex-shrink: 0; }
        .emp-check-item label { font-size: 0.84rem; color: var(--foam); cursor: pointer; margin: 0; }

        /* ── TABLE ── */
        .pay-table { width: 100%; border-collapse: collapse; }
        .pay-table th { font-size: 0.66rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.3); padding: 0 18px 12px; text-align: left; border-bottom: 1px solid var(--glass-border); }
        .pay-table td { padding: 14px 18px; font-size: 0.86rem; color: rgba(202,240,248,0.7); border-bottom: 1px solid rgba(72,202,228,0.06); vertical-align: middle; }
        .pay-table tr:last-child td { border-bottom: none; }
        .pay-table tr:hover td { background: rgba(0,180,216,0.03); color: var(--foam); }
        .pay-table .text-right { text-align: right; }

        /* status pills */
        .s-pill { padding: 4px 11px; border-radius: 50px; font-size: 0.71rem; font-weight: 700; letter-spacing: 0.07em; }
        .s-Processed { background: rgba(74,222,128,0.1);  color: var(--green); border: 1px solid rgba(74,222,128,0.25); }
        .s-Paid      { background: rgba(0,180,216,0.1);   color: var(--aqua);  border: 1px solid rgba(0,180,216,0.25); }
        .s-Pending   { background: rgba(244,200,66,0.1);  color: var(--gold);  border: 1px solid rgba(244,200,66,0.25); }

        /* period badge */
        .period-badge { display: inline-flex; align-items: center; gap: 5px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 50px; padding: 3px 10px; font-size: 0.74rem; color: rgba(202,240,248,0.55); }

        .net-val  { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; font-weight: 600; color: var(--green); }
        .count-badge { background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); padding: 3px 10px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; }

        /* empty */
        .empty-state { text-align: center; padding: 52px 20px; color: rgba(202,240,248,0.3); }
        .empty-state i { font-size: 2.2rem; display: block; margin-bottom: 12px; color: rgba(0,180,216,0.15); }
        .empty-state p { font-size: 0.85rem; }

        /* ── PREVIEW FRAME ── */
        .preview-frame { width: 100%; min-height: 600px; border: none; border-top: 1px solid var(--glass-border); }

        /* ── MODAL ── */
        .modal-content { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 18px !important; }
        .modal-header { border-bottom: 1px solid var(--glass-border) !important; padding: 20px 24px !important; background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12)) !important; }
        .modal-footer { border-top: 1px solid var(--glass-border) !important; padding: 16px 24px !important; }
        .modal-body { padding: 0 !important; }
        .modal-title { font-family: 'Cormorant Garamond', serif !important; font-size: 1.25rem !important; font-weight: 500 !important; color: var(--white) !important; }
        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

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
        <a href="manage_orders.php"     class="nav-link"><i class="fas fa-shopping-cart"></i> Orders</a>
        <a href="manage_users.php"      class="nav-link"><i class="fas fa-users"></i> Users</a>
        <a href="manage_employees.php"  class="nav-link"><i class="fas fa-user-tie"></i> Employees</a>
        <div class="nav-section-label">Operations</div>
        <a href="attendance_management.php" class="nav-link"><i class="fas fa-clock"></i> Attendance</a>
        <a href="payroll_management.php"    class="nav-link"><i class="fas fa-money-bill"></i> Payroll</a>
        <a href="generate_payslip.php"      class="nav-link active"><i class="fas fa-file-pdf"></i> Generate Payslip</a>
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
                <h4>Generate Payslip</h4>
                <p>Create and manage official employee payslips</p>
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

    <!-- Flash -->
    <?php if($flashMessage):
        $fc = match($flashType) { 'success'=>'flash-success','error'=>'flash-error','warning'=>'flash-warning',default=>'flash-info' };
        $fi = match($flashType) { 'success'=>'check-circle','error'=>'exclamation-circle','warning'=>'exclamation-triangle',default=>'info-circle' };
    ?>
    <div class="flash-alert <?php echo $fc;?>">
        <i class="fas fa-<?php echo $fi;?>"></i>
        <?php echo htmlspecialchars($flashMessage);?>
    </div>
    <?php endif; ?>

    <!-- ── GENERATE NEW PAYSLIP ── -->
    <div class="data-card">
        <div class="data-card-head">
            <div>
                <div class="data-card-title"><i class="fas fa-file-plus me-2" style="color:var(--aqua);font-size:0.9rem;"></i>Generate New Payslip</div>
                <div class="data-card-sub">Select an employee and pay period to generate their payslip</div>
            </div>
        </div>
        <div class="data-card-body">
            <form method="POST">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="field-label">Select Employee</label>
                        <select name="userID" class="field-select" required>
                            <option value="">Choose employee…</option>
                            <?php $employees->data_seek(0); while($emp = $employees->fetch_assoc()): ?>
                                <option value="<?php echo $emp['userID'];?>">
                                    <?php echo htmlspecialchars($emp['Firstname'].' '.$emp['Lastname']);?> &nbsp;·&nbsp; <?php echo $emp['Email'];?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="field-label">Period Start</label>
                        <input type="date" name="period_start" class="field-input" value="<?php echo date('Y-m-01');?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="field-label">Period End</label>
                        <input type="date" name="period_end" class="field-input" value="<?php echo date('Y-m-t');?>" required>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" name="generate_payslip" class="btn-primary-grd" style="width:100%;justify-content:center;padding:11px 12px;">
                            <i class="fas fa-magic"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ── BULK GENERATION ── -->
    <div class="data-card">
        <div class="data-card-head">
            <div>
                <div class="data-card-title"><i class="fas fa-users me-2" style="color:var(--green);font-size:0.9rem;"></i>Bulk Payslip Generation</div>
                <div class="data-card-sub">Generate payslips for multiple employees at once</div>
            </div>
            <span style="background:rgba(0,180,216,0.1);border:1px solid rgba(0,180,216,0.25);color:var(--aqua);padding:4px 12px;border-radius:50px;font-size:0.72rem;font-weight:700;">Multi-select</span>
        </div>
        <div class="data-card-body">
            <form method="POST" id="bulkForm">
                <div class="row g-3 align-items-end mb-4">
                    <div class="col-md-3">
                        <label class="field-label">Period Start</label>
                        <input type="date" name="bulk_period_start" class="field-input" value="<?php echo date('Y-m-01');?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="field-label">Period End</label>
                        <input type="date" name="bulk_period_end" class="field-input" value="<?php echo date('Y-m-t');?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="field-label" style="opacity:0;">.</label>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button type="submit" name="bulk_generate" class="btn-green-grd">
                                <i class="fas fa-magic"></i> Generate Selected
                            </button>
                            <button type="button" class="btn-outline-sm" onclick="selectAllEmployees()">
                                <i class="fas fa-check-double"></i> All
                            </button>
                            <button type="button" class="btn-outline-sm" onclick="deselectAllEmployees()">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>

                <div style="font-size:0.7rem;letter-spacing:0.12em;text-transform:uppercase;color:rgba(202,240,248,0.35);margin-bottom:10px;">Select Employees</div>
                <div class="emp-check-grid">
                    <?php $employees->data_seek(0); while($emp = $employees->fetch_assoc()): ?>
                    <div class="emp-check-item">
                        <input class="employee-checkbox" type="checkbox" name="selected_employees[]" value="<?php echo $emp['userID'];?>" id="emp_<?php echo $emp['userID'];?>">
                        <label for="emp_<?php echo $emp['userID'];?>"><?php echo htmlspecialchars($emp['Firstname'].' '.$emp['Lastname']);?></label>
                    </div>
                    <?php endwhile; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- ── ALL PAYSLIPS ── -->
    <div class="data-card">
        <div class="data-card-head">
            <div>
                <div class="data-card-title"><i class="fas fa-history me-2" style="color:var(--gold);font-size:0.9rem;"></i>All Generated Payslips</div>
                <div class="data-card-sub">View, print and mark payslips as paid</div>
            </div>
            <span class="count-badge"><?php echo $allPayrolls->num_rows;?> Total</span>
        </div>

        <?php if($allPayrolls->num_rows > 0): ?>
        <div style="overflow-x:auto;">
            <table class="pay-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Pay Period</th>
                        <th class="text-right">Gross</th>
                        <th class="text-right">Net Pay</th>
                        <th>Status</th>
                        <th style="text-align:right;padding-right:22px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $allPayrolls->data_seek(0); while($pr = $allPayrolls->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div style="font-weight:500;color:var(--white);"><?php echo htmlspecialchars($pr['Firstname'].' '.$pr['Lastname']);?></div>
                            <div style="font-size:0.73rem;color:rgba(202,240,248,0.35);"><?php echo htmlspecialchars($pr['Email']);?></div>
                        </td>
                        <td>
                            <span class="period-badge">
                                <i class="fas fa-calendar-days" style="font-size:0.65rem;"></i>
                                <?php echo date('M d', strtotime($pr['period_start']));?> – <?php echo date('M d, Y', strtotime($pr['period_end']));?>
                            </span>
                        </td>
                        <td class="text-right" style="color:var(--foam);">₱<?php echo number_format($pr['gross_pay'],2);?></td>
                        <td class="text-right"><span class="net-val">₱<?php echo number_format($pr['net_pay'],2);?></span></td>
                        <td><span class="s-pill s-<?php echo $pr['status'];?>"><?php echo $pr['status'];?></span></td>
                        <td style="text-align:right;padding-right:18px;">
                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;">
                                <button class="btn-glass-sm" onclick="viewEmployeePayslips(<?php echo $pr['userID'];?>, '<?php echo htmlspecialchars($pr['Firstname'].' '.$pr['Lastname']);?>')">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if($pr['status'] !== 'Paid'): ?>
                                <a href="generate_payslip.php?mark_paid=<?php echo $pr['payrollID'];?>" class="btn-gold-sm" onclick="return confirm('Mark this payslip as Paid?')">
                                    <i class="fas fa-check"></i> Paid
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p>No payslips generated yet.<br>Use the form above to create your first payslip.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── PAYSLIP PREVIEW ── -->
    <?php if($payslipHTML && $selectedPayroll): ?>
    <div class="data-card">
        <div class="data-card-head">
            <div>
                <div class="data-card-title">
                    <i class="fas fa-eye me-2" style="color:var(--aqua);font-size:0.9rem;"></i>
                    Preview · <?php echo htmlspecialchars($selectedPayroll['Firstname'].' '.$selectedPayroll['Lastname']);?>
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button onclick="printPayslip()" class="btn-green-grd"><i class="fas fa-print"></i> Print</button>
                <a href="generate_payslip.php" class="btn-outline-sm"><i class="fas fa-times"></i> Close</a>
            </div>
        </div>
        <iframe id="payslipFrame" class="preview-frame" srcdoc="<?php echo htmlspecialchars($payslipHTML);?>"></iframe>
    </div>
    <?php endif; ?>

</main>

<!-- ── EMPLOYEE PAYSLIPS MODAL ── -->
<div class="modal fade" id="empPayslipsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-invoice-dollar me-2" style="color:var(--aqua);"></i>
                    <span id="modalEmpName">Employee</span> — Payslip History
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div style="overflow-x:auto;">
                    <table class="pay-table" style="margin:0;">
                        <thead>
                            <tr>
                                <th>Pay Period</th>
                                <th class="text-right">Hours</th>
                                <th class="text-right">Gross</th>
                                <th class="text-right">Net Pay</th>
                                <th>Status</th>
                                <th style="text-align:right;padding-right:20px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="modalPayslipList"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-end">
                <button type="button" class="btn-outline-sm" data-bs-dismiss="modal">Close</button>
            </div>
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

    // ── PAYROLL DATA ──
    const allPayrollsData = <?php
        $allPayrolls->data_seek(0);
        $arr = [];
        while($r = $allPayrolls->fetch_assoc()) $arr[] = $r;
        echo json_encode($arr);
    ?>;

    // ── VIEW EMPLOYEE PAYSLIPS ──
    function viewEmployeePayslips(uid, name) {
        document.getElementById('modalEmpName').textContent = name;
        const list   = allPayrollsData.filter(p => parseInt(p.userID) === uid).sort((a,b)=>new Date(b.period_end)-new Date(a.period_end));
        const tbody  = document.getElementById('modalPayslipList');
        tbody.innerHTML = '';

        if(!list.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="empty-state"><i class="fas fa-inbox"></i><p>No payslips found.</p></td></tr>';
        } else {
            list.forEach(p => {
                const sClass = p.status === 'Processed' ? 's-Processed' : p.status === 'Paid' ? 's-Paid' : 's-Pending';
                const start  = new Date(p.period_start).toLocaleDateString('en-US',{month:'short',day:'numeric'});
                const end    = new Date(p.period_end).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><span class="period-badge">${start} – ${end}</span></td>
                    <td class="text-right">${parseFloat(p.total_hours).toFixed(2)} hrs</td>
                    <td class="text-right">₱${parseFloat(p.gross_pay).toLocaleString('en-US',{minimumFractionDigits:2})}</td>
                    <td class="text-right"><span class="net-val">₱${parseFloat(p.net_pay).toLocaleString('en-US',{minimumFractionDigits:2})}</span></td>
                    <td><span class="s-pill ${sClass}">${p.status}</span></td>
                    <td style="text-align:right;padding-right:18px;">
                        <div style="display:flex;gap:6px;justify-content:flex-end;">
                            <a href="generate_payslip.php?view_payslip=${p.payrollID}" class="btn-glass-sm"><i class="fas fa-eye"></i> View</a>
                            ${p.status !== 'Paid' ? `<a href="generate_payslip.php?mark_paid=${p.payrollID}" class="btn-gold-sm" onclick="return confirm('Mark as Paid?')"><i class="fas fa-check"></i> Paid</a>` : ''}
                        </div>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        new bootstrap.Modal(document.getElementById('empPayslipsModal')).show();
    }

    // ── PRINT PAYSLIP ──
    function printPayslip() {
        const frame = document.getElementById('payslipFrame');
        if(frame) { frame.contentWindow.focus(); frame.contentWindow.print(); }
    }

    // ── BULK SELECT ──
    function selectAllEmployees()   { document.querySelectorAll('.employee-checkbox').forEach(c=>c.checked=true); }
    function deselectAllEmployees() { document.querySelectorAll('.employee-checkbox').forEach(c=>c.checked=false); }

    // Clicking the whole emp-check-item toggles checkbox
    document.querySelectorAll('.emp-check-item').forEach(item => {
        item.addEventListener('click', e => {
            if(e.target.tagName !== 'INPUT') {
                const cb = item.querySelector('input[type="checkbox"]');
                cb.checked = !cb.checked;
            }
            item.style.borderColor = item.querySelector('input').checked ? 'rgba(0,180,216,0.45)' : '';
            item.style.background  = item.querySelector('input').checked ? 'rgba(0,180,216,0.1)' : '';
        });
    });
</script>
</body>
</html>