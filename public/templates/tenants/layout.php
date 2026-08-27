<?php
// public/templates/tenants/layout.php
// Tenant (owner) chrome. Branding is dynamic per tenant. Pages set $page_title
// and $content, then include this. The page is responsible for calling
// PageGuard::tenant() before building $content.

$__tenant = $__tenant ?? (TenantContext::tenantId()
    ? (new Models\TenantModel(Database::pdo()))->find(TenantContext::tenantId())
    : null);
$shopName = $__tenant['name'] ?? 'My Shop';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?> — <?php echo htmlspecialchars($shopName); ?></title>
    <?php include __DIR__ . '/../../components/pwa_head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root{ --pos-green:#16a34a; --pos-green-dark:#15803d; --pos-green-light:#f0fdf4; --pos-bg:#f7f7fb; --pos-ink:#1f2330; }
        *{box-sizing:border-box;} body{margin:0;font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--pos-bg);color:var(--pos-ink);overflow-x:hidden;}
        .t-wrap{display:flex;min-height:100vh;}
        .t-main{flex:1;margin-left:264px;padding:26px 30px;width:calc(100% - 264px);}
        .t-topbar{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:22px;box-shadow:0 1px 3px rgba(16,24,40,.04);}
        .t-topbar-actions{display:flex;align-items:center;gap:12px;}
        .t-mobile-logout{display:none;text-decoration:none;color:#64748b;font-size:1.05rem;}
        .t-topbar h1{font-size:1.35rem;margin:0;font-weight:700;color:var(--pos-ink);}
        .t-flash{border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:.92rem;}
        .t-flash.ok{background:#dcfce7;color:#166534;} .t-flash.err{background:#fee2e2;color:#991b1b;}
        @media (max-width:992px){ .t-main{margin-left:0;width:100%;padding:18px;} .t-topbar{margin-top:54px;} .t-mobile-logout{display:inline-flex;} }
        @media (max-width:576px){
            .t-main{padding:10px;padding-top:12px;}
            .t-topbar{margin-top:52px;margin-bottom:12px;padding:12px 14px;border-radius:12px;}
            .t-topbar h1{font-size:1.08rem;line-height:1.2;}
            .t-flash{padding:10px 12px;margin-bottom:12px;font-size:.86rem;}
            .table-responsive{margin-left:-10px;margin-right:-10px;padding-left:10px;padding-right:10px;}
        }

        /* ---- site-wide red/light POS theme ---- */
        .btn-primary{ --bs-btn-bg:var(--pos-green); --bs-btn-border-color:var(--pos-green); --bs-btn-hover-bg:var(--pos-green-dark); --bs-btn-hover-border-color:var(--pos-green-dark); --bs-btn-active-bg:var(--pos-green-dark); --bs-btn-active-border-color:var(--pos-green-dark); --bs-btn-disabled-bg:var(--pos-green); --bs-btn-disabled-border-color:var(--pos-green); }
        .btn-outline-primary{ --bs-btn-color:var(--pos-green); --bs-btn-border-color:var(--pos-green); --bs-btn-hover-bg:var(--pos-green); --bs-btn-hover-border-color:var(--pos-green); --bs-btn-active-bg:var(--pos-green); --bs-btn-active-border-color:var(--pos-green); }
        a{ color:var(--pos-green); }
        .text-primary{ color:var(--pos-green) !important; }
        .bg-primary{ background-color:var(--pos-green) !important; }
        .badge.bg-primary{ background-color:var(--pos-green) !important; }
        .form-control:focus, .form-select:focus{ border-color:var(--pos-green); box-shadow:0 0 0 .2rem rgba(22,163,74,.12); }
        .card{ border-radius:14px; }
        .table thead th{ color:#8a8f9c; font-size:.72rem; letter-spacing:.04em; }
        .table-responsive{border-radius:12px;}
        .dropdown-menu{max-width:calc(100vw - 24px);}
        @media (max-width:576px){
            .card{border-radius:12px!important;}
            .card-body.p-4{padding:1rem!important;}
            .card-body.p-5{padding:1.5rem 1rem!important;}
            .row.g-3{--bs-gutter-x:.75rem;--bs-gutter-y:.75rem;}
            .row.g-4{--bs-gutter-x:.75rem;--bs-gutter-y:.75rem;}
            .h4{font-size:1.18rem;}
            .h5{font-size:1.05rem;}
            .btn{min-height:40px;display:inline-flex;align-items:center;justify-content:center;white-space:normal;}
            .btn-sm{min-height:36px;}
            .btn-group{display:flex;flex-wrap:wrap;gap:6px;}
            .btn-group>.btn{border-radius:8px!important;flex:1 1 auto;}
            .d-flex.justify-content-between{gap:10px;}
            .form-control,.form-select{font-size:16px;}
            .form-control-sm,.form-select-sm{min-height:40px;}
            .table{min-width:680px;}
            .table.table-sm{min-width:620px;}
            .modal-dialog{margin:.75rem;max-width:calc(100% - 1.5rem);}
        }
    </style>
    <?php echo $extra_css ?? ''; ?>
</head>
<body>
<div class="t-wrap">
    <?php include __DIR__ . '/../../components/tenants/sidebar.php'; ?>
    <main class="t-main">
        <div class="t-topbar">
            <h1><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?></h1>
            <div class="t-topbar-actions">
                <div class="text-muted small d-none d-md-block"><?php echo date('l, j M Y'); ?></div>
                <a class="t-mobile-logout" href="<?php echo public_url('auth/logout.php'); ?>" aria-label="Logout"><i class="fas fa-arrow-right-from-bracket"></i></a>
            </div>
        </div>

        <?php if (!empty($_SESSION['flash']['success'])): ?>
            <div class="t-flash ok"><?php echo htmlspecialchars($_SESSION['flash']['success']); unset($_SESSION['flash']['success']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash']['error'])): ?>
            <div class="t-flash err"><?php echo htmlspecialchars($_SESSION['flash']['error']); unset($_SESSION['flash']['error']); ?></div>
        <?php endif; ?>

        <?php echo $content ?? ''; ?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<?php echo $extra_js ?? ''; ?>
</body>
</html>
