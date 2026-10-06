<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$value = static fn (string $field): string => $esc($formData[$field] ?? '');
?>
<section class="page-heading">
    <p class="eyebrow">Tu cuenta</p>
    <h1>Crear cuenta</h1>
    <p>Prototipo académico: usa datos ficticios. Con la sesión iniciada tendrás un <?= (int) \App\Services\CartService::MEMBER_PERCENT ?> % de descuento en tus compras, tu carrito se guardará y podrás seguir el estado de tus pedidos.</p>
</section>

<?php if (!empty($error)): ?><p class="notice error" role="alert"><?= $esc($error) ?></p><?php endif; ?>

<form class="checkout-form admin-login" method="post" action="/registro">
    <input type="hidden" name="_csrf" value="<?= $esc($csrfToken) ?>">
    <div class="form-field">
        <label for="name">Nombre</label>
        <input id="name" name="name" autocomplete="name" maxlength="120" required value="<?= $value('name') ?>">
        <?php if (isset($errors['name'])): ?><small class="field-error"><?= $esc($errors['name']) ?></small><?php endif; ?>
    </div>
    <div class="form-field">
        <label for="email">Email</label>
        <input id="email" type="email" name="email" autocomplete="email" maxlength="254" required value="<?= $value('email') ?>">
        <?php if (isset($errors['email'])): ?><small class="field-error"><?= $esc($errors['email']) ?></small><?php endif; ?>
    </div>
    <div class="form-field">
        <label for="password">Contraseña (mínimo <?= (int) \App\Services\AuthService::MIN_PASSWORD ?> caracteres)</label>
        <input id="password" type="password" name="password" autocomplete="new-password" minlength="<?= (int) \App\Services\AuthService::MIN_PASSWORD ?>" maxlength="72" required>
        <?php if (isset($errors['password'])): ?><small class="field-error"><?= $esc($errors['password']) ?></small><?php endif; ?>
    </div>
    <div class="form-field">
        <label for="password_confirm">Repite la contraseña</label>
        <input id="password_confirm" type="password" name="password_confirm" autocomplete="new-password" maxlength="72" required>
        <?php if (isset($errors['password_confirm'])): ?><small class="field-error"><?= $esc($errors['password_confirm']) ?></small><?php endif; ?>
    </div>
    <button class="button primary" type="submit">Crear cuenta</button>
    <p class="muted">¿Ya tienes cuenta? <a class="text-link" href="/login">Iniciar sesión</a></p>
</form>
