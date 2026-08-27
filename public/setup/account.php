<?php
// public/setup/account.php
// Step 2: create the first tenant owner account for logging in.

require_once __DIR__ . '/_setup.php';

$pdo = null;
$connectionError = '';
$schemaReady = false;
$ownerExists = false;
$errors = [];
$success = null;
$createdEmail = '';
$createdPassword = '';
$old = [
    'shop_name' => '',
    'name' => '',
    'email' => '',
    'phone' => '',
    'password' => '',
];

try {
    $pdo = Database::pdo();
    $schemaReady = setup_schema_ready($pdo);
    $ownerExists = setup_owner_exists($pdo);
} catch (\Throwable $e) {
    $connectionError = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$connectionError && $schemaReady) {
    $old = [
        'shop_name' => trim($_POST['shop_name'] ?? ''),
        'name' => trim($_POST['name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'password' => (string) ($_POST['password'] ?? ''),
    ];

    if ($ownerExists) {
        $errors[] = 'A super account already exists. Use the login page instead.';
    }
    if ($old['shop_name'] === '') {
        $errors[] = 'Business name is required.';
    }
    if ($old['name'] === '') {
        $errors[] = 'Full name is required.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    } else {
        $chk = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $chk->execute([$old['email']]);
        if ($chk->fetchColumn()) {
            $errors[] = 'That email is already registered.';
        }
    }
    if (strlen($old['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $roleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'tenant_owner' LIMIT 1")->fetchColumn();
            if (!$roleId) {
                throw new RuntimeException('tenant_owner role is missing.');
            }

            $tenants = new Models\TenantModel($pdo);
            $slug = $tenants->uniqueSlug($old['shop_name']);
            $tenantId = $tenants->create($old['shop_name'], $slug);
            $username = setup_unique_username($pdo, $old['email']);

            $stmt = $pdo->prepare(
                'INSERT INTO users (tenant_id, username, email, password_hash, role_id, is_active, email_verified, must_reset_password)
                 VALUES (?, ?, ?, ?, ?, 1, 1, 0)'
            );
            $stmt->execute([
                $tenantId,
                $username,
                $old['email'],
                password_hash($old['password'], PASSWORD_DEFAULT),
                $roleId,
            ]);
            $ownerId = (int) $pdo->lastInsertId();

            $parts = preg_split('/\s+/', $old['name'], 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';
            $profile = $pdo->prepare(
                'INSERT INTO user_profiles (user_id, first_name, last_name, phone)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), phone = VALUES(phone)'
            );
            $profile->execute([$ownerId, $firstName, $lastName, $old['phone'] !== '' ? $old['phone'] : null]);

            $tenants->setOwner($tenantId, $ownerId);
            $pdo->commit();

            $createdEmail = $old['email'];
            $createdPassword = $old['password'];
            $success = 'Super account created. You can now log in.';
            $ownerExists = true;
            $old['password'] = '';
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Could not create the super account: ' . $e->getMessage();
        }
    }
}

setup_render_header('Super account', 'account');
?>
<?php if ($connectionError): ?>
  <div class="alert err">
    <strong>Cannot connect to the database.</strong><br>
    <?php echo setup_h($connectionError); ?>
  </div>
<?php elseif (!$schemaReady): ?>
  <div class="alert err">The database schema is not ready yet. Run migrations first.</div>
  <div class="actions">
    <a class="btn" href="<?php echo public_url('setup/'); ?>">Run migrations</a>
  </div>
<?php else: ?>
  <?php if ($errors): ?>
    <div class="alert err"><strong>Please fix this:</strong><ul><?php foreach ($errors as $e): ?><li><?php echo setup_h($e); ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert ok"><?php echo setup_h($success); ?></div>
    <div class="card">
      <h2>Login details</h2>
      <p>Email: <code><?php echo setup_h($createdEmail); ?></code></p>
      <p>Password: <code><?php echo setup_h($createdPassword); ?></code></p>
      <div class="actions">
        <a class="btn" href="<?php echo public_url('auth/login.php'); ?>">Log in now</a>
      </div>
    </div>
  <?php elseif ($ownerExists): ?>
    <div class="alert info">A super account already exists, so this setup page will not create another one.</div>
    <div class="actions">
      <a class="btn" href="<?php echo public_url('auth/login.php'); ?>">Go to login</a>
    </div>
  <?php else: ?>
    <form method="post">
      <div class="card">
        <h2>Business</h2>
        <label for="shop_name">Business name</label>
        <input id="shop_name" name="shop_name" type="text" required value="<?php echo setup_h($old['shop_name']); ?>" placeholder="Denmar Bookshop">
      </div>
      <div class="card">
        <h2>Super account</h2>
        <label for="name">Full name</label>
        <input id="name" name="name" type="text" required value="<?php echo setup_h($old['name']); ?>" placeholder="Jane Doe">
        <div class="grid">
          <div>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required value="<?php echo setup_h($old['email']); ?>" placeholder="owner@example.com">
          </div>
          <div>
            <label for="phone">Phone</label>
            <input id="phone" name="phone" type="tel" value="<?php echo setup_h($old['phone']); ?>" placeholder="+254700000000">
          </div>
        </div>
        <label for="password">Password</label>
        <input id="password" name="password" type="text" required minlength="8" value="<?php echo setup_h($old['password']); ?>" placeholder="Min. 8 characters" autocomplete="off">
      </div>
      <button class="btn" type="submit">Create super account</button>
    </form>
  <?php endif; ?>
<?php endif; ?>
<?php setup_render_footer(); ?>
