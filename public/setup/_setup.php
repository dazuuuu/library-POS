<?php
// public/setup/_setup.php
// Shared helpers for the temporary first-run setup pages.

require_once __DIR__ . '/../../app/app.php';

function setup_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function setup_table_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
}

function setup_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function setup_schema_ready(PDO $pdo): bool
{
    if (!setup_table_exists($pdo, 'roles') || !setup_table_exists($pdo, 'users') || !setup_table_exists($pdo, 'tenants')) {
        return false;
    }
    return (bool) $pdo->query("SELECT 1 FROM roles WHERE role_name = 'tenant_owner' LIMIT 1")->fetchColumn();
}

function setup_owner_exists(PDO $pdo): bool
{
    if (!setup_schema_ready($pdo)) {
        return false;
    }
    return (bool) $pdo->query(
        "SELECT 1
           FROM users u
           JOIN roles r ON r.id = u.role_id
          WHERE r.role_name = 'tenant_owner'
            AND u.is_active = 1
          LIMIT 1"
    )->fetchColumn();
}

function setup_unique_username(PDO $pdo, string $email): string
{
    $base = preg_replace('/[^a-z0-9_]+/', '', strtolower(explode('@', $email)[0])) ?: 'super';
    $name = $base;
    $i = 0;
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
    while (true) {
        $stmt->execute([$name]);
        if (!$stmt->fetchColumn()) {
            return $name;
        }
        $name = $base . (++$i);
    }
}

function setup_render_header(string $title, string $activeStep = 'migrations'): void
{
    $h = 'setup_h';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $h($title); ?> - Website setup</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f6f7f9; color: #18212f; margin: 0; line-height: 1.5; }
  .wrap { max-width: 760px; margin: 0 auto; padding: 34px 18px; }
  .top { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 18px; }
  h1 { font-size: 1.45rem; margin: 0 0 4px; }
  .lead { color: #607086; font-size: .92rem; margin: 0; }
  .badge { display: inline-block; background: #fff1c7; color: #8a5a00; font-size: .68rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; margin-left: 6px; vertical-align: middle; }
  .steps { display: flex; gap: 8px; margin: 0 0 18px; }
  .step { flex: 1; padding: 10px 12px; border: 1px solid #d8dee8; background: #fff; color: #4d5d72; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: .84rem; }
  .step.active { border-color: #0f766e; color: #0f766e; background: #ecfdf9; }
  .card { background: #fff; border: 1px solid #d8dee8; border-radius: 8px; padding: 22px 24px; margin-bottom: 16px; }
  .card h2 { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #607086; margin: 0 0 16px; }
  .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; padding: 10px 18px; border: 0; border-radius: 8px; background: #0f766e; color: #fff; text-decoration: none; font-size: .94rem; font-weight: 700; cursor: pointer; }
  .btn:hover { background: #115e59; }
  .btn.secondary { background: #e8edf3; color: #253244; }
  .btn.secondary:hover { background: #dbe3ed; }
  .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
  .alert { border-radius: 8px; padding: 12px 15px; font-size: .9rem; margin-bottom: 14px; }
  .alert.ok { background: #def7ec; color: #07553f; border: 1px solid #b7ead7; }
  .alert.err { background: #fee2e2; color: #8f1d1d; border: 1px solid #fecaca; }
  .alert.warn { background: #fff7d6; color: #765000; border: 1px solid #f4df91; }
  .alert.info { background: #eaf3ff; color: #17457d; border: 1px solid #c8defb; }
  label { display: block; font-size: .84rem; font-weight: 700; color: #435267; margin-bottom: 5px; }
  input { width: 100%; min-height: 40px; padding: 9px 11px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: .93rem; background: #fff; outline: none; margin-bottom: 14px; }
  input:focus { border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15,118,110,.14); }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
  .stat { display: inline-block; margin-right: 28px; }
  .stat b { display: block; font-size: 1.45rem; line-height: 1; }
  code { background: #eef2f7; padding: 2px 5px; border-radius: 4px; font-size: .86em; word-break: break-all; }
  ul { margin: 6px 0 0; padding-left: 18px; }
  @media (max-width: 640px) {
    .top, .steps, .grid { display: block; }
    .step { display: block; margin-bottom: 8px; }
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <h1><?php echo $h($title); ?> <span class="badge">SETUP ONLY</span></h1>
      <p class="lead">Run once on a new install, then remove the setup folder.</p>
    </div>
  </div>
  <div class="steps">
    <a class="step <?php echo $activeStep === 'migrations' ? 'active' : ''; ?>" href="<?php echo public_url('setup/'); ?>">1. Migrations</a>
    <a class="step <?php echo $activeStep === 'account' ? 'active' : ''; ?>" href="<?php echo public_url('setup/account.php'); ?>">2. Super account</a>
  </div>
  <div class="alert warn">This setup area is intentionally unprotected. Delete <code>public/setup</code> after migrations and the first login account are ready.</div>
<?php
}

function setup_render_footer(): void
{
    echo "</div>\n</body>\n</html>\n";
}
