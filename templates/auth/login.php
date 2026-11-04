<?php $esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?>
<section class="page-heading">
    <p class="eyebrow">Tu cuenta</p>
    <h1>Iniciar sesión</h1>
    <p>Los clientes obtienen un <?= (int) \App\Services\CartService::MEMBER_PERCENT ?> % de descuento en sus compras con la sesión iniciada. Si eres administrador, entrarás al back-office.</p>
</section>

<?php if (!empty($error)): ?><p class="notice error" role="alert"><?= $esc($error) ?></p><?php endif; ?>

<form class="checkout-form admin-login" method="post" action="/login">
    <input type="hidden" name="_csrf" value="<?= $esc($csrfToken) ?>">
    <input type="hidden" name="next" value="<?= $esc($next) ?>">
    <div class="form-field">
        <label for="email">Email</label>
        <input id="email" type="email" name="email" autocomplete="username" required value="<?= $esc($email) ?>">
    </div>
    <div class="form-field">
        <label for="password">Contraseña</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
    </div>
    <button class="button primary" type="submit">Entrar</button>
    <p class="muted">¿No tienes cuenta? <a class="text-link" href="/registro">Crear cuenta</a>. También puedes comprar sin iniciar sesión.</p>
</form>
