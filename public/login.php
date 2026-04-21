<?php
// =============================================================================
// Login Page
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';
app_boot();

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (Auth::attempt($username, $password)) {
        header('Location: index.php');
        exit;
    }

    $error = 'Invalid credentials. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= h(APP_NAME) ?></title>
    <meta name="description" content="Sign in to TeraPH Web Deployer to manage your deployments.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-logo">
            <div class="logo-icon">⚡</div>
            <h1 class="auth-title"><?= h(APP_NAME) ?></h1>
            <p class="auth-subtitle">Deployment Control Panel</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="auth-form" autocomplete="off">
            <div class="form-group">
                <label for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    class="form-control"
                    value="<?= h($_POST['username'] ?? '') ?>"
                    required
                    autofocus
                    autocomplete="username"
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    required
                    autocomplete="current-password"
                >
            </div>

            <button type="submit" class="btn btn-primary btn-full">
                Sign In
            </button>
        </form>
    </div>
</div>

</body>
</html>
