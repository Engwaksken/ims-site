<main class="login-container">
    <div class="login-card">
        <div class="login-header">
            <h1>Sign in</h1>
            <p><?=e(env('APP_NAME','IMS'))?></p>
        </div>

        <?php if($m=$_SESSION['error']??null): unset($_SESSION['error']); ?>
            <div class="alert alert-danger" role="alert"><?=e($m)?></div>
        <?php endif ?>

        <form method="post" action="<?=e(url('/login'))?>">
            <?=csrf_field()?>

            <div class="form-group">
                <label for="login-username">Username or email</label>
                <input id="login-username" name="username" class="form-control" required autocomplete="username" autofocus>
            </div>

            <div class="form-group">
                <label for="login-password">Password</label>
                <input id="login-password" type="password" name="password" class="form-control" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>
    </div>
</main>
