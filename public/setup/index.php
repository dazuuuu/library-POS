<?php
// public/setup/index.php
// Step 1: install the schema snapshot into a brand-new database.

require_once __DIR__ . '/_setup.php';

$schemaFile = ROOT_PATH . '/databases/full_schema.sql';
$error = '';
$connectionError = '';
$alreadyInstalled = false;
$roleCount = 0;
$tableCount = 0;
$pdo = null;

try {
    $pdo = Database::pdo();
    $tableCount = setup_table_count($pdo);
    $roleCount = setup_table_exists($pdo, 'roles') ? (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn() : 0;
    $alreadyInstalled = setup_schema_ready($pdo);
} catch (\Throwable $e) {
    $connectionError = $e->getMessage();
}

function setup_split_sql(string $sql): array
{
    $parts = preg_split('/;\s*\n/', $sql);
    $statements = [];
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if ($trimmed === '') {
            continue;
        }
        $withoutComments = trim(preg_replace('/^\s*--.*$/m', '', $trimmed));
        if ($withoutComments === '') {
            continue;
        }
        $statements[] = $trimmed;
    }
    return $statements;
}

const SETUP_TOLERABLE_SQL_CODES = [1050, 1060, 1061, 1062, 1826];

$results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run' && !$connectionError) {
    $alreadyInstalled = setup_schema_ready($pdo);
    if ($alreadyInstalled) {
        $error = 'The schema already looks installed. Nothing was changed.';
    } elseif (!is_file($schemaFile)) {
        $error = 'Could not find databases/full_schema.sql.';
    } else {
        $statements = setup_split_sql(file_get_contents($schemaFile));
        $results = ['created' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
                $results['created']++;
            } catch (\PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (in_array($code, SETUP_TOLERABLE_SQL_CODES, true)) {
                    $results['skipped']++;
                    continue;
                }
                $results['errors'][] = [
                    'statement' => mb_substr($stmt, 0, 220),
                    'message' => $e->getMessage(),
                ];
                break;
            }
        }

        $tableCount = setup_table_count($pdo);
        $roleCount = setup_table_exists($pdo, 'roles') ? (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn() : 0;
        $alreadyInstalled = setup_schema_ready($pdo);
    }
}

setup_render_header('Website setup', 'migrations');
?>
<?php if ($connectionError): ?>
  <div class="alert err">
    <strong>Cannot connect to the database.</strong><br>
    <?php echo setup_h($connectionError); ?><br><br>
    Create the empty database and confirm <code>app/config/database.php</code> matches your host, database name, username, and password.
  </div>
<?php elseif ($results): ?>
  <?php if ($results['errors']): ?>
    <div class="alert err">
      <strong>Migration stopped at a database error.</strong><br>
      Statement: <code><?php echo setup_h($results['errors'][0]['statement']); ?>...</code><br>
      Error: <?php echo setup_h($results['errors'][0]['message']); ?>
    </div>
  <?php else: ?>
    <div class="alert ok">Migrations complete. <?php echo (int) $results['created']; ?> statement<?php echo $results['created'] === 1 ? '' : 's'; ?> ran<?php echo $results['skipped'] ? ', with ' . (int) $results['skipped'] . ' already in place' : ''; ?>.</div>
  <?php endif; ?>
  <div class="card">
    <div class="stat"><b><?php echo (int) $tableCount; ?></b><span>tables</span></div>
    <div class="stat"><b><?php echo (int) $roleCount; ?></b><span>roles</span></div>
  </div>
  <?php if ($alreadyInstalled && !$results['errors']): ?>
    <div class="actions">
      <a class="btn" href="<?php echo public_url('setup/account.php'); ?>">Next: create super account</a>
    </div>
  <?php endif; ?>
<?php elseif ($error): ?>
  <div class="alert err"><?php echo setup_h($error); ?></div>
<?php elseif ($alreadyInstalled): ?>
  <div class="alert info">The schema is already ready: <strong><?php echo (int) $tableCount; ?> tables</strong> and <strong><?php echo (int) $roleCount; ?> roles</strong> found.</div>
  <div class="actions">
    <a class="btn" href="<?php echo public_url('setup/account.php'); ?>">Continue to super account</a>
  </div>
<?php else: ?>
  <div class="card">
    <h2>Step 1</h2>
    <p style="margin-top:0;">This will install the full application schema from <code>databases/full_schema.sql</code> into the configured database.</p>
    <form method="post">
      <input type="hidden" name="action" value="run">
      <button class="btn" type="submit">Run migrations</button>
    </form>
  </div>
<?php endif; ?>
<?php setup_render_footer(); ?>
